<?php

namespace Webkul\Shop\Services\SkillCompile;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillBuildCommand;

/**
 * 构建指标只读快照（WBS B8）。
 *
 * 指标从 `skill_build_commands` 派生，而不是单独的计数表：
 *   - 同一 idempotency_key 的重试复用同一行 → 天然不重复计数；
 *   - label 只有有界的 status 与显式 build_key 过滤，不使用 event_id/路径/堆栈。
 */
class BuildMetricsService
{
    public function snapshot(?string $buildKey = null): array
    {
        $scoped = fn () => DB::table('skill_build_commands')
            ->when($buildKey !== null && $buildKey !== '', fn ($query) => $query->where('build_key', $buildKey));

        $counts = $scoped()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $duration = $scoped()
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->selectRaw('COUNT(*) as samples')
            ->selectRaw('SUM(TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) / 1000) as total_ms')
            ->selectRaw('MAX(TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) / 1000) as max_ms')
            ->first();

        $samples = (int) ($duration->samples ?? 0);
        $totalMs = (float) ($duration->total_ms ?? 0);
        $maxMs = (float) ($duration->max_ms ?? 0);

        $resultStatuses = $scoped()
            ->whereNotNull('result_json')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.status')) as result_status")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('result_status')
            ->pluck('total', 'result_status')
            ->map(fn ($value) => (int) $value)
            ->all();

        return [
            'generated_at'           => now()->toIso8601String(),
            'build_key'              => $buildKey !== '' ? $buildKey : null,
            'build_accepted'         => array_sum($counts),
            'build_started'          => (int) $scoped()->whereNotNull('started_at')->count(),
            'build_succeeded'        => (int) ($counts[SkillBuildCommand::STATUS_COMPLETED] ?? 0),
            'build_failed'           => (int) ($counts[SkillBuildCommand::STATUS_FAILED] ?? 0),
            'build_canceled'         => (int) ($counts[SkillBuildCommand::STATUS_CANCELED] ?? 0),
            'build_queued'           => (int) ($counts[SkillBuildCommand::STATUS_QUEUED] ?? 0),
            'build_running'          => (int) ($counts[SkillBuildCommand::STATUS_RUNNING] ?? 0),
            'build_duration_ms'      => [
                'samples' => $samples,
                'total'   => round($totalMs, 3),
                'avg'     => $samples > 0 ? round($totalMs / $samples, 3) : null,
                'max'     => $samples > 0 ? round($maxMs, 3) : null,
            ],
            'status_breakdown'       => $counts,
            'result_status_breakdown' => $resultStatuses,
            'metric_status'          => [
                'build_accepted'  => 'instrumented: skill_build_commands',
                'build_started'   => 'instrumented: started_at',
                'build_succeeded' => 'instrumented: status=completed',
                'build_failed'    => 'instrumented: status=failed',
                'build_canceled'  => 'instrumented: status=canceled',
                'build_duration_ms' => 'instrumented: started_at/finished_at',
            ],
        ];
    }
}
