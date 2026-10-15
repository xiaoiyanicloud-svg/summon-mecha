<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Models\OrderProxy;
use Webkul\Shop\Listeners\SkillDelivery;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\PaymentTransaction;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\Payment\MockGateway;
use Webkul\Shop\Services\Payment\PaymentManager;

/**
 * 支付回调(v3.4 步骤 4)。
 *
 * ⚠ 本接口**不挂 customer 鉴权**(回调来自支付网关,不是用户)。已加固:
 *   - 订单身份**只来自网关解析出的商户订单号**(out_trade_no),不再信任请求体 order_id(M3)
 *   - 回调金额必须等于订单应付(grand_total),否则拒绝并留 code(M3)
 *   - 落支付流水 + out_trade_no 唯一键幂等(M6)
 *   - 幂等判定在事务内、对被改的订单行加写锁(lockForUpdate),并发重发不会双双读到 pending(M7)
 *
 * ⚠ **验签按设计推迟**:当前仍是 mock 网关("支付成功"由参数自报,无验签)。
 *   真实验签在微信资质就绪后于 WechatGateway::handleCallback 内做,**不在这里伪造**;
 *   生产环境由 `PaymentManager::assertUsableHere` 拒绝任何未声明 production_safe 的网关。
 *
 * 支付成功后:订单置 processing → 触发 v1 既有的授权发放监听逻辑(幂等)。
 */
class PaymentCallbackController extends APIController
{
    public function __construct(
        protected PaymentManager $payments,
        protected OperationLogger $oplog,
    ) {}

    public function handle(Request $request)
    {
        // mock 网关回调:带 out_trade_no + mock_paid=1
        //
        // ⚠ 网关解析失败要**明确拒绝**(503 = 让对方重试),不能在拿不到网关时
        //   继续往下走 —— 本接口不挂鉴权,而"把订单翻成 processing 并发授权"是实打实的
        //   价值转移。生产环境因 `production_safe` 守卫会走到这里(PaymentManager 抛错),
        //   这正是期望行为:宁可不处理,也不能凭一个自报参数发东西出去。
        try {
            $gateway = $this->payments->gateway();
        } catch (\Throwable $e) {
            return $this->fail(ApiErrorCode::PAYMENT_GATEWAY_UNAVAILABLE, $e->getMessage(), 503);
        }

        $result = $gateway->handleCallback($request->all());

        if (! $result->paid) {
            return $this->fail(ApiErrorCode::PAYMENT_FAILED, '支付未成功:'.$result->message, 422);
        }

        // 从 out_trade_no 反查订单(mock 里 out_trade_no 末段是 order id;真实网关用订单号映射)
        $order = $this->resolveOrder($result->outTradeNo);

        if (! $order) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单未找到。', 404);
        }

        // 金额核对(M3):回调金额必须等于订单应付。
        // 此前 `PaymentResult::amount` 拿到了却从未参与判定 —— 任何人可以自报任意金额。
        if (! $this->amountMatches($order, $result->amount)) {
            return $this->fail(ApiErrorCode::AMOUNT_MISMATCH, '回调金额与订单应付不一致,已拒绝。', 409);
        }

        // 幂等 + 落流水(M6):以商户订单号 out_trade_no 唯一键为锚点。
        // 此前回调只翻订单状态,把网关交易号/商户订单号/金额全部丢弃 —— 既无幂等键,
        // 也无对账源(OpsMetricsService 自己就写着「out_trade_no 从未持久化」)。
        // 现在重复回调会撞唯一键 → 按已处理返回,不再依赖"先查订单状态再改"的 check-then-act。
        $transaction = PaymentTransaction::query()
            ->where('out_trade_no', $result->outTradeNo)
            ->first();

