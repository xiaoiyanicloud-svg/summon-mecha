<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Services\Merchant\MerchantTenantService;
use Webkul\Shop\Services\Merchant\PortalBackendRegistryService;

class AuthenticateMerchant
{
    public function __construct(
        protected PortalBackendRegistryService $registryService,
        protected MerchantTenantService $tenantService,
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

            if ($request->expectsJson()) {
                return response()->json([
                    'code'    => ApiErrorCode::ACCOUNT_SUSPENDED,
                    'message' => '当前账号已被停用。',
                ], 403);
            }

            return redirect()->route('shop.merchant.login')
                ->with('error', '当前账号已被停用。');
        }

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

        $preferredShopId = (int) ($request->query('shop_id') ?: $request->session()->get('merchant_active_shop_id', 0)) ?: null;
        $context = $this->tenantService->resolveContext((int) $customer->id, $preferredShopId);

        if (! $context['active_shop'] || ! $context['active_member']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'code'    => ApiErrorCode::MERCHANT_SHOP_REQUIRED,
                    'message' => '请先完成商家入驻并开通店铺。',
                ], 403);
            }

            return redirect()->route('shop.merchant.onboarding');
        }

        $request->session()->put('merchant_active_shop_id', (int) $context['active_shop']->id);

        $request->attributes->set('merchant_profile', $context['profile']);
        $request->attributes->set('merchant_shop', $context['active_shop']);
        $request->attributes->set('merchant_member', $context['active_member']);
        $request->attributes->set('merchant_shops', $context['shops']);
        $request->attributes->set('merchant_permissions', $context['permissions']);

        View::share('currentMerchantProfile', $context['profile']);
        View::share('currentMerchantShop', $context['active_shop']);
        View::share('currentMerchantMember', $context['active_member']);
        View::share('merchantShops', $context['shops']);
        View::share('merchantPermissions', $context['permissions']);

        return $next($request);
    }
}
