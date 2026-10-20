<?php

/**
 * Q-05 · expand / backfill / switch / recovery 演练（仅独立测试库）。
 *
 *   php migration_drill.php <event_id>
 *
 * 在测试库上真实执行一遍未来的增量迁移流程（不碰 root、不改产品迁移）：
 *
 *   expand   → 新增目标表 + 源表可空列
 *   backfill → 分批、幂等回填（跑两次不重复）
 *   failure  → 回填后模拟“上线前失败”，撤掉探针，源数据哈希必须不变
 *   switch   → 建立读视图并核对行数 / 内容一致
 *   new write→ 切换后写入新报名
 *   down     → 检测到新写入后，破坏性回退必须被拒绝
 *   recovery → 前向恢复：再次回填，新行进入目标表
 *   cleanup  → 删除探针 / 视图 / 额外报名
 */

require __DIR__.'/../bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$eventId = (int) ($argv[1] ?? 0);

if ($eventId <= 0) {
    fwrite(STDERR, "usage: migration_drill.php <event_id>\n");
    exit(1);
}

$table = 'qa_q05_backfill_target';
$view = 'qa_q05_switch_view';
$column = 'qa_backfill_state';

$checks = [];
$check = static function (string $name, bool $passed, array $detail = []) use (&$checks): void {
    $checks[] = ['name' => $name, 'passed' => $passed] + $detail;
};

$sourceRows = static function () use ($eventId): array {
    return DB::table('event_registrations')
        ->where('event_id', $eventId)
        ->orderBy('id')
        ->get(['id', 'public_no', 'subject_key'])
        ->map(fn ($row) => [(int) $row->id, (string) $row->public_no, (string) $row->subject_key])
        ->all();
};

$hashRows = static fn (array $rows): string => hash('sha256', json_encode($rows));

$dropProbes = static function () use ($table, $view, $column): void {
    DB::statement('DROP VIEW IF EXISTS '.$view);

    if (Schema::hasTable($table)) {
        Schema::drop($table);
    }

    if (Schema::hasColumn('event_registrations', $column)) {
        Schema::table('event_registrations', function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropColumn($column);
        });
    }
};

$expand = static function () use ($table, $column): void {
    if (! Schema::hasTable($table)) {
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->bigIncrements('id');
            $blueprint->unsignedBigInteger('registration_id')->unique('uk_qa_q05_registration');
            $blueprint->string('public_no', 32)->nullable();
            $blueprint->string('subject_key', 100)->nullable();
            $blueprint->dateTime('synced_at')->nullable();
        });
    }

    if (! Schema::hasColumn('event_registrations', $column)) {
        Schema::table('event_registrations', function (Blueprint $blueprint) use ($column): void {
            $blueprint->tinyInteger($column)->default(0);
        });
    }
};

$backfill = static function () use ($eventId, $table): int {
    $count = 0;

    DB::table('event_registrations')
        ->where('event_id', $eventId)
        ->orderBy('id')
        ->chunkById(200, function ($rows) use ($table, &$count): void {
            $payload = [];

            foreach ($rows as $row) {
                $payload[] = [
                    'registration_id' => (int) $row->id,
                    'public_no'       => (string) $row->public_no,
                    'subject_key'     => (string) $row->subject_key,
                    'synced_at'       => now('UTC')->format('Y-m-d H:i:s'),
                ];
            }

            if ($payload !== []) {
                DB::table($table)->insertOrIgnore($payload);
                $count += count($payload);
            }
        });

    return $count;
};

$targetRows = static function () use ($table): array {
    return DB::table($table)->orderBy('registration_id')->get(['registration_id', 'public_no', 'subject_key'])
        ->map(fn ($row) => [(int) $row->registration_id, (string) $row->public_no, (string) $row->subject_key])
        ->all();
};

