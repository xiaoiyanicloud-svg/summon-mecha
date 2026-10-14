<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '开发者中心')</title>
    <style>
        /* 门户自带极简样式：不依赖前台主题（前台已停用，且主题绑 Channel/Theme） */
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif; background: #f5f6f8; color: #1f2937; }
        a { color: #2563eb; text-decoration: none; }
        .topbar { display: flex; align-items: center; justify-content: space-between; padding: 0 24px; height: 56px; background: #111827; color: #fff; }
        .topbar .brand { font-weight: 600; letter-spacing: .5px; }
        .topbar nav a { color: #cbd5e1; margin-left: 18px; font-size: 14px; }
        .topbar nav a.on, .topbar nav a:hover { color: #fff; }
        .wrap { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        .card h2 { margin: 0 0 12px; font-size: 16px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        label { display: block; font-size: 13px; color: #374151; margin: 12px 0 4px; }
        input[type=text], input[type=email], input[type=password], input[type=number], textarea, select {
            width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; font-family: inherit;
        }
        textarea { min-height: 80px; }
        button, .btn { display: inline-block; padding: 8px 18px; border: 0; border-radius: 6px; background: #2563eb; color: #fff; font-size: 14px; cursor: pointer; }
        .btn.ghost { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .btn.small { padding: 4px 12px; font-size: 13px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #eef0f3; }
        th { color: #6b7280; font-weight: 500; font-size: 13px; }
        .muted { color: #6b7280; font-size: 13px; }
        .flash { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 14px; }
        .flash.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .flash.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; background: #eef1f5; color: #475569; }
        .badge.ok { background: #ecfdf5; color: #047857; }
        .row { display: flex; gap: 12px; }
        .row > * { flex: 1; }
        /* 状态提示：用于在数字之前先把"这份数据的边界"讲清楚 */
        .notice { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 8px; padding: 12px 14px; font-size: 13px; line-height: 1.75; }
        .notice strong { color: #78350f; }
        .num { font-size: 18px; font-weight: 600; }
    </style>
</head>
<body>
<header class="topbar">
    <span class="brand">开发者中心</span>
    <nav>
        @auth('developer')
            <a href="{{ route('shop.dev.dashboard') }}" class="{{ request()->routeIs('shop.dev.dashboard') ? 'on' : '' }}">总览</a>
            <a href="{{ route('shop.dev.skills.index') }}" class="{{ request()->routeIs('shop.dev.skills.*') ? 'on' : '' }}">我的技能</a>
            <a href="{{ route('shop.dev.audits.index') }}" class="{{ request()->routeIs('shop.dev.audits.*') ? 'on' : '' }}">审核状态</a>
            <form method="POST" action="{{ route('shop.dev.logout') }}" style="display:inline">
                @csrf
                <button class="btn ghost small" type="submit" style="margin-left:18px">退出</button>
            </form>
        @endauth
    </nav>
</header>

<main class="wrap">
    @include('shop::dev.partials.flash')
    @yield('content')
</main>
</body>
</html>
