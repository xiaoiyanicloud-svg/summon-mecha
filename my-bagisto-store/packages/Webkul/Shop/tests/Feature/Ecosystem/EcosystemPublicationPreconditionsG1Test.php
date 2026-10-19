<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventReviewService;
use Webkul\Shop\Services\Ecosystem\MediaRefService;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;

/**
 * G1-08A · 提交 / 批准前置：指纹、媒体、净化版本、来源与时间（ADR 附录 B A-06）。
 *
 * 覆盖：
 *   · 审批前重算 content_fingerprint / media_manifest_hash，任一漂移 fail-closed；
 *   · sanitizer_version 必须等于当前 config；
 *   · 媒体引用 / 媒体自身必须 approved，私有盘文件必须存在（不生成公开 URL）；
 *   · 活动平台时间与来源字段完整；
 *   · 内容来源进入审核后必须有 URL。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08A precondition tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g8PreAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8 Precondition Admin',
        'email'      => 'g8-pre-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g8PreOrganizer(): EcosystemOrganizer
{
    return EcosystemOrganizer::create([
        'name'             => 'G8 前置主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'verified',
        'verified_by'      => 1,
        'verified_at'      => now(),
    ]);
}

function g8PreEvent(EcosystemOrganizer $organizer): Event
{
    return Event::create([
        'name'           => 'G8 前置活动 '.bin2hex(random_bytes(3)),
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
function g8PreEventPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                    => 'G8 前置活动名称',
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

function g8PreEventDraft(Event $event, EcosystemOrganizer $organizer, int $adminId, array $overrides = []): \Webkul\Shop\Models\EventRevision
{
    return app(EventRevisionService::class)->createDraft($event, $adminId, g8PreEventPayload($organizer, $overrides));
}

function g8PrePost(int $adminId): ContentPost
{
    return app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8-pre-'.bin2hex(random_bytes(5)),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8PreContentPayload(array $overrides = []): array
{
    return array_merge([
        'title'          => 'G8 前置资讯',
        'body_html'      => '<p>G8 正文</p>',
        'author_name'    => '张三',
        'source_type'    => 'original',
        'change_summary' => '初始版本',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8PreContentSource(array $overrides = []): array
{
    return array_merge([
        'source_name'        => '平台官网',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
    ], $overrides);
}

/**
 * @param  array<int, array<string, mixed>>  $sources
 */
function g8PreContentDraft(
    ContentPost $post,
    int $adminId,
    array $overrides = [],
    array $sources = [],
): \Webkul\Shop\Models\ContentRevision {
    return app(ContentRevisionService::class)->createDraft(
        $post,
        $adminId,
        g8PreContentPayload($overrides),
        $sources !== [] ? $sources : [g8PreContentSource()],
    );
}

function g8PreMedia(): EcosystemMedia
{
    $sha = hash('sha256', random_bytes(32));
    $key = 'ecosystem/media/'.substr($sha, 0, 2).'/'.$sha.'.png';

    $media = EcosystemMedia::create([
        'disk'          => 'local',
        'storage_key'   => $key,
        'original_name' => 'pre.png',
        'mime'          => 'image/png',
        'byte_size'     => 4,
        'width'         => 10,
        'height'        => 10,
        'sha256'        => $sha,
        'status'        => 'approved',
        'uploaded_by'   => 1,
    ]);

    Storage::disk('local')->put($key, 'PNG!');

    return $media;
}

