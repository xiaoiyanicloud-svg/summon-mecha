<?php

namespace Webkul\Shop\Services\SkillDelivery;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\RefundRequest;
use Webkul\Shop\Models\SkillEntitlement;
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
    public function __construct(protected PaymentManager $payments) {}

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

            // 2) 发起退款
            $gateway = $this->payments->gateway();
            $outRefundNo = 'RF'.$afterSale->id.now()->format('ymdHis');

            try {
                $result = $gateway->refund(null, (float) 0, $outRefundNo);
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
                'after_sale_id' => $afterSale->id,
                'amount'        => 0,
                'status'        => $ok ? 'success' : 'pending',
            ]);

            return [
                'ok'      => true,
                'message' => $ok ? '已退款并冻结授权。' : '已冻结授权,退款待支付资质就绪后补打款。',
                'data'    => ['id' => $afterSale->id, 'status' => $afterSale->status],
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

        return ['ok' => true, 'message' => '已驳回。', 'data' => ['id' => $afterSale->id]];
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
