<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Shop\Models\DeployInboxEvent;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Services\Deploy\Contracts\ExecutionFact;
use Webkul\Shop\Services\Deploy\Contracts\InboxEvent;

/**
 * 平台 Inbox。
 *
 * 所有可改变业务状态或需要审计的设备事实都先以 `(did,event_id)` 原子入账。
 * 同键同载荷只返回第一次结果；同键异载荷必须冲突；状态应用与事实入账同事务，
 * 不能出现“状态推进了但 Inbox 没记录”或反之。
 */
class DeployInboxService
{
    protected const TERMINAL_FACT_PHASES = ['done', 'failed', 'timeout', 'unknown', 'canceled'];

    public function __construct(
        protected DeployTaskService $deploy,
        protected SigningService $signing,
        protected DeployMetricCounter $metrics,
    ) {}

    public function acknowledge(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $task = DeployTask::query()
                ->where('task_no', $payload['task_no'])
                ->where('did', $payload['did'])
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return ['not_found' => true];
            }

            $serverStatus = (string) $task->status;
            $hash = $this->payloadHash($payload);
            $existing = $this->existingEvent($payload['did'], $payload['event_id']);

            if ($existing) {
                return $this->duplicateResult($existing, $hash, $task);
            }

            $event = $this->createEvent(
                payload: $payload,
                eventType: 'ack',
                stage: (string) $payload['stage'],
                payloadHash: $hash,
                applied: true,
                resultStatus: (string) $task->status,
                lateReport: false,
            );

            $this->deploy->markDelivered($task);
            $this->clearLease($task);

            Log::info('deploy.inbox.ack_processed', [
                'service' => 'skill-deploy',
                'env' => app()->environment(),
                'task_id' => $payload['task_no'],
                'did' => $payload['did'],
                'event_id' => $payload['event_id'],
                'stage' => $payload['stage'],
                'ack_id' => $event->ack_id,
                'duplicate' => false,
            ]);

