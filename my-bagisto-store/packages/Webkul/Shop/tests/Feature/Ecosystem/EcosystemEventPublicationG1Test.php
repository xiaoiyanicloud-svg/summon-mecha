<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\EcosystemOutbox;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;
use Webkul\Shop\Services\Ecosystem\EventRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G1-08B · 活动发布 / 排期 / 立即发布 / 系统到期发布（ADR-0001 §8.1~§8.4）。
 *
 * 覆盖：
 *   · 未批准 / 候选不匹配 / 批准后内容变动 → 发布拒绝且不切换；
 *   · 主办方未核验、B-01/B-03 未签署 → 发布拒绝；
 *   · 排期未来/过去、取消排期、UTC 落库；
 *   · 立即发布：指针 / 时间 / outbox / 审计同事务；
 *   · 已发布对象切换新候选：切换前旧指针保持，切换后旧 revision 保留；
 *   · expected_row_version 并发、outbox/审计失败整体回滚、重复发布幂等；
 *   · 普通写入口仍不能写 published_revision_id。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08B event publication tests require summon_mecha_step10_test.');
    }
});

function g8bEvtAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8B Event Publisher',
        'email'      => 'g8b-event-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g8bEvtOrganizer(array $overrides = []): EcosystemOrganizer
{
    return EcosystemOrganizer::create(array_merge([
        'name'             => 'G8B 主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g8bEvtEvent(EcosystemOrganizer $organizer, array $overrides = []): Event
{
    return Event::create(array_merge([
        'name'           => 'G8B 活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'competition',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ], $overrides))->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8bEvtPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                      => 'G8B 活动公开名称',
        'summary'                   => '活动摘要',
        'detail_html'               => '<p>活动正文</p>',
        'city'                      => '上海',
        'location_text'             => '张江科学会堂',
        'participation_mode'        => 'offline',
        'timezone'                  => 'Asia/Shanghai',
        'starts_at'                 => '2026-11-01 10:00:00',
        'ends_at'                   => '2026-11-01 18:00:00',
        'source_type'               => 'platform',
        'platform_role'             => $organizer->platform_role,
        'organizer_id'              => (int) $organizer->id,
        'organizer_name_snapshot'   => $organizer->name,
        'source_name'               => '官网活动页',
        'source_url'                => 'https://example.com/events',
        'source_verified_at'        => '2026-10-01 10:00:00',
        'authorization_basis'       => 'self_organized',
        'external_registration_url' => null,
        'rule_summary'              => '规则摘要',
        'change_summary'            => '初始版本',
    ], $overrides);
}

function g8bEvtDraft(Event $event, EcosystemOrganizer $organizer, int $adminId, array $overrides = []): EventRevision
{
    return app(EventRevisionService::class)->createDraft($event, $adminId, g8bEvtPayload($organizer, $overrides));
}

/**
 * 走到 approved：createDraft(row_version 0) -> submit(0->1) -> approve(1->2)。
 *
 * @return array{0: Event, 1: EventRevision, 2: int, 3: EcosystemOrganizer}
 */
function g8bEvtApproved(): array
{
    $adminId = g8bEvtAdminId();
    $organizer = g8bEvtOrganizer();
    $event = g8bEvtEvent($organizer);
    $revision = g8bEvtDraft($event, $organizer, $adminId);

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $event = app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1);

    return [$event->fresh(), $revision->fresh(), $adminId, $organizer];
}

function g8bEvtService(): EventPublicationService
{
    return app(EventPublicationService::class);
}

function g8bEnableEventPublishing(): void
{
    config(['ecosystem_publishing.event_publish_enabled' => true]);
}

it('G1-08B · 未批准的 revision 不能直接发布', function () {
    g8bEnableEventPublishing();

    $adminId = g8bEvtAdminId();
    $organizer = g8bEvtOrganizer();
    $event = g8bEvtEvent($organizer);
    $revision = g8bEvtDraft($event, $organizer, $adminId);

    expect(fn () => g8bEvtService()->publishNow($event, $revision, $adminId, 0))
        ->toThrow(PublicationTransitionException::class);

    $fresh = $event->fresh();

    expect((string) $fresh->publish_status)->toBe('draft')
        ->and($fresh->published_revision_id)->toBeNull()
        ->and($fresh->approved_revision_id)->toBeNull()
        ->and(EcosystemOutbox::count())->toBe(0)
        ->and(DB::table('operation_log')->where('action', 'ecosystem.event.publish')->count())->toBe(0);
});

it('G1-08B · 目标 revision 不是当前已批准候选时拒绝发布', function () {
    g8bEnableEventPublishing();

    [$event, $revision, $adminId, $organizer] = g8bEvtApproved();
    $other = g8bEvtDraft($event, $organizer, $adminId, [
        'name'           => '不是候选的版本',
        'change_summary' => '干扰版本',
    ]);
    DB::table('event_revisions')->where('id', $other->id)->update(['review_state' => 'approved']);

    expect(fn () => g8bEvtService()->publishNow($event, $other, $adminId, 2))
        ->toThrow(PublicationTransitionException::class);

    $fresh = $event->fresh();

    expect((string) $fresh->publish_status)->toBe('approved')
        ->and((int) $fresh->approved_revision_id)->toBe((int) $revision->getKey())
        ->and($fresh->published_revision_id)->toBeNull();
});

it('G1-08B · 批准后正文 / 来源 / 媒体集合 / sanitizer 变动，发布重检失败且不切换', function (string $column, string $value) {
    g8bEnableEventPublishing();

    [$event, $revision, $adminId] = g8bEvtApproved();

    DB::table('event_revisions')->where('id', $revision->id)->update([$column => $value]);

    try {
        g8bEvtService()->publishNow($event, $revision->fresh(), $adminId, 2);
        $this->fail('批准后内容变动不应允许发布。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_REVISION_CONFLICT);
    }

    $fresh = $event->fresh();

    expect((string) $fresh->publish_status)->toBe('approved')
        ->and($fresh->published_revision_id)->toBeNull()
        ->and($fresh->published_at)->toBeNull()
        ->and($fresh->first_published_at)->toBeNull()
        ->and(EcosystemOutbox::count())->toBe(0);
})->with([
    '正文变动'   => ['detail_html', '<p>批准后被改掉的正文</p>'],
    '来源变动'   => ['source_url', 'https://example.com/events/changed'],
    '媒体集合变动' => ['media_manifest_hash', str_repeat('0', 64)],
    '净化版本变动' => ['sanitizer_version', 'unsanitized-legacy'],
]);

it('G1-08B · 主办方 pending / rejected / suspended 时拒绝发布', function (string $status) {
    g8bEnableEventPublishing();

    [$event, $revision, $adminId, $organizer] = g8bEvtApproved();

    DB::table('ecosystem_organizers')->where('id', $organizer->id)->update(['status' => $status]);

    try {
        g8bEvtService()->publishNow($event, $revision, $adminId, 2);
        $this->fail('主办方未核验不应允许发布。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED);
    }

    expect($event->fresh()->published_revision_id)->toBeNull()
        ->and((string) $event->fresh()->publish_status)->toBe('approved');
})->with(['pending', 'rejected', 'suspended']);

it('G1-08B · B-01/B-03 未签署时，非 platform + organizer 拒绝发布', function () {
    g8bEnableEventPublishing();
    config([
        'ecosystem_publishing.b01_external_links_signed'         => false,
        'ecosystem_publishing.b03_organizer_verification_signed' => false,
    ]);

    $adminId = g8bEvtAdminId();
    $organizer = g8bEvtOrganizer(['platform_role' => 'directory_only']);
    $event = g8bEvtEvent($organizer, [
        'source_type'   => 'external',
        'platform_role' => 'directory_only',
    ]);
    $revision = g8bEvtDraft($event, $organizer, $adminId, [
        'source_type'               => 'external',
        'platform_role'             => 'directory_only',
        'external_registration_url' => 'https://external.example.com/register',
        'source_url'                => 'https://external.example.com/event',
        'authorization_basis'       => 'official_link_only',
    ]);

    // 提交阶段已被拒绝，这里直接用库内状态构造“已批准但不可发布”的边界。
    DB::table('event_revisions')->where('id', $revision->id)->update(['review_state' => 'approved']);
    DB::table('events')->where('id', $event->id)->update([
        'publish_status'       => 'approved',
        'approved_revision_id' => (int) $revision->id,
    ]);

    try {
        g8bEvtService()->publishNow($event->fresh(), $revision->fresh(), $adminId, 0);
        $this->fail('B-01/B-03 未签署时外部活动不应允许发布。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED);
    }

    expect($event->fresh()->published_revision_id)->toBeNull();
});

it('G1-08B · 排期必须是未来 UTC；到点前不允许系统发布', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();

    $publisher = g8bEvtService();

    expect(fn () => $publisher->schedule(
        $event,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2020-01-01 00:00:00', new DateTimeZone('UTC')),
    ))->toThrow(PublicationTransitionException::class);

    $scheduled = $publisher->schedule(
        $event,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Asia/Shanghai')),
    );

    // 上海 10:00 = UTC 02:00；读回必须是 UTC，不受应用时区影响。
    expect((string) $scheduled->publish_status)->toBe('scheduled')
        ->and((int) $scheduled->approved_revision_id)->toBe((int) $revision->getKey())
        ->and($scheduled->scheduled_publish_at->format('Y-m-d H:i:s'))->toBe('2026-12-01 02:00:00')
        ->and($scheduled->scheduled_publish_at->timezoneName)->toBe('UTC')
        ->and(DB::table('events')->where('id', $event->id)->value('scheduled_publish_at'))
        ->toBe('2026-12-01 02:00:00');

    // 排期不写 outbox（不是已发生公开事实），但写审计。
    expect(EcosystemOutbox::count())->toBe(0)
        ->and(DB::table('operation_log')->where('action', 'ecosystem.event.schedule')->count())->toBe(1);

    // 未到期：系统发布拒绝且不切换。
    expect(fn () => $publisher->publishScheduled($scheduled))
        ->toThrow(PublicationTransitionException::class);

    expect($scheduled->fresh()->published_revision_id)->toBeNull();
});

it('G1-08B · 取消排期：scheduled -> approved，清时间，不写 outbox', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();

    $publisher = g8bEvtService();
    $scheduled = $publisher->schedule(
        $event,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2026-12-01 02:00:00', new DateTimeZone('UTC')),
    );

    $unscheduled = $publisher->unschedule($scheduled, $adminId, 3);

    expect((string) $unscheduled->publish_status)->toBe('approved')
        ->and($unscheduled->scheduled_publish_at)->toBeNull()
        ->and((int) $unscheduled->approved_revision_id)->toBe((int) $revision->getKey())
        ->and((int) $unscheduled->row_version)->toBe(4)
        ->and(EcosystemOutbox::count())->toBe(0)
        ->and(DB::table('operation_log')->where('action', 'ecosystem.event.unschedule')->count())->toBe(1);
});

it('G1-08B · 立即发布：指针 / 时间 / outbox / 审计同事务', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();

    $published = g8bEvtService()->publishNow($event, $revision, $adminId, 2);
    $revision->refresh();

    expect((string) $published->publish_status)->toBe('published')
        ->and((int) $published->published_revision_id)->toBe((int) $revision->getKey())
        ->and($published->approved_revision_id)->toBeNull()
        ->and($published->scheduled_publish_at)->toBeNull()
        ->and((string) $published->disposition)->toBe('normal')
        ->and($published->published_at)->not->toBeNull()
        ->and($published->first_published_at)->not->toBeNull()
        ->and($published->published_at->timezoneName)->toBe('UTC')
        ->and((int) $published->row_version)->toBe(3)
        ->and($revision->published_at)->not->toBeNull();

    $outbox = EcosystemOutbox::query()->sole();

    expect($outbox->aggregate_type)->toBe('event')
        ->and((int) $outbox->aggregate_id)->toBe((int) $event->id)
        ->and($outbox->event_type)->toBe('event.published')
        ->and($outbox->payload)->toEqual([
            'revision_id'         => (int) $revision->getKey(),
            'revision_no'         => (int) $revision->revision_no,
            'row_version'         => 3,
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'reason_code'         => 'published',
        ])
        ->and($outbox->dedupe_key)->toBe(sprintf(
            'event.published#%d#r%d#v3',
            (int) $event->id,
            (int) $revision->getKey(),
        ));

    $audit = DB::table('operation_log')
        ->where('action', 'ecosystem.event.publish')
        ->where('target_id', (int) $event->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe('admin')
        ->and((int) $audit->actor_id)->toBe($adminId)
        ->and(json_decode((string) $audit->payload, true)['reason_code'])->toBe('published');
});

it('G1-08B · 已发布对象新候选：发布前旧指针保持，发布后切换且旧 revision 保留', function () {
    g8bEnableEventPublishing();
    [$event, $rev1, $adminId, $organizer] = g8bEvtApproved();
    $publisher = g8bEvtService();

    $event = $publisher->publishNow($event, $rev1, $adminId, 2);
    $rev1PublishedAt = $rev1->fresh()->published_at;

    // 已发布对象开始新 revision：父状态与公开指针都不变。
    $rev2 = g8bEvtDraft($event->fresh(), $organizer, $adminId, ['name' => '第二版']);
    $event = app(EventReviewService::class)->submit($event->fresh(), $rev2, $adminId, 3);

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->getKey());

    $event = app(EventReviewService::class)->approve($event, $rev2->fresh(), $adminId, 4);

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->getKey())
        ->and((int) $event->approved_revision_id)->toBe((int) $rev2->getKey());

    $event = $publisher->publishNow($event, $rev2->fresh(), $adminId, 5);

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev2->getKey())
        ->and($event->approved_revision_id)->toBeNull()
        ->and((int) $event->row_version)->toBe(6);

    // 旧 revision 不删除，旧公开时间不被改写；新 revision 首次获得 published_at。
    expect(EventRevision::query()->whereKey($rev1->id)->exists())->toBeTrue()
        ->and($rev1->fresh()->published_at->equalTo($rev1PublishedAt))->toBeTrue()
        ->and($rev2->fresh()->published_at)->not->toBeNull();

    expect(EcosystemOutbox::where('event_type', 'event.published')->count())->toBe(2);
});

it('G1-08B · 系统到期发布：到点后切换指针、写 outbox 与 system 审计', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();
    $publisher = g8bEvtService();

    $scheduled = $publisher->schedule(
        $event,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2026-12-01 02:00:00', new DateTimeZone('UTC')),
    );

    // 模拟到点：把排期时间推到过去（schedule 本身拒绝过去时间）。
    DB::table('events')->where('id', $event->id)->update([
        'scheduled_publish_at' => '2020-01-01 00:00:00',
    ]);

    $published = $publisher->publishScheduled($scheduled->fresh());

    expect((string) $published->publish_status)->toBe('published')
        ->and((int) $published->published_revision_id)->toBe((int) $revision->getKey())
        ->and($published->scheduled_publish_at)->toBeNull()
        ->and($published->approved_revision_id)->toBeNull();

    $audit = DB::table('operation_log')->where('action', 'ecosystem.event.publish')->first();

    expect($audit->actor_type)->toBe('system')
        ->and($audit->actor_id)->toBeNull()
        ->and(EcosystemOutbox::query()->where('event_type', 'event.published')->count())->toBe(1);
});

it('G1-08B · 两个发布者使用同一 expected_row_version，只有一个成功', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();
    $publisher = g8bEvtService();

    $winner = $publisher->publishNow($event, $revision, $adminId, 2);

    expect((int) $winner->row_version)->toBe(3);

    expect(fn () => $publisher->publishNow($event->fresh(), $revision->fresh(), $adminId, 2))
        ->toThrow(EventRevisionConflictException::class);

    expect((int) $event->fresh()->row_version)->toBe(3)
        ->and(EcosystemOutbox::count())->toBe(1);
});

