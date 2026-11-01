<?php

namespace Webkul\Shop\Http\Controllers\Merchant;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantShop;
use Webkul\Shop\Services\Merchant\MerchantTenantService;
use Webkul\Shop\Services\Merchant\PortalBackendRegistryService;

class MerchantPortalController extends Controller
{
    public function __construct(
        protected MerchantTenantService $tenantService,
        protected PortalBackendRegistryService $registryService,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (auth()->guard('merchant')->check()) {
            return redirect()->route('shop.merchant.dashboard');
        }

        return view('shop::merchant.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! auth()->guard('merchant')->attempt($credentials)) {
            return back()->withInput($request->only('email'))
                ->with('error', '邮箱或密码错误。');
        }

        $customer = auth()->guard('merchant')->user();
        if (isset($customer->status) && ! $customer->status) {
            auth()->guard('merchant')->logout();

            return back()->withInput($request->only('email'))
                ->with('error', '账号已被停用，请联系平台管理员。');
        }

        $request->session()->regenerate();

        $access = $this->registryService->checkMerchantPortalAccess($customer);
        if (! $access['has_permission']) {
            return redirect()->route('shop.merchant.dashboard');
        }

        $context = $this->tenantService->resolveContext((int) $customer->id);
        if (! $context['active_shop']) {
            return redirect()->route('shop.merchant.onboarding');
        }

        return redirect()->intended(route('shop.merchant.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        auth()->guard('merchant')->logout();
        $request->session()->forget('merchant_active_shop_id');

        return redirect()->route('shop.merchant.login')
            ->with('success', '已安全退出商家后台。');
    }

    public function showOnboarding(Request $request): View|RedirectResponse
    {
        $customer = auth()->guard('merchant')->user();
        $context = $this->tenantService->resolveContext((int) $customer->id);

        if ($context['active_shop']) {
            return redirect()->route('shop.merchant.dashboard');
        }

        $supplierApp = null;
        if (Schema::hasTable('cooperation_applications')) {
            $supplierApp = DB::table('cooperation_applications')
                ->where('customer_id', (int) $customer->id)
                ->orderByDesc('id')
                ->first();
        }

        return view('shop::merchant.onboarding', [
            'customer'    => $customer,
            'supplierApp' => $supplierApp,
            'agreements'  => config('portal_backends.agreements', []),
        ]);
    }

    public function storeOnboarding(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name'     => ['required', 'string', 'max:191'],
            'shop_name'        => ['required', 'string', 'max:128'],
            'credit_code'      => ['nullable', 'string', 'max:64'],
            'contact_name'     => ['required', 'string', 'max:64'],
            'contact_phone'    => ['required', 'string', 'max:32'],
            'contact_email'    => ['nullable', 'email', 'max:128'],
            'business_types'   => ['required', 'array', 'min:1'],
            'business_types.*' => ['string', 'in:sale_robot,rental_robot,accessory'],
            'timezone'         => ['nullable', 'string', 'max:64'],
            'accept_agreement' => ['accepted'],
        ], [
            'accept_agreement.accepted' => '请先阅读并勾选同意《商家入驻与租赁履约服务协议》。',
        ]);

        $customer = auth()->guard('merchant')->user();
        $result = $this->tenantService->onboard((int) $customer->id, $validated, $request);

        $request->session()->put('merchant_active_shop_id', (int) $result['shop']->id);

        return redirect()->route('shop.merchant.dashboard')
            ->with('success', '商家入驻与首家店铺开通成功。');
    }

    // ── 一级工作台 Tab 视图 ─────────────────────────────────────────────

    public function dashboard(Request $request): View
    {
        return $this->renderWorkspace($request, (string) $request->query('tab', 'overview'));
    }

    public function products(Request $request): View
    {
        return $this->renderWorkspace($request, 'products');
    }

    public function inventory(Request $request): View
    {
        return $this->renderWorkspace($request, 'inventory');
    }

    public function orders(Request $request): View
    {
        return $this->renderWorkspace($request, 'orders');
    }

    public function afterSales(Request $request): View
    {
        return $this->renderWorkspace($request, 'after_sales');
    }

    public function settlements(Request $request): View
    {
        return $this->renderWorkspace($request, 'settlements');
    }

    public function settings(Request $request): View
    {
        return $this->renderWorkspace($request, 'settings');
    }

