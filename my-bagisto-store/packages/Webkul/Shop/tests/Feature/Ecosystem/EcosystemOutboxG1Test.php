<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EcosystemOutbox;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\EventRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\OutboxWriter;

/**
 * G1-02 · 乐观并发写入口 + outbox 事务/幂等/PII 守卫（ADR-0001 G1-D07 / G1-D08）。
 *
 * 这组测试保护的是**行为**，不是“类存在”：
 *   · outbox 的默认值来自数据库，而不是写入方顺手填的；
 *   · dedupe_key 重放返回原行，复用同 key 承载另一件事必须炸；
 *   · outbox 不自开事务 —— 业务抛错时两边一起回滚；
 *   · row_version 只在真实字段变更时 +1，no-op 不推高版本；
 *   · 版本不符抛显式异常，而不是静默覆盖。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，不做 migrate:fresh / truncate / seed。
 * 运行必须指向独立库 summon_mecha_step10_test，禁止落到 root 主库。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-02 outbox tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g1OutboxWriter(): OutboxWriter
{
    return app(OutboxWriter::class);
}

function g1EventWriteService(): EventWriteService
{
    return app(EventWriteService::class);
}

/**
 * 独立 PDO 连接：用于在测试事务之外模拟“另一个已提交的 holder”。
 *
 * 直接用 config 里的 mysql 连接参数，保证与 Laravel 指向同一个库；
 * 连接建立后再硬校验一次库名，避免误连 root 主库。
 */
function g1ExternalPdo(): PDO
{
    $config = config('database.connections.mysql');

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'] ?: 3306,
        $config['database'],
        $config['charset'] ?: 'utf8mb4',
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $database = $pdo->query('SELECT DATABASE()')->fetchColumn();

    if ($database !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-02 external PDO must target summon_mecha_step10_test.');
    }

    return $pdo;
}

/**
 * @param  array<string, mixed>  $payload
 */
