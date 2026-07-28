<?php
declare(strict_types=1);

namespace App\Model;


use App\Util\Ini;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Kernel\Exception\JSONException;

/**
 * @property int $id
 * @property string $name
 * @property int $category_id
 * @property string $description
 * @property string $cover
 * @property float $factory_price
 * @property float $price
 * @property float $user_price
 * @property int $status
 * @property int $owner
 * @property string $create_time
 * @property int $integral
 * @property string $code
 * @property int $delivery_way
 * @property int $delivery_auto_mode
 * @property string $delivery_message
 * @property int $contact_type
 * @property int $sort
 * @property int $password_status
 * @property int $coupon
 * @property int $shared_id
 * @property string $shared_code
 * @property float $shared_premium
 * @property int $shared_premium_type
 * @property int $seckill_status
 * @property float|null $seckill_price
 * @property int $seckill_expire_action
 * @property int $api_status
 * @property int $draft_status
 * @property int $inventory_hidden
 * @property int $send_email
 * @property string $seckill_start_time
 * @property string $seckill_end_time
 * @property string $leave_message
 * @property int $only_user
 * @property int $purchase_count
 * @property int $sold_base
 * @property string $widget
 * @property int $minimum
 * @property int $maximum
 * @property int $shared_sync
 * @property int $shared_amount_sync
 * @property int $shared_config_sync
 * @property int $inventory_sync
 * @property int $hide
 * @property array|string $config
 * @property array $shared_stock
 * @property float $draft_premium
 * @property int $stock
 */
