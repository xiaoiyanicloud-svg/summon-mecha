<?php

namespace Webkul\Shop\Services\Deploy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Models\DeployInboxEvent;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\DeviceReconcileReport;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\SkillExecution;

/** 只读运行指标快照。未埋点的指标返回 null，禁止伪造 0。 */
class GaOperationalMetrics
{
    public function __construct(protected ?DeployMetricCounter $counters = null)
    {
        $this->counters ??= app(DeployMetricCounter::class);
    }

    public function snapshot(): array
    {
        $now = now();
        $oldestPending = DeployTask::query()
            ->whereIn('status', ['pending', 'pending_online', 'dispatching'])
            ->whereNull('delivered_at')
            ->min('created_at');

        $executionCounts = SkillExecution::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $unknown = (int) ($executionCounts['UNKNOWN'] ?? 0);
        $activeLeases = SkillExecution::query()
            ->whereIn('status', ['RUNNING', 'CANCEL_REQUESTED', 'UNKNOWN'])
            ->count();

        $outboxAge = $oldestPending ? max(0, $now->diffInSeconds($oldestPending)) : 0;

        // A3.2：`outbox_pending_age_seconds` 的**设备侧**口径（gauge）。
        //   旧字段是"平台任务等待下发多久"，与设备 outbox 是两件事，故拆成两个名字：
        //     · platform_outbox_pending_age_seconds —— 平台侧派生
        //     · outbox_pending_age_seconds          —— 设备上报的最大值（从未上报 = null）
        $deviceOutboxAge = Schema::hasTable(DeployMetricCounter::GAUGE_TABLE)
            ? $this->counters->gaugeMax('outbox_pending_age_seconds')
            : null;
        $effectiveOutboxAge = max($outboxAge, $deviceOutboxAge ?? 0);

        // A3.1：有界 label 集合的**契约 + 当前基数**。
        //   did 只作为"已知设备集合"的键出现（基数 = 设备数），不进入任何时间序列；
        //   model_code / operation / status / error_code 都取自闭集（枚举/白名单），
        //   不会随运行时长增长。这就是"有界"的可验证含义。
        $labelContract = $this->labelContract();
        $lateFacts = DeployInboxEvent::query()->where('late_report', true)->count();
        $reconcileDrifts = Schema::hasTable('device_reconcile_report')
            ? DeviceReconcileReport::query()->where('drift_count', '>', 0)->count()
            : 0;
        $missingStopEvidence = SkillExecution::query()
            ->whereIn('status', ['SUCCEEDED', 'FAILED', 'CANCELED'])
            ->where('actual_stopped', false)
            ->count();
        $queueLag = null;
        if (Schema::hasTable('jobs')) {
            $oldestJob = DB::table('jobs')->min('available_at');
            $queueLag = $oldestJob ? max(0, $now->timestamp - (int) $oldestJob) : 0;
        }

        $counterValues = $this->counters->counters();

        // `agent_restart_total` 统计的是**设备侧进程启动次数**（累计：第一次启动 = 1，
        // 之后每次重启再 +1）。所以"发生重启"不能写成 `> 0` —— 那等于任何设备
        // 第一次上线就报"有过重启"，告警一上线就是噪音。
        // 判据：累计启动次数 > 上报过指标的设备数 ⇒ 至少有一台设备启动过第二次。
        // 上报设备数直接取自批次登记表（每台设备一个 did），不是估算。
        $reportingDevices = Schema::hasTable('deploy_agent_metric_batches')
            ? (int) DB::table('deploy_agent_metric_batches')->distinct()->count('did')
            : 0;
        $agentStarts = (int) ($counterValues['agent_restart_total'] ?? 0);

        return [
            'generated_at' => $now->toIso8601String(),
            'agent_command_received_total' => DeployTask::query()->count(),
            'execution_status_total' => $executionCounts,
            'execution_unknown_total' => $unknown,
            // 设备侧 gauge（WBS A3.2 的字面项）；从未上报过 → null，绝不伪造成 0。
            'outbox_pending_age_seconds' => $deviceOutboxAge,
            // 平台侧派生口径（旧字段改名，避免两个含义共用一个名字）。
            'platform_outbox_pending_age_seconds' => $outboxAge,
            // A3.1：有界 label 契约 + 当前基数 + 按维度聚合。
            'label_contract' => $labelContract,
            'label_totals' => $this->labelTotals(),
            'queue_lag_seconds' => $queueLag,
            'active_device_leases' => $activeLeases,
            'inbox_ack_total' => DeployInboxEvent::query()->where('event_type', 'ack')->count(),
            'inbox_fact_total' => DeployInboxEvent::query()->whereIn('event_type', ['task_fact', 'execution_fact'])->count(),
            'late_fact_total' => $lateFacts,
            'execution_stop_evidence_missing_total' => $missingStopEvidence,
            'reconcile_reports_with_drift' => $reconcileDrifts,
            // —— A3：闭合键集的 9 个计数器（累加值来自 deploy_metric_counters，不再是 null）——
            ...$counterValues,
            // 诚实保留：Agent 侧**不校验包签名**（只有 SHA-256 与指令信封签名），
            // 这个计数器没有生产者，所以写 null 而不是写 0 —— 0 会被读成"一次都没失败过"。
            'package_signature_failed_total' => null,
            // 键集自身也要可观测：它就是"基数有界"的证据（12 个固定键，随设备/任务增长也不会变多）。
            'metric_key_total' => count($counterValues),
            'metric_keys' => array_keys($counterValues),
            'gauge_key_total' => count(DeployMetricCounter::GAUGE_KEYS),
            'gauge_keys' => DeployMetricCounter::GAUGE_KEYS,
            // 上报过指标批次的设备数 —— `agent_restart_observed` 的判据分母，快照里必须能自证。
            'agent_reporting_device_total' => $reportingDevices,
            'metric_status' => [
                ...array_fill_keys(array_keys($counterValues), 'instrumented'),
                'reconcile_drift_total' => 'instrumented; 仅在新对账报告落库时按偏差条数累加',
                'agent_restart_total' => 'instrumented; 设备端每次进程启动 +1，随心跳上报（累计值 > 上报设备数 才说明重启过）',
                'staging_orphan_total' => 'instrumented; 设备端随心跳增量上报',
                'command_received_total' => 'instrumented; 设备端每收到一条指令 +1（含最终被拒的指令）',
                'command_duplicate_total' => 'instrumented; 设备端同 task_id 重复投递命中幂等时 +1',
                'event_conflict_total' => 'instrumented; 平台 Inbox 同 event_id 异载荷（409）时 +1',
                'outbox_pending_age_seconds' => 'instrumented; 设备端 outbox 最老未确认事件年龄（gauge，覆盖不累加）',
                'package_signature_failed_total' => 'not_instrumented; Agent 侧不校验包签名（仅 SHA-256 + 指令信封签名），无生产者',
            ],
            'alerts' => [
                'execution_unknown_active' => $unknown > 0,
                'outbox_pending_age_over_300s' => $effectiveOutboxAge > 300,
                'queue_lag_over_300s' => $queueLag !== null && $queueLag > 300,
                'late_fact_observed' => $lateFacts > 0,
                'reconcile_drift_observed' => $reconcileDrifts > 0,
                'terminal_execution_without_stop_evidence' => $missingStopEvidence > 0,
                'fact_auth_rejected_observed' => ($counterValues['fact_auth_rejected_total'] ?? 0) > 0,
                'agent_restart_observed' => $reportingDevices > 0 && $agentStarts > $reportingDevices,
                'event_conflict_observed' => ($counterValues['event_conflict_total'] ?? 0) > 0,
            ],
        ];
    }

