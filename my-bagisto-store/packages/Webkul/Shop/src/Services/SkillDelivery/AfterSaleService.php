<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Sales\Models\OrderItemProxy;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Services\LifecycleNotifier;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\Shop\Services\Payment\PaymentManager;

/**
 * 售后/退款服务(v3.4 步骤 4,虚拟商品政策 D10)。
 *
 * 政策:
 *   试用(meta.trial=true)          → 不支持退款
 *   买断·未下发(无 device / 未 active) → 可退
 *   买断·已激活(active)             → 原则上不退,可申诉(走人工审)
 *
 * ⚠ 退款的实际打款(PaymentGateway::refund)在资质就绪前走 mock;
 *   revoke 授权用 v1 既有的通道能力,与退款解耦(即使打款失败也先冻结授权)。
 */
class AfterSaleService
{
    public function __construct(
        protected PaymentManager $payments,
        protected OperationLogger $oplog,
        protected LifecycleNotifier $notifier,
    ) {}

    /**
     * 用户发起售后。返回结果 + 政策依据。
     *
     * A3 · 防重复申请此前是**事务外的 check-then-act**:两次并发申请都读到"没有处理中"、
     * 都建单。现在整段收进事务,并对该授权行 `lockForUpdate()` —— 同一授权的申请串行化,
     * 后到的会在锁上等到先到的提交,再重查就能看到已存在的处理中单。
     *
     * ⚠ 这里锁的是**授权行**而不是 after_sale 表:要防的竞态是"针对同一授权重复申请",
     *   被争用的资源是那条授权 —— 锁它,语义最直白,也不依赖 after_sale 上加约束
     *   (那条约束本仓刻意不加,见 migration 2026_10_15_000006 的说明)。
     */
    public function apply(int $customerId, int $entitlementId, ?string $reasonCategory, ?string $detail): array
    {
        return DB::transaction(function () use ($customerId, $entitlementId, $reasonCategory, $detail) {
            $entitlement = SkillEntitlement::query()
                ->where('customer_id', $customerId)
                ->where('id', $entitlementId)
                ->lockForUpdate()
                ->first();

            if (! $entitlement) {
                return ['ok' => false, 'message' => '技能授权不存在。'];
            }

            $meta = is_array($entitlement->meta) ? $entitlement->meta : [];

            // 试用不退
            if (! empty($meta['trial'])) {
                return ['ok' => false, 'message' => '试用技能不支持退款。'];
            }

            // 判定政策依据:未下发可退 / 已激活需人工审
            $isActivated = $entitlement->status === SkillEntitlement::STATUS_ACTIVE
                || $entitlement->activations > 0;

            $policyBasis = $isActivated ? 'activated' : 'not_installed';

            // 防重复申请(在行锁内重查:并发下第二个请求这时才看得到第一个建的单)
            $existing = AfterSale::query()
                ->where('customer_id', $customerId)
                ->where('entitlement_id', $entitlementId)
                ->whereIn('status', [AfterSale::STATUS_APPLIED, AfterSale::STATUS_REVIEWING, AfterSale::STATUS_APPROVED])
                ->exists();

            if ($existing) {
                return ['ok' => false, 'message' => '该技能已有处理中的售后申请。'];
            }

            $afterSale = AfterSale::create([
                'order_id'        => $entitlement->order_id,
                'order_item_id'   => $entitlement->order_item_id,
                'entitlement_id'  => $entitlementId,
                'customer_id'     => $customerId,
                'type'            => AfterSale::TYPE_REFUND,
                'reason_category' => $reasonCategory,
                'detail'          => $detail,
                'policy_basis'    => $policyBasis,
                // 未下发的可自动通过;已激活的进人工审
                'status'          => $isActivated ? AfterSale::STATUS_REVIEWING : AfterSale::STATUS_APPLIED,
            ]);

            return [
                'ok'      => true,
                'message' => $isActivated ? '已提交,等待人工审核(已激活技能原则上不退)。' : '已受理,可退款(未下发)。',
                'data'    => ['id' => $afterSale->id, 'policy_basis' => $policyBasis, 'status' => $afterSale->status],
            ];
        });
    }