function g1ExternalInsertOutbox(PDO $pdo, string $dedupeKey, array $payload): int
{
    $now = gmdate('Y-m-d H:i:s');

    $statement = $pdo->prepare(
        'INSERT INTO ecosystem_outbox
            (aggregate_type, aggregate_id, event_type, payload, dedupe_key, status, attempts, available_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $statement->execute([
        'event',
        42,
        'event.published',
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $dedupeKey,
        'pending',
        0,
        $now,
        $now,
        $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function g1ExternalDeleteOutbox(PDO $pdo, string $dedupeKey): void
{
    $pdo->prepare('DELETE FROM ecosystem_outbox WHERE dedupe_key = ?')->execute([$dedupeKey]);
}

it('G1-02 · outbox 默认 status=pending / attempts=0 / UTC available_at，payload 以数组回读', function () {
    $payload = [
        'event_id'    => 42,
        'revision_id' => 7,
        'reason'      => 'published',
    ];

    $row = g1OutboxWriter()->write('event', 42, 'event.published', $payload);

    expect($row->exists)->toBeTrue()
        ->and($row->aggregate_type)->toBe('event')
        ->and((int) $row->aggregate_id)->toBe(42)
        ->and($row->event_type)->toBe('event.published')
        ->and($row->status)->toBe('pending')
        ->and((int) $row->attempts)->toBe(0)
        ->and($row->dedupe_key)->toBeNull()
        ->and($row->lease_owner)->toBeNull()
        ->and($row->lease_until)->toBeNull()
        ->and($row->last_error)->toBeNull();

    // available_at 默认 now()->utc()：允许执行耗时，但必须贴近当前时刻。
    expect($row->available_at)->not->toBeNull()
        ->and(abs($row->available_at->diffInSeconds(now()->utc())))->toBeLessThanOrEqual(5);

    // 从库里重新读一次，证明 JSON 列 + array cast 往返成立。
    $fresh = EcosystemOutbox::query()->findOrFail($row->getKey());

    // MySQL JSON 会规范对象键序，断言必须键序无关（toEqual 做深比较）。
    expect($fresh->payload)->toEqual($payload);
});

it('G1-02 · available_at 以 UTC 落库：上海 09:00 存为 01:00，读回时区为 UTC', function () {
    $row = g1OutboxWriter()->write(
        'event',
        42,
        'event.published',
        ['revision_id' => 7],
        null,
        Carbon::parse('2026-10-19 09:00:00', 'Asia/Shanghai'),
    );

    // 应用时区是 Asia/Shanghai，但 G1 时间字段按 UTC 存储：
    // 上海 09:00 必须落成 01:00，且读回时不再被默认 datetime cast 当成上海时间。
    expect($row->available_at->format('Y-m-d H:i:s'))->toBe('2026-10-19 01:00:00')
        ->and($row->available_at->timezoneName)->toBe('UTC');

    // 直接读 DATETIME 列，确认落库值本身（而不是模型 cast）就是 UTC。
    expect(DB::table('ecosystem_outbox')->where('id', $row->getKey())->value('available_at'))
        ->toBe('2026-10-19 01:00:00');
});

it('G1-02 · 同一 dedupe_key + 同一事实重放返回既有行，不新增记录', function () {
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::count();

    $first = $writer->write(
        'event',
        42,
        'event.published',
        ['revision_id' => 7, 'reason' => 'published'],
        'event:42:published:rev7',
    );

    // 键顺序不同仍是同一事实。
    $replayed = $writer->write(
        'event',
        42,
        'event.published',
        ['reason' => 'published', 'revision_id' => 7],
        'event:42:published:rev7',
    );

    expect($replayed->getKey())->toBe($first->getKey())
        ->and(EcosystemOutbox::count())->toBe($before + 1);
});

it('G1-02 · 复用 dedupe_key 承载另一条事实必须抛异常，且原记录不变', function () {
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::count();

    $first = $writer->write(
        'event',
        42,
        'event.published',
        ['revision_id' => 7],
        'event:42:published:rev7',
    );

    expect(fn () => $writer->write(
        'event',
        42,
        'event.published',
        ['revision_id' => 8],
        'event:42:published:rev7',
    ))->toThrow(RuntimeException::class);

    expect(EcosystemOutbox::count())->toBe($before + 1)
        ->and(EcosystemOutbox::query()->findOrFail($first->getKey())->payload)
        ->toBe(['revision_id' => 7]);
});

it('G1-02 · REPEATABLE READ 下同 key 已被并发事务提交时，仍应幂等重放而不是抛 1062', function () {
    $dedupeKey = 'g1:rr:'.bin2hex(random_bytes(8));
    $payload = ['revision_id' => 11, 'reason' => 'published'];

    // 1) 测试事务先做一次普通 SELECT：在 REPEATABLE READ 下建立一致性快照。
    //    此后的普通 SELECT 都会读这份快照 —— 这正是缺陷的触发条件。
    expect(EcosystemOutbox::query()->where('dedupe_key', $dedupeKey)->first())->toBeNull();

    $pdo = g1ExternalPdo();
    $externalId = g1ExternalInsertOutbox($pdo, $dedupeKey, $payload);

    try {
        // 2) 写入器 pre-check 读旧快照（看不到外部行）→ INSERT 1062。
        //    修复后用 lockForUpdate（current read）回查，必须返回外部行而不是抛 1062。
        $row = g1OutboxWriter()->write(
            'event',
            42,
            'event.published',
            $payload,
            $dedupeKey,
        );

        expect($row->getKey())->toBe($externalId)
            ->and($row->dedupe_key)->toBe($dedupeKey)
            ->and($row->payload)->toEqual($payload);
    } finally {
        // 3) 先释放测试事务（以及它在回查时可能获得的锁），
        //    否则独立连接的 DELETE 会被测试事务锁住。
        //    DatabaseTransactions 的 tearDown 会按 transactionLevel 判断，重复 rollback 安全。
        DB::rollBack();

        g1ExternalDeleteOutbox($pdo, $dedupeKey);
    }
});

it('G1-02 · dedupe_key 为 NULL 时可以有多条（唯一索引不约束 NULL）', function () {
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::whereNull('dedupe_key')->count();

    $writer->write('event', 1, 'event.published', ['revision_id' => 1]);
    $writer->write('event', 2, 'event.published', ['revision_id' => 1]);

    expect(EcosystemOutbox::whereNull('dedupe_key')->count())->toBe($before + 2);
});

it('G1-02 · outbox 不自开事务：业务抛错时记录一并回滚', function () {
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::count();

    expect(fn () => DB::transaction(function () use ($writer) {
        $writer->write('event', 99, 'event.published', ['revision_id' => 3]);

        throw new RuntimeException('业务写失败');
    }))->toThrow(RuntimeException::class);

    expect(EcosystemOutbox::count())->toBe($before);
});

it('G1-02 · 未登记的 aggregate_type / event_type 被拒绝且不留记录', function () {
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::count();

    expect(fn () => $writer->write('skill', 1, 'event.published', []))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $writer->write('event', 1, 'event.deleted', []))
        ->toThrow(InvalidArgumentException::class);

    // 报名事件属于 G2-12，G1 注册表不得提前放行。
    expect(fn () => $writer->write('event', 1, 'registration.created', []))
        ->toThrow(InvalidArgumentException::class);

    expect(EcosystemOutbox::count())->toBe($before);
});

it('G1-02 · payload 含 PII 键（含嵌套）被拒绝且不留记录', function () {
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::count();

    expect(fn () => $writer->write(
        'event',
        1,
        'event.published',
        ['phone' => '13800000000'],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => $writer->write(
        'event',
        1,
        'event.published',
        ['data' => ['contact_email' => 'someone@example.com']],
    ))->toThrow(InvalidArgumentException::class);

    expect(EcosystemOutbox::count())->toBe($before);
});

it('G1-02 · 真实字段变更 row_version = expected+1；no-op 不递增；旧版本抛冲突', function () {
    $event = Event::create(['name' => '并发写守卫']);
    $service = g1EventWriteService();

    $updated = $service->update($event, 0, [
        'kind'     => 'salon',
        'timezone' => 'Asia/Shanghai',
    ]);

    expect((int) $updated->row_version)->toBe(1)
        ->and($updated->kind)->toBe('salon')
        ->and($updated->timezone)->toBe('Asia/Shanghai');

    // 重复提交同一份表单：不推高版本号，也不刷新 updated_at。
    $noop = $service->update($updated, 1, [
        'kind'     => 'salon',
        'timezone' => 'Asia/Shanghai',
    ]);

    expect((int) $noop->row_version)->toBe(1)
        ->and($noop->updated_at->getTimestamp())->toBe($updated->updated_at->getTimestamp());

    // 用旧版本号保存必须显式失败，而不是静默覆盖。
    $caught = null;

    try {
        $service->update($noop, 0, ['kind' => 'expo']);
    } catch (EventRevisionConflictException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(EventRevisionConflictException::class);
    expect($caught->eventId)->toBe((int) $event->getKey())
        ->and($caught->expectedRowVersion)->toBe(0)
        ->and($caught->actualRowVersion)->toBe(1);

    $fresh = Event::query()->findOrFail($event->getKey());

    expect($fresh->kind)->toBe('salon')
        ->and((int) $fresh->row_version)->toBe(1);
});

it('G1-02 · outbox 写入抛错时，同一事务里的业务写一并回滚', function () {
    $event = Event::create(['name' => '事务一致性守卫']);
    $service = g1EventWriteService();
    $writer = g1OutboxWriter();
    $before = EcosystemOutbox::count();

    expect(fn () => DB::transaction(function () use ($service, $writer, $event) {
        $service->update($event, 0, ['kind' => 'workshop']);

        // 未登记类型 → 写入入口抛错 → 整个事务（含 row_version 更新）回滚。
        $writer->write('event', (int) $event->getKey(), 'event.not_registered', []);
    }))->toThrow(InvalidArgumentException::class);

    $fresh = Event::query()->findOrFail($event->getKey());

    expect($fresh->kind)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(0)
        ->and(EcosystemOutbox::count())->toBe($before);
});
