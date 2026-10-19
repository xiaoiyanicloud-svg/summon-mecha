<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\BeginRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;

/**
 * G1-08A · 已发布对象保留旧公开指针 / 新草稿让旧批准失效（ADR 附录 B A-04）。
 *
 * 覆盖：
 *   · published 对象开始新 draft：publish_status / published_revision_id 不变；
 *   · 新 revision 提交/批准期间公开指针不变，批准后 approved_revision_id 指向新候选；
 *   · approved / scheduled 对象开始新 draft：清 approved_revision_id、
 *     清 scheduled_publish_at、父回 draft；
 *   · in_review / retracted 对象拒绝开始新 draft。
 *
 * `scheduled_publish_at` 的测试值只作为**夹具**模拟 G1-08B 写入；
 * G1-08A 的生产代码从不写该字段。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08A release pointer tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g8RelAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8 Release Admin',
        'email'      => 'g8-release-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g8RelOrganizer(): EcosystemOrganizer
{
    return EcosystemOrganizer::create([
        'name'             => 'G8 发布主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ]);
}

function g8RelEvent(EcosystemOrganizer $organizer): Event
{
    return Event::create([
        'name'           => 'G8 发布活动 '.bin2hex(random_bytes(3)),
        'kind'           => 'competition',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => $organizer->platform_role,
        'organizer_id'   => (int) $organizer->id,
    ])->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8RelEventPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                    => 'G8 发布活动名称',
        'detail_html'             => '<p>活动正文</p>',
        'timezone'                => 'Asia/Shanghai',
        'starts_at'               => '2026-11-01 10:00:00',
        'ends_at'                 => '2026-11-01 18:00:00',
        'source_type'             => 'platform',
        'platform_role'           => $organizer->platform_role,
        'organizer_id'            => (int) $organizer->id,
        'organizer_name_snapshot' => $organizer->name,
        'source_name'             => '官网活动页',
        'source_url'              => 'https://example.com/events',
        'source_verified_at'      => '2026-10-01 10:00:00',
        'authorization_basis'     => 'self_organized',
        'change_summary'          => '初始版本',
    ], $overrides);
}

function g8RelEventDraft(Event $event, EcosystemOrganizer $organizer, int $adminId, array $overrides = []): \Webkul\Shop\Models\EventRevision
{
    return app(EventRevisionService::class)->createDraft($event, $adminId, g8RelEventPayload($organizer, $overrides));
}

function g8RelPost(int $adminId): ContentPost
{
    return app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8-release-'.bin2hex(random_bytes(5)),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8RelContentPayload(array $overrides = []): array
{
    return array_merge([
        'title'          => 'G8 发布资讯',
        'body_html'      => '<p>G8 正文</p>',
        'author_name'    => '张三',
        'source_type'    => 'original',
        'change_summary' => '初始版本',
    ], $overrides);
}

function g8RelContentDraft(ContentPost $post, int $adminId, array $overrides = []): \Webkul\Shop\Models\ContentRevision
{
    return app(ContentRevisionService::class)->createDraft(
        $post,
        $adminId,
        g8RelContentPayload($overrides),
        [[
            'source_name'        => '平台官网',
            'source_url'         => 'https://example.com/news/1',
            'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
            'rights_basis'       => 'platform_original',
        ]],
    );
}

it('G1-08A · 已发布活动开始新 draft：公开指针不变，批准后 approved 指针指向新候选', function () {
    $adminId = g8RelAdminId();
    $organizer = g8RelOrganizer();
    $event = g8RelEvent($organizer);
    $rev1 = g8RelEventDraft($event, $organizer, $adminId);

    DB::table('event_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('events')->where('id', $event->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        'first_published_at'    => now(),
        'published_at'          => now(),
        'row_version'           => 5,
    ]);

    $event = $event->fresh();
    $rev2 = app(BeginRevisionService::class)->startEventRevision(
        $event,
        $adminId,
        5,
        g8RelEventPayload($organizer, ['name' => 'G8 发布活动名称 v2', 'change_summary' => '第二版']),
    );

    $event = $event->fresh();

    expect((int) $rev2->revision_no)->toBe(2)
        ->and((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->id)
        ->and($event->approved_revision_id)->toBeNull()
        ->and((int) $event->working_revision_id)->toBe((int) $rev2->id)
        ->and((int) $event->row_version)->toBe(6)
        ->and($event->scheduled_publish_at)->toBeNull();

    $event = app(EventReviewService::class)->submit($event, $rev2, $adminId, 6);
    $event = $event->fresh();

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->id)
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->working_revision_id)->toBeNull();

    $event = app(EventReviewService::class)->approve($event, $rev2->fresh(), $adminId, 7);
    $event = $event->fresh();

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->id)
        ->and((int) $event->approved_revision_id)->toBe((int) $rev2->id)
        ->and((string) $rev1->fresh()->review_state)->toBe('approved')
        ->and((string) $rev2->fresh()->review_state)->toBe('approved');
});

it('G1-08A · 已批准 / 已排期活动开始新 draft：批准失效、排期清空、父回 draft', function (string $status) {
    $adminId = g8RelAdminId();
    $organizer = g8RelOrganizer();
    $event = g8RelEvent($organizer);
    $rev1 = g8RelEventDraft($event, $organizer, $adminId);

    DB::table('event_revisions')->where('id', $rev1->id)->update(['review_state' => 'approved']);
    DB::table('events')->where('id', $event->id)->update([
        'publish_status'       => $status,
        'approved_revision_id' => (int) $rev1->id,
        // 夹具模拟 G1-08B 的排期写入；G1-08A 自身从不写这个字段。
        'scheduled_publish_at' => '2026-12-01 00:00:00',
        'row_version'          => 3,
    ]);

    $rev2 = app(BeginRevisionService::class)->startEventRevision(
        $event->fresh(),
        $adminId,
        3,
        g8RelEventPayload($organizer, ['name' => 'G8 失效批准 v2', 'change_summary' => '第二版']),
    );
    $event = $event->fresh();

    expect((string) $event->publish_status)->toBe('draft')
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and((int) $event->working_revision_id)->toBe((int) $rev2->id)
        ->and((int) $event->row_version)->toBe(4)
        ->and($event->published_revision_id)->toBeNull();
})->with(['approved', 'scheduled']);

it('G1-08A · in_review / retracted 活动拒绝开始新 draft', function () {
    $adminId = g8RelAdminId();
    $organizer = g8RelOrganizer();

    foreach ([['in_review', 'normal'], ['draft', 'retracted']] as [$status, $disposition]) {
        $event = g8RelEvent($organizer);
        DB::table('events')->where('id', $event->id)->update([
            'publish_status' => $status,
            'disposition'    => $disposition,
        ]);

        expect(fn () => app(BeginRevisionService::class)->startEventRevision(
            $event->fresh(),
            $adminId,
            0,
            g8RelEventPayload($organizer, ['name' => '不应创建 '.$status, 'change_summary' => 'x']),
        ))->toThrow(PublicationTransitionException::class);
    }
});

it('G1-08A · 已发布文章开始新 draft：公开指针不变，批准后 approved 指针指向新候选', function () {
    $adminId = g8RelAdminId();
    $post = g8RelPost($adminId);
    $rev1 = g8RelContentDraft($post, $adminId);

    DB::table('content_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('content_posts')->where('id', $post->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        'first_published_at'    => now(),
        'published_at'          => now(),
        'row_version'           => 2,
    ]);

    $post = $post->fresh();
    $rev2 = app(BeginRevisionService::class)->startContentRevision(
        $post,
        $adminId,
        2,
        g8RelContentPayload(['title' => 'G8 发布资讯 v2', 'change_summary' => '第二版']),
        [[
            'source_name'        => '平台官网',
            'source_url'         => 'https://example.com/news/2',
            'source_verified_at' => Carbon::parse('2026-10-02 10:00:00', 'UTC'),
            'rights_basis'       => 'platform_original',
        ]],
    );

    $post = $post->fresh();

    expect((string) $post->publish_status)->toBe('published')
        ->and((int) $post->published_revision_id)->toBe((int) $rev1->id)
        ->and($post->approved_revision_id)->toBeNull()
        ->and((int) $post->working_revision_id)->toBe((int) $rev2->id)
        ->and($post->scheduled_publish_at)->toBeNull();

    $post = app(ContentReviewService::class)->submit($post, $rev2, $adminId, 3);
    $post = app(ContentReviewService::class)->approve($post, $rev2->fresh(), $adminId, 4);
    $post = $post->fresh();

    expect((string) $post->publish_status)->toBe('published')
        ->and((int) $post->published_revision_id)->toBe((int) $rev1->id)
        ->and((int) $post->approved_revision_id)->toBe((int) $rev2->id);
});

it('G1-08A · 已批准 / 已排期文章开始新 draft：批准失效、排期清空、父回 draft', function (string $status) {
    $adminId = g8RelAdminId();
    $post = g8RelPost($adminId);
    $rev1 = g8RelContentDraft($post, $adminId);

    DB::table('content_revisions')->where('id', $rev1->id)->update(['review_state' => 'approved']);
    DB::table('content_posts')->where('id', $post->id)->update([
        'publish_status'       => $status,
        'approved_revision_id' => (int) $rev1->id,
        'scheduled_publish_at' => '2026-12-01 00:00:00',
        'row_version'          => 1,
    ]);

    $rev2 = app(BeginRevisionService::class)->startContentRevision(
        $post->fresh(),
        $adminId,
        1,
        g8RelContentPayload(['title' => 'G8 失效批准资讯 v2', 'change_summary' => '第二版']),
    );
    $post = $post->fresh();

    expect((string) $post->publish_status)->toBe('draft')
        ->and($post->approved_revision_id)->toBeNull()
        ->and($post->scheduled_publish_at)->toBeNull()
        ->and((int) $post->working_revision_id)->toBe((int) $rev2->id)
        ->and($post->published_revision_id)->toBeNull();
})->with(['approved', 'scheduled']);

it('G1-08A · 已发布活动已有 in_review revision（working 为空）时拒绝开始新 draft', function () {
    $adminId = g8RelAdminId();
    $organizer = g8RelOrganizer();
    $event = g8RelEvent($organizer);
    $rev1 = g8RelEventDraft($event, $organizer, $adminId);

    DB::table('event_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('events')->where('id', $event->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        'row_version'           => 5,
    ]);

    // 走真实提交：working 指针被清空，父状态保持 published，rev2 处于 in_review。
    $rev2 = g8RelEventDraft($event->fresh(), $organizer, $adminId);
    $event = app(EventReviewService::class)->submit($event->fresh(), $rev2, $adminId, 5)->fresh();

    expect((string) $event->publish_status)->toBe('published')
        ->and($event->working_revision_id)->toBeNull()
        ->and((string) $rev2->fresh()->review_state)->toBe('in_review');

    expect(fn () => app(BeginRevisionService::class)->startEventRevision(
        $event,
        $adminId,
        (int) $event->row_version,
        g8RelEventPayload($organizer, ['name' => '不应并行审核 v3', 'change_summary' => '第三版']),
    ))->toThrow(PublicationTransitionException::class);

    $event = $event->fresh();

    expect(DB::table('event_revisions')->where('event_id', $event->id)->count())->toBe(2)
        ->and((int) $event->row_version)->toBe(6)
        ->and((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->id)
        ->and((int) $event->approved_revision_id)->toBe((int) $rev1->id)
        ->and((string) $rev2->fresh()->review_state)->toBe('in_review');
});

it('G1-08A · 已发布文章已有 in_review revision（working 为空）时拒绝开始新 draft', function () {
    $adminId = g8RelAdminId();
    $post = g8RelPost($adminId);
    $rev1 = g8RelContentDraft($post, $adminId);

    DB::table('content_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('content_posts')->where('id', $post->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        'row_version'           => 2,
    ]);

    $rev2 = g8RelContentDraft($post->fresh(), $adminId, ['title' => '不应并行审核 v2']);
    $post = app(ContentReviewService::class)->submit($post->fresh(), $rev2, $adminId, 2)->fresh();

    expect((string) $post->publish_status)->toBe('published')
        ->and($post->working_revision_id)->toBeNull()
        ->and((string) $rev2->fresh()->review_state)->toBe('in_review');

    expect(fn () => app(BeginRevisionService::class)->startContentRevision(
        $post,
        $adminId,
        (int) $post->row_version,
        g8RelContentPayload(['title' => '不应并行审核 v3', 'change_summary' => '第三版']),
    ))->toThrow(PublicationTransitionException::class);

    $post = $post->fresh();

    expect(DB::table('content_revisions')->where('post_id', $post->id)->count())->toBe(2)
        ->and((int) $post->row_version)->toBe(3)
        ->and((string) $post->publish_status)->toBe('published')
        ->and((int) $post->published_revision_id)->toBe((int) $rev1->id)
        ->and((int) $post->approved_revision_id)->toBe((int) $rev1->id)
        ->and((string) $rev2->fresh()->review_state)->toBe('in_review');
});

it('G1-08A · 已发布活动开始新 draft：公开指针与状态不变，旧批准与排期失效', function () {
    $adminId = g8RelAdminId();
    $organizer = g8RelOrganizer();
    $event = g8RelEvent($organizer);
    $rev1 = g8RelEventDraft($event, $organizer, $adminId);

    DB::table('event_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('events')->where('id', $event->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        // 夹具模拟 G1-08B 的排期写入；G1-08A 自身只清空、不写非空值。
        'scheduled_publish_at'  => '2026-12-01 00:00:00',
        'row_version'           => 5,
    ]);

    $rev2 = app(BeginRevisionService::class)->startEventRevision(
        $event->fresh(),
        $adminId,
        5,
        g8RelEventPayload($organizer, ['name' => 'G8 候选失效 v2', 'change_summary' => '第二版']),
    );
    $event = $event->fresh();

    expect((string) $event->publish_status)->toBe('published')
        ->and((int) $event->published_revision_id)->toBe((int) $rev1->id)
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and((int) $event->working_revision_id)->toBe((int) $rev2->id)
        ->and((int) $event->row_version)->toBe(6)
        ->and((string) $rev2->review_state)->toBe('draft');
});

it('G1-08A · 已发布文章开始新 draft：公开指针与状态不变，旧批准与排期失效', function () {
    $adminId = g8RelAdminId();
    $post = g8RelPost($adminId);
    $rev1 = g8RelContentDraft($post, $adminId);

    DB::table('content_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('content_posts')->where('id', $post->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        // 夹具模拟 G1-08B 的排期写入；G1-08A 自身只清空、不写非空值。
        'scheduled_publish_at'  => '2026-12-01 00:00:00',
        'row_version'           => 2,
    ]);

    $rev2 = app(BeginRevisionService::class)->startContentRevision(
        $post->fresh(),
        $adminId,
        2,
        g8RelContentPayload(['title' => 'G8 候选失效资讯 v2', 'change_summary' => '第二版']),
    );
    $post = $post->fresh();

    expect((string) $post->publish_status)->toBe('published')
        ->and((int) $post->published_revision_id)->toBe((int) $rev1->id)
        ->and($post->approved_revision_id)->toBeNull()
        ->and($post->scheduled_publish_at)->toBeNull()
        ->and((int) $post->working_revision_id)->toBe((int) $rev2->id)
        ->and((int) $post->row_version)->toBe(3)
        ->and((string) $rev2->review_state)->toBe('draft');
});
