<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentPublicationService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\EcosystemOutboxProcessor;
use Webkul\Shop\Services\Ecosystem\EventPublicationService;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;

/**
 * G1-09 · outbox 消费处理器（ADR-0001 G1-D07 / 规划 §5.3）。
 *
 * 覆盖：
 *   · 真实发布事实 → sent（只记账，不发送任何通知）；
 *   · row_version 被更新事实取代 → dead(superseded)；
 *   · 未知 event_type / aggregate_type → dead + error 日志；
 *   · --dry-run 语义（不领取/不结算）与 --limit 有界批次；
 *   · 内容域 published 事实同样可核验（slug 仍存在）。
 *
 * 重试 backoff / max attempts / 旧租约拒绝属于租约层，见
 * `EcosystemOutboxLeaseG1Test.php`。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-09 outbox processor tests require summon_mecha_step10_test.');
    }

    config(['ecosystem_publishing.event_publish_enabled' => true]);
});

function g9AdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G9 Worker Admin',
        'email'      => 'g9-worker-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g9Organizer(): EcosystemOrganizer
{
    return EcosystemOrganizer::create([
        'name'             => 'G9 主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ]);
}

/**
 * @return array{0: Event, 1: EventRevision, 2: int, 3: EcosystemOrganizer}
 */
function g9ApprovedEvent(): array
{
    $adminId = g9AdminId();
    $organizer = g9Organizer();

    $event = Event::create([
        'name'           => 'G9 活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'competition',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ])->fresh();

    $revision = app(EventRevisionService::class)->createDraft($event, $adminId, [
        'name'                      => 'G9 活动公开名称',
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
    ]);

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);
    $event = app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1);

    return [$event->fresh(), $revision->fresh(), $adminId, $organizer];
}

function g9PublishedEvent(): array
{
    [$event, $revision, $adminId] = g9ApprovedEvent();

    $event = app(EventPublicationService::class)->publishNow($event, $revision, $adminId, (int) $event->row_version);

    return [$event->fresh(), $revision->fresh()];
}

/**
 * @return array{0: ContentPost, 1: \Webkul\Shop\Models\ContentRevision, 2: int}
 */
function g9PublishedContent(): array
{
    config([
        'ecosystem_publishing.content_publish_enabled' => true,
        'ecosystem_publishing.b04_content_slug_signed' => true,
        'ecosystem_publishing.b07_content_legal_signed' => true,
    ]);

    $adminId = g9AdminId();
    $post = app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g9-content-'.bin2hex(random_bytes(5)),
    ]);

    $revision = app(ContentRevisionService::class)->createDraft($post, $adminId, [
        'title'          => 'G9 资讯标题',
        'summary'        => 'G9 摘要',
        'body_html'      => '<p>G9 正文</p>',
        'author_name'    => '张三',
        'author_org'     => '召唤机甲',
        'source_type'    => 'original',
        'change_summary' => '初始版本',
    ], [[
        'source_name'        => '平台官网',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => \Illuminate\Support\Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
    ]]);

    $post = app(ContentReviewService::class)->submit($post, $revision, $adminId, 0);
    $post = app(ContentReviewService::class)->approve($post, $revision->fresh(), $adminId, 1);
    $post = app(ContentPublicationService::class)->publishNow($post, $revision->fresh(), $adminId, (int) $post->row_version);

    return [$post->fresh(), $revision->fresh(), $adminId];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g9InsertRawOutbox(array $overrides = []): int
{
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
        'available_at' => now('UTC')->format('Y-m-d H:i:s'),
        'lease_owner'  => null,
        'lease_until'  => null,
        'last_error'   => null,
        'created_at'   => now(),
        'updated_at'   => now(),
    ], $overrides));
}

function g9OutboxProcessor(): EcosystemOutboxProcessor
{
    return app(EcosystemOutboxProcessor::class);
}

