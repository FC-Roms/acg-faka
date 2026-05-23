<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Support;

use App\Model\Bill;
use App\Model\Coupon;
use App\Plugin\InviteReward\Model\InviteRelation;
use App\Plugin\InviteReward\Model\InviteRewardRecord;
use App\Util\Date;
use App\Util\Plugin as PluginUtil;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager;
use Kernel\Exception\JSONException;

class RewardService
{
    public const PLUGIN = 'InviteReward';

    public function grantConfiguredRewards(InviteRelation $relation, int $userId, string $role, string $triggerType, string $triggerId = ''): void
    {
        if (!SchemaService::ready()) {
            return;
        }

        $config = PluginUtil::getConfig(self::PLUGIN);
        if ((int)($config['STATUS'] ?? 0) !== 1) {
            return;
        }

        $triggerKey = $role . '_reward_trigger';
        if (($config[$triggerKey] ?? 'none') !== $triggerType) {
            return;
        }

        $triggerId = $triggerId !== '' ? $triggerId : $triggerType;

        if ((int)($config[$role . '_coupon_enabled'] ?? 0) === 1) {
            $this->grantOnce($relation, $userId, $role, $triggerType, $triggerId, 'coupon', function () use ($config, $role) {
                return $this->createCoupon($config, $role);
            });
        }

        if ((int)($config[$role . '_coin_enabled'] ?? 0) === 1) {
            $amount = (float)($config[$role . '_coin_amount'] ?? 0);
            if ($amount > 0) {
                $this->grantOnce($relation, $userId, $role, $triggerType, $triggerId, 'coin', function () use ($config, $role, $userId, $amount) {
                    Bill::create($userId, $amount, Bill::TYPE_ADD, $this->rewardLog($role, '硬币奖励'), 1, (int)($config['bill_total'] ?? 1) === 1);

                    return [
                        'amount' => $amount,
                        'currency' => 'coin'
                    ];
                });
            }
        }
    }

    private function grantOnce(InviteRelation $relation, int $userId, string $role, string $triggerType, string $triggerId, string $rewardType, callable $grant): void
    {
        $exists = InviteRewardRecord::query()
            ->where('relation_id', $relation->id)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('trigger_type', $triggerType)
            ->where('trigger_id', $triggerId)
            ->where('reward_type', $rewardType)
            ->first();

        if ($exists) {
            return;
        }

        $record = new InviteRewardRecord();
        $record->relation_id = $relation->id;
        $record->user_id = $userId;
        $record->role = $role;
        $record->trigger_type = $triggerType;
        $record->trigger_id = $triggerId;
        $record->reward_type = $rewardType;
        $record->reward_payload = json_encode($this->payload($role, $rewardType), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $record->status = 0;
        $record->create_time = Date::current();
        $record->save();

        try {
            $result = Manager::connection()->transaction(function () use ($grant) {
                return $grant();
            });

            $record->reward_result = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $record->status = 1;
            $record->grant_time = Date::current();
            $record->save();
        } catch (\Throwable $e) {
            $record->status = 2;
            $record->remark = mb_substr($e->getMessage(), 0, 250);
            $record->save();
            PluginUtil::log(self::PLUGIN, '奖励发放失败：' . $e->getMessage());
        }
    }

    /**
     * @throws JSONException
     */
    private function createCoupon(array $config, string $role): array
    {
        $money = (float)($config[$role . '_coupon_money'] ?? 0);
        if ($money <= 0) {
            throw new JSONException('优惠券金额必须大于 0');
        }

        $userLimit = (int)($config[$role . '_coupon_user_limit'] ?? 0);
        if ($userLimit !== 0 && !Manager::schema()->hasColumn('coupon', 'user_limit')) {
            throw new JSONException('当前 coupon 表缺少 user_limit 字段，无法发放会员限制券');
        }

        $coupon = new Coupon();
        $coupon->code = $this->generateCouponCode((string)($config[$role . '_coupon_prefix'] ?? 'INV'));
        $coupon->commodity_id = (int)($config[$role . '_coupon_commodity_id'] ?? 0);
        $coupon->category_id = (int)($config[$role . '_coupon_category_id'] ?? 0);
        $coupon->owner = (int)($config[$role . '_coupon_owner'] ?? 0);
        $coupon->create_time = Date::current();
        $expireDays = (int)($config[$role . '_coupon_expire_days'] ?? 0);
        if ($expireDays > 0) {
            $coupon->expire_time = date('Y-m-d H:i:s', time() + $expireDays * 86400);
        }
        $coupon->money = $money;
        $coupon->status = 0;
        $coupon->note = $this->rewardLog($role, '优惠券奖励');
        $coupon->life = max(1, (int)($config[$role . '_coupon_life'] ?? 1));
        $coupon->mode = (int)($config[$role . '_coupon_mode'] ?? 0);
        if (Manager::schema()->hasColumn('coupon', 'user_limit')) {
            $coupon->user_limit = $userLimit;
        }
        $coupon->sku = [];
        $coupon->save();

        return [
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'money' => $coupon->money,
            'expire_time' => $coupon->expire_time ?? null
        ];
    }

    private function generateCouponCode(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'INV');
        for ($i = 0; $i < 8; $i++) {
            $code = $prefix . strtoupper(Str::generateRandStr(16));
            if (!Coupon::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return $prefix . strtoupper(Str::generateRandStr(24));
    }

    private function rewardLog(string $role, string $name): string
    {
        return ($role === 'inviter' ? '邀请人' : '新人') . $name;
    }

    private function payload(string $role, string $rewardType): array
    {
        $config = PluginUtil::getConfig(self::PLUGIN);
        $prefix = $role . '_' . $rewardType;
        $payload = [];

        foreach ($config as $key => $value) {
            if (str_starts_with((string)$key, $prefix)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
