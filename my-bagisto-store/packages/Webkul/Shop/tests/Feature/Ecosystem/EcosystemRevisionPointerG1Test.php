<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-04 · revision 指针守卫（ADR-0001 §4.2 / G1-D08）。
 *
 * 保护的是**工作版本的归属与可写边界**：
 *   · `published_revision_id` 在 G1-04 一律拒绝（发布属于 G1-08）；
 *   · `working_revision_id` 必须是同活动的 draft revision；
 *   · 跨活动、非 draft、不存在的 revision 都被拒绝，且不递增 row_version；
 *   · 清空指针是合法 no-op 之外的**真实变更**，要递增 row_version；
 *   · FK 真实阻止删除被引用的活动/revision（不是只“声明了 RESTRICT”）；
 *   · `event_registrations` 与四个公开/报名开关保持不变。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-04 revision pointer tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g4PtrForeignKey(string $table, string $constraint): ?object
{
    return DB::selectOne(
        'SELECT kcu.COLUMN_NAME AS column_name,
                kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
                kcu.REFERENCED_COLUMN_NAME AS referenced_column_name,
                rc.DELETE_RULE AS delete_rule,
                rc.UPDATE_RULE AS update_rule
           FROM information_schema.REFERENTIAL_CONSTRAINTS rc
           JOIN information_schema.KEY_COLUMN_USAGE kcu
             ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
            AND kcu.TABLE_NAME = rc.TABLE_NAME
          WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
            AND rc.TABLE_NAME = ?
            AND rc.CONSTRAINT_NAME = ?',
        [$table, $constraint]
    );
}

