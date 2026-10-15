<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Services\Payment\MockGateway;

use function Pest\Laravel\postJson;

/**
 * M7 · 支付回调幂等是 check-then-act(并发重发两条都读到 pending)。
 *
 * 背景:`PaymentCallbackController` 的幂等判定在**事务外**先读
 * `in_array($order->status, ['processing','completed'])` 再写,中间既无锁也无事务。
 * 两个并发回调**都**读到 pending → **都**进 `afterOrderCreated`。唯一的兜底是
 * `skill_entitlements.order_item_id` 唯一键,而那个唯一约束异常被 `SkillDelivery`
 * 的 try/catch 吞掉(吞是刻意的,见 D3)→ 可能出现"订单翻成 processing 但本次未交付"。
 *
 * 修法:幂等判定移进事务,并对**被改的订单行**加写锁(`lockForUpdate`)。
 *   —— 只靠流水唯一键不够:它只保证"流水一行",挡不住第二次继续翻状态/再交付。
 *
 * 本文件的断言方式:除行为断言外,直接对**SQL 与事务层级**取证 ——
 * "存在对 orders 的 `for update` 查询,且它发生在比测试基线更深的事务层里"。
 * 这不是"看代码觉得对":改动前那条语句根本不存在。
 */

/**
 * 记录回调期间的执行 SQL 及其所在事务层级。
 *
 * ⚠ 测试本身跑在 `DatabaseTransactions` 里,所以**基线层级不是 0**。
 *   断言一律用 "> 基线",而不是 ">= 1" —— 否则这个测试在改动前也会通过。
 */
final class M7QueryRecorder
{
    public static bool $registered = false;

    public static bool $recording = false;

    /** @var array<int, array{sql: string, level: int}> */
    public static array $queries = [];

    public static function start(): int
    {
        self::$queries = [];
        self::$recording = true;

        if (! self::$registered) {
            DB::listen(function ($query) {
                if (! self::$recording) {
                    return;
                }

                self::$queries[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
            });

            self::$registered = true;
        }

        return DB::transactionLevel();
    }

    public static function stop(): void
    {
        self::$recording = false;
    }

    /**
     * 找一条 SQL 含 `$needle` 的语句;找不到返回 null。
     *
     * @return array{sql: string, level: int}|null
     */
    public static function find(string $needle): ?array
    {
        foreach (self::$queries as $row) {
            if (str_contains($row['sql'], $needle)) {
                return $row;
            }
        }

        return null;
    }
}

function m7Customer(): Customer
{
    return Customer::create([
        'first_name'  => 'Callback',
        'last_name'   => 'Idem',
        'email'       => 'm7cb'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

/**
 * 造一个"技能类"商品并挂到 skill 分类下 —— 交付链路才会真的走到授权发放。
 */
function m7SkillProduct(): int
{
    $categoryId = DB::table('category_translations')
        ->join('categories', 'categories.id', '=', 'category_translations.category_id')
        ->where('category_translations.slug', 'skill')
        ->value('categories.id');

    $productId = DB::table('products')->insertGetId([
        'sku'                 => 'M7-SKU-'.uniqid(),
        'type'                => 'simple',
        // 必须给 family:Product::getAttribute() 会去查 family 属性,
        // 缺这列会在 ${product}->seller_id 处抛 "Attempt to read property id on null"
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

function m7Order(Customer $customer, float $grandTotal, string $status = 'pending'): int
{
    return DB::table('orders')->insertGetId([
        'increment_id' => 'M7CB'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => $status,
        'grand_total'  => $grandTotal,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function m7OrderItem(int $orderId, int $productId, float $total): int
{
    return DB::table('order_items')->insertGetId([
        'order_id'    => $orderId,
        'product_id'  => $productId,
        'type'        => 'simple',
        'name'        => '技能包',
        'sku'         => 'M7-SKU-'.uniqid(),
        'qty_ordered' => 1,
        'price'       => $total,
        'total'       => $total,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

function m7Payload(int $orderId, float $amount): array
{
    return [
        'out_trade_no' => MockGateway::makeOutTradeNo(new DateTimeImmutable('2026-01-01 00:00:00'), $orderId),
        'mock_paid'    => 1,
        'amount'       => $amount,
    ];
}

it('M7 · 幂等判定在事务内完成,且对被改的订单行加了写锁(lockForUpdate)', function () {
    $customer = m7Customer();
    $productId = m7SkillProduct();
    $orderId = m7Order($customer, 199.00);
    m7OrderItem($orderId, $productId, 199.00);

    $baseline = M7QueryRecorder::start();

    postJson(route('shop.api.callback.payment'), m7Payload($orderId, 199.00))->assertOk();

    M7QueryRecorder::stop();

    // ① 订单身份读取必须带行锁 —— 这是"并发重发不会双双读到 pending"的唯一机制
    $locked = M7QueryRecorder::find('for update');

    expect($locked)->not->toBeNull('回调里没有任何行锁查询')
        ->and($locked['sql'])->toContain('orders')
        // 且发生在回调自己的事务里(比测试基线更深),不是裸读
        ->and($locked['level'])->toBeGreaterThan($baseline);

    // ② 翻状态发生在事务内
    $update = M7QueryRecorder::find('update `orders`');

    expect($update)->not->toBeNull('没有找到更新订单状态的语句')
        ->and($update['level'])->toBeGreaterThan($baseline);

    // ③ 授权发放与翻状态在**同一事务**内(要么一起提交,要么一起回滚)
    $grant = M7QueryRecorder::find('insert into `skill_entitlements`');

    expect($grant)->not->toBeNull('本次回调没有发放授权')
        ->and($grant['level'])->toBeGreaterThan($baseline);
});

it('M7 · 同一笔回调重放 3 次:订单只推进一次、流水一行、授权一行、留痕一条', function () {
    $customer = m7Customer();
    $productId = m7SkillProduct();
    $orderId = m7Order($customer, 199.00);
    m7OrderItem($orderId, $productId, 199.00);

    $payload = m7Payload($orderId, 199.00);

    postJson(route('shop.api.callback.payment'), $payload)->assertOk();
    postJson(route('shop.api.callback.payment'), $payload)->assertOk();
    postJson(route('shop.api.callback.payment'), $payload)->assertOk();

    expect(PaymentTransaction::query()->where('out_trade_no', $payload['out_trade_no'])->count())->toBe(1)
        ->and(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing')
        // 授权不重复发放(order_item_id 唯一键是第一道兜底,行锁是第二道)
        ->and(DB::table('skill_entitlements')->where('order_id', $orderId)->count())->toBe(1)
        // 留痕只写一条:重放不该在审计里伪造出"处理了三次"
        ->and(DB::table('operation_log')->where('action', 'payment.callback.paid')->where('target_id', $orderId)->count())->toBe(1);
});

it('M7 · 已 completed 的订单不会被回调打回 processing(状态不回退)', function () {
    $customer = m7Customer();
    $productId = m7SkillProduct();
    $orderId = m7Order($customer, 88.00, 'completed');
    m7OrderItem($orderId, $productId, 88.00);

    postJson(route('shop.api.callback.payment'), m7Payload($orderId, 88.00))->assertOk();

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('completed');

    // 幂等分支既不翻状态,也不重复留痕
    expect(DB::table('operation_log')->where('action', 'payment.callback.paid')->where('target_id', $orderId)->count())->toBe(0);
});
