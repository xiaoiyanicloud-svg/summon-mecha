<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\ContentRevision;
use Webkul\Shop\Models\EcosystemOutbox;
use Webkul\Shop\Services\Ecosystem\ContentPostService;
use Webkul\Shop\Services\Ecosystem\ContentPublicationService;
use Webkul\Shop\Services\Ecosystem\ContentRevisionService;
use Webkul\Shop\Services\Ecosystem\ContentReviewService;
use Webkul\Shop\Services\Ecosystem\ContentTransitionException;
use Webkul\Shop\Services\Ecosystem\PublicationPreconditionException;
use Webkul\Shop\Services\Ecosystem\PublicationTransitionException;

/**
 * G1-08B · 资讯发布 / 排期 / 候选切换（与活动同构，ADR-0001 §8.1~§8.4 / §8.9）。
 *
 * 覆盖：
 *   · content_publish_enabled / B-04 / B-07 任一关闭 → publish / schedule 拒绝；
 *   · 批准后正文 / 来源变动 → 发布重检失败；
 *   · 立即发布指针 / 首发时间 / outbox / 审计；
 *   · 已发布文章切换到新候选，旧指针保持到切换那一刻；
 *   · 系统到期发布；
 *   · 普通写入口与模型守卫仍拒绝写 published_revision_id。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-08B content publication tests require summon_mecha_step10_test.');
    }
});

function g8bCntAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G8B Content Publisher',
        'email'      => 'g8b-content-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g8bCntPost(int $adminId): ContentPost
{
    return app(ContentPostService::class)->createDraft($adminId, [
        'type' => 'news',
        'slug' => 'g8b-content-'.bin2hex(random_bytes(5)),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g8bCntPayload(array $overrides = []): array
{
    return array_merge([
        'title'          => 'G8B 资讯标题',
        'summary'        => 'G8B 摘要',
        'body_html'      => '<p>G8B 正文</p>',
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
function g8bCntSource(array $overrides = []): array
{
    return array_merge([
        'source_name'        => '平台官网',
        'source_url'         => 'https://example.com/news/1',
        'source_verified_at' => Carbon::parse('2026-10-01 10:00:00', 'UTC'),
        'rights_basis'       => 'platform_original',
    ], $overrides);
}

function g8bCntDraft(ContentPost $post, int $adminId, array $overrides = [], ?array $sources = null): ContentRevision
{
    return app(ContentRevisionService::class)->createDraft(
        $post,
        $adminId,
        g8bCntPayload($overrides),
        $sources ?? [g8bCntSource()],
    );
}

/**
 * @return array{0: ContentPost, 1: ContentRevision, 2: int}
 */
function g8bCntApproved(): array
{
    $adminId = g8bCntAdminId();
    $post = g8bCntPost($adminId);
    $revision = g8bCntDraft($post, $adminId);

    $post = app(ContentReviewService::class)->submit($post, $revision, $adminId, 0);
    $post = app(ContentReviewService::class)->approve($post, $revision->fresh(), $adminId, 1);

    return [$post->fresh(), $revision->fresh(), $adminId];
}

function g8bCntService(): ContentPublicationService
{
    return app(ContentPublicationService::class);
}

function g8bEnableContentPublishing(): void
{
    config([
        'ecosystem_publishing.content_publish_enabled' => true,
        'ecosystem_publishing.b04_content_slug_signed' => true,
        'ecosystem_publishing.b07_content_legal_signed' => true,
    ]);
}

