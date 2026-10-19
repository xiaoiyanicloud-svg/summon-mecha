<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EventRevision;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * G1 Admin 发布工作台 · 真人与真实服务链路。
 *
 * 这些用例通过 HTTP 路由调用控制器，控制器再调用真实的审核 / 发布 / 主办方服务，
 * 覆盖：
 *   · 活动元数据 → 创建 revision → 提交 → 批准 → 排期/取消排期 → 发布 →
 *     暂缓 → 恢复 → 撤稿；
 *   · 资讯同构关键路径（B-04/B-07 未签署时 publish 被拒绝；测试内显式打开
 *     仅用于验证状态机接线，不改变仓库默认配置）；
 *   · 并发：stale row_version / 错误 expected_revision_id 提示“已被他人修改”，
 *     且不改变库；
 *   · 预览 no-store / noindex，且不泄露私有证据、storage_key、审核备注；
 *   · B-01/B-03 未签署时外部活动仍不能提交（新增的 DTO 外链字段不构成放行）。
 *
 * 运行必须指向独立测试库 summon_mecha_step10_test。
 */

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toBe('summon_mecha_step10_test');
});

function ecoFlowSuperAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'all',
        'permissions'     => [],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ecoFlowOrganizer(array $overrides = []): int
{
    $now = '2026-10-19 04:00:00';

    return (int) DB::table('ecosystem_organizers')->insertGetId(array_merge([
        'name'                 => '召唤机甲',
        'platform_role'        => 'organizer',
        'source_url'           => 'https://organizer.example/about',
        'public_materials'     => '公开资料',
        'private_evidence_ref' => 'PRIVATE-ORG-EVIDENCE-REF',
        'status'               => 'verified',
        'verified_by'          => 1,
        'verified_at'          => $now,
        'created_at'           => $now,
        'updated_at'           => $now,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ecoFlowEvent(int $organizerId, array $overrides = []): int
{
    $now = '2026-10-19 04:00:00';

    return (int) DB::table('events')->insertGetId(array_merge([
        'name'               => 'G1 后台活动',
        'type'               => '旧类型',
        'status'             => 'registering',
        'city'               => '上海',
        'hot'                => 0,
        'teams'              => 0,
        'kind'               => 'salon',
        'participation_mode' => 'offline',
        'publish_status'     => 'draft',
        'disposition'        => 'normal',
        'hosting_status'     => 'scheduled',
        'timezone'           => 'Asia/Shanghai',
        'source_type'        => 'platform',
        'platform_role'      => 'organizer',
        'organizer_id'       => $organizerId,
        'row_version'        => 0,
        'created_at'         => $now,
        'updated_at'         => $now,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ecoFlowEventRevisionPayload(int $rowVersion, array $overrides = []): array
{
    return array_merge([
        'expected_row_version'       => $rowVersion,
        'name'                       => 'G1 活动公开名称',
        'summary'                    => '公开活动摘要',
        'detail_html'                => '<p>活动正文</p><script>alert(1)</script>',
        'city'                       => '上海',
        'location_text'              => '张江科学会堂',
        'participation_mode'         => 'offline',
        'timezone'                   => 'Asia/Shanghai',
        'starts_at'                  => '2026-11-01T09:00',
        'ends_at'                    => '2026-11-01T18:00',
        'source_name'                => '官方来源',
        'source_url'                 => 'https://example.com/events/1',
        'source_verified_at'         => '2026-10-18T12:00',
        'authorization_basis'        => 'self_organized',
        'authorization_evidence_ref' => 'PRIVATE-AUTH-EVIDENCE-REF',
        'external_registration_url'  => '',
        'change_summary'             => '初始版本',
    ], $overrides);
}

function ecoFlowRowVersion(int $eventId): int
{
    return (int) DB::table('events')->where('id', $eventId)->value('row_version');
}

function ecoFlowPostRowVersion(int $postId): int
{
    return (int) DB::table('content_posts')->where('id', $postId)->value('row_version');
}

it('活动：元数据 → revision → 提交 → 批准 → 排期/发布 → 暂缓/恢复/撤稿全链路', function () {
    // 仅测试进程内打开发布开关，验证状态机接线；仓库默认值仍为 false。
    config(['ecosystem_publishing.event_publish_enabled' => true]);

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();
    $event = ecoFlowEvent($organizer);

    // ── 1. 元数据 ─────────────────────────────────────────────
    post(route('admin.catalog.ecosystem.events.metadata', $event), [
        'expected_row_version' => 0,
        'kind'                 => 'salon',
        'participation_mode'   => 'offline',
        'hosting_status'       => 'scheduled',
        'timezone'             => 'Asia/Shanghai',
        'starts_at'            => '2026-11-01T09:00',
        'ends_at'              => '2026-11-01T18:00',
        'source_type'          => 'platform',
        'platform_role'        => 'organizer',
        'organizer_id'         => $organizer,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    $row = DB::table('events')->where('id', $event)->first();
    expect($row->kind)->toBe('salon');
    expect($row->timezone)->toBe('Asia/Shanghai');
    expect((int) $row->row_version)->toBe(1);

    // ── 2. 创建 revision ─────────────────────────────────────
    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event)),
    )->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();

    expect($revision->review_state)->toBe('draft');
    expect((int) DB::table('events')->where('id', $event)->value('working_revision_id'))->toBe((int) $revision->id);
    expect((string) $revision->detail_html)->toContain('活动正文');
    expect((string) $revision->detail_html)->not->toContain('<script');

    // ── 3. 提交 ──────────────────────────────────────────────
    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect($revision->fresh()->review_state)->toBe('in_review');
    expect((string) DB::table('events')->where('id', $event)->value('publish_status'))->toBe('in_review');

    // ── 4. 批准 ──────────────────────────────────────────────
    post(route('admin.catalog.ecosystem.events.revisions.approve', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
        'review_note'          => '内部审核备注-不得出现在预览',
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect($revision->fresh()->review_state)->toBe('approved');
    expect((string) DB::table('events')->where('id', $event)->value('publish_status'))->toBe('approved');
    expect((int) DB::table('events')->where('id', $event)->value('approved_revision_id'))->toBe((int) $revision->id);

    // ── 5. 排期 / 取消排期 ───────────────────────────────────
    $scheduleAt = now()->addDay()->format('Y-m-d\TH:i');

    post(route('admin.catalog.ecosystem.events.revisions.schedule', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
        'scheduled_publish_at' => $scheduleAt,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) DB::table('events')->where('id', $event)->value('publish_status'))->toBe('scheduled');
    expect(DB::table('events')->where('id', $event)->value('scheduled_publish_at'))->not->toBeNull();

    post(route('admin.catalog.ecosystem.events.unschedule', $event), [
        'expected_row_version' => ecoFlowRowVersion($event),
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) DB::table('events')->where('id', $event)->value('publish_status'))->toBe('approved');
    expect(DB::table('events')->where('id', $event)->value('scheduled_publish_at'))->toBeNull();

    // ── 6. 立即发布 ──────────────────────────────────────────
    post(route('admin.catalog.ecosystem.events.revisions.publish', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    $published = DB::table('events')->where('id', $event)->first();
    expect((string) $published->publish_status)->toBe('published');
    expect((int) $published->published_revision_id)->toBe((int) $revision->id);
    expect($published->published_at)->not->toBeNull();

    // ── 7. 暂缓 / 恢复 ───────────────────────────────────────
    post(route('admin.catalog.ecosystem.events.withhold', $event), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'reason'               => '运营暂缓测试',
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) DB::table('events')->where('id', $event)->value('disposition'))->toBe('withheld');

    post(route('admin.catalog.ecosystem.events.restore', $event), [
        'expected_row_version' => ecoFlowRowVersion($event),
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) DB::table('events')->where('id', $event)->value('disposition'))->toBe('normal');

    // ── 8. 撤稿（终态）────────────────────────────────────────
    post(route('admin.catalog.ecosystem.events.retract', $event), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'reason'               => '撤稿测试',
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) DB::table('events')->where('id', $event)->value('disposition'))->toBe('retracted');

    $versionAfterRetract = ecoFlowRowVersion($event);

    post(route('admin.catalog.ecosystem.events.revisions.publish', [$event, $revision->id]), [
        'expected_row_version' => $versionAfterRetract,
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event))
        ->assertSessionHasErrors('action');

    expect((string) DB::table('events')->where('id', $event)->value('disposition'))->toBe('retracted');
    expect(ecoFlowRowVersion($event))->toBe($versionAfterRetract);

    post(route('admin.catalog.ecosystem.events.restore', $event), [
        'expected_row_version' => $versionAfterRetract,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event))
        ->assertSessionHasErrors('action');
});

it('活动预览：no-store / noindex，且不泄露私有证据 / storage_key / 审核备注', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();
    $event = ecoFlowEvent($organizer);

    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event)),
    )->assertRedirect();

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();

    // 审核备注写进 revision。
    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect();

    post(route('admin.catalog.ecosystem.events.revisions.approve', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
        'review_note'          => '内部审核备注-不得出现在预览',
    ])->assertRedirect();

    // 附加一条真实媒体，确认预览不输出 storage_key。
    $mediaId = (int) DB::table('ecosystem_media')->insertGetId([
        'disk'          => 'local',
        'storage_key'   => 'g1-admin/private-secret.png',
        'original_name' => 'private-secret.png',
        'mime'          => 'image/png',
        'byte_size'     => 3,
        'sha256'        => hash('sha256', 'abc'),
        'status'        => 'approved',
        'uploaded_by'   => 1,
        'created_at'    => '2026-10-19 04:00:00',
        'updated_at'    => '2026-10-19 04:00:00',
    ]);

    DB::table('ecosystem_media_refs')->insert([
        'media_id'      => $mediaId,
        'owner_type'    => 'event_revision',
        'owner_id'      => $revision->id,
        'role'          => 'cover',
        'sort_order'    => 0,
        'alt_text'      => '现场图',
        'review_status' => 'approved',
        'created_at'    => '2026-10-19 04:00:00',
    ]);

    $response = get(route('admin.catalog.ecosystem.events.revisions.preview', [$event, $revision->id]))
        ->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    expect((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');

    $body = (string) $response->getContent();

    expect($body)->toContain('活动正文');
    expect($body)->not->toContain('PRIVATE-AUTH-EVIDENCE-REF');
    expect($body)->not->toContain('g1-admin/private-secret.png');
    expect($body)->not->toContain('内部审核备注-不得出现在预览');
});

it('并发：stale row_version / 错误 expected_revision_id 提示已被他人修改且不改库', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();
    $event = ecoFlowEvent($organizer);

    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event)),
    )->assertRedirect();

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();
    $staleRowVersion = ecoFlowRowVersion($event);

    // 另一位管理员先改了活动行。
    DB::table('events')->where('id', $event)->increment('row_version', 1);

    post(route('admin.catalog.ecosystem.events.metadata', $event), [
        'expected_row_version' => $staleRowVersion,
        'kind'                 => 'expo',
        'participation_mode'   => 'offline',
        'hosting_status'       => 'scheduled',
        'timezone'             => 'Asia/Shanghai',
        'starts_at'            => '2026-11-01T09:00',
        'ends_at'              => '2026-11-01T18:00',
        'source_type'          => 'platform',
        'platform_role'        => 'organizer',
        'organizer_id'         => $organizer,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event))
        ->assertSessionHasErrors('action');

    expect((string) DB::table('events')->where('id', $event)->value('kind'))->toBe('salon');

    // 页面上的 revision 已被取代（expected_revision_id 不匹配）。
    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id + 1,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event))
        ->assertSessionHasErrors('action');

    expect($revision->fresh()->review_state)->toBe('draft');
});

it('B-01/B-03 未签署：外部活动仍不能提交审核', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer(['platform_role' => 'directory_only', 'status' => 'pending']);

    $event = ecoFlowEvent($organizer, [
        'source_type'   => 'external',
        'platform_role' => 'directory_only',
    ]);

    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event), [
            'participation_mode' => 'online',
        ]),
    )->assertRedirect();

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();

    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event))
        ->assertSessionHasErrors('action');

    expect($revision->fresh()->review_state)->toBe('draft');
    expect((string) DB::table('events')->where('id', $event)->value('publish_status'))->toBe('draft');
});