class Commodity extends Model
{
    /**
     * @var string
     */
    protected $table = 'commodity';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'factory_price' => 'float',
        'price' => 'float',
        'user_price' => 'float',
        'shared_premium' => 'float',
        'status' => 'integer',
        'hide' => 'integer',
        'stock' => 'integer',
        'owner' => 'integer',
        'integral' => 'integer',
        'delivery_way' => 'integer',
        'delivery_auto_mode' => 'integer',
        'contact_type' => 'integer',
        'sort' => 'integer',
        'coupon' => 'integer',
        'shared_id' => 'integer',
        'seckill_status' => 'integer',
        'seckill_price' => 'float',
        'seckill_expire_action' => 'integer',
        'password_status' => 'integer',
        'category_id' => 'integer',
        'api_status' => 'integer',
        'draft_status' => 'integer',
        'draft_premium' => 'float',
        'inventory_hidden' => 'integer',
        'send_email' => 'integer',
        'only_user' => 'integer',
        'purchase_count' => 'integer',
        'sold_base' => 'integer',
        'minimum' => 'integer',
        'maximum' => 'integer',
        'shared_amount_sync' => 'integer',
        'shared_config_sync' => 'integer',
        'shared_sync' => 'integer',
        'shared_stock' => 'json'
    ];

    private static ?bool $soldBaseColumnReady = null;
    private static ?bool $seckillColumnsReady = null;
    private static bool $expiredSeckillSynced = false;

    public static function ensureSoldBaseColumn(): void
    {
        if (self::$soldBaseColumnReady === true) {
            return;
        }

        if (!Manager::schema()->hasColumn('commodity', 'sold_base')) {
            Manager::schema()->table('commodity', function (Blueprint $blueprint) {
                $blueprint->integer('sold_base')->unsigned()->default(0)->comment('前台已售增加数量')->after('purchase_count');
            });
        }

        self::$soldBaseColumnReady = true;
    }

    public static function getDisplaySold(int|string|null $realSold, int|string|null $soldBase): int
    {
        return max(0, (int)$realSold) + max(0, (int)$soldBase);
    }

    /**
     * 确保秒杀扩展字段存在，兼容已安装旧版本的数据库。
     */
    public static function ensureSeckillColumns(): void
    {
        if (self::$seckillColumnsReady === true) {
            return;
        }

        $schema = Manager::schema();
        if (!$schema->hasColumn('commodity', 'seckill_price')) {
            $schema->table('commodity', function (Blueprint $blueprint) {
                $blueprint->decimal('seckill_price', 10, 2)->unsigned()->nullable()->default(null)->comment('秒杀价格')->after('seckill_end_time');
            });
        }

        if (!$schema->hasColumn('commodity', 'seckill_expire_action')) {
            $schema->table('commodity', function (Blueprint $blueprint) {
                $blueprint->tinyInteger('seckill_expire_action')->unsigned()->default(0)->comment('秒杀到期处理：0=恢复原价，1=下架商品')->after('seckill_price');
            });
        }

        self::$seckillColumnsReady = true;
    }

    /**
     * 同步已结束的秒杀，避免到期下架依赖额外的定时任务。
     */
    public static function syncExpiredSeckill(): void
    {
        if (self::$expiredSeckillSynced) {
            return;
        }

        self::ensureSeckillColumns();
        $now = date('Y-m-d H:i:s');

        self::query()
            ->where('seckill_status', 1)
            ->where('seckill_expire_action', 1)
            ->whereNotNull('seckill_end_time')
            ->where('seckill_end_time', '<=', $now)
            ->update(['seckill_status' => 0, 'status' => 0]);

        self::query()
            ->where('seckill_status', 1)
            ->where('seckill_expire_action', 0)
            ->whereNotNull('seckill_end_time')
            ->where('seckill_end_time', '<=', $now)
            ->update(['seckill_status' => 0]);

        self::$expiredSeckillSynced = true;
    }

    /**
     * 判断当前商品是否处于秒杀有效期。
     */
    public function isSeckillActive(?int $timestamp = null): bool
    {
        if ((int)$this->seckill_status !== 1 || $this->seckill_price === null) {
            return false;
        }

        $start = $this->seckill_start_time ? strtotime((string)$this->seckill_start_time) : false;
        $end = $this->seckill_end_time ? strtotime((string)$this->seckill_end_time) : false;
        $timestamp ??= time();

        return $start !== false && $end !== false && $start <= $timestamp && $end >= $timestamp;
    }

    public function owner(): ?HasOne
    {
        return $this->hasOne(User::class, "id", "owner");
    }

    public function shared(): ?HasOne
    {
        return $this->hasOne(Shared::class, "id", "shared_id");
    }

    public function category(): ?HasOne
    {
        return $this->hasOne(Category::class, "id", "category_id");
    }

    public function card(): ?HasMany
    {
        return $this->hasMany(Card::class, 'commodity_id', 'id');
    }

    public function order(): ?HasMany
    {
        return $this->hasMany(Order::class, 'commodity_id', 'id');
    }

    /**
     * 解析用户组配置
     * @param string|null $config
     * @param UserGroup|null $group
     * @return array|null
     * @throws JSONException
     */
    public static function parseGroupConfig(?string $config, ?UserGroup $group): ?array
    {
        if (!$group) {
            return null;
        }

        $levelPrice = (array)json_decode((string)$config, true);

        if (!array_key_exists($group->id, $levelPrice)) {
            return null;
        }

        $var = $levelPrice[$group->id];

        //解析自定义金额
        $parse = [];
        $parse['amount'] = (float)$var['amount'];
        $parse['config'] = Ini::toArray((string)$var['config']);
        $parse['show'] = (int)$var['show'];
        return $parse;
    }

    /**
     * @param string $config
     * @param int $type
     * @param float $premium
     * @return string
     * @throws JSONException
     */
    public static function premiumConfig(string $config, int $type, float $premium): string
    {
        $configs = Ini::toArray($config);

        if (array_key_exists("category", $configs)) {
            foreach ($configs['category'] as $ck => $cv) {
                //计算当前种类的成本
                $price = $type == 0 ? (float)$cv + $premium : (float)$cv + ($premium * (float)$cv);
                $price = (int)($price * 100) / 100;
                $configs['category'][$ck] = $price;
            }
        }
        return Ini::toConfig($configs);
    }
}
