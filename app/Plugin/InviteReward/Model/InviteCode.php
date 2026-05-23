<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Model;

use Illuminate\Database\Eloquent\Model;

class InviteCode extends Model
{
    protected $table = 'invite_reward_code';

    public $timestamps = false;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status' => 'integer'
    ];
}