function g4PtrEvent(): Event
{
    return Event::create(['name' => 'G4 指针活动 '.bin2hex(random_bytes(3))]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g4PtrRevision(int $eventId, array $overrides = []): int
{
    return (int) DB::table('event_revisions')->insertGetId(array_merge([
        'event_id'                 => $eventId,
        'revision_no'              => 1,
        'name'                     => 'G4 指针修订',
        'timezone'                 => 'Asia/Shanghai',
        'source_type'              => 'platform',
        'platform_role'            => 'organizer',
        'organizer_name_snapshot'  => 'G4 主办方',
        'source_name'              => 'G4 来源',
        'content_fingerprint'      => str_repeat('c', 64),
        'change_summary'           => '指针测试版本',
        'created_by'               => 1,
        'created_at'               => now(),
        'updated_at'               => now(),
    ], $overrides));
}

function g4PtrService(): EventWriteService
{
    return app(EventWriteService::class);
}

function g4PtrErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (RevisionImmutableException $exception) {
        return $exception->errorCode;
    }

    return null;
}

it('G1-04 · events 两个 revision 指针外键指向 event_revisions 且为 RESTRICT / RESTRICT', function () {
    foreach ([
        'published_revision_id' => 'fk_events_published_revision_id',
        'working_revision_id'   => 'fk_events_working_revision_id',
    ] as $column => $constraint) {
        $foreignKey = g4PtrForeignKey('events', $constraint);

        expect($foreignKey)->not->toBeNull()
            ->and($foreignKey->column_name)->toBe($column)
            ->and($foreignKey->referenced_table_name)->toBe('event_revisions')
            ->and($foreignKey->referenced_column_name)->toBe('id')
            ->and(strtoupper((string) $foreignKey->delete_rule))->toBe('RESTRICT')
            ->and(strtoupper((string) $foreignKey->update_rule))->toBe('RESTRICT');
    }
});

it('G1-04 · 同活动的 draft revision 可挂为工作版本并递增 row_version', function () {
    $event = g4PtrEvent();
    $revisionId = g4PtrRevision((int) $event->id);

    $updated = g4PtrService()->update($event, 0, ['working_revision_id' => $revisionId]);

    expect((int) $updated->working_revision_id)->toBe($revisionId)
        ->and((int) $updated->row_version)->toBe(1)
        ->and($updated->published_revision_id)->toBeNull();

    $fresh = Event::query()->findOrFail($event->id);

    expect((int) $fresh->working_revision_id)->toBe($revisionId)
        ->and($fresh->workingRevision)->not->toBeNull()
        ->and((int) $fresh->workingRevision->id)->toBe($revisionId);
});

it('G1-04 · 跨活动的 draft revision 被拒绝且不写库', function () {
    $eventA = g4PtrEvent();
    $eventB = g4PtrEvent();
    $revisionOfB = g4PtrRevision((int) $eventB->id);

    expect(g4PtrErrorCode(fn () => g4PtrService()->update(
        $eventA,
        0,
        ['working_revision_id' => $revisionOfB],
    )))->toBe(ApiErrorCode::EVENT_TRANSITION_INVALID);

    $fresh = Event::query()->findOrFail($eventA->id);

    expect($fresh->working_revision_id)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(0);
});

it('G1-04 · 非 draft revision 不能作为工作版本', function () {
    foreach (['in_review', 'approved', 'rejected'] as $state) {
        $event = g4PtrEvent();
        $revisionId = g4PtrRevision((int) $event->id, ['review_state' => $state]);

        expect(g4PtrErrorCode(fn () => g4PtrService()->update(
            $event,
            0,
            ['working_revision_id' => $revisionId],
        )))->toBe(ApiErrorCode::EVENT_TRANSITION_INVALID, '状态 '.$state.' 应被拒绝');

        $fresh = Event::query()->findOrFail($event->id);

        expect($fresh->working_revision_id)->toBeNull()
            ->and((int) $fresh->row_version)->toBe(0);
    }
});

it('G1-04 · 不存在的 working_revision_id 被拒绝', function () {
    $event = g4PtrEvent();

    expect(g4PtrErrorCode(fn () => g4PtrService()->update(
        $event,
        0,
        ['working_revision_id' => 999999999],
    )))->toBe(ApiErrorCode::EVENT_TRANSITION_INVALID);
});

it('G1-04 · published_revision_id 本批任何非 NULL 写入都被拒绝（即使是同活动 draft）', function () {
    $event = g4PtrEvent();
    $revisionId = g4PtrRevision((int) $event->id);

    expect(g4PtrErrorCode(fn () => g4PtrService()->update(
        $event,
        0,
        ['published_revision_id' => $revisionId],
    )))->toBe(ApiErrorCode::EVENT_TRANSITION_INVALID);

    $fresh = Event::query()->findOrFail($event->id);

    expect($fresh->published_revision_id)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(0);
});

it('G1-04 · 清空 working_revision_id 成功并递增 row_version', function () {
    $event = g4PtrEvent();
    $revisionId = g4PtrRevision((int) $event->id);

    $attached = g4PtrService()->update($event, 0, ['working_revision_id' => $revisionId]);
    expect((int) $attached->row_version)->toBe(1);

    $cleared = g4PtrService()->update($attached, 1, ['working_revision_id' => null]);

    expect($cleared->working_revision_id)->toBeNull()
        ->and((int) $cleared->row_version)->toBe(2);

    $fresh = Event::query()->findOrFail($event->id);

    expect($fresh->working_revision_id)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(2);
});

it('G1-04 · FK 阻止删除仍有 revision 的活动', function () {
    $event = g4PtrEvent();
    $revisionId = g4PtrRevision((int) $event->id);

    expect(fn () => $event->delete())->toThrow(QueryException::class);

    expect(Event::query()->find($event->id))->not->toBeNull()
        ->and(DB::table('event_revisions')->where('id', $revisionId)->exists())->toBeTrue();
});

it('G1-04 · FK 阻止删除被 working 指针引用的 revision', function () {
    $event = g4PtrEvent();
    $revisionId = g4PtrRevision((int) $event->id);

    g4PtrService()->update($event, 0, ['working_revision_id' => $revisionId]);

    // 绕过模型 deleting 守卫，直接打库，验证的是 FK 而不是模型兜底。
    expect(fn () => DB::table('event_revisions')->where('id', $revisionId)->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('event_revisions')->where('id', $revisionId)->exists())->toBeTrue()
        ->and((int) Event::query()->findOrFail($event->id)->working_revision_id)->toBe($revisionId);
});

it('G1-04 · event_registrations 结构与 G0 收口时完全一致', function () {
    $expected = [
        'id',
        'event_id',
        'customer_id',
        'name',
        'phone',
        'city',
        'note',
        'created_at',
        'updated_at',
    ];

    $actual = Schema::getColumnListing('event_registrations');
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

it('G1-04 · 迁移后四个公开/报名开关仍为 false', function () {
    expect(config('ecosystem.events.public_enabled'))->toBeFalse()
        ->and(config('ecosystem.events.registration_enabled'))->toBeFalse()
        ->and(config('ecosystem.events.registration_write_verified'))->toBeFalse()
        ->and(config('ecosystem.content.public_enabled'))->toBeFalse();
});
