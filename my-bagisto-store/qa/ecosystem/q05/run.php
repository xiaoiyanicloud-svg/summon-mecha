<?php

/**
 * Q-05 · 数据 preflight + 迁移 / 生命周期演练总控。
 *
 *   DB_DATABASE=summon_mecha_step10_test APP_ENV=testing \
 *     php qa/ecosystem/q05/run.php [--json=D:/main/docs/ecosystem/q05-result.json]
 *
 * 覆盖：
 *   1. 专用库硬校验：root 被拒绝；
 *   2. 制造风险数据 → preflight 必须逐项报出，且不写任何一行业务数据；
 *   3. confirmed_count 对账：dry-run 不写，--apply 才修正；
 *   4. 留存 dry-run：只统计，不删除；
 *   5. expand / backfill / switch / recovery：失败不丢源数据，
 *      切换后新写入阻止破坏性回退，前向恢复包含新行。
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Support\Facades\DB;

$jsonPath = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--json=')) {
        $jsonPath = substr($argument, 7);
    }
}

$result = [
    'q'            => 'Q-05',
    'generated_at' => gmdate('c'),
    'database'     => (string) config('database.connections.mysql.database'),
    'app_env'      => app()->environment(),
    'checks'       => [],
    'ok'           => false,
];

$check = static function (string $name, bool $passed, array $detail = []) use (&$result): void {
    $result['checks'][] = ['name' => $name, 'passed' => $passed] + $detail;
};

$counts = static fn (): array => [
    'events'          => (int) DB::table('events')->count(),
    'registrations'   => (int) DB::table('event_registrations')->count(),
    'attempts'        => (int) DB::table('event_registration_attempts')->count(),
    'scopes'          => (int) DB::table('event_registration_scopes')->count(),
    'outbox'          => (int) DB::table('ecosystem_outbox')->count(),
];

$fixture = null;
$preflightTemp = base_path('storage/q05-preflight.json');

try {
    // ── 0. 专用库硬校验 ───────────────────────────────────────────────
    $guarded = qa_spawn(__DIR__.'/../q02/guard_probe.php', [], [
        'APP_ENV'     => 'local',
        'DB_DATABASE' => 'root',
    ]);
    $check('guard_rejects_root', $guarded['exit'] === 2, ['exit' => $guarded['exit']]);

    // ── 1. 制造风险数据 + 只读 preflight ──────────────────────────────
    $seeded = qa_spawn(__DIR__.'/seed_risk.php');
    $fixture = qa_last_json($seeded['stdout']);

    if ($fixture === null || ($fixture['event_id'] ?? 0) <= 0) {
        throw new RuntimeException('q05 seed failed: '.$seeded['stderr'].$seeded['stdout']);
    }

    $beforePreflight = $counts();

    $preflight = qa_spawn(base_path('artisan'), [
        'ecosystem:data-preflight',
        '--strict',
        '--json='.$preflightTemp,
        '--sample=3',
    ]);
    $preflightJson = is_file($preflightTemp) ? json_decode((string) file_get_contents($preflightTemp), true) : null;
    $codes = $preflightJson === null ? [] : array_column($preflightJson['findings'], 'code');

    $expected = [
        'scope_counter_mismatch',
        'scope_counter_exceeds_capacity',
        'duplicate_active_attempts',
        'attempt_status_unknown',
        'event_timezone_invalid',
        'revision_time_invalid',
        'outbox_expired_lease',
    ];
    $missing = array_values(array_diff($expected, $codes));

    $check('preflight_exit_nonzero', $preflight['exit'] !== 0, ['exit' => $preflight['exit']]);
    $check('preflight_detects_risk_codes', $missing === [], [
        'expected' => $expected,
        'seen'     => $codes,
        'missing'  => $missing,
        'findings' => $preflightJson['findings'] ?? null,
    ]);

    $afterPreflight = $counts();
    $check('preflight_is_read_only', $beforePreflight === $afterPreflight, [
        'before' => $beforePreflight,
        'after'  => $afterPreflight,
    ]);

    // ── 2. 计数对账：dry-run 不写，--apply 修正 ───────────────────────
    $scopeId = (int) $fixture['scope_id'];
    $storedBefore = (int) DB::table('event_registration_scopes')->where('id', $scopeId)->value('confirmed_count');

    $dryRun = qa_spawn(base_path('artisan'), [
        'ecosystem:reconcile-registration-scopes',
        '--event='.(int) $fixture['event_id'],
    ]);
    $dryOutput = trim($dryRun['stdout']."\n".$dryRun['stderr']);
    $storedAfterDry = (int) DB::table('event_registration_scopes')->where('id', $scopeId)->value('confirmed_count');

    $check('reconcile_dry_run_reports_without_write', $storedBefore === 5
        && $storedAfterDry === 5
        && str_contains($dryOutput, '需修正'), [
        'stored_before' => $storedBefore,
        'stored_after'  => $storedAfterDry,
        'output'        => $dryOutput,
    ]);

    $apply = qa_spawn(base_path('artisan'), [
        'ecosystem:reconcile-registration-scopes',
        '--event='.(int) $fixture['event_id'],
        '--apply',
    ]);
    $storedAfterApply = (int) DB::table('event_registration_scopes')->where('id', $scopeId)->value('confirmed_count');

    $check('reconcile_apply_fixes_counter', $storedAfterApply === 2, [
        'stored_after_apply' => $storedAfterApply,
        'output'             => trim($apply['stdout']."\n".$apply['stderr']),
    ]);

    // ── 3. 留存 dry-run：只统计不删除 ────────────────────────────────
    $beforeRetention = $counts();
    $retention = qa_spawn(base_path('artisan'), ['ecosystem:registration-retention', '--check-key']);
    $retentionOutput = trim($retention['stdout']."\n".$retention['stderr']);
    $afterRetention = $counts();

    $check('retention_dry_run_no_delete', $beforeRetention === $afterRetention
        && str_contains($retentionOutput, '未执行任何删除'), [
        'before' => $beforeRetention,
        'after'  => $afterRetention,
        'output' => $retentionOutput,
    ]);

    // ── 4. expand / backfill / switch / recovery ──────────────────────
    $drill = qa_spawn(__DIR__.'/migration_drill.php', [(string) $fixture['event_id']]);
    $drillJson = qa_last_json($drill['stdout']);

    $check('migration_drill', ($drillJson['ok'] ?? false) === true, [
        'exit'   => $drill['exit'],
        'detail' => $drillJson,
        'stderr' => trim($drill['stderr']),
    ]);
} catch (Throwable $exception) {
    $check('harness_exception', false, [
        'class'   => $exception::class,
        'message' => $exception->getMessage(),
    ]);
} finally {
    if (is_array($fixture) && ($fixture['event_id'] ?? 0) > 0) {
        $cleanup = qa_spawn(__DIR__.'/../q02/cleanup.php', [
            (string) $fixture['event_id'],
            (string) ($fixture['marker'] ?? ''),
        ]);
        $cleanupJson = qa_last_json($cleanup['stdout']);
        $check('fixture_cleanup', ($cleanupJson['deleted'] ?? false) === true, [
            'exit'   => $cleanup['exit'],
            'detail' => $cleanupJson,
            'stderr' => trim($cleanup['stderr']),
        ]);
    }

    @unlink($preflightTemp);
}

$result['ok'] = $result['checks'] !== []
    && count(array_filter($result['checks'], fn ($item) => $item['passed'] === true)) === count($result['checks']);

if ($jsonPath !== null) {
    $directory = dirname($jsonPath);

    if (! is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }

    file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

qa_json_pretty($result);
exit($result['ok'] ? 0 : 1);
