<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
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

/**
 * 1×1 PNG（真实可解码），用于走 MediaService 的真实上传校验。
 */
function ecoFlowPngUpload(string $name = 'cover.png'): UploadedFile
{
    $bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    // 同一用例内可能上传多张：追加随机尾部让 sha256 不同；PNG 解码只看头部。
    return UploadedFile::fake()->createWithContent($name, $bytes.random_bytes(8));
}

/**
 * 上传并批准一张真实 PNG，返回媒体 ID。
 */
function ecoFlowApprovedMedia(string $name = 'cover.png'): int
{
    post(route('admin.catalog.ecosystem.media.store'), [
        'file' => ecoFlowPngUpload($name),
    ])->assertRedirect(route('admin.catalog.ecosystem.media.index'));

    $media = EcosystemMedia::query()->orderByDesc('id')->firstOrFail();

    post(route('admin.catalog.ecosystem.media.approve', $media->id), [
        'expected_status' => 'pending',
    ])->assertRedirect(route('admin.catalog.ecosystem.media.index'));

    return (int) $media->id;
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
        'sources'               => [
            [
                'source_name'        => '官方原文',
                'source_url'         => 'https://example.com/news/1',
                'source_verified_at' => '2026-10-18T12:00',
                'rights_basis'       => 'platform_original',
                'rights_scope'       => '全文',
                'evidence_ref'       => 'PRIVATE-CONTENT-EVIDENCE-REF',
            ],
            [
                'source_name'        => '补充来源',
                'source_url'         => 'https://example.com/news/2',
                'source_verified_at' => '2026-10-18T12:00',
                'rights_basis'       => 'platform_original',
                'rights_scope'       => '正文',
            ],
            [
                'source_name'        => '第三来源',
                'source_url'         => 'https://example.com/news/3',
                'source_verified_at' => '2026-10-18T12:00',
                'rights_basis'       => 'platform_original',
            ],
        ],
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    $revision = ContentRevision::query()->where('post_id', $post->id)->orderByDesc('id')->firstOrFail();

    expect($revision->review_state)->toBe('draft');
    expect((string) $revision->body_html)->toContain('资讯正文');
    expect((string) $revision->body_html)->not->toContain('<script');
    expect($revision->sources()->orderBy('sort_order')->count())->toBe(3);
    expect($revision->sources()->orderBy('sort_order')->pluck('source_name')->all())
        ->toBe(['官方原文', '补充来源', '第三来源']);

    get(route('admin.catalog.ecosystem.content.show', $post->id))
        ->assertOk()
        ->assertSeeText('1~N 条来源')
        ->assertDontSeeText('当前每条 revision 只登记一条来源');

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
        'sources'              => [[
            'source_name'        => '官方原文',
            'source_url'         => 'https://example.com/news/2',
            'source_verified_at' => '2026-10-18T12:00',
            'rights_basis'       => 'platform_original',
        ]],
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
        'sources'              => [[
            'source_name'        => '官方原文',
            'source_url'         => 'https://example.com/news/3',
            'source_verified_at' => '2026-10-18T12:00',
            'rights_basis'       => 'platform_original',
            'evidence_ref'       => 'PRIVATE-CONTENT-EVIDENCE-REF',
        ]],
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

it('活动新建入口：草稿默认值 / 来源三字段 / 审计 / 不能直接发布', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();

    get(route('admin.catalog.ecosystem.events.create'))
        ->assertOk()
        ->assertSeeText('新建活动草稿');

    $name = 'G1 新建活动 '.bin2hex(random_bytes(3));

    post(route('admin.catalog.ecosystem.events.store'), [
        'name'               => $name,
        'kind'               => 'salon',
        'participation_mode' => 'offline',
        'hosting_status'     => 'scheduled',
        'timezone'           => 'Asia/Shanghai',
        'starts_at'          => '2026-11-01T09:00',
        'ends_at'            => '2026-11-01T18:00',
        'source_type'        => 'platform',
        'platform_role'      => 'organizer',
        'organizer_id'       => $organizer,
    ])->assertRedirect();

    $event = Event::query()->where('name', $name)->firstOrFail();

    expect((string) $event->publish_status)->toBe('draft')
        ->and((string) $event->disposition)->toBe('normal')
        ->and((int) $event->row_version)->toBe(0)
        ->and($event->published_revision_id)->toBeNull()
        ->and($event->working_revision_id)->toBeNull()
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and((int) $event->organizer_id)->toBe($organizer)
        ->and((string) $event->source_type)->toBe('platform')
        ->and((string) $event->platform_role)->toBe('organizer')
        ->and((string) $event->kind)->toBe('salon')
        ->and((string) $event->type)->toBe('生态活动')
        ->and((string) $event->status)->toBe('upcoming')
        ->and((int) $event->hot)->toBe(0)
        ->and((int) $event->teams)->toBe(0)
        ->and((string) $event->organizer)->toBe('召唤机甲');

    // 草稿壳没有 revision，时间按活动时区写入并转 UTC。
    expect(EventRevision::query()->where('event_id', $event->id)->count())->toBe(0);
    expect($event->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 01:00:00');

    expect(DB::table('operation_log')
        ->where('action', 'ecosystem.event.create')
        ->where('target_type', 'event')
        ->where('target_id', $event->id)
        ->exists())->toBeTrue();

    // 来源三字段不完整：控制器/服务拒绝，不落库。
    $before = Event::query()->count();

    post(route('admin.catalog.ecosystem.events.store'), [
        'name'           => '无来源活动',
        'kind'           => 'salon',
        'hosting_status' => 'scheduled',
        'timezone'       => 'Asia/Shanghai',
        'source_type'    => 'platform',
        'platform_role'  => 'organizer',
    ])->assertSessionHasErrors('organizer_id');

    expect(Event::query()->count())->toBe($before);
});

it('资讯元数据编辑：expected_row_version CAS 与首次发布后 slug 冻结错误可读', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $slug = 'g1-meta-'.bin2hex(random_bytes(4));

    post(route('admin.catalog.ecosystem.content.store'), [
        'type'     => 'news',
        'slug'     => $slug,
        'category' => 'old',
    ])->assertRedirect();

    $post = ContentPost::query()->where('slug', $slug)->firstOrFail();

    post(route('admin.catalog.ecosystem.content.metadata', $post->id), [
        'expected_row_version' => 0,
        'type'                 => 'case',
        'slug'                 => $slug.'-v2',
        'category'             => 'new',
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    $post->refresh();

    expect((string) $post->type)->toBe('case')
        ->and((string) $post->slug)->toBe($slug.'-v2')
        ->and((string) $post->category)->toBe('new')
        ->and((int) $post->row_version)->toBe(1);

    // stale row_version：提示冲突、不覆盖。
    post(route('admin.catalog.ecosystem.content.metadata', $post->id), [
        'expected_row_version' => 0,
        'type'                 => 'announcement',
        'slug'                 => $slug.'-v3',
        'category'             => 'stale',
    ])->assertSessionHasErrors('action');

    expect((string) $post->fresh()->type)->toBe('case');

    // 首次发布后 slug 冻结：必须给出可读原因，不能静默或泛化。
    DB::table('content_posts')->where('id', $post->id)->update(['first_published_at' => now()]);

    $post->refresh();
    $version = (int) $post->row_version;

    post(route('admin.catalog.ecosystem.content.metadata', $post->id), [
        'expected_row_version' => $version,
        'type'                 => 'case',
        'slug'                 => $slug.'-renamed',
        'category'             => 'new',
    ])->assertSessionHasErrors('action');

    expect((string) session('errors')->first('action'))->toContain('slug')
        ->and((string) $post->fresh()->slug)->toBe($slug.'-v2');
});

it('媒体工作台：真实上传 / 审核 / 拒绝 / 隔离，页面不泄露 storage_key', function () {
    Storage::fake('local');

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    post(route('admin.catalog.ecosystem.media.store'), [
        'file' => ecoFlowPngUpload('cover.png'),
    ])->assertRedirect(route('admin.catalog.ecosystem.media.index'));

    $approved = EcosystemMedia::query()->orderByDesc('id')->firstOrFail();

    expect((string) $approved->status)->toBe('pending')
        ->and((string) $approved->mime)->toBe('image/png')
        ->and((int) $approved->byte_size)->toBeGreaterThan(0)
        ->and((int) $approved->width)->toBe(1)
        ->and((int) $approved->height)->toBe(1);

    get(route('admin.catalog.ecosystem.media.index'))
        ->assertOk()
        ->assertSeeText('image/png')
        ->assertDontSee($approved->storage_key);

    post(route('admin.catalog.ecosystem.media.approve', $approved->id), [
        'expected_status' => 'pending',
    ])->assertRedirect(route('admin.catalog.ecosystem.media.index'));

    expect((string) $approved->fresh()->status)->toBe('approved');

    // stale expected_status：不改库。
    post(route('admin.catalog.ecosystem.media.approve', $approved->id), [
        'expected_status' => 'pending',
    ])->assertSessionHasErrors('action');

    expect((string) $approved->fresh()->status)->toBe('approved');

    post(route('admin.catalog.ecosystem.media.store'), [
        'file' => ecoFlowPngUpload('rejected.png'),
    ])->assertRedirect();

    $rejected = EcosystemMedia::query()->orderByDesc('id')->firstOrFail();

    post(route('admin.catalog.ecosystem.media.reject', $rejected->id), [
        'expected_status' => 'pending',
    ])->assertRedirect();

    expect((string) $rejected->fresh()->status)->toBe('rejected');

    post(route('admin.catalog.ecosystem.media.store'), [
        'file' => ecoFlowPngUpload('quarantined.png'),
    ])->assertRedirect();

    $quarantined = EcosystemMedia::query()->orderByDesc('id')->firstOrFail();

    post(route('admin.catalog.ecosystem.media.quarantine', $quarantined->id), [
        'expected_status' => 'pending',
    ])->assertRedirect();

    expect((string) $quarantined->fresh()->status)->toBe('quarantined');

    // 伪装成 PNG 的 PHP：真实 MIME 校验拒绝，不落库。
    $countBefore = EcosystemMedia::query()->count();

    post(route('admin.catalog.ecosystem.media.store'), [
        'file' => UploadedFile::fake()->createWithContent('evil.png', '<?php echo 1; ?>'),
    ])->assertSessionHasErrors('action');

    expect(EcosystemMedia::query()->count())->toBe($countBefore);
});

it('revision 媒体引用：未审核不可提审；审核后可提审；提交后不可再改', function () {
    Storage::fake('local');

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();
    $event = ecoFlowEvent($organizer);

    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event)),
    )->assertRedirect();

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();
    $fingerprintBefore = (string) $revision->content_fingerprint;

    $mediaId = ecoFlowApprovedMedia('ref-cover.png');

    $refsRoute = route('admin.catalog.ecosystem.events.revisions.media_refs.store', [$event, $revision->id]);

    post($refsRoute, [
        'refs' => [[
            'media_id'   => $mediaId,
            'role'       => 'cover',
            'alt_text'   => '活动现场',
            'sort_order' => 99,
        ]],
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    $ref = DB::table('ecosystem_media_refs')
        ->where('owner_type', 'event_revision')
        ->where('owner_id', $revision->id)
        ->first();

    expect((string) $ref->review_status)->toBe('pending')
        ->and((int) $ref->sort_order)->toBe(0)
        ->and((string) $ref->alt_text)->toBe('活动现场');

    $revision->refresh();

    expect($revision->media_manifest_hash)->not->toBeNull()
        ->and((string) $revision->content_fingerprint)->not->toBe($fingerprintBefore);

    // 引用未批准：提交被前置校验拒绝。
    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertSessionHasErrors('action');

    expect((string) $revision->fresh()->review_state)->toBe('draft');

    // 审批引用后，提交通过。
    post(route('admin.catalog.ecosystem.events.revisions.media_refs.review', [
        $event,
        $revision->id,
        $ref->id,
    ]), [
        'expected_review_status' => 'pending',
        'status'                 => 'approved',
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) DB::table('ecosystem_media_refs')->where('id', $ref->id)->value('review_status'))->toBe('approved');

    // stale expected_review_status：不覆盖已批准的引用。
    post(route('admin.catalog.ecosystem.events.revisions.media_refs.review', [
        $event,
        $revision->id,
        $ref->id,
    ]), [
        'expected_review_status' => 'pending',
        'status'                 => 'approved',
    ])->assertSessionHasErrors('action');

    expect((string) DB::table('ecosystem_media_refs')->where('id', $ref->id)->value('review_status'))->toBe('approved');

    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    expect((string) $revision->fresh()->review_state)->toBe('in_review');

    // 提交后引用冻结：不能“只换媒体”绕过重新审核。
    post($refsRoute, ['refs' => []])->assertSessionHasErrors('action');

    expect(DB::table('ecosystem_media_refs')
        ->where('owner_type', 'event_revision')
        ->where('owner_id', $revision->id)
        ->count())->toBe(1);
});

it('媒体变化后旧批准不继承：隔离已批准媒体后发布被拒绝', function () {
    Storage::fake('local');

    config(['ecosystem_publishing.event_publish_enabled' => true]);

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();
    $event = ecoFlowEvent($organizer);

    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event)),
    )->assertRedirect();

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();
    $mediaId = ecoFlowApprovedMedia('publish-cover.png');

    post(route('admin.catalog.ecosystem.events.revisions.media_refs.store', [$event, $revision->id]), [
        'refs' => [['media_id' => $mediaId, 'role' => 'cover']],
    ])->assertRedirect();

    $refId = (int) DB::table('ecosystem_media_refs')
        ->where('owner_type', 'event_revision')
        ->where('owner_id', $revision->id)
        ->value('id');

    post(route('admin.catalog.ecosystem.events.revisions.media_refs.review', [$event, $revision->id, $refId]), [
        'expected_review_status' => 'pending',
        'status'                 => 'approved',
    ])->assertRedirect();

    post(route('admin.catalog.ecosystem.events.revisions.submit', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect();

    post(route('admin.catalog.ecosystem.events.revisions.approve', [$event, $revision->id]), [
        'expected_row_version' => ecoFlowRowVersion($event),
        'expected_revision_id' => $revision->id,
    ])->assertRedirect();

    expect((string) $revision->fresh()->review_state)->toBe('approved');

    // 已批准媒体的状态变化必须让旧批准失效。
    post(route('admin.catalog.ecosystem.media.quarantine', $mediaId), [
        'expected_status' => 'approved',
    ])->assertRedirect();

    $version = ecoFlowRowVersion($event);

    post(route('admin.catalog.ecosystem.events.revisions.publish', [$event, $revision->id]), [
        'expected_row_version' => $version,
        'expected_revision_id' => $revision->id,
    ])->assertSessionHasErrors('action');

    $row = DB::table('events')->where('id', $event)->first();

    expect((string) $row->publish_status)->toBe('approved')
        ->and($row->published_revision_id)->toBeNull()
        ->and((int) $row->row_version)->toBe($version);
});

