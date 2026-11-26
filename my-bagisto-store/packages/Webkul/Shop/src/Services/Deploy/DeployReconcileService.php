<?php

namespace Webkul\Shop\Services\Deploy;

use DomainException;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceReconcileReport;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillExecution;
use Webkul\Shop\Services\Deploy\Contracts\ReconcileReport;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;

/** 设备代次对账：比较设备观察事实与服务端投影，不把对账报告当作自动“修好”。 */
class DeployReconcileService
{
    public function __construct(
        protected SigningService $signing,
        protected DeployMetricCounter $metrics,
    ) {}

    public function reconcile(DeviceBinding $binding, array $payload): array
    {
        $did = (string) $payload['did'];
        $modelCode = (string) $payload['model_code'];
        $allowedModels = TargetProfileService::GA_TARGET_MODELS;
        if (app()->environment('testing') && $modelCode === 'mock') {
            $allowedModels[] = 'mock';
        }
        if (! in_array($modelCode, $allowedModels, true)) {
            throw new DomainException('RECONCILE_MODEL_NOT_GA_TARGET');
        }

        $expectedModel = $binding->model?->model_code
            ?? ($binding->meta['capability_snapshot']['model_code'] ?? null);
        if ($expectedModel && $expectedModel !== $modelCode) {
            throw new DomainException('RECONCILE_MODEL_MISMATCH');
        }

        $report = new ReconcileReport(
            did: $did,
            modelCode: $modelCode,
            observedGeneration: (int) $payload['observed_generation'],
            installedSkills: is_array($payload['skills'] ?? null) ? $payload['skills'] : [],
            activeExecutions: is_array($payload['active_executions'] ?? null) ? $payload['active_executions'] : [],
            outboxCursor: isset($payload['outbox_cursor']) ? (string) $payload['outbox_cursor'] : null,
            reportedAt: (int) $payload['reported_at'],
        );

        $serverRecords = InstallRecord::query()
            ->where('did', $did)
            ->get()
            ->keyBy('skill_version_id');

        $processedVersions = [];
        $details = [];
        $aligned = true;

        foreach ($report->installedSkills as $clientSkill) {
            $verId = (int) ($clientSkill['skill_version_id'] ?? 0);
            $clientStatus = (string) ($clientSkill['status'] ?? '');
            $processedVersions[$verId] = true;
            $server = $serverRecords->get($verId);

            if (! $server) {
                $aligned = false;
                $details[] = [
                    'skill_version_id' => $verId,
                    'client_status' => $clientStatus,
                    'server_status' => null,
                    'match' => false,
                    'drift' => 'CLIENT_ONLY',
                ];
                continue;
            }

            $match = $clientStatus === (string) $server->status;
            $aligned = $aligned && $match;
            $details[] = [
                'skill_version_id' => $verId,
                'client_status' => $clientStatus,
                'server_status' => (string) $server->status,
                'match' => $match,
                'drift' => $match ? null : 'STATUS_MISMATCH',
            ];
        }

        foreach ($serverRecords as $verId => $record) {
            if (! isset($processedVersions[(int) $verId])) {
                $aligned = false;
                $details[] = [
                    'skill_version_id' => (int) $verId,
                    'client_status' => null,
                    'server_status' => (string) $record->status,
                    'match' => false,
                    'drift' => 'SERVER_ONLY',
                ];
            }
        }

        $clientTasks = collect($payload['tasks'] ?? [])->keyBy('task_no');
        $serverTasks = DeployTask::query()
            ->where('did', $did)
            ->whereIn('task_no', $clientTasks->keys()->all())
            ->get()
            ->keyBy('task_no');
        $taskDetails = [];

        foreach ($clientTasks as $taskNo => $clientTask) {
            $serverTask = $serverTasks->get($taskNo);
            $clientStatus = (string) ($clientTask['status'] ?? '');
            if (! $serverTask) {
                $aligned = false;
                $taskDetails[] = [
                    'task_no' => $taskNo,
                    'client_status' => $clientStatus,
                    'server_status' => null,
                    'match' => false,
                    'drift' => 'CLIENT_ONLY',
                ];
                continue;
            }

            $match = $clientStatus === (string) $serverTask->status;
            $aligned = $aligned && $match;
            $taskDetails[] = [
                'task_no' => $taskNo,
                'client_status' => $clientStatus,
                'server_status' => (string) $serverTask->status,
                'match' => $match,
                'drift' => $match ? null : 'STATUS_MISMATCH',
            ];
        }

        $clientExecutionIds = collect($report->activeExecutions)->pluck('execution_id')->filter()->all();
        $serverExecutions = SkillExecution::query()
            ->where('did', $did)
            ->whereIn('status', [SkillExecution::STATUS_ACCEPTED, SkillExecution::STATUS_RUNNING, SkillExecution::STATUS_CANCEL_REQUESTED, SkillExecution::STATUS_UNKNOWN])
            ->whereNotIn('execution_id', $clientExecutionIds)
            ->get();
        foreach ($serverExecutions as $serverExecution) {
            $aligned = false;
            $taskDetails[] = [
                'execution_id' => $serverExecution->execution_id,
                'client_status' => null,
                'server_status' => $serverExecution->status,
                'match' => false,
                'drift' => 'ACTIVE_EXECUTION_SERVER_ONLY',
            ];
        }

        $previousGeneration = (int) (DeviceReconcileReport::query()
            ->where('did', $did)
            ->max('observed_generation') ?? 0);
        $stale = $report->observedGeneration < $previousGeneration;
        if ($stale) {
            $aligned = false;
        }

        $reportHash = hash('sha256', $this->signing->jcsCanonicalize($report->toArray()));
        $stored = DeviceReconcileReport::firstOrCreate(
            ['did' => $did, 'report_hash' => $reportHash],
            [
                'model_code' => $modelCode,
                'observed_generation' => $report->observedGeneration,
                'installed_skills' => $report->installedSkills,
                'active_executions' => $report->activeExecutions,
                'outbox_cursor' => $report->outboxCursor,
                'drift_count' => count($details) + count($taskDetails),
                'reported_at' => $report->reportedAt,
                'received_at' => now(),
            ],
        );

        // A3：偏差计数只在**新报告**落库时累加 —— 设备重发同一份报告（同一 report_hash）
        // 不该让"偏差次数"再涨一次，否则指标会把"网络重试"读成"又漂了一次"。
        $driftCount = count($details) + count($taskDetails);
        if ($stored->wasRecentlyCreated && $driftCount > 0) {
            $this->metrics->increment('reconcile_drift_total', $driftCount);
        }

        return [
            'did' => $did,
            'model_code' => $modelCode,
            'observed_generation' => $report->observedGeneration,
            'previous_generation' => $previousGeneration,
            'stale_generation' => $stale,
            'server_time' => now()->timestamp,
            'aligned' => $aligned,
            'report_hash' => $reportHash,
            'duplicate' => ! $stored->wasRecentlyCreated,
            'drift_count' => count($details) + count($taskDetails),
            'details' => $details,
            'task_details' => $taskDetails,
        ];
    }
}