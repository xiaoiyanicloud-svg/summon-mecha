<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\RichTextSanitizer;

/**
 * G1-07 · 富文本净化（ADR-0001 §3 G1-D06 / §4.3 / §4.7 / G1-07）。
 *
 * 保护的是**入库前的正文安全边界**：
 *   · 只放行注册表白名单标签；
 *   · script/style/on* 属性/iframe/form/object/embed/svg/math 一律移除；
 *   · 链接只允许 http/https，输出统一 rel="noopener noreferrer"；
 *   · img 只允许引用已审核媒体（data-media-id），远程 src 永不保留；
 *   · 空输入返回 NULL；超长输入直接拒绝；
 *   · sanitizer_version 来自 config/ecosystem_rich_text.php。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-07 rich text sanitizer tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g7Sanitizer(): RichTextSanitizer
{
    return app(RichTextSanitizer::class);
}

function g7Sanitize(?string $html, array $allowedMediaIds = []): ?string
{
    return g7Sanitizer()->sanitize($html, $allowedMediaIds);
}

it('G1-07 · 危险标签与事件属性被移除', function () {
    $html = <<<'HTML'
<script>alert(1)</script>
<style>body { color: red; }</style>
<iframe src="https://evil.example/frame"></iframe>
<form action="https://evil.example/post"><input name="q"></form>
<object data="https://evil.example/x"></object>
<embed src="https://evil.example/y">
<svg><script>alert(2)</script><path d="M0 0"></path></svg>
<math><mi>x</mi></math>
<p onclick="alert(3)" onmouseover="alert(4)">安全正文</p>
HTML;

    $result = (string) g7Sanitize($html);

    expect($result)->toContain('安全正文')
        ->and($result)->not->toContain('<script')
        ->and($result)->not->toContain('</script')
        ->and($result)->not->toContain('<style')
        ->and($result)->not->toContain('<iframe')
        ->and($result)->not->toContain('<form')
        ->and($result)->not->toContain('<object')
        ->and($result)->not->toContain('<embed')
        ->and($result)->not->toContain('<svg')
        ->and($result)->not->toContain('<math')
        ->and($result)->not->toContain('alert(1)')
        ->and($result)->not->toContain('alert(2)')
        ->and($result)->not->toContain('onclick')
        ->and($result)->not->toContain('onmouseover');
});

it('G1-07 · 允许列表标签完整保留', function () {
    $html = '<p>段落</p><br><strong>粗体</strong><em>斜体</em><u>下划线</u><s>删除线</s>'
        .'<blockquote>引用</blockquote><ul><li>一</li></ul><ol><li>二</li></ol>'
        .'<figure><figcaption>图注</figcaption></figure>'
        .'<table><thead><tr><th>头</th></tr></thead><tbody><tr><td>格</td></tr></tbody></table>'
        .'<code>code</code><pre>pre</pre><hr>';

    $result = (string) g7Sanitize($html);

    foreach ([
        '<p>', '<br', '<strong>', '<em>', '<u>', '<s>', '<blockquote>',
        '<ul>', '<ol>', '<li>', '<figure>', '<figcaption>', '<table>',
        '<thead>', '<tbody>', '<tr>', '<th>', '<td>', '<code>', '<pre>', '<hr',
    ] as $tag) {
        expect($result)->toContain($tag);
    }
});

it('G1-07 · 链接只允许 http/https，并统一 rel', function () {
    $html = '<p>'
        .'<a href="https://example.com/a" target="_blank" onclick="x()">https</a>'
        .'<a href="http://example.com/b">http</a>'
        .'<a href="javascript:alert(1)">javascript</a>'
        .'<a href="data:text/html;base64,PHNjcmlwdD4=">data</a>'
        .'<a href="file:///etc/passwd">file</a>'
        .'<a href="//evil.example/c">proto-relative</a>'
        .'<a href="/relative">relative</a>'
        .'</p>';

    $result = (string) g7Sanitize($html);

    expect($result)->toContain('href="https://example.com/a"')
        ->and($result)->toContain('rel="noopener noreferrer"')
        ->and($result)->toContain('href="http://example.com/b"')
        ->and($result)->not->toContain('target=')
        ->and($result)->not->toContain('onclick')
        ->and($result)->not->toContain('javascript:')
        ->and($result)->not->toContain('data:text/html')
        ->and($result)->not->toContain('file:///')
        ->and($result)->not->toContain('//evil.example')
        ->and($result)->not->toContain('href="/relative"')
        // 危险链接拆链但保留文字。
        ->and($result)->toContain('javascript')
        ->and($result)->toContain('relative');
});

it('G1-07 · img 只保留已审核 media-id，远程 src 永不保留', function () {
    $html = '<p>正文</p>'
        .'<img data-media-id="5" src="https://evil.example/a.png" alt="一张图" title="标题" width="640" height="480" onerror="x()">'
        .'<img data-media-id="9" src="https://evil.example/b.png" alt="未允许">'
        .'<img data-media-id="abc" src="https://evil.example/c.png">'
        .'<img src="https://evil.example/d.png">';

    $result = (string) g7Sanitize($html, [5, '7']);

    expect($result)->toContain('<img data-media-id="5"')
        ->and($result)->toContain('alt="一张图"')
        ->and($result)->toContain('title="标题"')
        ->and($result)->toContain('width="640"')
        ->and($result)->toContain('height="480"')
        ->and($result)->not->toContain('src=')
        ->and($result)->not->toContain('srcset')
        ->and($result)->not->toContain('evil.example')
        ->and($result)->not->toContain('onerror')
        ->and($result)->not->toContain('data-media-id="9"')
        ->and($result)->not->toContain('data-media-id="abc"');

    // 未允许 ID 的 img 被整条删除：输出里 img 只应剩 1 个。
    expect(substr_count($result, '<img'))->toBe(1);
});

it('G1-07 · figure 内合法图片保留，正文结构不被破坏', function () {
    $result = (string) g7Sanitize(
        '<figure><img data-media-id="7" src="https://evil.example/x.png" alt="图"><figcaption>说明</figcaption></figure>',
        [7],
    );

    expect($result)->toContain('<figure>')
        ->and($result)->toContain('<img data-media-id="7"')
        ->and($result)->toContain('<figcaption>说明</figcaption>')
        ->and($result)->not->toContain('src=');
});

it('G1-07 · 空输入与纯空白返回 NULL', function () {
    expect(g7Sanitize(null))->toBeNull()
        ->and(g7Sanitize(''))->toBeNull()
        ->and(g7Sanitize('   '))->toBeNull()
        ->and(g7Sanitize("\n\t"))->toBeNull();

    // 全部内容都是危险标签时同样不留下空壳。
    expect(g7Sanitize('<script>alert(1)</script>'))->toBeNull()
        ->and(g7Sanitize('<img src="https://evil.example/x.png">'))->toBeNull();
});

it('G1-07 · 超长输入被拒绝，不做截断', function () {
    $original = config('ecosystem_rich_text.max_input_chars');
    config(['ecosystem_rich_text.max_input_chars' => 20]);

    try {
        expect(fn () => g7Sanitizer()->sanitize(str_repeat('长', 21)))
            ->toThrow(InvalidArgumentException::class);

        expect(g7Sanitizer()->sanitize(str_repeat('a', 20)))->toBe(str_repeat('a', 20));
    } finally {
        config(['ecosystem_rich_text.max_input_chars' => $original]);
    }
});

it('G1-07 · sanitizer_version 来自注册表', function () {
    expect(g7Sanitizer()->version())->toBe('g1-07-v1')
        ->and(g7Sanitizer()->version())->toBe((string) config('ecosystem_rich_text.sanitizer_version'));

    expect((array) config('ecosystem_rich_text.allowed_tags'))->toContain(
        'p',
        'br',
        'a',
        'img',
        'figure',
        'figcaption',
        'table',
        'code',
        'pre',
        'hr',
    );

    expect((array) config('ecosystem_rich_text.allowed_schemes'))->toBe(['http', 'https']);
});
