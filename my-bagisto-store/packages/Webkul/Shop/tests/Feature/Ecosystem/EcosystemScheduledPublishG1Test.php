<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\BeginRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentPublicationService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\ScheduledPublicationScanner;

/**
 * G1-09 · 到期发布扫描（规划 §5.3 / §8.1 / §8.6）。
 *
 * 覆盖：
 *   · 到点活动 / 文章切换公开指针（系统 actor）；
 *   · 未来排期不发布；
 *   · 业务前置失败 → 安全回退 + schedule_aborted 审计 + 告警；
 *   · 已发布对象失败 → 保留旧公开指针、作废候选；
 *   · withheld / retracted 不复活；
 *   · 域发布开关关闭 → 整域跳过，不回退排期；
 *   · dry-run 不写库。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-09 scheduled publication tests require summon_mecha_step10_test.');
    }

    config(['ecosystem_publishing.event_publish_enabled' => true]);
});

function g9sAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G9 Scheduler Admin',
        'email'      => 'g9-scheduler-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g9sOrganizer(): EcosystemOrganizer
{
    return EcosystemOrganizer::create([
        'name'             => 'G9S 主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g9sEventPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                      => 'G9S 活动 '.bin2hex(random_bytes(3)),
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

function g9sNewEvent(EcosystemOrganizer $organizer): Event
{
    return Event::create([
        'name'           => 'G9S 活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'competition',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ])->fresh();
}

/**
 * @return array{0: Event, 1: EventRevision, 2: int, 3: EcosystemOrganizer}
 */
function g9sApprovedEvent(): array
{
    $adminId = g9sAdminId();
    $organizer = g9sOrganizer();
    $event = g9sNewEvent($organizer);
    $revision = app(EventRevisionService::class)->createDraft($event, $adminId, g9sEventPayload($organizer));

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $event = app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1);

    return [$event->fresh(), $revision->fresh(), $adminId, $organizer];
}

function g9sBackdate(string $table, int $id): void
{
    DB::table($table)->where('id', $id)->update([
        'scheduled_publish_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    ]);
}

/**
 * @return array{0: ContentPost, 1: \Webkul\Shop\Models\ContentRevision, 2: int}
 */
function g9sApprovedContent(): array
{
    config([
        'ecosystem_publishing.content_publish_enabled'  => true,
        'ecosystem_publishing.b04_content_slug_signed'  => true,
        'ecosystem_publishing.b07_content_legal_signed' => true,
    ]);

    $adminId = g9sAdminId();
    $post = app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g9s-content-'.bin2hex(random_bytes(5)),
    ]);

    $revision = app(ContentRevisionService::class)->createDraft($post, $adminId, [
        'title'          => 'G9S 资讯标题',
        'summary'        => 'G9S 摘要',
        'body_html'      => '<p>G9S 正文</p>',
        'author_name'    => '张三',
        'author_org'     => '召唤机甲',
        'source_type'    => 'original',
        'change_summary' => '初始版本',
    ], [[
        'source_name'        => '平台官网',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
    ]]);

    $post = app(ContentReviewService::class)->submit($post, $revision, $adminId, 0);
    $post = app(ContentReviewService::class)->approve($post, $revision->fresh(), $adminId, 1);

    return [$post->fresh(), $revision->fresh(), $adminId];
}

it('到期活动由系统切换公开指针并写审计', function () {
    [$event, $revision, $adminId] = g9sApprovedEvent();

    $scheduled = app(EventPublicationService::class)->schedule(
        $event,
        $revision,
        $adminId,
        (int) $event->row_version,
        now('UTC')->addHour(),
    );

    g9sBackdate('events', (int) $scheduled->id);

    $summary = app(ScheduledPublicationScanner::class)->run(10);

    $published = $scheduled->fresh();

    expect($summary['published'])->toBe(1)
        ->and($summary['aborted'])->toBe(0)
        ->and((string) $published->publish_status)->toBe('published')
        ->and((int) $published->published_revision_id)->toBe((int) $revision->id)
        ->and($published->scheduled_publish_at)->toBeNull()
        ->and($published->approved_revision_id)->toBeNull();

    expect(DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'event')
        ->where('aggregate_id', $scheduled->id)
        ->where('event_type', 'event.published')
        ->exists())->toBeTrue();

    $audit = DB::table('operation_log')
        ->where('action', 'ecosystem.event.publish')
        ->where('target_id', $scheduled->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe('system');
});

