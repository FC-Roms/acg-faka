<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Model;

use Illuminate\Database\Eloquent\Model;

class InviteRelation extends Model
{
    protected $table = 'invite_reward_relation';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'inviter_user_id' => 'integer',
        'invitee_user_id' => 'integer',
        'risk_level' => 'integer',
        'status' => 'integer',
        'first_paid_order_id' => 'integer'
    ];
}
