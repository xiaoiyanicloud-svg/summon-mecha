<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

final class ReconcileReport
{
    public function __construct(
        public readonly string $did,
        public readonly string $modelCode,
        public readonly int $observedGeneration,
        public readonly array $installedSkills,
        public readonly array $activeExecutions,
        public readonly ?string $outboxCursor,
        public readonly int $reportedAt,
    ) {
        if ($did === '' || $modelCode === '') {
            throw new InvalidArgumentException('ReconcileReport 设备身份不得为空');
        }
        if ($observedGeneration < 0 || $reportedAt <= 0) {
            throw new InvalidArgumentException('ReconcileReport 代次或上报时间非法');
        }
        if ($outboxCursor !== null && strlen($outboxCursor) > 128) {
            throw new InvalidArgumentException('ReconcileReport.outbox_cursor 过长');
        }
        foreach ($installedSkills as $entry) {
            if (! is_array($entry)
                || (int) ($entry['skill_version_id'] ?? 0) <= 0
                || trim((string) ($entry['status'] ?? '')) === '') {
                throw new InvalidArgumentException('ReconcileReport.installed_skills 条目非法');
            }
        }
        foreach ($activeExecutions as $entry) {
            if (! is_array($entry)
                || trim((string) ($entry['execution_id'] ?? '')) === ''
                || trim((string) ($entry['status'] ?? '')) === '') {
                throw new InvalidArgumentException('ReconcileReport.active_executions 条目非法');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'did' => $this->did,
            'model_code' => $this->modelCode,
            'observed_generation' => $this->observedGeneration,
            'installed_skills' => $this->installedSkills,
            'active_executions' => $this->activeExecutions,
            'outbox_cursor' => $this->outboxCursor,
            'reported_at' => $this->reportedAt,
        ];
    }
}