it('已发布的真实事实被结算为 sent，但不代表已通知用户', function () {
    [$event] = g9PublishedEvent();

    $summary = g9OutboxProcessor()->run(10);

    expect($summary['sent'])->toBe(1)
        ->and($summary['dead'])->toBe(0);

    $row = DB::table('ecosystem_outbox')->where('aggregate_type', 'event')->where('aggregate_id', $event->id)->first();
    expect($row->status)->toBe('sent')
        ->and($row->lease_owner)->toBeNull()
        ->and($row->lease_until)->toBeNull()
        ->and($row->last_error)->toBeNull();
});

it('被新 row_version 取代的旧事实标记 dead(superseded)', function () {
    [$event] = g9PublishedEvent();

    DB::table('events')->where('id', $event->id)->update([
        'row_version' => (int) $event->row_version + 1,
        'updated_at'  => now(),
    ]);

    $summary = g9OutboxProcessor()->run(10);

    expect($summary['sent'])->toBe(0)
        ->and($summary['dead'])->toBe(1);

    $row = DB::table('ecosystem_outbox')->where('aggregate_id', $event->id)->latest('id')->first();
    expect($row->status)->toBe('dead')
        ->and((string) $row->last_error)->toStartWith('dead:superseded:');
});

it('未知 event_type 直接 dead', function () {
    $id = g9InsertRawOutbox([
        'event_type' => 'event.registration_created',
        'payload'    => json_encode(['revision_id' => 0, 'row_version' => 0]),
    ]);

    $summary = g9OutboxProcessor()->run(10);

    expect($summary['dead'])->toBe(1);

    $row = DB::table('ecosystem_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('dead')
        ->and((string) $row->last_error)->toStartWith('dead:unknown_event_type:');
});

it('未知 aggregate_type 直接 dead', function () {
    $id = g9InsertRawOutbox(['aggregate_type' => 'bogus']);

    expect(g9OutboxProcessor()->run(10)['dead'])->toBe(1);

    expect((string) DB::table('ecosystem_outbox')->where('id', $id)->value('last_error'))
        ->toStartWith('dead:unknown_aggregate_type:');
});

it('event_type 与 aggregate_type 不匹配直接 dead', function () {
    [$event] = g9PublishedEvent();

    DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'event')
        ->where('aggregate_id', $event->id)
        ->update(['aggregate_type' => 'content']);

    expect(g9OutboxProcessor()->run(10)['dead'])->toBeGreaterThanOrEqual(1);
});

it('内容域 published 事实核验通过', function () {
    [$post] = g9PublishedContent();

    $summary = g9OutboxProcessor()->run(10);

    expect($summary['sent'])->toBeGreaterThanOrEqual(1)
        ->and($summary['dead'])->toBe(0);

    expect(DB::table('ecosystem_outbox')
        ->where('aggregate_type', 'content')
        ->where('aggregate_id', $post->id)
        ->value('status'))->toBe('sent');
});

it('dry-run 不领取、不结算', function () {
    $id = g9InsertRawOutbox();

    $summary = g9OutboxProcessor()->run(10, true);

    expect($summary['dry_run'])->toBeTrue()
        ->and($summary['pending_due'])->toBeGreaterThanOrEqual(1)
        ->and($summary['claimed'])->toBe(0);

    $row = DB::table('ecosystem_outbox')->where('id', $id)->first();
    expect($row->status)->toBe('pending')
        ->and($row->attempts)->toBe(0);
});

it('limit 是有界批次，本轮未领取的行保持 pending', function () {
    $ids = [
        g9InsertRawOutbox(['event_type' => 'event.unknown_a']),
        g9InsertRawOutbox(['event_type' => 'event.unknown_b']),
        g9InsertRawOutbox(['event_type' => 'event.unknown_c']),
    ];

    $summary = g9OutboxProcessor()->run(2);

    expect($summary['claimed'])->toBe(2)
        ->and($summary['dead'])->toBe(2);

    expect(DB::table('ecosystem_outbox')->where('id', $ids[2])->value('status'))->toBe('pending');

    $second = g9OutboxProcessor()->run(10);
    expect($second['claimed'])->toBe(1);
    expect(DB::table('ecosystem_outbox')->where('id', $ids[2])->value('status'))->toBe('dead');
});
