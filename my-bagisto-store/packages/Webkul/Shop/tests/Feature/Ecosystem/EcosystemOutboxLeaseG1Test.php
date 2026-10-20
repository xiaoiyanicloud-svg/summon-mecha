<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\OutboxLeaseLostException;
use Webkul\Shop\Services\Ecosystem\OutboxLeaseService;

/**
 * G1-09 · outbox 租约基础（ADR-0001 G1-D07 / 规划 §5.3 / §8.6）。
 *
 * 覆盖：
 *   · 领取：processing / 唯一 token / +TTL / attempts+1；
 *   · 两个 worker：同一行只被一个拿到；
 *   · 过期租约恢复：回 pending、清 lease、**不**重复加 attempts；
 *   · 旧 worker 结算/失败回报：0 行 → LeaseLost，不覆盖新租约；
 *   · 失败重试 backoff、达到上限 failed（可人工补偿）、不可重试直接 dead；
 *   · batch_size 上限 20（MySQL 5.7 无 SKIP LOCKED）。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-09 outbox lease tests require summon_mecha_step10_test.');
    }
});

/**
 * @param  array<string, mixed>  $overrides
 */
function g9LeaseInsertOutbox(array $overrides = []): int
{
    $now = now('UTC')->format('Y-m-d H:i:s');

    return (int) DB::table('ecosystem_outbox')->insertGetId(array_merge([
        'aggregate_type' => 'event',
        'aggregate_id'   => 1,
        'event_type'     => 'event.published',
        'payload'        => json_encode([
            'revision_id' => 0,
            'revision_no' => 0,
            'row_version' => 0,
        ], JSON_UNESCAPED_UNICODE),
        'dedupe_key' => null,
        'status'     => 'pending',
        'attempts'   => 0,
        'available_at' => $now,
        'lease_owner'  => null,
        'lease_until'  => null,
        'last_error'   => null,
        'created_at'   => now(),
        'updated_at'   => now(),
    ], $overrides));
}

function g9LeaseExpire(int $id): void
{
    DB::table('ecosystem_outbox')->where('id', $id)->update([
        'lease_until' => now('UTC')->subSeconds(1)->format('Y-m-d H:i:s'),
        'updated_at'  => now(),
    ]);
}

/**
 * @return array<string, mixed>
 */
function g9LeaseRow(int $id): array
{
    return (array) DB::table('ecosystem_outbox')->where('id', $id)->first();
}

it('领取后写入 processing、唯一租约、TTL 与 attempts+1', function () {
    $id = g9LeaseInsertOutbox();

    $claimed = app(OutboxLeaseService::class)->claim(10);

    expect($claimed)->toHaveCount(1);
    $row = $claimed[0];

    expect((int) $row->id)->toBe($id)
        ->and((string) $row->status)->toBe('processing')
        ->and((int) $row->attempts)->toBe(1)
        ->and((string) $row->lease_owner)->toMatch('/^.+:\d+:[0-9a-f-]{36}$/')
        ->and($row->lease_until)->not->toBeNull();

    $now = now('UTC');
    expect($row->lease_until->between($now->copy()->addSeconds(295), $now->copy()->addSeconds(310)))->toBeTrue();
});

it('两个 worker 不会领取同一行', function () {
    $id = g9LeaseInsertOutbox();

    $first = app(OutboxLeaseService::class);
    $second = app(OutboxLeaseService::class);

    $a = $first->claim(10);
    $b = $second->claim(10);

    expect($a)->toHaveCount(1)
        ->and($b)->toHaveCount(0)
        ->and(g9LeaseRow($id)['status'])->toBe('processing');

    expect($a[0]->lease_owner)->not->toBe($second->newLeaseOwner());
});

it('过期租约恢复回 pending 且不重复增加 attempts', function () {
    $id = g9LeaseInsertOutbox();
    $leases = app(OutboxLeaseService::class);

    $leases->claim(10);
    g9LeaseExpire($id);

    expect($leases->recoverExpired())->toBe(1);

    $row = g9LeaseRow($id);
    expect($row['status'])->toBe('pending')
        ->and($row['lease_owner'])->toBeNull()
        ->and($row['lease_until'])->toBeNull()
        ->and((int) $row['attempts'])->toBe(1)
        ->and($row['last_error'])->toBe('lease_expired');

    expect($leases->claim(10))->toHaveCount(1);
    expect((int) g9LeaseRow($id)['attempts'])->toBe(2);
});

