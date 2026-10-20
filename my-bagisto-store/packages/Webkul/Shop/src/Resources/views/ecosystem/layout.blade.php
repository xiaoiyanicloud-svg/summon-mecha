<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }} · {{ $seo['og']['og:site_name'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    @foreach ($seo['og'] as $property => $content)
        <meta property="{{ $property }}" content="{{ $content }}">
    @endforeach

    @if (! empty($seo['jsonLd']))
        <script type="application/ld+json">{!! json_encode($seo['jsonLd'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif

    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #F5F7FB; color: #1F2937; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif; line-height: 1.7; }
        a { color: #165DFF; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .top { background: #102F6E; color: #fff; }
        .top-inner { max-width: 1040px; margin: 0 auto; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .brand { font-size: 18px; font-weight: 700; color: #fff; }
        .brand span { font-weight: 400; opacity: .75; font-size: 13px; margin-left: 8px; }
        .nav a { color: rgba(255,255,255,.85); margin-left: 16px; font-size: 14px; }
        main { max-width: 1040px; margin: 24px auto 48px; padding: 0 20px; }
        .card { background: #fff; border: 1px solid #E5EAF3; border-radius: 12px; padding: 22px 24px; }
        .muted { color: #6B7280; font-size: 13px; }
        .btn { display: inline-block; padding: 9px 16px; border-radius: 8px; background: #165DFF; color: #fff; font-size: 14px; }
        .btn-line { display: inline-block; padding: 8px 14px; border-radius: 8px; border: 1px solid #C7D2FE; color: #165DFF; font-size: 14px; background: #fff; }
        .notice { padding: 14px 16px; border-radius: 10px; background: #FFF8EC; border: 1px solid #F2D8A8; color: #7C4A03; }
        .danger { background: #FEF2F2; border-color: #FECACA; color: #991B1B; }
        .grid { display: grid; gap: 16px; }
        .list-card { display: block; color: inherit; }
        .cover { width: 100%; max-height: 220px; object-fit: cover; border-radius: 10px; background: #E8EEF9; }
        .tags span { display: inline-block; font-size: 12px; color: #102F6E; background: #E8EEF9; border-radius: 999px; padding: 2px 10px; margin-right: 6px; }
        .body-html img { max-width: 100%; height: auto; border-radius: 8px; }
        .body-html table { max-width: 100%; border-collapse: collapse; }
        .body-html th, .body-html td { border: 1px solid #D1D5DB; padding: 6px 8px; }
        .related { margin-top: 28px; border-top: 1px solid #E5EAF3; padding-top: 18px; }
        .related h2 { font-size: 16px; margin: 0 0 10px; }
        .related-group { margin-bottom: 12px; }
        .related-group b { font-size: 13px; color: #374151; }
        .related-group a, .related-group span { display: inline-block; margin: 4px 8px 4px 0; font-size: 13px; }
        .pager { display: flex; justify-content: space-between; margin-top: 20px; }
        footer { border-top: 1px solid #E5EAF3; color: #6B7280; font-size: 12px; text-align: center; padding: 20px; }
        form.filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
        form.filters input, form.filters select { padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 8px; background: #fff; }
        @media (max-width: 640px) {
            .top-inner { padding: 12px 14px; }
            main { padding: 0 14px; margin-top: 16px; }
            .card { padding: 16px; }
            .nav a { margin-left: 0; margin-right: 14px; }
        }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-inner">
            <div class="brand">{{ $seo['og']['og:site_name'] }}<span>赛事活动 · 动态资讯</span></div>
            <nav class="nav">
                <a href="{{ url('/events') }}">赛事活动</a>
                <a href="{{ url('/content') }}">动态资讯</a>
                <a href="{{ url('/') }}">返回首页</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        {{ $seo['og']['og:site_name'] }} · 本页由服务端渲染，内容以当前公开发布版本为准。
    </footer>
</body>
</html>