try {
    $before = $sourceRows();
    $beforeHash = $hashRows($before);

    // ── 回填后失败：撤掉探针，源数据不变 ─────────────────────────────
    $expand();
    $backfill();
    $backfill();
    $targetAfterTwoRuns = $targetRows();
    $check('backfill_idempotent', count($targetAfterTwoRuns) === count($before), [
        'source' => count($before),
        'target' => count($targetAfterTwoRuns),
    ]);

    $dropProbes();
    $afterFailureHash = $hashRows($sourceRows());
    $check('failure_before_switch_preserves_source', $afterFailureHash === $beforeHash, [
        'before_hash' => $beforeHash,
        'after_hash'  => $afterFailureHash,
    ]);

    // ── 正式流程：expand → backfill → switch ─────────────────────────
    $expand();
    $backfill();
    $backfill();

    DB::statement('CREATE OR REPLACE VIEW '.$view.' AS SELECT * FROM '.$table);

    $viewCount = (int) DB::table($view)->count();
    $viewRows = DB::table($view)->orderBy('registration_id')->get(['registration_id', 'public_no', 'subject_key'])
        ->map(fn ($row) => [(int) $row->registration_id, (string) $row->public_no, (string) $row->subject_key])
        ->all();

    $check('switch_view_matches_source', $viewCount === count($before) && $hashRows($viewRows) === $beforeHash, [
        'source' => count($before),
        'view'   => $viewCount,
    ]);

    $checkpoint = $before === [] ? 0 : max(array_column($before, 0));

    // ── 切换后新写入 ────────────────────────────────────────────────
    $scope = DB::table('event_registration_scopes')->where('event_id', $eventId)->orderBy('id')->first();
    $newId = (int) DB::table('event_registrations')->insertGetId([
        'event_id'           => $eventId,
        'customer_id'        => null,
        'name'               => 'Q05 新写入',
        'phone'              => '13800000000',
        'city'               => '上海',
        'note'               => null,
        'scope_id'           => $scope->id,
        'team_id'            => null,
        'subject_key'        => 'q05-new-'.bin2hex(random_bytes(4)),
        'public_no'          => 'QAEV'.strtoupper(bin2hex(random_bytes(6))),
        'current_attempt_id' => null,
        'identity_kind'      => 'customer',
        'row_version'        => 0,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $newRows = DB::table('event_registrations')->where('event_id', $eventId)->where('id', '>', $checkpoint)->count();

    // 破坏性 down：有新行时必须拒绝。
    $downBlocked = ($newRows > 0);
    if (! $downBlocked) {
        $dropProbes();
    }

    $check('destructive_down_blocked_after_new_write', $downBlocked && Schema::hasTable($table), [
        'new_rows'   => $newRows,
        'checkpoint' => $checkpoint,
    ]);

    // ── 前向恢复：再次回填，新行必须进入目标表 ───────────────────────
    $backfill();
    $recoveredRows = $targetRows();
    $expectedIds = DB::table('event_registrations')->where('event_id', $eventId)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    $targetIds = array_column($recoveredRows, 0);

    $check('forward_recovery_includes_new_write', $targetIds === $expectedIds && in_array($newId, $targetIds, true), [
        'source_ids' => $expectedIds,
        'target_ids' => $targetIds,
    ]);

    // ── 清理 ────────────────────────────────────────────────────────
    $dropProbes();
    DB::table('event_registrations')->where('id', $newId)->delete();

    $check('cleanup_removed_probes', ! Schema::hasTable($table)
        && ! Schema::hasColumn('event_registrations', $column)
        && (int) DB::table('event_registrations')->where('event_id', $eventId)->count() === count($before), [
        'remaining' => (int) DB::table('event_registrations')->where('event_id', $eventId)->count(),
    ]);
} catch (Throwable $exception) {
    $check('drill_exception', false, [
        'class'   => $exception::class,
        'message' => $exception->getMessage(),
    ]);

    try {
        $dropProbes();
    } catch (Throwable) {
        // 清理失败由 run.php 的最终清理兜底。
    }
}

qa_json([
    'event_id' => $eventId,
    'checks'   => $checks,
    'ok'       => $checks !== [] && count(array_filter($checks, fn ($item) => $item['passed'] === true)) === count($checks),
]);
exit(($checks !== [] && count(array_filter($checks, fn ($item) => $item['passed'] === true)) === count($checks)) ? 0 : 1);
