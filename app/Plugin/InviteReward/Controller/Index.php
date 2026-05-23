<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Controller;

use App\Controller\Base\View\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Plugin\InviteReward\Support\InviteService;
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
        $service = new InviteService();
        $user = $this->getUser();

        return $this->render('邀请奖励', 'User/Index.html', [
            'invite_url' => $service->getInviteUrl((int)$user->id),
            'invite_code' => $service->getOrCreateCode((int)$user->id)?->code ?: '',
            'summary' => $service->userSummary((int)$user->id),
            'relations' => $service->userRelations((int)$user->id),
            'rewards' => $service->userRewards((int)$user->id),
            'plugin_config' => PluginUtil::getConfig(InviteService::PLUGIN)
        ], true);
    }

    public function go(): void
    {
        $code = (string)($_GET['code'] ?? $_GET['invite'] ?? '');
        (new InviteService())->setInviteCookie($code);

        Client::redirect('/user/authentication/register?invite=' . urlencode($code), '正在进入注册页面', 0);
    }
}