it('G1-08B · 内容发布开关 / B-04 / B-07 任一关闭时拒绝 publish 与 schedule', function (
    bool $publishEnabled,
    bool $b04,
    bool $b07,
) {
    [$post, $revision, $adminId] = g8bCntApproved();

    config([
        'ecosystem_publishing.content_publish_enabled' => $publishEnabled,
        'ecosystem_publishing.b04_content_slug_signed' => $b04,
        'ecosystem_publishing.b07_content_legal_signed' => $b07,
    ]);

    $publisher = g8bCntService();

    expect(fn () => $publisher->publishNow($post, $revision, $adminId, 2))
        ->toThrow(PublicationTransitionException::class);

    expect(fn () => $publisher->schedule(
        $post,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2026-12-01 02:00:00', new DateTimeZone('UTC')),
    ))->toThrow(PublicationTransitionException::class);

    expect($post->fresh()->published_revision_id)->toBeNull()
        ->and((string) $post->fresh()->publish_status)->toBe('approved')
        ->and(EcosystemOutbox::count())->toBe(0);
})->with([
    'publish 开关关闭' => [false, true, true],
    'B-04 未签署'      => [true, false, true],
    'B-07 未签署'      => [true, true, false],
]);

it('G1-08B · 内容立即发布：指针 / 首发时间 / outbox / 审计同事务', function () {
    g8bEnableContentPublishing();
    [$post, $revision, $adminId] = g8bCntApproved();
    $slug = (string) $post->slug;

    $published = g8bCntService()->publishNow($post, $revision, $adminId, 2);
    $revision->refresh();

    expect((string) $published->publish_status)->toBe('published')
        ->and((int) $published->published_revision_id)->toBe((int) $revision->getKey())
        ->and($published->approved_revision_id)->toBeNull()
        ->and($published->scheduled_publish_at)->toBeNull()
        ->and((string) $published->disposition)->toBe('normal')
        ->and($published->first_published_at)->not->toBeNull()
        ->and($published->published_at->timezoneName)->toBe('UTC')
        ->and((int) $published->row_version)->toBe(3)
        ->and((string) $published->slug)->toBe($slug)
        ->and((int) $published->updated_by)->toBe($adminId)
        ->and($revision->published_at)->not->toBeNull();

    $outbox = EcosystemOutbox::query()->sole();

    expect($outbox->aggregate_type)->toBe('content')
        ->and((int) $outbox->aggregate_id)->toBe((int) $post->id)
        ->and($outbox->event_type)->toBe('content.published')
        ->and($outbox->payload)->toEqual([
            'revision_id'         => (int) $revision->getKey(),
            'revision_no'         => (int) $revision->revision_no,
            'row_version'         => 3,
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'reason_code'         => 'published',
        ]);

    expect(DB::table('operation_log')
        ->where('action', 'ecosystem.content.publish')
        ->where('target_type', 'content_post')
        ->where('target_id', (int) $post->id)
        ->exists())->toBeTrue();
});

it('G1-08B · 内容批准后正文 / 来源变动，发布重检失败且不切换', function (string $table, string $key, string $column, string $value) {
    g8bEnableContentPublishing();
    [$post, $revision, $adminId] = g8bCntApproved();

    DB::table($table)->where($key, $revision->id)->update([$column => $value]);

    try {
        g8bCntService()->publishNow($post, $revision->fresh(), $adminId, 2);
        $this->fail('批准后内容变动不应允许发布。');
    } catch (PublicationPreconditionException $exception) {
        expect($exception->errorCode)->toBe(ApiErrorCode::CONTENT_REVISION_CONFLICT);
    }

    expect($post->fresh()->published_revision_id)->toBeNull()
        ->and((string) $post->fresh()->publish_status)->toBe('approved')
        ->and(EcosystemOutbox::count())->toBe(0);
})->with([
    '正文变动' => ['content_revisions', 'id', 'body_html', '<p>批准后被改掉的正文</p>'],
    '来源变动' => ['content_sources', 'revision_id', 'source_url', 'https://example.com/news/changed'],
]);