it('G1-08B · 重复发布同一 revision 是 no-op：不重复 outbox、不推 row_version', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();
    $publisher = g8bEvtService();

    $published = $publisher->publishNow($event, $revision, $adminId, 2);
    $beforeOutbox = EcosystemOutbox::count();
    $beforeAudit = DB::table('operation_log')->where('action', 'ecosystem.event.publish')->count();

    $again = $publisher->publishNow($published, $revision, $adminId, (int) $published->row_version);

    expect((int) $again->row_version)->toBe((int) $published->row_version)
        ->and((int) $again->published_revision_id)->toBe((int) $revision->getKey())
        ->and(EcosystemOutbox::count())->toBe($beforeOutbox)
        ->and(DB::table('operation_log')->where('action', 'ecosystem.event.publish')->count())->toBe($beforeAudit);
});

it('G1-08B · outbox 写入失败时发布整体回滚', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();

    config(['ecosystem_publishing.outbox_event_types.event.publish' => 'event.not_registered']);

    expect(fn () => g8bEvtService()->publishNow($event, $revision, $adminId, 2))
        ->toThrow(InvalidArgumentException::class);

    $fresh = $event->fresh();

    expect((string) $fresh->publish_status)->toBe('approved')
        ->and($fresh->published_revision_id)->toBeNull()
        ->and((int) $fresh->approved_revision_id)->toBe((int) $revision->getKey())
        ->and((int) $fresh->row_version)->toBe(2)
        ->and($fresh->published_at)->toBeNull()
        ->and($revision->fresh()->published_at)->toBeNull()
        ->and(EcosystemOutbox::count())->toBe(0)
        ->and(DB::table('operation_log')->where('action', 'ecosystem.event.publish')->count())->toBe(0);
});

