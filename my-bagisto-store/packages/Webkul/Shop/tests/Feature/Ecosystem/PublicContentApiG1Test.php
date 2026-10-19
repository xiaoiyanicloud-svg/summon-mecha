<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\getJson;

/**
 * G1-10 · 公开资讯 API（DTO、来源白名单、统一 404、撤稿 410、筛选分页、净化版本 fail-closed）。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-10 public content tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.content.public_enabled'         => true,
        'ecosystem.events.public_enabled'          => false,
        'ecosystem_public_read.emergency_disabled' => false,
    ]);
});

it('G1-10 · 已发布文章详情返回白名单 DTO、公开来源与受控媒体 URL', function () {
    $media = Fixtures::media('content-image-bytes', 'image/png');
    $post = Fixtures::content([], [
        'body_html' => '<p>正文</p><img data-media-id="'.$media['id'].'" alt="配图">',
        'correction_note' => '已更正一处时间。',
    ]);
    Fixtures::mediaRef('content_revision', $post['revision_id'], $media['id'], 'inline');

    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $response = getJson("/api/content/posts/{$slug}")->assertOk();

    $response
        ->assertJsonPath('data.slug', $slug)
        ->assertJsonPath('data.type', 'news')
        ->assertJsonPath('data.title', '公开文章标题')
        ->assertJsonPath('data.summary', '公开文章摘要')
        ->assertJsonPath('data.author.name', '张三')
        ->assertJsonPath('data.author.org', '召唤机甲编辑部')
        ->assertJsonPath('data.sources.0.name', '官方原文')
        ->assertJsonPath('data.sources.0.url', 'https://example.com/news/1')
        ->assertJsonPath('data.sources.0.verified_at', '2026-10-18T12:00:00+08:00')
        ->assertJsonPath('data.sources.0.rights_basis', 'platform_original')
        ->assertJsonPath('data.sources.0.rights_scope', '全文')
        ->assertJsonPath('data.published_at', '2026-10-19T12:00:00+08:00')
        ->assertJsonPath('data.first_published_at', '2026-10-19T12:00:00+08:00')
        ->assertJsonPath('data.revision_no', 1)
        ->assertJsonPath('data.correction_note', '已更正一处时间。')
        ->assertJsonPath('data.media.0.id', $media['id'])
        ->assertJsonPath('data.media.0.role', 'inline')
        ->assertJsonPath('data.media.0.url', "/api/content/posts/{$slug}/media/{$media['id']}");

    expect((string) $response->json('data.body_html'))
        ->toContain('/api/content/posts/'.$slug.'/media/'.$media['id']);

    expect($response->json('data.sources.0'))->not->toHaveKey('evidence_ref');
});

it('G1-10 · 文章不存在 / 未发布 / 待审 / 已批准 / 已排期 / 暂缓统一 404 且响应体一致', function () {
    $payloads = [];

    foreach ([
        ['publish_status' => 'draft', 'disposition' => 'normal'],
        ['publish_status' => 'in_review', 'disposition' => 'normal'],
        ['publish_status' => 'approved', 'disposition' => 'normal'],
        ['publish_status' => 'scheduled', 'disposition' => 'normal'],
        ['publish_status' => 'published', 'disposition' => 'withheld'],
    ] as $override) {
        $post = Fixtures::content($override);
        $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

        $payloads[] = getJson("/api/content/posts/{$slug}")
            ->assertNotFound()
            ->assertJsonPath('code', ApiErrorCode::CONTENT_NOT_FOUND)
            ->json();
    }

    $unapproved = Fixtures::content([], ['review_state' => 'in_review']);
    $slug = (string) DB::table('content_posts')->where('id', $unapproved['id'])->value('slug');
    $payloads[] = getJson("/api/content/posts/{$slug}")->assertNotFound()->json();

    $payloads[] = getJson('/api/content/posts/never-exists')->assertNotFound()->json();

    expect(array_unique(array_map('json_encode', $payloads)))->toHaveCount(1);
    expect($payloads[0]['message'])->toBe('文章尚未公开发布或不存在。');
});

it('G1-10 · 已撤稿文章返回 410 中性文案，不回显旧标题/正文/来源', function () {
    $post = Fixtures::content(['disposition' => 'retracted']);
    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $response = getJson("/api/content/posts/{$slug}")
        ->assertStatus(410)
        ->assertJsonPath('code', ApiErrorCode::CONTENT_UNAVAILABLE);

    $raw = (string) $response->getContent();

    expect($raw)->not->toContain('公开文章标题');
    expect($raw)->not->toContain('公开文章正文');
    expect($raw)->not->toContain('官方原文');
    expect($response->json('data'))->toBeNull();
});

it('G1-10 · 资讯筛选与稳定排序 / 分页', function () {
    $first = Fixtures::content(
        ['type' => 'news', 'category' => 'robotics', 'first_published_at' => '2026-10-01 04:00:00'],
        [],
        [],
    );
    $second = Fixtures::content(
        ['type' => 'news', 'category' => 'robotics', 'first_published_at' => '2026-10-02 04:00:00'],
    );
    $third = Fixtures::content(
        ['type' => 'case', 'category' => 'industry', 'first_published_at' => '2026-10-02 04:00:00'],
    );

    // 同一 first_published_at 由 id DESC 决胜。
    $slugsInOrder = array_column(getJson('/api/content/posts?per_page=50')->assertOk()->json('data'), 'slug');

    $slugOf = static fn (int $id): string => (string) DB::table('content_posts')->where('id', $id)->value('slug');

    expect($slugsInOrder)->toBe([
        $slugOf($third['id']),
        $slugOf($second['id']),
        $slugOf($first['id']),
    ]);

    $page1 = getJson('/api/content/posts?per_page=2&page=1')->json();
    $page2 = getJson('/api/content/posts?per_page=2&page=2')->json();

    expect(array_column($page1['data'], 'slug'))->toHaveCount(2);
    expect(array_column($page2['data'], 'slug'))->toHaveCount(1);
    expect(array_intersect(
        array_column($page1['data'], 'slug'),
        array_column($page2['data'], 'slug'),
    ))->toBe([]);
    expect($page1['meta'])->toMatchArray([
        'current_page' => 1,
        'last_page'    => 2,
        'per_page'     => 2,
        'total'        => 3,
    ]);

    $types = array_column(getJson('/api/content/posts?type=case')->json('data'), 'type');
    expect($types)->toBe(['case']);

    $categories = array_column(getJson('/api/content/posts?category=robotics')->json('data'), 'slug');
    expect($categories)->toHaveCount(2);

    $from = array_column(
        getJson('/api/content/posts?published_from=2026-10-02T00:00:00%2B08:00')->json('data'),
        'slug',
    );
    expect($from)->toHaveCount(2);
});

it('G1-10 · 非法筛选参数返回 422', function (string $query) {
    getJson('/api/content/posts?'.$query)
        ->assertStatus(422)
        ->assertJsonPath('code', ApiErrorCode::VALIDATION_ERROR);
})->with([
    'unknown type'      => 'type=unknown',
    'per_page too big'  => 'per_page=51',
    'page zero'         => 'page=0',
    'invalid date'      => 'published_from=not-a-date',
    'reversed range'    => 'published_from=2026-10-10&published_to=2026-10-01',
]);

it('G1-10 · sanitizer_version 过期时详情 404、列表排除，不输出旧 HTML', function () {
    $stale = Fixtures::content([], [
        'body_html'         => '<p>旧净化正文不得输出</p>',
        'sanitizer_version' => 'g1-05-legacy',
    ]);
    $fresh = Fixtures::content([], ['body_html' => '<p>当前正文</p>']);

    $slug = (string) DB::table('content_posts')->where('id', $stale['id'])->value('slug');

    getJson("/api/content/posts/{$slug}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::CONTENT_NOT_FOUND);

    $slugs = array_column(getJson('/api/content/posts')->json('data'), 'slug');
    $freshSlug = (string) DB::table('content_posts')->where('id', $fresh['id'])->value('slug');

    expect($slugs)->toContain($freshSlug);
    expect($slugs)->not->toContain($slug);
});

it('G1-10 · 域开关关闭时资讯列表为空、详情 404，且不查业务表', function () {
    $post = Fixtures::content();
    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    config(['ecosystem.content.public_enabled' => false]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    getJson('/api/content/posts')->assertOk()->assertExactJson(['data' => []]);
    getJson("/api/content/posts/{$slug}")
        ->assertNotFound()
        ->assertJsonPath('code', ApiErrorCode::CONTENT_NOT_FOUND);

    $contentQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $query): bool => str_contains($query['query'], 'content_posts')
            || str_contains($query['query'], 'content_revisions'),
    );

    DB::disableQueryLog();

    expect($contentQueries)->toBe([]);
});
