<?php

namespace Webkul\Shop\Http\Controllers\API\Merchant;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Webkul\Shop\Http\Controllers\API\APIController;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantShop;
use Webkul\Shop\Services\Merchant\MerchantTenantService;

class MerchantApiController extends APIController
{
    public function __construct(
        protected MerchantTenantService $tenantService,
    ) {}

    public function context(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('merchant_customer');
        $shop = $this->currentShop($request);
        $member = $this->currentMember($request);
        $profile = $request->attributes->get('merchant_profile');
        $shops = $request->attributes->get('merchant_shops', []);
        $permissions = $request->attributes->get('merchant_permissions', []);

        return $this->ok([
            'user' => [
                'id'    => (int) $customer->id,
                'name'  => trim(($customer->first_name ?? '').' '.($customer->last_name ?? '')),
                'email' => (string) $customer->email,
            ],
            'merchant_profile' => $profile ? [
                'id'           => (int) $profile->id,
                'company_name' => (string) $profile->company_name,
                'credit_code'  => (string) ($profile->credit_code ?? ''),
                'status'       => (string) $profile->status,
            ] : null,
            'active_shop' => [
                'id'             => (int) $shop->id,
                'seller_id'      => (int) $shop->seller_id,
                'shop_code'      => (string) $shop->shop_code,
                'shop_name'      => (string) $shop->shop_name,
                'description'    => (string) ($shop->description ?? ''),
                'business_types' => $shop->business_types ?? [],
                'timezone'       => (string) ($shop->timezone ?: 'Asia/Shanghai'),
                'status'         => (string) $shop->status,
            ],
            'role_code'   => (string) $member->role_code,
            'permissions' => $permissions,
            'shops'       => collect($shops)->map(fn ($s) => [
                'id'             => (int) $s->id,
                'seller_id'      => (int) $s->seller_id,
                'shop_code'      => (string) $s->shop_code,
                'shop_name'      => (string) $s->shop_name,
                'business_types' => $s->business_types ?? [],
                'timezone'       => (string) ($s->timezone ?: 'Asia/Shanghai'),
                'status'         => (string) $s->status,
            ])->values()->all(),
            'metrics'     => $this->tenantService->dashboardMetrics($shop),
        ]);
    }