it('主办方驳回 / 暂停：expected_status CAS + 必填原因', function () {
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $name = 'G1 驳回主办方 '.bin2hex(random_bytes(3));

    post(route('admin.catalog.ecosystem.organizers.store'), [
        'name'             => $name,
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/org',
        'public_materials' => '公开资料',
    ])->assertRedirect(route('admin.catalog.ecosystem.organizers.index'));

    $organizer = EcosystemOrganizer::query()->where('name', $name)->firstOrFail();

    // 原因必填。
    post(route('admin.catalog.ecosystem.organizers.reject', $organizer->id), [
        'expected_status' => 'pending',
    ])->assertSessionHasErrors('reason');

    expect((string) $organizer->fresh()->status)->toBe('pending');

    post(route('admin.catalog.ecosystem.organizers.reject', $organizer->id), [
        'expected_status' => 'pending',
        'reason'          => '资料不足',
    ])->assertRedirect(route('admin.catalog.ecosystem.organizers.index'));

    expect((string) $organizer->fresh()->status)->toBe('rejected')
        ->and((string) $organizer->fresh()->verification_note)->toBe('资料不足');

    // stale expected_status：不覆盖别人刚做的核验结论。
    post(route('admin.catalog.ecosystem.organizers.reject', $organizer->id), [
        'expected_status' => 'pending',
        'reason'          => '再次驳回',
    ])->assertSessionHasErrors('action');

    expect((string) $organizer->fresh()->status)->toBe('rejected');

    post(route('admin.catalog.ecosystem.organizers.verify', $organizer->id), [
        'expected_status'   => 'rejected',
        'verification_note' => '资料已补齐',
    ])->assertRedirect(route('admin.catalog.ecosystem.organizers.index'));

    expect((string) $organizer->fresh()->status)->toBe('verified');

    post(route('admin.catalog.ecosystem.organizers.suspend', $organizer->id), [
        'expected_status' => 'verified',
        'reason'          => '临时暂停',
    ])->assertRedirect(route('admin.catalog.ecosystem.organizers.index'));

    expect((string) $organizer->fresh()->status)->toBe('suspended');

    post(route('admin.catalog.ecosystem.organizers.suspend', $organizer->id), [
        'expected_status' => 'verified',
        'reason'          => '再次暂停',
    ])->assertSessionHasErrors('action');

    expect((string) $organizer->fresh()->status)->toBe('suspended');
});

