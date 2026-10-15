<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Invoice;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Services\Billing\InvoiceService;
use Webkul\Shop\Services\Payment\MockGateway;

use function Pest\Laravel\postJson;

/**
 * M9 · 发票（表 `invoice`，中国的税务发票/开票登记）。
 *
 * 计划里四张表只有「发票」这一项现在就该做：**用户买完要开票，是 M1 可售的组成部分**。
 *
 * 本文件取证的重点不是"有没有一条记录"，而是三条容易做假的地方：
 *   ① 记录是**支付回调**这条真实链路上落下来的（不是测试自己 insert 出来的）
 *   ② 重放不会多开一张 —— 且幂等的承重墙是回调里那把订单行锁（json 列加不了唯一键）
 *   ③ 开票写不进去时**整笔回调回滚**：已交付却查无开票登记，与 D3 那类
 *      "主链路成功、旁路静默缺失"是同一形状的缺陷
 */

function m9Customer(): Customer
{
    return Customer::create([
        'first_name'  => '开票',
        'last_name'   => '测试',
        'email'       => 'm9inv'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 技能类商品（挂到 skill 分类下）—— 交付链路才会真的走到授权发放。
 */
function m9SkillProduct(): int
{
    $categoryId = DB::table('category_translations')
        ->join('categories', 'categories.id', '=', 'category_translations.category_id')
        ->where('category_translations.slug', 'skill')
        ->value('categories.id');

    $productId = DB::table('products')->insertGetId([
        'sku'                 => 'M9-SKU-'.uniqid(),
        'type'                => 'simple',
        // 必须给 family，否则 Product::getAttribute() 会在交付链路里抛错
        'attribute_family_id' => DB::table('attribute_families')->value('id') ?: 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    DB::table('product_categories')->insert([
        'product_id'  => $productId,
        'category_id' => $categoryId,
    ]);

    return $productId;
}

/**
 * @param  bool  $guest  true = 游客单（`orders.customer_id` 为空）
 */
function m9Order(?Customer $customer, float $grandTotal, bool $guest = false): int
{
    return DB::table('orders')->insertGetId([
        'increment_id'         => 'M9INV'.uniqid(),
        'customer_id'          => $guest ? null : $customer->id,
        'customer_first_name'  => '张三',
        'customer_last_name'   => '李',
        'status'               => 'pending',
        'grand_total'          => $grandTotal,
        'created_at'           => now(),
        'updated_at'           => now(),
    ]);
}

function m9OrderItem(int $orderId, int $productId, float $total): int
{
    return DB::table('order_items')->insertGetId([
        'order_id'    => $orderId,
        'product_id'  => $productId,
        'type'        => 'simple',
        'name'        => '技能包',
        'sku'         => 'M9-SKU-'.uniqid(),
        'qty_ordered' => 1,
        'price'       => $total,
        'total'       => $total,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

function m9Payload(int $orderId, float $amount): array
{
    return [
        'out_trade_no' => MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01 00:00:00'), $orderId),
        'mock_paid'    => 1,
        'amount'       => $amount,
    ];
}

it('M9 · 支付成功后生成一条开票登记记录，字段取自订单', function () {
    $customer = m9Customer();
    $productId = m9SkillProduct();
    $orderId = m9Order($customer, 199.00);
    m9OrderItem($orderId, $productId, 199.00);

    postJson(route('shop.api.callback.payment'), m9Payload($orderId, 199.00))->assertOk();

    $invoice = Invoice::query()->first();

    expect($invoice)->not->toBeNull('支付回调没有登记开票')
        ->and($invoice->customer_id)->toBe($customer->id)
        ->and($invoice->orderIds())->toBe([$orderId])
        // 状态停在「已登记待开具」：真实开具（税控/PDF）未接入，此处**不许**写成 issued
        ->and($invoice->status)->toBe(Invoice::STATUS_APPLIED)
        ->and((float) $invoice->amount)->toBe(199.0)
        // 抬头取订单上的客户姓名快照（客户改名/注销不该改历史抬头）
        ->and($invoice->title)->toBe('张三 李')
        // 税号空 = "还没问过用户"，不是"用户是个人"；票面文件与开具时间同理，空即"没这个能力"
        ->and($invoice->tax_no)->toBeNull()
        ->and($invoice->file_path)->toBeNull()
        ->and($invoice->issued_at)->toBeNull()
        ->and($invoice->type)->toBe(Invoice::TYPE_PERSONAL);

    // 开票与交付发生在同一条链路上：订单确实被推进了
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing')
        ->and(DB::table('skill_entitlements')->where('order_id', $orderId)->count())->toBe(1);
});

it('M9 · 同一笔回调重放 3 次：发票只有一张（幂等不能靠 json 列的唯一键）', function () {
    $customer = m9Customer();
    $productId = m9SkillProduct();
    $orderId = m9Order($customer, 88.00);
    m9OrderItem($orderId, $productId, 88.00);

    $payload = m9Payload($orderId, 88.00);

    postJson(route('shop.api.callback.payment'), $payload)->assertOk();
    postJson(route('shop.api.callback.payment'), $payload)->assertOk();
    postJson(route('shop.api.callback.payment'), $payload)->assertOk();

    expect(Invoice::query()->whereJsonContains('order_ids', $orderId)->count())->toBe(1)
        ->and(PaymentTransaction::query()->where('out_trade_no', $payload['out_trade_no'])->count())->toBe(1);
});

it('M9 · 游客单不发发票（没有抬头主体），回调本身照常成功', function () {
    $productId = m9SkillProduct();
    $orderId = m9Order(null, 66.00, guest: true);
    m9OrderItem($orderId, $productId, 66.00);

    postJson(route('shop.api.callback.payment'), m9Payload($orderId, 66.00))->assertOk();

    // 开不出来 ≠ 失败：`invoice.customer_id` 非空，游客单没有可开票的主体
    expect(Invoice::query()->count())->toBe(0);
});

it('M9 · InvoiceService 幂等：同一订单重复调用只登记一条', function () {
    $customer = m9Customer();
    $orderId = m9Order($customer, 12.00);

    $order = \Webkul\Sales\Models\OrderProxy::modelClass()::find($orderId);
    $service = app(InvoiceService::class);

    $first = $service->issueForOrder($order);
    $second = $service->issueForOrder($order);

    expect($first->id)->toBe($second->id)
        ->and(Invoice::query()->count())->toBe(1);
});

it('M9 · 开票写不进去 → 整笔回调回滚（交付与开票是同一个断言，不允许静默缺失）', function () {
    $customer = m9Customer();
    $productId = m9SkillProduct();
    $orderId = m9Order($customer, 300.00);
    m9OrderItem($orderId, $productId, 300.00);

    // 让开票这一步失败（模拟开票表不可用）
    app()->instance(InvoiceService::class, new class extends InvoiceService
    {
        public function issueForOrder($order): ?Invoice
        {
            throw new RuntimeException('开票库不可用');
        }
    });

    $payload = m9Payload($orderId, 300.00);

    postJson(route('shop.api.callback.payment'), $payload)->assertStatus(500);

    // 订单没有被打成 processing、没有发授权 —— 要么一起成，要么一起不成
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('pending')
        ->and(DB::table('skill_entitlements')->where('order_id', $orderId)->count())->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);

    // 但支付流水**在事务外**已经落了（M6）：所以网关重投时这笔不会被当成新支付，
    // 修好故障后重放能继续把订单推完 —— 回滚不等于这笔支付白记
    expect(PaymentTransaction::query()->where('out_trade_no', $payload['out_trade_no'])->count())->toBe(1);
});
