<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Hook;

use App\Consts\Hook as HookPoint;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Model\User;
use App\Plugin\InviteReward\Support\InviteService;
use App\Plugin\InviteReward\Support\SchemaService;
use App\Util\Plugin as PluginUtil;
use Kernel\Annotation\Hook;
use Kernel\Consts\Base;
use Kernel\Util\Context;

class Main
{
    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::INSTALL)]
    public function Install(): void
    {
        SchemaService::install();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::UPGRADE)]
    public function Update(): void
    {
        SchemaService::install();
    }

    #[\Kernel\Annotation\Plugin(state: \Kernel\Annotation\Plugin::SAVE_CONFIG)]
    public function SaveConfig(string $id, array $map): void
    {
        if ($id !== InviteService::PLUGIN) {
            return;
        }

        if (function_exists('_plugin_hook_del')) {
            _plugin_hook_del($id);
        }

        if (function_exists('_plugin_hook_add')) {
            _plugin_hook_add($id);
        }
    }

    #[Hook(point: HookPoint::KERNEL_INIT)]
    public function CaptureInviteCode(): void
    {
        (new InviteService())->captureInviteCodeFromRequest();
    }

    #[Hook(point: HookPoint::USER_VIEW_MENU)]
    public function UserMenu(): void
    {
        // 用户中心主题已内置邀请奖励菜单，避免重复输出旧插件菜单。
    }

    #[Hook(point: HookPoint::ADMIN_VIEW_MENU)]
    public function AdminMenu(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $route = (string)Context::get(Base::ROUTE);
        $active = str_starts_with($route, '/plugin/InviteReward') ? ' active' : '';
        echo '<div class="menu-item"><a class="menu-link' . $active . '" href="/plugin/InviteReward/admin/index"><span class="menu-icon"><i class="fa-duotone fa-regular fa-user-plus"></i></span><span class="menu-title">邀请奖励</span></a></div>';
    }

    #[Hook(point: HookPoint::USER_API_AUTH_REGISTER_AFTER)]
    public function AfterRegister(User $user): void
    {
        $service = new InviteService();
        try {
            $service->bindInvitee($user);
        } finally {
            $service->clearInviteCookie();
        }
    }

    #[Hook(point: HookPoint::USER_API_SECURITY_EMAIL_BIND_AFTER)]
    public function AfterEmailBind(User $user): void
    {
        (new InviteService())->handleEmailBound($user);
    }

    #[Hook(point: HookPoint::USER_API_ORDER_PAY_AFTER)]
    public function AfterPay(Commodity $commodity, Order $order, Pay $pay): void
    {
        (new InviteService())->handleFirstPaidOrder($order);
    }

    private function isEnabled(): bool
    {
        $config = PluginUtil::getConfig(InviteService::PLUGIN);

        return (int)($config['STATUS'] ?? 0) === 1;
    }
}
