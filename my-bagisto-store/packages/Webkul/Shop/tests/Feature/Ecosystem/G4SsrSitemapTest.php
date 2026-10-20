<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Tests\Feature\Ecosystem\G1PublicApiFixtures as Fixtures;

use function Pest\Laravel\get;

/**
 * G4-04 · SSR / canonical / OG / JSON-LD / sitemap。
 *
 * 运行必须指向独立库 summon_mecha_step10_test；所有用例在 DatabaseTransactions 内。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G4-04 tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.public_enabled'          => true,
        'ecosystem.content.public_enabled'         => true,
        'ecosystem_public_read.emergency_disabled' => false,
        'ecosystem_g4.ssr.enabled'                 => true,
        'ecosystem_g4.ssr.base_url'               => 'https://example.test',
        'ecosystem_g4.ssr.site_name'              => '召唤机甲',
        'ecosystem_g4.sitemap.enabled'             => true,
        'ecosystem_g4.search.enabled'              => true,
        'ecosystem_g4.relations.enabled'           => true,
    ]);
});

it('G4-04 · SSR 开关关闭时页面与 sitemap 均为 404', function () {
    Fixtures::event();
    config(['ecosystem_g4.ssr.enabled' => false, 'ecosystem_g4.sitemap.enabled' => false]);

    get('/events')->assertNotFound();
    get('/content')->assertNotFound();
    get('/sitemap.xml')->assertNotFound();
});

it('G4-04 · 活动列表 SSR 只含公开内容，canonical 指向无筛选列表', function () {
    Fixtures::event([], ['name' => '公开活动甲']);
    Fixtures::event(['publish_status' => 'draft'], ['name' => '草稿活动乙']);

    $response = get('/events')->assertOk();

    $html = (string) $response->getContent();
    expect($html)->toContain('公开活动甲');
    expect($html)->not->toContain('草稿活动乙');
    expect($html)->toContain('<link rel="canonical" href="https://example.test/events">');
    expect($html)->toContain('<meta name="robots" content="index,follow">');

    // 筛选页不重复收录，canonical 仍指向无筛选列表。
    $filtered = get('/events?kind=salon')->assertOk();
    expect((string) $filtered->getContent())
        ->toContain('<meta name="robots" content="noindex,follow">')
        ->toContain('<link rel="canonical" href="https://example.test/events">');
});

it('G4-04 · 活动详情包含 canonical/OG/Event JSON-LD，且不泄露内部字段', function () {
    $event = Fixtures::event([], [
        'name'        => '公开活动名称',
        'summary'     => '公开活动摘要',
        'detail_html' => '<p>公开正文</p>',
    ]);

    $response = get("/events/{$event['id']}")->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('<link rel="canonical" href="https://example.test/events/'.$event['id'].'">');
    expect($html)->toContain('<meta property="og:title" content="公开活动名称">');
    expect($html)->toContain('"@type":"Event"');
    expect($html)->toContain('"startDate"');
    expect($html)->toContain('公开正文');
    expect($html)->not->toContain('内部审核备注不得公开');
    expect($html)->not->toContain('PRIVATE-AUTH-EVIDENCE-REF');
    expect($html)->not->toContain('storage_key');
});

it('G4-04 · 撤稿详情 410 中性页，不含旧标题；不存在 404 noindex', function () {
    $retracted = Fixtures::event(['disposition' => 'retracted'], [
        'name'    => '撤稿前标题',
        'summary' => '撤稿前摘要',
    ]);

    $gone = get("/events/{$retracted['id']}")->assertStatus(410);
    $html = (string) $gone->getContent();

    expect($html)->toContain('该赛事已撤稿');
    expect($html)->not->toContain('撤稿前标题');
    expect($html)->not->toContain('撤稿前摘要');
    expect($html)->toContain('noindex,nofollow');

    get('/events/999999999')->assertNotFound();
});

it('G4-04 · 资讯详情包含 Article JSON-LD 与来源', function () {
    $post = Fixtures::content([], ['title' => '公开文章标题', 'summary' => '公开文章摘要']);
    $slug = (string) DB::table('content_posts')->where('id', $post['id'])->value('slug');

    $response = get("/content/{$slug}")->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('<link rel="canonical" href="https://example.test/content/'.$slug.'">');
    expect($html)->toContain('"@type":"Article"');
    expect($html)->toContain('公开文章标题');
    expect($html)->toContain('官方原文');
    expect($html)->not->toContain('PRIVATE-CONTENT-EVIDENCE-REF');
});

it('G4-04 · sitemap 只收录公开活动/资讯，排除草稿与撤稿', function () {
    $publicEvent = Fixtures::event();
    $draftEvent = Fixtures::event(['publish_status' => 'draft']);
    $retractedEvent = Fixtures::event(['disposition' => 'retracted']);

    $publicPost = Fixtures::content();
    $publicSlug = (string) DB::table('content_posts')->where('id', $publicPost['id'])->value('slug');

    $draftPost = Fixtures::content(['publish_status' => 'draft']);
    $draftSlug = (string) DB::table('content_posts')->where('id', $draftPost['id'])->value('slug');

    $response = get('/sitemap.xml')->assertOk();
    $xml = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toContain('application/xml');
    expect($xml)->toContain('https://example.test/events/'.$publicEvent['id']);
    expect($xml)->toContain('https://example.test/content/'.$publicSlug);
    expect($xml)->not->toContain('https://example.test/events/'.$draftEvent['id']);
    expect($xml)->not->toContain('https://example.test/events/'.$retractedEvent['id']);
    expect($xml)->not->toContain('https://example.test/content/'.$draftSlug);
});

it('G4-04 · kill switch 让 SSR 返回 503 而不是空列表', function () {
    Fixtures::event();
    config(['ecosystem_public_read.emergency_disabled' => true]);

    get('/events')->assertStatus(503);
    get('/sitemap.xml')->assertStatus(503);
});

it('G4-04 · 域开关关闭时 SSR 404，不渲染空壳', function () {
    Fixtures::event();
    config(['ecosystem.events.public_enabled' => false]);

    get('/events')->assertNotFound();
    get('/events/1')->assertNotFound();
});

it('G4-04 · 搜索关闭时带 q 的 SSR 请求 422', function () {
    Fixtures::event();
    config(['ecosystem_g4.search.enabled' => false]);

    get('/events?q=机甲')->assertStatus(422);
    get('/content?q=机甲')->assertStatus(422);
});
