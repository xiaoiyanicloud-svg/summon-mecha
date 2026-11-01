<?php

namespace Webkul\Shop\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantProductExt;
use Webkul\Shop\Models\PortalBackendRegistry;
use Webkul\Shop\Services\Merchant\MerchantTenantService;

class PortalAndMerchantBackendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 确保新增表已迁移（Additive migration 幂等安全）
        $migration1 = require __DIR__.'/../../src/Database/Migrations/2026_11_01_000002_create_portal_backends_and_merchant_foundation_tables.php';
        $migration1->up();

        $migration2 = require __DIR__.'/../../src/Database/Migrations/2026_11_01_000003_create_merchant_deep_closed_loop_tables.php';
        $migration2->up();
    }

    protected function createTestCustomer(string $prefix = 'user'): Customer
    {
        $uniq = uniqid($prefix.'_', true);

        return Customer::query()->create([
            'first_name'        => '测试',
            'last_name'         => $prefix,
            'email'             => $uniq.'@example.com',
            'password'          => Hash::make('Secret123!'),
            'status'            => 1,
            'is_verified'       => 1,
            'customer_group_id' => 1,
            'channel_id'        => 1,
        ]);
    }

    public function test_portal_backends_api_returns_dynamic_registry_and_permission_states(): void
    {
        // 1. 普通买家（未开通商家权限）访问 GET /api/portal/backends
        $buyer = $this->createTestCustomer('buyer');

        $res = $this->actingAs($buyer, 'customer')
            ->getJson('/api/portal/backends');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.authenticated', true)
            ->assertJsonPath('meta.user_id', $buyer->id);

        $backends = collect($res->json('data'))->keyBy('code');

        $this->assertTrue($backends->has('merchant_admin'));
        $this->assertTrue($backends->has('developer_skill'));
        $this->assertTrue($backends->has('scene_partner_portal'));

        // 普通买家无商家权限：merchant_admin 置灰并携带 disabledReason
        $merchantEntry = $backends->get('merchant_admin');
        $this->assertTrue($merchantEntry['enabled']);
        $this->assertFalse($merchantEntry['hasPermission']);
        $this->assertFalse($merchantEntry['canEnter']);
        $this->assertNotEmpty($merchantEntry['disabledReason']);
        $this->assertSame('/merchant', $merchantEntry['routeUrl']);

        // 开发者技能后台保持兼容可用
        $devEntry = $backends->get('developer_skill');
        $this->assertTrue($devEntry['enabled']);
        $this->assertTrue($devEntry['hasPermission']);
        $this->assertTrue($devEntry['canEnter']);
        $this->assertSame('/dev', $devEntry['routeUrl']);

        // 第三个后台（配置化占位）：enabled=false 置灰并带原因
        $thirdEntry = $backends->get('scene_partner_portal');
        $this->assertFalse($thirdEntry['enabled']);
        $this->assertFalse($thirdEntry['canEnter']);
        $this->assertNotEmpty($thirdEntry['disabledReason']);

        // 2. 验证数据库注册表覆盖排序与动态新增第四个后台（零前端硬编码）
        PortalBackendRegistry::query()->updateOrCreate(
            ['code' => 'custom_ops_portal'],
            [
                'name'            => '城市运维中心后台',
                'description'     => '配置化动态扩展的第四个后台入口',
                'icon'            => 'cube',
                'route_url'       => '/ops-portal',
                'permissions'     => ['ops.portal.access'],
                'enabled'         => false,
                'disabled_reason' => '三期开放城市运维商入驻',
                'sort'            => 5,
            ]
        );

        $resAfterOverride = $this->actingAs($buyer, 'customer')
            ->getJson('/api/portal/backends');

        $listAfter = $resAfterOverride->json('data');
        $this->assertSame('custom_ops_portal', $listAfter[0]['code']);
        $this->assertSame('城市运维中心后台', $listAfter[0]['name']);
    }

    public function test_portal_backend_click_tracking_writes_immutable_operation_log(): void
    {
        $customer = $this->createTestCustomer('tracker');

        $res = $this->actingAs($customer, 'customer')
            ->postJson('/api/portal/backends/track', [
                'backend_code' => 'merchant_admin',
                'source'       => 'mine_portal_selector',
                'timestamp'    => '2026-11-01T06:30:00Z',
            ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recorded', true)
            ->assertJsonPath('data.backend_code', 'merchant_admin')
            ->assertJsonPath('data.user_id', $customer->id);

        $log = DB::table('operation_log')
            ->where('action', 'portal.backend.click')
            ->where('actor_id', $customer->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('customer', $log->actor_type);
        $payload = json_decode((string) $log->payload, true);
        $this->assertSame('merchant_admin', $payload['backend_code'] ?? null);
    }

    public function test_unauthorized_user_cannot_bypass_route_guard_via_direct_url_or_api(): void
    {
        $unauthorizedUser = $this->createTestCustomer('unauth');

        // 1. 直接输入 /merchant URL（Web SSR）—— 必须返回 403 拦截，绝不放行
        $webRes = $this->actingAs($unauthorizedUser, 'merchant')
            ->get('/merchant');
        $webRes->assertStatus(403);
        $webRes->assertSee('暂无商家后台访问权限');

        // 2. 直接输入 /merchant/onboarding URL —— 同样返回 403 拦截
        $onboardRes = $this->actingAs($unauthorizedUser, 'merchant')
            ->get('/merchant/onboarding');
        $onboardRes->assertStatus(403);

        // 3. 直接调用 /api/merchant/context —— 返回 403 MERCHANT_ACCESS_FORBIDDEN
        $apiRes = $this->actingAs($unauthorizedUser, 'customer')
            ->getJson('/api/merchant/context');
        $apiRes->assertStatus(403)
            ->assertJsonPath('code', 'MERCHANT_ACCESS_FORBIDDEN');
    }

    public function test_multi_tenant_isolation_product_lifecycle_l3_guard_and_optimistic_lock(): void
    {
        /** @var MerchantTenantService $tenantService */
        $tenantService = app(MerchantTenantService::class);

        // 创建两个独立商户 A 与 B
        $userA = $this->createTestCustomer('merchant_a');
        $userB = $this->createTestCustomer('merchant_b');

        $onboardA = $tenantService->onboard($userA, [
            'company_name'   => '甲机甲租赁有限公司',
            'shop_name'      => '甲机甲旗舰店',
            'contact_name'   => '张甲',
            'contact_phone'  => '13800000001',
            'business_types' => ['sale_robot', 'rental_robot', 'accessory'],
        ]);
        $shopA = $onboardA['shop'];

        $onboardB = $tenantService->onboard($userB, [
            'company_name'   => '乙智能设备有限公司',
            'shop_name'      => '乙智能专营店',
            'contact_name'   => '李乙',
            'contact_phone'  => '13800000002',
            'business_types' => ['sale_robot', 'rental_robot', 'accessory'],
        ]);
        $shopB = $onboardB['shop'];

        $this->assertNotEquals($shopA->seller_id, $shopB->seller_id);

        // 准备机型主数据：1 个 active 真机 + 1 个 display_only (L3 仅展示机型)
        if (DB::getSchemaBuilder()->hasTable('device_model')) {
            DB::table('device_model')->updateOrInsert(
                ['model_code' => 'unitree_g1_pro_test'],
                [
                    'model_name'  => 'Unitree G1 Pro 测试真机',
                    'brand'       => 'Unitree',
                    'vendor_code' => 'unitree',
                    'status'      => 'active',
                    'sort_order'  => 10,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
            DB::table('device_model')->updateOrInsert(
                ['model_code' => 'concept_mecha_l3_test'],
                [
                    'model_name'  => '概念机甲 L3 仅展示',
                    'brand'       => 'Summon',
                    'vendor_code' => 'summon',
                    'status'      => 'display_only',
                    'sort_order'  => 90,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );

            // 商户 A 尝试绑定 L3 仅展示机型 —— 必须被 422 MODEL_L3_DISPLAY_ONLY 拦截
            $l3Res = $this->actingAs($userA, 'customer')
                ->postJson('/api/merchant/products', [
                    'name'              => '违规绑定L3概念机配件',
                    'product_type'      => 'accessory',
                    'price_cents'       => 19900,
                    'compatible_models' => ['concept_mecha_l3_test'],
                ]);

            $l3Res->assertStatus(422)
                ->assertJsonPath('code', 'MODEL_L3_DISPLAY_ONLY');
        }

        // 商户 A 正常创建一台机器人租赁商品（整数分存储：日租 999.00 元 = 99900 分，押金 5000.00 元 = 500000 分）
        $createRes = $this->actingAs($userA, 'customer')
            ->postJson('/api/merchant/products', [
                'name'              => 'Unitree G1 商业巡展租赁版',
                'sku'               => 'MCH-A-RENT-001',
                'product_type'      => 'rental_robot',
                'fulfillment_type'  => 'rental',
                'price_cents'       => 99900,
                'deposit_cents'     => 500000,
                'sellable_stock'    => 0,
                'rentable_stock'    => 6,
                'compatible_models' => DB::getSchemaBuilder()->hasTable('device_model') ? ['unitree_g1_pro_test'] : [],
                'rental_plans'      => [
                    ['period' => 'day', 'days' => 1, 'price_cents' => 99900],
                    ['period' => 'week', 'days' => 7, 'price_cents' => 599900],
                ],
            ]);

        $createRes->assertOk()
            ->assertJsonPath('data.review_status', 'draft')
            ->assertJsonPath('data.shelf_status', 'off_shelf')
            ->assertJsonPath('data.price_cents', 99900)
            ->assertJsonPath('data.deposit_cents', 500000)
            ->assertJsonPath('data.rentable_stock', 6)
            ->assertJsonPath('data.seller_id', $shopA->seller_id);

        $productIdA = (int) $createRes->json('data.product_id');

        // 未审核通过前直接上架 —— 必须被 409 CONFLICT 拒绝
        $prematureShelf = $this->actingAs($userA, 'customer')
            ->postJson("/api/merchant/products/{$productIdA}/on-shelf");
        $prematureShelf->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT');

        // 提交审核并自动初审通过 -> 上架 -> 验证同步至 Bagisto product_flat
        $submitRes = $this->actingAs($userA, 'customer')
            ->postJson("/api/merchant/products/{$productIdA}/submit-review", [
                'auto_approve' => true,
            ]);
        $submitRes->assertOk()
            ->assertJsonPath('data.review_status', 'approved');

        $shelfRes = $this->actingAs($userA, 'customer')
            ->postJson("/api/merchant/products/{$productIdA}/on-shelf");
        $shelfRes->assertOk()
            ->assertJsonPath('data.shelf_status', 'on_shelf');

        $flatRow = DB::table('product_flat')->where('product_id', $productIdA)->first();
        $this->assertNotNull($flatRow);
        $this->assertEquals(1, (int) $flatRow->status);

        // ── 严格跨租户防越权（IDOR 测试）：商户 B 尝试读取/修改/下架/改库存商户 A 的商品 ──
        $this->actingAs($userB, 'customer')
            ->getJson("/api/merchant/products/{$productIdA}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'PRODUCT_NOT_FOUND');

        $this->actingAs($userB, 'customer')
            ->putJson("/api/merchant/products/{$productIdA}", ['name' => '越权篡改名称'])
            ->assertStatus(404);

        $this->actingAs($userB, 'customer')
            ->postJson("/api/merchant/products/{$productIdA}/off-shelf")
            ->assertStatus(404);

        $this->actingAs($userB, 'customer')
            ->putJson("/api/merchant/inventory/{$productIdA}", ['rentable_stock' => 999])
            ->assertStatus(404);

        // ── 乐观锁并发冲突测试：使用旧版本号更新库存返回 409 CONFLICT ──
        $currentVersion = (int) MerchantProductExt::query()->where('product_id', $productIdA)->value('version');

        $invOk = $this->actingAs($userA, 'customer')
            ->putJson("/api/merchant/inventory/{$productIdA}", [
                'rentable_stock'    => 8,
                'rented_locked_qty' => 2,
                'version'           => $currentVersion,
                'reason'            => '新到货 2 台 G1',
            ]);
        $invOk->assertOk()
            ->assertJsonPath('data.rentable_stock', 8)
            ->assertJsonPath('data.version', $currentVersion + 1);

        // 再次传入旧 version=$currentVersion —— 触发乐观锁冲突 409
        $invConflict = $this->actingAs($userA, 'customer')
            ->putJson("/api/merchant/inventory/{$productIdA}", [
                'rentable_stock' => 20,
                'version'        => $currentVersion,
            ]);
        $invConflict->assertStatus(409)
            ->assertJsonPath('code', 'CONFLICT');

        // ── RBAC 角色最小权限校验：仓管角色 warehouse 不可上下架商品 ──
        $warehouseUser = $this->createTestCustomer('warehouse_staff');
        MerchantMember::query()->create([
            'shop_id'     => $shopA->id,
            'seller_id'   => $shopA->seller_id,
            'customer_id' => $warehouseUser->id,
            'role'        => 'warehouse',
            'status'      => 'active',
        ]);

        // 仓管可以调库存
        $this->actingAs($warehouseUser, 'customer')
            ->putJson("/api/merchant/inventory/{$productIdA}", [
                'rentable_stock' => 9,
            ])
            ->assertOk();

        // 仓管尝试下架商品 —— 403 MERCHANT_PERMISSION_DENIED
        $this->actingAs($warehouseUser, 'customer')
            ->postJson("/api/merchant/products/{$productIdA}/off-shelf")
            ->assertStatus(403)
            ->assertJsonPath('code', 'MERCHANT_PERMISSION_DENIED');

        // ── 审计日志核验 ──
        $auditRes = $this->actingAs($userA, 'customer')
            ->getJson('/api/merchant/audit-logs');
        $auditRes->assertOk();
        $actions = collect($auditRes->json('data'))->pluck('action')->all();
        $this->assertContains('merchant.product.create', $actions);
        $this->assertContains('merchant.product.on_shelf', $actions);
        $this->assertContains('merchant.inventory.adjust', $actions);
    }

    public function test_web_ssr_workspace_multi_shop_switching_and_dev_portal_coexistence(): void
    {
        /** @var MerchantTenantService $tenantService */
        $tenantService = app(MerchantTenantService::class);

        $merchantUser = $this->createTestCustomer('ssr_owner');
        $onboard = $tenantService->onboard($merchantUser, [
            'company_name'   => '星际机甲集团',
            'shop_name'      => '星际一号旗舰店',
            'contact_name'   => '王店长',
            'contact_phone'  => '13900001111',
            'business_types' => ['sale_robot', 'rental_robot', 'accessory'],
        ]);

        $shop1 = $onboard['shop'];
        $shop2 = $tenantService->createShop($onboard['profile'], $merchantUser, [
            'shop_name'      => '星际二号华东租赁中心',
            'business_types' => ['rental_robot'],
        ]);

        // 1. 已入驻商家在后台选择入口看到 merchant_admin 为可点击（hasPermission=true, canEnter=true）
        $portalRes = $this->actingAs($merchantUser, 'customer')
            ->getJson('/api/portal/backends');
        $portalRes->assertOk();
        $merchantBackend = collect($portalRes->json('data'))->firstWhere('code', 'merchant_admin');
        $this->assertTrue($merchantBackend['hasPermission']);
        $this->assertTrue($merchantBackend['canEnter']);
        $this->assertNull($merchantBackend['disabledReason']);

        // 2. 访问商家后台 Web SSR 各核心页面
        $this->actingAs($merchantUser, 'merchant')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('星际一号旗舰店');

        $this->actingAs($merchantUser, 'merchant')
            ->get('/merchant/products')
            ->assertOk()
            ->assertSee('商品与租赁目录');

        $this->actingAs($merchantUser, 'merchant')
            ->get('/merchant/inventory')
            ->assertOk()
            ->assertSee('双轨库存中心');

        // 3. 切换至二号分店（多店铺切换）
        $switchRes = $this->actingAs($merchantUser, 'customer')
            ->postJson('/api/merchant/shops/switch', [
                'shop_id' => $shop2->id,
            ]);
        $switchRes->assertOk()
            ->assertJsonPath('data.active_shop.id', $shop2->id)
            ->assertJsonPath('data.active_shop.seller_id', $shop2->seller_id);

        // 4. 验证已有 /dev 开发者技能后台完全不受影响（共存验证）
        $this->get('/dev/login')->assertOk();
    }

    public function test_l2_l3_l4_deep_closed_loop_skus_rental_plans_orders_inspection_after_sales_settlements_and_invoices(): void
    {
        /** @var MerchantTenantService $tenantService */
        $tenantService = app(MerchantTenantService::class);

        $owner = $this->createTestCustomer('deep_owner');
        $staff = $this->createTestCustomer('deep_staff');

        $onboard = $tenantService->onboard((int) $owner->id, [
            'company_name'   => '深蓝具身机器人租赁集团',
            'shop_name'      => '深蓝旗舰履约中心',
            'contact_name'   => '陈总',
            'contact_phone'  => '13700008888',
            'business_types' => ['sale_robot', 'rental_robot', 'accessory'],
            'timezone'       => 'Asia/Shanghai',
        ]);
        $shop = $onboard['shop'];

        // 1. 创建商品草稿
        $prodRes = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/products', [
                'name'             => 'Unitree G1 赛事旗舰租赁版',
                'sku'              => 'DEEP-G1-001',
                'product_type'     => 'rental_robot',
                'fulfillment_type' => 'rental',
                'price_cents'      => 100000, // 1000 元/天
                'deposit_cents'    => 200000, // 2000 元押金
                'sellable_stock'   => 2,
                'rentable_stock'   => 4,
            ]);
        $prodRes->assertOk();
        $productId = (int) $prodRes->json('data.product_id');

        // 2. L3 多规格 SKU 矩阵：新增两个 SKU 并验证自动汇总回写 SPU 库存
        $sku1Res = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/skus', [
                'product_id'       => $productId,
                'sku_code'         => 'DEEP-G1-STD',
                'name'             => '标准算力版',
                'spec_json'        => ['算力' => '40TOPS'],
                'price_cents'      => 100000,
                'daily_rent_cents' => 100000,
                'deposit_cents'    => 200000,
                'sellable_stock'   => 3,
                'rentable_stock'   => 5,
            ]);
        $sku1Res->assertOk()->assertJsonPath('data.sku_code', 'DEEP-G1-STD');

        $sku2Res = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/skus', [
                'product_id'       => $productId,
                'sku_code'         => 'DEEP-G1-PRO',
                'name'             => '激光雷达高算力版',
                'spec_json'        => ['算力' => '100TOPS'],
                'price_cents'      => 150000,
                'daily_rent_cents' => 120000,
                'deposit_cents'    => 300000,
                'sellable_stock'   => 2,
                'rentable_stock'   => 3,
            ]);
        $sku2Res->assertOk();

        // 验证 SPU 库存已自动汇总：初始默认 SKU (2售/4租) + 新增 2 个 SKU (3+2售 / 5+3租) = 7 可售、12 可租
        $detailAfterSku = $this->actingAs($owner, 'customer')
            ->getJson("/api/merchant/products/{$productId}");
        $detailAfterSku->assertOk()
            ->assertJsonPath('data.sellable_stock', 7)
            ->assertJsonPath('data.rentable_stock', 12);

        // 3. L3 租赁阶梯方案与押金规则闭环
        $planRes = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/rental-plans', [
                'product_id'       => $productId,
                'name'             => '周租/月租阶梯优享方案',
                'period_unit'      => 'day',
                'min_days'         => 3,
                'max_days'         => 60,
                'base_price_cents' => 100000,
                'price_tiers'      => [
                    ['min_days' => 7, 'daily_cents' => 85000],
                    ['min_days' => 30, 'daily_cents' => 70000],
                ],
                'deposit_rule'     => [
                    'mode'                => 'full',
                    'deposit_cents'       => 200000,
                    'overdue_daily_cents' => 30000,
                    'buyout_price_cents'  => 9900000,
                ],
            ]);
        $planRes->assertOk()
            ->assertJsonPath('data.period_unit', 'day')
            ->assertJsonPath('data.deposit_rule.overdue_daily_cents', 30000);

        // 4. L4 审核状态机全分支：草稿 -> 提审 (in_review) -> 驳回 (rejected + 原因) -> 修改 -> 重提审 -> 通过 (approved) -> 上架
        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/products/{$productId}/submit-review", ['auto_approve' => false])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'in_review');

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/products/{$productId}/review-decision", [
                'decision'      => 'reject',
                'reject_reason' => '请补充设备续航与额定负载参数说明',
            ])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'rejected')
            ->assertJsonPath('data.reject_reason', '请补充设备续航与额定负载参数说明');

        // 补充参数并重新提审通过 + 上架
        $this->actingAs($owner, 'customer')
            ->putJson("/api/merchant/products/{$productId}", [
                'param_specs' => ['自由度/关节' => '23 DoF', '续航时间' => '2.5h'],
            ])
            ->assertOk();

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/products/{$productId}/submit-review", ['auto_approve' => false])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'in_review');

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/products/{$productId}/review-decision", ['decision' => 'approve'])
            ->assertOk()
            ->assertJsonPath('data.review_status', 'approved');

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/products/{$productId}/on-shelf")
            ->assertOk()
            ->assertJsonPath('data.shelf_status', 'on_shelf');

        // 5. L2/L3/L4 多仓库与维保库存调度闭环
        $whRes = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/warehouses', [
                'name'   => '苏州昆山赛训备件分仓',
                'region' => '华东区',
            ]);
        $whRes->assertOk()->assertJsonPath('data.name', '苏州昆山赛训备件分仓');

        // 转入维保 1 台（可租 12 -> 11，维保 0 -> 1）
        $this->actingAs($owner, 'customer')
            ->putJson("/api/merchant/inventory/{$productId}", [
                'action_type' => 'maintenance_lock',
                'qty'         => 1,
                'reason'      => '出库前激光雷达标定检修',
            ])
            ->assertOk()
            ->assertJsonPath('data.rentable_stock', 11)
            ->assertJsonPath('data.maintenance_qty', 1);

        // 维保修复释放 1 台（可租 11 -> 12，维保 1 -> 0）
        $this->actingAs($owner, 'customer')
            ->putJson("/api/merchant/inventory/{$productId}", [
                'action_type' => 'maintenance_release',
                'qty'         => 1,
                'reason'      => '标定完成回补可租池',
            ])
            ->assertOk()
            ->assertJsonPath('data.rentable_stock', 12)
            ->assertJsonPath('data.maintenance_qty', 0);

        // 6. L2/L3/L4 租赁订单全链路闭环：下单锁库存&签合同 -> 发货绑SN -> 归还登记 -> 验机重损扣押金&自动转维保工单 -> 办结工单释放库存 -> 完结订单
        $orderRes = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/orders', [
                'product_id'       => $productId,
                'fulfillment_type' => 'rental',
                'buyer_name'       => '杭州具身赛训俱乐部',
                'buyer_phone'      => '13611112222',
                'qty'              => 1,
                'rental_days'      => 5, // 5天 × 1000元 = 5000元 (500000分)，押金 2000元 (200000分)
            ]);
        $orderRes->assertOk()
            ->assertJsonPath('data.status', 'pending_shipment')
            ->assertJsonPath('data.contract_status', 'signed')
            ->assertJsonPath('data.total_amount_cents', 500000)
            ->assertJsonPath('data.deposit_cents', 200000);
        $orderId = (int) $orderRes->json('data.id');

        // 验证下单后可租库存 12 -> 11，在租锁定 0 -> 1
        $this->actingAs($owner, 'customer')
            ->getJson("/api/merchant/products/{$productId}")
            ->assertJsonPath('data.rentable_stock', 11)
            ->assertJsonPath('data.rented_locked_qty', 1);

        // 发货并绑定机器序列号 SN
        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/orders/{$orderId}/ship", [
                'carrier'     => 'SF-Express',
                'tracking_no' => 'SF99887766',
                'device_sn'   => 'SN-G1-2026-088',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_use')
            ->assertJsonPath('data.device_sn', 'SN-G1-2026-088');

        // 登记客户归还
        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/orders/{$orderId}/return", [
                'return_carrier'     => 'SF-Return',
                'return_tracking_no' => 'SF11223344',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'returned_inspecting');

        // 归还验机：判定 major_damage（严重受损），定损扣款 300 元（30000 分）
        // 预期：应退押金 = 200000 - 30000 = 170000 分；在租锁定 1 -> 0，维保锁定 0 -> 1；自动生成 1 张 maintenance 工单
        $inspectRes = $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/orders/{$orderId}/inspect", [
                'inspection_result'   => 'major_damage',
                'inspection_notes'    => '左臂谐波减速器外壳磕碰需返修',
                'damage_deduct_cents' => 30000,
                'overdue_fee_cents'   => 0,
            ]);
        $inspectRes->assertOk()
            ->assertJsonPath('data.status', 'inspected')
            ->assertJsonPath('data.damage_deduct_cents', 30000)
            ->assertJsonPath('data.refund_deposit_cents', 170000);

        $this->actingAs($owner, 'customer')
            ->getJson("/api/merchant/products/{$productId}")
            ->assertJsonPath('data.rentable_stock', 11)
            ->assertJsonPath('data.rented_locked_qty', 0)
            ->assertJsonPath('data.maintenance_qty', 1);

        // 查询自动生成的维保工单并办结释放维保库存
        $ticketsRes = $this->actingAs($owner, 'customer')
            ->getJson('/api/merchant/after-sales?ticket_type=maintenance');
        $ticketsRes->assertOk();
        $tickets = $ticketsRes->json('data');
        $this->assertNotEmpty($tickets);
        $ticketId = (int) $tickets[0]['id'];
        $this->assertSame('SN-G1-2026-088', $tickets[0]['device_sn']);

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/after-sales/{$ticketId}/resolve", [
                'status'                    => 'resolved',
                'resolution_notes'          => '已更换左臂外壳并通过标定测试',
                'release_maintenance_stock' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.locks_maintenance_stock', false);

        // 验证维保库存已释放回可租池（可租 11 -> 12，维保 1 -> 0）
        $this->actingAs($owner, 'customer')
            ->getJson("/api/merchant/products/{$productId}")
            ->assertJsonPath('data.rentable_stock', 12)
            ->assertJsonPath('data.maintenance_qty', 0);

        // 完结订单，进入待结算池
        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/orders/{$orderId}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        // 7. L2/L3/L4 结算对账与增值税发票开具闭环
        // 订单毛额 500000 分 + 定损赔偿 30000 分 = 530000 分；5% 平台服务费 = 26500 分；应结净额 = 503500 分
        $stlRes = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/settlements', ['period_label' => '2026-11']);
        $stlRes->assertOk()
            ->assertJsonPath('data.orders_count', 1)
            ->assertJsonPath('data.gross_amount_cents', 500000)
            ->assertJsonPath('data.damage_income_cents', 30000)
            ->assertJsonPath('data.platform_fee_cents', 26500)
            ->assertJsonPath('data.net_amount_cents', 503500);
        $settlementId = (int) $stlRes->json('data.id');

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/settlements/{$settlementId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $invRes = $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/invoices', [
                'settlement_id' => $settlementId,
                'invoice_type'  => 'vat_special',
                'buyer_title'   => '杭州具身赛训俱乐部有限公司',
                'buyer_tax_no'  => '91330100MA27XXXXXX',
                'amount_cents'  => 530000,
                'tax_rate'      => '6%',
            ]);
        $invRes->assertOk()->assertJsonPath('data.status', 'applied');
        $invoiceId = (int) $invRes->json('data.id');

        $this->actingAs($owner, 'customer')
            ->postJson("/api/merchant/invoices/{$invoiceId}/issue", [
                'invoice_code_number' => '033002600111-66889900',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.invoice_code_number', '033002600111-66889900');

        // 8. L2/L3/L4 店铺时区档案与成员 RBAC 授权 + Web SSR 二三级页面可访问性验证
        $this->actingAs($owner, 'customer')
            ->putJson('/api/merchant/shops/current', [
                'shop_name' => '深蓝旗舰履约中心（全球站）',
                'timezone'  => 'Asia/Tokyo',
            ])
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Asia/Tokyo');

        $this->actingAs($owner, 'customer')
            ->postJson('/api/merchant/members', [
                'email' => $staff->email,
                'role'  => 'finance',
            ])
            ->assertOk()
            ->assertJsonPath('data.role', 'finance');

        // 验证 Web SSR 二级详情页与新增模块页全部正常渲染 200
        $this->actingAs($owner, 'merchant')
            ->get("/merchant/products/{$productId}")
            ->assertOk()
            ->assertSee('Unitree G1 赛事旗舰租赁版')
            ->assertSee('DEEP-G1-STD')
            ->assertSee('周租/月租阶梯优享方案');

        $this->actingAs($owner, 'merchant')->get('/merchant/orders')->assertOk()->assertSee('SN-G1-2026-088');
        $this->actingAs($owner, 'merchant')->get('/merchant/after-sales')->assertOk()->assertSee('售后与设备维保工单中心');
        $this->actingAs($owner, 'merchant')->get('/merchant/settlements')->assertOk()->assertSee('033002600111-66889900');
        $this->actingAs($owner, 'merchant')->get('/merchant/settings')->assertOk()->assertSee('Asia/Tokyo');
    }
}
