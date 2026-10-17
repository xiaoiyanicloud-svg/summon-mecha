<?php

declare(strict_types=1);

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\SkillEntitlement;

/**
 * Agent 下发通道 —— **授权侧**的实现。
 *
 * 诚实边界（本通道存在的全部理由）—— 它**不创建任何下发任务**:
 *   · 真正的下发由 `deploy_task` 承担(试用见 `TrialService::start()`,设备确权后的
 *     重跑见 `DeviceController::claimPendingEntitlements()`)；
 *   · 本通道只负责两件事:① 能解析(`SkillDeliveryManager::channel('agent')` 不再抛
 *     `InvalidArgumentException`);② `deliver()` 时**如实回报**这条授权到底有没有下发任务。
 *
 * ⚠ 此前 `config/skill_delivery.php` 的注册表里只有 `app`,而 `TrialService` 把试用授权的
 *   `delivery_channel` 写成 `agent` —— 每个调用点(DeviceController / SkillController /
 *   Listeners\SkillDelivery / AfterSaleService)都有 try/catch,所以不会 500,但会把
 *   **正常的试用授权记成"交付失败"**:日志噪音 + 计数失真。补通道而不是把试用的
 *   `delivery_channel` 改成 `app`,因为那会在用户侧把"Agent 下发"错标成"应用内交付"。
 *
 * ⚠ `deliver()` 必须幂等,且**不改授权状态**:状态由 `deploy_task` 的状态机驱动
 *   (安装回报后 `DeployTaskService::onEvent()` 推进),本通道没有资格改它。
 */
class AgentChannel implements DeliveryChannel
{
    /**
     * {@inheritdoc}
     */
    public function key(): string
    {
        return 'agent';
    }

    /**
     * {@inheritdoc}
     */
    public function label(): string
    {
        return 'Agent 下发';
    }

    /**
     * {@inheritdoc}
     *
     * 授权自身是否可用(未撤销 / 未过期 / 未在回收中)—— 与 AppChannel 同一口径,
     * 不另造判断。
     */
    public function canDeliver(SkillEntitlement $entitlement): bool
    {
        return $entitlement->isUsable();
    }

    /**
     * {@inheritdoc}
     *
     * 幂等:只读不写。找到「本轮交付」任务就如实回报 task_no/status;找不到就**明确失败**
     * —— 这正是本卡要消灭的那种"看起来成功、其实什么都没发生"的静默。
     */
    public function deliver(SkillEntitlement $entitlement, array $context = []): DeliveryResult
    {
        if (! $this->canDeliver($entitlement)) {
            return DeliveryResult::fail('授权已撤销或已过期,无法交付');
        }

        $task = $this->deliveryTaskFor($entitlement);

        if (! $task) {
            return DeliveryResult::fail('找不到下发任务:agent 通道没有实际交付');
        }

        return DeliveryResult::ok('下发任务已存在,实际交付由 deploy_task 承担', [
            'task_no'        => $task->task_no,
            'status'         => $task->status,
            'operation'      => $task->operation,
            'entitlement_id' => $entitlement->id,
        ]);
    }

    /**
     * {@inheritdoc}
     *
     * 只把授权置 revoked —— 与 AppChannel 同口径。
     *
     * ⚠ **不在这里造卸载任务**:卸载 / 回收是熔断(kill switch)与到期回收的职责,
     *   本通道越界创建任务会让"谁在什么时候把技能从设备上撤掉"变得不可追溯。
     */
    public function revoke(SkillEntitlement $entitlement): void
    {
        if ($entitlement->status === SkillEntitlement::STATUS_REVOKED) {
            return;
        }

        $entitlement->status = SkillEntitlement::STATUS_REVOKED;
        $entitlement->revoked_at = now();
        $entitlement->save();
    }

    /**
     * 该授权的「本轮交付」任务。
     *
     * 只认**会往设备写入交付物**的操作(`DeployTask::PACKAGE_OPERATIONS` = 安装 / 回滚):
     * 一条回收(operation=6)或启停任务不是交付,拿它来回报"已下发"就是谎报。
     * 取最新一条 —— 重装 / 升级后会留下多条任务,最近的才是本轮。
     */
    protected function deliveryTaskFor(SkillEntitlement $entitlement): ?DeployTask
    {
        if (! $entitlement->exists) {
            return null;
        }

        return DeployTask::query()
            ->where('entitlement_id', $entitlement->id)
            ->whereIn('operation', DeployTask::PACKAGE_OPERATIONS)
            ->orderByDesc('id')
            ->first();
    }
}
