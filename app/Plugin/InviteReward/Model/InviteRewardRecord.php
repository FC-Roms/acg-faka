<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Model;

use Illuminate\Database\Eloquent\Model;

class InviteRewardRecord extends Model
{
    protected $table = 'invite_reward_record';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'relation_id' => 'integer',
        'user_id' => 'integer',
        'status' => 'integer'
    ];
}
