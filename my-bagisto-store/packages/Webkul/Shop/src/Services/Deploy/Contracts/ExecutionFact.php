<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/**
 * 技能执行事实。
 *
 * 关键语义：RPC/SDK/BT 的“接受”只推进到 RUNNING；只有设备侧独立观察器
 * 报告 actual_stopped=true 时，SUCCEEDED/FAILED/CANCELED 才可成立。
 * 无法确认停止时唯一安全的终态是 UNKNOWN，且资源租约必须保持占用。
 */
final class ExecutionFact
{
    public const STATUSES = ['ACCEPTED', 'RUNNING', 'CANCEL_REQUESTED', 'SUCCEEDED', 'FAILED', 'CANCELED', 'UNKNOWN'];

    public function __construct(
        public readonly string $executionId,
        public readonly string $observedStatus,
        public readonly bool $actualStarted,
        public readonly bool $actualStopped,
        public readonly ?int $startedAt,
        public readonly ?int $stoppedAt,
        public readonly ?string $errorCode,
        public readonly array $evidence,
        public readonly int $reportedAt,
        public readonly ?StopObservation $stopObservation = null,
    ) {
        if (trim($executionId) === '') {
            throw new InvalidArgumentException('ExecutionFact.execution_id 不得为空');
        }
        if (! in_array($observedStatus, self::STATUSES, true)) {
            throw new InvalidArgumentException('ExecutionFact.observed_status 非法');
        }
        if ($reportedAt <= 0) {
            throw new InvalidArgumentException('ExecutionFact.reported_at 非法');
        }
        if ($evidence === []) {
            throw new InvalidArgumentException('ExecutionFact.evidence 不得为空');
        }
        if ($startedAt !== null && $startedAt <= 0) {
            throw new InvalidArgumentException('ExecutionFact.started_at 非法');
        }
        if ($stoppedAt !== null && $stoppedAt <= 0) {
            throw new InvalidArgumentException('ExecutionFact.stopped_at 非法');
        }
        if ($startedAt !== null && $stoppedAt !== null && $stoppedAt < $startedAt) {
            throw new InvalidArgumentException('ExecutionFact.stopped_at 不得早于 started_at');
        }
        if ($actualStarted && $startedAt === null) {
            throw new InvalidArgumentException('actual_started=true 必须提供 started_at');
        }
        if ($actualStopped && $stoppedAt === null) {
            throw new InvalidArgumentException('actual_stopped=true 必须提供 stopped_at');
        }

        match ($observedStatus) {
            'ACCEPTED' => $this->assertAccepted(),
            'RUNNING' => $this->assertRunning(),
            'CANCEL_REQUESTED' => $this->assertCancelRequested(),
            'SUCCEEDED', 'FAILED', 'CANCELED' => $this->assertStoppedTerminal(),
            'UNKNOWN' => $this->assertUnknown(),
        };
    }

    public static function fromArray(array $data): self
    {
        return new self(
            executionId: (string) ($data['execution_id'] ?? ''),
            observedStatus: (string) ($data['observed_status'] ?? ''),
            actualStarted: (bool) ($data['actual_started'] ?? false),
            actualStopped: (bool) ($data['actual_stopped'] ?? false),
            startedAt: isset($data['started_at']) ? (int) $data['started_at'] : null,
            stoppedAt: isset($data['stopped_at']) ? (int) $data['stopped_at'] : null,
            errorCode: isset($data['error_code']) ? (string) $data['error_code'] : null,
            evidence: is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
            reportedAt: (int) ($data['reported_at'] ?? 0),
            stopObservation: is_array($data['stop_observation'] ?? null)
                ? StopObservation::fromArray($data['stop_observation'])
                : null,
        );
    }

    private function assertAccepted(): void
    {
        if ($this->actualStarted || $this->actualStopped) {
            throw new InvalidArgumentException('ACCEPTED 不得声称已启动或已停止');
        }
    }

    private function assertRunning(): void
    {
        if (! $this->actualStarted || $this->actualStopped) {
            throw new InvalidArgumentException('RUNNING 必须 actual_started=true 且 actual_stopped=false');
        }
    }

    private function assertCancelRequested(): void
    {
        if ($this->actualStopped) {
            throw new InvalidArgumentException('CANCEL_REQUESTED 不得标记 actual_stopped=true');
        }
    }

    private function assertStoppedTerminal(): void
    {
        if (! $this->actualStopped) {
            throw new InvalidArgumentException("{$this->observedStatus} 必须由独立观测确认 actual_stopped=true");
        }
        if ($this->observedStatus === 'SUCCEEDED' && ! $this->actualStarted) {
            throw new InvalidArgumentException('SUCCEEDED 必须 actual_started=true');
        }

        // 红线上限：没有独立 StopObservation 就不得进入终态。
        if ($this->stopObservation === null) {
            throw new InvalidArgumentException("{$this->observedStatus} 必须携带独立 StopObservation");
        }

        if (! $this->stopObservation->actualStopped) {
            throw new InvalidArgumentException('StopObservation 必须确认 actual_stopped=true');
        }

        if (! $this->stopObservation->isAcceptable()) {
            throw new InvalidArgumentException('StopObservation 质量或物理量不满足停止判据');
        }

        if (! $this->stopObservation->isFresh($this->reportedAt)) {
            throw new InvalidArgumentException('StopObservation 已过期或时间戳异常');
        }
    }

    private function assertUnknown(): void
    {
        if ($this->actualStopped) {
            throw new InvalidArgumentException('UNKNOWN 不得声称 actual_stopped=true；应报告 FAILED/CANCELED');
        }
    }

    public function isTerminal(): bool
    {
        return in_array($this->observedStatus, ['SUCCEEDED', 'FAILED', 'CANCELED', 'UNKNOWN'], true);
    }

    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'observed_status' => $this->observedStatus,
            'actual_started' => $this->actualStarted,
            'actual_stopped' => $this->actualStopped,
            'started_at' => $this->startedAt,
            'stopped_at' => $this->stoppedAt,
            'error_code' => $this->errorCode,
            'evidence' => $this->evidence,
            'reported_at' => $this->reportedAt,
            'stop_observation' => $this->stopObservation?->toArray(),
        ];
    }
}