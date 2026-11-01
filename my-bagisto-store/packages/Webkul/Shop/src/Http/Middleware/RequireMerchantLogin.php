<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Services\Merchant\PortalBackendRegistryService;

class RequireMerchantLogin
{
    public function __construct(
        protected PortalBackendRegistryService $registryService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $guard = auth()->guard('merchant');

        if (! $guard->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'code'    => ApiErrorCode::UNAUTHENTICATED,
                    'message' => '请先登录商家后台。',
                ], 401);
            }

            return redirect()->guest(route('shop.merchant.login'));
        }

        $customer = $guard->user();
        if (isset($customer->status) && ! $customer->status) {
            $guard->logout();

            return redirect()->route('shop.merchant.login')
                ->with('error', '当前账号已被停用。');
        }

        // 即使直接访问 /merchant/onboarding，无权限用户也必须拦截（仅限非登出路由）
        if (! $request->routeIs('shop.merchant.logout')) {
            $access = $this->registryService->checkMerchantPortalAccess($customer);
            if (! $access['has_permission']) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'code'    => $access['code'] ?? ApiErrorCode::MERCHANT_ACCESS_FORBIDDEN,
                        'message' => $access['disabled_reason'] ?? '您暂无商家后台访问权限。',
                    ], 403);
                }

                return response()->view('shop::merchant.forbidden', [
                    'reason'   => $access['disabled_reason'] ?? '您暂无商家后台访问权限。',
                    'customer' => $customer,
                ], 403);
            }
        }

        return $next($request);
    }
}
