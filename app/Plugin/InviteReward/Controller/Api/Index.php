<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Controller\Api;

use App\Controller\Base\API\UserPlugin;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use App\Plugin\InviteReward\Support\InviteService;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class], Interceptor::TYPE_API)]
class Index extends UserPlugin
{
    public function summary(): array
    {
        $service = new InviteService();
        $userId = (int)$this->getUser()->id;

        return $this->json(200, 'success', [
            'invite_url' => $service->getInviteUrl($userId),
            'summary' => $service->userSummary($userId),
            'relations' => $service->userRelations($userId),
            'rewards' => $service->userRewards($userId)
        ]);
    }
}
