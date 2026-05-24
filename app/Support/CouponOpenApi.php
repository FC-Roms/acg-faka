<?php
declare(strict_types=1);

namespace App\Support;

use App\Model\Coupon;
use App\Model\User;
use App\Util\Client;
use App\Util\Date;
use App\Util\Str;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Exception\JSONException;

class CouponOpenApi
{
    public const TABLE = 'coupon_openapi_record';
    private const COUPON_MONEY = 5.00;

    public static function configuredToken(): string
    {
        $config = config('coupon_api');
        $token = trim((string)($config['token'] ?? ''));
        if ($token === '') {
            $token = trim((string)getenv('ACG_COUPON_API_TOKEN'));
        }

        return $token;
    }

    public static function ensureRecordTable(): void
    {
        if (Manager::schema()->hasTable(self::TABLE)) {
            return;
        }

        Manager::schema()->create(self::TABLE, function (Blueprint $blueprint) {
            $blueprint->increments('id');
            $blueprint->string('qq', 32)->unique();
            $blueprint->string('target_email', 191)->index();
            $blueprint->integer('coupon_id')->unsigned()->unique();
            $blueprint->string('group_id', 64)->nullable(true)->index();
            $blueprint->string('nickname', 191)->nullable(true);
            $blueprint->string('source', 64)->nullable(true)->index();
            $blueprint->integer('request_count')->unsigned()->default(1);
            $blueprint->text('raw')->nullable(true);
            $blueprint->dateTime('create_time')->index();
            $blueprint->dateTime('update_time')->nullable(true);
            $blueprint->dateTime('last_request_time')->nullable(true);
        });
    }

    public static function ensureUserLimitColumn(): void
    {
        if (Manager::schema()->hasColumn('coupon', 'user_limit')) {
            return;
        }

        Manager::schema()->table('coupon', function (Blueprint $blueprint) {
            $blueprint->tinyInteger('user_limit')->unsigned()->default(0)->comment('0=no limit, 1=new bound user, 2=one use per member')->after('sku');
            $blueprint->index('user_limit');
        });
    }

    public static function normalizeQq(string $qq): string
    {
        return (string)preg_replace('/\D+/', '', trim($qq));
    }

    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string)$email));
    }

    public static function targetEmail(string $qq): string
    {
        return self::normalizeQq($qq) . '@qq.com';
    }

    public static function couponUrl(Coupon $coupon): string
    {
        return rtrim(Client::getUrl(), '/') . '/coupon/' . rawurlencode((string)$coupon->code);
    }

    public static function findRecordByCouponId(int $couponId): ?object
    {
        if ($couponId <= 0 || !Manager::schema()->hasTable(self::TABLE)) {
            return null;
        }

        return Manager::table(self::TABLE)->where('coupon_id', $couponId)->first();
    }

    /**
     * @throws JSONException
     */
    public static function validateExclusiveCoupon(Coupon $coupon, ?User $user): void
    {
        $record = self::findRecordByCouponId((int)$coupon->id);
        if (!$record) {
            return;
        }

        if (!$user) {
            throw new JSONException('该优惠券仅限绑定指定 QQ 邮箱的登录用户使用');
        }

        $userEmail = self::normalizeEmail($user->email ?? '');
        $targetEmail = self::normalizeEmail((string)$record->target_email);
        if ($userEmail === '' || $userEmail !== $targetEmail) {
            throw new JSONException('该优惠券仅限绑定邮箱 ' . $targetEmail . ' 的用户使用');
        }
    }

    /**
     * @throws JSONException
     */
    public static function createOrGetCoupon(array $payload): array
    {
        $qq = self::normalizeQq((string)($payload['qq'] ?? ''));
        if (!preg_match('/^[1-9]\d{4,11}$/', $qq)) {
            throw new JSONException('qq 必须是 5 到 12 位数字');
        }

        self::ensureRecordTable();
        self::ensureUserLimitColumn();

        $targetEmail = self::targetEmail($qq);
        $now = Date::current();
        $groupId = mb_substr(trim((string)($payload['group_id'] ?? '')), 0, 64);
        $nickname = mb_substr(trim((string)($payload['nickname'] ?? '')), 0, 191);
        $source = mb_substr(trim((string)($payload['source'] ?? 'qq_group_join')), 0, 64);
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Manager::connection()->transaction(function () use ($qq, $targetEmail, $now, $groupId, $nickname, $source, $raw) {
            $record = Manager::table(self::TABLE)->where('qq', $qq)->lockForUpdate()->first();
            if ($record) {
                Manager::table(self::TABLE)->where('id', $record->id)->update([
                    'group_id' => $groupId,
                    'nickname' => $nickname,
                    'source' => $source,
                    'request_count' => ((int)$record->request_count) + 1,
                    'raw' => $raw,
                    'update_time' => $now,
                    'last_request_time' => $now
                ]);

                $coupon = Coupon::query()->find((int)$record->coupon_id);
                if ($coupon) {
                    $record = Manager::table(self::TABLE)->where('id', $record->id)->first();
                    return ['coupon' => $coupon, 'record' => $record, 'created' => false];
                }
            }

            $coupon = new Coupon();
            $coupon->code = self::generateCouponCode($qq);
            $coupon->commodity_id = 0;
            $coupon->category_id = 0;
            $coupon->owner = 0;
            $coupon->create_time = $now;
            $coupon->money = self::COUPON_MONEY;
            $coupon->status = 0;
            $coupon->note = mb_substr('QQ exclusive ' . $qq, 0, 32);
            $coupon->life = 1;
            $coupon->mode = 0;
            $coupon->user_limit = 2;
            $coupon->sku = [];
            $coupon->save();

            Manager::table(self::TABLE)->updateOrInsert(
                ['qq' => $qq],
                [
                    'target_email' => $targetEmail,
                    'coupon_id' => $coupon->id,
                    'group_id' => $groupId,
                    'nickname' => $nickname,
                    'source' => $source,
                    'request_count' => 1,
                    'raw' => $raw,
                    'create_time' => $now,
                    'update_time' => $now,
                    'last_request_time' => $now
                ]
            );

            $record = Manager::table(self::TABLE)->where('qq', $qq)->first();
            return ['coupon' => $coupon, 'record' => $record, 'created' => true];
        });
    }

    private static function generateCouponCode(string $qq): string
    {
        for ($i = 0; $i < 8; $i++) {
            $code = 'AI-' . $qq . '-' . strtoupper(Str::generateRandStr(4));
            if (!Coupon::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return 'AI-' . $qq . '-' . strtoupper(Str::generateRandStr(6));
    }
}
