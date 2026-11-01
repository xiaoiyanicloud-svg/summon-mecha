<?php

namespace Webkul\Shop\Services\Merchant;

use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Webkul\Category\Models\Category;
use Webkul\Customer\Models\Customer;
use Webkul\Product\Models\ProductProxy;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Shop\Models\AccessoryFitment;
use Webkul\Shop\Models\AccessoryProfile;
use Webkul\Shop\Models\DeviceModel;
use Webkul\Shop\Models\MerchantAfterSalesTicket;
use Webkul\Shop\Models\MerchantInventoryLock;
use Webkul\Shop\Models\MerchantInvoice;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantOrder;
use Webkul\Shop\Models\MerchantProductExt;
use Webkul\Shop\Models\MerchantProductSku;
use Webkul\Shop\Models\MerchantProfile;
use Webkul\Shop\Models\MerchantRentalPlan;
use Webkul\Shop\Models\MerchantSettlement;
use Webkul\Shop\Models\MerchantSettlementItem;
use Webkul\Shop\Models\MerchantShop;
use Webkul\Shop\Models\MerchantWarehouse;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\Seller;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 商家后台多租户核心域服务（一二三四级子功能全链路闭环）。
 *
 * 覆盖：
 * 1. 入驻、多店铺切换、店铺档案配置、成员与 RBAC 角色授权管理；
 * 2. 商品 SPU + 多规格 SKU + 租赁计划阶梯价 + 押金规则 + 媒体/技术参数 + 审核与上下架状态机；
 * 3. 多仓库管理 + 售卖/租赁双轨库存 + 维保锁定与释放 + 库存流水台账（行锁+乐观锁防超卖）；
 * 4. 售卖订单与租赁订单全状态机（电子合同 -> 发货绑 SN -> 归还登记 -> 验机定损 -> 押金结算完结）；
 * 5. 售后退换修与设备维保工单（联动维保库存占用与释放）；
 * 6. 结算单生成/确认（含结算明细穿透）与发票开具管理；
 * 7. 操作审计日志多维筛选与时区感知指标看板。
 */
class MerchantTenantService
{
    public const SESSION_ACTIVE_SHOP_KEY = 'merchant_active_shop_id';

    public function __construct(
        protected ProductRepository $products,
        protected OperationLogger $ops,
    ) {}

    public function assertValidSellerId(?int $sellerId): int
    {
        if ($sellerId === null || $sellerId <= 0) {
            throw new InvalidArgumentException('非法的商家租户上下文（seller_id 不能为空）。');
        }

        return $sellerId;
    }

    protected function resolveSellerId(MerchantShop|int $shopOrSellerId): int
    {
        $raw = $shopOrSellerId instanceof MerchantShop ? (int) $shopOrSellerId->seller_id : (int) $shopOrSellerId;

        return $this->assertValidSellerId($raw);
    }

    protected function resolveCustomerId(Customer|int $customer): int
    {
        return $customer instanceof Customer ? (int) $customer->id : (int) $customer;
    }

    public function formatTimeForShop(?Carbon $utcTime, ?string $timezone = 'Asia/Shanghai'): ?string
    {
        if (! $utcTime) {
            return null;
        }

        try {
            return $utcTime->copy()->setTimezone($timezone ?: 'Asia/Shanghai')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $utcTime->toDateTimeString();
        }
    }

    // =========================================================================
    // 1. 入驻、多店铺、店铺设置与 RBAC 成员闭环
    // =========================================================================

    public function onboard(Customer|int $customer, array $data, mixed $request = null): array
    {
        $customerId = $this->resolveCustomerId($customer);
        $customerModel = $customer instanceof Customer ? $customer : Customer::query()->findOrFail($customerId);

        return DB::transaction(function () use ($customerModel, $customerId, $data) {
            $profile = MerchantProfile::query()
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            $shopName = trim((string) ($data['shop_name'] ?? $data['company_name'] ?? ($customerModel->name.'的机器人店铺')));
            if ($shopName === '') {
                $shopName = '商家店铺 '.$customerId;
            }

            $rawScopes = (array) ($data['business_types'] ?? $data['business_scopes'] ?? ['sale_robot', 'rental_robot', 'accessory']);
            $scopes = array_values(array_intersect(
                $rawScopes,
                [MerchantShop::SCOPE_SALE_ROBOT, MerchantShop::SCOPE_RENTAL_ROBOT, MerchantShop::SCOPE_ACCESSORY]
            )) ?: [MerchantShop::SCOPE_SALE_ROBOT, MerchantShop::SCOPE_RENTAL_ROBOT, MerchantShop::SCOPE_ACCESSORY];

            if (! $profile) {
                $seller = Seller::create([
                    'code'              => $this->generateSellerCode(),
                    'name'              => $shopName,
                    'status'            => 'active',
                    'owner_customer_id' => $customerId,
                ]);

                $profile = MerchantProfile::create([
                    'customer_id'         => $customerId,
                    'default_seller_id'   => $seller->id,
                    'invite_code'         => ! empty($data['invite_code']) ? strtoupper(trim((string) $data['invite_code'])) : null,
                    'merchant_type'       => in_array(($data['merchant_type'] ?? 'company'), ['company', 'personal'], true) ? ($data['merchant_type'] ?? 'company') : 'company',
                    'company_name'        => trim((string) ($data['company_name'] ?? $shopName)),
                    'credit_code'         => ! empty($data['credit_code']) ? strtoupper(trim((string) $data['credit_code'])) : null,
                    'contact_name'        => trim((string) ($data['contact_name'] ?? $customerModel->name ?? '')),
                    'contact_phone'       => trim((string) ($data['contact_phone'] ?? $customerModel->phone ?? '')),
                    'realname_status'     => MerchantProfile::REALNAME_APPROVED,
                    'status'              => MerchantProfile::STATUS_ACTIVE,
                    'timezone'            => (string) ($data['timezone'] ?? 'Asia/Shanghai'),
                    'agreement_signed_at' => now(),
                ]);

                $shop = MerchantShop::create([
                    'seller_id'           => $seller->id,
                    'merchant_profile_id' => $profile->id,
                    'code'                => $this->generateShopCode(),
                    'name'                => $shopName,
                    'description'         => trim((string) ($data['description'] ?? '')),
                    'business_scopes'     => $scopes,
                    'timezone'            => $profile->timezone,
                    'status'              => MerchantShop::STATUS_ACTIVE,
                ]);

                $member = MerchantMember::create([
                    'shop_id'     => $shop->id,
                    'seller_id'   => $seller->id,
                    'customer_id' => $customerId,
                    'role'        => MerchantMember::ROLE_OWNER,
                    'status'      => MerchantMember::STATUS_ACTIVE,
                ]);

                // 自动初始化默认主仓库
                if (Schema::hasTable('merchant_warehouses')) {
                    MerchantWarehouse::create([
                        'seller_id'     => $seller->id,
                        'shop_id'       => $shop->id,
                        'code'          => 'WH-MAIN-'.$seller->id,
                        'name'          => $shopName.'·默认主仓',
                        'region'        => '华南/深圳',
                        'address'       => '默认履约中心仓',
                        'contact_name'  => $profile->contact_name,
                        'contact_phone' => $profile->contact_phone,
                        'is_default'    => true,
                        'status'        => 'active',
                    ]);
                }

                $this->ops->log(
                    OperationLog::ACTOR_CUSTOMER,
                    $customerId,
                    'merchant.onboard',
                    'merchant_shop',
                    $shop->id,
                    [
                        'seller_id'     => $seller->id,
                        'shop_code'     => $shop->code,
                        'merchant_type' => $profile->merchant_type,
                    ]
                );

                return ['profile' => $profile, 'shop' => $shop, 'member' => $member];
            }

            $firstMember = MerchantMember::query()
                ->where('customer_id', $customerId)
                ->where('status', MerchantMember::STATUS_ACTIVE)
                ->first();

            $shop = $firstMember
                ? MerchantShop::query()->find($firstMember->shop_id)
                : $profile->shops()->first();

            return ['profile' => $profile, 'shop' => $shop, 'member' => $firstMember];
        });
    }

    public function createShop(MerchantProfile $profile, Customer|int $customer, array $data, mixed $request = null): MerchantShop
    {
        $customerId = $this->resolveCustomerId($customer);

        return DB::transaction(function () use ($customerId, $profile, $data) {
            $shopName = trim((string) ($data['shop_name'] ?? $data['name'] ?? '新店铺'));
            $rawScopes = (array) ($data['business_types'] ?? $data['business_scopes'] ?? ['sale_robot', 'rental_robot', 'accessory']);
            $scopes = array_values(array_intersect(
                $rawScopes,
                [MerchantShop::SCOPE_SALE_ROBOT, MerchantShop::SCOPE_RENTAL_ROBOT, MerchantShop::SCOPE_ACCESSORY]
            )) ?: [MerchantShop::SCOPE_SALE_ROBOT];

            $seller = Seller::create([
                'code'              => $this->generateSellerCode(),
                'name'              => $shopName,
                'status'            => 'active',
                'owner_customer_id' => $customerId,
            ]);

            $shop = MerchantShop::create([
                'seller_id'           => $seller->id,
                'merchant_profile_id' => $profile->id,
                'code'                => $this->generateShopCode(),
                'name'                => $shopName,
                'description'         => trim((string) ($data['description'] ?? '')),
                'business_scopes'     => $scopes,
                'timezone'            => (string) ($data['timezone'] ?? $profile->timezone ?? 'Asia/Shanghai'),
                'status'              => MerchantShop::STATUS_ACTIVE,
            ]);

            MerchantMember::create([
                'shop_id'     => $shop->id,
                'seller_id'   => $seller->id,
                'customer_id' => $customerId,
                'role'        => MerchantMember::ROLE_OWNER,
                'status'      => MerchantMember::STATUS_ACTIVE,
            ]);

            if (Schema::hasTable('merchant_warehouses')) {
                MerchantWarehouse::create([
                    'seller_id'     => $seller->id,
                    'shop_id'       => $shop->id,
                    'code'          => 'WH-MAIN-'.$seller->id,
                    'name'          => $shopName.'·默认主仓',
                    'region'        => '默认片区',
                    'address'       => '默认履约中心仓',
                    'contact_name'  => $profile->contact_name,
                    'contact_phone' => $profile->contact_phone,
                    'is_default'    => true,
                    'status'        => 'active',
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $customerId,
                'merchant.shop.create',
                'merchant_shop',
                $shop->id,
                ['seller_id' => $seller->id, 'name' => $shop->name]
            );

            return $shop;
        });
    }

    public function updateShopProfile(MerchantShop $shop, Customer|int $actor, array $data): MerchantShop
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);

        if (isset($data['shop_name']) || isset($data['name'])) {
            $shop->name = trim((string) ($data['shop_name'] ?? $data['name']));
            Seller::query()->where('id', $sellerId)->update(['name' => $shop->name]);
        }
        if (array_key_exists('description', $data)) {
            $shop->description = trim((string) $data['description']);
        }
        if (! empty($data['timezone'])) {
            $shop->timezone = trim((string) $data['timezone']);
        }
        if (! empty($data['business_types']) || ! empty($data['business_scopes'])) {
            $rawScopes = (array) ($data['business_types'] ?? $data['business_scopes']);
            $scopes = array_values(array_intersect(
                $rawScopes,
                [MerchantShop::SCOPE_SALE_ROBOT, MerchantShop::SCOPE_RENTAL_ROBOT, MerchantShop::SCOPE_ACCESSORY]
            ));
            if ($scopes !== []) {
                $shop->business_scopes = $scopes;
            }
        }

        $shop->save();

        $this->ops->log(
            OperationLog::ACTOR_CUSTOMER,
            $actorId,
            'merchant.shop.update',
            'merchant_shop',
            $shop->id,
            ['seller_id' => $sellerId, 'name' => $shop->name, 'timezone' => $shop->timezone]
        );

        return $shop;
    }

