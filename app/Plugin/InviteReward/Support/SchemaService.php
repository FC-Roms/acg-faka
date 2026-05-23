<?php
declare(strict_types=1);

namespace App\Plugin\InviteReward\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;

class SchemaService
{
    public const CODE_TABLE = 'invite_reward_code';
    public const RELATION_TABLE = 'invite_reward_relation';
    public const RECORD_TABLE = 'invite_reward_record';

    public static function ready(): bool
    {
        return Manager::schema()->hasTable(self::CODE_TABLE)
            && Manager::schema()->hasTable(self::RELATION_TABLE)
            && Manager::schema()->hasTable(self::RECORD_TABLE);
    }

    public static function install(): void
    {
        if (!Manager::schema()->hasTable(self::CODE_TABLE)) {
            Manager::schema()->create(self::CODE_TABLE, function (Blueprint $blueprint) {
                $blueprint->increments('id');
                $blueprint->integer('user_id')->unsigned()->unique();
                $blueprint->string('code', 32)->unique();
                $blueprint->tinyInteger('status')->default(1);
                $blueprint->dateTime('create_time');
                $blueprint->dateTime('update_time')->nullable(true);
            });
        }

        if (!Manager::schema()->hasTable(self::RELATION_TABLE)) {
            Manager::schema()->create(self::RELATION_TABLE, function (Blueprint $blueprint) {
                $blueprint->increments('id');
                $blueprint->integer('inviter_user_id')->unsigned()->index();
                $blueprint->integer('invitee_user_id')->unsigned()->unique();
                $blueprint->string('invite_code', 32)->index();
                $blueprint->string('source', 64)->nullable(true);
                $blueprint->string('register_ip', 64)->nullable(true)->index();
                $blueprint->string('device_id', 64)->nullable(true)->index();
                $blueprint->string('fingerprint', 128)->nullable(true);
                $blueprint->tinyInteger('risk_level')->default(0);
                $blueprint->tinyInteger('status')->default(1)->index();
                $blueprint->integer('first_paid_order_id')->unsigned()->nullable(true);
                $blueprint->dateTime('first_paid_time')->nullable(true);
                $blueprint->dateTime('create_time')->index();
                $blueprint->dateTime('update_time')->nullable(true);
            });
        }

        if (!Manager::schema()->hasTable(self::RECORD_TABLE)) {
            Manager::schema()->create(self::RECORD_TABLE, function (Blueprint $blueprint) {
                $blueprint->increments('id');
                $blueprint->integer('relation_id')->unsigned()->index();
                $blueprint->integer('user_id')->unsigned()->index();
                $blueprint->string('role', 16);
                $blueprint->string('trigger_type', 32);
                $blueprint->string('trigger_id', 64)->default('');
                $blueprint->string('reward_type', 32);
                $blueprint->text('reward_payload')->nullable(true);
                $blueprint->text('reward_result')->nullable(true);
                $blueprint->tinyInteger('status')->default(0)->index();
                $blueprint->string('remark', 255)->nullable(true);
                $blueprint->dateTime('create_time')->index();
                $blueprint->dateTime('grant_time')->nullable(true);
                $blueprint->unique(
                    ['relation_id', 'user_id', 'role', 'trigger_type', 'trigger_id', 'reward_type'],
                    'uk_invite_reward_once'
                );
            });
        }
    }
}