it('活动草稿二次编辑：重算指纹、保留媒体引用；stale 指纹与已离开 draft 一律拒绝', function () {
    Storage::fake('local');

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $organizer = ecoFlowOrganizer();
    $event = ecoFlowEvent($organizer);

    post(
        route('admin.catalog.ecosystem.events.revisions.store', $event),
        ecoFlowEventRevisionPayload(ecoFlowRowVersion($event)),
    )->assertRedirect();

    $revision = EventRevision::query()->where('event_id', $event)->orderByDesc('id')->firstOrFail();

    // 先挂一条媒体引用，验证编辑正文不会清空 media_manifest_hash。
    $mediaId = ecoFlowApprovedMedia('draft-edit-cover.png');

    post(route('admin.catalog.ecosystem.events.revisions.media_refs.store', [$event, $revision->id]), [
        'refs' => [[
            'media_id' => $mediaId,
            'role'     => 'cover',
            'alt_text' => '草稿封面',
        ]],
    ])->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    $revision->refresh();
    $manifestBefore = (string) $revision->media_manifest_hash;
    $fingerprintBefore = (string) $revision->content_fingerprint;

    expect($manifestBefore)->not->toBe('')->and($fingerprintBefore)->toHaveLength(64);

    $updateRoute = route('admin.catalog.ecosystem.events.revisions.update', [$event, $revision->id]);

    $payload = fn (string $fingerprint, array $overrides = []): array => array_merge([
        'expected_fingerprint' => $fingerprint,
        'name'                 => '二次编辑后的活动名称',
        'summary'              => '二次编辑后的摘要',
        'detail_html'          => '<p>二次编辑后的正文</p>',
        'city'                 => '北京',
        'location_text'        => '国家会议中心',
        'participation_mode'   => 'hybrid',
        'timezone'             => 'Asia/Shanghai',
        'starts_at'            => '2026-11-02T09:00',
        'ends_at'              => '2026-11-02T18:00',
        'source_name'          => '二次编辑后的来源',
        'source_url'           => 'https://example.com/events/2',
        'source_verified_at'   => '2026-10-19T10:00',
        'authorization_basis'  => 'self_organized',
        'change_summary'       => '二次编辑：调整时间与地点',
    ], $overrides);

    post($updateRoute, $payload($fingerprintBefore))
        ->assertRedirect(route('admin.catalog.ecosystem.events.show', $event));

    $revision->refresh();

    expect((string) $revision->name)->toBe('二次编辑后的活动名称')
        ->and((string) $revision->city)->toBe('北京')
        ->and((string) $revision->detail_html)->toContain('二次编辑后的正文')
        ->and((string) $revision->content_fingerprint)->not->toBe($fingerprintBefore)
        ->and((string) $revision->content_fingerprint)->toHaveLength(64)
        // 媒体集合指纹由 MediaRefService 维护，编辑正文不得清空。
        ->and((string) $revision->media_manifest_hash)->toBe($manifestBefore);

    expect(DB::table('ecosystem_media_refs')
        ->where('owner_type', 'event_revision')
        ->where('owner_id', $revision->id)
        ->count())->toBe(1);

    // 页面在最新 draft 上展示编辑表单并预填。
    get(route('admin.catalog.ecosystem.events.show', $event))
        ->assertOk()
        ->assertSeeText('编辑草稿（最新 draft）')
        ->assertSee('二次编辑后的活动名称')
        ->assertSee($updateRoute);

    $fingerprintAfterEdit = (string) $revision->content_fingerprint;

    // stale 指纹：明确提示且不改库（不得静默覆盖）。
    post($updateRoute, $payload($fingerprintBefore, ['name' => '不应写入的名称']))
        ->assertSessionHasErrors(['action' => '内容已被他人修改，请刷新后重试；本次编辑未保存。']);

    $revision->refresh();

    expect((string) $revision->name)->toBe('二次编辑后的活动名称')
        ->and((string) $revision->content_fingerprint)->toBe($fingerprintAfterEdit);

    // in_review / approved / rejected：页面不再展示编辑入口，直接 POST 也拒绝。
    foreach (['in_review', 'approved', 'rejected'] as $state) {
        DB::table('event_revisions')->where('id', $revision->id)->update(['review_state' => $state]);

        $before = (array) DB::table('event_revisions')->where('id', $revision->id)->first();

        post($updateRoute, $payload($fingerprintAfterEdit, ['name' => '不应写入-'.$state]))
            ->assertSessionHasErrors('action');

        expect((array) DB::table('event_revisions')->where('id', $revision->id)->first())->toBe($before);
    }

    get(route('admin.catalog.ecosystem.events.show', $event))
        ->assertOk()
        ->assertDontSee($updateRoute);
});

