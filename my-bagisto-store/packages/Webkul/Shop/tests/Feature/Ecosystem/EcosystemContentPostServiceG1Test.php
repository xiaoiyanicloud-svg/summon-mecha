<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\ContentSource;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\ContentSlugTakenException;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;

/**
 * G1-05 · ContentPostService / 内容模型守卫（ADR-0001 §4.6 / §4.7 / §4.8 / G1-D06）。
 *
 * 保护的是**文章壳与不可变边界**：
 *   · 创建强制 draft / normal / row_version=0，服务端字段不可透传；
 *   · slug 字符集、长度、首尾连字符、保留前缀、全局唯一（含撤稿保留）；
 *   · updateDraft 只改 type / slug / category，draft-only，行锁 + row_version CAS；
 *   · no-op 不推版本；并发冲突与幂等短路是两种结果；
 *   · 首次发布后 slug 冻结；
 *   · 文章 / revision 禁止删除；来源仅在父 revision 为 draft 时允许替换；
 *   · published 指针本批拒绝，working 指针必须同文章且 draft。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-05 content post service tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g5SvcService(): ContentPostService
{
    return app(ContentPostService::class);
}

function g5SvcAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G5 Content Editor',
        'email'      => 'g5-content-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g5SvcRevision(int $postId, array $overrides = []): int
{
    return (int) DB::table('content_revisions')->insertGetId(array_merge([
        'post_id'             => $postId,
        'revision_no'         => 1,
        'title'               => 'G5 内容修订',
        'author_name'         => 'G5 作者',
        'source_type'         => 'original',
        'change_summary'      => '初始版本',
        'content_fingerprint' => str_repeat('c', 64),
        'sanitizer_version'   => (string) config('ecosystem_content.sanitizer_placeholder_version'),
        'review_state'        => 'draft',
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ], $overrides));
}

function g5SvcSlug(): string
{
    return 'g5-post-'.bin2hex(random_bytes(5));
}

it('G1-05 · createDraft 强制 draft / normal / row_version=0 与服务端审计字段', function () {
    $adminId = g5SvcAdminId();
    $slug = g5SvcSlug();

    $post = g5SvcService()->createDraft($adminId, [
        'type'     => 'news',
        'slug'     => $slug,
        'category' => '行业动态',
    ]);

    $row = DB::table('content_posts')->where('id', $post->getKey())->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe('news')
        ->and($row->slug)->toBe($slug)
        ->and($row->category)->toBe('行业动态')
        ->and($row->publish_status)->toBe('draft')
        ->and($row->disposition)->toBe('normal')
        ->and((int) $row->row_version)->toBe(0)
        ->and($row->published_revision_id)->toBeNull()
        ->and($row->working_revision_id)->toBeNull()
        ->and($row->first_published_at)->toBeNull()
        ->and($row->published_at)->toBeNull()
        ->and((int) $row->created_by)->toBe($adminId)
        ->and((int) $row->updated_by)->toBe($adminId);
});

it('G1-05 · createDraft 拒绝无效 admin / type / 越权字段', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();

    expect(fn () => $service->createDraft(0, ['type' => 'news', 'slug' => g5SvcSlug()]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->createDraft(999999999, ['type' => 'news', 'slug' => g5SvcSlug()]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->createDraft($adminId, ['type' => 'blog', 'slug' => g5SvcSlug()]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->createDraft($adminId, ['slug' => g5SvcSlug()]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->createDraft($adminId, ['type' => 'news']))
        ->toThrow(InvalidArgumentException::class);

    // 发布轴 / 指针 / 审计字段不得透传。
    expect(fn () => $service->createDraft($adminId, [
        'type'           => 'news',
        'slug'           => g5SvcSlug(),
        'publish_status' => 'published',
    ]))->toThrow(InvalidArgumentException::class);
});

it('G1-05 · slug 合法值被接受（3 / 120 边界与常见形态）', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();

    foreach (['abc', 'a1b', 'news-2026', 'robot-ecosystem-news', str_repeat('a', 120)] as $slug) {
        $post = $service->createDraft($adminId, ['type' => 'news', 'slug' => $slug]);

        expect($post->slug)->toBe($slug);
    }
});

it('G1-05 · slug 非法值被拒绝（长度 / 字符集 / 首尾连字符 / 保留前缀）', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();

    $invalid = [
        'ab',                        // 少于 3
        str_repeat('a', 121),        // 超过 120
        'News-2026',                 // 大写不自动转写
        'news_2026',                 // 下划线
        'news 2026',                 // 空格
        '机器人新闻',                 // 中文不自动转拼音
        ' news',                     // 前导空格
        'news ',                     // 尾随空格
        '-news',                     // 首连字符
        'news-',                     // 尾连字符
        'api',                       // 保留词
        'api-news',                  // 保留前缀
        'admin',
        'admin-dashboard',
        'cms',
        'cms-page',
        'content',
        'content-post',
        'media',
        'media-asset',
    ];

    foreach ($invalid as $slug) {
        $thrown = null;

        try {
            $service->createDraft($adminId, ['type' => 'news', 'slug' => $slug]);
        } catch (InvalidArgumentException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull("slug「{$slug}」应被拒绝。");
    }
});

it('G1-05 · slug 全局唯一，撤稿 slug 永久保留', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();
    $slug = g5SvcSlug();

    $service->createDraft($adminId, ['type' => 'news', 'slug' => $slug]);

    try {
        $service->createDraft($adminId, ['type' => 'announcement', 'slug' => $slug]);
        $this->fail('重复 slug 应抛出 ContentSlugTakenException。');
    } catch (ContentSlugTakenException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_SLUG_TAKEN)
            ->and($exception->slug)->toBe($slug);
    }

    // 撤稿不删除记录；slug 仍被占用。
    DB::table('content_posts')->where('slug', $slug)->update(['disposition' => 'retracted']);

    expect(fn () => $service->createDraft($adminId, ['type' => 'news', 'slug' => $slug]))
        ->toThrow(ContentSlugTakenException::class);
});

it('G1-05 · updateDraft 更新 type / slug / category 并递增 row_version', function () {
    $service = g5SvcService();
    $admin1 = g5SvcAdminId();
    $admin2 = g5SvcAdminId();

    $post = $service->createDraft($admin1, [
        'type'     => 'news',
        'slug'     => g5SvcSlug(),
        'category' => '旧分类',
    ]);

    $newSlug = g5SvcSlug();

    $updated = $service->updateDraft($post, 0, $admin2, [
        'type'     => 'case',
        'slug'     => $newSlug,
        'category' => '新分类',
    ]);

    $row = DB::table('content_posts')->where('id', $post->getKey())->first();

    expect($row->type)->toBe('case')
        ->and($row->slug)->toBe($newSlug)
        ->and($row->category)->toBe('新分类')
        ->and((int) $row->row_version)->toBe(1)
        ->and((int) $row->updated_by)->toBe($admin2)
        ->and($updated->row_version)->toBe(1);
});

it('G1-05 · updateDraft 只允许 type / slug / category，拒绝越权字段与无效 admin', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();
    $post = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);

    expect(fn () => $service->updateDraft($post, 0, $adminId, ['first_published_at' => now()]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->updateDraft($post, 0, $adminId, ['published_revision_id' => 1]))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->updateDraft($post, 0, 0, ['category' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});

it('G1-05 · updateDraft row_version 不匹配时抛 CONTENT_REVISION_CONFLICT', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();
    $post = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);

    $service->updateDraft($post, 0, $adminId, ['category' => '第一次修改']);

    try {
        $service->updateDraft($post, 0, $adminId, ['category' => '过期修改']);
        $this->fail('过期 row_version 应抛出 ContentRevisionConflictException。');
    } catch (ContentRevisionConflictException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_REVISION_CONFLICT)
            ->and($exception->postId)->toBe((int) $post->getKey())
            ->and($exception->expectedRowVersion)->toBe(0)
            ->and($exception->actualRowVersion)->toBe(1);
    }
});

it('G1-05 · updateDraft no-op 不推版本、不改 updated_by', function () {
    $service = g5SvcService();
    $admin1 = g5SvcAdminId();
    $admin2 = g5SvcAdminId();
    $slug = g5SvcSlug();

    $post = $service->createDraft($admin1, [
        'type'     => 'news',
        'slug'     => $slug,
        'category' => '分类',
    ]);

    $returned = $service->updateDraft($post, 0, $admin2, [
        'type'     => 'news',
        'slug'     => $slug,
        'category' => '分类',
    ]);

    $row = DB::table('content_posts')->where('id', $post->getKey())->first();

    expect((int) $row->row_version)->toBe(0)
        ->and((int) $row->updated_by)->toBe($admin1)
        ->and((int) $returned->row_version)->toBe(0);
});

it('G1-05 · 非 draft 文章拒绝编辑', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();
    $post = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);

    DB::table('content_posts')->where('id', $post->getKey())->update(['publish_status' => 'in_review']);

    try {
        $service->updateDraft($post, 0, $adminId, ['category' => '不应写入']);
        $this->fail('非 draft 文章应拒绝编辑。');
    } catch (ContentTransitionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_TRANSITION_INVALID);
    }
});

it('G1-05 · 首次发布后 slug 冻结，其他壳字段仍可编辑', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();
    $slug = g5SvcSlug();
    $post = $service->createDraft($adminId, ['type' => 'news', 'slug' => $slug]);

    DB::table('content_posts')->where('id', $post->getKey())->update(['first_published_at' => now()]);

    expect(fn () => $service->updateDraft($post, 0, $adminId, ['slug' => g5SvcSlug()]))
        ->toThrow(ContentTransitionException::class);

    $updated = $service->updateDraft($post, 0, $adminId, ['category' => '发布后仍可改分类']);
    $row = DB::table('content_posts')->where('id', $post->getKey())->first();

    expect($row->slug)->toBe($slug)
        ->and($row->category)->toBe('发布后仍可改分类')
        ->and((int) $row->row_version)->toBe(1)
        ->and($updated->slug)->toBe($slug);

    // 模型守卫也必须兜底，不能只依赖服务。
    $model = ContentPost::query()->findOrFail($post->getKey());
    $model->slug = 'g5-model-'.bin2hex(random_bytes(4));

    expect(fn () => $model->save())->toThrow(ContentTransitionException::class);
});

it('G1-05 · 更新到已被占用的 slug 抛 CONTENT_SLUG_TAKEN', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();

    $first = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);
    $second = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);

    expect(fn () => $service->updateDraft($second, 0, $adminId, ['slug' => $first->slug]))
        ->toThrow(ContentSlugTakenException::class);
});

it('G1-05 · 文章禁止删除，published 指针本批拒绝、working 指针必须同文章且 draft', function () {
    $service = g5SvcService();
    $adminId = g5SvcAdminId();

    $post = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);
    $otherPost = $service->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);

    $draftRevisionId = g5SvcRevision((int) $post->getKey());

    // published 指针：G1-05 任何非 NULL 写入都拒绝（G1-08 才开放）。
    $model = ContentPost::query()->findOrFail($post->getKey());
    $model->published_revision_id = $draftRevisionId;

    expect(fn () => $model->save())->toThrow(ContentTransitionException::class);

    // working 指针：同文章 draft 允许。
    $model->refresh();
    $model->working_revision_id = $draftRevisionId;
    $model->save();

    expect((int) DB::table('content_posts')->where('id', $post->getKey())->value('working_revision_id'))
        ->toBe($draftRevisionId);

    // 跨文章 revision 拒绝。
    $model->refresh();
    $model->working_revision_id = g5SvcRevision((int) $otherPost->getKey());
    expect(fn () => $model->save())->toThrow(ContentTransitionException::class);

    // 非 draft revision 拒绝。
    $nonDraftRevisionId = g5SvcRevision((int) $post->getKey(), ['revision_no' => 2]);
    DB::table('content_revisions')->where('id', $nonDraftRevisionId)->update(['review_state' => 'in_review']);

    $model->refresh();
    $model->working_revision_id = $nonDraftRevisionId;
    expect(fn () => $model->save())->toThrow(ContentTransitionException::class);

    // 审计对象禁止删除。
    $model->refresh();
    expect(fn () => $model->delete())->toThrow(ContentTransitionException::class);
});

it('G1-05 · ContentRevision：非 draft 内容冻结、身份列冻结、禁止删除', function () {
    $adminId = g5SvcAdminId();
    $post = g5SvcService()->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);
    $revisionId = g5SvcRevision((int) $post->getKey());

    $revision = ContentRevision::query()->findOrFail($revisionId);
    $revision->title = '草稿阶段可改';
    $revision->save();

    // draft -> in_review 本身是合法流转。
    $revision->review_state = 'in_review';
    $revision->submitted_by = $adminId;
    $revision->submitted_at = now();
    $revision->save();

    // 提审后内容冻结。
    $revision->title = '提审后不应可改';
    expect(fn () => $revision->save())->toThrow(ContentTransitionException::class);

    $revision->refresh();
    expect($revision->title)->toBe('草稿阶段可改');

    // 身份列永远冻结。
    $revision->revision_no = 2;
    expect(fn () => $revision->save())->toThrow(ContentTransitionException::class);

    $revision->refresh();
    $revision->post_id = (int) $post->getKey() + 1;
    expect(fn () => $revision->save())->toThrow(ContentTransitionException::class);

    $revision->refresh();
    $revision->created_by = 99;
    expect(fn () => $revision->save())->toThrow(ContentTransitionException::class);

    expect(fn () => $revision->delete())->toThrow(ContentTransitionException::class);
});

it('G1-05 · ContentSource：父 revision 为 draft 时可增删改，非 draft 时全部冻结', function () {
    $adminId = g5SvcAdminId();
    $post = g5SvcService()->createDraft($adminId, ['type' => 'news', 'slug' => g5SvcSlug()]);
    $revisionId = g5SvcRevision((int) $post->getKey());

    $source = ContentSource::create([
        'revision_id'        => $revisionId,
        'source_name'        => 'G5 来源',
        'source_url'         => 'https://example.com/source',
        'source_verified_at' => now(),
        'rights_basis'       => 'platform_original',
        'sort_order'         => 0,
    ]);

    expect($source->exists)->toBeTrue()
        ->and($source->revision_id)->toBe($revisionId);

    // draft 期允许修改与替换。
    $source->source_name = 'G5 来源（草稿修正）';
    $source->save();
    expect($source->refresh()->source_name)->toBe('G5 来源（草稿修正）');

    $replacement = ContentSource::create([
        'revision_id'        => $revisionId,
        'source_name'        => 'G5 替换来源',
        'source_verified_at' => now(),
        'rights_basis'       => 'platform_original',
        'sort_order'         => 1,
    ]);

    expect($replacement->delete())->toBeTrue();

    // 父 revision 离开 draft 后，新增 / 修改 / 删除全部拒绝。
    DB::table('content_revisions')->where('id', $revisionId)->update(['review_state' => 'in_review']);

    expect(fn () => ContentSource::create([
        'revision_id'        => $revisionId,
        'source_name'        => '提审后新增',
        'source_verified_at' => now(),
        'rights_basis'       => 'platform_original',
        'sort_order'         => 2,
    ]))->toThrow(ContentTransitionException::class);

    $source->refresh();
    $source->source_name = '提审后修改';
    expect(fn () => $source->save())->toThrow(ContentTransitionException::class);

    $source->refresh();
    expect(fn () => $source->delete())->toThrow(ContentTransitionException::class);
});
