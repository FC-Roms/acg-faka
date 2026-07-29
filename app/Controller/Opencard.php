<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Card as CardModel;
use App\Model\Commodity;
use App\Model\User;
use App\Support\CouponOpenApi;
use App\Util\Date;
use App\Util\Plugin;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Annotation\Inject;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

class Opencard
{
    private const EXTRACT_RECORD_TABLE = 'api_notification_extract_record';

    #[Inject]
    private \App\Service\Order $orderService;

    private function normalizeHeaderName(string $name): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $name));
    }

    private function getApiHeader(Request $request, string $name): string
    {
        $target = $this->normalizeHeaderName($name);

        foreach ((array)$request->header() as $key => $value) {
            if ($this->normalizeHeaderName((string)$key) === $target && $value !== '') {
                return (string)$value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with((string)$key, 'HTTP_')) {
                continue;
            }

            $headerName = substr((string)$key, 5);
            if ($this->normalizeHeaderName($headerName) === $target && $value !== '') {
                return (string)$value;
            }
        }

        return '';
    }

    private function getBearerToken(Request $request): string
    {
        $authorization = $this->getApiHeader($request, 'Authorization');
        if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($this->getApiHeader($request, 'X-Card-Extract-Token'));
    }

    private function ensureExtractRecordTable(): void
    {
        if (Manager::schema()->hasTable(self::EXTRACT_RECORD_TABLE)) {
            return;
        }

        Manager::schema()->create(self::EXTRACT_RECORD_TABLE, function (Blueprint $blueprint) {
            $blueprint->increments('id');
            $blueprint->string('trade_no', 64)->nullable(true)->index();
            $blueprint->string('order_id', 64)->nullable(true);
            $blueprint->string('commodity_id', 64)->nullable(true)->index();
            $blueprint->string('card', 191)->unique();
            $blueprint->tinyInteger('status')->default(1);
            $blueprint->dateTime('extracted_at')->nullable(true)->index();
            $blueprint->string('source', 64)->nullable(true);
            $blueprint->text('raw')->nullable(true);
            $blueprint->dateTime('created_at')->nullable(true);
            $blueprint->dateTime('updated_at')->nullable(true);
        });
    }

    private function splitSecretCards(string $secret): array
    {
        $cards = preg_split('/[\r\n|]+/', $secret) ?: [];
        $cards = array_map('trim', $cards);
        $cards = array_filter($cards, static fn(string $card) => $card !== '');

        return array_values(array_unique($cards));
    }

    private function normalizeDateTime(?string $value): string
    {
        $time = strtotime(trim((string)$value));
        if ($time === false) {
            $time = time();
        }

        return date('Y-m-d H:i:s', $time);
    }

    private function normalizeExtractCards(array $payload): array
    {
        $cards = $payload['cards'] ?? [];
        if (is_string($cards)) {
            $decoded = json_decode($cards, true);
            $cards = is_array($decoded) ? $decoded : $this->splitSecretCards($cards);
        }

        if (!is_array($cards) || count($cards) === 0) {
            $card = trim((string)($payload['card'] ?? ''));
            $cards = $card === '' ? [] : [$card];
        }

        $normalized = [];
        foreach ($cards as $item) {
            if (is_array($item)) {
                $card = trim((string)($item['card'] ?? $item['code'] ?? ''));
                if ($card === '') {
                    continue;
                }
                $normalized[] = [
                    'card' => $card,
                    'trade_no' => trim((string)($item['trade_no'] ?? $item['tradeNo'] ?? $payload['trade_no'] ?? $payload['tradeNo'] ?? '')),
                    'order_id' => trim((string)($item['order_id'] ?? $item['orderId'] ?? $payload['order_id'] ?? $payload['orderId'] ?? '')),
                    'commodity_id' => trim((string)($item['commodity_id'] ?? $item['commodityId'] ?? $payload['commodity_id'] ?? $payload['commodityId'] ?? '')),
                    'extracted_at' => $this->normalizeDateTime((string)($item['extracted_at'] ?? $item['extractedAt'] ?? $payload['extracted_at'] ?? $payload['extractedAt'] ?? '')),
                    'source' => trim((string)($item['source'] ?? $payload['source'] ?? 'card-extract:universal-exchange')),
                    'raw' => $item
                ];
                continue;
            }

            $card = trim((string)$item);
            if ($card === '') {
                continue;
            }
            $normalized[] = [
                'card' => $card,
                'trade_no' => trim((string)($payload['trade_no'] ?? $payload['tradeNo'] ?? '')),
                'order_id' => trim((string)($payload['order_id'] ?? $payload['orderId'] ?? '')),
                'commodity_id' => trim((string)($payload['commodity_id'] ?? $payload['commodityId'] ?? '')),
                'extracted_at' => $this->normalizeDateTime((string)($payload['extracted_at'] ?? $payload['extractedAt'] ?? '')),
                'source' => trim((string)($payload['source'] ?? 'card-extract:universal-exchange')),
                'raw' => $item
            ];
        }

        return $normalized;
    }

    public function extract(Request $request): array
    {
        $config = Plugin::getConfig('ApiNotification', false);
        $expectedToken = trim((string)($config['card_extract_token'] ?? ''));
        if ($expectedToken === '' || !hash_equals($expectedToken, $this->getBearerToken($request))) {
            throw new JSONException('Card-Extract Token校验失败');
        }

        $payload = $request->json(flags: Filter::NORMAL);
        if (!is_array($payload) || count($payload) === 0) {
            $payload = $request->post(flags: Filter::NORMAL);
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $cards = $this->normalizeExtractCards($payload);
        if (count($cards) === 0) {
            throw new JSONException('未收到有效提取卡密');
        }

        $this->ensureExtractRecordTable();
        $now = Date::current();
        $saved = 0;

        foreach ($cards as $item) {
            $record = [
                'trade_no' => $item['trade_no'],
                'order_id' => $item['order_id'],
                'commodity_id' => $item['commodity_id'],
                'status' => 1,
                'extracted_at' => $item['extracted_at'],
                'source' => $item['source'],
                'raw' => json_encode($item['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now
            ];

            $existing = Manager::table(self::EXTRACT_RECORD_TABLE)->where('card', $item['card'])->first();
            if (!$existing) {
                $record['created_at'] = $now;
            }

            Manager::table(self::EXTRACT_RECORD_TABLE)->updateOrInsert(['card' => $item['card']], $record);
            $saved++;
        }

        if (!empty($config['log'])) {
            Plugin::log('ApiNotification', '收到Card-Extract提取回传，记录数量：' . $saved);
        }

        return ['code' => 200, 'msg' => '提取状态记录成功', 'data' => ['saved' => $saved]];
    }

    public function upload(Request $request): array
    {
        $appId = $this->getApiHeader($request, "Api-Id");
        $signature = $this->getApiHeader($request, "Api-Signature");
        
        if (!$appId || !$signature) {
            throw new JSONException("缺少API认证信息");
        }

        $user = User::query()->where("id", (int)$appId)->first();
        
        if (!$user || $user->status != 1) {
            throw new JSONException("无效的商户ID");
        }

        $postData = $request->post();
        $expectedSignature = Str::generateSignature($postData, $user->app_key);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new JSONException("签名验证失败");
        }

        $commodityId = $request->post("commodity_id", Filter::INTEGER);
        $raceGetMode = $request->post("race_get_mode", Filter::INTEGER);
        $race = $raceGetMode == 1 ? $request->post("race_input", Filter::NORMAL) : $request->post("race", Filter::NORMAL);
        $sku = $request->post("sku", Filter::NORMAL) ?: [];
        $cardType = $request->post("card_type", Filter::INTEGER);
        $unique = (bool)$request->post("unique", Filter::INTEGER);
        $note = $request->post("note", Filter::NORMAL);

        if ($commodityId == 0) {
            throw new JSONException("请选择商品");
        }

        if (!Commodity::query()->where("owner", $user->id)->where("id", $commodityId)->exists()) {
            throw new JSONException("商品不存在或无权操作");
        }

        $cards = trim(trim((string)$request->post("secret", Filter::NORMAL)), PHP_EOL);

        if ($cards == '') {
            throw new JSONException("请至少添加1条卡密信息");
        }

        $cards = explode(PHP_EOL, $cards);
        $count = count($cards);

        $success = 0;
        $error = 0;
        $date = Date::current();
        $userId = $user->id;

        foreach ($cards as $card) {
            $cardt = trim(trim($card), PHP_EOL);
            if ($cardt == "") {
                $error++;
                continue;
            }

            $cardObj = new CardModel();

            if ($cardType == 0) {
                $cardObj->secret = $cardt;
            } else {
                $list = explode("║", $cardt);
                if (count($list) < 2) {
                    $error++;
                    continue;
                }
                $cardObj->secret = trim($list[0]);

                if (isset($list[1])) {
                    $cardObj->draft = trim($list[1]);
                }

                if (isset($list[2])) {
                    $cardObj->draft_premium = (float)trim($list[2]);
                }

                if (isset($list[3])) {
                    $cardObj->cost = (float)trim($list[3]);
                }
            }

            if ($unique) {
                if (CardModel::query()->where("owner", $userId)->where("secret", $cardObj->secret)->first()) {
                    $error++;
                    continue;
                }
            }

            $cardObj->commodity_id = $commodityId;
            $cardObj->owner = $userId;
            if ($note) {
                $cardObj->note = $note;
            }
            $cardObj->status = 0;
            $cardObj->sku = $sku;
            $cardObj->create_time = $date;

            if ($race) {
                $cardObj->race = $race;
            }

            try {
                $cardObj->save();
                $success++;
            } catch (\Exception $e) {
                $error++;
            }
        }

        return ["code" => 200, "msg" => "共计导入:{$count}张卡密，成功:{$success}张，失败：{$error}张", "data" => ["total" => $count, "success" => $success, "error" => $error]];
    }

    public function remove(Request $request): array
    {
        $appId = $this->getApiHeader($request, "Api-Id");
        $signature = $this->getApiHeader($request, "Api-Signature");

        if (!$appId || !$signature) {
            throw new JSONException("缺少API认证信息");
        }

        $user = User::query()->where("id", (int)$appId)->first();

        if (!$user || $user->status != 1) {
            throw new JSONException("无效的商户ID");
        }

        $postData = $request->post();
        $expectedSignature = Str::generateSignature($postData, $user->app_key);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new JSONException("签名验证失败");
        }

        $commodityId = $request->post("commodity_id", Filter::INTEGER);
        if ($commodityId > 0 && !Commodity::query()->where("owner", $user->id)->where("id", $commodityId)->exists()) {
            throw new JSONException("商品不存在或无权操作");
        }

        $cards = $request->post("cards", Filter::NORMAL);
        if (is_string($cards)) {
            $decoded = json_decode($cards, true);
            $cards = is_array($decoded) ? $decoded : $this->splitSecretCards($cards);
        }

        if (!is_array($cards) || count($cards) === 0) {
            $secret = trim((string)$request->post("secret", Filter::NORMAL));
            $cards = $secret === '' ? [] : $this->splitSecretCards($secret);
        }

        $cards = array_map(static fn($card) => trim((string)$card), $cards);
        $cards = array_values(array_unique(array_filter($cards, static fn(string $card) => $card !== '')));

        if (count($cards) === 0) {
            throw new JSONException("请至少提供1条需要清除的卡密");
        }

        $query = CardModel::query()
            ->where("owner", $user->id)
            ->where("status", 0)
            ->whereIn("secret", $cards);

        if ($commodityId > 0) {
            $query->where("commodity_id", $commodityId);
        }

        $removed = (clone $query)->count();
        if ($removed > 0) {
            $query->delete();
        }

        $missing = max(0, count($cards) - $removed);
        $scope = $commodityId > 0 ? "商品{$commodityId}" : "当前商户";

        return [
            "code" => 200,
            "msg" => "{$scope}共计请求清除:" . count($cards) . "张卡密，成功:{$removed}张，未找到:{$missing}张",
            "data" => [
                "commodity_id" => $commodityId > 0 ? $commodityId : null,
                "total" => count($cards),
                "removed" => $removed,
                "missing" => $missing
            ]
        ];
    }

    /**
     * 第三方一键发卡。
     *
     * 参数兼容 Card-Extract 的 /api/get-card 接口，其中 item_id 为第三方
     * 平台商品 ID，commodity_id 为本站商品 ID。
     */
    public function getCard(Request $request): array
    {
        if ($request->method() !== 'POST') {
            throw new JSONException('仅支持 POST 请求');
        }

        $expectedToken = CouponOpenApi::configuredToken();
        if ($expectedToken === '' || !hash_equals($expectedToken, $this->getBearerToken($request))) {
            throw new JSONException('接口 Token 错误或未配置');
        }

        $postData = $request->json(flags: Filter::NORMAL);
        if (!is_array($postData) || count($postData) === 0) {
            $postData = $request->post(flags: Filter::NORMAL);
        }
        if (!is_array($postData)) {
            $postData = [];
        }

        $externalItemId = $postData['item_id'] ?? null;
        if (!is_scalar($externalItemId) || trim((string)$externalItemId) === '') {
            throw new JSONException("请提供有效的第三方商品ID（item_id）");
        }
        $commodityId = filter_var($postData['commodity_id'] ?? null, FILTER_VALIDATE_INT);
        if ($commodityId === false || $commodityId <= 0) {
            throw new JSONException("请提供有效的本站商品ID（commodity_id）");
        }

        $quantityValue = $postData['order_quantity'] ?? '';
        if ($quantityValue === '') {
            $quantityValue = $postData['count'] ?? 1;
        }
        $quantity = filter_var($quantityValue, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 150) {
            throw new JSONException("发卡数量必须在1-150之间");
        }

        /** @var Commodity|null $commodity */
        $commodity = Commodity::query()
            ->where("id", $commodityId)
            ->first();
        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        $race = trim((string)($postData['race'] ?? ''));
        if (mb_strlen($race) > 32) {
            throw new JSONException("商品分类长度不能超过32个字符");
        }

        $externalOrderId = trim((string)($postData['order_id'] ?? ''));
        if (mb_strlen($externalOrderId) > 128) {
            throw new JSONException("外部订单号长度不能超过128个字符");
        }
        $requestNo = $externalOrderId === ''
            ? ''
            : substr(sha1("opencard:{$externalOrderId}"), 0, 19);

        $result = $this->orderService->giftOrder(
            $commodity,
            $race,
            $quantity,
            requestNo: $requestNo,
            requireActive: true
        );

        return [
            "code" => 200,
            "success" => true,
            "msg" => !empty($result['repeated']) ? "该外部订单已发卡" : "发卡成功",
            "data" => $result['secret'],
            "meta" => [
                "trade_no" => $result['tradeNo'],
                "order_id" => $externalOrderId,
                "item_id" => $externalItemId,
                "commodity_id" => $commodityId,
                "order_quantity" => $quantity,
                "repeated" => (bool)($result['repeated'] ?? false)
            ]
        ];
    }

    public function commodities(Request $request): array
    {
        $appId = $this->getApiHeader($request, "Api-Id");
        $signature = $this->getApiHeader($request, "Api-Signature");
        
        if (!$appId || !$signature) {
            throw new JSONException("缺少API认证信息");
        }

        $user = User::query()->where("id", (int)$appId)->first();
        
        if (!$user || $user->status != 1) {
            throw new JSONException("无效的商户ID");
        }

        $postData = $request->post();
        $expectedSignature = Str::generateSignature($postData, $user->app_key);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new JSONException("签名验证失败");
        }

        $commodities = Commodity::query()
            ->where("owner", $user->id)
            ->where("status", 1)
            ->select(["id", "name", "code"])
            ->get()
            ->toArray();

        return ["code" => 200, "msg" => "success", "data" => $commodities];
    }
}