it('资讯草稿二次编辑：多来源整组替换并重算指纹；stale 指纹不覆盖', function () {
    Storage::fake('local');

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    $slug = 'g1-draft-edit-'.bin2hex(random_bytes(4));

    post(route('admin.catalog.ecosystem.content.store'), [
        'type'     => 'news',
        'slug'     => $slug,
        'category' => 'robotics',
    ])->assertRedirect();

    $post = ContentPost::query()->where('slug', $slug)->firstOrFail();

    post(route('admin.catalog.ecosystem.content.revisions.store', $post->id), [
        'expected_row_version'  => ecoFlowPostRowVersion($post->id),
        'title'                 => '二次编辑前的标题',
        'summary'               => '编辑前摘要',
        'body_html'             => '<p>编辑前正文</p>',
        'author_name'           => '张三',
        'author_org'            => '召唤机甲编辑部',
        'source_type'           => 'original',
        'original_published_at' => '2026-10-18T08:00',
        'change_summary'        => '初始版本',
        'sources'               => [
            [
                'source_name'        => '旧来源一',
                'source_url'         => 'https://example.com/news/1',
                'source_verified_at' => '2026-10-18T12:00',
                'rights_basis'       => 'platform_original',
                'rights_scope'       => '全文',
            ],
            [
                'source_name'        => '旧来源二',
                'source_url'         => 'https://example.com/news/2',
                'source_verified_at' => '2026-10-18T12:00',
                'rights_basis'       => 'platform_original',
            ],
        ],
    ])->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    $revision = ContentRevision::query()->where('post_id', $post->id)->orderByDesc('id')->firstOrFail();
    $fingerprintBefore = (string) $revision->content_fingerprint;

    expect($revision->sources()->count())->toBe(2);

    $updateRoute = route('admin.catalog.ecosystem.content.revisions.update', [$post->id, $revision->id]);

    $payload = fn (string $fingerprint, array $overrides = []): array => array_merge([
        'expected_fingerprint'  => $fingerprint,
        'title'                 => '二次编辑后的标题',
        'summary'               => '编辑后摘要',
        'body_html'             => '<p>编辑后正文</p><script>alert(1)</script>',
        'author_name'           => '李四',
        'author_org'            => '召唤机甲编辑部',
        'source_type'           => 'original',
        'original_published_at' => '2026-10-17T09:00',
        'correction_note'       => '补充了第二段信息',
        'change_summary'        => '二次编辑：整组替换来源',
        'sources'               => [
            [
                'source_name'        => '新来源一',
                'source_url'         => 'https://example.com/news/11',
                'source_verified_at' => '2026-10-18T12:00',
                'rights_basis'       => 'platform_original',
                'rights_scope'       => '全文',
            ],
            [
                'source_name'        => '新来源二',
                'source_url'         => 'https://example.com/news/12',
                'source_verified_at' => '2026-10-18T13:00',
                'rights_basis'       => 'platform_original',
                'rights_scope'       => '正文',
            ],
            [
                'source_name'        => '新来源三',
                'source_url'         => 'https://example.com/news/13',
                'source_verified_at' => '2026-10-18T14:00',
                'rights_basis'       => 'platform_original',
            ],
        ],
    ], $overrides);

    post($updateRoute, $payload($fingerprintBefore))
        ->assertRedirect(route('admin.catalog.ecosystem.content.show', $post->id));

    $revision->refresh();

    expect((string) $revision->title)->toBe('二次编辑后的标题')
        ->and((string) $revision->author_name)->toBe('李四')
        ->and((string) $revision->body_html)->toContain('编辑后正文')
        ->and((string) $revision->body_html)->not->toContain('<script')
        ->and((string) $revision->content_fingerprint)->not->toBe($fingerprintBefore);

    $sources = $revision->sources()->orderBy('sort_order')->get();

    expect($sources)->toHaveCount(3)
        ->and($sources->pluck('source_name')->all())->toBe(['新来源一', '新来源二', '新来源三'])
        ->and($sources->pluck('sort_order')->map(fn ($value): int => (int) $value)->all())->toBe([0, 1, 2]);

    $fingerprintAfterEdit = (string) $revision->content_fingerprint;
    $sourcesAfterEdit = $revision->sources()->orderBy('sort_order')->get(['id', 'source_name', 'sort_order'])->toArray();

    // 页面在最新 draft 上展示编辑表单并预填（含多来源行）。
    get(route('admin.catalog.ecosystem.content.show', $post->id))
        ->assertOk()
        ->assertSeeText('编辑草稿（最新 draft）')
        ->assertSee('二次编辑后的标题')
        ->assertSee('新来源三')
        ->assertSee($updateRoute);

    // stale 指纹：明确提示且不改库。
    post($updateRoute, $payload($fingerprintBefore, [
        'title'   => '不应写入的标题',
        'sources' => [[
            'source_name'        => '不应写入的来源',
            'source_url'         => 'https://example.com/news/99',
            'source_verified_at' => '2026-10-18T12:00',
            'rights_basis'       => 'platform_original',
        ]],
    ]))->assertSessionHasErrors(['action' => '内容已被他人修改，请刷新后重试；本次编辑未保存。']);

    $revision->refresh();

    expect((string) $revision->title)->toBe('二次编辑后的标题')
        ->and((string) $revision->content_fingerprint)->toBe($fingerprintAfterEdit)
        ->and($revision->sources()->orderBy('sort_order')->get(['id', 'source_name', 'sort_order'])->toArray())
        ->toBe($sourcesAfterEdit);

    // 离开 draft 后直接 POST 被拒绝，且不改库。
    DB::table('content_revisions')->where('id', $revision->id)->update(['review_state' => 'in_review']);

    $before = (array) DB::table('content_revisions')->where('id', $revision->id)->first();

    post($updateRoute, $payload($fingerprintAfterEdit, ['title' => '不应写入的标题']))
        ->assertSessionHasErrors('action');

    expect((array) DB::table('content_revisions')->where('id', $revision->id)->first())->toBe($before)
        ->and($revision->sources()->count())->toBe(3);
});