    /**
     * 通过售后 → 冻结授权 + 走退款。
     *
     * M7 · 事务边界:此前「冻结授权 + 调外部网关 + 落 RefundRequest」全挤在一个事务里,
     * 而**外部退款网关的调用不可回滚** —— 钱已经退出去,后面 `RefundRequest::create`
     * 一旦抛错,整个事务回滚:账上既没有退款单、授权也没真正记为已退,钱却出去了。
     * 现在拆成三段:
     *   ① 事务内:状态守卫(行锁)+ 金额校验 + 冻结授权 + 落「退款中」记录 → 提交
     *   ② 事务外:调网关(钱出去这一步不放在事务里)
     *   ③ 事务内:回写终态(`settleRefund()`,也是将来网关回调/对账轮询的唯一入口)
     * 这样"钱是否出去"与"账本怎么写"不再互相绑架:任何一段失败都留下可核对的中间态。
     */
    public function approve(int $afterSaleId, ?int $reviewerId = null): array
    {
        // ── 阶段①:事务内准备(守卫 + 冻结 + 落「退款中」),提交后才动钱 ──
        $prepared = DB::transaction(function () use ($afterSaleId, $reviewerId) {
            // 状态守卫移进事务 + 行锁:此前守卫在事务外,两个并发 approve 都能读到
            // 旧状态并通过(退款是资金级重复,必须挡住)。
            $afterSale = AfterSale::query()
                ->whereKey($afterSaleId)
                ->lockForUpdate()
                ->first();

            if (! $afterSale) {
                return ['ok' => false, 'message' => '售后单不存在。'];
            }

            if (in_array($afterSale->status, [AfterSale::STATUS_APPROVED, AfterSale::STATUS_REFUNDED], true)) {
                return ['ok' => false, 'message' => '该售后单已处理。'];
            }

            $entitlement = $afterSale->entitlement_id
                ? SkillEntitlement::find($afterSale->entitlement_id)
                : null;

            // 1) 退款金额取自该订单条目的实付(M5)。
            //    此前恒传 0、RefundRequest 也恒落 0 —— 退款账目全是 0,事后无法对账。
            $orderItemId = $afterSale->order_item_id ?: $entitlement?->order_item_id;
            $paidTotal = $this->paidAmountOf($orderItemId);
            $alreadyRefunded = $this->refundedAmountOf($orderItemId);

            // 已退金额累计不得超实付。
            // ⚠ 这个校验放在**冻结授权之前**:此前冻结在前、校验在后,被拒的退单一样把
            //   授权冻掉了(校验失败 return 不会回滚事务,revoke 已经落库)。
            if ($paidTotal > 0 && $alreadyRefunded >= $paidTotal - 0.005) {
                return ['ok' => false, 'message' => '该订单条目已退款,不能重复退款。'];
            }

            $amount = $paidTotal > 0
                ? round(min($paidTotal - $alreadyRefunded, $paidTotal), 2)
                : 0.0;

            // 2) 冻结授权(先冻结,与退款解耦——即使打款失败也不能再用)
            if ($entitlement) {
                $this->revokeEntitlement($entitlement);
            }

            // 3) 落「退款中」记录。退款单号用 ULID:秒级 `ymdHis` 同秒重试会撞号。
            $outRefundNo = 'RF'.(string) Str::ulid();

            $afterSale->status = AfterSale::STATUS_APPROVED;
            $afterSale->reviewer_id = $reviewerId;
            $afterSale->reviewed_at = now();
            $afterSale->save();

            $refund = RefundRequest::create([
                'after_sale_id'    => $afterSale->id,
                'wechat_refund_no' => $outRefundNo,
                'amount'           => $amount,
                'status'           => RefundRequest::STATUS_PENDING, // 退款中,等网关回执
            ]);

            // M8 · 退款/售后审批留痕。此前 Shop 侧资金动作一处都不写 operation_log。
            // 这是"钱即将出去"的动作,用 logOrFail():审计写不进去就整个回滚,
            // 而且**此时钱还没出去**,回滚是安全的(这正是 logOrFail 该待的位置)。
            $this->oplog->logOrFail(
                OperationLog::ACTOR_ADMIN,
                $reviewerId,
                'after_sale.approve',
                'after_sale',
                $afterSale->id,
                [
                    'phase'          => 'initiated',
                    'entitlement_id' => $afterSale->entitlement_id,
                    'order_item_id'  => $afterSale->order_item_id,
                    'amount'         => $amount,
                    'refund_no'      => $outRefundNo,
                    'refund_id'      => $refund->id,
                ]
            );

            return [
                'ok'            => true,
                'refund_id'     => $refund->id,
                'after_sale_id' => $afterSale->id,
                'amount'        => $amount,
                'out_refund_no' => $outRefundNo,
            ];
        });

        if (! ($prepared['ok'] ?? false)) {
            return $prepared;
        }

        // ── 阶段②:事务外调网关。钱一旦出去,就不能再靠"回滚事务"把它拿回来 ──
        try {
            $gateway = $this->payments->gateway();
            $result = $gateway->refund(null, (float) $prepared['amount'], $prepared['out_refund_no']);
            $ok = $result['ok'] ?? false;
            $msg = $result['message'] ?? '';
        } catch (\Throwable $e) {
            // 资质未就绪时 mock 也会走通;真实网关异常不应让整单回滚(先冻结授权是对的)
            report($e);
            $ok = false;
            $msg = $e->getMessage();
        }

        // ── 阶段③:回写终态 ──
        $result = $this->settleRefund($prepared['refund_id'], $ok, $msg);

        // B-2 · 站内通知(副作用,不改变上面已定的结果):
        //   · 审核结果 —— 批准是已经发生的事实,网关成功与否都发;
        //   · 退款到账 —— 仅当打款成功才发(失败不发"到账",见 LifecycleNotifier)。
        $afterSale = AfterSale::find($prepared['after_sale_id']);
        if ($afterSale) {
            $this->notifier->afterSaleDecided($afterSale, true);
            $this->notifier->refundSettled(
                $afterSale,
                (float) ($result['data']['amount'] ?? $prepared['amount']),
                ($result['data']['refund_status'] ?? null) === RefundRequest::STATUS_SUCCESS,
                $prepared['out_refund_no'] ?? null,
            );
        }

        return $result;
    }

