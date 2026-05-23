<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Controller\Api;

use App\Controller\Base\API\ManagePlugin;
use App\Interceptor\ManageSession;
use App\Plugin\InviteReward\Support\InviteService;
use App\Plugin\InviteReward\Support\SchemaService;
use Kernel\Annotation\Interceptor;

#[Interceptor(ManageSession::class, Interceptor::TYPE_API)]
class Admin extends ManagePlugin
{
    public function summary(): array
    {
        SchemaService::install();

        $service = new InviteService();

        return $this->json(200, 'success', [
            'summary' => $service->adminSummary(),
            'relations' => $service->adminRelations(),
            'rewards' => $service->adminRewards()
        ]);
    }
}
