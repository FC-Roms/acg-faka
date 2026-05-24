<?php
declare(strict_types=1);

namespace App\Controller\User\Api;

use App\Controller\Base\API\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Model\Coupon;
use App\Model\Order;
use App\Support\CouponOpenApi;
use Illuminate\Database\Capsule\Manager;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class CouponWallet extends User
{
    public function data(): array
    {
        $rows = [];
        $seen = [];
        $user = $this->getUser();

        $email = CouponOpenApi::normalizeEmail($user->email ?? '');
        if ($email !== '') {
            CouponOpenApi::ensureRecordTable();
            $records = Manager::table(CouponOpenApi::TABLE)
                ->where('target_email', $email)
                ->orderByDesc('id')
                ->get();

            foreach ($records as $record) {
                $coupon = Coupon::query()->find((int)$record->coupon_id);
                $this->appendCouponRow($rows, $seen, $coupon, 'qq', [
                    'qq' => $record->qq,
                    'target_email' => $record->target_email,
                    'source_label' => 'QQ 专属券'
                ]);
            }
        }

        if (Manager::schema()->hasTable('invite_reward_record')) {
            $records = Manager::table('invite_reward_record')
                ->where('user_id', $user->id)
                ->where('reward_type', 'coupon')
                ->where('status', 1)
                ->orderByDesc('id')
                ->get();

            foreach ($records as $record) {
                $result = json_decode((string)$record->reward_result, true) ?: [];
                $couponId = (int)($result['coupon_id'] ?? 0);
                if ($couponId <= 0 && !empty($result['code'])) {
                    $coupon = Coupon::query()->where('code', (string)$result['code'])->first();
                } else {
                    $coupon = Coupon::query()->find($couponId);
                }

                $this->appendCouponRow($rows, $seen, $coupon, 'invite', [
                    'source_label' => '邀请奖励',
                    'trigger_type' => $record->trigger_type,
                    'grant_time' => $record->grant_time
                ]);
            }
        }

        usort($rows, static function (array $a, array $b) {
            return strcmp((string)($b['create_time'] ?? ''), (string)($a['create_time'] ?? ''));
        });

        return $this->json(data: $this->paginate($rows));
    }

    public function records(): array
    {
        $page = max(1, (int)$this->request->post('page'));
        $limit = max(1, min(100, (int)$this->request->post('limit') ?: 10));
        $offset = ($page - 1) * $limit;

        $query = Order::query()
            ->where('owner', $this->getUser()->id)
            ->whereNotNull('coupon_id')
            ->where('coupon_id', '>', 0)
            ->with(['coupon', 'commodity'])
            ->orderByDesc('id');

        $total = (clone $query)->count();
        $list = $query->offset($offset)->limit($limit)->get()->map(static function (Order $order) {
            return [
                'id' => $order->id,
                'trade_no' => $order->trade_no,
                'coupon_code' => $order->coupon->code ?? '',
                'commodity_name' => $order->commodity->name ?? '',
                'amount' => $order->amount,
                'status' => $order->status,
                'create_time' => $order->create_time,
                'pay_time' => $order->pay_time
            ];
        })->toArray();

        return $this->json(data: ['list' => $list, 'total' => $total]);
    }

    private function appendCouponRow(array &$rows, array &$seen, ?Coupon $coupon, string $source, array $extra = []): void
    {
        if (!$coupon || isset($seen[$coupon->id])) {
            return;
        }

        $seen[$coupon->id] = true;
        $expireTime = (string)($coupon->expire_time ?? '');
        $isExpired = $expireTime !== '' && strtotime($expireTime) < time();
        $available = (int)$coupon->status === 0 && !$isExpired && (int)$coupon->life > 0;

        $rows[] = array_merge([
            'id' => $coupon->id,
            'code' => $coupon->code,
            'source' => $source,
            'source_label' => $source === 'invite' ? '邀请奖励' : 'QQ 专属券',
            'money' => $coupon->money,
            'mode' => $coupon->mode,
            'scope_text' => $this->scopeText($coupon),
            'life' => $coupon->life,
            'use_life' => $coupon->use_life,
            'status' => $coupon->status,
            'status_text' => $this->statusText($coupon, $isExpired),
            'available' => $available ? 1 : 0,
            'create_time' => $coupon->create_time,
            'expire_time' => $coupon->expire_time,
            'service_time' => $coupon->service_time,
            'trade_no' => $coupon->trade_no,
            'note' => $coupon->note
        ], $extra);
    }

    private function scopeText(Coupon $coupon): string
    {
        if ((int)$coupon->commodity_id > 0) {
            return '指定商品 #' . $coupon->commodity_id;
        }
        if ((int)$coupon->category_id > 0) {
            return '指定分类 #' . $coupon->category_id;
        }

        return '全场通用';
    }

    private function statusText(Coupon $coupon, bool $isExpired): string
    {
        if ((int)$coupon->status === 1) {
            return '已使用';
        }
        if ((int)$coupon->status === 2) {
            return '已锁定';
        }
        if ($isExpired) {
            return '已过期';
        }
        if ((int)$coupon->life <= 0) {
            return '已用完';
        }

        return '可使用';
    }

    private function paginate(array $rows): array
    {
        $page = max(1, (int)$this->request->post('page'));
        $limit = max(1, min(100, (int)$this->request->post('limit') ?: 10));
        $total = count($rows);

        return [
            'list' => array_slice($rows, ($page - 1) * $limit, $limit),
            'total' => $total
        ];
    }
}