it('资讯：创建 → revision → 提交 → 批准 → 排期/发布 → 暂缓/恢复/撤稿全链路', function () {
    config([
        'ecosystem_publishing.content_publish_enabled' => true,
        'ecosystem_publishing.b04_content_slug_signed' => true,
        'ecosystem_publishing.b07_content_legal_signed' => true,
    ]);

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $slug = 'g1-admin-flow-'.bin2hex(random_bytes(4));

    // ── 1. 新建文章壳 ─────────────────────────────────────────
    post(route('admin.catalog.ecosystem.content.store'), [
        'type'     => 'news',
        'slug'     => $slug,
        'category' => 'robotics',
    ])->assertRedirect();

    $post = ContentPost::query()->where('slug', $slug)->firstOrFail();

    // ── 2. 创建 revision ──────────────────────────────────────
    post(route('admin.catalog.ecosystem.content.revisions.store', $post->id), [
        'expected_row_version'  => ecoFlowPostRowVersion($post->id),
        'title'                 => 'G1 资讯标题',
        'summary'               => '公开资讯摘要',
        'body_html'             => '<p>资讯正文</p><script>alert(1)</script>',
        'author_name'           => '张三',
        'author_org'            => '召唤机甲编辑部',
        'source_type'           => 'original',
        'original_published_at' => '2026-10-18T08:00',
        'correction_note'       => '',
        'change_summary'        => '初始版本',
        'source_name'           => '官方原文',
        'source_url'            => 'https://example.com/news/1',
        'source_verified_at'    => '2026-10-18T12:00',
        'rights_basis'          => 'platform_original',
        'rights_scope'          => '全文',
        'evidence_ref'          => 'PRIVATE-CONTENT-EVIDENCE-REF',
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    $revision = ContentRevision::query()->where('post_id', $post->id)->orderByDesc('id')->firstOrFail();

    expect($revision->review_state)->toBe('draft');
    expect((string) $revision->body_html)->toContain('资讯正文');
    expect((string) $revision->body_html)->not->toContain('<script');

    // ── 3. 提交 / 批准 ───────────────────────────────────────
    post(route('admin.catalog.ecosystem.content.revisions.submit', [$post->id, $revision->id]), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    post(route('admin.catalog.ecosystem.content.revisions.approve', [$post->id, $revision->id]), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'expected_revision_id' => $revision->id,
        'review_note'          => '资讯内部审核备注',
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    expect($revision->fresh()->review_state)->toBe('approved');
    expect((string) DB::table('content_posts')->where('id', $post->id)->value('publish_status'))->toBe('approved');

    // ── 4. 排期 / 取消排期 / 发布 ────────────────────────────
    post(route('admin.catalog.ecosystem.content.revisions.schedule', [$post->id, $revision->id]), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'expected_revision_id' => $revision->id,
        'scheduled_publish_at' => now()->addDay()->format('Y-m-d\TH:i'),
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    expect((string) DB::table('content_posts')->where('id', $post->id)->value('publish_status'))->toBe('scheduled');

    post(route('admin.catalog.ecosystem.content.unschedule', $post->id), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    post(route('admin.catalog.ecosystem.content.revisions.publish', [$post->id, $revision->id]), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    $published = DB::table('content_posts')->where('id', $post->id)->first();
    expect((string) $published->publish_status)->toBe('published');
    expect((int) $published->published_revision_id)->toBe((int) $revision->id);

    // ── 5. 暂缓 / 恢复 / 撤稿 ────────────────────────────────
    post(route('admin.catalog.ecosystem.content.withhold', $post->id), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'reason'               => '资讯暂缓测试',
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    expect((string) DB::table('content_posts')->where('id', $post->id)->value('disposition'))->toBe('withheld');

    post(route('admin.catalog.ecosystem.content.restore', $post->id), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    post(route('admin.catalog.ecosystem.content.retract', $post->id), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'reason'               => '资讯撤稿测试',
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    expect((string) DB::table('content_posts')->where('id', $post->id)->value('disposition'))->toBe('retracted');
});

it('资讯默认关闭：B-04/B-07 未签署时 publish 被拒绝且不切指针', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $slug = 'g1-admin-default-off-'.bin2hex(random_bytes(4));

    post(route('admin.catalog.ecosystem.content.store'), [
        'type' => 'news',
        'slug' => $slug,
    ])->assertRedirect();

    $post = ContentPost::query()->where('slug', $slug)->firstOrFail();

    post(route('admin.catalog.ecosystem.content.revisions.store', $post->id), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'title'                => '默认关闭测试',
        'body_html'            => '<p>正文</p>',
        'author_name'          => '作者',
        'source_type'          => 'original',
        'change_summary'       => '初始版本',
        'source_name'          => '官方原文',
        'source_url'           => 'https://example.com/news/2',
        'source_verified_at'   => '2026-10-18T12:00',
        'rights_basis'         => 'platform_original',
    ])->assertRedirect();

    $revision = ContentRevision::query()->where('post_id', $post->id)->orderByDesc('id')->firstOrFail();

    post(route('admin.catalog.ecosystem.content.revisions.submit', [$post->id, $revision->id]), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect();

    post(route('admin.catalog.ecosystem.content.revisions.approve', [$post->id, $revision->id]), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect();

    $versionBefore = ecoFlowPostRowVersion($post->id);

    post(route('admin.catalog.ecosystem.content.revisions.publish', [$post->id, $revision->id]), [
        'expected_row_version' => $versionBefore,
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id))
        ->assertSessionHasErrors('action');

    expect((string) DB::table('content_posts')->where('id', $post->id)->value('publish_status'))->toBe('approved');
    expect(DB::table('content_posts')->where('id', $post->id)->value('published_revision_id'))->toBeNull();
    expect(ecoFlowPostRowVersion($post->id))->toBe($versionBefore);
});

it('资讯预览：no-store / noindex，且不泄露来源证据与审核备注', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $slug = 'g1-admin-preview-'.bin2hex(random_bytes(4));

    post(route('admin.catalog.ecosystem.content.store'), [
        'type' => 'news',
        'slug' => $slug,
    ])->assertRedirect();

    $post = ContentPost::query()->where('slug', $slug)->firstOrFail();

    post(route('admin.catalog.ecosystem.content.revisions.store', $post->id), [
        'expected_row_version' => ecoFlowPostRowVersion($post->id),
        'title'                => '预览标题',
        'body_html'            => '<p>预览正文</p>',
        'author_name'          => '作者',
        'source_type'          => 'original',
        'change_summary'       => '初始版本',
        'source_name'          => '官方原文',
        'source_url'           => 'https://example.com/news/3',
        'source_verified_at'   => '2026-10-18T12:00',
        'rights_basis'         => 'platform_original',
        'evidence_ref'         => 'PRIVATE-CONTENT-EVIDENCE-REF',
    ])->assertRedirect();

    $revision = ContentRevision::query()->where('post_id', $post->id)->orderByDesc('id')->firstOrFail();

    DB::table('content_revisions')->where('id', $revision->id)->update([
        'review_note' => '资讯内部审核备注-不得出现在预览',
    ]);

    $response = get(route('admin.catalog.ecosystem.content.revisions.preview', [$post->id, $revision->id]))
        ->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    expect((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');

    $body = (string) $response->getContent();

    expect($body)->toContain('预览正文');
    expect($body)->not->toContain('PRIVATE-CONTENT-EVIDENCE-REF');
    expect($body)->not->toContain('资讯内部审核备注-不得出现在预览');
});
