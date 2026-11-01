<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', '开发者中心') · 召唤机甲开发者技能后台</title>
    <style>
        :root {
            --bg-body: #F8FAFC;
            --bg-card: #FFFFFF;
            --bg-sidebar: #0B1528;
            --bg-sidebar-hover: rgba(255, 255, 255, 0.07);
            --bg-sidebar-active: linear-gradient(90deg, #2563EB 0%, #3B82F6 100%);
            --text-main: #0F172A;
            --text-secondary: #475569;
            --text-muted: #64748B;
            --border-base: #E2E8F0;
            --border-subtle: #F1F5F9;
            --primary: #2563EB;
            --primary-hover: #1D4ED8;
            --primary-soft: #EFF6FF;
            --success: #059669;
            --success-soft: #ECFDF5;
            --warning: #D97706;
            --warning-soft: #FFFBEB;
            --danger: #DC2626;
            --danger-soft: #FEF2F2;
            --radius-lg: 14px;
            --radius-md: 10px;
            --radius-sm: 8px;
            --shadow-card: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.02);
            --shadow-modal: 0 24px 48px -12px rgba(15, 23, 42, 0.28);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            font-size: 14px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--primary); text-decoration: none; transition: color .15s; }
        a:hover { color: var(--primary-hover); }

        /* 整体壳层 */
        .dev-shell { display: flex; min-height: 100vh; }
        .dev-sidebar {
            width: 248px;
            background: var(--bg-sidebar);
            color: #E2E8F0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 40;
            border-right: 1px solid rgba(255, 255, 255, 0.06);
        }
        .dev-brand {
            padding: 20px 20px 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .dev-brand-logo {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #3B82F6 0%, #6366F1 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: #FFF;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
            flex-shrink: 0;
        }
        .dev-brand-text .brand {
            display: block;
            font-size: 15px;
            font-weight: 700;
            color: #FFF;
            letter-spacing: 0.3px;
        }
        .dev-brand-text .brand-sub {
            display: block;
            font-size: 11px;
            color: #94A3B8;
            margin-top: 2px;
        }
        .dev-nav-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 14px 12px;
        }
        .dev-nav-group-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #64748B;
            padding: 10px 12px 6px;
        }
        .dev-nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 9px;
            color: #cbd5e1;
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: 3px;
            transition: all .15s ease;
        }
        .dev-nav-link:hover {
            background: var(--bg-sidebar-hover);
            color: #FFF;
        }
        .dev-nav-link.on {
            background: var(--bg-sidebar-active);
            color: #FFF;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        .dev-nav-link-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .dev-nav-icon {
            width: 18px;
            text-align: center;
            font-size: 15px;
            opacity: 0.9;
        }
        /* 顶栏/侧栏未读角标：深色栏上用亮色小胶囊，数字一眼可见（锁定类名 .nav-badge） */
        .nav-badge {
            display: inline-block;
            min-width: 18px;
            margin-left: 4px;
            padding: 0 6px;
            border-radius: 999px;
            background: #EF4444;
            color: #FFF;
            font-size: 11px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
            vertical-align: 1px;
        }
        .dev-sidebar-footer {
            padding: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(2, 6, 23, 0.35);
        }
        .dev-portal-switch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            color: #E2E8F0;
            font-size: 12.5px;
            font-weight: 500;
            margin-bottom: 10px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .dev-portal-switch:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #FFF;
        }
        .dev-user-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .dev-user-meta {
            min-width: 0;
            flex: 1;
        }
        .dev-user-name {
            font-size: 13px;
            font-weight: 600;
            color: #F8FAFC;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dev-user-sub {
            font-size: 11px;
            color: #94A3B8;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 主内容区 + Sticky Topbar */
        .dev-main {
            flex: 1;
            margin-left: 248px;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .dev-main.guest-main {
            margin-left: 0;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            height: 60px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-base);
            position: sticky;
            top: 0;
            z-index: 30;
        }
        .topbar.guest-topbar {
            background: #0B1528;
            color: #FFF;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .topbar-crumb {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .topbar-crumb strong {
            color: var(--text-main);
            font-weight: 600;
        }
        .topbar nav {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .topbar nav a {
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 7px;
        }
        .topbar nav a.on, .topbar nav a:hover {
            color: var(--primary);
            background: var(--primary-soft);
        }

        .wrap {
            max-width: 1180px;
            width: 100%;
            margin: 0 auto;
            padding: 28px;
        }

        /* 页面标题区 */
        .page-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .page-head h1, h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0 0 6px;
            letter-spacing: -0.2px;
        }
        .page-head-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0;
        }
        .page-head-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* 卡片与子面板 */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-base);
            border-radius: var(--radius-lg);
            padding: 22px 24px;
            margin-bottom: 18px;
            box-shadow: var(--shadow-card);
        }
        .card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .card h2 {
            margin: 0 0 12px;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
        }
        .card-head h2 { margin: 0; }
        .sub-panel {
            background: #F8FAFC;
            border: 1px solid var(--border-base);
            border-radius: var(--radius-md);
            padding: 16px 18px;
            margin-top: 14px;
        }

        /* KPI 统计网格 */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }
        .kpi-card {
            background: var(--bg-card);
            border: 1px solid var(--border-base);
            border-radius: var(--radius-md);
            padding: 16px 18px;
            box-shadow: var(--shadow-card);
        }
        .kpi-label {
            font-size: 12.5px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .kpi-val, .num {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-main);
            margin-top: 4px;
            font-variant-numeric: tabular-nums;
        }
        .kpi-foot {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 4px;
        }

        /* 表单控件 */
        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin: 12px 0 5px;
        }
        input[type=text], input[type=email], input[type=password], input[type=number], input[type=file], textarea, select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-family: inherit;
            color: var(--text-main);
            background: #FFF;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus, textarea:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        textarea { min-height: 84px; resize: vertical; }

        /* 按钮体系 */
        button, .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border: 1px solid transparent;
            border-radius: var(--radius-sm);
            background: var(--primary);
            color: #FFF;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all .15s ease;
            line-height: 1.35;
        }
        button:hover, .btn:hover {
            background: var(--primary-hover);
            color: #FFF;
        }
        .btn.ghost {
            background: #FFF;
            color: #334155;
            border-color: #cbd5e1;
        }
        .btn.ghost:hover {
            background: #F8FAFC;
            border-color: #94A3B8;
            color: #0F172A;
        }
        .btn.danger {
            background: #FEF2F2;
            color: var(--danger);
            border-color: #FECACA;
        }
        .btn.danger:hover {
            background: var(--danger);
            color: #FFF;
            border-color: var(--danger);
        }
        .btn.success {
            background: var(--success);
            color: #FFF;
        }
        .btn.success:hover {
            background: #047857;
            color: #FFF;
        }
        .btn.small {
            padding: 5px 11px;
            font-size: 12.5px;
            border-radius: 6px;
        }

        /* 表格 */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        th, td {
            text-align: left;
            padding: 11px 10px;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: middle;
        }
        th {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 12px;
            background: #F8FAFC;
            border-bottom: 1px solid var(--border-base);
        }
        tr:last-child td { border-bottom: 0; }
        .muted { color: var(--text-muted); font-size: 12.5px; }

        /* 反馈与提示条 */
        .flash {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 16px;
            font-size: 13.5px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .flash.success {
            background: var(--success-soft);
            color: #065F46;
            border: 1px solid #A7F3D0;
        }
        .flash.error {
            background: var(--danger-soft);
            color: #991B1B;
            border: 1px solid #FECACA;
        }
        .notice {
            background: var(--warning-soft);
            border: 1px solid #FDE68A;
            color: #92400E;
            border-radius: var(--radius-md);
            padding: 13px 16px;
            font-size: 13px;
            line-height: 1.7;
            margin-bottom: 16px;
        }
        .notice strong { color: #78350F; }
        .notice.info {
            background: var(--primary-soft);
            border-color: #BFDBFE;
            color: #1E40AF;
        }
        .notice.info strong { color: #1E3A8A; }

        /* 语义徽章 */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: #F1F5F9;
            color: #475569;
            border: 1px solid #E2E8F0;
        }
        .badge.ok {
            background: var(--success-soft);
            color: #047857;
            border-color: #A7F3D0;
        }
        .badge.warn {
            background: var(--warning-soft);
            color: #B45309;
            border-color: #FDE68A;
        }
        .badge.danger {
            background: var(--danger-soft);
            color: #B91C1C;
            border-color: #FECACA;
        }
        .badge.info, .badge.current {
            background: var(--primary-soft);
            color: #1D4ED8;
            border-color: #BFDBFE;
        }
        .badge.purple {
            background: #F5F3FF;
            color: #6D28D9;
            border-color: #DDD6FE;
        }

        .code-chip {
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            background: #F1F5F9;
            color: #334155;
            padding: 2px 7px;
            border-radius: 5px;
            border: 1px solid #E2E8F0;
        }

        .row { display: flex; gap: 14px; flex-wrap: wrap; }
        .row > * { flex: 1; min-width: 200px; }

        /* 搜索筛选条与 Tab 胶囊 */
        .filter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .tab-bar {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #F1F5F9;
            padding: 4px;
            border-radius: 9px;
        }
        .tab-pill {
            border: 0;
            background: transparent;
            color: #475569;
            font-size: 12.5px;
            font-weight: 600;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
        }
        .tab-pill:hover { background: rgba(255,255,255,0.6); color: #0F172A; }
        .tab-pill.active {
            background: #FFF;
            color: var(--primary);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
        }

        /* 通用弹窗（Modal Dialog） */
        .dev-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(3px);
            z-index: 80;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .dev-modal-backdrop.open {
            display: flex;
        }
        .dev-modal {
            background: #FFF;
            border-radius: 16px;
            width: min(680px, 96vw);
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-modal);
            border: 1px solid var(--border-base);
            animation: devModalIn .16s ease-out;
        }
        @keyframes devModalIn {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .dev-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-base);
        }
        .dev-modal-head h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
        }
        .dev-modal-close {
            background: transparent;
            border: 0;
            color: #64748B;
            font-size: 20px;
            padding: 2px 8px;
            cursor: pointer;
        }
        .dev-modal-close:hover { background: #F1F5F9; color: #0F172A; }
        .dev-modal-body { padding: 20px 22px; }
        .dev-modal-foot {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            padding: 14px 22px;
            border-top: 1px solid var(--border-base);
            background: #F8FAFC;
            border-radius: 0 0 16px 16px;
        }

        .empty-state {
            text-align: center;
            padding: 36px 20px;
            color: var(--text-muted);
        }
        .empty-state-icon {
            font-size: 32px;
            margin-bottom: 8px;
        }

        @media (max-width: 900px) {
            .dev-sidebar { display: none; }
            .dev-main { margin-left: 0; }
            .wrap { padding: 18px 14px; }
        }
    </style>
</head>
<body>
@auth('developer')
<div class="dev-shell">
    <aside class="dev-sidebar">
        <div class="dev-brand">
            <div class="dev-brand-logo">⚡</div>
            <div class="dev-brand-text">
                <span class="brand">开发者中心</span>
                <span class="brand-sub">机器人技能供给与发布台</span>
            </div>
        </div>

        <div class="dev-nav-scroll">
            <div class="dev-nav-group-label">工作台与供给</div>
            <a href="{{ route('shop.dev.dashboard') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.dashboard') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">📊</span><span>总览工作台</span></span>
            </a>
            <a href="{{ route('shop.dev.skills.index') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.skills.*') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">🧩</span><span>我的技能</span></span>
            </a>
            <a href="{{ route('shop.dev.orders.index') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.orders.*') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">🧾</span><span>交易与授权订单</span></span>
            </a>

            <div class="dev-nav-group-label" style="margin-top:10px">主体与合规</div>
            <a href="{{ route('shop.dev.realname') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.realname*') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">🪪</span><span>实名认证</span></span>
            </a>
            <a href="{{ route('shop.dev.agreements') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.agreements*') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">📜</span><span>开发者协议</span></span>
            </a>

            <div class="dev-nav-group-label" style="margin-top:10px">审核与留痕</div>
            <a href="{{ route('shop.dev.audits.index') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.audits.*') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">🛡️</span><span>审核状态与日志</span></span>
            </a>
            <a href="{{ route('shop.dev.notifications.index') }}" class="dev-nav-link {{ request()->routeIs('shop.dev.notifications.*') ? 'on' : '' }}">
                <span class="dev-nav-link-left"><span class="dev-nav-icon">🔔</span><span>通知收件箱</span></span>
                @if (($devUnreadCount ?? 0) > 0)
                    <span class="nav-badge">{{ $devUnreadCount }}</span>
                @endif
            </a>
        </div>

        <div class="dev-sidebar-footer">
            <a href="/merchant" class="dev-portal-switch">
                <span>🏪 切换至商家后台</span>
                <span>↗</span>
            </a>
            <div class="dev-user-row">
                <div class="dev-user-meta">
                    <div class="dev-user-name">{{ auth('developer')->user()->name ?? auth('developer')->user()->email ?? '开发者账号' }}</div>
                    <div class="dev-user-sub">{{ auth('developer')->user()->email ?? '' }}</div>
                </div>
                <form method="POST" action="{{ route('shop.dev.logout') }}" style="margin:0">
                    @csrf
                    <button class="btn ghost small" type="submit" style="background:rgba(255,255,255,0.08);color:#E2E8F0;border-color:rgba(255,255,255,0.16)">退出</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="dev-main">
        <header class="topbar">
            <div class="topbar-left">
                <span class="topbar-crumb">召唤机甲 · <strong>@yield('title', '开发者中心')</strong></span>
            </div>
            <nav>
                <a href="{{ route('shop.dev.dashboard') }}" class="{{ request()->routeIs('shop.dev.dashboard') ? 'on' : '' }}">总览</a>
                <a href="{{ route('shop.dev.skills.index') }}" class="{{ request()->routeIs('shop.dev.skills.*') ? 'on' : '' }}">我的技能</a>
                <a href="{{ route('shop.dev.orders.index') }}" class="{{ request()->routeIs('shop.dev.orders.*') ? 'on' : '' }}">交易订单</a>
                <a href="{{ route('shop.dev.realname') }}" class="{{ request()->routeIs('shop.dev.realname*') ? 'on' : '' }}">实名认证</a>
                <a href="{{ route('shop.dev.audits.index') }}" class="{{ request()->routeIs('shop.dev.audits.*') ? 'on' : '' }}">审核状态</a>
                <a href="{{ route('shop.dev.notifications.index') }}" class="{{ request()->routeIs('shop.dev.notifications.*') ? 'on' : '' }}">
                    通知@if (($devUnreadCount ?? 0) > 0)<span class="nav-badge">{{ $devUnreadCount }}</span>@endif
                </a>
            </nav>
        </header>

        <main class="wrap">
            @include('shop::dev.partials.flash')
            @yield('content')
        </main>
    </div>
</div>
@else
<div class="dev-main guest-main">
    <header class="topbar guest-topbar">
        <div class="topbar-left">
            <span class="brand" style="font-weight:700;font-size:15px">⚡ 召唤机甲 · 开发者中心</span>
        </div>
        <nav>
            <a href="/merchant" style="color:#cbd5e1">🏪 商家后台 ↗</a>
        </nav>
    </header>

    <main class="wrap">
        @include('shop::dev.partials.flash')
        @yield('content')
    </main>
</div>
@endauth

<script>
    function openDevModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.add('open');
    }
    function closeDevModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dev-modal-backdrop.open').forEach(function (el) {
                el.classList.remove('open');
            });
        }
    });
</script>
</body>
</html>