    /**
     * M7 · 退款终态回写 —— 事务外打完款之后调;将来网关异步回调 / 对账轮询也走这里。
     *
     * 幂等:只有 `pending`(退款中)才写终态,重复回执不会再改一次,
     * 也不会让同一个售后单被结算两遍。
     *
     * ⚠ 这里用 `log()` 而不是 `logOrFail()`:钱**已经**出去了,此时若因为审计表写不进去
     *   而回滚状态回写,只会让"钱出去了但账本还停在退款中"——那是更坏的账。
     */
    public function settleRefund(int $refundId, bool $ok, ?string $message = null): array
    {
        return DB::transaction(function () use ($refundId, $ok, $message) {
            $refund = RefundRequest::query()
                ->whereKey($refundId)
                ->lockForUpdate()
                ->first();

            if (! $refund) {
                return ['ok' => false, 'message' => '退款单不存在。'];
            }

            $afterSale = $refund->after_sale_id
                ? AfterSale::query()->whereKey($refund->after_sale_id)->lockForUpdate()->first()
                : null;

            $amount = (float) $refund->amount;

            // 幂等:已是终态 → 按既有结果返回,不重复改
            if ($refund->status !== RefundRequest::STATUS_PENDING) {
                return [
                    'ok'      => true,
                    'message' => '该退款单已是终态,按幂等返回。',
                    'data'    => [
                        'id'            => $afterSale?->id,
                        'status'        => $afterSale?->status,
                        'amount'        => $amount,
                        'refund_status' => $refund->status,
                    ],
                ];
            }

            $refund->status = $ok ? RefundRequest::STATUS_SUCCESS : RefundRequest::STATUS_FAILED;
            $refund->callback_at = now();
            $refund->save();

            if ($afterSale) {
                $afterSale->status = $ok ? AfterSale::STATUS_REFUNDED : AfterSale::STATUS_APPROVED;
                $afterSale->save();
            }

            $this->oplog->log(
                OperationLog::ACTOR_ADMIN,
                $afterSale?->reviewer_id,
                'after_sale.refund_settled',
                'after_sale',
                $afterSale?->id,
                [
                    'refund_id'     => $refund->id,
                    'refund_no'     => $refund->wechat_refund_no,
                    'amount'        => $amount,
                    'refund_status' => $refund->status,
                    'message'       => $message,
                ]
            );

            return [
                'ok'      => true,
                'message' => $ok
                    ? '已退款并冻结授权。'
                    : '已冻结授权,退款未成功(待支付资质就绪后重打款)。',
                'data'    => [
                    'id'            => $afterSale?->id,
                    'status'        => $afterSale?->status,
                    'amount'        => $amount,
                    'refund_status' => $refund->status,
                ],
            ];
        });
    }

