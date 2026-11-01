<?php

namespace Webkul\Shop\Tests\Feature\Merchant;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\MerchantInventoryLock;
use Webkul\Shop\Models\MerchantMember;
use Webkul\Shop\Models\MerchantOrder;
use Webkul\Shop\Models\MerchantProductExt;
use Webkul\Shop\Models\MerchantProfile;
use Webkul\Shop\Models\MerchantShop;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Merchant\MerchantTenantService;

class MerchantOrderFulfillmentDeepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration1 = require __DIR__.'/../../../src/Database/Migrations/2026_11_01_000002_create_portal_backends_and_merchant_foundation_tables.php';
        $migration1->up();

        $migration2 = require __DIR__.'/../../../src/Database/Migrations/2026_11_01_000003_create_merchant_deep_closed_loop_tables.php';
        $migration2->up();

        $migration3 = require __DIR__.'/../../../src/Database/Migrations/2026_11_02_000001_add_cancel_fields_to_merchant_orders.php';
        $migration3->up();
    }

    protected function createMerchantWithShop(string $prefix = 'm'): array
    {
        $uniq = uniqid($prefix.'_', true);
        $customer = Customer::query()->create([
            'first_name'        => '商家',
            'last_name'         => $prefix,
            'email'             => $uniq.'@example.com',
            'password'          => Hash::make('Secret123!'),
            'status'            => 1,
            'is_verified'       => 1,
            'customer_group_id' => 1,
            'channel_id'        => 1,
        ]);

        $sellerId = DB::table('sellers')->insertGetId([
            'code'       => 'SELLER-'.strtoupper(substr(md5($prefix.uniqid('', true)), 0, 10)),
            'name'       => '机甲机器人商户-'.$prefix,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profile = MerchantProfile::create([
            'customer_id'       => $customer->id,
            'default_seller_id' => $sellerId,
            'company_name'      => '机甲机器人商户-'.$prefix,
            'credit_code'       => '91440300MA5'.substr(strtoupper(md5($prefix)), 0, 7),
            'contact_name'      => '张商户',
            'status'            => 'active',
        ]);

        $shop = MerchantShop::create([
            'seller_id'           => $sellerId,
            'merchant_profile_id' => $profile->id,
            'code'                => 'SHOP-'.strtoupper(substr(md5($uniq), 0, 8)),
            'name'                => '总店-'.$prefix,
            'status'              => 'active',
            'timezone'            => 'Asia/Shanghai',
        ]);

        $member = MerchantMember::create([
            'seller_id'   => $sellerId,
            'shop_id'     => $shop->id,
            'customer_id' => $customer->id,
            'role'        => MerchantMember::ROLE_OWNER,
            'permissions' => ['*'],
            'status'      => 'active',
        ]);

        return compact('customer', 'sellerId', 'profile', 'shop', 'member');
    }

    public function test_merchant_order_cancellation_releases_stock_and_records_audit(): void
    {
        $m = $this->createMerchantWithShop('cancel');
        $tenantService = app(MerchantTenantService::class);

        // 建立初始商品扩展，初始可售 10，已锁 2
        $ext = MerchantProductExt::create([
            'product_id'        => 8801,
            'seller_id'         => $m['sellerId'],
            'fulfillment_type'  => 'sale',
            'sellable_stock'    => 10,
            'rentable_stock'    => 0,
            'locked_stock'      => 2,
            'maintenance_stock' => 0,
            'safety_stock'      => 2,
            'review_status'     => MerchantProductExt::REVIEW_APPROVED,
            'shelf_status'      => MerchantProductExt::SHELF_ON,
            'version'           => 1,
        ]);

        // 创建待发货订单
        $order = MerchantOrder::create([
            'order_no'         => 'ORD-20261102-001',
            'seller_id'        => $m['sellerId'],
            'shop_id'          => $m['shop']->id,
            'product_id'       => 8801,
            'fulfillment_type' => 'sale',
            'product_name'     => '工业六轴机械臂配件',
            'sku_code'         => 'SKU-ARM-01',
            'buyer_name'       => '李建华',
            'buyer_phone'      => '13812345678',
            'shipping_address' => '广东省深圳市南山区高新科技园南区T3栋801',
            'qty'              => 2,
            'unit_price_cents' => 50000,
            'subtotal_cents'   => 100000, // 1000.00 元
            'deposit_cents'    => 0,
            'status'           => MerchantOrder::STATUS_PENDING_SHIPMENT,
            'version'          => 1,
        ]);

        // 1. Web SSR 商家发起取消订单
        $res = $this->actingAs($m['customer'], 'merchant')
            ->post('/merchant/orders/'.$order->id.'/cancel', [
                'reason' => '客户修改采购计划，协商一致取消',
                'party'  => 'buyer',
            ]);

        $res->assertRedirect();

        // 验证订单状态机转移
        $order->refresh();
        $this->assertEquals(MerchantOrder::STATUS_CANCELLED, $order->status);
        $this->assertEquals('客户修改采购计划，协商一致取消', $order->cancel_reason);
        $this->assertEquals('buyer', $order->cancel_party);
        $this->assertNotNull($order->cancelled_at);

        // 验证库存原路释放：locked_stock - 2 = 0, sellable_stock + 2 = 12
        $ext->refresh();
        $this->assertEquals(0, $ext->locked_stock);
        $this->assertEquals(12, $ext->sellable_stock);

        // 验证写台账流水
        $lock = MerchantInventoryLock::query()->where('reference_no', $order->order_no)->latest()->first();
        $this->assertNotNull($lock);
        $this->assertEquals('cancel_release', $lock->action_type);
        $this->assertEquals(2, $lock->qty_change);
        $this->assertEquals(12, $lock->sellable_after);

        // 验证写操作审计日志
        $oplog = OperationLog::query()->where('action', 'merchant.order.cancel')->where('target_id', $order->id)->first();
        $this->assertNotNull($oplog);
        $this->assertEquals($m['sellerId'], $oplog->payload['seller_id']);

        // 2. 对已取消状态的订单再次取消，必须抛出 409 Conflict
        $repeatRes = $this->actingAs($m['customer'], 'merchant')
            ->post('/merchant/orders/'.$order->id.'/cancel', [
                'reason' => '再次尝试取消',
            ]);
        $repeatRes->assertSessionHas('error');
    }

    public function test_merchant_sales_metrics_and_pii_masking_timeline(): void
    {
        $m = $this->createMerchantWithShop('metrics');

        // 创建租赁商品扩展
        $ext = MerchantProductExt::create([
            'product_id'        => 8802,
            'seller_id'         => $m['sellerId'],
            'fulfillment_type'  => 'rental',
            'sellable_stock'    => 0,
            'rentable_stock'    => 5,
            'locked_stock'      => 1,
            'maintenance_stock' => 0,
            'review_status'     => MerchantProductExt::REVIEW_APPROVED,
            'shelf_status'      => MerchantProductExt::SHELF_ON,
            'version'           => 1,
        ]);

        // 订单 1：正常租赁单（履约在租中，带押金）
        $orderRental = MerchantOrder::create([
            'order_no'           => 'RNT-20261102-001',
            'seller_id'          => $m['sellerId'],
            'shop_id'            => $m['shop']->id,
            'product_id'         => 8802,
            'fulfillment_type'   => 'rental',
            'product_name'       => '仿生四足机器狗（专业版）',
            'sku_code'           => 'SKU-DOG-01',
            'buyer_name'         => '王五',
            'buyer_phone'        => '13987654321',
            'shipping_address'   => '北京市海淀区中关村南大街1号',
            'qty'                => 1,
            'rental_days'        => 30,
            'subtotal_cents'     => 300000, // 3000.00 元
            'deposit_cents'      => 500000, // 5000.00 元押金
            'status'             => MerchantOrder::STATUS_IN_USE,
            'contract_no'        => 'CTR-20261102-099',
            'contract_signed_at' => now()->subDays(5),
            'device_sn'          => 'MECHA-DOG-999',
            'carrier'            => '顺丰速运',
            'tracking_no'        => 'SF1234567890',
            'shipped_at'         => now()->subDays(4),
            'version'            => 1,
        ]);

        // 订单 2：已完结租赁单（已验机定损并完结，定损 500 元）
        $orderCompleted = MerchantOrder::create([
            'order_no'            => 'RNT-20261102-002',
            'seller_id'           => $m['sellerId'],
            'shop_id'             => $m['shop']->id,
            'product_id'          => 8802,
            'fulfillment_type'    => 'rental',
            'product_name'        => '仿生四足机器狗（专业版）',
            'sku_code'            => 'SKU-DOG-01',
            'buyer_name'          => '赵六',
            'buyer_phone'         => '13700001111',
            'shipping_address'    => '上海市浦东新区张江高科园区碧波路',
            'qty'                 => 1,
            'rental_days'         => 15,
            'subtotal_cents'      => 150000, // 1500.00 元
            'deposit_cents'       => 500000,
            'damage_deduct_cents' => 50000,  // 500.00 元定损
            'refund_deposit_cents'=> 450000, // 4500.00 元退还押金
            'status'              => MerchantOrder::STATUS_COMPLETED,
            'inspected_at'        => now()->subDays(2),
            'completed_at'        => now()->subDay(),
            'settled'             => false,
            'version'             => 1,
        ]);

        // 订单 3：已取消单（金额 800 元）
        $orderCancelled = MerchantOrder::create([
            'order_no'         => 'ORD-20261102-003',
            'seller_id'        => $m['sellerId'],
            'shop_id'          => $m['shop']->id,
            'product_id'       => 8802,
            'fulfillment_type' => 'sale',
            'product_name'     => '机器狗备用电池组',
            'sku_code'         => 'SKU-BAT-01',
            'buyer_name'       => '张三丰',
            'buyer_phone'      => '13611112222',
            'shipping_address' => '湖北省武汉市东湖高新区光谷金融港',
            'qty'              => 1,
            'subtotal_cents'   => 80000, // 800.00 元
            'status'           => MerchantOrder::STATUS_CANCELLED,
            'cancel_reason'    => '无现货，客户申请退款',
            'cancel_party'     => 'merchant',
            'cancelled_at'     => now(),
            'version'          => 1,
        ]);

        // 1. 验证看板指标：GMV, 净销售额, 在押押金池
        $tenantService = app(MerchantTenantService::class);
        $metrics = $tenantService->dashboardMetrics($m['shop']);

        // GMV = 3000 + 1500 + 800 = 5300.00
        $this->assertEquals('5300.00', $metrics['gmv']['value']);
        // 净销售额 = (3000 + 1500) + 定损 500 = 5000.00
        $this->assertEquals('5000.00', $metrics['net_sales']['value']);
        // 履约中在押押金池 = 5000.00 (只算 in_use 的 5000，completed 不在履约中)
        $this->assertEquals('5000.00', $metrics['deposit_in_custody']['value']);
        $this->assertEquals(1, $metrics['cancelled_orders']['value']);
        $this->assertEquals(1, $metrics['completed_orders']['value']);

        // 2. 验证 PII 脱敏与履约全流程时间线
        $item = $tenantService->formatOrderItem($orderRental, $m['shop']->timezone);
        $this->assertEquals('王*', $item['buyer_name_masked']);
        $this->assertEquals('139****4321', $item['buyer_phone_masked']);
        $this->assertStringContainsString('******', $item['shipping_address_masked']);

        $timeline = $item['timeline'];
        $this->assertIsArray($timeline);
        $this->assertGreaterThanOrEqual(3, count($timeline));

        // 3. 验证 REST API 接口与 Anti-IDOR 越权阻断
        $mB = $this->createMerchantWithShop('other');

        $tokenA = $m['customer']->createToken('merchant-token')->plainTextToken;
        $tokenB = $mB['customer']->createToken('merchant-token')->plainTextToken;

        // Merchant A 访问自己订单
        $apiRes = $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/merchant/orders');
        $apiRes->assertOk()
            ->assertJsonPath('data.0.buyer_name_masked', '张*丰');

        $pendingOrder = MerchantOrder::create([
            'order_no'         => 'ORD-20261102-099',
            'seller_id'        => $m['sellerId'],
            'shop_id'          => $m['shop']->id,
            'product_id'       => 8802,
            'fulfillment_type' => 'sale',
            'product_name'     => '待发货标品',
            'sku_code'         => 'SKU-099',
            'qty'              => 1,
            'subtotal_cents'   => 10000,
            'status'           => MerchantOrder::STATUS_PENDING_SHIPMENT,
            'version'          => 1,
        ]);

        // Merchant B 尝试取消 Merchant A 的待发货订单 -> 404 Not Found
        auth('merchant')->logout();
        Sanctum::actingAs($mB['customer'], ['*']);
        $mB['customer']->withAccessToken(new \Laravel\Sanctum\TransientToken);

        $stealCancel = $this->postJson('/api/merchant/orders/'.$pendingOrder->id.'/cancel', [
            'reason' => '非法越权取消',
        ]);
        $stealCancel->assertNotFound();

        // Merchant A 正常调用 API 取消
        Sanctum::actingAs($m['customer'], ['*']);
        $m['customer']->withAccessToken(new \Laravel\Sanctum\TransientToken);

        $validCancel = $this->postJson('/api/merchant/orders/'.$pendingOrder->id.'/cancel', [
            'reason' => '客户自提改为取消',
            'party'  => 'buyer',
        ]);
        $validCancel->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancel_reason', '客户自提改为取消');
    }
}
