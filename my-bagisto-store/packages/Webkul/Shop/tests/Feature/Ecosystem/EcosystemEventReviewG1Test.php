<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\EventWriteService;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-08A · 活动提交 / 批准 / 拒绝（ADR-0001 附录 B A-01/A-04/A-06）。
 *
 * 覆盖：
 *   · draft -> in_review -> approved / rejected；
 *   · 提交后 revision 不可改；working 指针离开 draft 时清空；
 *   · stale expected_row_version 稳定冲突，不静默覆盖；
 *   · B-01/B-03 未签署时非 platform + organizer 在提交阶段拒绝；
 *   · retracted 终态拒绝任何动作；
 *   · 本批不写 published_revision_id / scheduled_publish_at / outbox。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08A event review tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g8EvtAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8 Event Reviewer',
        'email'      => 'g8-event-'.bin2hex(random_bytes(8)).'@example.test',
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
function g8EvtOrganizer(array $overrides = []): EcosystemOrganizer
{
    return EcosystemOrganizer::create(array_merge([
        'name'             => 'G8 主办方 '.bin2hex(random_bytes(3)),
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
function g8EvtEvent(EcosystemOrganizer $organizer, array $overrides = []): Event
{
    return Event::create(array_merge([
        'name'           => 'G8 活动 '.bin2hex(random_bytes(3)),
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
function g8EvtPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                      => 'G8 活动公开名称',
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

function g8EvtDraft(Event $event, EcosystemOrganizer $organizer, int $adminId, array $overrides = []): EventRevision
{
    return app(EventRevisionService::class)->createDraft($event, $adminId, g8EvtPayload($organizer, $overrides));
}

function g8EvtReview(): EventReviewService
{
    return app(EventReviewService::class);
}

it('G1-08A · 活动 draft -> in_review -> approved，working 指针在离开 draft 时清空', function () {
    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer();
    $event = g8EvtEvent($organizer);
    $revision = g8EvtDraft($event, $organizer, $adminId);

    // 明确挂 working 指针，验证提交后不会继续指向 in_review revision。
    $event = app(EventWriteService::class)->update($event, 0, [
        'working_revision_id' => (int) $revision->getKey(),
    ]);

    expect((int) $event->row_version)->toBe(1);

    $event = g8EvtReview()->submit($event, $revision, $adminId, 1);
    $revision->refresh();

    expect((string) $event->publish_status)->toBe('in_review')
        ->and((int) $event->row_version)->toBe(2)
        ->and($event->working_revision_id)->toBeNull()
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->published_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and((string) $revision->review_state)->toBe('in_review')
        ->and((int) $revision->submitted_by)->toBe($adminId)
        ->and($revision->submitted_at)->not->toBeNull();

    $event = g8EvtReview()->approve($event, $revision, $adminId, 2, '资料齐全');
    $revision->refresh();

    expect((string) $event->publish_status)->toBe('approved')
        ->and((int) $event->approved_revision_id)->toBe((int) $revision->getKey())
        ->and($event->published_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and((int) $event->row_version)->toBe(3)
        ->and((string) $revision->review_state)->toBe('approved')
        ->and((int) $revision->reviewed_by)->toBe($adminId)
        ->and((string) $revision->review_note)->toBe('资料齐全');
});

it('G1-08A · in_review 后修订内容不可修改，服务层拒绝', function () {
    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer();
    $event = g8EvtEvent($organizer);
    $revision = g8EvtDraft($event, $organizer, $adminId);

    $event = g8EvtReview()->submit($event, $revision, $adminId, 0);
    $revision->refresh();

    expect(fn () => app(EventRevisionService::class)->updateDraft(
        $revision,
        (string) $revision->content_fingerprint,
        ['name' => '审核中被改掉的名称'],
    ))->toThrow(RevisionImmutableException::class);
});

it('G1-08A · 拒绝回到 draft，原 revision 变为 rejected 且不可重开', function () {
    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer();
    $event = g8EvtEvent($organizer);
    $revision = g8EvtDraft($event, $organizer, $adminId);

    $event = g8EvtReview()->submit($event, $revision, $adminId, 0);
    $event = g8EvtReview()->reject($event, $revision->fresh(), $adminId, 1, '来源授权依据不足');
    $revision->refresh();

    expect((string) $event->publish_status)->toBe('draft')
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->working_revision_id)->toBeNull()
        ->and((string) $revision->review_state)->toBe('rejected')
        ->and((string) $revision->review_note)->toBe('来源授权依据不足')
        ->and((int) $event->row_version)->toBe(2);

    // 被拒绝的 revision 不能重开，只能新建 revision_no + 1。
    expect(fn () => g8EvtReview()->approve($event, $revision, $adminId, 2))
        ->toThrow(PublicationTransitionException::class);
});

it('G1-08A · stale expected_row_version 返回稳定冲突，不覆盖已提交状态', function () {
    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer();
    $event = g8EvtEvent($organizer);
    $revision = g8EvtDraft($event, $organizer, $adminId);

    $event = g8EvtReview()->submit($event, $revision, $adminId, 0);

    // 第二个操作者仍拿着提交前的 row_version=0。
    expect(fn () => g8EvtReview()->approve($event, $revision, $adminId, 0))
        ->toThrow(\Webkul\Shop\Services\Ecosystem\EventRevisionConflictException::class);

    $fresh = $event->fresh();
    $revision->refresh();

    expect((string) $fresh->publish_status)->toBe('in_review')
        ->and((int) $fresh->row_version)->toBe(1)
        ->and($fresh->approved_revision_id)->toBeNull()
        ->and((string) $revision->review_state)->toBe('in_review');
});

it('G1-08A · B-01/B-03 未签署时，非 platform + organizer 在提交阶段拒绝', function () {
    config([
        'ecosystem_publishing.b01_external_links_signed'         => false,
        'ecosystem_publishing.b03_organizer_verification_signed' => false,
    ]);

    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer(['platform_role' => 'directory_only']);
    $event = g8EvtEvent($organizer, [
        'source_type'   => 'external',
        'platform_role' => 'directory_only',
    ]);

    $revision = g8EvtDraft($event, $organizer, $adminId, [
        'source_type'               => 'external',
        'platform_role'             => 'directory_only',
        'external_registration_url' => 'https://external.example.com/register',
        'source_url'                => 'https://external.example.com/event',
        'authorization_basis'       => 'official_link_only',
    ]);

    try {
        g8EvtReview()->submit($event, $revision, $adminId, 0);
        $this->fail('外部活动在 B-01/B-03 未签署时不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED);
    }

    expect((string) $event->fresh()->publish_status)->toBe('draft')
        ->and((string) $revision->fresh()->review_state)->toBe('draft');
});

it('G1-08A · disposition=retracted 的活动拒绝提交 / 批准 / 拒绝', function () {
    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer();
    $event = g8EvtEvent($organizer);
    $revision = g8EvtDraft($event, $organizer, $adminId);

    DB::table('events')->where('id', $event->id)->update(['disposition' => 'retracted']);

    expect(fn () => g8EvtReview()->submit($event->fresh(), $revision, $adminId, 0))
        ->toThrow(PublicationTransitionException::class);

    expect((string) $event->fresh()->publish_status)->toBe('draft')
        ->and((string) $revision->fresh()->review_state)->toBe('draft');
});

it('G1-08A · 已发布活动拒绝新 revision：公开指针不变，approved / scheduled 清空', function () {
    $adminId = g8EvtAdminId();
    $organizer = g8EvtOrganizer();
    $event = g8EvtEvent($organizer);
    $rev1 = g8EvtDraft($event, $organizer, $adminId);

    DB::table('event_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('events')->where('id', $event->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        // 夹具模拟 G1-08B 的排期写入；拒绝时必须清空。
        'scheduled_publish_at'  => '2026-12-01 00:00:00',
        'row_version'           => 4,
    ]);

    $rev2 = g8EvtDraft($event->fresh(), $organizer, $adminId, ['name' => '待拒绝的新版本']);
    $event = g8EvtReview()->submit($event->fresh(), $rev2, $adminId, 4);

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->approved_revision_id)->toBe((int) $rev1->id);

    $event = g8EvtReview()->reject($event, $rev2->fresh(), $adminId, 5, '新版本资料不完整')->fresh();

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->id)
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and($event->working_revision_id)->toBeNull()
        ->and((int) $event->row_version)->toBe(6)
        ->and((string) $rev2->fresh()->review_state)->toBe('rejected');
});
