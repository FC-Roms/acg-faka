<?php
declare(strict_types=1);

namespace App\Plugin\ApiNotification\Hook;

use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Util\Http;
use App\Util\Plugin;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Annotation\Hook;

/**
 *
 */
class Main
{
    private const EXTRACT_RECORD_TABLE = 'api_notification_extract_record';

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

    private function getSelectedCommodityIds(array $config): array
    {
        $commodityIds = array_map('strval', (array)($config['commodity'] ?? []));
        $commodityIds = array_filter($commodityIds, static fn(string $id) => $id !== '');

        return array_values(array_unique($commodityIds));
    }

    private function isSelectedCommodity(array $config, Commodity $commodity, bool $isLog, string $tradeNo = ''): bool
    {
        $commodityIds = $this->getSelectedCommodityIds($config);
        if (count($commodityIds) === 0) {
            if ($isLog) {
                $prefix = $tradeNo !== '' ? "订单 {$tradeNo} " : '';
                Plugin::log("ApiNotification", "{$prefix}未配置商品白名单，按所有商品处理");
            }
            return true;
        }

        if (in_array((string)$commodity->id, $commodityIds, true)) {
            return true;
        }

        if ($isLog) {
            $prefix = $tradeNo !== '' ? "订单 {$tradeNo} " : '';
            Plugin::log("ApiNotification", "{$prefix}商品 {$commodity->id} 未在 ApiNotification 商品选择中，跳过通知");
        }

        return false;
    }

    private function syncCardExtractShipment(array $config, Commodity $commodity, Order $order, ?Pay $pay, bool $isLog, string $source = 'acg-faka:ApiNotification'): void
    {
        if (empty($config['card_extract_sync'])) {
            if ($isLog) {
                Plugin::log("ApiNotification", "Card-Extract同步未开启，跳过订单 {$order->trade_no}");
            }
            return;
        }

        $url = trim((string)($config['card_extract_url'] ?? ''));
        $token = trim((string)($config['card_extract_token'] ?? ''));
        if ($url === '' || $token === '') {
            if ($isLog) {
                Plugin::log("ApiNotification", "Card-Extract同步已开启，但接口地址或Token为空，跳过同步");
            }
            return;
        }

        if ((int)$order->delivery_status !== 1) {
            if ($isLog) {
                Plugin::log("ApiNotification", "订单 {$order->trade_no} 尚未自动发货，跳过Card-Extract同步");
            }
            return;
        }

        $secret = (string)$order->secret;
        $allCards = $this->splitSecretCards($secret);
        $cards = $this->filterCardExtractCards($allCards, $config);
        if (count($allCards) === 0) {
            if ($isLog) {
                Plugin::log("ApiNotification", "订单 {$order->trade_no} 没有可同步的卡密内容，跳过Card-Extract同步");
            }
            return;
        }
        if (count($cards) === 0) {
            if ($isLog) {
                Plugin::log("ApiNotification", "订单 {$order->trade_no} 发货内容不符合Card-Extract通用卡密格式，跳过同步");
            }
            return;
        }

        try {
            $client = Http::make([
                'timeout' => 5,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token
                ]
            ]);
            $filteredSecret = implode(PHP_EOL, $cards);
            $orderData = $order->toArray();
            $orderData['secret'] = $filteredSecret;

            $payload = [
                'source' => $source,
                'trade_no' => $order->trade_no,
                'order_id' => (string)$order->id,
                'commodity_id' => (string)$commodity->id,
                'commodity_name' => (string)$commodity->name,
                'cards' => json_encode($cards, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'secret' => $filteredSecret,
                'data' => json_encode([
                    "commodity" => $commodity->toArray(),
                    "order" => $orderData,
                    "pay" => $pay ? $pay->toArray() : null
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ];

            if ($isLog) {
                Plugin::log("ApiNotification", "准备同步Card-Extract已发货卡密：订单={$order->trade_no}，数量=" . count($cards) . "，已过滤=" . (count($allCards) - count($cards)));
            }

            $response = $client->post($url, [
                "form_params" => $payload
            ]);

            if ($isLog) {
                Plugin::log("ApiNotification", "Card-Extract同步完成，返回结果：" . $response->getBody()->getContents());
            }
        } catch (\Error | \Exception $e) {
            if ($isLog) {
                Plugin::log("ApiNotification", "Card-Extract同步失败，错误原因：" . $e->getMessage());
            }
        }
    }

    /**
     * 更新或安装时，安装数据库支持
     */
    private function InstallDB(): void
    {
        //判断字段是否存在，不存在则创建字段
        $extend = Manager::schema()->hasColumn("commodity", "extend");
        if (!$extend) {
            Manager::schema()->table("commodity", function (Blueprint $blueprint) {
                $blueprint->text("extend")->nullable(true)->default(null);
            });
        }

        if (!Manager::schema()->hasTable(self::EXTRACT_RECORD_TABLE)) {
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
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::INSTALL)]
    public function Install(): void
    {
        $this->InstallDB();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::UPGRADE)]
    public function Update(): void
    {
        $this->InstallDB();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::SAVE_CONFIG)]
    public function SaveConfig(string $id, array $map): void
    {
        if ($id !== 'ApiNotification') {
            return;
        }

        if (function_exists('_plugin_hook_del')) {
            _plugin_hook_del($id);
        }

        if (function_exists('_plugin_hook_add')) {
            _plugin_hook_add($id);
        }
    }