it('未来排期不会被提前发布', function () {
    [$event, $revision, $adminId] = g9sApprovedEvent();

    $scheduled = app(EventPublicationService::class)->schedule(
        $event,
        $revision,
        $adminId,
        (int) $event->row_version,
        now('UTC')->addHour(),
    );

    $summary = app(ScheduledPublicationScanner::class)->run(10);

    expect($summary['candidates'])->toBe(0)
        ->and($summary['published'])->toBe(0)
        ->and((string) $scheduled->fresh()->publish_status)->toBe('scheduled');
});

it('到期文章由系统切换公开指针', function () {
    [$post, $revision, $adminId] = g9sApprovedContent();

    $scheduled = app(ContentPublicationService::class)->schedule(
        $post,
        $revision,
        $adminId,
        (int) $post->row_version,
        now('UTC')->addHour(),
    );

    g9sBackdate('content_posts', (int) $scheduled->id);

    $summary = app(ScheduledPublicationScanner::class)->run(10);
    $published = $scheduled->fresh();

    expect($summary['published'])->toBe(1)
        ->and((string) $published->publish_status)->toBe('published')
        ->and((int) $published->published_revision_id)->toBe((int) $revision->id)
        ->and($published->scheduled_publish_at)->toBeNull();
});

it('业务前置失败：从未发布对象回 approved、清排期、写审计并告警', function () {
    [$event, $revision, $adminId, $organizer] = g9sApprovedEvent();

    $scheduled = app(EventPublicationService::class)->schedule(
        $event,
        $revision,
        $adminId,
        (int) $event->row_version,
        now('UTC')->addHour(),
    );

    g9sBackdate('events', (int) $scheduled->id);
    DB::table('ecosystem_organizers')->where('id', $organizer->id)->update(['status' => 'pending']);

    $summary = app(ScheduledPublicationScanner::class)->run(10);
    $after = $scheduled->fresh();

    expect($summary['published'])->toBe(0)
        ->and($summary['aborted'])->toBe(1)
        ->and((string) $after->publish_status)->toBe('approved')
        ->and($after->scheduled_publish_at)->toBeNull()
        ->and((int) $after->approved_revision_id)->toBe((int) $revision->id)
        ->and($after->published_revision_id)->toBeNull();

    $audit = DB::table('operation_log')
        ->where('action', 'ecosystem.event.schedule_aborted')
        ->where('target_id', $scheduled->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_type)->toBe('system');

    $payload = json_decode((string) $audit->payload, true);
    expect($payload['reason_code'])->toBe('schedule_aborted')
        ->and($payload['from'])->toBe('scheduled')
        ->and($payload['to'])->toBe('approved');

    expect(DB::table('notification')
        ->where('type', 'ecosystem.schedule_aborted')
        ->where('recipient_type', 'admin')
        ->where('recipient_id', $adminId)
        ->exists())->toBeTrue();
});