    public function listMembers(MerchantShop $shop): array
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);

        return MerchantMember::query()
            ->with('customer')
            ->where('shop_id', $shop->id)
            ->where('seller_id', $sellerId)
            ->orderBy('id')
            ->get()
            ->map(function (MerchantMember $m) use ($shop) {
                $cName = trim(($m->customer?->first_name ?? '').' '.($m->customer?->last_name ?? '')) ?: ('用户 #'.$m->customer_id);
                $cEmail = (string) ($m->customer?->email ?? '');
                $rLabel = (string) (config('portal_backends.roles.'.$m->role.'.name') ?? config('portal_backends.roles.'.$m->role.'.label', $m->role));

                return [
                    'id'                   => (int) $m->id,
                    'customer_id'          => (int) $m->customer_id,
                    'name'                 => $cName,
                    'customer_name'        => $cName,
                    'email'                => $cEmail,
                    'customer_email'       => $cEmail,
                    'role'                 => (string) $m->role,
                    'role_code'            => (string) $m->role,
                    'role_label'           => $rLabel,
                    'role_name'            => $rLabel,
                    'custom_permissions'   => $m->permissions ?? [],
                    'resolved_permissions' => $m->resolvedPermissions(),
                    'status'               => (string) $m->status,
                    'created_at'           => $this->formatTimeForShop($m->created_at, $shop->timezone),
                ];
            })
            ->all();
    }

    public function upsertMember(MerchantShop $shop, Customer|int $actor, array $data): MerchantMember
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);

        $targetCustomer = null;
        if (! empty($data['customer_id'])) {
            $targetCustomer = Customer::query()->find((int) $data['customer_id']);
        } elseif (! empty($data['email'])) {
            $targetCustomer = Customer::query()->where('email', trim((string) $data['email']))->first();
        }

        if (! $targetCustomer) {
            throw new RuntimeException('VALIDATION_ERROR:未找到对应的平台客户账号（请核对邮箱或客户 ID）。', 422);
        }

        $role = (string) ($data['role'] ?? MerchantMember::ROLE_CATALOG_OPS);
        if (! array_key_exists($role, (array) config('portal_backends.roles', []))) {
            throw new RuntimeException('VALIDATION_ERROR:不支持的角色编码：'.$role, 422);
        }

        $status = in_array(($data['status'] ?? 'active'), ['active', 'disabled'], true)
            ? (string) ($data['status'] ?? 'active')
            : 'active';

        $customPerms = isset($data['permissions']) && is_array($data['permissions'])
            ? array_values(array_filter($data['permissions'], 'is_string'))
            : null;

        $member = MerchantMember::query()->updateOrCreate(
            [
                'shop_id'     => $shop->id,
                'customer_id' => $targetCustomer->id,
            ],
            [
                'seller_id'   => $sellerId,
                'role'        => $role,
                'permissions' => $customPerms,
                'status'      => $status,
            ]
        );

        $this->ops->log(
            OperationLog::ACTOR_CUSTOMER,
            $actorId,
            'merchant.member.upsert',
            'merchant_member',
            $member->id,
            [
                'seller_id'          => $sellerId,
                'target_customer_id' => $targetCustomer->id,
                'role'               => $role,
                'status'             => $status,
            ]
        );

        return $member;
    }

    public function resolveContext(Customer|int $customer, ?int $preferredShopId = null): array
    {
        $customerId = $this->resolveCustomerId($customer);
        $profile = MerchantProfile::query()->where('customer_id', $customerId)->first();

        $members = MerchantMember::query()
            ->with('shop')
            ->where('customer_id', $customerId)
            ->where('status', MerchantMember::STATUS_ACTIVE)
            ->get()
            ->filter(fn (MerchantMember $m) => $m->shop && $m->shop->isActive() && $m->shop->seller_id > 0)
            ->values();

        $shops = $members->map(fn (MerchantMember $m) => $m->shop)->values();

        $activeMember = null;
        if ($preferredShopId) {
            $activeMember = $members->firstWhere('shop_id', $preferredShopId);
        } else {
            if ($profile && $profile->default_seller_id) {
                $activeMember = $members->firstWhere('seller_id', $profile->default_seller_id);
            }
            if (! $activeMember) {
                $activeMember = $members->first();
            }
        }

        return [
            'profile'       => $profile,
            'shops'         => $shops,
            'active_shop'   => $activeMember?->shop,
            'active_member' => $activeMember,
            'permissions'   => $activeMember ? $activeMember->resolvedPermissions() : [],
        ];
    }

    // =========================================================================
    // 2. 商品中心（SPU + 多规格 SKU + 租赁计划阶梯 + 押金规则 + 审核与上下架闭环）
    // =========================================================================

    public function listProducts(MerchantShop|int $shopOrSellerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $timezone = $shopOrSellerId instanceof MerchantShop ? $shopOrSellerId->timezone : 'Asia/Shanghai';

        $query = MerchantProductExt::query()
            ->with(['product', 'skus', 'rentalPlans'])
            ->where('seller_id', $sellerId)
            ->whereHas('product', fn ($q) => $q->where('seller_id', $sellerId));

        if (! empty($filters['product_type'])) {
            $query->where('product_type', (string) $filters['product_type']);
        }
        if (! empty($filters['fulfillment_type'])) {
            $query->where('fulfillment_type', (string) $filters['fulfillment_type']);
        }
        if (! empty($filters['review_status'])) {
            $query->where('review_status', (string) $filters['review_status']);
        }
        if (! empty($filters['shelf_status'])) {
            $query->where('shelf_status', (string) $filters['shelf_status']);
        }
        if (! empty($filters['low_stock'])) {
            $query->whereRaw('(sellable_stock + rentable_stock) <= safety_stock');
        }

        $kw = trim((string) ($filters['keyword'] ?? $filters['q'] ?? ''));
        if ($kw !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw);
            $matchingIds = DB::table('product_flat')
                ->where('name', 'like', '%'.$escaped.'%')
                ->orWhere('sku', 'like', '%'.$escaped.'%')
                ->pluck('product_id')
                ->all();
            $query->whereIn('product_id', $matchingIds ?: [0]);
        }

        $paginator = $query->orderByDesc('id')->paginate(max(1, min(100, $perPage)));
        $paginator->getCollection()->transform(fn (MerchantProductExt $ext) => $this->formatProductItem($ext, $timezone));

        return $paginator;
    }

    public function findOwnedProduct(MerchantShop|int $shopOrSellerId, int $productId): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);

        $ext = MerchantProductExt::query()
            ->with(['product', 'skus', 'rentalPlans'])
            ->where('seller_id', $sellerId)
            ->where('product_id', $productId)
            ->first();

        if (! $ext || ! $ext->product || (int) $ext->product->seller_id !== $sellerId) {
            throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
        }

        return $ext;
    }

    public function formatProductItem(MerchantProductExt $ext, ?string $timezone = 'Asia/Shanghai'): array
    {
        $flat = DB::table('product_flat')
            ->where('product_id', $ext->product_id)
            ->orderByDesc('id')
            ->first();

        $priceCents = (int) $ext->price_cents;
        $dailyRentCents = (int) $ext->daily_rent_cents;
        $depositCents = (int) $ext->deposit_cents;
        $safetyStock = (int) ($ext->safety_stock ?? 2);

        $skus = Schema::hasTable('merchant_product_skus')
            ? ($ext->relationLoaded('skus') ? $ext->skus : $ext->skus()->get())
            : collect();

        $rentalPlans = Schema::hasTable('merchant_rental_plans')
            ? ($ext->relationLoaded('rentalPlans') ? $ext->rentalPlans : $ext->rentalPlans()->get())
            : collect();

        return [
            'id'                => (int) $ext->id,
            'product_id'        => (int) $ext->product_id,
            'seller_id'         => (int) $ext->seller_id,
            'shop_id'           => (int) $ext->shop_id,
            'sku'               => (string) ($ext->product?->sku ?? $flat?->sku ?? ''),
            'name'              => (string) ($flat?->name ?? $ext->product?->sku ?? ''),
            'short_description' => (string) ($flat?->short_description ?? ''),
            'description'       => (string) ($flat?->description ?? ''),
            'product_type'      => (string) $ext->product_type,
            'fulfillment_type'  => (string) $ext->fulfillment_type,
            'price_cents'       => $priceCents,
            'price_yuan'        => number_format($priceCents / 100, 2, '.', ''),
            'daily_rent_cents'  => $dailyRentCents,
            'daily_rent_yuan'   => number_format($dailyRentCents / 100, 2, '.', ''),
            'deposit_cents'     => $depositCents,
            'deposit_yuan'      => number_format($depositCents / 100, 2, '.', ''),
            'min_rent_days'     => (int) $ext->min_rent_days,
            'sellable_stock'    => (int) $ext->sellable_stock,
            'rentable_stock'    => (int) $ext->rentable_stock,
            'rented_locked_qty' => (int) $ext->locked_stock,
            'maintenance_qty'   => (int) $ext->maintenance_stock,
            'safety_stock'      => $safetyStock,
            'is_low_stock'      => ((int) $ext->sellable_stock + (int) $ext->rentable_stock) <= $safetyStock,
            'compatible_models' => $ext->compatible_models ?? [],
            'param_specs'       => $ext->param_specs ?? [],
            'media_items'       => $ext->media_items ?? [],
            'review_status'     => (string) $ext->review_status,
            'shelf_status'      => (string) $ext->shelf_status,
            'reject_reason'     => $ext->reject_reason,
            'version'           => (int) $ext->version,
            'skus'              => $skus->map(fn (MerchantProductSku $s) => [
                'id'                => (int) $s->id,
                'sku_code'          => (string) $s->sku_code,
                'name'              => (string) $s->name,
                'spec_json'         => $s->spec_json ?? [],
                'price_cents'       => (int) $s->price_cents,
                'price_yuan'        => number_format($s->price_cents / 100, 2, '.', ''),
                'daily_rent_cents'  => (int) $s->daily_rent_cents,
                'daily_rent_yuan'   => number_format($s->daily_rent_cents / 100, 2, '.', ''),
                'deposit_cents'     => (int) $s->deposit_cents,
                'deposit_yuan'      => number_format($s->deposit_cents / 100, 2, '.', ''),
                'sellable_stock'    => (int) $s->sellable_stock,
                'rentable_stock'    => (int) $s->rentable_stock,
                'locked_stock'      => (int) $s->locked_stock,
                'maintenance_stock' => (int) $s->maintenance_stock,
                'safety_stock'      => (int) $s->safety_stock,
                'status'            => (string) $s->status,
                'version'           => (int) $s->version,
            ])->values()->all(),
            'rental_plans'      => $formattedPlans = $rentalPlans->map(fn (MerchantRentalPlan $rp) => [
                'id'                     => (int) $rp->id,
                'plan_code'              => (string) $rp->plan_code,
                'name'                   => (string) $rp->name,
                'period_unit'            => (string) $rp->period_unit,
                'min_days'               => (int) $rp->min_days,
                'max_days'               => (int) $rp->max_days,
                'base_price_cents'       => (int) $rp->base_price_cents,
                'base_price_yuan'        => number_format($rp->base_price_cents / 100, 2, '.', ''),
                'price_tiers'            => $rp->price_tiers ?? [],
                'deposit_rule'           => $rp->deposit_rule ?? [],
                'contract_template_code' => (string) $rp->contract_template_code,
                'enabled'                => (bool) $rp->enabled,
                'version'                => (int) $rp->version,
            ])->values()->all(),
            'structured_plans'  => $formattedPlans,
            'updated_at'        => $this->formatTimeForShop($ext->updated_at, $timezone),
            'updated_at_local'  => $this->formatTimeForShop($ext->updated_at, $timezone),
        ];
    }

    public function validateCompatibleModels(array $modelCodes): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($c) => trim((string) $c),
            $modelCodes
        ))));

        if ($normalized === []) {
            return ['ok' => true, 'code' => null, 'message' => null, 'normalized' => []];
        }

        $models = DeviceModel::query()
            ->whereIn('model_code', $normalized)
            ->get()
            ->keyBy('model_code');

        foreach ($normalized as $code) {
            $dm = $models->get($code);
            if (! $dm) {
                return [
                    'ok'         => false,
                    'code'       => 'COMPATIBLE_MODEL_UNKNOWN',
                    'message'    => "机型编码 {$code} 不在平台标准机型库中。",
                    'normalized' => $normalized,
                ];
            }

            if ($dm->status === DeviceModel::STATUS_DISPLAY_ONLY) {
                return [
                    'ok'         => false,
                    'code'       => 'MODEL_L3_DISPLAY_ONLY',
                    'message'    => "机型 {$dm->model_name}（{$code}）属于 L3 仅展示级别，平台红线禁止将其关联为可售/可租交付商品。",
                    'normalized' => $normalized,
                ];
            }
        }

        return ['ok' => true, 'code' => null, 'message' => null, 'normalized' => $normalized];
    }

    public function createProduct(MerchantShop $shop, Customer|int $actor, array $data, mixed $request = null): MerchantProductExt
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);

        $productType = (string) ($data['product_type'] ?? MerchantProductExt::TYPE_SALE_ROBOT);
        if (! in_array($productType, MerchantProductExt::PRODUCT_TYPES, true)) {
            throw new RuntimeException('VALIDATION_ERROR:不支持的商品类型：'.$productType, 422);
        }

        $compatCheck = $this->validateCompatibleModels((array) ($data['compatible_models'] ?? []));
        if (! $compatCheck['ok']) {
            throw new RuntimeException($compatCheck['code'].':'.$compatCheck['message'], 422);
        }

        $priceCents = max(0, (int) ($data['price_cents'] ?? 0));
        $dailyRentCents = max(0, (int) ($data['daily_rent_cents'] ?? $priceCents));
        $depositCents = max(0, (int) ($data['deposit_cents'] ?? 0));
        $effectiveYuan = $productType === MerchantProductExt::TYPE_RENTAL_ROBOT
            ? round($dailyRentCents / 100, 2)
            : round($priceCents / 100, 2);

        $fulfillmentType = ! empty($data['fulfillment_type'])
            ? (string) $data['fulfillment_type']
            : ($productType === MerchantProductExt::TYPE_RENTAL_ROBOT
                ? MerchantProductExt::FULFILLMENT_RENTAL
                : MerchantProductExt::FULFILLMENT_SALE);

        return DB::transaction(function () use (
            $shop,
            $sellerId,
            $actorId,
            $data,
            $productType,
            $fulfillmentType,
            $compatCheck,
            $priceCents,
            $dailyRentCents,
            $depositCents,
            $effectiveYuan
        ) {
            $sku = ! empty($data['sku'])
                ? strtoupper(trim((string) $data['sku']))
                : $this->generateSku($productType);

            Event::dispatch('catalog.product.create.before');

            $product = $this->products->create([
                'type'                => 'simple',
                'attribute_family_id' => $this->defaultFamilyId(),
                'sku'                 => $sku,
            ]);

            $product->seller_id = $sellerId;
            $product->save();

            Event::dispatch('catalog.product.create.after', $product);

            $categoryId = $this->resolveCategoryIdForType($productType);
            $sellableStock = max(0, (int) ($data['sellable_stock'] ?? 0));
            $rentableStock = max(0, (int) ($data['rentable_stock'] ?? 0));
            $safetyStock = max(0, (int) ($data['safety_stock'] ?? 2));
            $bagistoStock = $productType === MerchantProductExt::TYPE_RENTAL_ROBOT ? $rentableStock : $sellableStock;

            Event::dispatch('catalog.product.update.before', $product->id);

            $updatePayload = [
                'channel'              => core()->getDefaultChannelCode(),
                'locale'               => core()->getDefaultLocaleCodeFromDefaultChannel(),
                'name'                 => trim((string) $data['name']),
                'url_key'              => Str::slug($sku.'-'.Str::random(4)),
                'price'                => $effectiveYuan,
                'short_description'    => trim((string) ($data['short_description'] ?? $data['name'])),
                'description'          => trim((string) ($data['description'] ?? $data['name'])),
                'status'               => 0,
                'visible_individually' => 1,
                'guest_checkout'       => 1,
                'manage_stock'         => 1,
                'inventories'          => [$this->defaultInventorySourceId() => $bagistoStock],
            ];

            if ($categoryId) {
                $updatePayload['categories'] = [$categoryId];
            }

            $product = $this->products->update($updatePayload, $product->id);

            Event::dispatch('catalog.product.update.after', $product);

            if ($productType === MerchantProductExt::TYPE_ACCESSORY) {
                $this->syncAccessoryMetadata($product->id, $data, $compatCheck['normalized']);
            }

            $ext = MerchantProductExt::create([
                'product_id'            => $product->id,
                'seller_id'             => $sellerId,
                'shop_id'               => $shop->id,
                'product_type'          => $productType,
                'fulfillment_type'      => $fulfillmentType,
                'compatible_models'     => $compatCheck['normalized'],
                'param_specs'           => is_array($data['param_specs'] ?? null) ? $data['param_specs'] : [],
                'media_items'           => is_array($data['media_items'] ?? null) ? $data['media_items'] : [],
                'rental_plans_snapshot' => is_array($data['rental_plans'] ?? null) ? $data['rental_plans'] : [],
                'deposit_rule_snapshot' => is_array($data['deposit_rule'] ?? null) ? $data['deposit_rule'] : [],
                'price_cents'           => $priceCents,
                'daily_rent_cents'      => $dailyRentCents,
                'deposit_cents'         => $depositCents,
                'min_rent_days'         => max(1, (int) ($data['min_rent_days'] ?? 1)),
                'sellable_stock'        => $sellableStock,
                'rentable_stock'        => $rentableStock,
                'locked_stock'          => 0,
                'maintenance_stock'     => 0,
                'safety_stock'          => $safetyStock,
                'review_status'         => MerchantProductExt::REVIEW_DRAFT,
                'shelf_status'          => MerchantProductExt::SHELF_OFF,
                'version'               => 1,
            ]);

            // 自动初始化默认标准规格 SKU
            if (Schema::hasTable('merchant_product_skus')) {
                MerchantProductSku::create([
                    'product_id'        => $product->id,
                    'seller_id'         => $sellerId,
                    'shop_id'           => $shop->id,
                    'sku_code'          => $sku.'-STD',
                    'name'              => '标准配置版',
                    'spec_json'         => ['edition' => '标准版'],
                    'price_cents'       => $priceCents,
                    'daily_rent_cents'  => $dailyRentCents,
                    'deposit_cents'     => $depositCents,
                    'sellable_stock'    => $sellableStock,
                    'rentable_stock'    => $rentableStock,
                    'locked_stock'      => 0,
                    'maintenance_stock' => 0,
                    'safety_stock'      => $safetyStock,
                    'status'            => 'active',
                    'version'           => 1,
                ]);
            }

            // 若为租赁商品或传入了 rental_plans，自动落库租赁方案表
            if (Schema::hasTable('merchant_rental_plans') && ($productType === MerchantProductExt::TYPE_RENTAL_ROBOT || ! empty($data['rental_plans']))) {
                $rawPlans = ! empty($data['rental_plans']) && is_array($data['rental_plans'])
                    ? $data['rental_plans']
                    : [
                        ['period' => 'day', 'days' => 1, 'price_cents' => $dailyRentCents],
                    ];

                foreach ($rawPlans as $idx => $rp) {
                    $periodUnit = (string) ($rp['period_unit'] ?? $rp['period'] ?? 'day');
                    $days = max(1, (int) ($rp['min_days'] ?? $rp['days'] ?? 1));
                    $baseCents = max(0, (int) ($rp['base_price_cents'] ?? $rp['price_cents'] ?? $dailyRentCents));

                    MerchantRentalPlan::create([
                        'product_id'       => $product->id,
                        'seller_id'        => $sellerId,
                        'shop_id'          => $shop->id,
                        'plan_code'        => $sku.'-PLAN-'.($idx + 1),
                        'name'             => (string) ($rp['name'] ?? match ($periodUnit) {
                            'week'    => '周租特惠计划',
                            'month'   => '月租长租计划',
                            'quarter' => '季租企业计划',
                            default   => '标准日租计划',
                        }),
                        'period_unit'      => $periodUnit,
                        'min_days'         => $days,
                        'max_days'         => max($days, (int) ($rp['max_days'] ?? 365)),
                        'base_price_cents' => $baseCents,
                        'price_tiers'      => is_array($rp['price_tiers'] ?? null) ? $rp['price_tiers'] : [],
                        'deposit_rule'     => is_array($data['deposit_rule'] ?? null) ? $data['deposit_rule'] : [
                            'mode'                => 'full',
                            'deposit_cents'       => $depositCents,
                            'overdue_daily_cents' => (int) round($dailyRentCents * 1.5),
                        ],
                        'enabled'          => true,
                        'version'          => 1,
                    ]);
                }
            }

            // 记录初始建品库存流水
            if (Schema::hasTable('merchant_inventory_locks') && ($sellableStock > 0 || $rentableStock > 0)) {
                MerchantInventoryLock::create([
                    'seller_id'         => $sellerId,
                    'shop_id'           => $shop->id,
                    'product_id'        => $product->id,
                    'action_type'       => 'restock',
                    'qty_change'        => $sellableStock + $rentableStock,
                    'sellable_after'    => $sellableStock,
                    'rentable_after'    => $rentableStock,
                    'locked_after'      => 0,
                    'maintenance_after' => 0,
                    'reference_no'      => $sku,
                    'reason'            => '创建商品初始库存建账',
                    'actor_id'          => $actorId,
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.product.create',
                'product',
                $product->id,
                [
                    'seller_id'    => $sellerId,
                    'shop_id'      => $shop->id,
                    'sku'          => $sku,
                    'product_type' => $productType,
                    'price_cents'  => $priceCents,
                ]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    public function updateProduct(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $productId, array $data, mixed $request = null): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $productId, $actorId, $data) {
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            $expectedVersion = $data['version'] ?? $data['expected_version'] ?? null;
            if ($expectedVersion !== null && (int) $expectedVersion !== (int) $ext->version) {
                throw new RuntimeException('VERSION_CONFLICT:商品数据已被其他成员修改，请刷新后重试（乐观锁版本冲突）。', 409);
            }

            if (array_key_exists('compatible_models', $data)) {
                $compatCheck = $this->validateCompatibleModels((array) $data['compatible_models']);
                if (! $compatCheck['ok']) {
                    throw new RuntimeException($compatCheck['code'].':'.$compatCheck['message'], 422);
                }
                $ext->compatible_models = $compatCheck['normalized'];
            }

            if (isset($data['price_cents'])) {
                $ext->price_cents = max(0, (int) $data['price_cents']);
            }
            if (isset($data['daily_rent_cents'])) {
                $ext->daily_rent_cents = max(0, (int) $data['daily_rent_cents']);
            }
            if (isset($data['deposit_cents'])) {
                $ext->deposit_cents = max(0, (int) $data['deposit_cents']);
            }
            if (isset($data['min_rent_days'])) {
                $ext->min_rent_days = max(1, (int) $data['min_rent_days']);
            }
            if (isset($data['safety_stock'])) {
                $ext->safety_stock = max(0, (int) $data['safety_stock']);
            }
            if (array_key_exists('param_specs', $data) && is_array($data['param_specs'])) {
                $ext->param_specs = $data['param_specs'];
            }
            if (array_key_exists('media_items', $data) && is_array($data['media_items'])) {
                $ext->media_items = $data['media_items'];
            }

            $effectiveYuan = $ext->product_type === MerchantProductExt::TYPE_RENTAL_ROBOT
                ? round($ext->daily_rent_cents / 100, 2)
                : round($ext->price_cents / 100, 2);

            if (isset($data['name']) || isset($data['short_description']) || isset($data['description']) || isset($data['price_cents']) || isset($data['daily_rent_cents'])) {
                DB::table('product_flat')
                    ->where('product_id', $productId)
                    ->update(array_filter([
                        'name'              => isset($data['name']) ? trim((string) $data['name']) : null,
                        'short_description' => isset($data['short_description']) ? trim((string) $data['short_description']) : null,
                        'description'       => isset($data['description']) ? trim((string) $data['description']) : null,
                        'price'             => $effectiveYuan,
                        'updated_at'        => now(),
                    ], static fn ($v) => $v !== null));
            }

            if ($ext->review_status === MerchantProductExt::REVIEW_REJECTED) {
                $ext->review_status = MerchantProductExt::REVIEW_DRAFT;
                $ext->reject_reason = null;
            }

            $ext->version = (int) $ext->version + 1;
            $ext->save();

            if ($ext->product_type === MerchantProductExt::TYPE_ACCESSORY && array_key_exists('compatible_models', $data)) {
                $this->syncAccessoryMetadata($productId, $data, (array) $ext->compatible_models);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.product.update',
                'product',
                $productId,
                ['seller_id' => $sellerId, 'version' => $ext->version]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    /**
     * L3 多规格 SKU 列表与新增/编辑闭环
     */
    public function listSkus(MerchantShop|int $shopOrSellerId, ?int $productId = null): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);

        $query = MerchantProductSku::query()->where('seller_id', $sellerId);
        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->orderBy('product_id')->orderBy('id')->get()->all();
    }

    public function upsertSku(MerchantShop $shop, Customer|int $actor, array $data, ?int $skuId = null): MerchantProductSku
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);
        $productId = (int) ($data['product_id'] ?? 0);

        $ext = $this->findOwnedProduct($shop, $productId);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $ext, $data, $skuId) {
            if ($skuId) {
                $sku = MerchantProductSku::query()
                    ->where('seller_id', $sellerId)
                    ->where('id', $skuId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (isset($data['version']) && (int) $data['version'] !== (int) $sku->version) {
                    throw new RuntimeException('VERSION_CONFLICT:SKU 数据已被并发修改，请刷新重试。', 409);
                }

                $sku->fill([
                    'name'             => isset($data['name']) ? trim((string) $data['name']) : $sku->name,
                    'spec_json'        => isset($data['spec_json']) && is_array($data['spec_json']) ? $data['spec_json'] : $sku->spec_json,
                    'price_cents'      => isset($data['price_cents']) ? max(0, (int) $data['price_cents']) : $sku->price_cents,
                    'daily_rent_cents' => isset($data['daily_rent_cents']) ? max(0, (int) $data['daily_rent_cents']) : $sku->daily_rent_cents,
                    'deposit_cents'    => isset($data['deposit_cents']) ? max(0, (int) $data['deposit_cents']) : $sku->deposit_cents,
                    'sellable_stock'   => isset($data['sellable_stock']) ? max(0, (int) $data['sellable_stock']) : $sku->sellable_stock,
                    'rentable_stock'   => isset($data['rentable_stock']) ? max(0, (int) $data['rentable_stock']) : $sku->rentable_stock,
                    'safety_stock'     => isset($data['safety_stock']) ? max(0, (int) $data['safety_stock']) : $sku->safety_stock,
                    'status'           => isset($data['status']) ? (string) $data['status'] : $sku->status,
                    'version'          => (int) $sku->version + 1,
                ]);
                $sku->save();
            } else {
                $skuCode = ! empty($data['sku_code'])
                    ? strtoupper(trim((string) $data['sku_code']))
                    : ($ext->product->sku.'-'.strtoupper(Str::random(4)));

                $sku = MerchantProductSku::create([
                    'product_id'       => $ext->product_id,
                    'seller_id'        => $sellerId,
                    'shop_id'          => $shop->id,
                    'sku_code'         => $skuCode,
                    'name'             => trim((string) ($data['name'] ?? '新规格')),
                    'spec_json'        => is_array($data['spec_json'] ?? null) ? $data['spec_json'] : [],
                    'price_cents'      => max(0, (int) ($data['price_cents'] ?? $ext->price_cents)),
                    'daily_rent_cents' => max(0, (int) ($data['daily_rent_cents'] ?? $ext->daily_rent_cents)),
                    'deposit_cents'    => max(0, (int) ($data['deposit_cents'] ?? $ext->deposit_cents)),
                    'sellable_stock'   => max(0, (int) ($data['sellable_stock'] ?? 0)),
                    'rentable_stock'   => max(0, (int) ($data['rentable_stock'] ?? 0)),
                    'safety_stock'     => max(0, (int) ($data['safety_stock'] ?? 1)),
                    'status'           => 'active',
                    'version'          => 1,
                ]);
            }

            // 汇总激活态 SKU 库存回写 SPU 双轨库存
            $activeSkus = MerchantProductSku::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $ext->product_id)
                ->where('status', 'active')
                ->get();

            if ($activeSkus->isNotEmpty()) {
                $ext->sellable_stock = (int) $activeSkus->sum('sellable_stock');
                $ext->rentable_stock = (int) $activeSkus->sum('rentable_stock');
                $ext->version = (int) $ext->version + 1;
                $ext->save();
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.sku.upsert',
                'merchant_sku',
                $sku->id,
                ['seller_id' => $sellerId, 'product_id' => $ext->product_id, 'sku_code' => $sku->sku_code]
            );

            return $sku;
        });
    }

    /**
     * L3 租赁方案、租期阶梯价与押金规则闭环
     */
    public function listRentalPlans(MerchantShop|int $shopOrSellerId, ?int $productId = null): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);

        $query = MerchantRentalPlan::query()->where('seller_id', $sellerId);
        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->orderBy('product_id')->orderBy('min_days')->get()->all();
    }

    public function upsertRentalPlan(MerchantShop $shop, Customer|int $actor, array $data, ?int $planId = null): MerchantRentalPlan
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);
        $productId = (int) ($data['product_id'] ?? 0);

        $ext = $this->findOwnedProduct($shop, $productId);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $ext, $data, $planId) {
            if ($planId) {
                $plan = MerchantRentalPlan::query()
                    ->where('seller_id', $sellerId)
                    ->where('id', $planId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $plan->fill([
                    'name'                   => isset($data['name']) ? trim((string) $data['name']) : $plan->name,
                    'period_unit'            => isset($data['period_unit']) ? (string) $data['period_unit'] : $plan->period_unit,
                    'min_days'               => isset($data['min_days']) ? max(1, (int) $data['min_days']) : $plan->min_days,
                    'max_days'               => isset($data['max_days']) ? max(1, (int) $data['max_days']) : $plan->max_days,
                    'base_price_cents'       => isset($data['base_price_cents']) ? max(0, (int) $data['base_price_cents']) : $plan->base_price_cents,
                    'price_tiers'            => isset($data['price_tiers']) && is_array($data['price_tiers']) ? $data['price_tiers'] : $plan->price_tiers,
                    'deposit_rule'           => isset($data['deposit_rule']) && is_array($data['deposit_rule']) ? $data['deposit_rule'] : $plan->deposit_rule,
                    'contract_template_code' => isset($data['contract_template_code']) ? (string) $data['contract_template_code'] : $plan->contract_template_code,
                    'enabled'                => isset($data['enabled']) ? (bool) $data['enabled'] : $plan->enabled,
                    'version'                => (int) $plan->version + 1,
                ]);
                $plan->save();
            } else {
                $planCode = ! empty($data['plan_code'])
                    ? strtoupper(trim((string) $data['plan_code']))
                    : ($ext->product->sku.'-RP-'.strtoupper(Str::random(4)));

                $plan = MerchantRentalPlan::create([
                    'product_id'             => $ext->product_id,
                    'seller_id'              => $sellerId,
                    'shop_id'                => $shop->id,
                    'plan_code'              => $planCode,
                    'name'                   => trim((string) ($data['name'] ?? '新增租赁方案')),
                    'period_unit'            => (string) ($data['period_unit'] ?? 'day'),
                    'min_days'               => max(1, (int) ($data['min_days'] ?? 1)),
                    'max_days'               => max(1, (int) ($data['max_days'] ?? 365)),
                    'base_price_cents'       => max(0, (int) ($data['base_price_cents'] ?? $ext->daily_rent_cents)),
                    'price_tiers'            => is_array($data['price_tiers'] ?? null) ? $data['price_tiers'] : [],
                    'deposit_rule'           => is_array($data['deposit_rule'] ?? null) ? $data['deposit_rule'] : [
                        'mode'                => 'full',
                        'deposit_cents'       => $ext->deposit_cents,
                        'overdue_daily_cents' => (int) round($ext->daily_rent_cents * 1.5),
                    ],
                    'contract_template_code' => (string) ($data['contract_template_code'] ?? 'TPL-ROBOT-RENTAL-STD'),
                    'enabled'                => isset($data['enabled']) ? (bool) $data['enabled'] : true,
                    'version'                => 1,
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.rental_plan.upsert',
                'merchant_rental_plan',
                $plan->id,
                ['seller_id' => $sellerId, 'product_id' => $ext->product_id, 'plan_code' => $plan->plan_code]
            );

            return $plan;
        });
    }

    public function submitForReview(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $productId, bool $autoApprove = false, mixed $request = null): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $productId, $actorId, $autoApprove) {
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            if (! in_array($ext->review_status, [MerchantProductExt::REVIEW_DRAFT, MerchantProductExt::REVIEW_REJECTED], true)) {
                throw new RuntimeException('INVALID_STATE:当前审核状态（'.$ext->review_status.'）不允许重复提审。', 409);
            }

            $compatCheck = $this->validateCompatibleModels((array) ($ext->compatible_models ?? []));
            if (! $compatCheck['ok']) {
                throw new RuntimeException($compatCheck['code'].':'.$compatCheck['message'], 422);
            }

            $prevStatus = $ext->review_status;
            $ext->review_status = $autoApprove ? MerchantProductExt::REVIEW_APPROVED : MerchantProductExt::REVIEW_IN_REVIEW;
            $ext->reject_reason = null;
            $ext->submitted_at = now();
            if ($autoApprove) {
                $ext->reviewed_at = now();
            }
            $ext->version = (int) $ext->version + 1;
            $ext->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.product.submit_review',
                'product',
                $productId,
                [
                    'seller_id'   => $sellerId,
                    'from_status' => $prevStatus,
                    'to_status'   => $ext->review_status,
                ]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    /**
     * L4 审核决定闭环（支持通过 approved / 驳回 rejected + 驳回原因留痕）
     */
    public function reviewDecision(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $productId, string $decision, ?string $rejectReason = null): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $productId, $actorId, $decision, $rejectReason) {
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            if ($decision === 'reject' || $decision === MerchantProductExt::REVIEW_REJECTED) {
                $reason = trim((string) $rejectReason);
                if ($reason === '') {
                    throw new RuntimeException('VALIDATION_ERROR:驳回商品必须填写驳回原因。', 422);
                }
                $ext->review_status = MerchantProductExt::REVIEW_REJECTED;
                $ext->reject_reason = $reason;
                $ext->shelf_status = MerchantProductExt::SHELF_OFF;
                DB::table('product_flat')->where('product_id', $productId)->update(['status' => 0]);
            } else {
                $ext->review_status = MerchantProductExt::REVIEW_APPROVED;
                $ext->reject_reason = null;
            }

            $ext->reviewed_at = now();
            $ext->version = (int) $ext->version + 1;
            $ext->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.product.review_decision',
                'product',
                $productId,
                [
                    'seller_id'     => $sellerId,
                    'review_status' => $ext->review_status,
                    'reject_reason' => $ext->reject_reason,
                ]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    public function putOnShelf(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $productId, mixed $request = null): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $productId, $actorId) {
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            if ($ext->review_status !== MerchantProductExt::REVIEW_APPROVED) {
                throw new RuntimeException('INVALID_STATE:商品尚未通过平台审核，无法上架发售。', 409);
            }

            $ext->shelf_status = MerchantProductExt::SHELF_ON;
            $ext->on_shelf_at = now();
            $ext->version = (int) $ext->version + 1;
            $ext->save();

            DB::table('product_flat')
                ->where('product_id', $productId)
                ->update(['status' => 1, 'updated_at' => now()]);

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.product.on_shelf',
                'product',
                $productId,
                ['seller_id' => $sellerId, 'version' => $ext->version]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    public function pullOffShelf(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $productId, mixed $request = null): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $productId, $actorId) {
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            $ext->shelf_status = MerchantProductExt::SHELF_OFF;
            $ext->version = (int) $ext->version + 1;
            $ext->save();

            DB::table('product_flat')
                ->where('product_id', $productId)
                ->update(['status' => 0, 'updated_at' => now()]);

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.product.off_shelf',
                'product',
                $productId,
                ['seller_id' => $sellerId, 'version' => $ext->version]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    // =========================================================================
    // 3. 库存中心（仓库管理 + 双轨库存 + 维保锁定释放 + 库存台账流水闭环）
    // =========================================================================

    public function listWarehouses(MerchantShop|int $shopOrSellerId): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        if (! Schema::hasTable('merchant_warehouses')) {
            return [];
        }

        return MerchantWarehouse::query()
            ->where('seller_id', $sellerId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (MerchantWarehouse $wh) => [
                'id'             => (int) $wh->id,
                'code'           => (string) $wh->code,
                'warehouse_code' => (string) $wh->code,
                'name'           => (string) $wh->name,
                'region'         => (string) ($wh->region ?? ''),
                'address'        => (string) ($wh->address ?? ''),
                'contact_name'   => (string) ($wh->contact_name ?? ''),
                'contact_phone'  => (string) ($wh->contact_phone ?? ''),
                'is_default'     => (bool) $wh->is_default,
                'status'         => (string) $wh->status,
            ])
            ->all();
    }

    public function upsertWarehouse(MerchantShop $shop, Customer|int $actor, array $data, ?int $warehouseId = null): MerchantWarehouse
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $data, $warehouseId) {
            if (! empty($data['is_default'])) {
                MerchantWarehouse::query()->where('seller_id', $sellerId)->update(['is_default' => false]);
            }

            if ($warehouseId) {
                $wh = MerchantWarehouse::query()
                    ->where('seller_id', $sellerId)
                    ->where('id', $warehouseId)
                    ->firstOrFail();

                $wh->fill([
                    'name'          => isset($data['name']) ? trim((string) $data['name']) : $wh->name,
                    'region'        => isset($data['region']) ? trim((string) $data['region']) : $wh->region,
                    'address'       => isset($data['address']) ? trim((string) $data['address']) : $wh->address,
                    'contact_name'  => isset($data['contact_name']) ? trim((string) $data['contact_name']) : $wh->contact_name,
                    'contact_phone' => isset($data['contact_phone']) ? trim((string) $data['contact_phone']) : $wh->contact_phone,
                    'is_default'    => isset($data['is_default']) ? (bool) $data['is_default'] : $wh->is_default,
                ]);
                $wh->save();
            } else {
                $code = ! empty($data['code'])
                    ? strtoupper(trim((string) $data['code']))
                    : 'WH-'.strtoupper(Str::random(5));

                $wh = MerchantWarehouse::create([
                    'seller_id'     => $sellerId,
                    'shop_id'       => $shop->id,
                    'code'          => $code,
                    'name'          => trim((string) $data['name']),
                    'region'        => trim((string) ($data['region'] ?? '')),
                    'address'       => trim((string) ($data['address'] ?? '')),
                    'contact_name'  => trim((string) ($data['contact_name'] ?? '')),
                    'contact_phone' => trim((string) ($data['contact_phone'] ?? '')),
                    'is_default'    => (bool) ($data['is_default'] ?? false),
                    'status'        => 'active',
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.warehouse.upsert',
                'merchant_warehouse',
                $wh->id,
                ['seller_id' => $sellerId, 'code' => $wh->code, 'name' => $wh->name]
            );

            return $wh;
        });
    }

    public function adjustInventory(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $productId, array $data, mixed $request = null): MerchantProductExt
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $productId, $actorId, $data) {
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            $expectedVersion = $data['version'] ?? $data['expected_version'] ?? null;
            if ($expectedVersion !== null && (int) $expectedVersion !== (int) $ext->version) {
                throw new RuntimeException('VERSION_CONFLICT:库存已被并发更新，请刷新最新库存后再提交（版本冲突）。', 409);
            }

            $before = [
                'sellable_stock'    => (int) $ext->sellable_stock,
                'rentable_stock'    => (int) $ext->rentable_stock,
                'locked_stock'      => (int) $ext->locked_stock,
                'maintenance_stock' => (int) $ext->maintenance_stock,
            ];

            $actionType = (string) ($data['action_type'] ?? 'stocktake');

            // 专项动作 1：将可租库存转入维保锁定（maintenance_lock）
            if ($actionType === 'maintenance_lock') {
                $qty = max(1, (int) ($data['qty'] ?? 1));
                if ($ext->rentable_stock < $qty) {
                    throw new RuntimeException('INVALID_STATE:当前可租可用库存不足，无法转入维保锁定。', 409);
                }
                $ext->rentable_stock -= $qty;
                $ext->maintenance_stock += $qty;
            }
            // 专项动作 2：维保完成释放回可租库存（maintenance_release）
            elseif ($actionType === 'maintenance_release') {
                $qty = max(1, (int) ($data['qty'] ?? 1));
                if ($ext->maintenance_stock < $qty) {
                    throw new RuntimeException('INVALID_STATE:当前维保锁定库存不足，无法释放。', 409);
                }
                $ext->maintenance_stock -= $qty;
                $ext->rentable_stock += $qty;
            } else {
                if (isset($data['sellable_stock'])) {
                    $ext->sellable_stock = max(0, (int) $data['sellable_stock']);
                }
                if (isset($data['rentable_stock'])) {
                    $ext->rentable_stock = max(0, (int) $data['rentable_stock']);
                }
                if (isset($data['rented_locked_qty']) || isset($data['locked_stock'])) {
                    $ext->locked_stock = max(0, (int) ($data['rented_locked_qty'] ?? $data['locked_stock']));
                }
                if (isset($data['maintenance_qty']) || isset($data['maintenance_stock'])) {
                    $ext->maintenance_stock = max(0, (int) ($data['maintenance_qty'] ?? $data['maintenance_stock']));
                }
                if (isset($data['safety_stock'])) {
                    $ext->safety_stock = max(0, (int) $data['safety_stock']);
                }
            }

            if (isset($data['deduct_sellable'])) {
                $deduct = max(0, (int) $data['deduct_sellable']);
                if ($ext->sellable_stock < $deduct) {
                    throw new RuntimeException('INVALID_STATE:可售库存不足，禁止超卖。', 409);
                }
                $ext->sellable_stock -= $deduct;
                $ext->locked_stock += $deduct;
                $actionType = 'sale_lock';
            }

            if (isset($data['deduct_rentable'])) {
                $deduct = max(0, (int) $data['deduct_rentable']);
                if ($ext->rentable_stock < $deduct) {
                    throw new RuntimeException('INVALID_STATE:可租档期库存不足，禁止超租。', 409);
                }
                $ext->rentable_stock -= $deduct;
                $ext->locked_stock += $deduct;
                $actionType = 'rental_lock';
            }

            $ext->version = (int) $ext->version + 1;
            $ext->save();

            $bagistoQty = $ext->product_type === MerchantProductExt::TYPE_RENTAL_ROBOT
                ? $ext->rentable_stock
                : $ext->sellable_stock;

            DB::table('product_inventories')
                ->where('product_id', $productId)
                ->update(['qty' => $bagistoQty]);

            if (Schema::hasTable('merchant_inventory_locks')) {
                $qtyChange = ($ext->sellable_stock + $ext->rentable_stock) - ($before['sellable_stock'] + $before['rentable_stock']);
                MerchantInventoryLock::create([
                    'seller_id'         => $sellerId,
                    'shop_id'           => $ext->shop_id,
                    'product_id'        => $productId,
                    'warehouse_id'      => isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
                    'action_type'       => $actionType,
                    'qty_change'        => $qtyChange,
                    'sellable_after'    => (int) $ext->sellable_stock,
                    'rentable_after'    => (int) $ext->rentable_stock,
                    'locked_after'      => (int) $ext->locked_stock,
                    'maintenance_after' => (int) $ext->maintenance_stock,
                    'reference_no'      => (string) ($data['reference_no'] ?? ''),
                    'reason'            => (string) ($data['reason'] ?? '库存盘点/调整'),
                    'actor_id'          => $actorId,
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.inventory.adjust',
                'product',
                $productId,
                [
                    'seller_id'   => $sellerId,
                    'action_type' => $actionType,
                    'before'      => $before,
                    'after'       => [
                        'sellable_stock'    => $ext->sellable_stock,
                        'rentable_stock'    => $ext->rentable_stock,
                        'locked_stock'      => $ext->locked_stock,
                        'maintenance_stock' => $ext->maintenance_stock,
                    ],
                    'version'     => $ext->version,
                ]
            );

            return $ext->load(['product', 'skus', 'rentalPlans']);
        });
    }

    public function listInventoryLocks(MerchantShop|int $shopOrSellerId, ?int $productId = null, int $limit = 30): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        if (! Schema::hasTable('merchant_inventory_locks')) {
            return [];
        }

        $query = MerchantInventoryLock::query()->where('seller_id', $sellerId);
        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->orderByDesc('id')->limit($limit)->get()->map(function (MerchantInventoryLock $lk) {
            $action = (string) $lk->action_type;
            $direction = match ($action) {
                'sale_lock', 'rental_lock', 'maintenance_lock' => 'lock',
                'return_release', 'maintenance_release'        => 'release',
                default                                        => 'adjust',
            };

            return [
                'id'                => (int) $lk->id,
                'product_id'        => (int) $lk->product_id,
                'action_type'       => $action,
                'lock_type'         => $action,
                'direction'         => $direction,
                'qty_change'        => (int) $lk->qty_change,
                'qty'               => abs((int) $lk->qty_change) ?: 1,
                'sellable_after'    => (int) $lk->sellable_after,
                'rentable_after'    => (int) $lk->rentable_after,
                'locked_after'      => (int) $lk->locked_after,
                'maintenance_after' => (int) $lk->maintenance_after,
                'snapshot'          => [
                    'sellable_stock'    => (int) $lk->sellable_after,
                    'rentable_stock'    => (int) $lk->rentable_after,
                    'rented_locked_qty' => (int) $lk->locked_after,
                    'maintenance_qty'   => (int) $lk->maintenance_after,
                ],
                'reference_no'      => (string) ($lk->reference_no ?? ''),
                'device_sn'         => str_starts_with((string) $lk->reference_no, 'SN-') ? (string) $lk->reference_no : '',
                'reason'            => (string) ($lk->reason ?? ''),
                'created_at'        => $lk->created_at?->toIso8601String(),
            ];
        })->all();
    }

    // =========================================================================
    // 4. 订单与租赁履约全生命周期状态机闭环（下单锁库 -> 发货绑SN -> 归还 -> 验机定损 -> 完结退押）
    // =========================================================================

    public function createOrder(MerchantShop $shop, Customer|int $actor, array $data): MerchantOrder
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);
        $productId = (int) ($data['product_id'] ?? 0);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $productId, $data) {
            $ext = MerchantProductExt::query()
                ->with('product')
                ->where('seller_id', $sellerId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $ext) {
                throw (new ModelNotFoundException)->setModel(MerchantProductExt::class, [$productId]);
            }

            $qty = max(1, (int) ($data['qty'] ?? 1));
            $fulfillmentType = ! empty($data['fulfillment_type'])
                ? (string) $data['fulfillment_type']
                : (string) $ext->fulfillment_type;

            $flat = DB::table('product_flat')->where('product_id', $productId)->orderByDesc('id')->first();
            $productName = (string) ($flat?->name ?? $ext->product?->sku ?? '商品 #'.$productId);
            $skuCode = (string) ($ext->product?->sku ?? 'SKU-'.$productId);

            $rentalDays = max(1, (int) ($data['rental_days'] ?? $ext->min_rent_days ?? 1));

            if ($fulfillmentType === 'rental') {
                if ($ext->rentable_stock < $qty) {
                    throw new RuntimeException('INVALID_STATE:当前可租可用库存不足（剩余 '.$ext->rentable_stock.' 台），禁止超租。', 409);
                }
                $ext->rentable_stock -= $qty;
                $ext->locked_stock += $qty;

                $dailyCents = (int) ($ext->daily_rent_cents ?: $ext->price_cents);
                $unitPriceCents = $dailyCents;
                $subtotalCents = $dailyCents * $rentalDays * $qty;
                $depositCents = ((int) $ext->deposit_cents) * $qty;
            } else {
                if ($ext->sellable_stock < $qty) {
                    throw new RuntimeException('INVALID_STATE:当前可售库存不足（剩余 '.$ext->sellable_stock.' 件），禁止超卖。', 409);
                }
                $ext->sellable_stock -= $qty;
                $ext->locked_stock += $qty;

                $unitPriceCents = (int) $ext->price_cents;
                $subtotalCents = $unitPriceCents * $qty;
                $depositCents = 0;
            }

            $ext->version = (int) $ext->version + 1;
            $ext->save();

            $orderNo = ($fulfillmentType === 'rental' ? 'RNT-' : 'ORD-').date('Ymd').'-'.strtoupper(Str::random(6));
            $contractNo = $fulfillmentType === 'rental' ? ('CTR-'.date('Ymd').'-'.strtoupper(Str::random(6))) : null;

            $order = MerchantOrder::create([
                'order_no'             => $orderNo,
                'seller_id'            => $sellerId,
                'shop_id'              => $shop->id,
                'product_id'           => $productId,
                'sku_id'               => isset($data['sku_id']) ? (int) $data['sku_id'] : null,
                'rental_plan_id'       => isset($data['rental_plan_id']) ? (int) $data['rental_plan_id'] : null,
                'fulfillment_type'     => $fulfillmentType,
                'product_name'         => $productName,
                'sku_code'             => $skuCode,
                'buyer_name'           => trim((string) ($data['buyer_name'] ?? '企业客户')),
                'buyer_phone'          => trim((string) ($data['buyer_phone'] ?? '13800000000')),
                'shipping_address'     => trim((string) ($data['shipping_address'] ?? '深圳市南山区科技园交付点')),
                'qty'                  => $qty,
                'rental_days'          => $rentalDays,
                'rental_start_at'      => $fulfillmentType === 'rental' ? now() : null,
                'rental_end_at'        => $fulfillmentType === 'rental' ? now()->addDays($rentalDays) : null,
                'unit_price_cents'     => $unitPriceCents,
                'subtotal_cents'       => $subtotalCents,
                'deposit_cents'        => $depositCents,
                'damage_deduct_cents'  => 0,
                'overdue_fee_cents'    => 0,
                'refund_deposit_cents' => $depositCents,
                'status'               => MerchantOrder::STATUS_PENDING_SHIPMENT,
                'contract_no'          => $contractNo,
                'contract_status'      => $fulfillmentType === 'rental' ? 'signed' : 'not_required',
                'contract_signed_at'   => $fulfillmentType === 'rental' ? now() : null,
                'version'              => 1,
            ]);

            if (Schema::hasTable('merchant_inventory_locks')) {
                MerchantInventoryLock::create([
                    'seller_id'         => $sellerId,
                    'shop_id'           => $shop->id,
                    'product_id'        => $productId,
                    'action_type'       => $fulfillmentType === 'rental' ? 'rental_lock' : 'sale_lock',
                    'qty_change'        => -$qty,
                    'sellable_after'    => (int) $ext->sellable_stock,
                    'rentable_after'    => (int) $ext->rentable_stock,
                    'locked_after'      => (int) $ext->locked_stock,
                    'maintenance_after' => (int) $ext->maintenance_stock,
                    'reference_no'      => $orderNo,
                    'reason'            => ($fulfillmentType === 'rental' ? '租赁下单锁定档期库存' : '售卖下单锁定库存'),
                    'actor_id'          => $actorId,
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.order.create',
                'merchant_order',
                $order->id,
                [
                    'seller_id'        => $sellerId,
                    'order_no'         => $orderNo,
                    'fulfillment_type' => $fulfillmentType,
                    'subtotal_cents'   => $subtotalCents,
                    'deposit_cents'    => $depositCents,
                ]
            );

            return $order;
        });
    }

    public function findOwnedOrder(MerchantShop|int $shopOrSellerId, int $orderId): MerchantOrder
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);

        $order = MerchantOrder::query()
            ->where('seller_id', $sellerId)
            ->where('id', $orderId)
            ->first();

        if (! $order) {
            throw (new ModelNotFoundException)->setModel(MerchantOrder::class, [$orderId]);
        }

        return $order;
    }

    /**
     * 订单发货（绑定物流单号与出库真机序列号 SN）
     */
    public function shipOrder(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $orderId, array $data): MerchantOrder
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $actorId, $orderId, $data) {
            $order = MerchantOrder::query()
                ->where('seller_id', $sellerId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw (new ModelNotFoundException)->setModel(MerchantOrder::class, [$orderId]);
            }

            if ($order->status !== MerchantOrder::STATUS_PENDING_SHIPMENT) {
                throw new RuntimeException('INVALID_STATE:当前订单状态（'.$order->status.'）不允许重复发货。', 409);
            }

            $order->carrier = trim((string) ($data['carrier'] ?? '顺丰大件特快'));
            $order->tracking_no = trim((string) ($data['tracking_no'] ?? ('SF'.date('YmdHis'))));
            $order->device_sn = ! empty($data['device_sn']) ? strtoupper(trim((string) $data['device_sn'])) : ('SN-'.strtoupper(Str::random(8)));
            $order->shipped_at = now();
            $order->status = $order->fulfillment_type === 'rental'
                ? MerchantOrder::STATUS_IN_USE
                : MerchantOrder::STATUS_SHIPPED;
            $order->version = (int) $order->version + 1;
            $order->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.order.ship',
                'merchant_order',
                $order->id,
                [
                    'seller_id'   => $sellerId,
                    'order_no'    => $order->order_no,
                    'tracking_no' => $order->tracking_no,
                    'device_sn'   => $order->device_sn,
                    'to_status'   => $order->status,
                ]
            );

            return $order;
        });
    }

    /**
     * 租赁设备归还登记（in_use / shipped -> returned_inspecting）
     */
    public function returnOrder(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $orderId, array $data = []): MerchantOrder
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $actorId, $orderId, $data) {
            $order = MerchantOrder::query()
                ->where('seller_id', $sellerId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw (new ModelNotFoundException)->setModel(MerchantOrder::class, [$orderId]);
            }

            if ($order->fulfillment_type !== 'rental') {
                throw new RuntimeException('INVALID_STATE:仅租赁订单支持归还登记流转。', 409);
            }

            if (! in_array($order->status, [MerchantOrder::STATUS_SHIPPED, MerchantOrder::STATUS_IN_USE], true)) {
                throw new RuntimeException('INVALID_STATE:当前租赁订单状态（'.$order->status.'）无法登记归还。', 409);
            }

            $order->return_carrier = trim((string) ($data['return_carrier'] ?? '顺丰逆向物流'));
            $order->return_tracking_no = trim((string) ($data['return_tracking_no'] ?? ('RET'.date('YmdHis'))));
            $order->returned_at = now();
            $order->status = MerchantOrder::STATUS_RETURNED_INSPECTING;
            $order->version = (int) $order->version + 1;
            $order->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.order.return',
                'merchant_order',
                $order->id,
                [
                    'seller_id'          => $sellerId,
                    'order_no'           => $order->order_no,
                    'return_tracking_no' => $order->return_tracking_no,
                ]
            );

            return $order;
        });
    }

    /**
     * 归还设备验机与定损（returned_inspecting -> 核定定损扣款 + 自动释放可租库存或转入维保锁定并建维保单）
     */
    public function inspectOrder(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $orderId, array $data): MerchantOrder
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $actorId, $orderId, $data) {
            $order = MerchantOrder::query()
                ->where('seller_id', $sellerId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw (new ModelNotFoundException)->setModel(MerchantOrder::class, [$orderId]);
            }

            if ($order->status !== MerchantOrder::STATUS_RETURNED_INSPECTING) {
                throw new RuntimeException('INVALID_STATE:请先完成设备归还登记后再执行验机定损。', 409);
            }

            $result = (string) ($data['inspection_result'] ?? 'passed');
            if (! in_array($result, ['passed', 'minor_damage', 'major_damage'], true)) {
                throw new RuntimeException('VALIDATION_ERROR:无效的验机结论：'.$result, 422);
            }

            $damageCents = max(0, min((int) $order->deposit_cents, (int) ($data['damage_deduct_cents'] ?? 0)));
            $overdueCents = max(0, min((int) $order->deposit_cents - $damageCents, (int) ($data['overdue_fee_cents'] ?? 0)));
            $refundDepositCents = max(0, (int) $order->deposit_cents - $damageCents - $overdueCents);

            $order->inspection_result = $result;
            $order->inspection_notes = trim((string) ($data['inspection_notes'] ?? ($result === 'passed' ? '整机关节与传感器检测完好' : '存在外观/部件受损')));
            $order->damage_deduct_cents = $damageCents;
            $order->overdue_fee_cents = $overdueCents;
            $order->refund_deposit_cents = $refundDepositCents;
            $order->status = 'inspected';
            $order->inspected_at = now();
            $order->version = (int) $order->version + 1;
            $order->save();

            // 联动更新商品双轨库存与维保工单
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $order->product_id)
                ->lockForUpdate()
                ->first();

            if ($ext) {
                $qty = max(1, (int) $order->qty);
                $ext->locked_stock = max(0, (int) $ext->locked_stock - $qty);

                if ($result === 'major_damage') {
                    // 严重受损：转入维保锁定库存 + 自动创建一条维保工单
                    $ext->maintenance_stock = (int) $ext->maintenance_stock + $qty;
                    $lockAction = 'maintenance_lock';

                    if (Schema::hasTable('merchant_after_sales_tickets')) {
                        MerchantAfterSalesTicket::create([
                            'ticket_no'               => 'MNT-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                            'seller_id'               => $sellerId,
                            'shop_id'                 => $order->shop_id,
                            'merchant_order_id'       => $order->id,
                            'product_id'              => $order->product_id,
                            'ticket_type'             => 'maintenance',
                            'device_sn'               => $order->device_sn,
                            'requester_name'          => '验机中心自动建单',
                            'reason'                  => '归还验机判定严重受损（'.$order->inspection_notes.'），需检修合格后释放库存',
                            'status'                  => MerchantAfterSalesTicket::STATUS_OPEN,
                            'compensation_cents'      => $damageCents,
                            'locks_maintenance_stock' => true,
                            'maintenance_released'    => false,
                            'version'                 => 1,
                        ]);
                    }
                } else {
                    // 验机通过或轻微磨损：直接释放回可租可用库存
                    $ext->rentable_stock = (int) $ext->rentable_stock + $qty;
                    $lockAction = 'return_release';
                }

                $ext->version = (int) $ext->version + 1;
                $ext->save();

                if (Schema::hasTable('merchant_inventory_locks')) {
                    MerchantInventoryLock::create([
                        'seller_id'         => $sellerId,
                        'shop_id'           => $order->shop_id,
                        'product_id'        => $order->product_id,
                        'action_type'       => $lockAction,
                        'qty_change'        => $result === 'major_damage' ? 0 : $qty,
                        'sellable_after'    => (int) $ext->sellable_stock,
                        'rentable_after'    => (int) $ext->rentable_stock,
                        'locked_after'      => (int) $ext->locked_stock,
                        'maintenance_after' => (int) $ext->maintenance_stock,
                        'reference_no'      => $order->order_no,
                        'reason'            => '租赁归还验机结论：'.$result,
                        'actor_id'          => $actorId,
                    ]);
                }
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.order.inspect',
                'merchant_order',
                $order->id,
                [
                    'seller_id'            => $sellerId,
                    'order_no'             => $order->order_no,
                    'inspection_result'    => $result,
                    'damage_deduct_cents'  => $damageCents,
                    'refund_deposit_cents' => $refundDepositCents,
                ]
            );

            return $order;
        });
    }

    /**
     * 订单履约完结（completed）
     */
    public function completeOrder(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $orderId): MerchantOrder
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $actorId, $orderId) {
            $order = MerchantOrder::query()
                ->where('seller_id', $sellerId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw (new ModelNotFoundException)->setModel(MerchantOrder::class, [$orderId]);
            }

            if ($order->fulfillment_type === 'rental' && ! $order->inspected_at) {
                throw new RuntimeException('INVALID_STATE:租赁订单必须先完成归还验机定损后才能完结。', 409);
            }

            if ($order->fulfillment_type === 'sale' && $order->status === MerchantOrder::STATUS_SHIPPED) {
                $ext = MerchantProductExt::query()
                    ->where('seller_id', $sellerId)
                    ->where('product_id', $order->product_id)
                    ->lockForUpdate()
                    ->first();
                if ($ext) {
                    $ext->locked_stock = max(0, (int) $ext->locked_stock - (int) $order->qty);
                    $ext->save();
                }
            }

            $order->status = MerchantOrder::STATUS_COMPLETED;
            $order->completed_at = now();
            $order->version = (int) $order->version + 1;
            $order->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.order.complete',
                'merchant_order',
                $order->id,
                [
                    'seller_id'            => $sellerId,
                    'order_no'             => $order->order_no,
                    'subtotal_cents'       => $order->subtotal_cents,
                    'refund_deposit_cents' => $order->refund_deposit_cents,
                ]
            );

            return $order;
        });
    }

    /**
     * 取消订单（释放锁定库存，原路归还）
     */
    public function cancelOrder(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $orderId, array $data = []): MerchantOrder
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);
        $reason = trim((string) ($data['reason'] ?? $data['cancel_reason'] ?? '商家主动取消/与客户协商一致'));
        $party = in_array(($data['party'] ?? $data['cancel_party'] ?? 'merchant'), ['merchant', 'buyer', 'platform', 'system'], true)
            ? ($data['party'] ?? $data['cancel_party'] ?? 'merchant')
            : 'merchant';

        return DB::transaction(function () use ($sellerId, $actorId, $orderId, $reason, $party) {
            $order = MerchantOrder::query()
                ->where('seller_id', $sellerId)
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw (new ModelNotFoundException)->setModel(MerchantOrder::class, [$orderId]);
            }

            if ($order->status !== MerchantOrder::STATUS_PENDING_SHIPMENT) {
                throw new RuntimeException('INVALID_STATE:仅允许对待发货状态的订单执行取消操作（当前状态：'.$order->status.'）。', 409);
            }

            // 释放锁定的库存，原路归还
            $ext = MerchantProductExt::query()
                ->where('seller_id', $sellerId)
                ->where('product_id', $order->product_id)
                ->lockForUpdate()
                ->first();

            if ($ext) {
                $qty = max(1, (int) $order->qty);
                $ext->locked_stock = max(0, (int) $ext->locked_stock - $qty);
                if ($order->fulfillment_type === 'rental') {
                    $ext->rentable_stock = (int) $ext->rentable_stock + $qty;
                } else {
                    $ext->sellable_stock = (int) $ext->sellable_stock + $qty;
                }
                $ext->version = (int) $ext->version + 1;
                $ext->save();

                if (Schema::hasTable('merchant_inventory_locks')) {
                    MerchantInventoryLock::create([
                        'seller_id'         => $sellerId,
                        'shop_id'           => $order->shop_id,
                        'product_id'        => $order->product_id,
                        'action_type'       => 'cancel_release',
                        'qty_change'        => $qty,
                        'sellable_after'    => (int) $ext->sellable_stock,
                        'rentable_after'    => (int) $ext->rentable_stock,
                        'locked_after'      => (int) $ext->locked_stock,
                        'maintenance_after' => (int) $ext->maintenance_stock,
                        'reference_no'      => $order->order_no,
                        'reason'            => '订单取消，锁定库存原路释放：'.$reason,
                        'actor_id'          => $actorId,
                    ]);
                }
            }

            $order->status = MerchantOrder::STATUS_CANCELLED;
            $order->cancel_reason = $reason;
            $order->cancel_party = $party;
            $order->cancelled_at = now();
            $order->version = (int) $order->version + 1;
            $order->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.order.cancel',
                'merchant_order',
                $order->id,
                [
                    'seller_id'     => $sellerId,
                    'order_no'      => $order->order_no,
                    'cancel_reason' => $reason,
                    'cancel_party'  => $party,
                ]
            );

            return $order;
        });
    }

    public function listOrders(MerchantShop|int $shopOrSellerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $timezone = $shopOrSellerId instanceof MerchantShop ? $shopOrSellerId->timezone : 'Asia/Shanghai';

        if (Schema::hasTable('merchant_orders')) {
            $query = MerchantOrder::query()->where('seller_id', $sellerId);

            if (! empty($filters['fulfillment_type'])) {
                $query->where('fulfillment_type', (string) $filters['fulfillment_type']);
            }
            if (! empty($filters['status'])) {
                $query->where('status', (string) $filters['status']);
            }
            if (! empty($filters['keyword'])) {
                $kw = trim((string) $filters['keyword']);
                $query->where(function ($q) use ($kw) {
                    $q->where('order_no', 'like', '%'.$kw.'%')
                        ->orWhere('buyer_name', 'like', '%'.$kw.'%')
                        ->orWhere('device_sn', 'like', '%'.$kw.'%');
                });
            }

            $paginator = $query->orderByDesc('id')->paginate(max(1, min(100, $perPage)));
            $paginator->getCollection()->transform(fn (MerchantOrder $o) => $this->formatOrderItem($o, $timezone));

            return $paginator;
        }

        return DB::table('order_items as oi')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('p.seller_id', $sellerId)
            ->orderByDesc('o.id')
            ->paginate(max(1, min(100, $perPage)));
    }

    public function formatOrderItem(MerchantOrder $o, ?string $timezone = 'Asia/Shanghai'): array
    {
        // 买家 PII 严格合规脱敏
        $rawBuyerName = (string) ($o->buyer_name ?? '');
        $nameLen = mb_strlen($rawBuyerName, 'UTF-8');
        if ($nameLen <= 1) {
            $maskedBuyerName = $rawBuyerName !== '' ? '*' : '—';
        } elseif ($nameLen === 2) {
            $maskedBuyerName = mb_substr($rawBuyerName, 0, 1, 'UTF-8') . '*';
        } else {
            $maskedBuyerName = mb_substr($rawBuyerName, 0, 1, 'UTF-8') . str_repeat('*', $nameLen - 2) . mb_substr($rawBuyerName, -1, 1, 'UTF-8');
        }

        $rawPhone = (string) ($o->buyer_phone ?? '');
        $phoneLen = strlen($rawPhone);
        if ($phoneLen >= 7) {
            $maskedPhone = substr($rawPhone, 0, 3) . '****' . substr($rawPhone, -4);
        } else {
            $maskedPhone = $rawPhone !== '' ? '***' : '—';
        }

        $rawAddr = (string) ($o->shipping_address ?? '');
        $addrLen = mb_strlen($rawAddr, 'UTF-8');
        if ($addrLen > 8) {
            $maskedAddress = mb_substr($rawAddr, 0, 8, 'UTF-8') . '******';
        } elseif ($addrLen > 0) {
            $maskedAddress = mb_substr($rawAddr, 0, 3, 'UTF-8') . '***';
        } else {
            $maskedAddress = '—';
        }

        // 履约全链路节点时间线
        $timeline = [
            [
                'title'  => '订单创建与支付',
                'time'   => $this->formatTimeForShop($o->created_at, $timezone),
                'status' => 'done',
                'desc'   => '订单已支付成功，履约类型：' . ($o->fulfillment_type === 'rental' ? '设备租赁' : '整机/配件售卖'),
            ],
        ];

        if ($o->fulfillment_type === 'rental') {
            $timeline[] = [
                'title'  => '电子租赁合同',
                'time'   => $this->formatTimeForShop($o->contract_signed_at, $timezone),
                'status' => $o->contract_signed_at ? 'done' : ($o->status === MerchantOrder::STATUS_CANCELLED ? 'cancelled' : 'wait'),
                'desc'   => $o->contract_signed_at ? ('已签署在线电子合同（' . $o->contract_no . '）') : '等待买家完成在线免押/实名合同签署',
            ];
        }

        if ($o->shipped_at) {
            $timeline[] = [
                'title'  => '仓库发货与序列号绑定',
                'time'   => $this->formatTimeForShop($o->shipped_at, $timezone),
                'status' => 'done',
                'desc'   => ($o->carrier ?: '顺丰特快') . '（单号：' . $o->tracking_no . '），出库绑定机器 SN：' . ($o->device_sn ?: '未登记'),
            ];
        } else {
            $timeline[] = [
                'title'  => '等待出库发货',
                'time'   => null,
                'status' => $o->status === MerchantOrder::STATUS_CANCELLED ? 'cancelled' : 'wait',
                'desc'   => $o->status === MerchantOrder::STATUS_CANCELLED ? '订单已取消，无需发货' : '待仓库配货并绑定设备 SN 发货',
            ];
        }

        if ($o->fulfillment_type === 'rental') {
            if ($o->returned_at) {
                $timeline[] = [
                    'title'  => '租赁到期设备寄回',
                    'time'   => $this->formatTimeForShop($o->returned_at, $timezone),
                    'status' => 'done',
                    'desc'   => ($o->return_carrier ?: '回寄物流') . '（单号：' . ($o->return_tracking_no ?: '—') . '）',
                ];
            } elseif (in_array($o->status, [MerchantOrder::STATUS_SHIPPED, MerchantOrder::STATUS_IN_USE], true)) {
                $timeline[] = [
                    'title'  => '在租履约中',
                    'time'   => null,
                    'status' => 'wait',
                    'desc'   => '租期至 ' . ($this->formatTimeForShop($o->rental_end_at, $timezone) ?: '未定') . '，待客户租期届满回寄',
                ];
            }

            if ($o->inspected_at) {
                $timeline[] = [
                    'title'  => '归还验机定损',
                    'time'   => $this->formatTimeForShop($o->inspected_at, $timezone),
                    'status' => 'done',
                    'desc'   => '质检结论：' . ($o->inspection_result === 'passed' ? '完好合格' : ($o->inspection_result === 'minor_damage' ? '轻微划痕磨损' : '部件受损需检修'))
                                . '，定损扣款 ¥' . number_format($o->damage_deduct_cents / 100, 2)
                                . '，应退押金 ¥' . number_format($o->refund_deposit_cents / 100, 2),
                ];
            } elseif ($o->status === MerchantOrder::STATUS_RETURNED_INSPECTING) {
                $timeline[] = [
                    'title'  => '等待验机定损',
                    'time'   => null,
                    'status' => 'wait',
                    'desc'   => '设备已到达仓库，待验机工程师出具质检定损报告',
                ];
            }
        }

        if ($o->status === MerchantOrder::STATUS_CANCELLED) {
            $partyLabel = match ($o->cancel_party) {
                'buyer'    => '买家申请取消',
                'merchant' => '商家主动取消',
                'platform' => '平台风控撤单',
                default    => '系统自动取消',
            };
            $timeline[] = [
                'title'  => '订单已取消并释放库存',
                'time'   => $this->formatTimeForShop($o->cancelled_at, $timezone),
                'status' => 'cancelled',
                'desc'   => $partyLabel . '，原因：' . ($o->cancel_reason ?: '无说明') . '，锁定库存已安全原路回退。',
            ];
        } elseif ($o->status === MerchantOrder::STATUS_COMPLETED) {
            $timeline[] = [
                'title'  => '订单履约完结',
                'time'   => $this->formatTimeForShop($o->completed_at, $timezone),
                'status' => 'done',
                'desc'   => '整单履约完毕，进入结算池，押金原路解冻或退还。',
            ];
        }

        return [
            'id'                      => (int) $o->id,
            'order_id'                => (int) $o->id,
            'order_no'                => (string) $o->order_no,
            'increment_id'            => (string) $o->order_no,
            'seller_id'               => (int) $o->seller_id,
            'shop_id'                 => (int) $o->shop_id,
            'product_id'              => (int) $o->product_id,
            'product_name'            => (string) $o->product_name,
            'sku'                     => (string) $o->sku_code,
            'sku_code'                => (string) $o->sku_code,
            'fulfillment_type'        => (string) $o->fulfillment_type,
            'buyer_id'                => (int) ($o->buyer_id ?? 0),
            'buyer_name'              => (string) $o->buyer_name,
            'buyer_name_masked'       => $maskedBuyerName,
            'buyer_phone'             => (string) ($o->buyer_phone ?? ''),
            'buyer_phone_masked'      => $maskedPhone,
            'shipping_address'        => (string) ($o->shipping_address ?? ''),
            'shipping_address_masked' => $maskedAddress,
            'qty'                     => (int) $o->qty,
            'qty_ordered'             => (int) $o->qty,
            'rental_days'             => (int) $o->rental_days,
            'rental_start_at'         => $this->formatTimeForShop($o->rental_start_at, $timezone),
            'rental_end_at'           => $this->formatTimeForShop($o->rental_end_at, $timezone),
            'unit_price_cents'        => (int) $o->unit_price_cents,
            'subtotal_cents'          => (int) $o->subtotal_cents,
            'total_amount_cents'      => (int) $o->subtotal_cents,
            'total'                   => number_format($o->subtotal_cents / 100, 2, '.', ''),
            'total_amount_yuan'       => number_format($o->subtotal_cents / 100, 2, '.', ''),
            'deposit_cents'           => (int) $o->deposit_cents,
            'deposit_yuan'            => number_format($o->deposit_cents / 100, 2, '.', ''),
            'damage_deduct_cents'     => (int) $o->damage_deduct_cents,
            'damage_deduct_yuan'      => number_format($o->damage_deduct_cents / 100, 2, '.', ''),
            'overdue_fee_cents'       => (int) $o->overdue_fee_cents,
            'refund_deposit_cents'    => (int) $o->refund_deposit_cents,
            'refund_deposit_yuan'     => number_format($o->refund_deposit_cents / 100, 2, '.', ''),
            'status'                  => (string) $o->status,
            'order_status'            => (string) $o->status,
            'cancel_reason'           => $o->cancel_reason,
            'cancel_party'            => $o->cancel_party,
            'cancelled_at'            => $this->formatTimeForShop($o->cancelled_at, $timezone),
            'contract_no'             => $o->contract_no,
            'contract_status'         => (string) $o->contract_status,
            'device_sn'               => $o->device_sn,
            'carrier'                 => $o->carrier,
            'tracking_no'             => $o->tracking_no,
            'shipped_at'              => $this->formatTimeForShop($o->shipped_at, $timezone),
            'return_carrier'          => $o->return_carrier,
            'return_tracking_no'      => $o->return_tracking_no,
            'returned_at'             => $this->formatTimeForShop($o->returned_at, $timezone),
            'inspection_result'       => $o->inspection_result,
            'inspection_notes'        => $o->inspection_notes,
            'inspected_at'            => $this->formatTimeForShop($o->inspected_at, $timezone),
            'completed_at'            => $this->formatTimeForShop($o->completed_at, $timezone),
            'settled'                 => (bool) $o->settled,
            'order_created_at'        => $this->formatTimeForShop($o->created_at, $timezone),
            'timeline'                => $timeline,
        ];
    }

    // =========================================================================
    // 5. 售后退换修与维保工单闭环（联动维保锁定库存占用与释放）
    // =========================================================================

    public function listAfterSalesTickets(MerchantShop|int $shopOrSellerId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);

        $query = MerchantAfterSalesTicket::query()->where('seller_id', $sellerId);
        if (! empty($filters['ticket_type'])) {
            $query->where('ticket_type', (string) $filters['ticket_type']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $paginator = $query->orderByDesc('id')->paginate(max(1, min(100, $perPage)));
        $paginator->getCollection()->transform(function (MerchantAfterSalesTicket $tk) {
            $flat = DB::table('product_flat')->where('product_id', $tk->product_id)->orderByDesc('id')->first();
            $orderNo = $tk->merchant_order_id
                ? (string) (MerchantOrder::query()->where('id', $tk->merchant_order_id)->value('order_no') ?? '')
                : '';

            return [
                'id'                      => (int) $tk->id,
                'ticket_no'               => (string) $tk->ticket_no,
                'seller_id'               => (int) $tk->seller_id,
                'shop_id'                 => (int) $tk->shop_id,
                'merchant_order_id'       => $tk->merchant_order_id,
                'order_no'                => $orderNo,
                'product_id'              => (int) $tk->product_id,
                'product_name'            => (string) ($flat?->name ?? ('商品 #'.$tk->product_id)),
                'ticket_type'             => (string) $tk->ticket_type,
                'device_sn'               => $tk->device_sn,
                'requester_name'          => (string) $tk->requester_name,
                'reason'                  => (string) $tk->reason,
                'status'                  => (string) $tk->status,
                'resolution_notes'        => $tk->resolution_notes,
                'refund_cents'            => (int) $tk->refund_cents,
                'refund_yuan'             => number_format($tk->refund_cents / 100, 2, '.', ''),
                'compensation_cents'      => (int) $tk->compensation_cents,
                'compensation_yuan'       => number_format($tk->compensation_cents / 100, 2, '.', ''),
                'locks_maintenance_stock' => (bool) ($tk->locks_maintenance_stock && ! $tk->maintenance_released),
                'maintenance_released'    => (bool) $tk->maintenance_released,
                'created_at'              => $tk->created_at?->toIso8601String(),
            ];
        });

        return $paginator;
    }

    public function createAfterSalesTicket(MerchantShop $shop, Customer|int $actor, array $data): MerchantAfterSalesTicket
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);
        $productId = (int) ($data['product_id'] ?? 0);

        $ext = $this->findOwnedProduct($shop, $productId);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $ext, $data) {
            $ticketType = (string) ($data['ticket_type'] ?? 'repair');
            $locksMaintenance = (bool) ($data['locks_maintenance_stock'] ?? ($ticketType === 'maintenance'));

            if ($locksMaintenance && $ext->rentable_stock > 0) {
                $ext->rentable_stock -= 1;
                $ext->maintenance_stock += 1;
                $ext->version += 1;
                $ext->save();

                if (Schema::hasTable('merchant_inventory_locks')) {
                    MerchantInventoryLock::create([
                        'seller_id'         => $sellerId,
                        'shop_id'           => $shop->id,
                        'product_id'        => $ext->product_id,
                        'action_type'       => 'maintenance_lock',
                        'qty_change'        => -1,
                        'sellable_after'    => (int) $ext->sellable_stock,
                        'rentable_after'    => (int) $ext->rentable_stock,
                        'locked_after'      => (int) $ext->locked_stock,
                        'maintenance_after' => (int) $ext->maintenance_stock,
                        'reference_no'      => (string) ($data['device_sn'] ?? ''),
                        'reason'            => '新建维保/检修工单占用库存',
                        'actor_id'          => $actorId,
                    ]);
                }
            }

            $prefix = $ticketType === 'maintenance' ? 'MNT-' : 'AFS-';
            $ticket = MerchantAfterSalesTicket::create([
                'ticket_no'               => $prefix.date('Ymd').'-'.strtoupper(Str::random(5)),
                'seller_id'               => $sellerId,
                'shop_id'                 => $shop->id,
                'merchant_order_id'       => isset($data['merchant_order_id']) ? (int) $data['merchant_order_id'] : null,
                'product_id'              => $ext->product_id,
                'ticket_type'             => $ticketType,
                'device_sn'               => ! empty($data['device_sn']) ? strtoupper(trim((string) $data['device_sn'])) : null,
                'requester_name'          => trim((string) ($data['requester_name'] ?? '客户/巡检员')),
                'reason'                  => trim((string) $data['reason']),
                'status'                  => MerchantAfterSalesTicket::STATUS_OPEN,
                'refund_cents'            => max(0, (int) ($data['refund_cents'] ?? 0)),
                'compensation_cents'      => max(0, (int) ($data['compensation_cents'] ?? 0)),
                'locks_maintenance_stock' => $locksMaintenance,
                'maintenance_released'    => false,
                'version'                 => 1,
            ]);

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.after_sale.create',
                'merchant_after_sales_ticket',
                $ticket->id,
                [
                    'seller_id'   => $sellerId,
                    'ticket_no'   => $ticket->ticket_no,
                    'ticket_type' => $ticketType,
                ]
            );

            return $ticket;
        });
    }

    public function resolveAfterSalesTicket(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $ticketId, array $data): MerchantAfterSalesTicket
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $actorId, $ticketId, $data) {
            $ticket = MerchantAfterSalesTicket::query()
                ->where('seller_id', $sellerId)
                ->where('id', $ticketId)
                ->lockForUpdate()
                ->first();

            if (! $ticket) {
                throw (new ModelNotFoundException)->setModel(MerchantAfterSalesTicket::class, [$ticketId]);
            }

            $status = (string) ($data['status'] ?? MerchantAfterSalesTicket::STATUS_RESOLVED);
            if (! in_array($status, [MerchantAfterSalesTicket::STATUS_PROCESSING, MerchantAfterSalesTicket::STATUS_RESOLVED, MerchantAfterSalesTicket::STATUS_REJECTED], true)) {
                throw new RuntimeException('VALIDATION_ERROR:不支持的工单目标状态：'.$status, 422);
            }

            $ticket->status = $status;
            $ticket->resolution_notes = trim((string) ($data['resolution_notes'] ?? '已检修完毕并验证通过'));
            if (isset($data['refund_cents'])) {
                $ticket->refund_cents = max(0, (int) $data['refund_cents']);
            }
            if (isset($data['compensation_cents'])) {
                $ticket->compensation_cents = max(0, (int) $data['compensation_cents']);
            }
            $ticket->handled_by = $actorId;

            if (in_array($status, [MerchantAfterSalesTicket::STATUS_RESOLVED, MerchantAfterSalesTicket::STATUS_REJECTED], true)) {
                $ticket->resolved_at = now();

                // 若该工单占用了维保锁定库存且尚未释放，完结时自动释放回可租可用库存
                $shouldRelease = (bool) ($data['release_maintenance_stock'] ?? true);
                if ($ticket->locks_maintenance_stock && ! $ticket->maintenance_released && $shouldRelease) {
                    $ext = MerchantProductExt::query()
                        ->where('seller_id', $sellerId)
                        ->where('product_id', $ticket->product_id)
                        ->lockForUpdate()
                        ->first();

                    if ($ext && $ext->maintenance_stock > 0) {
                        $ext->maintenance_stock -= 1;
                        $ext->rentable_stock += 1;
                        $ext->version += 1;
                        $ext->save();

                        $ticket->maintenance_released = true;
                        $ticket->locks_maintenance_stock = false;

                        if (Schema::hasTable('merchant_inventory_locks')) {
                            MerchantInventoryLock::create([
                                'seller_id'         => $sellerId,
                                'shop_id'           => $ticket->shop_id,
                                'product_id'        => $ticket->product_id,
                                'action_type'       => 'maintenance_release',
                                'qty_change'        => 1,
                                'sellable_after'    => (int) $ext->sellable_stock,
                                'rentable_after'    => (int) $ext->rentable_stock,
                                'locked_after'      => (int) $ext->locked_stock,
                                'maintenance_after' => (int) $ext->maintenance_stock,
                                'reference_no'      => $ticket->ticket_no,
                                'reason'            => '维保工单完结，设备回归可租库存',
                                'actor_id'          => $actorId,
                            ]);
                        }
                    }
                }
            }

            $ticket->version = (int) $ticket->version + 1;
            $ticket->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.after_sale.resolve',
                'merchant_after_sales_ticket',
                $ticket->id,
                [
                    'seller_id'            => $sellerId,
                    'ticket_no'            => $ticket->ticket_no,
                    'status'               => $ticket->status,
                    'refund_cents'         => $ticket->refund_cents,
                    'maintenance_released' => $ticket->maintenance_released,
                ]
            );

            return $ticket;
        });
    }

    // =========================================================================
    // 6. 结算中心与发票管理闭环（结算单生成 -> 结算明细穿透 -> 确认结算 -> 开具发票）
    // =========================================================================

    public function listSettlements(MerchantShop|int $shopOrSellerId): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        if (! Schema::hasTable('merchant_settlements')) {
            return [];
        }

        return MerchantSettlement::query()
            ->with('items')
            ->where('seller_id', $sellerId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MerchantSettlement $s) => [
                'id'                  => (int) $s->id,
                'settlement_no'       => (string) $s->settlement_no,
                'period_label'        => (string) $s->period_label,
                'orders_count'        => (int) $s->orders_count,
                'gross_amount_cents'  => (int) $s->gross_amount_cents,
                'gross_amount_yuan'   => number_format($s->gross_amount_cents / 100, 2, '.', ''),
                'damage_income_cents' => (int) $s->damage_income_cents,
                'damage_income_yuan'  => number_format($s->damage_income_cents / 100, 2, '.', ''),
                'platform_fee_cents'  => (int) $s->platform_fee_cents,
                'platform_fee_yuan'   => number_format($s->platform_fee_cents / 100, 2, '.', ''),
                'refund_deduct_cents' => (int) $s->refund_deduct_cents,
                'refund_deduct_yuan'  => number_format($s->refund_deduct_cents / 100, 2, '.', ''),
                'net_amount_cents'    => (int) $s->net_amount_cents,
                'net_amount_yuan'     => number_format($s->net_amount_cents / 100, 2, '.', ''),
                'status'              => (string) $s->status,
                'items'               => $s->items->map(fn (MerchantSettlementItem $it) => [
                    'id'               => (int) $it->id,
                    'order_no'         => (string) $it->order_no,
                    'fulfillment_type' => (string) $it->fulfillment_type,
                    'gross_cents'      => (int) $it->gross_cents,
                    'gross_yuan'       => number_format($it->gross_cents / 100, 2, '.', ''),
                    'damage_yuan'      => number_format($it->damage_income_cents / 100, 2, '.', ''),
                    'fee_yuan'         => number_format($it->platform_fee_cents / 100, 2, '.', ''),
                    'net_cents'        => (int) $it->net_cents,
                    'net_yuan'         => number_format($it->net_cents / 100, 2, '.', ''),
                ])->values()->all(),
            ])
            ->all();
    }

    public function createSettlement(MerchantShop $shop, Customer|int $actor, ?string $periodLabel = null): MerchantSettlement
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $periodLabel) {
            $unsettledOrders = MerchantOrder::query()
                ->where('seller_id', $sellerId)
                ->where('status', MerchantOrder::STATUS_COMPLETED)
                ->where('settled', false)
                ->lockForUpdate()
                ->get();

            if ($unsettledOrders->isEmpty()) {
                throw new RuntimeException('INVALID_STATE:当前没有已完结且待结算的订单。', 409);
            }

            $grossCents = (int) $unsettledOrders->sum('subtotal_cents');
            $damageIncomeCents = (int) $unsettledOrders->sum('damage_deduct_cents') + (int) $unsettledOrders->sum('overdue_fee_cents');
            $platformFeeCents = (int) round(($grossCents + $damageIncomeCents) * 0.05);

            $refundDeductCents = (int) MerchantAfterSalesTicket::query()
                ->where('seller_id', $sellerId)
                ->where('status', MerchantAfterSalesTicket::STATUS_RESOLVED)
                ->whereIn('merchant_order_id', $unsettledOrders->pluck('id')->all())
                ->sum('refund_cents');

            $netCents = $grossCents + $damageIncomeCents - $platformFeeCents - $refundDeductCents;

            $settlement = MerchantSettlement::create([
                'settlement_no'       => 'STL-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                'seller_id'           => $sellerId,
                'shop_id'             => $shop->id,
                'period_label'        => $periodLabel ?: (date('Y-m').' 履约结算期'),
                'orders_count'        => $unsettledOrders->count(),
                'gross_amount_cents'  => $grossCents,
                'damage_income_cents' => $damageIncomeCents,
                'platform_fee_cents'  => $platformFeeCents,
                'refund_deduct_cents' => $refundDeductCents,
                'net_amount_cents'    => $netCents,
                'status'              => 'pending_confirm',
            ]);

            foreach ($unsettledOrders as $ord) {
                $itemGross = (int) $ord->subtotal_cents;
                $itemDamage = (int) $ord->damage_deduct_cents + (int) $ord->overdue_fee_cents;
                $itemFee = (int) round(($itemGross + $itemDamage) * 0.05);

                MerchantSettlementItem::create([
                    'settlement_id'       => $settlement->id,
                    'seller_id'           => $sellerId,
                    'merchant_order_id'   => $ord->id,
                    'order_no'            => $ord->order_no,
                    'fulfillment_type'    => $ord->fulfillment_type,
                    'gross_cents'         => $itemGross,
                    'damage_income_cents' => $itemDamage,
                    'platform_fee_cents'  => $itemFee,
                    'net_cents'           => $itemGross + $itemDamage - $itemFee,
                ]);

                $ord->settled = true;
                $ord->save();
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.settlement.create',
                'merchant_settlement',
                $settlement->id,
                [
                    'seller_id'        => $sellerId,
                    'settlement_no'    => $settlement->settlement_no,
                    'orders_count'     => $settlement->orders_count,
                    'net_amount_cents' => $settlement->net_amount_cents,
                ]
            );

            return $settlement->load('items');
        });
    }

    public function confirmSettlement(MerchantShop|int $shopOrSellerId, Customer|int $actor, int $settlementId): MerchantSettlement
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($sellerId, $actorId, $settlementId) {
            $settlement = MerchantSettlement::query()
                ->where('seller_id', $sellerId)
                ->where('id', $settlementId)
                ->lockForUpdate()
                ->first();

            if (! $settlement) {
                throw (new ModelNotFoundException)->setModel(MerchantSettlement::class, [$settlementId]);
            }

            $settlement->status = 'confirmed';
            $settlement->confirmed_at = now();
            $settlement->save();

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.settlement.confirm',
                'merchant_settlement',
                $settlement->id,
                ['seller_id' => $sellerId, 'settlement_no' => $settlement->settlement_no]
            );

            return $settlement->load('items');
        });
    }

    public function listInvoices(MerchantShop|int $shopOrSellerId): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        if (! Schema::hasTable('merchant_invoices')) {
            return [];
        }

        return MerchantInvoice::query()
            ->where('seller_id', $sellerId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MerchantInvoice $inv) => [
                'id'                  => (int) $inv->id,
                'invoice_no'          => (string) $inv->invoice_no,
                'invoice_type'        => (string) $inv->invoice_type,
                'direction'           => (string) $inv->direction,
                'buyer_title'         => (string) $inv->buyer_title,
                'buyer_tax_no'        => (string) ($inv->buyer_tax_no ?? ''),
                'amount_cents'        => (int) $inv->amount_cents,
                'amount_yuan'         => number_format($inv->amount_cents / 100, 2, '.', ''),
                'tax_rate'            => (string) $inv->tax_rate,
                'invoice_code_number' => $inv->invoice_code_number,
                'status'              => (string) $inv->status,
                'issued_at'           => $inv->issued_at?->toIso8601String(),
            ])
            ->all();
    }

    public function createOrIssueInvoice(MerchantShop $shop, Customer|int $actor, array $data, ?int $invoiceId = null): MerchantInvoice
    {
        $sellerId = $this->assertValidSellerId($shop->seller_id);
        $actorId = $this->resolveCustomerId($actor);

        return DB::transaction(function () use ($shop, $sellerId, $actorId, $data, $invoiceId) {
            if ($invoiceId) {
                $inv = MerchantInvoice::query()
                    ->where('seller_id', $sellerId)
                    ->where('id', $invoiceId)
                    ->lockForUpdate()
                    ->first();

                if (! $inv) {
                    throw (new ModelNotFoundException)->setModel(MerchantInvoice::class, [$invoiceId]);
                }

                $inv->invoice_code_number = trim((string) ($data['invoice_code_number'] ?? ('INV-CODE-'.date('YmdHis'))));
                $inv->status = 'issued';
                $inv->issued_at = now();
                $inv->save();
            } else {
                $hasCode = ! empty($data['invoice_code_number']);
                $inv = MerchantInvoice::create([
                    'invoice_no'          => 'INV-'.date('Ymd').'-'.strtoupper(Str::random(5)),
                    'seller_id'           => $sellerId,
                    'shop_id'             => $shop->id,
                    'settlement_id'       => isset($data['settlement_id']) ? (int) $data['settlement_id'] : null,
                    'merchant_order_id'   => isset($data['merchant_order_id']) ? (int) $data['merchant_order_id'] : null,
                    'direction'           => (string) ($data['direction'] ?? 'outbound'),
                    'invoice_type'        => (string) ($data['invoice_type'] ?? 'vat_special'),
                    'buyer_title'         => trim((string) $data['buyer_title']),
                    'buyer_tax_no'        => trim((string) ($data['buyer_tax_no'] ?? '')),
                    'amount_cents'        => max(0, (int) ($data['amount_cents'] ?? 0)),
                    'tax_rate'            => (string) ($data['tax_rate'] ?? '6%'),
                    'invoice_code_number' => $hasCode ? trim((string) $data['invoice_code_number']) : null,
                    'status'              => $hasCode ? 'issued' : 'applied',
                    'issued_at'           => $hasCode ? now() : null,
                ]);
            }

            $this->ops->log(
                OperationLog::ACTOR_CUSTOMER,
                $actorId,
                'merchant.invoice.upsert',
                'merchant_invoice',
                $inv->id,
                [
                    'seller_id'    => $sellerId,
                    'invoice_no'   => $inv->invoice_no,
                    'amount_cents' => $inv->amount_cents,
                    'status'       => $inv->status,
                ]
            );

            return $inv;
        });
    }

    // =========================================================================
    // 7. 审计日志筛选与看板待办/预警指标闭环
    // =========================================================================

    public function listAuditLogs(MerchantShop|int $shopOrSellerId, int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);
        $timezone = $shopOrSellerId instanceof MerchantShop ? $shopOrSellerId->timezone : 'Asia/Shanghai';

        $query = OperationLog::query()
            ->where('action', 'like', 'merchant.%')
            ->where('payload->seller_id', $sellerId);

        if (! empty($filters['action_prefix'])) {
            $query->where('action', 'like', trim((string) $filters['action_prefix']).'%');
        }

        $paginator = $query->orderByDesc('id')->paginate(max(1, min(100, $perPage)));

        $paginator->getCollection()->transform(function (OperationLog $log) use ($timezone) {
            $log->setAttribute('operator', $log->actor_type.':'.$log->actor_id);
            $log->setAttribute('local_time', $this->formatTimeForShop($log->created_at, $timezone));

            return $log;
        });

        return $paginator;
    }

    public function dashboardMetrics(MerchantShop|int $shopOrSellerId): array
    {
        $sellerId = $this->resolveSellerId($shopOrSellerId);

        $exts = MerchantProductExt::query()->where('seller_id', $sellerId)->get();
        $lowStockCount = $exts->filter(fn (MerchantProductExt $e) => ($e->sellable_stock + $e->rentable_stock) <= ($e->safety_stock ?? 2))->count();

        $pendingShipmentCount = 0;
        $returningInspectCount = 0;
        $expiringRentalsCount = 0;
        $unsettledCents = 0;
        $ordersTotal = 0;
        $gmvCents = 0;
        $netSalesCents = 0;
        $depositInCustodyCents = 0;
        $cancelledCount = 0;
        $completedCount = 0;

        if (Schema::hasTable('merchant_orders')) {
            $orders = MerchantOrder::query()->where('seller_id', $sellerId)->get();
            $ordersTotal = $orders->count();
            $pendingShipmentCount = $orders->where('status', MerchantOrder::STATUS_PENDING_SHIPMENT)->count();
            $returningInspectCount = $orders->where('status', MerchantOrder::STATUS_RETURNED_INSPECTING)->count();
            $expiringRentalsCount = $orders->filter(function (MerchantOrder $o) {
                return $o->status === MerchantOrder::STATUS_IN_USE
                    && $o->rental_end_at
                    && $o->rental_end_at->lte(now()->addDays(3));
            })->count();
            $unsettledCents = (int) $orders
                ->where('status', MerchantOrder::STATUS_COMPLETED)
                ->where('settled', false)
                ->sum(fn (MerchantOrder $o) => (int) round(($o->subtotal_cents + $o->damage_deduct_cents + $o->overdue_fee_cents) * 0.95));

            $cancelledCount = $orders->where('status', MerchantOrder::STATUS_CANCELLED)->count();
            $completedCount = $orders->where('status', MerchantOrder::STATUS_COMPLETED)->count();
            $gmvCents = (int) $orders->sum('subtotal_cents');
            $netSalesCents = (int) $orders->where('status', '!=', MerchantOrder::STATUS_CANCELLED)->sum('subtotal_cents')
                + (int) $orders->sum('damage_deduct_cents') + (int) $orders->sum('overdue_fee_cents');
            $depositInCustodyCents = (int) $orders->whereIn('status', [
                MerchantOrder::STATUS_PENDING_SHIPMENT,
                MerchantOrder::STATUS_SHIPPED,
                MerchantOrder::STATUS_IN_USE,
                MerchantOrder::STATUS_RETURNED_INSPECTING,
            ])->sum('deposit_cents');
        }

        $openTicketsCount = Schema::hasTable('merchant_after_sales_tickets')
            ? MerchantAfterSalesTicket::query()
                ->where('seller_id', $sellerId)
                ->whereIn('status', [MerchantAfterSalesTicket::STATUS_OPEN, MerchantAfterSalesTicket::STATUS_PROCESSING])
                ->count()
            : 0;

        return [
            'gmv' => [
                'value' => number_format($gmvCents / 100, 2, '.', ''),
                'cents' => $gmvCents,
                'basis' => '累计出单销售总额（GMV，单位：元）',
                'ready' => true,
            ],
            'net_sales' => [
                'value' => number_format($netSalesCents / 100, 2, '.', ''),
                'cents' => $netSalesCents,
                'basis' => '扣除取消/退款后的实际净销售额（单位：元）',
                'ready' => true,
            ],
            'deposit_in_custody' => [
                'value' => number_format($depositInCustodyCents / 100, 2, '.', ''),
                'cents' => $depositInCustodyCents,
                'basis' => '履约期在押押金池监管总额（单位：元）',
                'ready' => true,
            ],
            'cancelled_orders' => [
                'value' => $cancelledCount,
                'basis' => '已取消/撤单的订单笔数',
                'ready' => true,
            ],
            'completed_orders' => [
                'value' => $completedCount,
                'basis' => '履约验收完毕的完成订单笔数',
                'ready' => true,
            ],
            'on_shelf_products' => [
                'value' => $exts->where('shelf_status', MerchantProductExt::SHELF_ON)->count(),
                'basis' => '已过审且在售上架的机器人/配件商品数',
                'ready' => true,
            ],
            'pending_review_products' => [
                'value' => $exts->whereIn('review_status', [MerchantProductExt::REVIEW_DRAFT, MerchantProductExt::REVIEW_IN_REVIEW, MerchantProductExt::REVIEW_REJECTED])->count(),
                'basis' => '草稿、待审核及需修改重提的商品数',
                'ready' => true,
            ],
            'low_stock_alerts' => [
                'value' => $lowStockCount,
                'basis' => '可用库存低于或等于安全库存阈值的商品数',
                'ready' => true,
            ],
            'sellable_stock_total' => [
                'value' => (int) $exts->sum('sellable_stock'),
                'basis' => '当前店铺全部可售标品可用库存合计',
                'ready' => true,
            ],
            'rentable_stock_total' => [
                'value' => (int) $exts->sum('rentable_stock'),
                'basis' => '当前店铺全部可租排期机器可用台数合计',
                'ready' => true,
            ],
            'maintenance_stock_total' => [
                'value' => (int) $exts->sum('maintenance_stock'),
                'basis' => '当前处于维修保养锁定中的机器台数合计',
                'ready' => true,
            ],
            'orders_total' => [
                'value' => $ordersTotal,
                'basis' => '归属本店铺的售卖与租赁履约订单总数',
                'ready' => true,
            ],
            'todo_pending_shipment' => [
                'value' => $pendingShipmentCount,
                'basis' => '待出库绑定 SN 并发货的订单数',
                'ready' => true,
            ],
            'todo_pending_inspection' => [
                'value' => $returningInspectCount,
                'basis' => '客户已寄回、待验机定损与退押的租赁单数',
                'ready' => true,
            ],
            'todo_expiring_rentals' => [
                'value' => $expiringRentalsCount,
                'basis' => '未来 72 小时内租期即将届满的在租设备单数',
                'ready' => true,
            ],
            'todo_open_tickets' => [
                'value' => $openTicketsCount,
                'basis' => '待处理的售后退换修与设备维保工单数',
                'ready' => true,
            ],
            'settlement_pending_cents' => [
                'value' => $unsettledCents,
                'basis' => '已完结订单扣除 5% 平台技术服务费后的待生成结算净额（分）',
                'ready' => true,
            ],
        ];
    }

    protected function syncAccessoryMetadata(int $productId, array $data, array $modelCodes): void
    {
        AccessoryProfile::updateOrCreate(
            ['product_id' => $productId],
            [
                'summary'         => trim((string) ($data['short_description'] ?? $data['name'] ?? '')),
                'install_method'  => in_array(($data['install_method'] ?? 'self'), AccessoryProfile::INSTALL_METHODS, true)
                    ? $data['install_method']
                    : AccessoryProfile::INSTALL_SELF,
                'warranty_months' => max(0, (int) ($data['warranty_months'] ?? 12)),
                'lead_time_days'  => max(1, (int) ($data['lead_time_days'] ?? 3)),
                'official'        => (bool) ($data['official'] ?? false),
                'custom_service'  => (bool) ($data['custom_service'] ?? false),
            ]
        );

        AccessoryFitment::query()->where('product_id', $productId)->delete();

        $fitLevel = in_array(($data['fit_level'] ?? 'direct'), AccessoryFitment::SELLABLE_LEVELS, true)
            ? $data['fit_level']
            : AccessoryFitment::FIT_DIRECT;

        foreach ($modelCodes as $code) {
            AccessoryFitment::create([
                'product_id' => $productId,
                'model_code' => $code,
                'fit_level'  => $fitLevel,
                'source'     => AccessoryFitment::SOURCE_VENDOR,
            ]);
        }
    }

    protected function resolveCategoryIdForType(string $productType): ?int
    {
        $slug = match ($productType) {
            MerchantProductExt::TYPE_RENTAL_ROBOT => 'rent',
            MerchantProductExt::TYPE_ACCESSORY    => 'accessory',
            default                               => 'buy',
        };

        return Category::query()
            ->whereHas('translations', fn ($q) => $q->where('slug', $slug))
            ->value('id');
    }

    protected function defaultFamilyId(): int
    {
        return (int) (DB::table('attribute_families')->orderBy('id')->value('id') ?: 1);
    }

    protected function defaultInventorySourceId(): int
    {
        return (int) (DB::table('inventory_sources')->orderBy('id')->value('id') ?: 1);
    }

    protected function generateSellerCode(): string
    {
        do {
            $code = 'MER-'.strtoupper(Str::random(8));
        } while (Seller::query()->where('code', $code)->exists());

        return $code;
    }

    protected function generateShopCode(): string
    {
        do {
            $code = 'SHOP-'.strtoupper(Str::random(6));
        } while (MerchantShop::query()->where('code', $code)->exists());

        return $code;
    }

    protected function generateSku(string $productType): string
    {
        $prefix = match ($productType) {
            MerchantProductExt::TYPE_RENTAL_ROBOT => 'RENT-',
            MerchantProductExt::TYPE_ACCESSORY    => 'ACC-',
            default                               => 'SALE-',
        };

        do {
            $sku = $prefix.strtoupper(Str::random(8));
        } while (ProductProxy::modelClass()::query()->where('sku', $sku)->exists());

        return $sku;
    }
}
