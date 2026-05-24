<?php
declare(strict_types=1);

namespace App\Controller;

use App\Util\Client;

class Coupon
{
    public function index(): void
    {
        $code = trim((string)($_GET['code'] ?? ''));
        Client::redirect('/user/couponWallet/index?code=' . rawurlencode($code), '正在进入优惠券页面', 0);
    }
}
