<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Support\CouponOpenApi;
use Kernel\Annotation\Inject;
use Kernel\Context\Interface\Request;
use Kernel\Exception\JSONException;
use Kernel\Waf\Filter;

class Coupon
{
    #[Inject]
    private Request $request;

    private function normalizeHeaderName(string $name): string
    {
        return strtolower((string)preg_replace('/[^a-z0-9]/i', '', $name));
    }

    private function getApiHeader(string $name): string
    {
        $target = $this->normalizeHeaderName($name);
        foreach ((array)$this->request->header() as $key => $value) {
            if ($this->normalizeHeaderName((string)$key) === $target && $value !== '') {
                return (string)$value;
            }
        }

        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with((string)$key, 'HTTP_')) {
                continue;
            }
            if ($this->normalizeHeaderName(substr((string)$key, 5)) === $target && $value !== '') {
                return (string)$value;
            }
        }

        return '';
    }

    private function bearerToken(): string
    {
        $authorization = $this->getApiHeader('Authorization');
        if (preg_match('/Bearer\s+(.+)/i', $authorization, $matches) === 1) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * @throws JSONException
     */
    public function create(): array
    {
        if ($this->request->method() !== 'POST') {
            throw new JSONException('仅支持 POST 请求');
        }

        $expectedToken = CouponOpenApi::configuredToken();
        if ($expectedToken === '' || !hash_equals($expectedToken, $this->bearerToken())) {
            throw new JSONException('接口 Token 错误或未配置');
        }

        $payload = $this->request->json(flags: Filter::NORMAL);
        if (!is_array($payload) || count($payload) === 0) {
            $payload = $this->request->post(flags: Filter::NORMAL);
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $result = CouponOpenApi::createOrGetCoupon($payload);
        $coupon = $result['coupon'];

        return [
            'coupon_code' => $coupon->code,
            'coupon_url' => CouponOpenApi::couponUrl($coupon),
            'message' => '专属 5 元优惠券'
        ];
    }
}
