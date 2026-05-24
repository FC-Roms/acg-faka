<?php
declare(strict_types=1);

namespace App\Controller\User;

use App\Controller\Base\View\User;
use App\Interceptor\UserSession;
use App\Interceptor\Waf;
use Kernel\Annotation\Interceptor;

#[Interceptor([Waf::class, UserSession::class])]
class CouponWallet extends User
{
    public function index(): string
    {
        return $this->theme("我的优惠券", "COUPON_WALLET", "User/CouponWallet.html");
    }
}
