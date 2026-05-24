<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Controller;

use App\Controller\Base\View\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Plugin\InviteReward\Support\InviteService;
use App\Plugin\InviteReward\Support\SchemaService;
use App\Util\Client;
use App\Util\Plugin as PluginUtil;
use Kernel\Annotation\Interceptor;

class Index extends UserPlugin
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    #[Interceptor([Waf::class, UserSession::class])]
    public function index(): string
    {
        if (!SchemaService::ready()) {
            SchemaService::install();
        }

        $service = new InviteService();
        $user = $this->getUser();
        $code = $service->getOrCreateCode((int)$user->id);
        $config = PluginUtil::getConfig(InviteService::PLUGIN);

        return $this->render('邀请奖励', 'User/Index.html', [
            'invite_url' => $service->getInviteUrl((int)$user->id),
            'invite_code' => $code?->code ?: '',
            'summary' => $service->userSummary((int)$user->id),
            'relations' => $service->userRelations((int)$user->id),
            'rewards' => $service->userRewards((int)$user->id),
            'reward_config' => $this->rewardConfigRows($config),
            'plugin_config' => $config
        ], true);
    }

    public function go(): void
    {
        $code = (string)($_GET['code'] ?? $_GET['invite'] ?? '');
        (new InviteService())->setInviteCookie($code);

        Client::redirect('/user/authentication/register?invite=' . urlencode($code), '正在进入注册页面', 0);
    }
    private function rewardConfigRows(array $config): array
    {
        $rows = [];

        foreach (['inviter' => '邀请人奖励', 'invitee' => '新人奖励'] as $role => $roleText) {
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
                    'content' => (string)$config[$role . '_coin_amount'] . ' 枚'
                ];
            }
        }

        return $rows;
    }

    private function couponRewardText(array $config, string $role): string
    {
        $mode = (int)($config[$role . '_coupon_mode'] ?? 0) === 1 ? '折扣' : '立减';
        $money = (string)($config[$role . '_coupon_money'] ?? 0);
        $life = max(1, (int)($config[$role . '_coupon_life'] ?? 1));
        $expireDays = (int)($config[$role . '_coupon_expire_days'] ?? 0);
        $expireText = $expireDays > 0 ? "有效期 {$expireDays} 天" : '长期有效';
        $limitText = $this->couponUserLimitText((int)($config[$role . '_coupon_user_limit'] ?? 0));
        $scopeText = $this->couponScopeText($config, $role);

        return "{$mode} {$money}，{$expireText}，可用 {$life} 次，{$limitText}，{$scopeText}";
    }

    private function couponUserLimitText(int $limit): string
    {
        return match ($limit) {
            1 => '仅新客绑定邮箱/手机可用',
            2 => '登录会员每人限用 1 次',
            default => '不限使用人群'
        };
    }

    private function couponScopeText(array $config, string $role): string
    {
        $owner = (int)($config[$role . '_coupon_owner'] ?? 0);
        $categoryId = (int)($config[$role . '_coupon_category_id'] ?? 0);
        $commodityId = (int)($config[$role . '_coupon_commodity_id'] ?? 0);

        if ($owner <= 0 && $categoryId <= 0 && $commodityId <= 0) {
            return '平台通用';
        }

        $scope = [];
        if ($owner > 0) {
            $scope[] = '商户ID：' . $owner;
        }
        if ($categoryId > 0) {
            $scope[] = '分类ID：' . $categoryId;
        }
        if ($commodityId > 0) {
            $scope[] = '商品ID：' . $commodityId;
        }

        return implode('，', $scope);
    }
}
