<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\ContentSource;
use Webkul\Shop\Services\Ecosystem\ContentFingerprint;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionFingerprintConflictException;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentSourceValidationException;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;

/**
 * G1-05 · ContentRevisionService 行为守卫（ADR-0001 §4.7 / §4.8 / G1-D06）。
 *
 * 保护的是**版本产生、来源绑定与编辑边界**：
 *   · revision_no 在文章行锁内从 1 递增；
 *   · 指纹服务端计算且包含有序来源：同正文不同来源指纹必须不同；
 *   · source_type / rights_basis 组合、URL、核验时间、授权范围与依据严格校验；
 *   · link_summary 禁止复制全文（body_html 必须 NULL）；
 *   · updateDraft 只改内容字段、draft-only、expectedFingerprint CAS；
 *   · sources 传数组即整组替换，传 NULL 保持原来源；
 *   · 本批 media_manifest_hash 必须 NULL，sanitizer_version 固定占位值；
 *   · 非 draft 后服务与模型双层拒绝内容 / 来源修改。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-05 content revision service tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g5RevService(): ContentRevisionService
{
    return app(ContentRevisionService::class);
}

function g5RevFingerprint(): ContentFingerprint
{
    return app(ContentFingerprint::class);
}

function g5RevAdminId(): int
{
    return DB::table('admins')->insertGetId([
        'name'       => 'G5 Revision Editor',
        'email'      => 'g5-revision-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g5RevPost(?int $adminId = null): ContentPost
{
    return app(ContentPostService::class)->createDraft($adminId ?? g5RevAdminId(), [
        'type' => 'news',
        'slug' => 'g5-revision-'.bin2hex(random_bytes(5)),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g5RevPayload(array $overrides = []): array
{
    return array_merge([
        'title'          => 'G5 资讯标题',
        'summary'        => 'G5 摘要',
        'body_html'      => '<p>G5 正文</p>',
        'author_name'    => '张三',
        'author_org'     => '召唤机甲',
        'source_type'    => 'original',
        'change_summary' => '初始版本',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g5RevSource(array $overrides = []): array
{
    return array_merge([
        'source_name'        => '示例来源',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
    ], $overrides);
}

function g5RevCurrentFingerprint(ContentRevision $revision): string
{
    return g5RevFingerprint()->contentRevision(
        $revision->fresh(),
        $revision->sources()->orderBy('sort_order')->get()->all(),
    );
}

it('G1-05 · createDraft 生成 revision_no 1/2，强制 draft / 占位净化 / 空媒体', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $first = $service->createDraft($post, $adminId, g5RevPayload());
    $second = $service->createDraft($post, $adminId, g5RevPayload([
        'title'          => 'G5 资讯标题 v2',
        'change_summary' => '第二版',
    ]));

    expect((int) $first->revision_no)->toBe(1)
        ->and((int) $second->revision_no)->toBe(2)
        ->and((string) $first->review_state)->toBe('draft')
        ->and((int) $first->created_by)->toBe($adminId)
        ->and($first->media_manifest_hash)->toBeNull()
        ->and((string) $first->sanitizer_version)->toBe((string) config('ecosystem_content.sanitizer_placeholder_version'))
        ->and(strlen((string) $first->content_fingerprint))->toBe(64);

    expect(g5RevCurrentFingerprint($first))->toBe((string) $first->content_fingerprint);
});

it('G1-05 · 指纹包含来源：同正文不同来源指纹不同，同输入指纹稳定', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $noSources = $service->createDraft($post, $adminId, g5RevPayload());
    $withSource = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $same = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $otherSource = $service->createDraft($post, $adminId, g5RevPayload(), [
        g5RevSource(['source_name' => '另一个来源']),
    ]);
    $changedBody = $service->createDraft($post, $adminId, g5RevPayload([
        'body_html' => '<p>换过的正文</p>',
    ]), [g5RevSource()]);

    expect((string) $withSource->content_fingerprint)->not->toBe((string) $noSources->content_fingerprint)
        ->and((string) $same->content_fingerprint)->toBe((string) $withSource->content_fingerprint)
        ->and((string) $otherSource->content_fingerprint)->not->toBe((string) $withSource->content_fingerprint)
        ->and((string) $changedBody->content_fingerprint)->not->toBe((string) $withSource->content_fingerprint);

    expect(g5RevCurrentFingerprint($withSource))->toBe((string) $withSource->content_fingerprint)
        ->and(g5RevCurrentFingerprint($otherSource))->toBe((string) $otherSource->content_fingerprint);
});

it('G1-05 · 三种 source_type 与 rights_basis 的合法组合被接受', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $cases = [
        ['original', 'platform_original'],
        ['authorized_repost', 'written_repost_permission'],
        ['link_summary', 'official_link_only'],
        ['link_summary', 'public_document_summary'],
    ];

    foreach ($cases as [$sourceType, $rightsBasis]) {
        $payload = g5RevPayload(['source_type' => $sourceType]);

        if ($sourceType === 'link_summary') {
            $payload['body_html'] = null;
        }

        $source = g5RevSource([
            'rights_basis' => $rightsBasis,
            'rights_scope' => $sourceType === 'authorized_repost' ? '正文 / 图片' : null,
            'evidence_ref' => $sourceType === 'authorized_repost' ? 'contract-2026-001' : null,
        ]);

        $revision = $service->createDraft($post, $adminId, $payload, [$source]);

        expect((string) $revision->source_type)->toBe($sourceType)
            ->and((string) $revision->sources()->first()->rights_basis)->toBe($rightsBasis);
    }
});

it('G1-05 · source_type 与 rights_basis 不匹配时拒绝（三种类型各一例）', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $mismatches = [
        ['original', 'written_repost_permission'],
        ['authorized_repost', 'platform_original'],
        ['link_summary', 'platform_original'],
    ];

    foreach ($mismatches as [$sourceType, $rightsBasis]) {
        $payload = g5RevPayload(['source_type' => $sourceType]);

        if ($sourceType === 'link_summary') {
            $payload['body_html'] = null;
        }

        $source = g5RevSource([
            'rights_basis' => $rightsBasis,
            'rights_scope' => '正文',
            'evidence_ref' => 'contract-2026-001',
        ]);

        expect(fn () => $service->createDraft($post, $adminId, $payload, [$source]))
            ->toThrow(ContentSourceValidationException::class);
    }
});

it('G1-05 · link_summary 禁止正文，body_html 必须 NULL', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $source = g5RevSource(['rights_basis' => 'official_link_only']);

    expect(fn () => $service->createDraft(
        $post,
        $adminId,
        g5RevPayload([
            'source_type' => 'link_summary',
            'body_html'   => '<p>试图复制全文</p>',
        ]),
        [$source],
    ))->toThrow(ContentSourceValidationException::class);

    // 切换 source_type 到 link_summary 时，沿用旧正文同样必须拒绝。
    $revision = $service->createDraft($post, $adminId, g5RevPayload());

    expect(fn () => $service->updateDraft(
        $revision,
        (string) $revision->content_fingerprint,
        ['source_type' => 'link_summary'],
        [$source],
    ))->toThrow(ContentSourceValidationException::class);
});

it('G1-05 · authorized_repost 必须有 source_url / rights_scope / evidence_ref', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();
    $payload = g5RevPayload(['source_type' => 'authorized_repost']);

    $valid = [
        'rights_basis' => 'written_repost_permission',
        'rights_scope' => '正文 / 图片',
        'evidence_ref' => 'contract-2026-001',
    ];

    $missingUrl = g5RevSource(array_merge($valid, ['source_url' => null]));
    $missingScope = g5RevSource(array_merge($valid, ['rights_scope' => null]));
    $missingEvidence = g5RevSource(array_merge($valid, ['evidence_ref' => null]));

    expect(fn () => $service->createDraft($post, $adminId, $payload, [$missingUrl]))
        ->toThrow(ContentSourceValidationException::class)
        ->and(fn () => $service->createDraft($post, $adminId, $payload, [$missingScope]))
        ->toThrow(ContentSourceValidationException::class)
        ->and(fn () => $service->createDraft($post, $adminId, $payload, [$missingEvidence]))
        ->toThrow(ContentSourceValidationException::class);
});

it('G1-05 · source_verified_at 必填且不得晚于当前时间', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $missing = g5RevSource(['source_verified_at' => null]);
    $future = g5RevSource(['source_verified_at' => Carbon::now('UTC')->addDay()]);

    expect(fn () => $service->createDraft($post, $adminId, g5RevPayload(), [$missing]))
        ->toThrow(ContentSourceValidationException::class)
        ->and(fn () => $service->createDraft($post, $adminId, g5RevPayload(), [$future]))
        ->toThrow(ContentSourceValidationException::class);

    // 过去时间合法，且库内按 UTC 读回。
    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $stored = $revision->sources()->first()->source_verified_at;

    expect($stored->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 10:00:00');
});

it('G1-05 · 来源名必填且不超过 191 字符；URL 走 EventSourceValidator 校验', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $invalidSources = [
        g5RevSource(['source_name' => '']),
        g5RevSource(['source_name' => str_repeat('来', 192)]),
        g5RevSource(['source_url' => 'javascript:alert(1)']),
        g5RevSource(['source_url' => 'ftp://example.com/file']),
        g5RevSource(['source_url' => 'http://127.0.0.1/private']),
        g5RevSource(['source_url' => 'http://localhost/private']),
        g5RevSource(['source_url' => 'https://exa mple.com/news']),
    ];

    foreach ($invalidSources as $source) {
        expect(fn () => $service->createDraft($post, $adminId, g5RevPayload(), [$source]))
            ->toThrow(ContentSourceValidationException::class);
    }

    // 191 字符边界与 http/https 合法 URL 被接受。
    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [
        g5RevSource([
            'source_name' => str_repeat('来', 191),
            'source_url'  => 'http://example.com/ok',
        ]),
    ]);

    expect(mb_strlen((string) $revision->sources()->first()->source_name))->toBe(191);

    // original 草稿允许空 URL。
    $noUrl = $service->createDraft($post, $adminId, g5RevPayload(), [
        g5RevSource(['source_url' => null]),
    ]);

    expect($noUrl->sources()->first()->source_url)->toBeNull();
});

it('G1-05 · sort_order 由服务端按输入顺序重排，来源审计字段不可透传', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [
        g5RevSource(['source_name' => '第一条', 'sort_order' => 99]),
        g5RevSource(['source_name' => '第二条', 'sort_order' => 5]),
    ]);

    $rows = DB::table('content_sources')
        ->where('revision_id', $revision->getKey())
        ->orderBy('sort_order')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->source_name)->toBe('第一条')
        ->and((int) $rows[0]->sort_order)->toBe(0)
        ->and($rows[1]->source_name)->toBe('第二条')
        ->and((int) $rows[1]->sort_order)->toBe(1);

    foreach (['id' => 1, 'revision_id' => 1, 'created_at' => now()] as $field => $value) {
        $source = array_merge(g5RevSource(), [$field => $value]);

        expect(fn () => $service->createDraft($post, $adminId, g5RevPayload(), [$source]))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('G1-05 · draft 允许 0 条来源', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);

    $revision = g5RevService()->createDraft($post, $adminId, g5RevPayload(), []);

    expect($revision->sources()->count())->toBe(0)
        ->and((string) $revision->review_state)->toBe('draft');
});

it('G1-05 · updateDraft 改正文后重算指纹', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $originalFingerprint = (string) $revision->content_fingerprint;

    $updated = $service->updateDraft($revision, $originalFingerprint, [
        'body_html' => '<p>修改后的正文</p>',
    ]);

    expect((string) $updated->content_fingerprint)->not->toBe($originalFingerprint)
        ->and((int) $updated->revision_no)->toBe(1)
        ->and(g5RevCurrentFingerprint($updated))->toBe((string) $updated->content_fingerprint)
        ->and((string) $updated->fresh()->body_html)->toBe('<p>修改后的正文</p>');
});

it('G1-05 · updateDraft 传 sources 时整组替换并重算指纹', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $oldIds = DB::table('content_sources')->where('revision_id', $revision->getKey())->pluck('id')->all();
    $originalFingerprint = (string) $revision->content_fingerprint;

    $updated = $service->updateDraft($revision, $originalFingerprint, [], [
        g5RevSource(['source_name' => '替换来源 A', 'sort_order' => 7]),
        g5RevSource(['source_name' => '替换来源 B', 'sort_order' => 2]),
    ]);

    $rows = DB::table('content_sources')
        ->where('revision_id', $revision->getKey())
        ->orderBy('sort_order')
        ->get();

    expect((string) $updated->content_fingerprint)->not->toBe($originalFingerprint)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->source_name)->toBe('替换来源 A')
        ->and((int) $rows[0]->sort_order)->toBe(0)
        ->and($rows[1]->source_name)->toBe('替换来源 B')
        ->and((int) $rows[1]->sort_order)->toBe(1)
        ->and(array_intersect($oldIds, $rows->pluck('id')->all()))->toBe([])
        ->and(g5RevCurrentFingerprint($updated))->toBe((string) $updated->content_fingerprint);
});

it('G1-05 · updateDraft sources 为 NULL 时保留原来源', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $sourceIds = DB::table('content_sources')->where('revision_id', $revision->getKey())->pluck('id')->all();

    $updated = $service->updateDraft($revision, (string) $revision->content_fingerprint, [
        'summary' => '只改摘要',
    ]);

    expect(DB::table('content_sources')->where('revision_id', $revision->getKey())->pluck('id')->all())
        ->toBe($sourceIds)
        ->and((string) $updated->content_fingerprint)->not->toBe((string) $revision->content_fingerprint)
        ->and(g5RevCurrentFingerprint($updated))->toBe((string) $updated->content_fingerprint);
});

it('G1-05 · 改 source_type 但沿用旧来源时，权利依据不匹配被拒绝', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);

    expect(fn () => $service->updateDraft(
        $revision,
        (string) $revision->content_fingerprint,
        ['source_type' => 'authorized_repost'],
    ))->toThrow(ContentSourceValidationException::class);
});

it('G1-05 · 过期指纹抛 CONTENT_REVISION_CONFLICT', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload());
    $staleFingerprint = (string) $revision->content_fingerprint;

    $service->updateDraft($revision, $staleFingerprint, ['summary' => '第一次修改']);

    try {
        $service->updateDraft($revision, $staleFingerprint, ['summary' => '第二次修改']);
        $this->fail('过期指纹应抛出 ContentRevisionFingerprintConflictException。');
    } catch (ContentRevisionFingerprintConflictException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_REVISION_CONFLICT)
            ->and($exception->revisionId)->toBe((int) $revision->getKey())
            ->and($exception->expectedFingerprint)->toBe($staleFingerprint);
    }
});

it('G1-05 · 非 draft revision 服务与模型都拒绝内容修改', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload());
    $fingerprint = (string) $revision->content_fingerprint;

    DB::table('content_revisions')->where('id', $revision->getKey())->update(['review_state' => 'in_review']);

    expect(fn () => $service->updateDraft($revision, $fingerprint, ['summary' => '提审后修改']))
        ->toThrow(ContentTransitionException::class);

    $model = ContentRevision::query()->findOrFail($revision->getKey());
    $model->title = '提审后标题';

    expect(fn () => $model->save())->toThrow(ContentTransitionException::class)
        ->and(fn () => $model->delete())->toThrow(ContentTransitionException::class);
});

it('G1-05 · 非 draft revision 的来源记录不可修改 / 删除 / 新增', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, g5RevPayload(), [g5RevSource()]);
    $source = ContentSource::query()->where('revision_id', $revision->getKey())->firstOrFail();

    DB::table('content_revisions')->where('id', $revision->getKey())->update(['review_state' => 'in_review']);

    $source->refresh();
    $source->source_name = '提审后改名';
    expect(fn () => $source->save())->toThrow(ContentTransitionException::class);

    $source->refresh();
    expect(fn () => $source->delete())->toThrow(ContentTransitionException::class);

    expect(fn () => ContentSource::create([
        'revision_id'        => $revision->getKey(),
        'source_name'        => '提审后新增',
        'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
        'sort_order'         => 9,
    ]))->toThrow(ContentTransitionException::class);
});

it('G1-05 · media_manifest_hash 本批强制 NULL', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $revision = $service->createDraft($post, $adminId, array_merge(g5RevPayload(), [
        'media_manifest_hash' => null,
    ]));
    expect($revision->fresh()->media_manifest_hash)->toBeNull();

    expect(fn () => $service->createDraft($post, $adminId, array_merge(g5RevPayload(), [
        'media_manifest_hash' => str_repeat('a', 64),
    ])))->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->updateDraft($revision, (string) $revision->content_fingerprint, [
        'media_manifest_hash' => str_repeat('b', 64),
    ]))->toThrow(InvalidArgumentException::class);
});

it('G1-05 · sanitizer_version 固定为配置占位值', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();
    $placeholder = (string) config('ecosystem_content.sanitizer_placeholder_version');

    $revision = $service->createDraft($post, $adminId, array_merge(g5RevPayload(), [
        'sanitizer_version' => $placeholder,
    ]));

    expect((string) $revision->fresh()->sanitizer_version)->toBe($placeholder)
        ->and((string) DB::table('content_revisions')->where('id', $revision->getKey())->value('sanitizer_version'))
        ->toBe($placeholder);

    expect(fn () => $service->createDraft($post, $adminId, array_merge(g5RevPayload(), [
        'sanitizer_version' => 'v1',
    ])))->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->updateDraft($revision, (string) $revision->content_fingerprint, [
        'sanitizer_version' => 'v1',
    ]))->toThrow(InvalidArgumentException::class);
});

it('G1-05 · 调用方不得传审核 / 指纹 / 版本 / 归属字段', function () {
    $adminId = g5RevAdminId();
    $post = g5RevPost($adminId);
    $service = g5RevService();

    $forbidden = [
        'review_state'        => 'in_review',
        'content_fingerprint' => str_repeat('f', 64),
        'revision_no'         => 9,
        'created_by'          => 1,
        'post_id'             => (int) $post->getKey(),
    ];

    foreach ($forbidden as $field => $value) {
        expect(fn () => $service->createDraft($post, $adminId, array_merge(g5RevPayload(), [$field => $value])))
            ->toThrow(InvalidArgumentException::class);
    }

    $revision = $service->createDraft($post, $adminId, g5RevPayload());

    foreach ($forbidden as $field => $value) {
        expect(fn () => $service->updateDraft(
            $revision,
            (string) $revision->content_fingerprint,
            [$field => $value],
        ))->toThrow(InvalidArgumentException::class);
    }
});

it('G1-05 · category 按 UTF-8 字符计数：64 接受，65 拒绝', function () {
    $adminId = g5RevAdminId();
    $service = app(ContentPostService::class);

    $category64 = str_repeat('机', 64);
    $post = $service->createDraft($adminId, [
        'type'     => 'news',
        'slug'     => 'g5-category-'.bin2hex(random_bytes(5)),
        'category' => $category64,
    ]);

    expect($post->category)->toBe($category64)
        ->and(mb_strlen((string) $post->category))->toBe(64);

    expect(fn () => $service->createDraft($adminId, [
        'type'     => 'news',
        'slug'     => 'g5-category-'.bin2hex(random_bytes(5)),
        'category' => str_repeat('机', 65),
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->updateDraft($post, 0, $adminId, [
        'category' => str_repeat('机', 65),
    ]))->toThrow(InvalidArgumentException::class);
});