it('G1-08A · 审批前正文被改动 -> 活动指纹冲突，不写批准', function () {
    $adminId = g8PreAdminId();
    $organizer = g8PreOrganizer();
    $event = g8PreEvent($organizer);
    $revision = g8PreEventDraft($event, $organizer, $adminId);

    $event = app(EventReviewService::class)->submit($event, $revision, $adminId, 0);

    // 绕过模型守卫模拟“审核后正文漂移”。
    DB::table('event_revisions')->where('id', $revision->id)->update(['name' => '审批后偷改的名称']);

    try {
        app(EventReviewService::class)->approve($event, $revision->fresh(), $adminId, 1);
        $this->fail('指纹漂移后不应允许批准。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_REVISION_CONFLICT);
    }

    expect((string) $event->fresh()->publish_status)->toBe('in_review')
        ->and($event->fresh()->approved_revision_id)->toBeNull();
});

it('G1-08A · sanitizer_version 与当前版本不一致 -> 提交失败', function () {
    $adminId = g8PreAdminId();
    $organizer = g8PreOrganizer();
    $event = g8PreEvent($organizer);
    $revision = g8PreEventDraft($event, $organizer, $adminId);

    DB::table('event_revisions')->where('id', $revision->id)->update(['sanitizer_version' => 'g1-06-old']);

    try {
        app(EventReviewService::class)->submit($event, $revision->fresh(), $adminId, 0);
        $this->fail('旧净化版本不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_REVISION_CONFLICT);
    }
});

it('G1-08A · 平台活动缺起止时间 / 缺授权依据 -> 提交拒绝', function () {
    $adminId = g8PreAdminId();
    $organizer = g8PreOrganizer();
    $event = g8PreEvent($organizer);

    $missingTime = g8PreEventDraft($event, $organizer, $adminId, [
        'starts_at' => null,
        'ends_at'   => null,
    ]);

    try {
        app(EventReviewService::class)->submit($event, $missingTime, $adminId, 0);
        $this->fail('平台活动缺时间不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_TIME_INVALID);
    }

    $missingBasis = g8PreEventDraft($event, $organizer, $adminId, [
        'authorization_basis' => null,
    ]);

    try {
        app(EventReviewService::class)->submit($event, $missingBasis, $adminId, 0);
        $this->fail('缺 authorization_basis 不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
    }
});

it('G1-08A · 媒体引用未批准 / 文件缺失 / 媒体隔离 -> MEDIA_INVALID', function () {
    Storage::fake('local');

    $adminId = g8PreAdminId();
    $organizer = g8PreOrganizer();
    $event = g8PreEvent($organizer);
    $revision = g8PreEventDraft($event, $organizer, $adminId);
    $media = g8PreMedia();

    $refs = app(MediaRefService::class)->replaceForRevision('event_revision', (int) $revision->id, [
        ['media_id' => (int) $media->id, 'role' => 'cover', 'alt_text' => '封面'],
    ]);

    // 引用仍是 pending。
    try {
        app(EventReviewService::class)->submit($event, $revision->fresh(), $adminId, 0);
        $this->fail('pending 引用不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::MEDIA_INVALID);
    }

    app(MediaRefService::class)->reviewRef((int) $refs[0]->id, 'approved', $adminId);

    // 文件缺失。
    Storage::disk('local')->delete((string) $media->storage_key);

    try {
        app(EventReviewService::class)->submit($event, $revision->fresh(), $adminId, 0);
        $this->fail('媒体文件缺失不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::MEDIA_INVALID);
    }

    // 文件恢复但媒体被隔离。
    Storage::disk('local')->put((string) $media->storage_key, 'PNG!');
    DB::table('ecosystem_media')->where('id', $media->id)->update(['status' => 'quarantined']);

    try {
        app(EventReviewService::class)->submit($event, $revision->fresh(), $adminId, 0);
        $this->fail('隔离媒体不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::MEDIA_INVALID);
    }
});

it('G1-08A · 引用与媒体均 approved 且文件存在 -> 提交成功', function () {
    Storage::fake('local');

    $adminId = g8PreAdminId();
    $organizer = g8PreOrganizer();
    $event = g8PreEvent($organizer);
    $revision = g8PreEventDraft($event, $organizer, $adminId);
    $media = g8PreMedia();

    $refs = app(MediaRefService::class)->replaceForRevision('event_revision', (int) $revision->id, [
        ['media_id' => (int) $media->id, 'role' => 'cover'],
    ]);
    app(MediaRefService::class)->reviewRef((int) $refs[0]->id, 'approved', $adminId);

    $event = app(EventReviewService::class)->submit($event, $revision->fresh(), $adminId, 0);

    expect((string) $event->publish_status)->toBe('in_review')
        ->and((string) $revision->fresh()->review_state)->toBe('in_review')
        ->and($event->scheduled_publish_at)->toBeNull();
});

it('G1-08A · 内容来源漂移 -> 审批 CONTENT_REVISION_CONFLICT', function () {
    $adminId = g8PreAdminId();
    $post = g8PrePost($adminId);
    $revision = g8PreContentDraft($post, $adminId);

    $post = app(ContentReviewService::class)->submit($post, $revision, $adminId, 0);

    DB::table('content_revisions')->where('id', $revision->id)->update(['title' => '审批后偷改的标题']);

    try {
        app(ContentReviewService::class)->approve($post, $revision->fresh(), $adminId, 1);
        $this->fail('内容指纹漂移后不应允许批准。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_REVISION_CONFLICT);
    }
});

it('G1-08A · 内容来源缺 URL / 旧 sanitizer 版本 -> 提交拒绝', function () {
    $adminId = g8PreAdminId();
    $post = g8PrePost($adminId);

    $noUrl = g8PreContentDraft($post, $adminId, [], [
        g8PreContentSource(['source_url' => null]),
    ]);

    try {
        app(ContentReviewService::class)->submit($post, $noUrl, $adminId, 0);
        $this->fail('来源缺 URL 不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_SOURCE_REQUIRED);
    }

    $oldSanitizer = g8PreContentDraft($post, $adminId);
    DB::table('content_revisions')->where('id', $oldSanitizer->id)->update(['sanitizer_version' => 'unsanitized-g1-05']);

    try {
        app(ContentReviewService::class)->submit($post, $oldSanitizer->fresh(), $adminId, 0);
        $this->fail('旧净化版本不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_REVISION_CONFLICT);
    }
});