    /**
     * 驳回售后。
     */
    public function reject(int $afterSaleId, ?int $reviewerId = null, ?string $reason = null): array
    {
        $afterSale = AfterSale::find($afterSaleId);

        if (! $afterSale) {
            return ['ok' => false, 'message' => '售后单不存在。'];
        }

        $afterSale->status = AfterSale::STATUS_REJECTED;
        $afterSale->reviewer_id = $reviewerId;
        $afterSale->reviewed_at = now();
        if ($reason) {
            $afterSale->detail = trim(($afterSale->detail ?? '')."\n[驳回] ".$reason);
        }
        $afterSale->save();

        // M8 · 驳回留痕(不涉及资金出账,用 log():审计失败不阻断业务)。
        $this->oplog->log(
            OperationLog::ACTOR_ADMIN,
            $reviewerId,
            'after_sale.reject',
            'after_sale',
            $afterSale->id,
            ['reason' => $reason]
        );

        // B-2 · 审核结果(驳回)站内通知
        $this->notifier->afterSaleDecided($afterSale, false);

        return ['ok' => true, 'message' => '已驳回。', 'data' => ['id' => $afterSale->id]];
    }

    /**
     * 该订单条目的实付金额(M5)。行 total 是含税/折扣后的应付;退化用 price×qty。
     */
    protected function paidAmountOf(?int $orderItemId): float
    {
        if (! $orderItemId) {
            return 0.0;
        }

        $item = OrderItemProxy::modelClass()::find($orderItemId);

        if (! $item) {
            return 0.0;
        }

        $total = (float) $item->total;

        if ($total <= 0) {
            $total = (float) $item->price * max(1, (int) $item->qty_ordered);
        }

        return round(max(0.0, $total), 2);
    }

    /**
     * 该订单条目**已发起**的退款累计(success + pending,不含 failed/被拒)。
     */
    protected function refundedAmountOf(?int $orderItemId): float
    {
        if (! $orderItemId) {
            return 0.0;
        }

        return (float) RefundRequest::query()
            ->join('after_sale', 'after_sale.id', '=', 'refund_request.after_sale_id')
            ->where('after_sale.order_item_id', $orderItemId)
            ->whereIn('refund_request.status', RefundRequest::COUNTED_STATUSES)
            ->sum('refund_request.amount');
    }

    /**
     * 冻结授权 —— 复用 v1 的通道 revoke(幂等)。
     */
    protected function revokeEntitlement(SkillEntitlement $entitlement): void
    {
        $manager = app(\Webkul\Shop\Services\SkillDelivery\SkillDeliveryManager::class);
        $manager->channel($entitlement->delivery_channel)->revoke($entitlement);
    }
}