    /**
     * 二级页面：商品详情工作台（集成 SPU 参数/媒体、L3 多规格 SKU 矩阵、L3 租赁阶梯方案与押金规则、L4 审核流转）
     */
    public function showProduct(Request $request, int $id): View|RedirectResponse
    {
        try {
            $shop = $this->currentShop($request);
            $ext = $this->tenantService->findOwnedProduct($shop, $id);
            $product = $this->tenantService->formatProductItem($ext, $shop->timezone);

            return view('shop::merchant.product-detail', [
                'activeTab'    => 'products',
                'product'      => $product,
                'deviceModels' => $this->loadDeviceModels(),
                'locks'        => $this->tenantService->listInventoryLocks($shop, $id, 15),
                'rolesConfig'  => config('portal_backends.roles', []),
            ]);
        } catch (ModelNotFoundException) {
            return redirect()->route('shop.merchant.products')
                ->with('error', '目标商品不存在或不属于当前店铺。');
        }
    }

    // ── 商品、多规格 SKU、租赁方案、审核上下架写动作 ──────────────────────

    public function storeProduct(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.write')) {
            return back()->with('error', '当前角色无权创建商品。');
        }

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:180'],
            'sku'                   => ['nullable', 'string', 'max:64'],
            'product_type'          => ['required', 'string', 'in:sale_robot,rental_robot,accessory'],
            'fulfillment_type'      => ['nullable', 'string', 'in:sale,rental'],
            'price_yuan'            => ['required', 'numeric', 'min:0'],
            'deposit_yuan'          => ['nullable', 'numeric', 'min:0'],
            'sellable_stock'        => ['nullable', 'integer', 'min:0'],
            'rentable_stock'        => ['nullable', 'integer', 'min:0'],
            'safety_stock'          => ['nullable', 'integer', 'min:0'],
            'compatible_models_raw' => ['nullable', 'string', 'max:255'],
            'daily_rent_yuan'       => ['nullable', 'numeric', 'min:0'],
            'weekly_rent_yuan'      => ['nullable', 'numeric', 'min:0'],
            'monthly_rent_yuan'     => ['nullable', 'numeric', 'min:0'],
            'short_description'     => ['nullable', 'string', 'max:500'],
            'description'           => ['nullable', 'string', 'max:5000'],
        ]);

        $compatibleModels = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,，\s]+/', (string) ($validated['compatible_models_raw'] ?? '')) ?: []
        )));

        $rentalPlans = [];
        if (($validated['product_type'] ?? '') === 'rental_robot' || ($validated['fulfillment_type'] ?? '') === 'rental') {
            if (! empty($validated['daily_rent_yuan'])) {
                $rentalPlans[] = ['period' => 'day', 'days' => 1, 'price_cents' => (int) round(((float) $validated['daily_rent_yuan']) * 100)];
            }
            if (! empty($validated['weekly_rent_yuan'])) {
                $rentalPlans[] = ['period' => 'week', 'days' => 7, 'price_cents' => (int) round(((float) $validated['weekly_rent_yuan']) * 100)];
            }
            if (! empty($validated['monthly_rent_yuan'])) {
                $rentalPlans[] = ['period' => 'month', 'days' => 30, 'price_cents' => (int) round(((float) $validated['monthly_rent_yuan']) * 100)];
            }
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $ext = $this->tenantService->createProduct(
                $this->currentShop($request),
                (int) $customer->id,
                [
                    'name'              => $validated['name'],
                    'sku'               => $validated['sku'] ?? null,
                    'product_type'      => $validated['product_type'],
                    'fulfillment_type'  => $validated['fulfillment_type'] ?? null,
                    'price_cents'       => (int) round(((float) $validated['price_yuan']) * 100),
                    'daily_rent_cents'  => ! empty($validated['daily_rent_yuan'])
                        ? (int) round(((float) $validated['daily_rent_yuan']) * 100)
                        : (int) round(((float) $validated['price_yuan']) * 100),
                    'deposit_cents'     => (int) round(((float) ($validated['deposit_yuan'] ?? 0)) * 100),
                    'sellable_stock'    => (int) ($validated['sellable_stock'] ?? 0),
                    'rentable_stock'    => (int) ($validated['rentable_stock'] ?? 0),
                    'safety_stock'      => (int) ($validated['safety_stock'] ?? 2),
                    'compatible_models' => $compatibleModels,
                    'rental_plans'      => $rentalPlans,
                    'short_description' => $validated['short_description'] ?? '',
                    'description'       => $validated['description'] ?? '',
                ],
                $request
            );

            return redirect()->route('shop.merchant.products.show', $ext->product_id)
                ->with('success', '商品草稿已创建，您可在下方继续配置多规格 SKU、租赁阶梯价与技术参数。');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function updateProduct(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.write')) {
            return back()->with('error', '当前角色无权修改商品。');
        }

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:180'],
            'price_yuan'            => ['required', 'numeric', 'min:0'],
            'daily_rent_yuan'       => ['nullable', 'numeric', 'min:0'],
            'deposit_yuan'          => ['nullable', 'numeric', 'min:0'],
            'min_rent_days'         => ['nullable', 'integer', 'min:1'],
            'safety_stock'          => ['nullable', 'integer', 'min:0'],
            'compatible_models_raw' => ['nullable', 'string', 'max:255'],
            'short_description'     => ['nullable', 'string', 'max:500'],
            'description'           => ['nullable', 'string', 'max:5000'],
            'param_dof'             => ['nullable', 'string', 'max:64'],
            'param_battery'         => ['nullable', 'string', 'max:64'],
            'param_payload'         => ['nullable', 'string', 'max:64'],
            'media_cover_url'       => ['nullable', 'string', 'max:255'],
            'version'               => ['nullable', 'integer', 'min:1'],
        ]);

        $compatibleModels = array_values(array_filter(array_map(
            'trim',
            preg_split('/[,，\s]+/', (string) ($validated['compatible_models_raw'] ?? '')) ?: []
        )));

        $paramSpecs = array_filter([
            '自由度/关节' => $validated['param_dof'] ?? null,
            '续航时间'    => $validated['param_battery'] ?? null,
            '额定负载'    => $validated['param_payload'] ?? null,
        ]);

        $mediaItems = ! empty($validated['media_cover_url'])
            ? [['type' => 'image', 'url' => trim((string) $validated['media_cover_url'])]]
            : [];

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->updateProduct(
                $this->currentShop($request),
                (int) $customer->id,
                $id,
                [
                    'name'              => $validated['name'],
                    'price_cents'       => (int) round(((float) $validated['price_yuan']) * 100),
                    'daily_rent_cents'  => (int) round(((float) ($validated['daily_rent_yuan'] ?? $validated['price_yuan'])) * 100),
                    'deposit_cents'     => (int) round(((float) ($validated['deposit_yuan'] ?? 0)) * 100),
                    'min_rent_days'     => (int) ($validated['min_rent_days'] ?? 1),
                    'safety_stock'      => (int) ($validated['safety_stock'] ?? 2),
                    'compatible_models' => $compatibleModels,
                    'short_description' => $validated['short_description'] ?? '',
                    'description'       => $validated['description'] ?? '',
                    'param_specs'       => $paramSpecs,
                    'media_items'       => $mediaItems,
                    'version'           => $validated['version'] ?? null,
                ],
                $request
            );

            return back()->with('success', '商品基础信息、技术参数与适配机型已更新。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function storeSku(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.write')) {
            return back()->with('error', '当前角色无权管理 SKU。');
        }

        $validated = $request->validate([
            'sku_id'          => ['nullable', 'integer', 'min:1'],
            'sku_code'        => ['nullable', 'string', 'max:64'],
            'name'            => ['required', 'string', 'max:160'],
            'spec_label'      => ['nullable', 'string', 'max:120'],
            'price_yuan'      => ['required', 'numeric', 'min:0'],
            'daily_rent_yuan' => ['nullable', 'numeric', 'min:0'],
            'deposit_yuan'    => ['nullable', 'numeric', 'min:0'],
            'sellable_stock'  => ['required', 'integer', 'min:0'],
            'rentable_stock'  => ['required', 'integer', 'min:0'],
            'safety_stock'    => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->upsertSku(
                $this->currentShop($request),
                (int) $customer->id,
                [
                    'product_id'       => $id,
                    'sku_code'         => $validated['sku_code'] ?? null,
                    'name'             => $validated['name'],
                    'spec_json'        => ! empty($validated['spec_label']) ? ['规格' => $validated['spec_label']] : ['规格' => $validated['name']],
                    'price_cents'      => (int) round(((float) $validated['price_yuan']) * 100),
                    'daily_rent_cents' => (int) round(((float) ($validated['daily_rent_yuan'] ?? $validated['price_yuan'])) * 100),
                    'deposit_cents'    => (int) round(((float) ($validated['deposit_yuan'] ?? 0)) * 100),
                    'sellable_stock'   => (int) $validated['sellable_stock'],
                    'rentable_stock'   => (int) $validated['rentable_stock'],
                    'safety_stock'     => (int) ($validated['safety_stock'] ?? 1),
                ],
                isset($validated['sku_id']) ? (int) $validated['sku_id'] : null
            );

            return back()->with('success', '多规格 SKU 已保存并自动汇总同步至商品总库存。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function storeRentalPlan(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.write')) {
            return back()->with('error', '当前角色无权管理租赁方案。');
        }

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:120'],
            'period_unit'         => ['required', 'string', 'in:day,week,month,quarter'],
            'min_days'            => ['required', 'integer', 'min:1'],
            'max_days'            => ['nullable', 'integer', 'min:1'],
            'base_price_yuan'     => ['required', 'numeric', 'min:0'],
            'tier_7_daily_yuan'   => ['nullable', 'numeric', 'min:0'],
            'tier_30_daily_yuan'  => ['nullable', 'numeric', 'min:0'],
            'deposit_mode'        => ['required', 'string', 'in:full,ratio,credit_waiver'],
            'deposit_yuan'        => ['required', 'numeric', 'min:0'],
            'overdue_daily_yuan'  => ['nullable', 'numeric', 'min:0'],
            'buyout_price_yuan'   => ['nullable', 'numeric', 'min:0'],
        ]);

        $tiers = [];
        if (! empty($validated['tier_7_daily_yuan'])) {
            $tiers[] = ['min_days' => 7, 'daily_cents' => (int) round(((float) $validated['tier_7_daily_yuan']) * 100)];
        }
        if (! empty($validated['tier_30_daily_yuan'])) {
            $tiers[] = ['min_days' => 30, 'daily_cents' => (int) round(((float) $validated['tier_30_daily_yuan']) * 100)];
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->upsertRentalPlan(
                $this->currentShop($request),
                (int) $customer->id,
                [
                    'product_id'       => $id,
                    'name'             => $validated['name'],
                    'period_unit'      => $validated['period_unit'],
                    'min_days'         => (int) $validated['min_days'],
                    'max_days'         => (int) ($validated['max_days'] ?? 365),
                    'base_price_cents' => (int) round(((float) $validated['base_price_yuan']) * 100),
                    'price_tiers'      => $tiers,
                    'deposit_rule'     => [
                        'mode'                => $validated['deposit_mode'],
                        'deposit_cents'       => (int) round(((float) $validated['deposit_yuan']) * 100),
                        'overdue_daily_cents' => (int) round(((float) ($validated['overdue_daily_yuan'] ?? 0)) * 100),
                        'buyout_price_cents'  => (int) round(((float) ($validated['buyout_price_yuan'] ?? 0)) * 100),
                    ],
                ]
            );

            return back()->with('success', '租赁方案、租期阶梯价与押金规则已保存。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function submitReview(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.submit_review')) {
            return back()->with('error', '当前角色无权提交审核。');
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $autoApprove = $request->boolean('auto_approve', false);
            $this->tenantService->submitForReview(
                $this->currentShop($request),
                (int) $customer->id,
                $id,
                $autoApprove,
                $request
            );

            return back()->with('success', $autoApprove ? '商品已提审并通过初审，现在可上架。' : '商品已提交审核（状态：待审核 in_review）。');
        } catch (ModelNotFoundException) {
            return back()->with('error', '商品不存在或不属于当前店铺。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function reviewDecision(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.submit_review')) {
            return back()->with('error', '当前角色无权执行审核判定。');
        }

        $validated = $request->validate([
            'decision'      => ['required', 'string', 'in:approve,reject'],
            'reject_reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->reviewDecision(
                $this->currentShop($request),
                (int) $customer->id,
                $id,
                $validated['decision'],
                $validated['reject_reason'] ?? null
            );

            return back()->with('success', $validated['decision'] === 'approve' ? '商品审核已通过，现在可以执行上架。' : '商品已驳回并记录驳回原因。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function onShelf(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.shelf')) {
            return back()->with('error', '当前角色无权执行上架。');
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->putOnShelf($this->currentShop($request), (int) $customer->id, $id, $request);

            return back()->with('success', '商品已上架，已实时同步至前台目录（product_flat.status=1）。');
        } catch (ModelNotFoundException) {
            return back()->with('error', '商品不存在或不属于当前店铺。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function offShelf(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.product.shelf')) {
            return back()->with('error', '当前角色无权执行下架。');
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->pullOffShelf($this->currentShop($request), (int) $customer->id, $id, $request);

            return back()->with('success', '商品已下架（存量订单与在租设备履约不受影响）。');
        } catch (ModelNotFoundException) {
            return back()->with('error', '商品不存在或不属于当前店铺。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    // ── 库存与多仓库写动作 ───────────────────────────────────────────────

    public function storeWarehouse(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.inventory.write')) {
            return back()->with('error', '当前角色无权管理仓库。');
        }

        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:120'],
            'region'        => ['nullable', 'string', 'max:120'],
            'address'       => ['nullable', 'string', 'max:255'],
            'contact_name'  => ['nullable', 'string', 'max:64'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'is_default'    => ['nullable', 'boolean'],
        ]);

        $customer = auth()->guard('merchant')->user();
        $this->tenantService->upsertWarehouse($this->currentShop($request), (int) $customer->id, $validated);

        return back()->with('success', '仓库节点已保存。');
    }

    public function adjustInventory(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.inventory.write')) {
            return back()->with('error', '当前角色无权调整库存。');
        }

        $validated = $request->validate([
            'action_type'       => ['nullable', 'string', 'in:restock,stocktake,maintenance_lock,maintenance_release'],
            'qty'               => ['nullable', 'integer', 'min:1'],
            'sellable_stock'    => ['nullable', 'integer', 'min:0'],
            'rentable_stock'    => ['nullable', 'integer', 'min:0'],
            'rented_locked_qty' => ['nullable', 'integer', 'min:0'],
            'maintenance_qty'   => ['nullable', 'integer', 'min:0'],
            'safety_stock'      => ['nullable', 'integer', 'min:0'],
            'version'           => ['nullable', 'integer', 'min:1'],
            'reason'            => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->adjustInventory(
                $this->currentShop($request),
                (int) $customer->id,
                $id,
                $validated,
                $request
            );

            return back()->with('success', '库存与台账流水已安全更新。');
        } catch (ModelNotFoundException) {
            return back()->with('error', '商品不存在或不属于当前店铺。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    // ── 订单与租赁履约全状态机写动作 ─────────────────────────────────────

    public function storeOrder(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.order.fulfill')) {
            return back()->with('error', '当前角色无权创建履约单。');
        }

        $validated = $request->validate([
            'product_id'       => ['required', 'integer', 'min:1'],
            'fulfillment_type' => ['required', 'string', 'in:sale,rental'],
            'buyer_name'       => ['required', 'string', 'max:64'],
            'buyer_phone'      => ['nullable', 'string', 'max:32'],
            'shipping_address' => ['nullable', 'string', 'max:255'],
            'qty'              => ['required', 'integer', 'min:1', 'max:50'],
            'rental_days'      => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $order = $this->tenantService->createOrder($this->currentShop($request), (int) $customer->id, $validated);

            return redirect()->route('shop.merchant.orders')
                ->with('success', '订单 '.$order->order_no.' 已生成并锁定对应库存。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function shipOrder(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.order.fulfill')) {
            return back()->with('error', '当前角色无权执行发货。');
        }

        $validated = $request->validate([
            'carrier'     => ['nullable', 'string', 'max:64'],
            'tracking_no' => ['required', 'string', 'max:64'],
            'device_sn'   => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->shipOrder($this->currentShop($request), (int) $customer->id, $id, $validated);

            return back()->with('success', '订单已发货并绑定机器序列号 SN。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function returnOrder(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.order.fulfill')) {
            return back()->with('error', '当前角色无权登记归还。');
        }

        $validated = $request->validate([
            'return_carrier'     => ['nullable', 'string', 'max:64'],
            'return_tracking_no' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->returnOrder($this->currentShop($request), (int) $customer->id, $id, $validated);

            return back()->with('success', '客户归还已登记，请执行归还验机与定损。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function inspectOrder(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.order.fulfill')) {
            return back()->with('error', '当前角色无权执行验机定损。');
        }

        $validated = $request->validate([
            'inspection_result'  => ['required', 'string', 'in:passed,minor_damage,major_damage'],
            'inspection_notes'   => ['nullable', 'string', 'max:500'],
            'damage_deduct_yuan' => ['nullable', 'numeric', 'min:0'],
            'overdue_fee_yuan'   => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->inspectOrder($this->currentShop($request), (int) $customer->id, $id, [
                'inspection_result'   => $validated['inspection_result'],
                'inspection_notes'    => $validated['inspection_notes'] ?? '',
                'damage_deduct_cents' => (int) round(((float) ($validated['damage_deduct_yuan'] ?? 0)) * 100),
                'overdue_fee_cents'   => (int) round(((float) ($validated['overdue_fee_yuan'] ?? 0)) * 100),
            ]);

            return back()->with('success', '归还验机与定损已完成，库存释放/维保转入及应退押金已自动核算。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function completeOrder(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.order.fulfill')) {
            return back()->with('error', '当前角色无权完结订单。');
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->completeOrder($this->currentShop($request), (int) $customer->id, $id);

            return back()->with('success', '订单已完结并进入待结算池。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function cancelOrder(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.order.fulfill')) {
            return back()->with('error', '当前角色无权取消订单。');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'party'  => ['nullable', 'string', 'in:merchant,buyer,platform'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->cancelOrder($this->currentShop($request), (int) $customer->id, $id, [
                'reason' => $validated['reason'],
                'party'  => $validated['party'] ?? 'merchant',
            ]);

            return back()->with('success', '订单已取消，预扣库存已安全原路释放。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    // ── 售后与维保工单写动作 ─────────────────────────────────────────────

    public function storeAfterSales(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.after_sales.manage')) {
            return back()->with('error', '当前角色无权创建售后/维保工单。');
        }

        $validated = $request->validate([
            'product_id'              => ['required', 'integer', 'min:1'],
            'merchant_order_id'       => ['nullable', 'integer', 'min:1'],
            'ticket_type'             => ['required', 'string', 'in:return_refund,exchange,repair,maintenance'],
            'device_sn'               => ['nullable', 'string', 'max:64'],
            'requester_name'          => ['nullable', 'string', 'max:64'],
            'reason'                  => ['required', 'string', 'max:500'],
            'refund_yuan'             => ['nullable', 'numeric', 'min:0'],
            'compensation_yuan'       => ['nullable', 'numeric', 'min:0'],
            'locks_maintenance_stock' => ['nullable', 'boolean'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->createAfterSalesTicket($this->currentShop($request), (int) $customer->id, [
                'product_id'              => (int) $validated['product_id'],
                'merchant_order_id'       => isset($validated['merchant_order_id']) ? (int) $validated['merchant_order_id'] : null,
                'ticket_type'             => $validated['ticket_type'],
                'device_sn'               => $validated['device_sn'] ?? null,
                'requester_name'          => $validated['requester_name'] ?? '客户/运维员',
                'reason'                  => $validated['reason'],
                'refund_cents'            => (int) round(((float) ($validated['refund_yuan'] ?? 0)) * 100),
                'compensation_cents'      => (int) round(((float) ($validated['compensation_yuan'] ?? 0)) * 100),
                'locks_maintenance_stock' => $request->boolean('locks_maintenance_stock', $validated['ticket_type'] === 'maintenance'),
            ]);

            return back()->with('success', '售后/维保工单已创建。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function resolveAfterSales(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.after_sales.manage')) {
            return back()->with('error', '当前角色无权处理工单。');
        }

        $validated = $request->validate([
            'status'                    => ['required', 'string', 'in:processing,resolved,rejected'],
            'resolution_notes'          => ['nullable', 'string', 'max:500'],
            'refund_yuan'               => ['nullable', 'numeric', 'min:0'],
            'release_maintenance_stock' => ['nullable', 'boolean'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->resolveAfterSalesTicket($this->currentShop($request), (int) $customer->id, $id, [
                'status'                    => $validated['status'],
                'resolution_notes'          => $validated['resolution_notes'] ?? '已处理完结',
                'refund_cents'              => isset($validated['refund_yuan']) ? (int) round(((float) $validated['refund_yuan']) * 100) : null,
                'release_maintenance_stock' => $request->boolean('release_maintenance_stock', true),
            ]);

            return back()->with('success', '工单状态已更新，关联维保锁定库存已同步释放。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    // ── 结算单与发票写动作 ───────────────────────────────────────────────

    public function storeSettlement(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.settlement.view')) {
            return back()->with('error', '当前角色无权生成结算单。');
        }

        try {
            $customer = auth()->guard('merchant')->user();
            $stl = $this->tenantService->createSettlement(
                $this->currentShop($request),
                (int) $customer->id,
                $request->input('period_label')
            );

            return back()->with('success', '结算单 '.$stl->settlement_no.' 已生成（共汇总 '.$stl->orders_count.' 笔已完结订单）。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    public function confirmSettlement(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.settlement.view')) {
            return back()->with('error', '当前角色无权确认结算单。');
        }

        $customer = auth()->guard('merchant')->user();
        $this->tenantService->confirmSettlement($this->currentShop($request), (int) $customer->id, $id);

        return back()->with('success', '结算单已确认无误。');
    }

    public function storeInvoice(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.settlement.view')) {
            return back()->with('error', '当前角色无权管理发票。');
        }

        $validated = $request->validate([
            'settlement_id'       => ['nullable', 'integer', 'min:1'],
            'direction'           => ['nullable', 'string', 'in:outbound,platform_fee'],
            'invoice_type'        => ['required', 'string', 'in:vat_normal,vat_special'],
            'buyer_title'         => ['required', 'string', 'max:191'],
            'buyer_tax_no'        => ['nullable', 'string', 'max:64'],
            'amount_yuan'         => ['required', 'numeric', 'min:0.01'],
            'tax_rate'            => ['nullable', 'string', 'max:16'],
            'invoice_code_number' => ['nullable', 'string', 'max:64'],
        ]);

        $customer = auth()->guard('merchant')->user();
        $this->tenantService->createOrIssueInvoice($this->currentShop($request), (int) $customer->id, [
            'settlement_id'       => isset($validated['settlement_id']) ? (int) $validated['settlement_id'] : null,
            'direction'           => $validated['direction'] ?? 'outbound',
            'invoice_type'        => $validated['invoice_type'],
            'buyer_title'         => $validated['buyer_title'],
            'buyer_tax_no'        => $validated['buyer_tax_no'] ?? '',
            'amount_cents'        => (int) round(((float) $validated['amount_yuan']) * 100),
            'tax_rate'            => $validated['tax_rate'] ?? '6%',
            'invoice_code_number' => $validated['invoice_code_number'] ?? null,
        ]);

        return back()->with('success', '发票记录已登记。');
    }

    public function issueInvoice(Request $request, int $id): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.settlement.view')) {
            return back()->with('error', '当前角色无权开具发票。');
        }

        $validated = $request->validate([
            'invoice_code_number' => ['required', 'string', 'max:64'],
        ]);

        $customer = auth()->guard('merchant')->user();
        $this->tenantService->createOrIssueInvoice($this->currentShop($request), (int) $customer->id, $validated, $id);

        return back()->with('success', '发票号码已回填并标记为已开具。');
    }

    // ── 店铺切换、店铺档案与成员管理写动作 ───────────────────────────────

    public function switchShop(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'integer', 'min:1'],
        ]);

        $customer = auth()->guard('merchant')->user();
        $targetShopId = (int) $validated['shop_id'];
        $context = $this->tenantService->resolveContext((int) $customer->id, $targetShopId);

        if (! $context['active_shop'] || (int) $context['active_shop']->id !== $targetShopId) {
            return back()->with('error', '无权切换到该店铺。');
        }

        $request->session()->put('merchant_active_shop_id', $targetShopId);

        return redirect()->route('shop.merchant.dashboard')
            ->with('success', '已切换至店铺：'.$context['active_shop']->shop_name);
    }

    public function storeShop(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.shop.manage')) {
            return back()->with('error', '当前角色无权新建店铺。');
        }

        $validated = $request->validate([
            'shop_name'        => ['required', 'string', 'max:128'],
            'description'      => ['nullable', 'string', 'max:500'],
            'business_types'   => ['nullable', 'array'],
            'business_types.*' => ['string', 'in:sale_robot,rental_robot,accessory'],
            'timezone'         => ['nullable', 'string', 'max:64'],
        ]);

        $profile = $request->attributes->get('merchant_profile');
        $customer = auth()->guard('merchant')->user();

        $shop = $this->tenantService->createShop($profile, (int) $customer->id, $validated, $request);
        $request->session()->put('merchant_active_shop_id', (int) $shop->id);

        return redirect()->route('shop.merchant.settings')
            ->with('success', '新店铺「'.$shop->shop_name.'」已创建并切换。');
    }

    public function updateCurrentShop(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.shop.manage')) {
            return back()->with('error', '当前角色无权修改店铺档案。');
        }

        $validated = $request->validate([
            'shop_name'        => ['required', 'string', 'max:128'],
            'description'      => ['nullable', 'string', 'max:500'],
            'business_types'   => ['nullable', 'array'],
            'business_types.*' => ['string', 'in:sale_robot,rental_robot,accessory'],
            'timezone'         => ['required', 'string', 'max:64'],
        ]);

        $customer = auth()->guard('merchant')->user();
        $this->tenantService->updateShopProfile($this->currentShop($request), (int) $customer->id, $validated);

        return back()->with('success', '当前店铺档案与展示时区已保存。');
    }

    public function upsertMember(Request $request): RedirectResponse
    {
        $member = $this->currentMember($request);
        if (! $member->hasPermission('merchant.member.manage')) {
            return back()->with('error', '当前角色无权管理店铺成员。');
        }

        $validated = $request->validate([
            'email'       => ['nullable', 'email', 'max:128'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'role'        => ['required', 'string', 'in:owner,admin,catalog_ops,warehouse,finance'],
            'status'      => ['nullable', 'string', 'in:active,disabled'],
        ]);

        try {
            $customer = auth()->guard('merchant')->user();
            $this->tenantService->upsertMember($this->currentShop($request), (int) $customer->id, $validated);

            return back()->with('success', '店铺成员与 RBAC 角色授权已保存。');
        } catch (RuntimeException $e) {
            return back()->with('error', $this->humanizeError($e->getMessage()));
        }
    }

    protected function renderWorkspace(Request $request, string $activeTab): View
    {
        $shop = $this->currentShop($request);
        $allowedTabs = ['overview', 'products', 'inventory', 'orders', 'after_sales', 'settlements', 'settings'];
        $tab = in_array($activeTab, $allowedTabs, true) ? $activeTab : 'overview';

        $metrics = $this->tenantService->dashboardMetrics($shop);
        $products = $this->tenantService->listProducts(
            $shop,
            $request->only(['product_type', 'fulfillment_type', 'review_status', 'shelf_status', 'low_stock', 'keyword']),
            20
        );
        $orders = $this->tenantService->listOrders($shop, $request->only(['fulfillment_type', 'status', 'keyword']), 20);
        $afterSales = $this->tenantService->listAfterSalesTickets($shop, $request->only(['ticket_type', 'status']), 20);
        $settlements = $this->tenantService->listSettlements($shop);
        $invoices = $this->tenantService->listInvoices($shop);
        $warehouses = $this->tenantService->listWarehouses($shop);
        $inventoryLocks = $this->tenantService->listInventoryLocks($shop, null, 20);
        $members = $this->tenantService->listMembers($shop);
        $auditLogs = $this->tenantService->listAuditLogs($shop, 25, $request->only(['action_prefix']));

        return view('shop::merchant.dashboard', [
            'activeTab'      => $tab,
            'metrics'        => $metrics,
            'products'       => $products,
            'orders'         => $orders,
            'afterSales'     => $afterSales,
            'settlements'    => $settlements,
            'invoices'       => $invoices,
            'warehouses'     => $warehouses,
            'inventoryLocks' => $inventoryLocks,
            'members'        => $members,
            'auditLogs'      => $auditLogs,
            'deviceModels'   => $this->loadDeviceModels(),
            'rolesConfig'    => config('portal_backends.roles', []),
        ]);
    }

    protected function loadDeviceModels(): array
    {
        if (! Schema::hasTable('device_model')) {
            return [];
        }

        return DB::table('device_model')
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('model_code')
            ->get(['model_code as code', 'model_name as name', 'brand', 'status as level'])
            ->all();
    }

    protected function currentShop(Request $request): MerchantShop
    {
        return $request->attributes->get('merchant_shop');
    }

    protected function currentMember(Request $request): MerchantMember
    {
        return $request->attributes->get('merchant_member');
    }

    protected function humanizeError(string $raw): string
    {
        [$prefix, $detail] = array_pad(explode(':', $raw, 2), 2, $raw);

        return $detail ?: $raw;
    }
}
