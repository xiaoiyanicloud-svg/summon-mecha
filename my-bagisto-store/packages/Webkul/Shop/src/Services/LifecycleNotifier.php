<?php

namespace Webkul\Shop\Services;

use Throwable;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;

/**
 * 业务生命周期通知的**生产者**（B-2）。
 *
 * 背景：通知中心读写端早就通了（`NotificationController` + `pages/mine/notifications.vue`），
 * 但 `NotificationService::send()` 此前**只有 `AdminAlertService` 一个调用方**（发给 admin）
 * → `notification` 表 **0 行** → 用户的「消息通知」页永远空白，等于"功能看着有、实际没有"。
 * 本类把四类**用户侧业务事件**接上生产者：
 *   审核结果（售后通过/驳回）、退款到账、下发完成、试用到期。
 *
 * ⚠ 只做**站内信**（inapp）。微信订阅消息（subscribe_msg）属 A-3，本类不碰。
 *
 * ⚠ **通知是副作用，绝不能反过来搞挂主业务**：所有落库都包在 `safe()` 里吞异常 + `report()`
 *   —— 一次通知写不进去，不该让"退款已批""技能已下发"这些**已经发生的事实**回滚。
 *   （与 `DeviceController` 补建 binding 的防御姿态同源。）
 */
class LifecycleNotifier
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * 审核结果 —— 售后批准 / 驳回（B-2）。
     */
    public function afterSaleDecided(AfterSale $afterSale, bool $approved): void
    {
        $this->safe(function () use ($afterSale, $approved) {
            $this->notifications->toCustomer(
                (int) $afterSale->customer_id,
                $approved ? 'after_sale.approved' : 'after_sale.rejected',
                $approved ? '退款申请已通过' : '退款申请已驳回',
                $approved
                    ? '你的退款申请已通过审核，平台正在处理打款，到账后会再通知你。'
                    : '你的退款申请未通过审核。如有疑问，可在订单售后里查看说明。',
                ['after_sale_id' => $afterSale->id, 'status' => $afterSale->status],
            );
        });
    }

    /**
     * 退款到账（B-2）。
     *
     * ⚠ `$ok = false`（打款未成功）**不发**"到账" —— 到不了账就不是到账，
     *   臆造一条"已退款"通知，只会让用户以为钱到了。
     */
    public function refundSettled(AfterSale $afterSale, float $amount, bool $ok, ?string $refundNo = null): void
    {
        if (! $ok) {
            return;
        }

        $this->safe(function () use ($afterSale, $amount, $refundNo) {
            $this->notifications->toCustomer(
                (int) $afterSale->customer_id,
                'refund.settled',
                '退款已到账',
                '你的退款 ¥'.number_format($amount, 2).' 已发起，实际到账时间以支付渠道为准。',
                ['after_sale_id' => $afterSale->id, 'amount' => $amount, 'refund_no' => $refundNo],
            );
        });
    }

    /**
     * 下发完成 —— 技能已装到设备（B-2）。
     */
    public function deliveryCompleted(DeployTask $task): void
    {
        $this->notifyByTask(
            $task,
            'delivery.completed',
            '技能已下发',
            '你购买的技能已下发到设备。',
        );
    }

    /**
     * 试用到期 —— 试用回收任务完成（B-2）。
     */
    public function trialExpired(DeployTask $task): void
    {
        $this->notifyByTask(
            $task,
            'trial.expired',
            '试用已到期',
            '你的技能试用已到期，试用版本已从设备回收。',
        );
    }

    protected function notifyByTask(DeployTask $task, string $type, string $title, string $content): void
    {
        $customerId = $this->customerIdForTask($task);

        // 找不到归属（游客单 / 未确权设备）就不发 —— **不臆造一个收件人**
        if (! $customerId) {
            return;
        }

        $this->safe(function () use ($customerId, $type, $title, $content, $task) {
            $this->notifications->toCustomer(
                $customerId,
                $type,
                $title,
                $content,
                ['task_no' => $task->task_no, 'skill_id' => $task->skill_id, 'operation' => $task->operation],
            );
        });
    }

    /**
     * 下发任务 → 客户：优先走**授权归属**，退化到**设备绑定归属**（D1 真相源）。
     */
    protected function customerIdForTask(DeployTask $task): ?int
    {
        if ($task->entitlement_id) {
            $cid = SkillEntitlement::query()->whereKey($task->entitlement_id)->value('customer_id');

            if ($cid) {
                return (int) $cid;
            }
        }

        if ($task->did) {
            $cid = DeviceBinding::query()->where('did', $task->did)->value('owner_customer_id');

            if ($cid) {
                return (int) $cid;
            }
        }

        return null;
    }

    /**
     * 吞异常执行 —— 通知失败只留日志，绝不让业务动作失败。
     */
    protected function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
