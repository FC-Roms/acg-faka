<?php

declare(strict_types=1);

namespace App\Controller\Admin\Api;


use App\Controller\Base\API\Manage;
use App\Entity\Query\Delete;
use App\Entity\Query\Get;
use App\Entity\Query\Save;
use App\Interceptor\ManageSession;
use App\Model\ManageLog;
use App\Service\Query;
use App\Util\Plugin;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use App\Util\Date;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Kernel\Annotation\Inject;
use Kernel\Annotation\Interceptor;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Order extends Manage
{
    private const EXTRACT_RECORD_TABLE = 'api_notification_extract_record';

    #[Inject]
    private Query $query;

    /**
     * @return array
     */
    public function data(): array
    {
        $map = $_POST;
        $get = new Get(\App\Model\Order::class);
        $get->setPaginate((int)$this->request->post("page"), (int)$this->request->post("limit"));
        $get->setWhere($map);
        $raw = [];
        $data = $this->query->get($get, function (Builder $builder) use (&$raw) {
            $raw['order_amount'] = (clone $builder)->sum("amount");
            $raw['order_cost'] = (clone $builder)->sum("cost");
            return $builder->with([
                'coupon' => function (Relation $relation) {
                    $relation->select(["id", "code"]);
                },
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'user' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "name", "cover", "delivery_way", "contact_type"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                },
                //推广者
                'promote' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                //分站订单
                'substationUser' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'card'
            ]);
        });

        return $this->json(data: array_merge($data, $raw));
    }


    /**
     * @return array
     * @throws JSONException
     */
    public function save(): array
    {
        $map = $this->request->post(flags: Filter::NORMAL);
        if (!$map['secret']) {
            throw new JSONException("请填写要发货的内容");
        }
        $save = new Save(\App\Model\Order::class);
        $save->setMap(['id' => (int)$map['id'], 'secret' => $map['secret'], 'delivery_status' => 1]);
        $save = $this->query->save($save);
        if (!$save) {
            throw new JSONException("发货失败");
        }

        ManageLog::log($this->getManage(), "[手动发货]({$map['id']})修改了发货信息");
        return $this->json(200, '（＾∀＾）发货成功');
    }


    /**
     * @return array
     */
    public function clear(): array
    {
        \App\Model\Order::query()
            ->where("create_time", "<", date("Y-m-d H:i:s", time() - 1800))
            ->where("status", 0)->delete();

        ManageLog::log($this->getManage(), "进行了一键清理无用商品订单操作");
        return $this->json(200, '（＾∀＾）清理完成');
    }

    private function normalizeTradeNos(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[,\r\n|]+/', $value);
        }

        $tradeNos = array_map(static fn($item) => trim((string)$item), (array)$value);
        $tradeNos = array_filter($tradeNos, static fn(string $tradeNo) => $tradeNo !== '');

        return array_values(array_unique($tradeNos));
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

    public function extractStatus(): array
    {
        $tradeNos = $this->normalizeTradeNos($this->request->post('trade_nos', Filter::NORMAL));
        if (count($tradeNos) === 0) {
            return $this->json(200, 'ok', []);
        }

        $this->ensureExtractRecordTable();
        $config = Plugin::getConfig('ApiNotification', false);
        $orders = \App\Model\Order::query()
            ->whereIn('trade_no', $tradeNos)
            ->get(['id', 'trade_no', 'secret']);

        $cardsByTradeNo = [];
        $allCards = [];
        foreach ($orders as $order) {
            $cards = $this->filterCardExtractCards($this->splitSecretCards((string)$order->secret), $config);
            $cardsByTradeNo[(string)$order->trade_no] = $cards;
            $allCards = array_merge($allCards, $cards);
        }

        $allCards = array_values(array_unique($allCards));
        $recordByCard = [];
        if (count($allCards) > 0) {
            $records = Manager::table(self::EXTRACT_RECORD_TABLE)
                ->whereIn('card', $allCards)
                ->get();

            foreach ($records as $record) {
                $recordByCard[(string)$record->card] = $record;
            }
        }

        $statusMap = [];
        foreach ($tradeNos as $tradeNo) {
            $cards = $cardsByTradeNo[$tradeNo] ?? [];
            $items = [];
            $extracted = 0;
            foreach ($cards as $card) {
                $record = $recordByCard[$card] ?? null;
                $isExtracted = $record !== null && (int)$record->status === 1;
                if ($isExtracted) {
                    $extracted++;
                }
                $items[] = [
                    'card' => $card,
                    'extracted' => $isExtracted,
                    'extracted_at' => $isExtracted ? (string)$record->extracted_at : ''
                ];
            }

            $statusMap[$tradeNo] = [
                'total' => count($cards),
                'extracted' => $extracted,
                'cards' => $items
            ];
        }

        return $this->json(200, 'ok', $statusMap);
    }


    /**
     * @return void
     */
    public function export(): void
    {
        ignore_user_abort(true);
        set_time_limit(0);

        $map = $_GET;
        $exportStatus = (int)($map['export_status'] ?? 0);
        $exportNum = (int)($map['export_num'] ?? 0);

        unset($map['export_status'], $map['export_num']);

        $get = new Get(\App\Model\Order::class);
        $get->setWhere($map);

        if ($exportNum > 0) {
            $get->setPaginate(1, $exportNum);
        }

        $data = $this->query->get($get, function (Builder $builder) {
            return $builder->with([
                'coupon' => function (Relation $relation) {
                    $relation->select(["id", "code"]);
                },
                'owner' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'user' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'commodity' => function (Relation $relation) {
                    $relation->select(["id", "name", "cover", "delivery_way", "contact_type"]);
                },
                'pay' => function (Relation $relation) {
                    $relation->select(["id", "name", "icon"]);
                },
                'promote' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                },
                'substationUser' => function (Relation $relation) {
                    $relation->select(["id", "username", "avatar", "recharge"]);
                }
            ]);
        });

        $list = $data['list'] ?? [];
        $ids = [];

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="订单导出-' . Date::current("YmdHis") . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $fp = fopen('php://output', 'w');

        fwrite($fp, "\xEF\xBB\xBF");

        fputcsv($fp, [
            '订单号',
            '金额',
            '商品名称',
            '数量',
            '支付方式',
            '下单时间',
            '下单IP',
            '下单设备',
            '支付时间',
            '订单状态',
            '联系方式',
            '发货状态',
            '优惠券',
            '客户',
            '推广人',
            '分站',
            '分站手续费',
            '接口手续费',
            '推广分成',
            '返利'
        ]);

        foreach ($list as $d) {
            $ids[] = $d['id'];

            $deviceText = match ((int)($d['create_device'] ?? 0)) {
                1 => '安卓',
                2 => 'IOS',
                3 => 'iPad',
                default => 'PC',
            };

            $statusText = match ((int)($d['status'] ?? 0)) {
                0 => '未支付',
                1 => '已支付',
                default => '未知',
            };

            $deliveryStatusText = match ((int)($d['delivery_status'] ?? 0)) {
                0 => '未发货',
                1 => '已发货',
                default => '未知',
            };


            fputcsv($fp, [
                (string)($d['trade_no'] ?? ''),
                (string)($d['amount'] ?? 0),
                (string)($d['commodity']['name'] ?? ''),
                (string)($d['card_num'] ?? 0),
                (string)($d['pay']['name'] ?? ''),
                (string)($d['create_time'] ?? ''),
                (string)($d['create_ip'] ?? ''),
                $deviceText,
                (string)($d['pay_time'] ?? ''),
                $statusText,
                (string)($d['contact'] ?? ''),
                $deliveryStatusText,
                (string)($d['coupon']['code'] ?? ''),
                (string)($d['owner']['username'] ?? ''),
                (string)($d['promote']['username'] ?? ''),
                (string)($d['user']['username'] ?? ''),
                (string)($d['cost'] ?? 0),
                (string)($d['pay_cost'] ?? 0),
                (string)($d['divide_amount'] ?? 0),
                (string)($d['rebate'] ?? 0),
            ]);
        }

        fclose($fp);

        if ($exportStatus === 1 && !empty($ids)) {
            try {
                $deleteBatchEntity = new Delete(\App\Model\Order::class, $ids);
                $this->query->delete($deleteBatchEntity);
            } catch (\Exception $e) {
            }
        }

        ManageLog::log($this->getManage(), '[订单导出]导出订单，共计：' . count($list));
        exit;
    }
}
