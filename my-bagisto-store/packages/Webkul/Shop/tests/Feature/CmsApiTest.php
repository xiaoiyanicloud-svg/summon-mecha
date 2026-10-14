<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * 静态内容页的 JSON 出口（`GET /api/cms/pages/{slug}`）—— 给 uni-app 用。
 *
 * 这个接口存在的**唯一理由**，是 App 里那两页不得在自己代码里写死协议正文：
 * 写死就有了第二个「协议正文出处」——律师定稿后改了后台，网页端更新了、
 * App 还显示旧条款，而没有任何东西能发现这件事。
 *
 * 所以本文件的头号断言不是"接口通不通"，而是 **App 拿到的正文与网页端是同一份**
 * （见「同一份正文」那条）。接口通但内容各说各话，等于没做。
 *
 * ⚠ 网页端那条路由挂了 spatie/laravel-responsecache，读的是缓存 HTML —— 所以要比对
 *   就得先清缓存，否则比的是上一次的缓存快照。
 */

beforeEach(function () {
    \Spatie\ResponseCache\Facades\ResponseCache::clear();
});

it('隐私政策：返回 200 与 {slug, title, html_content}', function () {
    $data = getJson('/api/cms/pages/privacy-policy')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveKeys(['slug', 'title', 'html_content'])
        ->and($data['slug'])->toBe('privacy-policy')
        ->and($data['title'])->toBe('隐私政策')
        ->and($data['html_content'])->not->toBe('');
});

it('App 拿到的正文与网页端是**同一份** —— 这是这个接口存在的全部理由', function () {
    $api = getJson('/api/cms/pages/privacy-policy')->assertOk()->json('data.html_content');

    $web = get('/page/privacy-policy')->assertOk()->getContent();

    // 网页是整页 HTML（外层套了店面布局），正文以原样未转义的方式嵌在里面
    // （`cms/page.blade.php` 用的是 `{!! !!}`）—— 所以能逐字比对。
    expect($api)->not->toBe('')
        ->and($web)->toContain($api);
});

it('连查两页都必须正确 —— 仓储实例会把上一次的查询条件留下来，这里守着别再写回去', function () {
    // 这条不是凑数的。第一版控制器照抄了原生 `PageController::view()` 的写法
    // （`$this->pageRepository->whereHas(...)->whereTranslation(...)`），
    // 结果**同一次请求处理里查第二页必然 404**：仓储实例复用了上一次的查询构造器，
    // SQL 变成 `url_key = ? and url_key = ?`（两次的 slug 都在），无解。
    // 单请求容器下线上碰不到，但测试 / 队列 worker / 常驻进程里会碰到，
    // 且现象是"第二次查任何一页都 404"，极难定位。现在改用 `Page::query()`。
    foreach (['privacy-policy', 'terms-of-use'] as $slug) {
        $res = getJson('/api/cms/pages/'.$slug)->assertOk();

        expect($res->json('data.slug'))->toBe($slug)
            ->and($res->json('data.html_content'))->toContain('尚未定稿');
    }
});

it('正文里不得出现安装器占位文案（网页端已去伪，接口这边不能漏）', function () {
    foreach (['privacy-policy', 'terms-of-use'] as $slug) {
        $html = getJson('/api/cms/pages/'.$slug)->assertOk()->json('data.html_content');

        expect($html)->not->toContain('頁面內容')
            ->and($html)->toContain('尚未定稿');
    }
});

it('一份文件只有一个名字：接口标题、网页标题、正文里嵌的文档名三者一致', function () {
    // 用户协议这一页曾经有三处名字：App 入口「用户协议」/ 页头「使用条款」/ 正文「《使用条款》」。
    // 迁移 2026_10_15_000001 在生产侧的源头对齐了它们，这条守着别再分叉。
    $data = getJson('/api/cms/pages/terms-of-use')->assertOk()->json('data');

    expect($data['title'])->toBe('用户协议')
        ->and($data['html_content'])->toContain('《用户协议》')
        ->and($data['html_content'])->not->toContain('使用条款');

    // 网页端同源同结论
    expect(get('/page/terms-of-use')->assertOk()->getContent())->toContain('用户协议');
});

it('不存在的页面返回 404 且带明确 code（不是一页空白）', function () {
    getJson('/api/cms/pages/no-such-page')
        ->assertStatus(404)
        ->assertJsonPath('code', 'CMS_PAGE_NOT_FOUND');
});

it('slug 只接受 url_key 形态的字符：带斜杠/空格/点号的路径不会被当成一页返回', function () {
    $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('shop.api.cms.pages.show');

    expect($route)->not->toBeNull()
        ->and($route->wheres['slug'] ?? null)->toBe('[A-Za-z0-9_-]+');

    // 行为侧：这些路径**不得**被当成一个文档返回内容。
    //
    // ⚠ 它们不会得到 404：不匹配 `/api/cms/pages/{slug}` 之后，请求落进了店面那条
    //   `Route::fallback(ProductsCategoriesProxyController@index)`。所以这里只断言
    //   "不是 200、也不是我们的 JSON 信封" —— 断言成 404 会是在描述一个不存在的行为。
    foreach (['../etc/passwd', 'a b', 'x.php'] as $bad) {
        $res = getJson('/api/cms/pages/'.rawurlencode($bad));

        expect($res->status())->not->toBe(200)
            ->and($res->getContent())->not->toContain('CMS_PAGE_NOT_FOUND')
            ->and($res->getContent())->not->toContain('html_content');
    }
});

it('公开可读：不带任何凭证也能拿到（这些页面本来就在网页端公开）', function () {
    // 反面约束：它**不该**要求登录。一个要登录才能看的隐私政策，
    // 在"用户想知道你们怎么处理我的数据"这个场景下毫无用处。
    getJson('/api/cms/pages/privacy-policy')->assertOk();
});

it('接口不比网页多暴露一页 —— 没有发布到当前频道的页面读不到', function () {
    // 造一页**不属于任何频道**的 CMS 页：网页端取不到它，接口也必须取不到。
    $pageId = DB::table('cms_pages')->insertGetId([
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('cms_page_translations')->insert([
        'cms_page_id'  => $pageId,
        'locale'       => 'zh_CN',
        'url_key'      => 'secret-draft-page',
        'page_title'   => '未发布页',
        'html_content' => '这页不该被任何人读到',
    ]);

    // 网页端：取不到
    get('/page/secret-draft-page')->assertStatus(404);

    // 接口：同样取不到（两者用的是同一个频道过滤 + url_key 条件）
    getJson('/api/cms/pages/secret-draft-page')->assertStatus(404);
});