    /**
     * A3.1：有界 label 集合的契约。
     *
     * 这里回答的是"允许出现哪些 label、它们各自的上界是什么"，而不是"给每个维度都存一条时间序列"。
     * 后者才是基数爆炸的来源：did × task_id × skill_id 的组合数会随业务线性（甚至更快）增长。
     * 当前实现里，label 只用于**查询时聚合**（按 model_code/operation/status/error_code），
     * 不落任何按 label 拆分的持久化序列，因此基数天然有界。
     *
     * @return array{allowed:array<int,string>,definitions:array<string,string>,cardinality:array<string,int>,rejected_unbounded:array<int,string>}
     */
    public function labelContract(): array
    {
        $modelCodes = DB::table('device_model')->pluck('model_code')->all();
        $operations = DeployTask::query()->distinct()->pluck('operation')->all();
        $statuses = DeployTask::query()->distinct()->pluck('status')->all();
        $errorCodes = DeployTask::query()->whereNotNull('error_code')->distinct()->pluck('error_code')->all();

        return [
            'allowed' => ['did', 'model_code', 'operation', 'status', 'error_code'],
            'definitions' => [
                'did'          => 'device_binding.did；基数 = 设备数，只做已知集合查找，不进入时间序列',
                'model_code'   => 'device_model.model_code；闭集（已登记机型）',
                'operation'    => 'deploy_task.operation；闭集（操作码枚举）',
                'status'       => 'deploy_task.status；闭集（状态机枚举）',
                'error_code'   => 'deploy_task.error_code；闭集（错误码白名单，不是自由文本）',
            ],
            'cardinality' => [
                'did'        => DeviceBinding::query()->count(),
                'model_code' => count($modelCodes),
                'operation'  => count($operations),
                'status'     => count($statuses),
                'error_code' => count($errorCodes),
            ],
            'rejected_unbounded' => ['event_id', 'task_id', 'skill_id', 'path', 'stack'],
        ];
    }

    /**
     * 按允许的 label 维度聚合当前总量（查询时计算，不落持久化序列）。
     *
     * @return array<string,array<string,int>>
     */
    public function labelTotals(): array
    {
        $byModel = DB::table('device_binding as b')
            ->leftJoin('device_model as m', 'm.id', '=', 'b.model_id')
            ->selectRaw("COALESCE(m.model_code, 'unknown') as label, COUNT(*) as total")
            ->groupBy('label')
            ->pluck('total', 'label')
            ->map(fn ($value) => (int) $value)
            ->all();

        $byOperation = DeployTask::query()
            ->select('operation', DB::raw('COUNT(*) as total'))
            ->groupBy('operation')
            ->pluck('total', 'operation')
            ->map(fn ($value) => (int) $value)
            ->all();

        $byStatus = DeployTask::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $byErrorCode = DeployTask::query()
            ->whereNotNull('error_code')
            ->select('error_code', DB::raw('COUNT(*) as total'))
            ->groupBy('error_code')
            ->pluck('total', 'error_code')
            ->map(fn ($value) => (int) $value)
            ->all();

        return [
            'by_model_code' => $byModel,
            'by_operation'  => $byOperation,
            'by_status'     => $byStatus,
            'by_error_code' => $byErrorCode,
        ];
    }
}