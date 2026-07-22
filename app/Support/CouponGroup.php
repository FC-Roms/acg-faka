<?php
declare(strict_types=1);

namespace App\Support;

use App\Model\Coupon;
use App\Model\Order;
use App\Model\User;
use App\Util\Date;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Exception\JSONException;

class CouponGroup
{
    public const USER_LIMIT = 3;
    public const MEMBER_TABLE = 'coupon_group_member';
    private const MAX_GROUPS = 20;

    /**
     * 确保群券配置字段和 AstrBot 群成员凭据表存在。
     *
     * @throws JSONException
     */
    public static function ensureSchema(): void
    {
        if (!Manager::schema()->hasColumn('coupon', 'group_ids')) {
            try {
                Manager::schema()->table('coupon', function (Blueprint $blueprint) {
                    $blueprint->string('group_ids', 512)
                        ->nullable(true)
                        ->comment('群优惠券适用群号，多个使用|分隔')
                        ->after('user_limit');
                });
            } catch (\Throwable $throwable) {
                if (!Manager::schema()->hasColumn('coupon', 'group_ids')) {
                    throw new JSONException('自动创建群优惠券配置字段失败，请先执行数据库增量脚本');
                }
            }
        }

        $created = false;
        if (!Manager::schema()->hasTable(self::MEMBER_TABLE)) {
            try {
                Manager::schema()->create(self::MEMBER_TABLE, function (Blueprint $blueprint) {
                    $blueprint->increments('id');
                    $blueprint->string('qq', 32);
                    $blueprint->string('target_email', 191);
                    $blueprint->string('group_id', 64);
                    $blueprint->string('nickname', 191)->nullable(true);
                    $blueprint->string('source', 64)->nullable(true);
                    $blueprint->dateTime('create_time');
                    $blueprint->dateTime('update_time')->nullable(true);
                    $blueprint->dateTime('last_request_time')->nullable(true);
                    $blueprint->unique(['qq', 'group_id'], 'uk_coupon_group_member');
                    $blueprint->index('target_email', 'coupon_group_member_email_index');
                    $blueprint->index('group_id', 'coupon_group_member_group_index');
                    $blueprint->index('source', 'coupon_group_member_source_index');
                    $blueprint->index('create_time', 'coupon_group_member_create_time_index');
                });
                $created = true;
            } catch (\Throwable $throwable) {
                if (!Manager::schema()->hasTable(self::MEMBER_TABLE)) {
                    throw new JSONException('自动创建 AstrBot 群成员凭据表失败，请先执行数据库增量脚本');
                }
            }
        }

        if ($created) {
            self::backfillExistingRecords();
        }
    }

    /**
     * 规范化群号配置，输出使用 | 分隔的稳定格式。
     *
     * @throws JSONException
     */
    public static function normalizeGroupIds(string $value): string
    {
        $items = array_filter(array_map('trim', explode('|', trim($value))), static fn(string $item) => $item !== '');
        $items = array_values(array_unique($items));

        if (count($items) === 0) {
            throw new JSONException('群优惠券必须配置至少一个QQ群号');
        }
        if (count($items) > self::MAX_GROUPS) {
            throw new JSONException('单张群优惠券最多配置' . self::MAX_GROUPS . '个QQ群号');
        }

        foreach ($items as $item) {
            if (!preg_match('/^[1-9]\d{4,11}$/', $item)) {
                throw new JSONException('QQ群号格式错误，多个群号请使用 | 分隔');
            }
        }

        return implode('|', $items);
    }

    public static function isGroupCoupon(Coupon $coupon): bool
    {
        return (int)($coupon->user_limit ?? 0) === self::USER_LIMIT;
    }