it('业务前置失败：已发布对象保留旧公开指针并作废候选', function () {
    [$event, $rev1, $adminId, $organizer] = g9sApprovedEvent();

    $published = app(EventPublicationService::class)->publishNow(
        $event,
        $rev1,
        $adminId,
        (int) $event->row_version,
    );

    // 已发布对象的新候选：开始工作修订 -> 提交 -> 批准。
    $rev2 = app(BeginRevisionService::class)->startEventRevision(
        $published,
        $adminId,
        (int) $published->row_version,
        g9sEventPayload($organizer, ['name' => 'G9S 活动 v2', 'change_summary' => '第二版']),
    );

    $event = app(EventReviewService::class)->submit($published->fresh(), $rev2, $adminId, (int) $published->fresh()->row_version);
    $event = app(EventReviewService::class)->approve($event, $rev2->fresh(), $adminId, (int) $event->row_version);

    $scheduled = app(EventPublicationService::class)->schedule(
        $event,
        $rev2->fresh(),
        $adminId,
        (int) $event->row_version,
        now('UTC')->addHour(),
    );

    g9sBackdate('events', (int) $scheduled->id);
    DB::table('ecosystem_organizers')->where('id', $organizer->id)->update(['status' => 'pending']);

    $summary = app(ScheduledPublicationScanner::class)->run(10);
    $after = $scheduled->fresh();

    expect($summary['aborted'])->toBe(1)
        ->and((string) $after->publish_status)->toBe('published')
        ->and((int) $after->published_revision_id)->toBe((int) $rev1->id)
        ->and($after->scheduled_publish_at)->toBeNull()
        ->and($after->approved_revision_id)->toBeNull();
});

it('withheld / retracted 不会被扫描复活', function () {
    [$published, $revision, $adminId] = g9sApprovedEvent();

    $published = app(EventPublicationService::class)->publishNow(
        $published,
        $revision,
        $adminId,
        (int) $published->row_version,
    );

    $withheld = app(EventPublicationService::class)->withhold(
        $published,
        $adminId,
        (int) $published->row_version,
        '内部原因',
    );

    // 模拟一条残留排期，验证 disposition 过滤优先于时间。
    DB::table('events')->where('id', $withheld->id)->update([
        'scheduled_publish_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    ]);
    g9sBackdate('events', (int) $withheld->id);

    $summary = app(ScheduledPublicationScanner::class)->run(10);

    expect($summary['candidates'])->toBe(0)
        ->and((string) $withheld->fresh()->disposition)->toBe('withheld');

    $retracted = app(EventPublicationService::class)->retract(
        $withheld->fresh(),
        $adminId,
        (int) $withheld->fresh()->row_version,
        '内部原因',
    );

    g9sBackdate('events', (int) $retracted->id);
    DB::table('events')->where('id', $retracted->id)->update([
        'scheduled_publish_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s'),
    ]);

    expect(app(ScheduledPublicationScanner::class)->run(10)['candidates'])->toBe(0)
        ->and((string) $retracted->fresh()->disposition)->toBe('retracted');
});

it('域发布开关关闭时整域跳过，不回退已排期对象', function () {
    [$event, $revision, $adminId] = g9sApprovedEvent();

    $scheduled = app(EventPublicationService::class)->schedule(
        $event,
        $revision,
        $adminId,
        (int) $event->row_version,
        now('UTC')->addHour(),
    );

    g9sBackdate('events', (int) $scheduled->id);
    config(['ecosystem_publishing.event_publish_enabled' => false]);

    $summary = app(ScheduledPublicationScanner::class)->run(10);

    expect($summary['domains']['event']['skipped_disabled'])->toBeTrue()
        ->and($summary['candidates'])->toBe(0)
        ->and($summary['aborted'])->toBe(0)
        ->and((string) $scheduled->fresh()->publish_status)->toBe('scheduled');
});

it('dry-run 只统计候选，不发布也不回退', function () {
    [$event, $revision, $adminId] = g9sApprovedEvent();

    $scheduled = app(EventPublicationService::class)->schedule(
        $event,
        $revision,
        $adminId,
        (int) $event->row_version,
        now('UTC')->addHour(),
    );

    g9sBackdate('events', (int) $scheduled->id);

    $summary = app(ScheduledPublicationScanner::class)->run(10, true);

    expect($summary['dry_run'])->toBeTrue()
        ->and($summary['candidates'])->toBe(1)
        ->and($summary['published'])->toBe(0)
        ->and((string) $scheduled->fresh()->publish_status)->toBe('scheduled');
});
