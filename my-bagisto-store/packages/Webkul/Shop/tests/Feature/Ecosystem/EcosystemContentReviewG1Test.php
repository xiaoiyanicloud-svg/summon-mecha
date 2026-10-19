<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionConflictException;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;

/**
 * G1-08A · 内容提交 / 批准 / 拒绝（ADR-0001 附录 B A-04/A-06）。
 *
 * 覆盖：
 *   · draft -> in_review -> approved / rejected；
 *   · 至少一条来源、来源完整、指纹包含有序来源；
 *   · 提交后 revision / 来源不可改；
 *   · stale row_version 稳定冲突；
 *   · B-04/B-07 未签署时仍可演练 submit/approve，但发布开关保持 false。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08A content review tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g8CntAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8 Content Reviewer',
        'email'      => 'g8-content-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g8CntPost(int $adminId): ContentPost
{
    return app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8-content-'.bin2hex(random_bytes(5)),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8CntPayload(array $overrides = []): array
{
    return array_merge([
        'title'          => 'G8 资讯标题',
        'summary'        => 'G8 摘要',
        'body_html'      => '<p>G8 正文</p>',
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
function g8CntSource(array $overrides = []): array
{
    return array_merge([
        'source_name'        => '平台官网',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<int, array<string, mixed>>|null  $sources
 */
function g8CntDraft(
    ContentPost $post,
    int $adminId,
    array $overrides = [],
    ?array $sources = null,
): ContentRevision {
    return app(ContentRevisionService::class)->createDraft(
        $post,
        $adminId,
        g8CntPayload($overrides),
        $sources ?? [g8CntSource()],
    );
}

function g8CntReview(): ContentReviewService
{
    return app(ContentReviewService::class);
}

it('G1-08A · 内容 draft -> in_review -> approved；发布开关仍为 false', function () {
    $adminId = g8CntAdminId();
    $post = g8CntPost($adminId);
    $revision = g8CntDraft($post, $adminId);

    // 明确挂 working 指针，验证提交后清空。
    $post->working_revision_id = (int) $revision->getKey();
    $post->save();

    expect(config('ecosystem_publishing.content_publish_enabled'))->toBeFalse();

    $post = g8CntReview()->submit($post, $revision, $adminId, 0);
    $revision->refresh();

    expect((string) $post->publish_status)->toBe('in_review')
        ->and($post->working_revision_id)->toBeNull()
        ->and($post->approved_revision_id)->toBeNull()
        ->and($post->scheduled_publish_at)->toBeNull()
        ->and((int) $post->row_version)->toBe(1)
        ->and((string) $revision->review_state)->toBe('in_review')
        ->and((int) $revision->submitted_by)->toBe($adminId);

    $post = g8CntReview()->approve($post, $revision, $adminId, 1, '来源与正文已核对');
    $revision->refresh();

    expect((string) $post->publish_status)->toBe('approved')
        ->and((int) $post->approved_revision_id)->toBe((int) $revision->getKey())
        ->and($post->published_revision_id)->toBeNull()
        ->and($post->scheduled_publish_at)->toBeNull()
        ->and((string) $revision->review_state)->toBe('approved')
        ->and((string) $revision->review_note)->toBe('来源与正文已核对');
});

it('G1-08A · 内容提交要求至少一条来源', function () {
    $adminId = g8CntAdminId();
    $post = g8CntPost($adminId);
    $revision = g8CntDraft($post, $adminId, [], []);

    try {
        g8CntReview()->submit($post, $revision, $adminId, 0);
        $this->fail('没有来源的内容 revision 不应允许提交。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_SOURCE_REQUIRED);
    }

    expect((string) $post->fresh()->publish_status)->toBe('draft')
        ->and((string) $revision->fresh()->review_state)->toBe('draft');
});

it('G1-08A · 内容 in_review 后正文与来源不可修改', function () {
    $adminId = g8CntAdminId();
    $post = g8CntPost($adminId);
    $revision = g8CntDraft($post, $adminId);

    $post = g8CntReview()->submit($post, $revision, $adminId, 0);
    $revision->refresh();

    expect(fn () => app(ContentRevisionService::class)->updateDraft(
        $revision,
        (string) $revision->content_fingerprint,
        ['title' => '审核中被改掉的标题'],
    ))->toThrow(ContentTransitionException::class);
});

it('G1-08A · 内容拒绝回到 draft，原 revision 变为 rejected', function () {
    $adminId = g8CntAdminId();
    $post = g8CntPost($adminId);
    $revision = g8CntDraft($post, $adminId);

    $post = g8CntReview()->submit($post, $revision, $adminId, 0);
    $post = g8CntReview()->reject($post, $revision->fresh(), $adminId, 1, '来源核验时间缺失');
    $revision->refresh();

    expect((string) $post->publish_status)->toBe('draft')
        ->and($post->approved_revision_id)->toBeNull()
        ->and((string) $revision->review_state)->toBe('rejected')
        ->and((string) $revision->review_note)->toBe('来源核验时间缺失');

    expect(fn () => g8CntReview()->approve($post, $revision, $adminId, 2))
        ->toThrow(PublicationTransitionException::class);
});

it('G1-08A · 内容 stale row_version 返回 CONTENT_REVISION_CONFLICT', function () {
    $adminId = g8CntAdminId();
    $post = g8CntPost($adminId);
    $revision = g8CntDraft($post, $adminId);

    $post = g8CntReview()->submit($post, $revision, $adminId, 0);

    try {
        g8CntReview()->approve($post, $revision, $adminId, 0);
        $this->fail('stale row_version 不应允许批准。');
    } catch (ContentRevisionConflictException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_REVISION_CONFLICT);
    }

    expect((string) $post->fresh()->publish_status)->toBe('in_review')
        ->and((string) $revision->fresh()->review_state)->toBe('in_review');
});

it('G1-08A · 已发布文章拒绝新 revision：公开指针不变，approved / scheduled 清空', function () {
    $adminId = g8CntAdminId();
    $post = g8CntPost($adminId);
    $rev1 = g8CntDraft($post, $adminId);

    DB::table('content_revisions')->where('id', $rev1->id)->update([
        'review_state' => 'approved',
        'published_at' => now(),
    ]);
    DB::table('content_posts')->where('id', $post->id)->update([
        'publish_status'        => 'published',
        'published_revision_id' => (int) $rev1->id,
        'approved_revision_id'  => (int) $rev1->id,
        // 夹具模拟 G1-08B 的排期写入；拒绝时必须清空。
        'scheduled_publish_at'  => '2026-12-01 00:00:00',
        'row_version'           => 2,
    ]);

    $rev2 = g8CntDraft($post->fresh(), $adminId, ['title' => '待拒绝的新标题']);
    $post = g8CntReview()->submit($post->fresh(), $rev2, $adminId, 2);

    expect((string) $post->publish_status)->toBe('published')
        ->and((int) $post->approved_revision_id)->toBe((int) $rev1->id);

    $post = g8CntReview()->reject($post, $rev2->fresh(), $adminId, 3, '新版本来源不完整')->fresh();

    expect((string) $post->publish_status)->toBe('published')
        ->and((int) $post->published_revision_id)->toBe((int) $rev1->id)
        ->and($post->approved_revision_id)->toBeNull()
        ->and($post->scheduled_publish_at)->toBeNull()
        ->and($post->working_revision_id)->toBeNull()
        ->and((int) $post->row_version)->toBe(4)
        ->and((string) $rev2->fresh()->review_state)->toBe('rejected');
});
