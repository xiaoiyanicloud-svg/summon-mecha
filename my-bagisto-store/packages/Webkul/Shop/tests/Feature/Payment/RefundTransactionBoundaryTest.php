<?php

use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\Payment\PaymentGateway;
use Webkul\Shop\Services\Payment\PaymentResult;
use Webkul\Shop\Services\SkillDelivery\AfterSaleService;

/**
 * M7 · 退款在 `DB::transaction` 内调外部网关。
 *
 * 背景:`AfterSaleService::approve()` 把「冻结授权 + `$gateway->refund()` + 落
 * `RefundRequest`」全放在一个事务里。而**外部退款网关的调用不可回滚** ——
 * 钱已经退出去,后面 `RefundRequest::create` 一旦抛错,整个事务回滚:
 * 账上没有退款单、授权也没记为已退,钱却出去了。
 *
 * 修法(三段式):
 *   ① 事务内:状态守卫(行锁)+ 金额校验 + 冻结授权 + 落「退款中」记录 → 提交
 *   ② 事务外:调网关
 *   ③ 事务内:`settleRefund()` 回写终态(将来网关回调/轮询的同一入口)
 *
 * 本文件的取证方式:让替身网关**在被打款的那一刻**回报自己的事务层级与
 * 数据库里退款单的状态 —— 这两条是改动的核心主张,不能只靠读代码。
 */

/**
 * 替身网关:记录"被打款时"的事务层级与当时的退款单状态。
 */
class M7RefundSpyGateway implements PaymentGateway
{
    /** @var array<int, array{level: int, amount: float, out_refund_no: string, refund_row_status: ?string}> */
    public static array $calls = [];

    public static bool $fail = false;

    public static bool $throw = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$fail = false;
        self::$throw = false;
    }

    public function key(): string
    {
        return 'm7refundspy';
    }

    public function createPayment($order): array
    {
        return [];
    }

    public function handleCallback(array $payload): PaymentResult
    {
        throw new RuntimeException('本替身不处理支付回调');
    }

    public function refund($order, float $amount, string $outRefundNo): array
    {
        self::$calls[] = [
            'level'             => DB::transactionLevel(),
            'amount'            => $amount,
            'out_refund_no'     => $outRefundNo,
            // 打款这一刻,退款单在库里是什么状态?
            'refund_row_status' => DB::table('refund_request')
                ->where('wechat_refund_no', $outRefundNo)
                ->value('status'),
        ];

        if (self::$throw) {
            throw new RuntimeException('网关不可达');
        }

        return [
            'ok'        => ! self::$fail,
            'refund_no' => 'SPY'.$outRefundNo,
            'message'   => self::$fail ? '网关拒绝' : '替身退款成功',
        ];
    }
}

beforeEach(function () {
    M7RefundSpyGateway::reset();

    // 走项目既有的"配置注册表 + 容器解析"机制换网关,不做 new/mock 绕过
    config([
        'payment_gateways.gateways.m7refundspy' => ['class' => M7RefundSpyGateway::class, 'production_safe' => false],
        'payment_gateways.default'              => 'm7refundspy',
    ]);
});

