<?php
declare(strict_types=1);

namespace App\Plugin\ApiNotification\Controller;

use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Model\Order;
use App\Util\Http;
use App\Util\Plugin;
use Kernel\Annotation\Interceptor;
use Kernel\Waf\Filter;

#[Interceptor([ManageSession::class], Interceptor::TYPE_API)]
class Backfill extends ManagePlugin
{
    private function splitSecretCards(string $secret): array
    {
        $cards = preg_split('/[\r\n|]+/', $secret) ?: [];
        $cards = array_map('trim', $cards);
        $cards = array_filter($cards, static fn(string $card) => $card !== '');

        return array_values(array_unique($cards));
    }

    private function getCardExtractPrefixes(array $config): array
    {
        $prefixText = trim((string)($config['card_extract_prefixes'] ?? ''));
        if ($prefixText === '') {
            return [];
        }

        $prefixes = preg_split('/[,\r\n|]+/', $prefixText) ?: [];
        $prefixes = array_map(static fn(string $prefix) => strtolower(trim(trim($prefix), '-')), $prefixes);
        $prefixes = array_filter($prefixes, static fn(string $prefix) => $prefix !== '');

        return array_values(array_unique($prefixes));
    }

    private function isCardExtractUniversalCard(string $card, array $config): bool
    {
        $card = trim($card);
        if ($card === '') {
            return false;
        }

        $prefixes = $this->getCardExtractPrefixes($config);
        if (count($prefixes) > 0) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with(strtolower($card), $prefix . '-')) {
                    return preg_match('/^[a-z0-9_]+-[A-Za-z0-9]{16,64}$/', $card) === 1;
                }
            }
            return false;
        }

        return preg_match('/^[a-z0-9_]+-[A-Za-z0-9]{16,64}$/', $card) === 1;
    }

    private function filterCardExtractCards(array $cards, array $config): array
    {
        $cards = array_filter($cards, fn(string $card) => $this->isCardExtractUniversalCard($card, $config));

        return array_values(array_unique($cards));
    }

    private function normalizeDateTime(?string $value, bool $endOfDay = false): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        }

        $time = strtotime($value);
        if ($time === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $time);
    }

    private function normalizeCommodityIds(array|string|null $ids): array
    {
        if (is_string($ids)) {
            $ids = [$ids];
        }

        $ids = array_map(static fn($id) => (int)$id, (array)$ids);
        $ids = array_filter($ids, static fn(int $id) => $id > 0);

        return array_values(array_unique($ids));
    }

    private function appendDetail(array &$details, array $detail): void
    {
        if (count($details) < 50) {
            $details[] = $detail;
        }
    }

    public function run(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        $config = Plugin::getConfig('ApiNotification', false);
        $isLog = !empty($config['log']);
        $url = trim((string)($config['card_extract_url'] ?? ''));
        $token = trim((string)($config['card_extract_token'] ?? ''));

        if (empty($config['card_extract_sync'])) {
            return $this->json(0, '请先在 ApiNotification 配置中开启 Card-Extract 同步');
        }

        if ($url === '' || $token === '') {
            return $this->json(0, 'Card-Extract 标记接口或 API Token 为空');
        }

        $configuredCommodityIds = $this->normalizeCommodityIds($config['commodity'] ?? []);
        $targetCommodityId = (int)($map['commodity_id'] ?? 0);
        $commodityIds = $targetCommodityId > 0 ? [$targetCommodityId] : $configuredCommodityIds;

        $page = max(1, (int)($map['page'] ?? 1));
        $limit = min(500, max(1, (int)($map['limit'] ?? 100)));
        $dryRun = (int)($map['dry_run'] ?? 0) === 1;
        $startTime = $this->normalizeDateTime($map['start_date'] ?? null);
        $endTime = $this->normalizeDateTime($map['end_date'] ?? null, true);

        $query = Order::query()
            ->where('status', 1)
            ->where('delivery_status', 1)
            ->whereNotNull('secret')
            ->where('secret', '<>', '');

        if (count($commodityIds) > 0) {
            $query->whereIn('commodity_id', $commodityIds);
        }

        if ($startTime !== null) {
            $query->where('pay_time', '>=', $startTime);
        }

        if ($endTime !== null) {
            $query->where('pay_time', '<=', $endTime);
        }

        $total = (clone $query)->count();
        $orders = (clone $query)
            ->orderBy('id', 'asc')
            ->skip(($page - 1) * $limit)
            ->limit($limit)
            ->get();

        $result = [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'dryRun' => $dryRun,
            'processed' => 0,
            'wouldSync' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped' => 0,
            'marked' => 0,
            'alreadyMarked' => 0,
            'skippedCards' => 0,
            'nextPage' => ($page * $limit) < $total ? $page + 1 : null,
            'details' => []
        ];

        $client = null;
        if (!$dryRun) {
            $client = Http::make([
                'timeout' => 10,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ]);
        }

        foreach ($orders as $order) {
            $result['processed']++;
            $allCards = $this->splitSecretCards((string)$order->secret);
            $cards = $this->filterCardExtractCards($allCards, $config);
            if (count($allCards) === 0) {
                $result['skipped']++;
                $this->appendDetail($result['details'], [
                    'tradeNo' => $order->trade_no,
                    'status' => 'skipped',
                    'message' => '订单没有可同步的卡密内容'
                ]);
                continue;
            }
            if (count($cards) === 0) {
                $result['skipped']++;
                $this->appendDetail($result['details'], [
                    'tradeNo' => $order->trade_no,
                    'status' => 'skipped',
                    'message' => '订单卡密不符合Card-Extract通用卡密格式'
                ]);
                continue;
            }

            $commodity = $order->commodity;
            $pay = $order->pay;
            $filteredSecret = implode(PHP_EOL, $cards);

            if ($dryRun) {
                $result['wouldSync']++;
                $this->appendDetail($result['details'], [
                    'tradeNo' => $order->trade_no,
                    'status' => 'dry_run',
                    'cardCount' => count($cards),
                    'filtered' => count($allCards) - count($cards),
                    'commodity' => $commodity?->name ?: (string)$order->commodity_id
                ]);
                continue;
            }

            $orderData = $order->toArray();
            $orderData['secret'] = $filteredSecret;

            $payload = [
                'source' => 'acg-faka:ApiNotification:backfill',
                'trade_no' => (string)$order->trade_no,
                'order_id' => (string)$order->id,
                'commodity_id' => (string)$order->commodity_id,
                'commodity_name' => $commodity?->name ? (string)$commodity->name : '',
                'cards' => json_encode($cards, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'secret' => $filteredSecret,
                'data' => json_encode([
                    'commodity' => $commodity ? $commodity->toArray() : null,
                    'order' => $orderData,
                    'pay' => $pay ? $pay->toArray() : null
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ];

            try {
                $response = $client->post($url, [
                    'form_params' => $payload
                ]);
                $body = (string)$response->getBody()->getContents();
                $json = json_decode($body, true);
                if (!is_array($json) || empty($json['success'])) {
                    $result['failed']++;
                    $this->appendDetail($result['details'], [
                        'tradeNo' => $order->trade_no,
                        'status' => 'failed',
                        'message' => substr($body, 0, 200)
                    ]);
                    continue;
                }

                $data = (array)($json['data'] ?? []);
                $result['synced']++;
                $result['marked'] += (int)($data['marked'] ?? 0);
                $result['alreadyMarked'] += (int)($data['alreadyMarked'] ?? 0);
                $result['skippedCards'] += (int)($data['skipped'] ?? 0);
                $this->appendDetail($result['details'], [
                    'tradeNo' => $order->trade_no,
                    'status' => 'synced',
                    'cardCount' => count($cards),
                    'marked' => (int)($data['marked'] ?? 0),
                    'alreadyMarked' => (int)($data['alreadyMarked'] ?? 0),
                    'skipped' => (int)($data['skipped'] ?? 0),
                    'recordId' => (string)($data['recordId'] ?? '')
                ]);
            } catch (\Throwable $e) {
                $result['failed']++;
                $this->appendDetail($result['details'], [
                    'tradeNo' => $order->trade_no,
                    'status' => 'failed',
                    'message' => $e->getMessage()
                ]);
            }
        }

        if ($isLog) {
            Plugin::log('ApiNotification', 'Card-Extract历史订单回补完成：' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $this->json(200, $dryRun ? '历史订单回补预检完成' : '历史订单回补完成', $result);
    }
}
