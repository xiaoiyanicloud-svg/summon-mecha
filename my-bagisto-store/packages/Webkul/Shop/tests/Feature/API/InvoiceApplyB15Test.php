<?php

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Invoice;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * B-15 · 用户侧发票申请入口（计划 §4.18）。
 *
 * 勘察结论（2026-10-14）：无 `/api/invoices` 路由、API 目录无发票控制器
 * （Admin 只有只读台账 `Ops\InvoiceController`）；而 `pages/mine/index.vue`
 * 仍硬编码 5 张 `INV-2026-00x` 假发票当真实数据展示 —— 本项目铁律里的"会说谎"。
 *
 * 本文件取证用户侧入口，并盯住三条最容易出错的地方：
 *   ① 只能给**本人、已支付**的订单开票；② 企业抬头必须有税号；③ 已开具不许改。
 */

function b15Customer(): Customer
{
    return Customer::create([
        'first_name'  => '张',
        'last_name'   => '三',
        'email'       => 'b15'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function b15Order(?Customer $customer, string $status = 'processing', float $total = 199.00): int
{
    return DB::table('orders')->insertGetId([
        'increment_id'        => 'B15'.uniqid(),
        'customer_id'         => $customer?->id,
        'customer_first_name' => '张',
        'customer_last_name'  => '三',
        'status'              => $status,
        'grand_total'         => $total,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

// ==================== 读 ====================

it('B-15 · 没有开票记录 → 返回空列表（不再拿假数据糊弄）', function () {
    $customer = b15Customer();

    actingAs($customer, 'customer');

    getJson(route('shop.api.invoices.index'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('B-15 · 支付后自动登记的开票记录 → 用户能查到', function () {
    $customer = b15Customer();
    $orderId = b15Order($customer);

    // 复刻支付回调那条链：落一条登记
    app(\Webkul\Shop\Services\Billing\InvoiceService::class)
        ->issueForOrder(\Webkul\Sales\Models\OrderProxy::modelClass()::find($orderId));

    actingAs($customer, 'customer');

    getJson(route('shop.api.invoices.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', Invoice::STATUS_APPLIED)
        ->assertJsonPath('data.0.order_ids.0', $orderId);
});

it('B-15 · 只返回本人的发票（不会看到别人的）', function () {
    $me = b15Customer();
    $other = b15Customer();
    b15Order($other); // 别人的订单

    $invoice = Invoice::create([
        'customer_id' => $other->id,
        'order_ids'   => [1],
        'type'        => Invoice::TYPE_PERSONAL,
        'title'       => '别人',
        'amount'      => 100,
        'status'      => Invoice::STATUS_APPLIED,
    ]);

    actingAs($me, 'customer');

    getJson(route('shop.api.invoices.index'))->assertJsonCount(0, 'data');
});

// ==================== 写 ====================

it('B-15 · 对已支付订单申请开票 → 落登记并回填抬头/税号', function () {
    $customer = b15Customer();
    $orderId = b15Order($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.invoices.store'), [
        'order_id' => $orderId,
        'type'     => 'company',
        'title'    => '召唤机甲（上海）科技有限公司',
        'tax_no'   => '91310000MA1FL0XXXX',
    ])
        ->assertStatus(201)
        ->assertJsonPath('data.type', 'company')
        ->assertJsonPath('data.title', '召唤机甲（上海）科技有限公司')
        ->assertJsonPath('data.tax_no', '91310000MA1FL0XXXX')
        // 状态停在"已登记待开具"：真实开具未接入，不许写成 issued
        ->assertJsonPath('data.status', Invoice::STATUS_APPLIED);

    expect(Invoice::query()->where('customer_id', $customer->id)->count())->toBe(1);
});

it('B-15 · 企业抬头缺税号 → 422（不静默落空）', function () {
    $customer = b15Customer();
    $orderId = b15Order($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.invoices.store'), [
        'order_id' => $orderId,
        'type'     => 'company',
        'title'    => '某公司',
    ])->assertStatus(422);

    expect(Invoice::query()->count())->toBe(0);
});

it('B-15 · 未支付订单 → 422，不开票', function () {
    $customer = b15Customer();
    $orderId = b15Order($customer, 'pending', 88.00);

    actingAs($customer, 'customer');

    postJson(route('shop.api.invoices.store'), [
        'order_id' => $orderId,
        'type'     => 'personal',
        'title'    => '张三',
    ])->assertStatus(422);

    expect(Invoice::query()->count())->toBe(0);
});

it('B-15 · 别人的订单 → 404（不泄露订单是否存在）', function () {
    $me = b15Customer();
    $other = b15Customer();
    $orderId = b15Order($other);

    actingAs($me, 'customer');

    postJson(route('shop.api.invoices.store'), [
        'order_id' => $orderId,
        'type'     => 'personal',
        'title'    => '张三',
    ])
        ->assertStatus(404)
        ->assertJsonPath('code', 'ORDER_NOT_FOUND');
});

it('B-15 · 重复申请 → 改同一条登记（不重复开票）', function () {
    $customer = b15Customer();
    $orderId = b15Order($customer);

    actingAs($customer, 'customer');

    postJson(route('shop.api.invoices.store'), ['order_id' => $orderId, 'type' => 'personal', 'title' => '张三'])->assertStatus(201);
    postJson(route('shop.api.invoices.store'), [
        'order_id' => $orderId, 'type' => 'company', 'title' => '某公司', 'tax_no' => 'TAX123',
    ])->assertStatus(201);

    $rows = Invoice::query()->where('customer_id', $customer->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->type)->toBe('company')
        ->and($rows->first()->tax_no)->toBe('TAX123');
});

it('B-15 · 已开具的发票不允许改 → 409', function () {
    $customer = b15Customer();
    $orderId = b15Order($customer);

    $invoice = Invoice::create([
        'customer_id' => $customer->id,
        'order_ids'   => [$orderId],
        'type'        => Invoice::TYPE_PERSONAL,
        'title'       => '张三',
        'amount'      => 199,
        'status'      => Invoice::STATUS_ISSUED,
        'issued_at'   => now(),
    ]);

    actingAs($customer, 'customer');

    postJson(route('shop.api.invoices.store'), ['order_id' => $orderId, 'type' => 'personal', 'title' => '李四'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CONFLICT');

    expect($invoice->fresh()->title)->toBe('张三');
});