        if (! $transaction) {
            try {
                $transaction = PaymentTransaction::create([
                    'order_id'       => $order->id,
                    'out_trade_no'   => $result->outTradeNo,
                    'transaction_id' => $result->transactionId,
                    'gateway'        => $gateway->key(),
                    'amount'         => $result->amount,
                    'status'         => PaymentTransaction::STATUS_PAID,
                    'paid_at'        => now(),
                    'raw'            => $result->raw,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // 并发重复回调:唯一键冲突 = 另一半已落库 → 取回并按幂等处理
                $transaction = PaymentTransaction::query()
                    ->where('out_trade_no', $result->outTradeNo)
                    ->first();

                if (! $transaction) {
                    throw $e;
                }
            }
        }

        // M7 · 幂等判定移进事务 + 订单行锁(真幂等,不是 check-then-act)。
        //
        // 此前是事务**外**的 check-then-act:先读 `in_array($order->status, [processing, completed])`
        // 再写,中间既无锁也无事务 —— 两个并发回调**都**读到 pending,**都**进 afterOrderCreated。
        // 兜底只有 skill_entitlements.order_item_id 唯一键,而那个唯一约束异常被
        // SkillDelivery 的 try/catch 吞掉(见该类注释第 1 条:吞是刻意的)→ 结果可能是
        // "订单状态翻成 processing,但本次交付没落"。
        //
        // 现在:`SELECT ... FOR UPDATE` 先取订单行的写锁,后到的回调**必须等**先到的提交,
        // 等到的就是 processing/completed → 走幂等分支。"翻状态 + 交付"仍在同一事务内
        // (D3 已统一两条入口的边界),所以要么一起提交、要么一起回滚。
        //
        // ⚠ 为什么锁订单行而不是只靠流水唯一键:out_trade_no 唯一键只保证"流水只有一行",
        //   它挡不住第二次回调继续往下翻状态/再交付 —— 需要锁住被改的那行本身。
        $outcome = DB::transaction(function () use ($order) {
            $locked = OrderProxy::modelClass()::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return ['order' => null, 'processed' => false];
            }

            // 幂等:已支付则不改不交付
            if (in_array($locked->status, ['processing', 'completed'], true)) {
                return ['order' => $locked, 'processed' => false];
            }

            $locked->status = 'processing';
            $locked->save();

            // 触发 v1 既有授权发放(幂等:order_item_id 唯一键兜底)
            app(SkillDelivery::class)->afterOrderCreated($locked);

            return ['order' => $locked, 'processed' => true];
        });

        $order = $outcome['order'];

        if (! $order) {
            return $this->fail(ApiErrorCode::ORDER_NOT_FOUND, '订单未找到。', 404);
        }

        if (! $outcome['processed']) {
            return response()->json(['message' => '已处理(幂等)。', 'order_id' => $order->id]);
        }

        // M8 · 资金动作留痕:此前 Shop 侧(含支付回调翻状态)一处都不写 operation_log,
        // 出事要追责时查无此人。回调由网关触发、无登录用户,故记 SYSTEM。
        // 用 log()(非 logOrFail):留痕是旁路,不应因审计表写不进去而回滚一笔已成功的支付。
        //
        // ⚠ 写在事务**外**且只在"本次真的处理了"时写:幂等重放不该再留一条痕
        //   (否则审计里同一次支付会出现多条,反而看不出真实处理次数)。
        $this->oplog->log(
            OperationLog::ACTOR_SYSTEM,
            null,
            'payment.callback.paid',
            'order',
            $order->id,
            [
                'out_trade_no'   => $result->outTradeNo,
                'transaction_id' => $result->transactionId,
                'amount'         => $result->amount,
                'gateway'        => $gateway->key(),
            ],
            $request->ip()
        );

        return response()->json([
            'message'  => '支付成功,授权已发放。',
            'order_id' => $order->id,
        ]);
    }

    /**
     * 从回调负载反查订单 —— **订单身份只来自网关验签结果**,不信任请求体参数。
     *
     * ⚠ 此前这里有一个 `if ($request->filled('order_id')) return Order::find($request->input('order_id'));`
     *   的分支(M3):本接口**不挂鉴权**,于是任何人 `POST /api/callback/payment?order_id=<他人订单>&mock_paid=1`
     *   就能把别人的订单翻成已支付。现已**删除该分支** —— 订单只能由网关自己声明的
     *   商户订单号(out_trade_no)解析得出,解析不出就老实返回 null(404),不猜。
     *
     * ⚠ 严格解析而非"取结尾数字":换成任何结尾带数字的真实商户订单号,后者都能编出
     *   一个看起来合法的订单 id —— 而本接口不挂鉴权。真实网关接入时在这里加
     *   "商户订单号 → 本平台订单"的映射(微信侧应落 out_trade_no 到订单)。
     *
     * ⚠ 验签按设计**推迟**:真网关资质就绪后在 WechatGateway::handleCallback 内做,
     *   本方法只负责"用网关已经认过的结果反查订单",不伪造验签。
     */
    protected function resolveOrder(string $outTradeNo)
    {
        if ($orderId = MockGateway::orderIdFrom($outTradeNo)) {
            return OrderProxy::modelClass()::find($orderId);
        }

        return null;
    }

    /**
     * 回调金额是否与订单应付一致。
     *
     * 用分/厘级容差(0.005)吸收浮点表示误差,而不是精确 `===`。
     * 订单没有应付金额时按"无法核对"处理(视为不匹配 → 拒绝),宁可拒绝也不放行。
     */
    protected function amountMatches($order, float $callbackAmount): bool
    {
        $expected = (float) $order->grand_total;

        return abs($expected - $callbackAmount) < 0.005;
    }
}