it('G1-08B · 内容排期 / 取消排期：UTC 落库，scheduled -> approved', function () {
    g8bEnableContentPublishing();
    [$post, $revision, $adminId] = g8bCntApproved();

    $publisher = g8bCntService();
    $scheduled = $publisher->schedule(
        $post,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('Asia/Shanghai')),
    );

    expect((string) $scheduled->publish_status)->toBe('scheduled')
        ->and($scheduled->scheduled_publish_at->format('Y-m-d H:i:s'))->toBe('2026-12-01 02:00:00')
        ->and($scheduled->scheduled_publish_at->timezoneName)->toBe('UTC')
        ->and(EcosystemOutbox::count())->toBe(0);

    $unscheduled = $publisher->unschedule($scheduled, $adminId, 3);

    expect((string) $unscheduled->publish_status)->toBe('approved')
        ->and($unscheduled->scheduled_publish_at)->toBeNull()
        ->and((int) $unscheduled->approved_revision_id)->toBe((int) $revision->getKey())
        ->and(EcosystemOutbox::count())->toBe(0);
});

it('G1-08B · 已发布文章切换新候选：切换前旧指针保持，切换后旧 revision 保留', function () {
    g8bEnableContentPublishing();
    [$post, $rev1, $adminId] = g8bCntApproved();
    $publisher = g8bCntService();

    $post = $publisher->publishNow($post, $rev1, $adminId, 2);
    $firstPublishedAt = $post->first_published_at;

    $rev2 = g8bCntDraft($post->fresh(), $adminId, ['title' => '第二版标题']);
    $post = app(ContentReviewService::class)->submit($post->fresh(), $rev2, $adminId, 3);

    expect((string) $post->publish_status)->toBe('published')
        ->and((int) $post->published_revision_id)->toBe((int) $rev1->getKey());

    $post = app(ContentReviewService::class)->approve($post, $rev2->fresh(), $adminId, 4);
    $post = $publisher->publishNow($post, $rev2->fresh(), $adminId, 5);

    expect((int) $post->published_revision_id)->toBe((int) $rev2->getKey())
        ->and($post->approved_revision_id)->toBeNull()
        ->and((int) $post->row_version)->toBe(6)
        ->and($post->first_published_at->equalTo($firstPublishedAt))->toBeTrue();

    expect(ContentRevision::query()->whereKey($rev1->id)->exists())->toBeTrue()
        ->and(EcosystemOutbox::where('event_type', 'content.published')->count())->toBe(2);
});

it('G1-08B · 内容系统到期发布成功后清排期', function () {
    g8bEnableContentPublishing();
    [$post, $revision, $adminId] = g8bCntApproved();
    $publisher = g8bCntService();

    $publisher->schedule(
        $post,
        $revision,
        $adminId,
        2,
        new DateTimeImmutable('2026-12-01 02:00:00', new DateTimeZone('UTC')),
    );

    DB::table('content_posts')->where('id', $post->id)->update([
        'scheduled_publish_at' => '2020-01-01 00:00:00',
    ]);

    $published = $publisher->publishScheduled($post->fresh());

    expect((int) $published->published_revision_id)->toBe((int) $revision->getKey())
        ->and($published->scheduled_publish_at)->toBeNull()
        ->and(DB::table('operation_log')->where('action', 'ecosystem.content.publish')->first()->actor_type)
        ->toBe('system');
});

it('G1-08B · 内容普通写入口与模型守卫仍拒绝 published_revision_id', function () {
    g8bEnableContentPublishing();
    [$post, $revision, $adminId] = g8bCntApproved();

    expect(fn () => app(ContentPostService::class)->updateDraft($post, 2, $adminId, [
        'published_revision_id' => (int) $revision->getKey(),
    ]))->toThrow(InvalidArgumentException::class);

    $model = ContentPost::query()->findOrFail($post->getKey());
    $model->published_revision_id = (int) $revision->getKey();

    expect(fn () => $model->save())->toThrow(ContentTransitionException::class);

    expect($post->fresh()->published_revision_id)->toBeNull()
        ->and((int) $post->fresh()->row_version)->toBe(2);
});