    /**
     * AstrBot 入群事件写入独立的 QQ + 群号凭据，不再受旧记录单群覆盖限制。
     */
    public static function syncMember(
        string $qq,
        string $targetEmail,
        string $groupId,
        string $nickname,
        string $source,
        string $now
    ): void {
        $groupId = trim($groupId);
        if (!preg_match('/^[1-9]\d{4,11}$/', $groupId)) {
            return;
        }

        self::ensureSchema();

        $key = ['qq' => $qq, 'group_id' => $groupId];
        $data = [
            'target_email' => CouponOpenApi::normalizeEmail($targetEmail),
            'nickname' => $nickname,
            'source' => $source,
            'update_time' => $now,
            'last_request_time' => $now
        ];

        $query = Manager::table(self::MEMBER_TABLE)->where($key);
        if ($query->exists()) {
            $query->update($data);
            return;
        }

        try {
            Manager::table(self::MEMBER_TABLE)->insert(array_merge($key, $data, [
                'create_time' => $now
            ]));
        } catch (QueryException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            // 同一入群事件并发提交时，唯一索引只允许一条凭据，后到请求改为更新。
            $query = Manager::table(self::MEMBER_TABLE)->where($key);
            $query->update($data);
            if (!$query->exists()) {
                throw $exception;
            }
        }
    }

    /**
     * 校验登录会员是否拥有该群券要求的 AstrBot 入群凭据，并限制每人一次。
     *
     * @throws JSONException
     */
    public static function validate(Coupon $coupon, ?User $user): void
    {
        if (!self::isGroupCoupon($coupon)) {
            return;
        }
        if (!$user) {
            throw new JSONException('该优惠券仅限登录后的指定QQ群成员使用');
        }

        self::ensureSchema();
        $groupIds = explode('|', self::normalizeGroupIds((string)($coupon->group_ids ?? '')));
        $email = CouponOpenApi::normalizeEmail($user->email ?? '');
        if (!preg_match('/^[1-9]\d{4,11}@qq\.com$/i', $email)) {
            throw new JSONException('请先绑定加入QQ群时使用的 QQ 邮箱');
        }

        $verified = Manager::table(self::MEMBER_TABLE)
            ->where('target_email', $email)
            ->whereIn('group_id', $groupIds)
            ->exists();

        if (!$verified && Manager::schema()->hasTable(CouponOpenApi::TABLE)) {
            $legacyRecords = Manager::table(CouponOpenApi::TABLE)
                ->where('target_email', $email)
                ->whereNotNull('group_id')
                ->get();
            foreach ($legacyRecords as $record) {
                if (in_array(trim((string)$record->group_id), $groupIds, true)) {
                    $verified = true;
                    break;
                }
            }
        }

        if (!$verified) {
            throw new JSONException('未查询到指定QQ群的入群凭据，请先通过 AstrBot 领取入群优惠券');
        }

        $hasUsedCoupon = Order::query()
            ->where('owner', $user->id)
            ->where('coupon_id', $coupon->id)
            ->exists();
        if ($hasUsedCoupon) {
            throw new JSONException('该群优惠券每个群成员只能使用1次');
        }
    }

    private static function backfillExistingRecords(): void
    {
        if (!Manager::schema()->hasTable(CouponOpenApi::TABLE)) {
            return;
        }

        $records = Manager::table(CouponOpenApi::TABLE)
            ->whereNotNull('group_id')
            ->get();
        foreach ($records as $record) {
            $groupId = trim((string)$record->group_id);
            if (!preg_match('/^[1-9]\d{4,11}$/', $groupId)) {
                continue;
            }

            $key = ['qq' => (string)$record->qq, 'group_id' => $groupId];
            Manager::table(self::MEMBER_TABLE)->updateOrInsert($key, [
                'target_email' => CouponOpenApi::normalizeEmail((string)$record->target_email),
                'nickname' => $record->nickname ?? null,
                'source' => $record->source ?? 'qq_group_join',
                'create_time' => $record->create_time ?? Date::current(),
                'update_time' => $record->update_time ?? null,
                'last_request_time' => $record->last_request_time ?? null
            ]);
        }
    }
}
