<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G1-10 · 公开媒体流式输出与门禁（私有盘、真实 MIME、no-store、统一 404）。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-10 public media tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem.content.public_enabled'         => true,
        'ecosystem_public_read.emergency_disabled' => false,
    ]);
});

it('G1-10 · 活动媒体从私有盘流式输出，响应头完整且不暴露存储路径', function () {
    $media = Fixtures::media('event-image-bytes', 'image/png');
    $event = Fixtures::event();
    Fixtures::mediaRef('event_revision', $event['revision_id'], $media['id']);

    $response = getJson("/api/events/{$event['id']}/media/{$media['id']}")->assertOk();

    expect($response->streamedContent())->toBe('event-image-bytes');

    $response
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Length', (string) strlen('event-image-bytes'))
        ->assertHeader('Content-Disposition', 'inline')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    // Symfony 会规范化 Cache-Control 的指令顺序；只断言两个指令都存在。
    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private');
    expect($cacheControl)->toContain('no-store');

    $rawHeaders = json_encode($response->headers->all());

    expect($rawHeaders)->not->toContain($media['storage_key']);
    expect($rawHeaders)->not->toContain('storage_key');
    expect($rawHeaders)->not->toContain('disk');
});

it('G1-10 · 资讯媒体走 slug 路由；PDF 使用 attachment', function () {
    $media = Fixtures::media('%PDF-1.4 fake', 'application/pdf');
    $post = Fixtures::content();
    Fixtures::mediaRef('content_revision', $post['revision_id'], $media['id'], 'attachment');

    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $response = getJson("/api/content/posts/{$slug}/media/{$media['id']}")->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 fake');

    $response
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment');

    $cacheControl = (string) $response->headers->get('Cache-Control');
    expect($cacheControl)->toContain('private');
    expect($cacheControl)->toContain('no-store');
});

it('G1-10 · 引用未批准 / 媒体未批准 / 父对象暂缓或撤稿 / 非当前 revision 统一 404', function () {
    $refPending = Fixtures::media('a', 'image/png');
    $eventPendingRef = Fixtures::event();
    Fixtures::mediaRef('event_revision', $eventPendingRef['revision_id'], $refPending['id'], 'cover', [
        'review_status' => 'pending',
    ]);

    getJson("/api/events/{$eventPendingRef['id']}/media/{$refPending['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);

    $mediaPending = Fixtures::media('b', 'image/png', ['status' => 'pending']);
    $eventPendingMedia = Fixtures::event();
    Fixtures::mediaRef('event_revision', $eventPendingMedia['revision_id'], $mediaPending['id']);

    getJson("/api/events/{$eventPendingMedia['id']}/media/{$mediaPending['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);

    $withheldMedia = Fixtures::media('c', 'image/png');
    $withheld = Fixtures::event(['disposition' => 'withheld']);
    Fixtures::mediaRef('event_revision', $withheld['revision_id'], $withheldMedia['id']);

    getJson("/api/events/{$withheld['id']}/media/{$withheldMedia['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);

    $retractedMedia = Fixtures::media('d', 'image/png');
    $retracted = Fixtures::event(['disposition' => 'retracted']);
    Fixtures::mediaRef('event_revision', $retracted['revision_id'], $retractedMedia['id']);

    // 撤稿父对象的媒体统一 404（不是 410），避免通过媒体探测撤稿对象。
    getJson("/api/events/{$retracted['id']}/media/{$retractedMedia['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);

    $oldRevisionMedia = Fixtures::media('e', 'image/png');
    $current = Fixtures::event();
    $oldRevisionId = (int) DB::table('event_revisions')->insertGetId([
        'event_id'                    => $current['id'],
        'revision_no'                 => 2,
        'name'                        => '旧候选',
        'summary'                     => null,
        'detail_html'                 => null,
        'city'                        => null,
        'location_text'               => null,
        'participation_mode'          => 'offline',
        'timezone'                    => 'Asia/Shanghai',
        'starts_at'                   => null,
        'ends_at'                     => null,
        'source_type'                 => 'platform',
        'platform_role'               => 'organizer',
        'organizer_id'                => null,
        'organizer_name_snapshot'     => '召唤机甲',
        'source_name'                 => '召唤机甲官方',
        'source_url'                  => null,
        'source_verified_at'          => null,
        'authorization_basis'         => null,
        'authorization_evidence_ref'  => null,
        'external_registration_url'   => null,
        'rule_summary'                => null,
        'content_fingerprint'         => str_repeat('c', 64),
        'media_manifest_hash'         => null,
        'sanitizer_version'           => (string) config('ecosystem_rich_text.sanitizer_version'),
        'review_state'                => 'approved',
        'submitted_by'                => null,
        'submitted_at'                => null,
        'reviewed_by'                 => null,
        'reviewed_at'                 => null,
        'review_note'                 => null,
        'change_summary'              => '候选版本',
        'published_at'                => null,
        'created_by'                  => 1,
        'created_at'                  => '2026-10-19 04:00:00',
        'updated_at'                  => '2026-10-19 04:00:00',
    ]);
    Fixtures::mediaRef('event_revision', $oldRevisionId, $oldRevisionMedia['id']);

    getJson("/api/events/{$current['id']}/media/{$oldRevisionMedia['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);
});

it('G1-10 · 文件缺失返回 404 并记录 error 日志', function () {
    Log::spy();

    $media = Fixtures::media('lost-bytes', 'image/png');
    $event = Fixtures::event();
    Fixtures::mediaRef('event_revision', $event['revision_id'], $media['id']);

    Storage::disk('local')->delete($media['storage_key']);

    getJson("/api/events/{$event['id']}/media/{$media['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);

    Log::shouldHaveReceived('error')->withArgs(
        static fn (string $message, array $context = []): bool => $message === 'ecosystem.public_media.file_missing'
            && ($context['media_id'] ?? null) === $media['id'],
    );
});

it('G1-10 · kill switch 503 优先于媒体门禁；域开关关闭时 404 且不查库', function () {
    $media = Fixtures::media('kill-switch', 'image/png');
    $event = Fixtures::event();
    Fixtures::mediaRef('event_revision', $event['revision_id'], $media['id']);

    config([
        'ecosystem_public_read.emergency_disabled' => true,
        'ecosystem.events.public_enabled'          => false,
    ]);

    getJson("/api/events/{$event['id']}/media/{$media['id']}")
        ->assertStatus(503)
        ->assertJsonPath('code', ApiErrorCode::SERVICE_UNAVAILABLE);

    config([
        'ecosystem_public_read.emergency_disabled' => false,
        'ecosystem.events.public_enabled'          => false,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    getJson("/api/events/{$event['id']}/media/{$media['id']}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::MEDIA_NOT_FOUND);

    $queries = array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], 'events')
            || str_contains($query['query'], 'event_revisions'),
    );

    DB::disableQueryLog();

    expect($queries)->toBe([]);
});
