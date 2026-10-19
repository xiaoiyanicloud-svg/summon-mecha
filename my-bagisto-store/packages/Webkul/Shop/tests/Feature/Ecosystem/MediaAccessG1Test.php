<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Services\Ecosystem\MediaAccessException;
use Webkul\Shop\Services\Ecosystem\MediaAccessService;

/**
 * G1-06 · MediaAccessService 公开读取门禁（ADR-0001 §4.9 / §4.10 / G1-D06）。
 *
 * 门禁必须**同时**满足：
 *   · 所属域 public_enabled 打开；
 *   · owner_type 合法；
 *   · 引用存在且 review_status=approved；
 *   · 媒体 status=approved；
 *   · 父 revision review_state=approved 且是父对象当前 published_revision_id；
 *   · 父对象 publish_status=published 且 disposition=normal；
 *   · 私有盘文件存在。
 *
 * 任一失败统一 MEDIA_NOT_FOUND，文案完全一致 —— 不泄露媒体是否存在。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-06 media access tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    // 默认开关关闭；需要合法场景的用例显式打开。
    config([
        'ecosystem.events.public_enabled'  => false,
        'ecosystem.content.public_enabled' => false,
    ]);
});

function g6AccService(): MediaAccessService
{
    return app(MediaAccessService::class);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6AccEvent(array $overrides = []): int
{
    return (int) DB::table('events')->insertGetId(array_merge([
        'name'           => 'G6 访问活动',
        'publish_status' => 'published',
        'disposition'    => 'normal',
        'created_at'     => now(),
        'updated_at'     => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6AccEventRevision(int $eventId, array $overrides = []): int
{
    return (int) DB::table('event_revisions')->insertGetId(array_merge([
        'event_id'                => $eventId,
        'revision_no'             => 1,
        'name'                    => 'G6 活动快照',
        'timezone'                => 'Asia/Shanghai',
        'source_type'             => 'platform',
        'platform_role'           => 'organizer',
        'organizer_name_snapshot' => 'G6 主办方',
        'source_name'             => 'G6 来源',
        'content_fingerprint'     => str_repeat('a', 64),
        'change_summary'          => '初始版本',
        'review_state'            => 'approved',
        'created_by'              => 1,
        'created_at'              => now(),
        'updated_at'              => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6AccContentPost(array $overrides = []): int
{
    return (int) DB::table('content_posts')->insertGetId(array_merge([
        'type'           => 'news',
        'slug'           => 'g6-media-'.bin2hex(random_bytes(6)),
        'publish_status' => 'published',
        'disposition'    => 'normal',
        'row_version'    => 0,
        'created_by'     => 1,
        'created_at'     => now(),
        'updated_at'     => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6AccContentRevision(int $postId, array $overrides = []): int
{
    return (int) DB::table('content_revisions')->insertGetId(array_merge([
        'post_id'             => $postId,
        'revision_no'         => 1,
        'title'               => 'G6 内容快照',
        'author_name'         => 'G6 作者',
        'source_type'         => 'original',
        'change_summary'      => '初始版本',
        'content_fingerprint' => str_repeat('b', 64),
        'sanitizer_version'   => 'unsanitized-g1-05',
        'review_state'        => 'approved',
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: int, storage_key: string}
 */