it('G1-08B · 审计写入失败时发布整体回滚', function () {
    g8bEnableEventPublishing();
    [$event, $revision, $adminId] = g8bEvtApproved();

    app()->instance(OperationLogger::class, new class extends OperationLogger
    {
        public function logOrFail(
            string $actorType,
            ?int $actorId,
            string $action,
            ?string $targetType = null,
            ?int $targetId = null,
            array $payload = [],
            ?string $ip = null,
        ): OperationLog {
            throw new RuntimeException('forced audit failure');
        }
    });

    expect(fn () => app(EventPublicationService::class)->publishNow($event, $revision, $adminId, 2))
        ->toThrow(RuntimeException::class);

    $fresh = $event->fresh();

    expect((string) $fresh->publish_status)->toBe('approved')
        ->and($fresh->published_revision_id)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(2)
        ->and(EcosystemOutbox::count())->toBe(0);
});

it('G1-08B · 普通写入口仍不能写 published_revision_id', function () {
    $adminId = g8bEvtAdminId();
    $organizer = g8bEvtOrganizer();
    $event = g8bEvtEvent($organizer);
    $revision = g8bEvtDraft($event, $organizer, $adminId);

    expect(fn () => app(EventWriteService::class)->update($event, 0, [
        'published_revision_id' => (int) $revision->getKey(),
    ]))->toThrow(RevisionImmutableException::class);

    expect($event->fresh()->published_revision_id)->toBeNull()
        ->and((int) $event->fresh()->row_version)->toBe(0);
});
