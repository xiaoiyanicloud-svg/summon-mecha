<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Sales\Models\OrderItemProxy;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
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
    ) {}

    /**
     * 用户发起售后。返回结果 + 政策依据。
     */
    public function apply(int $customerId, int $entitlementId, ?string $reasonCategory, ?string $detail): array
    {
        $entitlement = SkillEntitlement::query()
            ->where('customer_id', $customerId)
            ->where('id', $entitlementId)
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

        // 防重复申请
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
    }

    /**
     * 通过售后 → 冻结授权 + 走退款。
     */
    public function approve(int $afterSaleId, ?int $reviewerId = null): array
    {
        $afterSale = AfterSale::find($afterSaleId);

        if (! $afterSale) {
            return ['ok' => false, 'message' => '售后单不存在。'];
        }

        if (in_array($afterSale->status, [AfterSale::STATUS_APPROVED, AfterSale::STATUS_REFUNDED], true)) {
            return ['ok' => false, 'message' => '该售后单已处理。'];
        }

        return DB::transaction(function () use ($afterSale, $reviewerId) {
            $entitlement = $afterSale->entitlement_id
                ? SkillEntitlement::find($afterSale->entitlement_id)
                : null;

            // 1) 冻结授权(先冻结,与退款解耦——即使打款失败也不能再用)
            if ($entitlement) {
                $this->revokeEntitlement($entitlement);
            }

            // 2) 退款金额取自该订单条目的实付(M5)。
            //    此前恒传 0、RefundRequest 也恒落 0 —— 退款账目全是 0,事后无法对账。
            $orderItemId = $afterSale->order_item_id ?: $entitlement?->order_item_id;
            $paidTotal = $this->paidAmountOf($orderItemId);
            $alreadyRefunded = $this->refundedAmountOf($orderItemId);

            // 已退金额累计不得超实付
            if ($paidTotal > 0 && $alreadyRefunded >= $paidTotal - 0.005) {
                return ['ok' => false, 'message' => '该订单条目已退款,不能重复退款。'];
            }

            $amount = $paidTotal > 0
                ? round(min($paidTotal - $alreadyRefunded, $paidTotal), 2)
                : 0.0;

            // 3) 发起退款。退款单号用 ULID:秒级 `ymdHis` 同秒重试会撞号。
            $gateway = $this->payments->gateway();
            $outRefundNo = 'RF'.(string) Str::ulid();

            try {
                $result = $gateway->refund(null, (float) $amount, $outRefundNo);
                $ok = $result['ok'] ?? false;
                $msg = $result['message'] ?? '';
            } catch (\Throwable $e) {
                // 资质未就绪时 mock 也会走通;真实网关异常不应让整单回滚(先冻结授权是对的)
                report($e);
                $ok = false;
                $msg = $e->getMessage();
            }

            $afterSale->status = $ok ? AfterSale::STATUS_REFUNDED : AfterSale::STATUS_APPROVED;
            $afterSale->reviewer_id = $reviewerId;
            $afterSale->reviewed_at = now();
            $afterSale->save();

            RefundRequest::create([
                'after_sale_id'    => $afterSale->id,
                'wechat_refund_no' => $outRefundNo,
                'amount'           => $amount,
                'status'           => $ok ? 'success' : 'pending',
            ]);

            // M8 · 退款/售后审批留痕。此前 Shop 侧资金动作一处都不写 operation_log。
            // 这是"钱出去"的动作,用 logOrFail():审计写不进去就整个回滚,不留无痕退款。
            $this->oplog->logOrFail(
                OperationLog::ACTOR_ADMIN,
                $reviewerId,
                'after_sale.approve',
                'after_sale',
                $afterSale->id,
                [
                    'entitlement_id' => $afterSale->entitlement_id,
                    'order_item_id'  => $afterSale->order_item_id,
                    'amount'         => $amount,
                    'refund_no'      => $outRefundNo,
                    'refunded'       => $ok,
                ]
            );

            return [
                'ok'      => true,
                'message' => $ok ? '已退款并冻结授权。' : '已冻结授权,退款待支付资质就绪后补打款。',
                'data'    => ['id' => $afterSale->id, 'status' => $afterSale->status, 'amount' => $amount],
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
     * 该订单条目**已发起**的退款累计(success + pending,不含被拒)。
     */
    protected function refundedAmountOf(?int $orderItemId): float
    {
        if (! $orderItemId) {
            return 0.0;
        }

        return (float) RefundRequest::query()
            ->join('after_sale', 'after_sale.id', '=', 'refund_request.after_sale_id')
            ->where('after_sale.order_item_id', $orderItemId)
            ->whereIn('refund_request.status', ['success', 'pending'])
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
