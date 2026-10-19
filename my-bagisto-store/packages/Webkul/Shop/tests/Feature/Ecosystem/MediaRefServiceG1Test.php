<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EcosystemMediaRef;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\ContentFingerprint;
use Webkul\Shop\Services\Ecosystem\MediaManifest;
use Webkul\Shop\Services\Ecosystem\MediaRefService;
use Webkul\Shop\Services\Ecosystem\MediaTransitionException;
use Webkul\Shop\Services\Ecosystem\MediaValidationException;

/**
 * G1-06 · MediaRefService 引用写入与审核（ADR-0001 §4.9 / §4.10）。
 *
 * 保护的是 revision 与媒体之间的审核快照：
 *   · 只有 draft revision 可以整组替换引用；非 draft 由服务与模型守卫双重拒绝；
 *   · 被引用媒体必须自身 approved，未批准媒体不得混入草稿；
 *   · sort_order 由服务端按输入顺序重排，调用方传值不算数；
 *   · media_manifest_hash 包含 media sha256 / role / sort_order / alt_text /
 *     review_status；替换与引用审核都必须同步重算父 revision 指纹；
 *   · content_revision 的指纹必须带上 content_sources。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-06 media ref tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g6RefAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G6 Ref Admin',
        'email'      => 'g6-ref-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g6RefMedia(string $status = 'approved'): EcosystemMedia
{
    $sha256 = hash('sha256', random_bytes(32));
    $storageKey = 'ecosystem/media/'.substr($sha256, 0, 2).'/'.$sha256.'.png';

    $id = (int) DB::table('ecosystem_media')->insertGetId([
        'disk'          => 'local',
        'storage_key'   => $storageKey,
        'original_name' => 'ref.png',
        'mime'          => 'image/png',
        'byte_size'     => 1234,
        'width'         => 320,
        'height'        => 200,
        'sha256'        => $sha256,
        'status'        => $status,
        'uploaded_by'   => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return EcosystemMedia::query()->findOrFail($id);
}

function g6RefEventId(): int
{
    return (int) DB::table('events')->insertGetId([
        'name'       => 'G6 媒体引用活动',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g6RefEventRevision(int $eventId, string $state = 'draft'): EventRevision
{
    $id = (int) DB::table('event_revisions')->insertGetId([
        'event_id'                => $eventId,
        'revision_no'             => 1,
        'name'                    => 'G6 媒体引用活动快照',
        'timezone'                => 'Asia/Shanghai',
        'source_type'             => 'platform',
        'platform_role'           => 'organizer',
        'organizer_name_snapshot' => 'G6 主办方',
        'source_name'             => 'G6 来源',
        'content_fingerprint'     => str_repeat('a', 64),
        'change_summary'          => '媒体引用夹具',
        'review_state'            => $state,
        'created_by'              => 1,
        'created_at'              => now(),
        'updated_at'              => now(),
    ]);

    return EventRevision::query()->findOrFail($id);
}

function g6RefContentRevision(string $state = 'draft'): ContentRevision
{
    $postId = (int) DB::table('content_posts')->insertGetId([
        'type'       => 'news',
        'slug'       => 'g6-ref-'.bin2hex(random_bytes(6)),
        'created_by' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $id = (int) DB::table('content_revisions')->insertGetId([
        'post_id'             => $postId,
        'revision_no'         => 1,
        'title'               => 'G6 媒体引用内容快照',
        'author_name'         => 'G6 作者',
        'source_type'         => 'original',
        'change_summary'      => '媒体引用夹具',
        'content_fingerprint' => str_repeat('b', 64),
        'sanitizer_version'   => 'unsanitized-g1-05',
        'review_state'        => $state,
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return ContentRevision::query()->findOrFail($id);
}

function g6RefService(): MediaRefService
{
    return app(MediaRefService::class);
}

function g6RefManifestHash(string $ownerType, int $ownerId): string
{
    $refs = EcosystemMediaRef::query()
        ->where('owner_type', $ownerType)
        ->where('owner_id', $ownerId)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get()
        ->all();

    return app(MediaManifest::class)->hash($refs);
}

it('G1-06 · event revision 整组替换：按输入重排 sort_order 并更新 manifest / 指纹', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $first = g6RefMedia();
    $second = g6RefMedia();

    $originalFingerprint = (string) $revision->content_fingerprint;

    $refs = $service->replaceForRevision('event_revision', (int) $revision->getKey(), [
        ['media_id' => (int) $second->getKey(), 'role' => 'attachment', 'alt_text' => ' 第二张图 ', 'sort_order' => 99],
        ['media_id' => (int) $first->getKey(), 'role' => 'cover'],
    ]);

    expect($refs)->toHaveCount(2)
        ->and((int) $refs[0]->media_id)->toBe((int) $second->getKey())
        ->and((int) $refs[0]->sort_order)->toBe(0)
        ->and($refs[0]->alt_text)->toBe('第二张图')
        ->and($refs[0]->review_status)->toBe('pending')
        ->and((int) $refs[1]->media_id)->toBe((int) $first->getKey())
        ->and((int) $refs[1]->sort_order)->toBe(1);

    $fresh = $revision->fresh();

    expect($fresh->media_manifest_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($fresh->media_manifest_hash)->toBe(g6RefManifestHash('event_revision', (int) $revision->getKey()))
        ->and($fresh->content_fingerprint)->not->toBe($originalFingerprint)
        ->and($fresh->content_fingerprint)->toBe(app(ContentFingerprint::class)->eventRevision($fresh));

    expect(
        DB::table('ecosystem_media_refs')
            ->where('owner_type', 'event_revision')
            ->where('owner_id', $revision->getKey())
            ->orderBy('sort_order')
            ->pluck('media_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()
    )->toBe([(int) $second->getKey(), (int) $first->getKey()]);
});

it('G1-06 · 再次整组替换会删掉旧引用，manifest 随顺序 / alt / role 变化', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $first = g6RefMedia();
    $second = g6RefMedia();
    $ownerId = (int) $revision->getKey();

    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $first->getKey(), 'role' => 'cover', 'alt_text' => 'A'],
        ['media_id' => (int) $second->getKey(), 'role' => 'inline', 'alt_text' => 'B'],
    ]);
    $hashOne = (string) $revision->fresh()->media_manifest_hash;

    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $second->getKey(), 'role' => 'inline', 'alt_text' => 'B'],
        ['media_id' => (int) $first->getKey(), 'role' => 'cover', 'alt_text' => 'A'],
    ]);
    $hashTwo = (string) $revision->fresh()->media_manifest_hash;

    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $first->getKey(), 'role' => 'cover', 'alt_text' => 'A 改'],
        ['media_id' => (int) $second->getKey(), 'role' => 'inline', 'alt_text' => 'B'],
    ]);
    $hashThree = (string) $revision->fresh()->media_manifest_hash;

    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $first->getKey(), 'role' => 'attachment', 'alt_text' => 'A 改'],
        ['media_id' => (int) $second->getKey(), 'role' => 'inline', 'alt_text' => 'B'],
    ]);
    $hashFour = (string) $revision->fresh()->media_manifest_hash;

    expect($hashTwo)->not->toBe($hashOne)
        ->and($hashThree)->not->toBe($hashTwo)
        ->and($hashFour)->not->toBe($hashThree);

    // 整组替换后只剩本次输入的两条。
    expect(DB::table('ecosystem_media_refs')->where('owner_type', 'event_revision')
        ->where('owner_id', $ownerId)->count())->toBe(2);
});

it('G1-06 · content revision 指纹包含 content_sources，来源变化必须产生新指纹', function () {
    $service = g6RefService();
    $revision = g6RefContentRevision();
    $media = g6RefMedia();
    $ownerId = (int) $revision->getKey();

    DB::table('content_sources')->insert([
        'revision_id'        => $ownerId,
        'source_name'        => 'G6 原始来源',
        'source_url'         => 'https://example.test/source',
        'source_verified_at' => now(),
        'rights_basis'       => 'platform_original',
        'rights_scope'       => null,
        'evidence_ref'       => null,
        'sort_order'         => 0,
        'created_at'         => now(),
    ]);

    $service->replaceForRevision('content_revision', $ownerId, [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]);

    $fresh = $revision->fresh();
    $sources = $fresh->sources()->orderBy('sort_order')->get()->all();

    expect($fresh->content_fingerprint)
        ->toBe(app(ContentFingerprint::class)->contentRevision($fresh, $sources));

    $manifestBefore = (string) $fresh->media_manifest_hash;
    $fingerprintBefore = (string) $fresh->content_fingerprint;

    // 只改来源、媒体引用完全相同：manifest 不变，但 content_fingerprint 必须变。
    DB::table('content_sources')->where('revision_id', $ownerId)->update(['source_name' => 'G6 改名来源']);

    $service->replaceForRevision('content_revision', $ownerId, [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]);

    $after = $revision->fresh();

    expect($after->media_manifest_hash)->toBe($manifestBefore)
        ->and($after->content_fingerprint)->not->toBe($fingerprintBefore);
});

it('G1-07 · 替换引用后按新媒体集合重新净化正文并改变 manifest / 指纹', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $ownerId = (int) $revision->getKey();
    $first = g6RefMedia();
    $second = g6RefMedia();

    DB::table('event_revisions')->where('id', $ownerId)->update([
        'detail_html' => '<p>活动正文</p><img data-media-id="'.(int) $first->getKey().'" alt="图一">',
    ]);

    // 第一次替换：正文引用的媒体在允许集合内，图片保留。
    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $first->getKey(), 'role' => 'inline'],
    ]);

    $kept = $revision->fresh();
    $keptManifest = (string) $kept->media_manifest_hash;
    $keptFingerprint = (string) $kept->content_fingerprint;

    expect((string) $kept->detail_html)->toContain('data-media-id="'.(int) $first->getKey().'"')
        ->and((string) $kept->sanitizer_version)->toBe((string) config('ecosystem_rich_text.sanitizer_version'))
        ->and($keptManifest)->not->toBe('')
        ->and($keptFingerprint)->toBe(app(ContentFingerprint::class)->eventRevision($kept));

    // 第二次替换：正文引用的旧媒体已不在新集合内，必须被净化掉。
    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $second->getKey(), 'role' => 'inline'],
    ]);

    $after = $revision->fresh();

    expect((string) $after->detail_html)->toContain('活动正文')
        ->and((string) $after->detail_html)->not->toContain('data-media-id="'.(int) $first->getKey().'"')
        ->and((string) $after->detail_html)->not->toContain('<img')
        ->and((string) $after->media_manifest_hash)->not->toBe($keptManifest)
        ->and((string) $after->content_fingerprint)->not->toBe($keptFingerprint)
        ->and((string) $after->content_fingerprint)->toBe(app(ContentFingerprint::class)->eventRevision($after));
});

it('G1-07 · content revision 替换引用同样重新净化 body_html', function () {
    $service = g6RefService();
    $revision = g6RefContentRevision();
    $ownerId = (int) $revision->getKey();
    $first = g6RefMedia();
    $second = g6RefMedia();

    DB::table('content_revisions')->where('id', $ownerId)->update([
        'body_html' => '<p>资讯正文</p><img data-media-id="'.(int) $first->getKey().'" alt="图一">',
    ]);

    $service->replaceForRevision('content_revision', $ownerId, [
        ['media_id' => (int) $first->getKey(), 'role' => 'inline'],
    ]);

    $kept = $revision->fresh();
    $keptFingerprint = (string) $kept->content_fingerprint;

    expect((string) $kept->body_html)->toContain('data-media-id="'.(int) $first->getKey().'"');

    $service->replaceForRevision('content_revision', $ownerId, [
        ['media_id' => (int) $second->getKey(), 'role' => 'inline'],
    ]);

    $after = $revision->fresh();

    expect((string) $after->body_html)->toContain('资讯正文')
        ->and((string) $after->body_html)->not->toContain('<img')
        ->and((string) $after->content_fingerprint)->not->toBe($keptFingerprint)
        ->and((string) $after->sanitizer_version)->toBe((string) config('ecosystem_rich_text.sanitizer_version'));
});

it('G1-06 · 超过 max_refs_per_revision 整体拒绝且不落任何引用', function () {
    config(['ecosystem_media.max_refs_per_revision' => 2]);

    $revision = g6RefEventRevision(g6RefEventId());
    $media = [g6RefMedia(), g6RefMedia(), g6RefMedia()];

    expect(fn () => g6RefService()->replaceForRevision(
        'event_revision',
        (int) $revision->getKey(),
        array_map(fn (EcosystemMedia $item): array => [
            'media_id' => (int) $item->getKey(),
            'role'     => 'inline',
        ], $media),
    ))->toThrow(MediaValidationException::class);

    expect(DB::table('ecosystem_media_refs')->count())->toBe(0)
        ->and($revision->fresh()->media_manifest_hash)->toBeNull();
});

it('G1-06 · 未批准 / 不存在的媒体不得进入引用，失败时保留旧引用', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $ownerId = (int) $revision->getKey();
    $approved = g6RefMedia();
    $pending = g6RefMedia('pending');
    $rejected = g6RefMedia('rejected');

    expect(fn () => $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $pending->getKey(), 'role' => 'cover'],
    ]))->toThrow(MediaValidationException::class);

    expect(fn () => $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $rejected->getKey(), 'role' => 'cover'],
    ]))->toThrow(MediaValidationException::class);

    expect(fn () => $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => 999999999, 'role' => 'cover'],
    ]))->toThrow(MediaValidationException::class);

    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $approved->getKey(), 'role' => 'cover'],
    ]);

    // 第二次替换因含未批准媒体而失败，旧引用必须原样保留。
    expect(fn () => $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $approved->getKey(), 'role' => 'cover'],
        ['media_id' => (int) $pending->getKey(), 'role' => 'inline'],
    ]))->toThrow(MediaValidationException::class);

    expect(DB::table('ecosystem_media_refs')->where('owner_type', 'event_revision')
        ->where('owner_id', $ownerId)->pluck('media_id')->map(fn ($id): int => (int) $id)->all())
        ->toBe([(int) $approved->getKey()]);
});

it('G1-06 · role / alt_text / 越权字段校验', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $ownerId = (int) $revision->getKey();
    $media = g6RefMedia();

    $cases = [
        [['media_id' => (int) $media->getKey(), 'role' => 'banner']],
        [['media_id' => (int) $media->getKey(), 'role' => 'cover', 'alt_text' => str_repeat('长', 256)]],
        [['media_id' => (int) $media->getKey(), 'role' => 'cover', 'alt_text' => ['not', 'string']]],
        [['media_id' => (int) $media->getKey(), 'role' => 'cover', 'review_status' => 'approved']],
        [['media_id' => 'abc', 'role' => 'cover']],
        [['role' => 'cover']],
    ];

    foreach ($cases as $refs) {
        expect(fn () => $service->replaceForRevision('event_revision', $ownerId, $refs))
            ->toThrow(MediaValidationException::class);
    }

    expect(DB::table('ecosystem_media_refs')->count())->toBe(0);
});

it('G1-06 · 父 revision 非 draft 时服务与模型守卫都拒绝创建 / 修改 / 删除引用', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $ownerId = (int) $revision->getKey();
    $media = g6RefMedia();

    $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]);

    $ref = EcosystemMediaRef::query()->firstOrFail();

    DB::table('event_revisions')->where('id', $ownerId)->update(['review_state' => 'in_review']);

    expect(fn () => $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]))->toThrow(MediaValidationException::class);

    expect(fn () => EcosystemMediaRef::create([
        'media_id'   => (int) $media->getKey(),
        'owner_type' => 'event_revision',
        'owner_id'   => $ownerId,
        'role'       => 'cover',
        'sort_order' => 1,
    ]))->toThrow(MediaValidationException::class);

    $ref->review_status = 'approved';
    expect(fn () => $ref->save())->toThrow(MediaValidationException::class);

    expect(fn () => $ref->delete())->toThrow(MediaValidationException::class);

    // content revision 同样冻结。
    $contentRevision = g6RefContentRevision('approved');
    expect(fn () => $service->replaceForRevision('content_revision', (int) $contentRevision->getKey(), [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]))->toThrow(MediaValidationException::class);
});

it('G1-06 · 不存在的父 revision 与非法 owner_type 被拒绝', function () {
    $service = g6RefService();
    $media = g6RefMedia();

    expect(fn () => $service->replaceForRevision('event', 1, []))
        ->toThrow(MediaValidationException::class);

    expect(fn () => $service->replaceForRevision('event_revision', 999999999, [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]))->toThrow(MediaValidationException::class);

    expect(fn () => $service->approvedMediaIdsForRevision('content', 1))
        ->toThrow(MediaValidationException::class);
});

it('G1-06 · reviewRef 只允许 pending -> approved / rejected，并同步重算指纹', function () {
    $service = g6RefService();
    $adminId = g6RefAdminId();
    $revision = g6RefEventRevision(g6RefEventId());
    $ownerId = (int) $revision->getKey();
    $first = g6RefMedia();
    $second = g6RefMedia();

    $refs = $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $first->getKey(), 'role' => 'cover'],
        ['media_id' => (int) $second->getKey(), 'role' => 'inline'],
    ]);

    $hashBefore = (string) $revision->fresh()->media_manifest_hash;
    $fingerprintBefore = (string) $revision->fresh()->content_fingerprint;

    $approved = $service->reviewRef((int) $refs[0]->getKey(), 'approved', $adminId);

    expect($approved->review_status)->toBe('approved');

    $afterApprove = $revision->fresh();

    expect($afterApprove->media_manifest_hash)->not->toBe($hashBefore)
        ->and($afterApprove->media_manifest_hash)->toBe(g6RefManifestHash('event_revision', $ownerId))
        ->and($afterApprove->content_fingerprint)->not->toBe($fingerprintBefore)
        ->and($afterApprove->content_fingerprint)->toBe(app(ContentFingerprint::class)->eventRevision($afterApprove));

    expect(fn () => $service->reviewRef((int) $refs[0]->getKey(), 'rejected', $adminId))
        ->toThrow(MediaTransitionException::class);

    $rejected = $service->reviewRef((int) $refs[1]->getKey(), 'rejected', $adminId);

    expect($rejected->review_status)->toBe('rejected');

    expect(fn () => $service->reviewRef((int) $refs[1]->getKey(), 'pending', $adminId))
        ->toThrow(MediaValidationException::class);

    expect(fn () => $service->reviewRef((int) $refs[1]->getKey(), 'approved', 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->reviewRef(999999999, 'approved', $adminId))
        ->toThrow(MediaValidationException::class);
});

it('G1-06 · reviewRef 批准时媒体必须仍 approved', function () {
    $service = g6RefService();
    $adminId = g6RefAdminId();
    $revision = g6RefEventRevision(g6RefEventId());
    $media = g6RefMedia();

    $refs = $service->replaceForRevision('event_revision', (int) $revision->getKey(), [
        ['media_id' => (int) $media->getKey(), 'role' => 'cover'],
    ]);

    // 引用写入后媒体被隔离：引用不能借此转为 approved。
    DB::table('ecosystem_media')->where('id', $media->getKey())->update(['status' => 'quarantined']);

    expect(fn () => $service->reviewRef((int) $refs[0]->getKey(), 'approved', $adminId))
        ->toThrow(MediaValidationException::class);

    // 但仍可以驳回该引用。
    $rejected = $service->reviewRef((int) $refs[0]->getKey(), 'rejected', $adminId);

    expect($rejected->review_status)->toBe('rejected');
});

it('G1-06 · approvedMediaIdsForRevision 只返回被引用且媒体 approved 的 ID', function () {
    $service = g6RefService();
    $revision = g6RefEventRevision(g6RefEventId());
    $ownerId = (int) $revision->getKey();
    $approved = g6RefMedia();
    $pending = g6RefMedia();
    $rejected = g6RefMedia();

    $refs = $service->replaceForRevision('event_revision', $ownerId, [
        ['media_id' => (int) $approved->getKey(), 'role' => 'cover'],
        ['media_id' => (int) $pending->getKey(), 'role' => 'inline'],
        ['media_id' => (int) $rejected->getKey(), 'role' => 'attachment'],
    ]);

    DB::table('ecosystem_media')->where('id', $pending->getKey())->update(['status' => 'pending']);
    DB::table('ecosystem_media')->where('id', $rejected->getKey())->update(['status' => 'rejected']);

    expect($service->approvedMediaIdsForRevision('event_revision', $ownerId))
        ->toBe([(int) $approved->getKey()]);

    // 引用自身被驳回不影响“媒体仍 approved 且被引用”的事实；公开可见性另行门禁。
    DB::table('ecosystem_media_refs')->where('id', $refs[0]->getKey())->update(['review_status' => 'rejected']);

    expect($service->approvedMediaIdsForRevision('event_revision', $ownerId))
        ->toBe([(int) $approved->getKey()]);

    // 另一个 revision 不受影响。
    $other = g6RefEventRevision(g6RefEventId());
    expect($service->approvedMediaIdsForRevision('event_revision', (int) $other->getKey()))->toBe([]);
});
