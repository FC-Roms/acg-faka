<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Controller;

use App\Controller\Base\View\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Plugin\InviteReward\Support\InviteService;
use App\Plugin\InviteReward\Support\SchemaService;
use App\Util\Plugin as PluginUtil;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class)]
class Admin extends ManagePlugin
{
    /**
     * @throws \Kernel\Exception\ViewException
     */
    public function index(): string
    {
        SchemaService::install();

        $service = new InviteService();

        return $this->render('邀请奖励', 'Admin/Index.html', [
            'summary' => $service->adminSummary(),
            'relations' => $service->adminRelations(),
            'rewards' => $service->adminRewards(),
            'plugin_config' => PluginUtil::getConfig(InviteService::PLUGIN)
        ], true);
    }
}