    public function switchShop(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'min:1'],
        ]);

        $customer = $request->attributes->get('merchant_customer');
        $targetShopId = (int) $validated['shop_id'];

        $context = $this->tenantService->resolveContext((int) $customer->id, $targetShopId);
        if (! $context['active_shop'] || (int) $context['active_shop']->id !== $targetShopId) {
            return $this->fail(
                ApiErrorCode::MERCHANT_PERMISSION_DENIED,
                '目标店铺不存在或您无权切换到该店铺。',
                403
            );
        }

        if ($request->hasSession()) {
            $request->session()->put('merchant_active_shop_id', $targetShopId);
        }

        return $this->ok([
            'active_shop' => [
                'id'        => (int) $context['active_shop']->id,
                'seller_id' => (int) $context['active_shop']->seller_id,
                'shop_code' => (string) $context['active_shop']->shop_code,
                'shop_name' => (string) $context['active_shop']->shop_name,
                'timezone'  => (string) ($context['active_shop']->timezone ?: 'Asia/Shanghai'),
            ],
            'role_code'   => (string) $context['active_member']->role_code,
            'permissions' => $context['permissions'],
        ], '已切换当前经营店铺。');
    }

    public function createShop(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.shop.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'shop_name'        => ['required', 'string', 'max:128'],
            'description'      => ['nullable', 'string', 'max:500'],
            'business_types'   => ['nullable', 'array'],
            'business_types.*' => ['string', 'in:sale_robot,rental_robot,accessory'],
            'timezone'         => ['nullable', 'string', 'max:64'],
        ]);

        $customer = $request->attributes->get('merchant_customer');
        $profile = $request->attributes->get('merchant_profile');

        if (! $profile) {
            return $this->fail(ApiErrorCode::MERCHANT_SHOP_REQUIRED, '未找到商户主体档案。', 403);
        }

        $shop = $this->tenantService->createShop($profile, (int) $customer->id, $validated, $request);

        return $this->ok([
            'id'             => (int) $shop->id,
            'seller_id'      => (int) $shop->seller_id,
            'shop_code'      => (string) $shop->shop_code,
            'shop_name'      => (string) $shop->shop_name,
            'business_types' => $shop->business_types ?? [],
            'timezone'       => (string) $shop->timezone,
            'status'         => (string) $shop->status,
        ], '店铺创建成功。');
    }

    public function updateCurrentShop(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.shop.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'shop_name'        => ['sometimes', 'string', 'max:128'],
            'description'      => ['nullable', 'string', 'max:500'],
            'business_types'   => ['sometimes', 'array'],
            'business_types.*' => ['string', 'in:sale_robot,rental_robot,accessory'],
            'timezone'         => ['sometimes', 'string', 'max:64'],
        ]);

        $customer = $request->attributes->get('merchant_customer');
        $shop = $this->tenantService->updateShopProfile($this->currentShop($request), (int) $customer->id, $validated);

        return $this->ok([
            'id'             => (int) $shop->id,
            'seller_id'      => (int) $shop->seller_id,
            'shop_code'      => (string) $shop->shop_code,
            'shop_name'      => (string) $shop->shop_name,
            'description'    => (string) $shop->description,
            'business_types' => $shop->business_types ?? [],
            'timezone'       => (string) $shop->timezone,
        ], '店铺设置已保存。');
    }

    public function members(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.dashboard.view')) {
            return $denied;
        }

        return $this->ok($this->tenantService->listMembers($this->currentShop($request)));
    }

    public function upsertMember(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.member.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'customer_id'   => ['nullable', 'integer', 'min:1'],
            'email'         => ['nullable', 'email', 'max:128'],
            'role'          => ['required', 'string', 'in:owner,admin,catalog_ops,warehouse,finance'],
            'permissions'   => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'status'        => ['nullable', 'string', 'in:active,disabled'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $member = $this->tenantService->upsertMember($this->currentShop($request), (int) $customer->id, $validated);

            return $this->ok([
                'id'                   => (int) $member->id,
                'customer_id'          => (int) $member->customer_id,
                'role'                 => (string) $member->role,
                'status'               => (string) $member->status,
                'resolved_permissions' => $member->resolvedPermissions(),
            ], '店铺成员权限已更新。');
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    // ── 商品与多规格 SKU、租赁方案、审核上下架 ─────────────────────────────

    public function products(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.view')) {
            return $denied;
        }

        $shop = $this->currentShop($request);
        $paginator = $this->tenantService->listProducts(
            $shop,
            $request->only(['product_type', 'fulfillment_type', 'review_status', 'shelf_status', 'low_stock', 'keyword']),
            (int) $request->input('per_page', 15)
        );

        return $this->ok($paginator->items(), '', [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function showProduct(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.view')) {
            return $denied;
        }

        try {
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->findOwnedProduct($shop, $id);

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone));
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        }
    }

    public function storeProduct(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:180'],
            'sku'                 => ['nullable', 'string', 'max:64'],
            'product_type'        => ['required', 'string', 'in:sale_robot,rental_robot,accessory'],
            'fulfillment_type'    => ['nullable', 'string', 'in:sale,rental'],
            'price_cents'         => ['required', 'integer', 'min:0'],
            'daily_rent_cents'    => ['nullable', 'integer', 'min:0'],
            'deposit_cents'       => ['nullable', 'integer', 'min:0'],
            'min_rent_days'       => ['nullable', 'integer', 'min:1'],
            'sellable_stock'      => ['nullable', 'integer', 'min:0'],
            'rentable_stock'      => ['nullable', 'integer', 'min:0'],
            'safety_stock'        => ['nullable', 'integer', 'min:0'],
            'compatible_models'   => ['nullable', 'array'],
            'compatible_models.*' => ['string', 'max:64'],
            'rental_plans'        => ['nullable', 'array'],
            'deposit_rule'        => ['nullable', 'array'],
            'param_specs'         => ['nullable', 'array'],
            'media_items'         => ['nullable', 'array'],
            'short_description'   => ['nullable', 'string', 'max:500'],
            'description'         => ['nullable', 'string', 'max:5000'],
            'install_method'      => ['nullable', 'string', 'in:self,remote,onsite'],
            'warranty_months'     => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->createProduct(
                $shop,
                (int) $customer->id,
                $validated,
                $request
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '商品草稿创建成功。');
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'name'                => ['sometimes', 'string', 'max:180'],
            'product_type'        => ['sometimes', 'string', 'in:sale_robot,rental_robot,accessory'],
            'fulfillment_type'    => ['sometimes', 'string', 'in:sale,rental'],
            'price_cents'         => ['sometimes', 'integer', 'min:0'],
            'daily_rent_cents'    => ['sometimes', 'integer', 'min:0'],
            'deposit_cents'       => ['sometimes', 'integer', 'min:0'],
            'min_rent_days'       => ['sometimes', 'integer', 'min:1'],
            'safety_stock'        => ['sometimes', 'integer', 'min:0'],
            'compatible_models'   => ['sometimes', 'array'],
            'compatible_models.*' => ['string', 'max:64'],
            'param_specs'         => ['sometimes', 'array'],
            'media_items'         => ['sometimes', 'array'],
            'short_description'   => ['sometimes', 'string', 'max:500'],
            'description'         => ['sometimes', 'string', 'max:5000'],
            'version'             => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->updateProduct(
                $shop,
                (int) $customer->id,
                $id,
                $validated,
                $request
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '商品已更新。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function skus(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.view')) {
            return $denied;
        }

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;

        return $this->ok($this->tenantService->listSkus($this->currentShop($request), $productId));
    }

    public function storeSku(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'product_id'       => ['required', 'integer', 'min:1'],
            'sku_code'         => ['nullable', 'string', 'max:64'],
            'name'             => ['required', 'string', 'max:160'],
            'spec_json'        => ['nullable', 'array'],
            'price_cents'      => ['required', 'integer', 'min:0'],
            'daily_rent_cents' => ['nullable', 'integer', 'min:0'],
            'deposit_cents'    => ['nullable', 'integer', 'min:0'],
            'sellable_stock'   => ['nullable', 'integer', 'min:0'],
            'rentable_stock'   => ['nullable', 'integer', 'min:0'],
            'safety_stock'     => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $sku = $this->tenantService->upsertSku($this->currentShop($request), (int) $customer->id, $validated);

            return $this->ok($sku, '多规格 SKU 创建成功。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '所属商品不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function updateSku(Request $request, int $skuId): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'product_id'       => ['required', 'integer', 'min:1'],
            'name'             => ['sometimes', 'string', 'max:160'],
            'spec_json'        => ['sometimes', 'array'],
            'price_cents'      => ['sometimes', 'integer', 'min:0'],
            'daily_rent_cents' => ['sometimes', 'integer', 'min:0'],
            'deposit_cents'    => ['sometimes', 'integer', 'min:0'],
            'sellable_stock'   => ['sometimes', 'integer', 'min:0'],
            'rentable_stock'   => ['sometimes', 'integer', 'min:0'],
            'safety_stock'     => ['sometimes', 'integer', 'min:0'],
            'status'           => ['sometimes', 'string', 'in:active,disabled'],
            'version'          => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $sku = $this->tenantService->upsertSku($this->currentShop($request), (int) $customer->id, $validated, $skuId);

            return $this->ok($sku, 'SKU 已更新。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '目标 SKU 不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function rentalPlans(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.view')) {
            return $denied;
        }

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;

        return $this->ok($this->tenantService->listRentalPlans($this->currentShop($request), $productId));
    }

    public function storeRentalPlan(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'product_id'             => ['required', 'integer', 'min:1'],
            'plan_code'              => ['nullable', 'string', 'max:64'],
            'name'                   => ['required', 'string', 'max:120'],
            'period_unit'            => ['required', 'string', 'in:day,week,month,quarter'],
            'min_days'               => ['required', 'integer', 'min:1'],
            'max_days'               => ['nullable', 'integer', 'min:1'],
            'base_price_cents'       => ['required', 'integer', 'min:0'],
            'price_tiers'            => ['nullable', 'array'],
            'deposit_rule'           => ['nullable', 'array'],
            'contract_template_code' => ['nullable', 'string', 'max:64'],
            'enabled'                => ['nullable', 'boolean'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $plan = $this->tenantService->upsertRentalPlan($this->currentShop($request), (int) $customer->id, $validated);

            return $this->ok($plan, '租赁计划与阶梯价已保存。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '所属商品不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function updateRentalPlan(Request $request, int $planId): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'product_id'             => ['required', 'integer', 'min:1'],
            'name'                   => ['sometimes', 'string', 'max:120'],
            'period_unit'            => ['sometimes', 'string', 'in:day,week,month,quarter'],
            'min_days'               => ['sometimes', 'integer', 'min:1'],
            'max_days'               => ['sometimes', 'integer', 'min:1'],
            'base_price_cents'       => ['sometimes', 'integer', 'min:0'],
            'price_tiers'            => ['sometimes', 'array'],
            'deposit_rule'           => ['sometimes', 'array'],
            'contract_template_code' => ['sometimes', 'string', 'max:64'],
            'enabled'                => ['sometimes', 'boolean'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $plan = $this->tenantService->upsertRentalPlan($this->currentShop($request), (int) $customer->id, $validated, $planId);

            return $this->ok($plan, '租赁方案已更新。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '目标租赁方案不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function submitReview(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.submit_review')) {
            return $denied;
        }

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->submitForReview(
                $shop,
                (int) $customer->id,
                $id,
                (bool) $request->boolean('auto_approve', false),
                $request
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '商品已提交审核。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function reviewDecision(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.submit_review')) {
            return $denied;
        }

        $validated = $request->validate([
            'decision'      => ['required', 'string', 'in:approve,approved,reject,rejected'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->reviewDecision(
                $shop,
                (int) $customer->id,
                $id,
                (string) $validated['decision'],
                $validated['reject_reason'] ?? null
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '商品审核结论已生效。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function onShelf(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.shelf')) {
            return $denied;
        }

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->putOnShelf(
                $shop,
                (int) $customer->id,
                $id,
                $request
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '商品已上架。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function offShelf(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.product.shelf')) {
            return $denied;
        }

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->pullOffShelf(
                $shop,
                (int) $customer->id,
                $id,
                $request
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '商品已下架（不影响存量履约订单）。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    // ── 多仓库与双轨库存、维保锁定台账 ──────────────────────────────────

    public function warehouses(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.inventory.view')) {
            return $denied;
        }

        return $this->ok($this->tenantService->listWarehouses($this->currentShop($request)));
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.inventory.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'code'          => ['nullable', 'string', 'max:64'],
            'name'          => ['required', 'string', 'max:120'],
            'region'        => ['nullable', 'string', 'max:120'],
            'address'       => ['nullable', 'string', 'max:255'],
            'contact_name'  => ['nullable', 'string', 'max:64'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'is_default'    => ['nullable', 'boolean'],
        ]);

        $customer = $request->attributes->get('merchant_customer');
        $wh = $this->tenantService->upsertWarehouse($this->currentShop($request), (int) $customer->id, $validated);

        return $this->ok($wh, '仓库已保存。');
    }

    public function inventory(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.inventory.view')) {
            return $denied;
        }

        $shop = $this->currentShop($request);
        $paginator = $this->tenantService->listProducts(
            $shop,
            $request->only(['product_type', 'fulfillment_type', 'low_stock', 'keyword']),
            (int) $request->input('per_page', 20)
        );

        $items = collect($paginator->items())->map(fn (array $item) => [
            'product_id'         => $item['product_id'],
            'sku'                => $item['sku'],
            'name'               => $item['name'],
            'product_type'       => $item['product_type'],
            'fulfillment_type'   => $item['fulfillment_type'],
            'sellable_stock'     => $item['sellable_stock'],
            'rentable_stock'     => $item['rentable_stock'],
            'rented_locked_qty'  => $item['rented_locked_qty'],
            'maintenance_qty'    => $item['maintenance_qty'],
            'safety_stock'       => $item['safety_stock'],
            'is_low_stock'       => $item['is_low_stock'],
            'shelf_status'       => $item['shelf_status'],
            'version'            => $item['version'],
        ])->values()->all();

        return $this->ok($items, '', [
            'meta' => [
                'current_page'   => $paginator->currentPage(),
                'per_page'       => $paginator->perPage(),
                'total'          => $paginator->total(),
                'last_page'      => $paginator->lastPage(),
                'warehouses'     => $this->tenantService->listWarehouses($shop),
                'recent_locks'   => $this->tenantService->listInventoryLocks($shop, null, 20),
            ],
        ]);
    }

    public function updateInventory(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.inventory.write')) {
            return $denied;
        }

        $validated = $request->validate([
            'action_type'       => ['nullable', 'string', 'in:restock,stocktake,maintenance_lock,maintenance_release'],
            'qty'               => ['nullable', 'integer', 'min:1'],
            'sellable_stock'    => ['sometimes', 'integer', 'min:0'],
            'rentable_stock'    => ['sometimes', 'integer', 'min:0'],
            'rented_locked_qty' => ['sometimes', 'integer', 'min:0'],
            'maintenance_qty'   => ['sometimes', 'integer', 'min:0'],
            'safety_stock'      => ['sometimes', 'integer', 'min:0'],
            'warehouse_id'      => ['nullable', 'integer', 'min:1'],
            'version'           => ['sometimes', 'integer', 'min:1'],
            'reason'            => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->adjustInventory(
                $shop,
                (int) $customer->id,
                $id,
                $validated,
                $request
            );

            return $this->ok($this->tenantService->formatProductItem($ext, $shop->timezone), '库存已更新。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前商户店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function inventoryLocks(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.inventory.view')) {
            return $denied;
        }

        $productId = $request->filled('product_id') ? (int) $request->input('product_id') : null;

        return $this->ok($this->tenantService->listInventoryLocks($this->currentShop($request), $productId, 50));
    }

    // ── 订单与租赁履约全生命周期状态机 ──────────────────────────────────

    public function orders(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.view')) {
            return $denied;
        }

        $paginator = $this->tenantService->listOrders(
            $this->currentShop($request),
            $request->only(['fulfillment_type', 'status', 'keyword']),
            (int) $request->input('per_page', 15)
        );

        return $this->ok($paginator->items(), '', [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function rentalOrders(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.view')) {
            return $denied;
        }

        $paginator = $this->tenantService->listOrders(
            $this->currentShop($request),
            array_merge($request->only(['status', 'keyword']), ['fulfillment_type' => 'rental']),
            (int) $request->input('per_page', 15)
        );

        return $this->ok($paginator->items(), '', [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.fulfill')) {
            return $denied;
        }

        $validated = $request->validate([
            'product_id'       => ['required', 'integer', 'min:1'],
            'sku_id'           => ['nullable', 'integer', 'min:1'],
            'rental_plan_id'   => ['nullable', 'integer', 'min:1'],
            'fulfillment_type' => ['nullable', 'string', 'in:sale,rental'],
            'buyer_name'       => ['required', 'string', 'max:64'],
            'buyer_phone'      => ['nullable', 'string', 'max:32'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'qty'              => ['nullable', 'integer', 'min:1', 'max:100'],
            'rental_days'      => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $order = $this->tenantService->createOrder($shop, (int) $customer->id, $validated);

            return $this->ok($this->tenantService->formatOrderItem($order, $shop->timezone), '订单已创建并锁定对应库存。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '商品不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function shipOrder(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.fulfill')) {
            return $denied;
        }

        $validated = $request->validate([
            'carrier'     => ['nullable', 'string', 'max:64'],
            'tracking_no' => ['required', 'string', 'max:64'],
            'device_sn'   => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $order = $this->tenantService->shipOrder($shop, (int) $customer->id, $id, $validated);

            return $this->ok($this->tenantService->formatOrderItem($order, $shop->timezone), '订单已出库发货。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function returnOrder(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.fulfill')) {
            return $denied;
        }

        $validated = $request->validate([
            'return_carrier'     => ['nullable', 'string', 'max:64'],
            'return_tracking_no' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $order = $this->tenantService->returnOrder($shop, (int) $customer->id, $id, $validated);

            return $this->ok($this->tenantService->formatOrderItem($order, $shop->timezone), '租赁设备归还已登记，请进行验机定损。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function inspectOrder(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.fulfill')) {
            return $denied;
        }

        $validated = $request->validate([
            'inspection_result'   => ['required', 'string', 'in:passed,minor_damage,major_damage'],
            'inspection_notes'    => ['nullable', 'string', 'max:500'],
            'damage_deduct_cents' => ['nullable', 'integer', 'min:0'],
            'overdue_fee_cents'   => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $order = $this->tenantService->inspectOrder($shop, (int) $customer->id, $id, $validated);

            return $this->ok($this->tenantService->formatOrderItem($order, $shop->timezone), '归还验机与定损已完成，库存及押金核算已自动联动。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function completeOrder(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.fulfill')) {
            return $denied;
        }

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $order = $this->tenantService->completeOrder($shop, (int) $customer->id, $id);

            return $this->ok($this->tenantService->formatOrderItem($order, $shop->timezone), '订单履约已完结，已纳入待结算池。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.order.fulfill')) {
            return $denied;
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'party'  => ['nullable', 'string', 'in:merchant,buyer,platform'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $shop = $this->currentShop($request);
            $order = $this->tenantService->cancelOrder($shop, (int) $customer->id, $id, $validated);

            return $this->ok($this->tenantService->formatOrderItem($order, $shop->timezone), '订单已成功取消，预扣库存已安全原路释放。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    // ── 售后退换修与维保工单 ───────────────────────────────────────────

    public function afterSales(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.after_sale.view')) {
            return $denied;
        }

        $paginator = $this->tenantService->listAfterSalesTickets(
            $this->currentShop($request),
            $request->only(['ticket_type', 'status']),
            (int) $request->input('per_page', 15)
        );

        return $this->ok($paginator->items(), '', [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function storeAfterSales(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.after_sales.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'product_id'              => ['required', 'integer', 'min:1'],
            'merchant_order_id'       => ['nullable', 'integer', 'min:1'],
            'ticket_type'             => ['required', 'string', 'in:return_refund,exchange,repair,maintenance'],
            'device_sn'               => ['nullable', 'string', 'max:64'],
            'requester_name'          => ['nullable', 'string', 'max:64'],
            'reason'                  => ['required', 'string', 'max:500'],
            'refund_cents'            => ['nullable', 'integer', 'min:0'],
            'compensation_cents'      => ['nullable', 'integer', 'min:0'],
            'locks_maintenance_stock' => ['nullable', 'boolean'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $ticket = $this->tenantService->createAfterSalesTicket(
                $this->currentShop($request),
                (int) $customer->id,
                $validated
            );

            return $this->ok($ticket, '售后/维保工单已创建。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::PRODUCT_NOT_FOUND, '关联商品不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function resolveAfterSales(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.after_sales.manage')) {
            return $denied;
        }

        $validated = $request->validate([
            'status'                    => ['required', 'string', 'in:processing,resolved,rejected'],
            'resolution_notes'          => ['nullable', 'string', 'max:500'],
            'refund_cents'              => ['nullable', 'integer', 'min:0'],
            'compensation_cents'        => ['nullable', 'integer', 'min:0'],
            'release_maintenance_stock' => ['nullable', 'boolean'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $ticket = $this->tenantService->resolveAfterSalesTicket(
                $this->currentShop($request),
                (int) $customer->id,
                $id,
                $validated
            );

            return $this->ok($ticket, '工单处理状态已更新。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::TICKET_NOT_FOUND, '工单不存在或不属于当前店铺。', 404);
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    // ── 结算单、结算明细与发票中心 ─────────────────────────────────────

    public function settlements(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.settlement.view')) {
            return $denied;
        }

        $shop = $this->currentShop($request);
        $metrics = $this->tenantService->dashboardMetrics($shop);

        return $this->ok($this->tenantService->listSettlements($shop), '', [
            'meta' => [
                'settlement_pending_cents' => $metrics['settlement_pending_cents'],
                'invoices'                 => $this->tenantService->listInvoices($shop),
                'seller_id'                => (int) $shop->seller_id,
            ],
        ]);
    }

    public function storeSettlement(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.settlement.view')) {
            return $denied;
        }

        try {
            $customer = $request->attributes->get('merchant_customer');
            $settlement = $this->tenantService->createSettlement(
                $this->currentShop($request),
                (int) $customer->id,
                $request->input('period_label')
            );

            return $this->ok($settlement, '本期结算单已生成。');
        } catch (RuntimeException $e) {
            return $this->mapDomainException($e);
        }
    }

    public function confirmSettlement(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.settlement.view')) {
            return $denied;
        }

        try {
            $customer = $request->attributes->get('merchant_customer');
            $settlement = $this->tenantService->confirmSettlement(
                $this->currentShop($request),
                (int) $customer->id,
                $id
            );

            return $this->ok($settlement, '结算单已确认无误。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '结算单不存在或不属于当前店铺。', 404);
        }
    }

    public function invoices(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.settlement.view')) {
            return $denied;
        }

        return $this->ok($this->tenantService->listInvoices($this->currentShop($request)));
    }

    public function storeInvoice(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.settlement.view')) {
            return $denied;
        }

        $validated = $request->validate([
            'settlement_id'       => ['nullable', 'integer', 'min:1'],
            'merchant_order_id'   => ['nullable', 'integer', 'min:1'],
            'direction'           => ['nullable', 'string', 'in:outbound,platform_fee'],
            'invoice_type'        => ['nullable', 'string', 'in:vat_normal,vat_special'],
            'buyer_title'         => ['required', 'string', 'max:191'],
            'buyer_tax_no'        => ['nullable', 'string', 'max:64'],
            'amount_cents'        => ['required', 'integer', 'min:1'],
            'tax_rate'            => ['nullable', 'string', 'max:16'],
            'invoice_code_number' => ['nullable', 'string', 'max:64'],
        ]);

        $customer = $request->attributes->get('merchant_customer');
        $invoice = $this->tenantService->createOrIssueInvoice(
            $this->currentShop($request),
            (int) $customer->id,
            $validated
        );

        return $this->ok($invoice, '发票记录已保存。');
    }

    public function issueInvoice(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.settlement.view')) {
            return $denied;
        }

        $validated = $request->validate([
            'invoice_code_number' => ['required', 'string', 'max:64'],
        ]);

        try {
            $customer = $request->attributes->get('merchant_customer');
            $invoice = $this->tenantService->createOrIssueInvoice(
                $this->currentShop($request),
                (int) $customer->id,
                $validated,
                $id
            );

            return $this->ok($invoice, '发票已开具登记。');
        } catch (ModelNotFoundException) {
            return $this->fail(ApiErrorCode::NOT_FOUND, '发票记录不存在或不属于当前店铺。', 404);
        }
    }

    // ── 操作审计日志 ───────────────────────────────────────────────────

    public function auditLogs(Request $request): JsonResponse
    {
        if ($denied = $this->requirePermission($request, 'merchant.audit.view')) {
            return $denied;
        }

        $paginator = $this->tenantService->listAuditLogs(
            $this->currentShop($request),
            (int) $request->input('per_page', 20),
            $request->only(['action_prefix'])
        );

        return $this->ok($paginator->items(), '', [
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    protected function currentShop(Request $request): MerchantShop
    {
        return $request->attributes->get('merchant_shop');
    }

    protected function currentMember(Request $request): MerchantMember
    {
        return $request->attributes->get('merchant_member');
    }

    protected function requirePermission(Request $request, string $permission): ?JsonResponse
    {
        $member = $this->currentMember($request);
        if (! $member || ! $member->hasPermission($permission)) {
            return $this->fail(
                ApiErrorCode::MERCHANT_PERMISSION_DENIED,
                '当前店铺角色无权执行该操作（需权限：'.$permission.'）。',
                403
            );
        }

        return null;
    }

    protected function mapDomainException(RuntimeException $e): JsonResponse
    {
        $raw = $e->getMessage();
        [$prefix, $detail] = array_pad(explode(':', $raw, 2), 2, $raw);

        return match ($prefix) {
            'COMPATIBLE_MODEL_UNKNOWN' => $this->fail(ApiErrorCode::COMPATIBLE_MODEL_UNKNOWN, $detail, 422),
            'MODEL_L3_DISPLAY_ONLY'    => $this->fail(ApiErrorCode::MODEL_L3_DISPLAY_ONLY, $detail, 422),
            'VERSION_CONFLICT'         => $this->fail(ApiErrorCode::CONFLICT, $detail, 409),
            'INVALID_STATE'            => $this->fail(ApiErrorCode::CONFLICT, $detail, 409),
            'VALIDATION_ERROR'         => $this->fail(ApiErrorCode::VALIDATION_ERROR, $detail, 422),
            default                    => $this->fail(ApiErrorCode::VALIDATION_ERROR, $raw, 422),
        };
    }
}