function g6AccMedia(array $overrides = [], bool $writeFile = true): array
{
    $sha = str_pad(bin2hex(random_bytes(32)), 64, '0', STR_PAD_LEFT);
    $storageKey = 'ecosystem/media/'.substr($sha, 0, 2).'/'.$sha.'.png';

    $id = (int) DB::table('ecosystem_media')->insertGetId(array_merge([
        'disk'          => 'local',
        'storage_key'   => $storageKey,
        'original_name' => 'cover.png',
        'mime'          => 'image/png',
        'byte_size'     => 1234,
        'width'         => 320,
        'height'        => 200,
        'sha256'        => $sha,
        'status'        => 'approved',
        'uploaded_by'   => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ], $overrides));

    if ($writeFile) {
        Storage::disk('local')->put($storageKey, 'g6-media-bytes');
    }

    return ['id' => $id, 'storage_key' => $storageKey];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g6AccRef(int $mediaId, string $ownerType, int $ownerId, array $overrides = []): int
{
    return (int) DB::table('ecosystem_media_refs')->insertGetId(array_merge([
        'media_id'      => $mediaId,
        'owner_type'    => $ownerType,
        'owner_id'      => $ownerId,
        'role'          => 'cover',
        'sort_order'    => 0,
        'alt_text'      => null,
        'review_status' => 'approved',
        'created_at'    => now(),
    ], $overrides));
}

/**
 * @return array{event_id: int, revision_id: int, media_id: int}
 */
function g6AccEventScenario(
    array $eventOverrides = [],
    array $revisionOverrides = [],
    array $mediaOverrides = [],
    array $refOverrides = [],
): array {
    $eventId = g6AccEvent($eventOverrides);
    $revisionId = g6AccEventRevision($eventId, $revisionOverrides);
    DB::table('events')->where('id', $eventId)->update(['published_revision_id' => $revisionId]);

    $media = g6AccMedia($mediaOverrides);
    g6AccRef($media['id'], 'event_revision', $revisionId, $refOverrides);

    return ['event_id' => $eventId, 'revision_id' => $revisionId, 'media_id' => $media['id']];
}

/**
 * @return array{post_id: int, revision_id: int, media_id: int}
 */
function g6AccContentScenario(
    array $postOverrides = [],
    array $revisionOverrides = [],
    array $mediaOverrides = [],
    array $refOverrides = [],
): array {
    $postId = g6AccContentPost($postOverrides);
    $revisionId = g6AccContentRevision($postId, $revisionOverrides);
    DB::table('content_posts')->where('id', $postId)->update(['published_revision_id' => $revisionId]);

    $media = g6AccMedia($mediaOverrides);
    g6AccRef($media['id'], 'content_revision', $revisionId, $refOverrides);

    return ['post_id' => $postId, 'revision_id' => $revisionId, 'media_id' => $media['id']];
}

function g6AccExpectNotFound(callable $callback, string $label = ''): void
{
    $thrown = null;

    try {
        $callback();
    } catch (MediaAccessException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull($label.' 应被拒绝。')
        ->and($thrown->errorCode)->toBe(ApiErrorCode::MEDIA_NOT_FOUND)
        ->and($thrown->getMessage())->toBe('媒体不存在或不可访问。');
}

it('G1-06 · 合法活动媒体：开关打开 + 全部审核发布条件满足时可读取', function () {
    config(['ecosystem.events.public_enabled' => true]);

    $scenario = g6AccEventScenario();

    $media = g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']);

    expect($media->getKey())->toBe($scenario['media_id'])
        ->and($media->status)->toBe('approved')
        ->and(Storage::disk('local')->exists($media->storage_key))->toBeTrue();
});

it('G1-06 · 合法内容媒体：内容开关打开 + 全部审核发布条件满足时可读取', function () {
    config(['ecosystem.content.public_enabled' => true]);

    $scenario = g6AccContentScenario();

    $media = g6AccService()->resolvePublic('content_revision', $scenario['revision_id'], $scenario['media_id']);

    expect($media->getKey())->toBe($scenario['media_id']);
});

it('G1-06 · 所属域 public_enabled 关闭时统一 404（活动 / 内容）', function () {
    $event = g6AccEventScenario();
    $content = g6AccContentScenario();

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('event_revision', $event['revision_id'], $event['media_id']),
        '活动开关关闭',
    );

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('content_revision', $content['revision_id'], $content['media_id']),
        '内容开关关闭',
    );

    // 只打开活动开关不会放行内容媒体，反之亦然。
    config(['ecosystem.events.public_enabled' => true]);

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('content_revision', $content['revision_id'], $content['media_id']),
        '只开活动开关',
    );
});