    #[Hook(point: \App\Consts\Hook::USER_API_ORDER_PAY_AFTER)]
    public function Notification(Commodity $commodity, Order $order, Pay $pay): void
    {
        $config = Plugin::getConfig("ApiNotification");
        $isLog = !empty($config['log']);

        try {
            if (!$this->isSelectedCommodity($config, $commodity, $isLog, (string)$order->trade_no)) {
                return;
            }

            $notified = false;
            $notificationUrl = trim((string)($config['url'] ?? ''));
            if ($notificationUrl !== '') {
                try {
                    if ($isLog) {
                        Plugin::log("ApiNotification", "捕获到订单支付成功：{$order->trade_no}，准备请求API：" . $notificationUrl);
                    }

                    $client = Http::make(['timeout' => 5, 'headers' => (array)json_decode((string)($config['headers'] ?? ''), true)]);
                    $data = [];
                    $data['data'] = json_encode([
                        "commodity" => $commodity->toArray(),
                        "order" => $order->toArray(),
                        "pay" => $pay->toArray()
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    if ($isLog) {
                        Plugin::log("ApiNotification", "准备商品：{$commodity->name}");
                    }

                    $param = json_decode((string)($config['param'] ?? ''), true);

                    if (!empty($param)) {
                        foreach ($param as $key => $val) {
                            $data[$key] = $val;
                        }
                    }

                    $data['sign'] = Str::generateSignature($data, (string)($config['key'] ?? ''));

                    if ($isLog) {
                        Plugin::log("ApiNotification", "本地生成签名：" . $data['sign']);
                    }

                    if ((int)($config['request_type'] ?? 0) == 0) {
                        $response = $client->post($notificationUrl, [
                            "form_params" => $data
                        ]);
                    } else {
                        $response = $client->get($notificationUrl, [
                            "query" => $data
                        ]);
                    }

                    if ($isLog) {
                        Plugin::log("ApiNotification", "请求完成，返回结果：" . $response->getBody()->getContents());
                    }
                    $notified = true;
                } catch (\Error | \Exception $e) {
                    if ($isLog) {
                        Plugin::log("ApiNotification", "请求失败，错误原因：" . $e->getMessage());
                    }
                }
            }

            $this->syncCardExtractShipment($config, $commodity, $order, $pay, $isLog, 'acg-faka:ApiNotification:pay');

            if ($notified) {
                //通知结束，将订单改成已发货
                $order->delivery_status = 1;
                $order->save();
            }

        } catch (\Error | \Exception $e) {
            if ($isLog) {
                Plugin::log("ApiNotification", "请求失败，错误原因：" . $e->getMessage());
            }
        }
    }

    #[Hook(point: \App\Consts\Hook::USER_API_INDEX_QUERY_SECRET)]
    public function QuerySecret(Order $order): void
    {
        $config = Plugin::getConfig("ApiNotification");
        $isLog = !empty($config['log']);

        try {
            $commodity = $order->commodity;
            if (!$commodity instanceof Commodity) {
                if ($isLog) {
                    Plugin::log("ApiNotification", "订单 {$order->trade_no} 未找到商品信息，无法同步Card-Extract");
                }
                return;
            }

            if (!$this->isSelectedCommodity($config, $commodity, $isLog, (string)$order->trade_no)) {
                return;
            }

            // 用户查询卡密时再做一次同步兜底，避免支付回调阶段未触发或未完成时漏标记。
            $this->syncCardExtractShipment($config, $commodity, $order, $order->pay, $isLog, 'acg-faka:ApiNotification:query-secret');
        } catch (\Error | \Exception $e) {
            if ($isLog) {
                Plugin::log("ApiNotification", "查询卡密后同步Card-Extract失败，错误原因：" . $e->getMessage());
            }
        }
    }


    #[Hook(point: \App\Consts\Hook::ADMIN_VIEW_COMMODITY_POST)]
    public function CommodityPost(): void
    {
        echo '{title: "API通知", name: "extend", type: "json", tips: "该扩展为API开发提供数据支持", default: ""},';
    }
}
