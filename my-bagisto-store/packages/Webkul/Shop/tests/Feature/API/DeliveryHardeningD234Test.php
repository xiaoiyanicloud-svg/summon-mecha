<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Listeners\SkillDelivery;
use Webkul\Shop\Models\Device;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * 授权与交付 · D2 / D3 / D4。
 *
 * D2 · 重复 deliver() 把已激活(active)状态打回 granted —— 状态回退。
 * D3 · 监听器吞异常且无补偿;两条发放入口事务边界不一致。
 * D4 · 自报安装不落 install_record(安装事实分裂成两套)。
 */

function hardenCustomer2(): Customer
{
    return Customer::create([
        'first_name'  => 'Deliver',
        'last_name'   => 'Test',
        'email'       => 'deliver'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function hardenDevice2(Customer $customer): Device
{
    return Device::create([
        'customer_id' => $customer->id,
        'sn'          => 'D234-'.uniqid(),
        'bind_status' => Device::STATUS_BOUND,
        'bound_at'    => now(),
    ]);
}

function hardenEntitlement2(Customer $customer, ?Device $device, string $status, string $channel = 'app', ?int $versionId = null): SkillEntitlement
{
    return SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'skill_version_id' => $versionId,
        'device_id'        => $device?->id,
        'status'           => $status,
        'delivery_channel' => $channel,
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);
}

// ───────────────────────────── D2 ─────────────────────────────

it('D2 · 已 active 的授权再跑一次 deliver() 不回退为 granted', function () {
    $customer = hardenCustomer2();
    $device = hardenDevice2($customer);
    $entitlement = hardenEntitlement2($customer, $device, SkillEntitlement::STATUS_ACTIVE);

    app(SkillDeliveryManager::class)
        ->channel('app')
        ->deliver($entitlement, ['device_id' => $device->id]);

    expect($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE);
});

it('D2 · 未交付的授权仍照常推进到 granted(修复未误伤正向路径)', function () {
    $customer = hardenCustomer2();
    $device = hardenDevice2($customer);
    // granted_at 为空 = 尚未交付
    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => 900000 + random_int(1, 90000),
        'order_item_id'    => 900000 + random_int(1, 90000),
        'product_id'       => 900000 + random_int(1, 9999),
        'device_id'        => $device->id,
        'status'           => SkillEntitlement::STATUS_PENDING_BIND,
        'delivery_channel' => 'app',
    ]);

    app(SkillDeliveryManager::class)
        ->channel('app')
        ->deliver($entitlement, ['device_id' => $device->id]);

    expect($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_GRANTED);
});

// ───────────────────────────── D4 ─────────────────────────────

it('D4 · 自报安装落一行 install_record,且设备已装列表可见', function () {
    $customer = hardenCustomer2();
    $device = hardenDevice2($customer);
    $versionId = 900000 + random_int(1, 90000);
    $entitlement = hardenEntitlement2($customer, $device, SkillEntitlement::STATUS_GRANTED, 'app', $versionId);

    actingAs($customer, 'customer');

    postJson(route('shop.api.skills.confirm-install', $entitlement->id))->assertOk();

    // 人工通道真相源:heal 时 binding.did = sn_{sn}
    $did = 'sn_'.$device->sn;

    $record = InstallRecord::query()
        ->where('did', $did)
        ->where('skill_version_id', $versionId)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->entitlement_id)->toBe($entitlement->id)
        ->and($record->status)->toBe('installed');
});

// ───────────────────────────── D3 ─────────────────────────────

it('D3 · 交付失败不吞成静默:留 delivery_failed 痕迹且不让调用方炸', function () {
    $customer = hardenCustomer2();
    $device = hardenDevice2($customer);

    // 指向不存在的通道 —— 这正是配置漂移的真实故障形态
    $orderId = hskillOrder($customer);
    $itemId = hskillOrderItem($orderId, hskillProductId());

    SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => $itemId,
        'product_id'       => hskillProductId(),
        'device_id'        => $device->id,
        'status'           => SkillEntitlement::STATUS_PENDING_BIND,
        'delivery_channel' => 'no-such-channel',
    ]);

    $order = \Webkul\Sales\Models\OrderProxy::modelClass()::find($orderId);

    // 不抛异常 —— 建单不能被交付失败拖垮
    app(SkillDelivery::class)->afterOrderCreated($order);

    $ent = SkillEntitlement::query()->where('order_item_id', $itemId)->first();
    $meta = (array) $ent->meta;

    expect($meta['delivery_failed'] ?? null)->not->toBeNull()
        ->and($meta['delivery_failed']['class'] ?? null)->not->toBeNull();
});

// ── skill 分类夹具:让 skillProductIds() 命中,使 grantForOrder 真正进入逐条发放 ──

function hskillProductId(): int
{
    // RefreshDatabase 下每个用例的库是干净的 —— 每次现建,不能跨用例缓存静态 id。
    $categoryId = DB::table('category_translations')->where('slug', 'skill')->value('category_id');

    if (! $categoryId) {
        $categoryId = DB::table('categories')->insertGetId([
            'status'     => 1,
            '_lft'       => 1,
            '_rgt'       => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'locale_id'   => DB::table('locales')->value('id') ?: 1,
            'locale'      => 'en',
            'name'        => '技能',
            'slug'        => 'skill',
        ]);
    }

    $id = DB::table('products')->insertGetId([
        'sku'                 => 'D234-'.uniqid(),
        'type'                => 'simple',
        'attribute_family_id' => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_categories')->insert([
        'product_id'  => $id,
        'category_id' => $categoryId,
    ]);

    return $id;
}

function hskillOrderItem(int $orderId, int $productId): int
{
    return DB::table('order_items')->insertGetId([
        'order_id'    => $orderId,
        'product_id'  => $productId,
        'type'        => 'simple',
        'name'        => '技能包',
        'sku'         => 'SKU'.uniqid(),
        'qty_ordered' => 1,
        'price'       => 100,
        'total'       => 100,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

function hskillOrder(Customer $customer): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'D234'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => 100.00,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

it('D3 · redeliver() 按订单补发且幂等(已交付不重复)', function () {
    $customer = hardenCustomer2();
    $orderId = hskillOrder($customer);
    hskillOrderItem($orderId, hskillProductId());

    $listener = app(SkillDelivery::class);

    $first = $listener->redeliver($orderId);
    $second = $listener->redeliver($orderId);

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and(SkillEntitlement::query()->where('order_id', $orderId)->count())->toBe(1);
});