function m7rCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Refund',
        'last_name'   => 'Boundary',
        'email'       => 'm7r'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function m7rOrderItem(int $orderId, float $total): int
{
    return DB::table('order_items')->insertGetId([
        'order_id'    => $orderId,
        'product_id'  => 900000 + random_int(1, 9999),
        'type'        => 'simple',
        'name'        => '技能包',
        'sku'         => 'M7R-SKU-'.uniqid(),
        'qty_ordered' => 1,
        'price'       => $total,
        'total'       => $total,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
}

/**
 * @return array{0: Customer, 1: int, 2: int, 3: SkillEntitlement, 4: int}
 */
function m7rScenario(float $total = 999.00, string $entitlementStatus = SkillEntitlement::STATUS_ACTIVE): array
{
    $customer = m7rCustomer();

    $orderId = DB::table('orders')->insertGetId([
        'increment_id' => 'M7R'.uniqid(),
        'customer_id'  => $customer->id,
        'status'       => 'processing',
        'grand_total'  => $total,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $itemId = m7rOrderItem($orderId, $total);

    $entitlement = SkillEntitlement::create([
        'customer_id'      => $customer->id,
        'order_id'         => $orderId,
        'order_item_id'    => $itemId,
        'product_id'       => 900000 + random_int(1, 9999),
        'status'           => $entitlementStatus,
        'delivery_channel' => 'app',
        'license_key'      => SkillEntitlement::newLicenseKey(),
        'granted_at'       => now(),
    ]);

    $afterSale = AfterSale::create([
        'order_id'       => $orderId,
        'order_item_id'  => $itemId,
        'entitlement_id' => $entitlement->id,
        'customer_id'    => $customer->id,
        'type'           => AfterSale::TYPE_REFUND,
        'policy_basis'   => 'not_installed',
        'status'         => AfterSale::STATUS_APPLIED,
    ]);

    return [$customer, $orderId, $itemId, $entitlement, $afterSale->id];
}

it('M7 · 打网关发生在事务**外**,且打款时退款单已落库为「退款中」', function () {
    // 测试自身跑在 DatabaseTransactions 里,基线层级不是 0 —— 拿它当"事务外的基准"
    $baseline = DB::transactionLevel();

    [, , , , $afterSaleId] = m7rScenario(999.00);

    $result = app(AfterSaleService::class)->approve($afterSaleId, 1);

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['amount'])->toBe(999.0)
        ->and($result['data']['refund_status'])->toBe(RefundRequest::STATUS_SUCCESS);

    expect(M7RefundSpyGateway::$calls)->toHaveCount(1);

    $call = M7RefundSpyGateway::$calls[0];

    // ① 事务外 —— 这是本项的核心主张:调外部网关不在 DB::transaction 里
    expect($call['level'])->toBe($baseline)
        // ② 钱出去之前,账已经落下("先落「退款中」记录并提交,事务外调网关")
        ->and($call['refund_row_status'])->toBe(RefundRequest::STATUS_PENDING)
        ->and($call['amount'])->toBe(999.0);

    // ③ 单号是 ULID 序列,不是时间戳:同秒重试不会撞
    expect($call['out_refund_no'])->toStartWith('RF');

    $refund = RefundRequest::query()->where('after_sale_id', $afterSaleId)->first();

    expect($refund->wechat_refund_no)->toBe($call['out_refund_no'])
        ->and($refund->callback_at)->not->toBeNull();
});

it('M7 · 网关抛异常:退款单落 failed 而不是凭空消失,授权仍被冻结', function () {
    [, , , $entitlement, $afterSaleId] = m7rScenario(500.00);

    M7RefundSpyGateway::$throw = true;

    $result = app(AfterSaleService::class)->approve($afterSaleId, 1);

    // 不抛出去(网关异常不该把已经落好的账带走),但结果如实是"没退成"
    expect($result['ok'])->toBeTrue()
        ->and($result['data']['refund_status'])->toBe(RefundRequest::STATUS_FAILED);

    $refund = RefundRequest::query()->where('after_sale_id', $afterSaleId)->first();

    // 关键:这条记录**在事务外调网关之前就已经提交**,所以网关炸了它也在
    expect($refund)->not->toBeNull()
        ->and($refund->status)->toBe(RefundRequest::STATUS_FAILED)
        ->and((float) $refund->amount)->toBe(500.0);

    expect(AfterSale::find($afterSaleId)->status)->toBe(AfterSale::STATUS_APPROVED)
        // 冻结与打款解耦:钱没退成,授权也已经不能用了
        ->and(SkillEntitlement::find($entitlement->id)->status)->toBe(SkillEntitlement::STATUS_REVOKED);

    // failed 不占用实付额度(钱没出去),所以同一订单条目还能再发起一次退款
    $original = AfterSale::find($afterSaleId);

    $retry = AfterSale::create([
        'order_id'       => $original->order_id,
        'order_item_id'  => $original->order_item_id,
        'entitlement_id' => $original->entitlement_id,
        'customer_id'    => $original->customer_id,
        'type'           => AfterSale::TYPE_REFUND,
        'policy_basis'   => 'not_installed',
        'status'         => AfterSale::STATUS_APPLIED,
    ]);

    M7RefundSpyGateway::reset();

    expect(app(AfterSaleService::class)->approve($retry->id, 1)['ok'])->toBeTrue()
        ->and((float) RefundRequest::query()->where('after_sale_id', $retry->id)->value('amount'))->toBe(500.0);
});

it('M7 · 审计写不进去时金额不出账(先落账、再动钱)', function () {
    [, , , , $afterSaleId] = m7rScenario(300.00);

    // logOrFail 抛错 = 审计表写不进去。按 logOrFail 的定义,这种动作不许留无痕记录。
    app()->instance(OperationLogger::class, new class extends OperationLogger
    {
        public function logOrFail(string $actorType, ?int $actorId, string $action, ?string $targetType = null, ?int $targetId = null, array $payload = [], ?string $ip = null): OperationLog
        {
            throw new RuntimeException('审计库不可用');
        }
    });

    expect(fn () => app(AfterSaleService::class)->approve($afterSaleId, 1))
        ->toThrow(RuntimeException::class);

    // 钱一分没动,退款单也没留下半条
    expect(M7RefundSpyGateway::$calls)->toHaveCount(0)
        ->and(RefundRequest::query()->where('after_sale_id', $afterSaleId)->count())->toBe(0);
});

it('M7 · 同一售后单重复审批只产生一条退款单,网关只被调一次', function () {
    [, , , , $afterSaleId] = m7rScenario(120.00);

    $service = app(AfterSaleService::class);

    $first = $service->approve($afterSaleId, 1);
    $second = $service->approve($afterSaleId, 1);

    expect($first['ok'])->toBeTrue()
        ->and($second['ok'])->toBeFalse()
        ->and($second['message'])->toContain('已处理')
        ->and(M7RefundSpyGateway::$calls)->toHaveCount(1)
        ->and(RefundRequest::query()->where('after_sale_id', $afterSaleId)->count())->toBe(1);
});

it('M7 · 终态回写幂等:重复回执不会把金额再退一次(回调/轮询入口)', function () {
    [, , , , $afterSaleId] = m7rScenario(80.00);

    app(AfterSaleService::class)->approve($afterSaleId, 1);

    $refund = RefundRequest::query()->where('after_sale_id', $afterSaleId)->first();

    // 模拟网关重复回调:同一个 refund 再结算一次
    $again = app(AfterSaleService::class)->settleRefund($refund->id, true, '重复回执');

    expect($again['ok'])->toBeTrue()
        ->and($again['data']['refund_status'])->toBe(RefundRequest::STATUS_SUCCESS)
        ->and(RefundRequest::query()->where('after_sale_id', $afterSaleId)->count())->toBe(1);
});
