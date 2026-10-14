<?php

use function Pest\Laravel\get;

/**
 * CMS 静态页不得输出"安装器占位文案"（迁移 000008）。
 *
 * 起因：`/page/privacy-policy` 是**公开可达、HTTP 200、正文非空**的，
 * 而它的正文一直是 Bagisto 安装器塞进去的占位串（简体环境下显示为繁体
 * `隱私政策頁面內容`）。一个标题写着《隐私政策》的页面"有内容"，
 * 会让"这个平台有没有隐私政策"这个问题得到**错误答案**。
 *
 * 本文件锁两件事：
 *   ① 不再出现占位串（按**全表扫描**断言，将来安装器新增页面也会被这条抓到）
 *   ② 取而代之的是**明确说明正文尚未定稿**，而不是一段像条款的空壳
 *
 * ⚠ 页面走 spatie/laravel-responsecache，读的是缓存 HTML —— 所以要清一下缓存，
 *   否则这里验的可能是上一次的缓存而不是当前库。（部署时同理，见迁移注释。）
 */

beforeEach(function () {
    // 只清响应缓存（不是整个 cache 表），并且只影响本次测试可见的快照
    \Spatie\ResponseCache\Facades\ResponseCache::clear();
});

it('隐私政策页：不再输出安装器占位文案，改为明说「正文尚未定稿」', function () {
    $html = get('/page/privacy-policy')->assertOk()->getContent();

    expect($html)->not->toContain('隱私政策頁面內容')
        ->and($html)->toContain('本页正文尚未定稿')
        // 要讲清"为什么现在是空的"，否则读者会以为页面坏了
        ->and($html)->toContain('律师定稿');
});

it('全表扫描：简体中文下没有任何 CMS 页还在输出安装器占位串', function () {
    // 不枚举页面名 —— 直接看库里还有没有 "頁面內容" 这种占位尾巴。
    // 枚举写法会漏掉将来安装器新增的页面，这条不会。
    $leftovers = DB::table('cms_page_translations')
        ->where('locale', 'zh_CN')
        ->where('html_content', 'like', '%頁面內容%')
        ->pluck('url_key')
        ->all();

    expect($leftovers)->toBe([]);
});

it('占位说明不得伪装成条款正文', function () {
    // 反面：说明里不能出现"本政策适用于…"这类条款口吻的表述 ——
    // 一段像条款的占位文案，比一句"尚未定稿"更容易被当成真文件。
    foreach (['privacy-policy', 'terms-of-use', 'terms-conditions'] as $urlKey) {
        $content = DB::table('cms_page_translations')
            ->where('locale', 'zh_CN')
            ->where('url_key', $urlKey)
            ->value('html_content');

        expect($content)->toContain('尚未定稿')
            ->and($content)->not->toContain('本政策适用于')
            ->and($content)->not->toContain('您同意');
    }
});
