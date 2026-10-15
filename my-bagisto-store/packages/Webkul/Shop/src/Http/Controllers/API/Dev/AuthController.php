<?php

namespace Webkul\Shop\Http\Controllers\API\Dev;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Http\Controllers\API\APIController;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\DeveloperProfile;

/**
 * `/api/dev/auth/*` —— 开发者门户的 token 发放与注销（v3.4 步骤 9）。
 *
 * 为什么需要它：`/api/dev/*` 全线**只认 Bearer token**（因为 `api/*` 全局豁免 CSRF，
 * 接受 cookie-session 就是 CSRF 洞）。但在此之前**没有任何端点发 token** ——
 * 纯前端（SPA / 小程序 web-view）根本登不进来。这条补上那个缺口。
 *
 * 账号与 C 端同源（customers 表），但**不复用** `/api/customer/login`：
 * 那条强制 `is_verified`，与门户的登录语义不一致，且会污染买家的登录路径。
 *
 * token 过期策略：全局 `sanctum.expiration` 是 null（永不过期，影响小程序买家，不动它），
 * 这里用**按 token 过期**（`createToken` 的 expiresAt），只作用于 dev 通道。
 */
class AuthController extends APIController
{
    /**
     * 用邮箱+密码换 token。
     */
    public function token(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::query()->where('email', $payload['email'])->first();

        // 统一失败话术：不区分"邮箱不存在"与"密码错"，避免账号枚举
        if (! $customer || ! Hash::check($payload['password'], $customer->password)) {
            return $this->fail(ApiErrorCode::INVALID_CREDENTIALS, '邮箱或密码不正确。', 401);
        }

        $ttlDays = max(1, (int) config('developer_portal.token_ttl_days', 30));
        $expiresAt = now()->addDays($ttlDays);

        $token = $customer->createToken('developer-portal', ['dev'], $expiresAt);

        $profile = DeveloperProfile::query()->where('customer_id', $customer->id)->first();

        return response()->json([
            'message' => '登录成功。',
            'data'    => [
                'token'      => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toDateTimeString(),
                'customer'   => [
                    'id'    => $customer->id,
                    'name'  => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
                    'email' => $customer->email,
                ],
                // 没入驻也能拿到 token —— SPA 据此决定跳入驻页还是进门户（与 Blade 语义一致）
                'developer'  => [
                    'has_profile' => (bool) $profile,
                    'status'      => $profile?->status,
                    'agreements_ok' => $profile ? $profile->hasSignedAllAgreements() : false,
                ],
            ],
        ]);
    }

    /**
     * 注销当前 token。
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user('sanctum')?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => '已登出。', 'data' => null]);
    }
}
