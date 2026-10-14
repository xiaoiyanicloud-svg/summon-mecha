<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Shop\Models\DeveloperProfile;

/**
 * `/api/dev/*` 的**轻量登录门槛** —— 只要求 token 有效，**不要求已入驻**。
 *
 * 为什么必须有它：入驻接口 `POST /api/dev/profile` 若挂在 `api.developer` 下，
 * 会陷入死锁 —— 中间件因为"没有 developer_profile"返回 403，而创建 profile 的
 * 请求正是被它拦掉的那一个，**新开发者永远无法入驻**。
 * （Web 侧早就用 `developer.session` 避开了这个坑，API 侧曾漏做对应物。）
 *
 * 有 profile 就顺带放进 request attributes；没有也不算错，那是"还没入驻"这一态。
 */
class ResolveDeveloperLogin
{
    public function handle(Request $request, Closure $next)
    {
        $customer = $request->user('sanctum');

        if (! $customer) {
            return response()->json(['message' => '未认证。', 'code' => 'UNAUTHENTICATED'], 401);
        }

        // 让下游能统一用 auth('developer')->user()，与门户侧写法一致
        auth('developer')->setUser($customer);

        $profile = DeveloperProfile::query()->where('customer_id', $customer->id)->first();

        if ($profile) {
            $request->attributes->set('developer_profile', $profile);
        }

        return $next($request);
    }
}
