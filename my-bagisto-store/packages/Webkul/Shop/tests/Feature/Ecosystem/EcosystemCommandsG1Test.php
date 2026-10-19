<?php

use Illuminate\Support\Facades\DB;

/**
 * G1-09 · artisan 命令接线（规划 §5.2 / §5.5）。
 *
 * 覆盖：
 *   · `ecosystem:process-outbox --dry-run` 不领取、不写状态；
 *   · `--limit` 是有界批次，本轮未领取的行保持 pending；
 *   · 命令输出可运维摘要且退出码如实反映“本轮已处理”。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-09 command tests require summon_mecha_step10_test.');
    }
});

/**
 * @param  array<string, mixed>  $overrides
 */
function g9CmdInsertOutbox(array $overrides = []): int
{
    return (int) DB::table('ecosystem_outbox')->insertGetId(array_merge([
        'aggregate_type' => 'event',
        'aggregate_id'   => 1,
        'event_type'     => 'event.unknown_command',
        'payload'        => json_encode(['revision_id' => 0, 'row_version' => 0]),
        'dedupe_key'     => null,
        'status'         => 'pending',
        'attempts'       => 0,
        'available_at'   => now('UTC')->format('Y-m-d H:i:s'),
        'lease_owner'    => null,
        'lease_until'    => null,
        'last_error'     => null,
        'created_at'     => now(),
        'updated_at'     => now(),
    ], $overrides));
}

it('process-outbox --dry-run 不写库', function () {
    $id = g9CmdInsertOutbox();

    $this->artisan('ecosystem:process-outbox', ['--dry-run' => true])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();

    $row = DB::table('ecosystem_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('pending')
        ->and((int) $row->attempts)->toBe(0);
});

it('process-outbox --limit 只处理有界批次', function () {
    $ids = [
        g9CmdInsertOutbox(),
        g9CmdInsertOutbox(),
        g9CmdInsertOutbox(),
    ];

    $this->artisan('ecosystem:process-outbox', ['--limit' => 2])
        ->expectsOutputToContain('领取 2')
        ->assertSuccessful();

    expect(DB::table('ecosystem_outbox')->where('id', $ids[0])->value('status'))->toBe('dead')
        ->and(DB::table('ecosystem_outbox')->where('id', $ids[1])->value('status'))->toBe('dead')
        ->and(DB::table('ecosystem_outbox')->where('id', $ids[2])->value('status'))->toBe('pending');
});

it('publish-scheduled --dry-run 在无候选时也输出可运维摘要', function () {
    $this->artisan('ecosystem:publish-scheduled', ['--dry-run' => true, '--limit' => 5])
        ->expectsOutputToContain('DRY-RUN')
        ->assertSuccessful();
});
