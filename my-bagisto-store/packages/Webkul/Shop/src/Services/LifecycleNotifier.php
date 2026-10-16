<?php

namespace Webkul\Shop\Services;

use Throwable;
use Webkul\Shop\Models\AfterSale;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\RejectionReason;

/**
 * 业务生命周期通知的**生产者**（B-2）。
 *
 * 背景：通知中心读写端早就通了（`NotificationController` + `pages/mine/notifications.vue`），
 * 但 `NotificationService::send()` 此前**只有 `AdminAlertService` 一个调用方**（发给 admin）
 * → `notification` 表 **0 行** → 用户的「消息通知」页永远空白，等于"功能看着有、实际没有"。
 * 本类把四类**用户侧业务事件**接上生产者：
 *   审核结果（售后通过/驳回）、退款到账、下发完成、试用到期。
 *
 * 任务卡 D-4 又接上**开发者侧**两类：技能审核判定（L0/L2）与技能上架。
 * 它们发给的是"开发者本人的 customer 账号"（见 customerIdForSkillVersion 注释）。
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

    /**
     * 技能审核判定结果 —— L0 机审 / L2 人审（任务卡 D-4）。
     *
     * type 只有三种（approved / rejected / supplement），但 title/content 按**层级**说人话：
     * L0 通过只是"过了机审、进人工队列"，**不是终审通过** —— 若也写成"审核已通过"，
     * 开发者会以为马上能上架。判决的权威事实仍是 `audit_record`，这里只是它的投影。
     *
     * @param  int  $result  AuditRecord::RESULT_PASS / RESULT_REJECT / RESULT_SUPPLEMENT
     * @param  array  $context  {layer, reason_category, reason_detail}
     */
    public function skillReviewDecided(int $productId, int $versionId, int $result, array $context = []): void
    {
        // 整个方法体（**含归属解析的查询**）都包在 safe() 里：解析也要读库，读库同样可能炸；
        // 让一个"发通知"的副作用把"审核判定"这个已落库的权威事实炸成 500，是本卡明令禁止的。
        $this->safe(function () use ($productId, $versionId, $result, $context) {
            $customerId = $this->customerIdForSkillVersion($versionId);

            // 找不到归属（平台自营 / 版本不存在）就不发 —— **不臆造一个收件人**
            if (! $customerId) {
                return;
            }

            $layer    = (int) ($context['layer'] ?? AuditRecord::LAYER_HUMAN);
            $category = $context['reason_category'] ?? null;
            $detail   = $context['reason_detail'] ?? null;

            [$type, $title, $content] = $this->reviewMessage($result, $layer, $category, $detail);

            $this->notifications->toCustomer(
                $customerId,
                $type,
                $title,
                $content,
                [
                    'product_id'      => $productId,
                    'version_id'      => $versionId,
                    'reason_category' => $category,
                    'reason_detail'   => $detail,
                ],
            );
        });
    }

    /**
     * 开发者实名**被驳回**（S-12c）—— 运营逐层裁定后告知开发者。
     *
     * 收件人 = 开发者本人的 customer 账号（与 `skillReviewDecided` 同源：开发者就是那个客户，
     * 用同一批 `notification` 行）。于是**用户侧「消息通知」页**（`API\NotificationController`
     * 按 `recipient_type='customer'` 取全部，无 type 前缀过滤）就能看到这条 ——
     * 开发者不必在群里追问"我到底哪一层没过、为什么没过"。
     *
     * ⚠ 类型用 `realname.rejected`（**刻意不带 `skill.` 前缀**）。
     *   后果要如实说清：开发者**门户收件箱**的可见范围是 `Notification::scopeDeveloperInbox`，
     *   它只收 `skill.%` —— 所以**这一条不会出现在门户收件箱**，只在用户侧消息中心可见。
     *   本卡**没有**为了让它进门户而改写 D-4 定下的 `skill.%` 口径（那属未报批的读侧变更）；
     *   这是 S-12c 如实报告的一处未闭合，最小修法见交付报告 R4 段。
     */
    public function developerRealnameRejected(DeveloperProfile $profile, string $layer, string $reason): void
    {
        $this->safe(function () use ($profile, $layer, $reason) {
            $customerId = (int) $profile->customer_id;

            // 找不到归属就不发 —— **不臆造一个收件人**
            if (! $customerId) {
                return;
            }

            $label = $layer === DeveloperProfile::REALNAME_LAYER_COMPANY
                ? '企业层（公司认证）'
                : '操作人层（操作人认证）';

            $this->notifications->toCustomer(
                $customerId,
                'realname.rejected',
                '实名审核未通过',
                "你的实名资料「{$label}」未通过审核。理由：{$reason}。"
                    .'实名未通过期间不能创建技能，也不能上架技能；修正后可重新提交。',
                [
                    'layer'                => $layer,
                    'reason'               => $reason,
                    'developer_profile_id' => $profile->id,
                ],
            );
        });
    }

    /**
     * 技能已上架（任务卡 D-4）—— 发布成功后告知开发者，商品已经真正可售。
     */
    public function skillPublished(int $productId, int $versionId, string $version): void
    {
        $this->safe(function () use ($productId, $versionId, $version) {
            $customerId = $this->customerIdForSkillVersion($versionId);

            if (! $customerId) {
                return;
            }

            $this->notifications->toCustomer(
                $customerId,
                'skill.published',
                '技能已上架',
                "你的技能版本 {$version} 已上架，用户现在可以买到。",
                ['product_id' => $productId, 'version_id' => $versionId, 'version' => $version],
            );
        });
    }

    /**
     * 审核结果 → [type, title, content]。
     *
     * 分开成纯函数：文案随层级/理由变化，混在 send 里会变成一坨难读的嵌套三元。
     */
    protected function reviewMessage(int $result, int $layer, ?string $category, ?string $detail): array
    {
        if ($result === AuditRecord::RESULT_PASS) {
            return $layer === AuditRecord::LAYER_MACHINE
                ? ['skill.review.approved', '已通过机审', '你的技能版本已通过 L0 机审，正在等待人工审核。']
                : ['skill.review.approved', '技能审核已通过', '你的技能版本已通过审核，平台上架后即可对外销售。'];
        }

        // 理由摘要要说人话：理由码翻成人话（RejectionReason::LABELS），再拼上审核员填的明细
        $parts = [];

        if ($category) {
            $parts[] = RejectionReason::label($category);
        }

        if ($detail) {
            $parts[] = $detail;
        }

        $reason = $parts ? '理由：'.implode('：', $parts) : '';

        if ($result === AuditRecord::RESULT_SUPPLEMENT) {
            return ['skill.review.supplement', '技能审核需补充材料', '你的技能版本还需补充材料。'.$reason];
        }

        return ['skill.review.rejected', '技能审核未通过', '你的技能版本未通过审核。'.$reason];
    }

    /**
     * 技能版本 → 开发者本人的 `customer_id`（任务卡 D-4）。
     *
     * ⚠ 为什么收件人是 **customer 而不是 developer**：开发者**就是那个客户**（同一账号，
     *   `developer_profile.customer_id` 唯一指向一行 customers）。用 customer 意味着
     *   用户侧「消息通知」页与门户收件箱读的是同一批行 —— 读侧一行都不用改。
     *   若改写成 `recipient_type='developer'`，就会产生一类**谁都读不到**的通知
     *   （`NotificationController` 硬过滤 customer）—— 又回到"功能看着有、实际没有"。
     *
     * 归属链：`skill_versions.seller_id` → `developer_profile.customer_id`。
     * 解析不到（平台自营 seller_id 为 NULL / 版本不存在）返回 null → 调用方**不发**。
     */
    protected function customerIdForSkillVersion(int $versionId): ?int
    {
        $sellerId = SkillVersion::query()->whereKey($versionId)->value('seller_id');

        if (! $sellerId) {
            return null;
        }

        $customerId = DeveloperProfile::query()->where('seller_id', $sellerId)->value('customer_id');

        return $customerId ? (int) $customerId : null;
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