            return ['duplicate' => false, 'event' => $event, 'task' => $task->fresh()];
        });
    }

    /**
     * 幂等处理任务/执行事实，并在同一数据库事务内推进状态机。
     *
     * @return array{not_found?:bool,conflict?:bool,duplicate?:bool,late_report?:bool,event?:DeployInboxEvent,task?:DeployTask}
     */
    public function processFact(array $payload, ?ExecutionFact $fact = null): array
    {
        return DB::transaction(function () use ($payload, $fact) {
            $task = DeployTask::query()
                ->where('task_no', $payload['task_no'])
                ->where('did', $payload['did'])
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return ['not_found' => true];
            }

            $serverStatus = (string) $task->status;
            $hash = $this->payloadHash($payload);
            $existing = $this->existingEvent($payload['did'], $payload['event_id']);

            if ($existing) {
                return $this->duplicateResult($existing, $hash, $task);
            }

            $event = $this->createEvent(
                payload: $payload,
                eventType: $fact ? 'execution_fact' : 'task_fact',
                stage: $fact?->observedStatus ?? (string) $payload['phase'],
                payloadHash: $hash,
            );

            $lateReport = false;

            try {
                $updatedTask = $this->deploy->onEvent(
                    $payload['task_no'],
                    $payload['phase'],
                    $payload['error_code'] ?? null,
                    $payload['error_msg'] ?? null,
                    $fact,
                    isset($payload['event_seq']) ? (int) $payload['event_seq'] : null,
                );
            } catch (IllegalTaskTransitionException $exception) {
                if (! $this->isAllowedLateReport($exception->from, $payload['phase'])) {
                    throw $exception;
                }

                $lateReport = true;
                $updatedTask = $task->fresh();
                $event->payload = array_merge($event->payload ?? [], [
                    'late_report' => [
                        'accepted_at' => now()->timestamp,
                        'attempted_phase' => $payload['phase'],
                        'server_status' => $updatedTask->status,
                    ],
                ]);
            }

            if (! $updatedTask) {
                throw new \RuntimeException('Inbox 事实对应的任务在状态机中不可见');
            }

            $this->deploy->markDelivered($updatedTask);
            $this->clearLease($updatedTask);

            $event->applied = ! $lateReport;
            $event->result_status = (string) $updatedTask->status;
            $event->late_report = $lateReport;
            $event->processed_at = now();
            $event->save();

            Log::info($lateReport ? 'deploy.inbox.late_fact_recorded' : 'deploy.inbox.fact_processed', [
                'service' => 'skill-deploy',
                'env' => app()->environment(),
                'task_id' => $payload['task_no'],
                'did' => $payload['did'],
                'event_id' => $payload['event_id'],
                'event_type' => $event->event_type,
                'stage' => $event->stage,
                'result_status' => $event->result_status,
                'late_report' => $lateReport,
                'ack_id' => $event->ack_id,
            ]);

            return [
                'duplicate' => false,
                'late_report' => $lateReport,
                'terminal_conflict' => $lateReport && $serverStatus !== 'timeout',
                'event' => $event->fresh(),
                'task' => $updatedTask->fresh(),
            ];
        });
    }

    protected function existingEvent(string $did, string $eventId): ?DeployInboxEvent
    {
        return DeployInboxEvent::query()
            ->where('did', $did)
            ->where('event_id', $eventId)
            ->lockForUpdate()
            ->first();
    }

    protected function duplicateResult(DeployInboxEvent $existing, string $hash, DeployTask $task): array
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            // A3.2：同 event_id 异载荷 = 设备/攻击者试图用旧 id 覆盖新事实。
            // 这是安全信号，不只是"业务冲突"，必须计数。
            $this->metrics->increment('event_conflict_total');

            return ['conflict' => true, 'event' => $existing, 'task' => $task];
        }

        // 构造强类型事实，防止脏库中的半条 Inbox 记录被当成已处理事实。
        $this->contract($existing);

        // A3：幂等命中计数。**放在这里而不是调用方** —— 只有这一处能同时看到
        // "已存在"与"载荷一致"；放在控制器或事件处理器里会漏掉另一条通道，
        // 计数就不再是"幂等真的发生了几次"，而是"某条代码路径出现过几次"。
        $this->metrics->increment('idempotency_hit_total');

        return ['duplicate' => true, 'event' => $existing, 'task' => $task->fresh()];
    }

    protected function createEvent(
        array $payload,
        string $eventType,
        string $stage,
        string $payloadHash,
        bool $applied = false,
        ?string $resultStatus = null,
        bool $lateReport = false,
    ): DeployInboxEvent {
        $ackId = ($eventType === 'ack' ? 'ack_' : 'fact_') . Str::lower((string) Str::uuid());
        $eventSeq = isset($payload['event_seq']) ? (int) $payload['event_seq'] : null;

        // 先构造契约再落库，保证任何不合法事件都不会留下半条审计事实。
        new InboxEvent(
            did: (string) $payload['did'],
            eventId: (string) $payload['event_id'],
            taskNo: (string) $payload['task_no'],
            eventType: $eventType,
            stage: $stage,
            payloadHash: $payloadHash,
            payload: $payload,
            eventSeq: $eventSeq,
            applied: $applied,
            resultStatus: $resultStatus,
            lateReport: $lateReport,
            processedAt: null,
            ackId: $ackId,
        );

        return DeployInboxEvent::create([
            'did' => $payload['did'],
            'event_id' => $payload['event_id'],
            'task_no' => $payload['task_no'],
            'event_type' => $eventType,
            'event_seq' => $eventSeq,
            'stage' => $stage,
            'payload_hash' => $payloadHash,
            'payload' => $payload,
            'applied' => $applied,
            'result_status' => $resultStatus,
            'late_report' => $lateReport,
            'ack_id' => $ackId,
            'processed_at' => now(),
        ]);
    }

    protected function contract(DeployInboxEvent $event): InboxEvent
    {
        return new InboxEvent(
            did: (string) $event->did,
            eventId: (string) $event->event_id,
            taskNo: (string) $event->task_no,
            eventType: (string) $event->event_type,
            stage: (string) $event->stage,
            payloadHash: (string) $event->payload_hash,
            payload: is_array($event->payload) ? $event->payload : [],
            eventSeq: $event->event_seq !== null ? (int) $event->event_seq : null,
            applied: (bool) $event->applied,
            resultStatus: $event->result_status !== null ? (string) $event->result_status : null,
            lateReport: (bool) $event->late_report,
            processedAt: $event->processed_at?->timestamp,
            ackId: (string) $event->ack_id,
        );
    }

    protected function payloadHash(array $payload): string
    {
        return hash('sha256', $this->signing->jcsCanonicalize($payload));
    }

    protected function isAllowedLateReport(string $serverStatus, string $phase): bool
    {
        // 迟到事实必须入账；timeout 返回 200（服务端原本未知），
        // success/failed/cancelled 返回 409，但仍保留 late_report 审计，且终态不回退。
        return in_array($serverStatus, ['success', 'failed', 'timeout', 'cancelled'], true)
            && in_array($phase, self::TERMINAL_FACT_PHASES, true);
    }

    protected function clearLease(DeployTask $task): void
    {
        DeployTask::query()
            ->whereKey($task->id)
            ->update([
                'outbox_lease_token' => null,
                'outbox_lease_expires_at' => null,
            ]);
    }
}