it('Admin 媒体预览：权限 / 真实 MIME / nosniff / no-store / PDF attachment / 缺件 404', function () {
    Storage::fake('local');

    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    post(route('admin.catalog.ecosystem.media.store'), [
        'file' => ecoFlowPngUpload('preview.png'),
    ])->assertRedirect(route('admin.catalog.ecosystem.media.index'));

    $image = EcosystemMedia::query()->orderByDesc('id')->firstOrFail();

    $response = get(route('admin.catalog.ecosystem.media.preview', $image->id));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Laravel 的会话中间件会再叠加 no-cache / max-age=0；只要 private + no-store 都在即满足。
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('no-store');

    expect((string) $response->headers->get('Content-Disposition'))->toBe('inline')
        ->and(strlen($response->streamedContent()))->toBeGreaterThan(0);

    // 响应头不暴露私有盘密钥 / 路径。
    $headerJson = (string) json_encode($response->headers->all(), JSON_UNESCAPED_SLASHES);

    expect($headerJson)->not->toContain((string) $image->storage_key)
        ->and($headerJson)->not->toContain('ecosystem/media/');

    // 媒体列表提供预览入口。
    get(route('admin.catalog.ecosystem.media.index'))
        ->assertOk()
        ->assertSee(route('admin.catalog.ecosystem.media.preview', $image->id));

    // PDF：attachment，避免浏览器内联打开不可信文档。
    $pdfKey = 'ecosystem/media/cc/'.str_repeat('c', 64).'.pdf';
    $pdfBytes = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n";

    Storage::disk('local')->put($pdfKey, $pdfBytes);

    $pdfId = (int) DB::table('ecosystem_media')->insertGetId([
        'disk'          => 'local',
        'storage_key'   => $pdfKey,
        'original_name' => 'preview.pdf',
        'mime'          => 'application/pdf',
        'byte_size'     => strlen($pdfBytes),
        'width'         => null,
        'height'        => null,
        'sha256'        => str_repeat('c', 64),
        'status'        => 'pending',
        'uploaded_by'   => 1,
        'created_at'    => '2026-10-19 04:00:00',
        'updated_at'    => '2026-10-19 04:00:00',
    ]);

    $pdfResponse = get(route('admin.catalog.ecosystem.media.preview', $pdfId));

    $pdfResponse->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect((string) $pdfResponse->headers->get('Content-Disposition'))->toContain('attachment')
        ->and((int) $pdfResponse->headers->get('Content-Length'))->toBe(strlen($pdfBytes))
        ->and((string) $pdfResponse->headers->get('Content-Disposition'))->not->toContain($pdfKey);

    // 无 ecosystem 权限的 custom 角色：403，不输出文件。
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => ['catalog'],
    ]);
    $limited = Admin::factory()->create(['role_id' => $role->id]);

    $this->actingAs($limited, 'admin');

    get(route('admin.catalog.ecosystem.media.preview', $image->id))->assertForbidden();

    // 超级管理员恢复正常；文件缺失与记录不存在都 404。
    $this->actingAs(ecoFlowSuperAdmin(), 'admin');

    Storage::disk('local')->delete((string) $image->storage_key);

    get(route('admin.catalog.ecosystem.media.preview', $image->id))->assertNotFound();
    get(route('admin.catalog.ecosystem.media.preview', 999999999))->assertNotFound();
});