it('G1-06 · owner_type 非法 / 引用不存在 / 媒体 ID 不匹配时统一 404', function () {
    config([
        'ecosystem.events.public_enabled'  => true,
        'ecosystem.content.public_enabled' => true,
    ]);

    $scenario = g6AccEventScenario();

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('event', $scenario['revision_id'], $scenario['media_id']),
        '非法 owner_type',
    );

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('content_revision', $scenario['revision_id'], $scenario['media_id']),
        'owner_type 与引用不匹配',
    );

    $otherMedia = g6AccMedia();

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $otherMedia['id']),
        '没有引用的媒体',
    );
});

it('G1-06 · 引用 review_status 或媒体 status 未批准时统一 404', function () {
    config(['ecosystem.events.public_enabled' => true]);

    foreach (['pending', 'rejected'] as $reviewStatus) {
        $scenario = g6AccEventScenario(refOverrides: ['review_status' => $reviewStatus]);

        g6AccExpectNotFound(
            fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']),
            "引用 review_status={$reviewStatus}",
        );
    }

    foreach (['pending', 'rejected', 'quarantined'] as $status) {
        $scenario = g6AccEventScenario(mediaOverrides: ['status' => $status]);

        g6AccExpectNotFound(
            fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']),
            "媒体 status={$status}",
        );
    }
});

it('G1-06 · 父 revision 未批准时统一 404', function () {
    config(['ecosystem.events.public_enabled' => true]);

    foreach (['draft', 'in_review', 'rejected'] as $state) {
        $scenario = g6AccEventScenario(revisionOverrides: ['review_state' => $state]);

        g6AccExpectNotFound(
            fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']),
            "revision review_state={$state}",
        );
    }
});

it('G1-06 · 父 revision 不是当前 published_revision_id 时统一 404', function () {
    config(['ecosystem.events.public_enabled' => true]);

    $scenario = g6AccEventScenario();
    $newerRevision = g6AccEventRevision($scenario['event_id'], ['revision_no' => 2]);

    DB::table('events')->where('id', $scenario['event_id'])
        ->update(['published_revision_id' => $newerRevision]);

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']),
        '旧 revision 媒体',
    );
});

it('G1-06 · 父对象未发布 / 处置非 normal 时统一 404', function () {
    config(['ecosystem.events.public_enabled' => true]);

    foreach ([
        ['publish_status' => 'draft'],
        ['publish_status' => 'in_review'],
        ['publish_status' => 'scheduled'],
        ['disposition' => 'withheld'],
        ['disposition' => 'retracted'],
    ] as $parentOverride) {
        $scenario = g6AccEventScenario(eventOverrides: $parentOverride);

        g6AccExpectNotFound(
            fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']),
            json_encode($parentOverride),
        );
    }
});

it('G1-06 · 私有盘文件缺失时统一 404', function () {
    config(['ecosystem.events.public_enabled' => true]);

    $scenario = g6AccEventScenario();

    Storage::disk('local')->delete(
        (string) DB::table('ecosystem_media')->where('id', $scenario['media_id'])->value('storage_key')
    );

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('event_revision', $scenario['revision_id'], $scenario['media_id']),
        '文件缺失',
    );
});

it('G1-06 · 内容媒体同样逐项校验父对象发布 / 当前指针', function () {
    config(['ecosystem.content.public_enabled' => true]);

    $scenario = g6AccContentScenario();

    // 指针切到新 revision。
    $newerRevision = g6AccContentRevision($scenario['post_id'], ['revision_no' => 2]);
    DB::table('content_posts')->where('id', $scenario['post_id'])
        ->update(['published_revision_id' => $newerRevision]);

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('content_revision', $scenario['revision_id'], $scenario['media_id']),
        '内容旧 revision',
    );

    // 恢复指针后，处置轴 withheld 仍拒绝。
    DB::table('content_posts')->where('id', $scenario['post_id'])
        ->update(['published_revision_id' => $scenario['revision_id'], 'disposition' => 'withheld']);

    g6AccExpectNotFound(
        fn () => g6AccService()->resolvePublic('content_revision', $scenario['revision_id'], $scenario['media_id']),
        '内容 disposition=withheld',
    );
});