it('旧 worker 结算被拒绝且不覆盖新租约', function () {
    $id = g9LeaseInsertOutbox();
    $leases = app(OutboxLeaseService::class);

    $old = $leases->claim(10)[0];
    $oldToken = (string) $old->lease_owner;

    g9LeaseExpire($id);
    $leases->recoverExpired();

    $new = $leases->claim(10)[0];
    $newToken = (string) $new->lease_owner;

    expect($newToken)->not->toBe($oldToken);

    expect(fn () => $leases->settle($id, $oldToken))
        ->toThrow(OutboxLeaseLostException::class);

    $row = g9LeaseRow($id);
    expect($row['status'])->toBe('processing')
        ->and($row['lease_owner'])->toBe($newToken);

    // 新 worker 仍可正常结算。
    $leases->settle($id, $newToken);
    expect(g9LeaseRow($id)['status'])->toBe('sent');
});

it('旧 worker 失败回报被拒绝且不写 dead / pending', function () {
    $id = g9LeaseInsertOutbox();
    $leases = app(OutboxLeaseService::class);

    $old = $leases->claim(10)[0];
    $oldToken = (string) $old->lease_owner;

    g9LeaseExpire($id);
    $leases->recoverExpired();
    $newToken = (string) $leases->claim(10)[0]->lease_owner;

    expect(fn () => $leases->fail($id, $oldToken, 1, 'boom', false))
        ->toThrow(OutboxLeaseLostException::class);

    $row = g9LeaseRow($id);
    expect($row['status'])->toBe('processing')
        ->and($row['lease_owner'])->toBe($newToken)
        // 恢复扫描写入的原因保持不变；旧 worker 的 'boom' 不得覆盖它。
        ->and($row['last_error'])->toBe('lease_expired');
});

it('可重试失败回 pending 并按 backoff 推迟 available_at', function () {
    $id = g9LeaseInsertOutbox();
    $leases = app(OutboxLeaseService::class);

    $row = $leases->claim(10)[0];

    config(['ecosystem_outbox.retry_backoff_seconds' => [30]]);

    expect($leases->fail($id, (string) $row->lease_owner, 1, 'transient', true))->toBe('pending');

    $stored = g9LeaseRow($id);
    expect($stored['status'])->toBe('pending')
        ->and($stored['lease_owner'])->toBeNull()
        ->and($stored['last_error'])->toBe('transient');

    $availableAt = \Illuminate\Support\Carbon::parse((string) $stored['available_at'], 'UTC');
    $now = now('UTC');
    expect($availableAt->between($now->copy()->addSeconds(29), $now->copy()->addSeconds(35)))->toBeTrue();
});

it('达到最大尝试次数后标记 failed（可人工补偿，不自动复活）', function () {
    $id = g9LeaseInsertOutbox();
    config([
        'ecosystem_outbox.max_attempts'          => 2,
        'ecosystem_outbox.retry_backoff_seconds' => [1],
    ]);

    $leases = app(OutboxLeaseService::class);

    $first = $leases->claim(10)[0];
    expect($leases->fail($id, (string) $first->lease_owner, (int) $first->attempts, 'transient', true))
        ->toBe('pending');

    DB::table('ecosystem_outbox')->where('id', $id)->update([
        'available_at' => now('UTC')->format('Y-m-d H:i:s'),
    ]);

    $second = $leases->claim(10)[0];
    expect((int) $second->attempts)->toBe(2);

    // 可重试但已耗尽自动重试：进入 failed（运营可重排），而不是 dead（永不复活的终态）。
    expect($leases->fail($id, (string) $second->lease_owner, (int) $second->attempts, 'transient', true))
        ->toBe('failed');

    $stored = g9LeaseRow($id);
    expect($stored['status'])->toBe('failed')
        ->and($stored['lease_owner'])->toBeNull()
        ->and((string) $stored['last_error'])->toStartWith('failed:transient');
});

it('不可重试失败即使 attempts 很小也直接 dead', function () {
    $id = g9LeaseInsertOutbox();
    $leases = app(OutboxLeaseService::class);

    $row = $leases->claim(10)[0];

    expect($leases->fail($id, (string) $row->lease_owner, 1, 'business', false))->toBe('dead');
    expect(g9LeaseRow($id)['status'])->toBe('dead');
});

it('batch_size 硬上限 20，即使配置更大', function () {
    config(['ecosystem_outbox.batch_size' => 100]);

    for ($i = 0; $i < 21; $i++) {
        g9LeaseInsertOutbox();
    }

    $claimed = app(OutboxLeaseService::class)->claim(100);

    expect($claimed)->toHaveCount(20);
    expect((int) DB::table('ecosystem_outbox')->where('status', 'pending')->count())->toBe(1);
});
