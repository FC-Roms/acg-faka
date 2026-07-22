<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Plugin\InviteReward\Support\InviteService;
use App\Plugin\InviteReward\Support\SchemaService;
use App\Util\Plugin as PluginUtil;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class InviteReward extends User
{
    public function index(): string
    {
        if (!SchemaService::ready()) {
            SchemaService::install();
        }

        $service = new InviteService();
        $userId = (int)$this->getUser()->id;
        $code = $service->getOrCreateCode($userId);
        $config = PluginUtil::getConfig(InviteService::PLUGIN);

        return $this->theme('邀请奖励', 'INVITE_REWARD', 'User/InviteReward.html', [
            'invite_url' => $service->getInviteUrl($userId),
            'invite_code' => $code?->code ?: '',
            'summary' => $service->userSummary($userId),
            'relations' => $service->userRelations($userId),
            'rewards' => $service->userRewards($userId),
            'reward_config' => $this->rewardConfigRows($config),
            'plugin_config' => $config
        ]);
    }

    private function rewardConfigRows(array $config): array
    {
        $rows = [];

        foreach (['inviter' => '邀请人', 'invitee' => '被邀请人'] as $role => $roleText) {
            if ((int)($config[$role . '_coupon_enabled'] ?? 0) === 1 && (float)($config[$role . '_coupon_money'] ?? 0) > 0) {
                $rows[] = [
                    'role' => $roleText,
                    'type' => '优惠券',
                    'content' => $this->couponRewardText($config, $role)
                ];
            }

            if ((int)($config[$role . '_coin_enabled'] ?? 0) === 1 && (float)($config[$role . '_coin_amount'] ?? 0) > 0) {
                $rows[] = [
                    'role' => $roleText,
                    'type' => '硬币',
                    'content' => (string)$config[$role . '_coin_amount'] . ' 硬币'
                ];
            }
        }

        return $rows;
    }

    private function couponRewardText(array $config, string $role): string
    {
        $mode = (int)($config[$role . '_coupon_mode'] ?? 0) === 1 ? '折扣' : '抵扣';
        $money = (string)($config[$role . '_coupon_money'] ?? 0);
        $life = max(1, (int)($config[$role . '_coupon_life'] ?? 1));
        $expireDays = (int)($config[$role . '_coupon_expire_days'] ?? 0);
        $expireText = $expireDays > 0 ? "{$expireDays} 天内有效" : '长期有效';
        $scopeText = $this->couponScopeText($config, $role);

        return "{$mode} {$money}，{$expireText}，可用 {$life} 次，{$scopeText}";
    }

    private function couponScopeText(array $config, string $role): string
    {
        $owner = (int)($config[$role . '_coupon_owner'] ?? 0);
        $categoryId = (int)($config[$role . '_coupon_category_id'] ?? 0);
        $commodityId = (int)($config[$role . '_coupon_commodity_id'] ?? 0);

        if ($owner <= 0 && $categoryId <= 0 && $commodityId <= 0) {
            return '全平台通用';
        }

        $scope = [];
        if ($owner > 0) {
            $scope[] = '店铺ID: ' . $owner;
        }
        if ($categoryId > 0) {
            $scope[] = '分类ID: ' . $categoryId;
        }
        if ($commodityId > 0) {
            $scope[] = '商品ID: ' . $commodityId;
        }

        return implode('，', $scope);
    }
}
