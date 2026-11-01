<?php

namespace Webkul\Shop\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Services\Merchant\MerchantTenantService;
use Webkul\Shop\Services\Merchant\PortalBackendRegistryService;

class ResolveMerchant
{
    public function __construct(
        protected PortalBackendRegistryService $registryService,
        protected MerchantTenantService $tenantService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $customer = auth('sanctum')->user()
            ?? auth('merchant')->user()
            ?? auth('customer')->user();

        if (! $customer) {
            return response()->json([
                'code'    => ApiErrorCode::UNAUTHENTICATED,
                'message' => '请先登录商家账号。',
            ], 401);
        }

        if (isset($customer->status) && ! $customer->status) {
            return response()->json([
                'code'    => ApiErrorCode::ACCOUNT_SUSPENDED,
                'message' => '当前账号已被停用。',
            ], 403);
        }

        $access = $this->registryService->checkMerchantPortalAccess($customer);
        if (! $access['has_permission']) {
            return response()->json([
                'code'    => $access['code'] ?? ApiErrorCode::MERCHANT_ACCESS_FORBIDDEN,
                'message' => $access['disabled_reason'] ?? '您暂无商家后台访问权限。',
            ], 403);
        }

        $preferredShopId = (int) (
            $request->header('X-Shop-Id')
            ?: $request->input('shop_id')
            ?: ($request->hasSession() ? $request->session()->get('merchant_active_shop_id', 0) : 0)
        ) ?: null;

        $context = $this->tenantService->resolveContext((int) $customer->id, $preferredShopId);

        if (! $context['active_shop'] || ! $context['active_member']) {
            return response()->json([
                'code'    => ApiErrorCode::MERCHANT_SHOP_REQUIRED,
                'message' => '请先开通商家店铺或切换到有效店铺。',
            ], 403);
        }

        $request->attributes->set('merchant_customer', $customer);
        $request->attributes->set('merchant_profile', $context['profile']);
        $request->attributes->set('merchant_shop', $context['active_shop']);
        $request->attributes->set('merchant_member', $context['active_member']);
        $request->attributes->set('merchant_shops', $context['shops']);
        $request->attributes->set('merchant_permissions', $context['permissions']);

        return $next($request);
    }
}
