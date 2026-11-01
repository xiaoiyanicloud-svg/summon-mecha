<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '商家工作台') · 召唤机甲 SummonMecha</title>
    <style>
        :root {
            --brand-navy: #0B1F4E;
            --brand-blue: #165DFF;
            --brand-blue-hover: #0E4BD8;
            --brand-blue-soft: #EEF4FF;
            --bg-page: #F4F6FA;
            --bg-surface: #FFFFFF;
            --bg-subtle: #F8FAFC;
            --sidebar-bg: #0B1528;
            --sidebar-hover: rgba(255, 255, 255, 0.07);
            --sidebar-active: #165DFF;
            --text-primary: #0F172A;
            --text-secondary: #475569;
            --text-muted: #94A3B8;
            --border-subtle: #E2E8F0;
            --border-strong: #CBD5E1;
            --success: #059669;
            --success-bg: #ECFDF5;
            --success-border: #A7F3D0;
            --warning: #D97706;
            --warning-bg: #FFFBEB;
            --warning-border: #FDE68A;
            --danger: #DC2626;
            --danger-bg: #FEF2F2;
            --danger-border: #FECACA;
            --radius-xl: 16px;
            --radius-lg: 12px;
            --radius-md: 8px;
            --shadow-xs: 0 1px 2px rgba(15, 23, 42, 0.04);
            --shadow-card: 0 1px 3px rgba(15, 23, 42, 0.05), 0 6px 20px rgba(15, 23, 42, 0.03);
            --shadow-modal: 0 20px 48px rgba(15, 23, 42, 0.22), 0 4px 12px rgba(15, 23, 42, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Segoe UI", sans-serif;
            background: var(--bg-page);
            color: var(--text-primary);
            font-size: 13.5px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }
        a { color: var(--brand-blue); text-decoration: none; transition: all .15s ease; }
        a:hover { color: var(--brand-blue-hover); }
        code {
            font-family: "JetBrains Mono", "SFMono-Regular", Consolas, monospace;
            font-size: 12px;
            background: #F1F5F9;
            color: #334155;
            padding: 2px 6px;
            border-radius: 5px;
        }

        /* ── SaaS 双栏工作台骨架 (Sidebar + Main) ── */
        .app-shell {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 248px;
            background: var(--sidebar-bg);
            color: #E2E8F0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 50;
            border-right: 1px solid rgba(255, 255, 255, 0.06);
        }
        .sidebar-brand {
            height: 64px;
            padding: 0 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        }
        .brand-logo {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, #2563EB, #06B6D4);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            font-weight: 800;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
            flex-shrink: 0;
        }
        .brand-title {
            font-size: 15px;
            font-weight: 700;
            color: #FFFFFF;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }
        .brand-sub {
            font-size: 11px;
            color: #94A3B8;
            margin-top: 2px;
        }

        .sidebar-scroll {
            flex: 1;
            padding: 18px 12px;
            overflow-y: auto;
        }
        .nav-group-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #64748B;
            padding: 12px 12px 6px;
        }
        .nav-group-label:first-child { padding-top: 0; }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            margin-bottom: 3px;
            border-radius: 9px;
            color: #CBD5E1;
            font-size: 13.5px;
            font-weight: 500;
            transition: all .15s ease;
        }
        .nav-item svg {
            width: 17px;
            height: 17px;
            opacity: 0.78;
            flex-shrink: 0;
        }
        .nav-item:hover {
            background: var(--sidebar-hover);
            color: #FFFFFF;
        }
        .nav-item:hover svg { opacity: 1; }
        .nav-item.active {
            background: var(--sidebar-active);
            color: #FFFFFF;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(22, 93, 255, 0.32);
        }
        .nav-item.active svg { opacity: 1; }

        .sidebar-footer {
            padding: 14px 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.07);
            background: rgba(15, 23, 42, 0.45);
        }
        .portal-switch-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            color: #E2E8F0;
            font-size: 12.5px;
            font-weight: 500;
        }
        .portal-switch-link:hover {
            background: rgba(255, 255, 255, 0.12);
            color: #FFFFFF;
        }

        .main-wrapper {
            flex: 1;
            margin-left: 248px;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }

        /* ── 顶部粘性状态栏 ── */
        .topbar {
            height: 64px;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-subtle);
            position: sticky;
            top: 0;
            z-index: 40;
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .shop-switcher {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-subtle);
            border: 1px solid var(--border-subtle);
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 12.5px;
            transition: border-color .15s;
        }
        .shop-switcher:hover { border-color: var(--border-strong); }
        .shop-switcher select {
            border: none;
            background: transparent;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
            outline: none;
            cursor: pointer;
        }

        .container {
            max-width: 1360px;
            width: 100%;
            margin: 0 auto;
            padding: 28px 32px 64px;
        }

        /* ── 独立认证页顶栏（未进入店铺前） ── */
        .standalone-topbar {
            height: 64px;
            background: #FFFFFF;
            border-bottom: 1px solid var(--border-subtle);
            padding: 0 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ── 页面标题头 ── */
        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
            letter-spacing: -0.01em;
        }
        .page-desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .page-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ── 卡片与面板 ── */
        .card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 22px 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
        }
        .card-flush {
            padding: 0;
            overflow: hidden;
        }
        .card-flush .card-header {
            padding: 18px 24px;
            margin-bottom: 0;
            border-bottom: 1px solid var(--border-subtle);
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .card-title {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
            color: var(--text-primary);
        }
        .card-subtitle {
            font-size: 12.5px;
            color: var(--text-secondary);
            margin-top: 3px;
        }

        /* ── KPI 指标卡与待办矩阵 ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .kpi-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
        }
        .kpi-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--brand-blue), #38BDF8);
            opacity: 0.85;
        }
        .kpi-label {
            font-size: 12.5px;
            color: var(--text-secondary);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .kpi-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 8px 0 6px;
            letter-spacing: -0.02em;
            font-variant-numeric: tabular-nums;
        }
        .kpi-basis {
            font-size: 11.5px;
            color: var(--text-muted);
        }

        .todo-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .todo-card {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 15px 16px;
            color: var(--text-primary);
            box-shadow: var(--shadow-xs);
            transition: all .16s ease;
        }
        .todo-card:hover {
            border-color: var(--brand-blue);
            box-shadow: 0 6px 18px rgba(22, 93, 255, 0.08);
            transform: translateY(-2px);
        }
        .todo-card .todo-title {
            font-size: 12px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        .todo-card .todo-num {
            font-size: 24px;
            font-weight: 700;
            margin: 6px 0;
            font-variant-numeric: tabular-nums;
        }
        .todo-card .todo-hint {
            font-size: 11.5px;
            color: var(--brand-blue);
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ── 按钮体系 ── */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 15px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .15s ease;
            white-space: nowrap;
            line-height: 1.3;
        }
        .btn-primary {
            background: var(--brand-blue);
            color: #fff;
            box-shadow: 0 1px 2px rgba(22, 93, 255, 0.2);
        }
        .btn-primary:hover { background: var(--brand-blue-hover); color: #fff; }
        .btn-secondary {
            background: #fff;
            border-color: var(--border-strong);
            color: var(--text-primary);
        }
        .btn-secondary:hover { background: var(--bg-subtle); border-color: #94A3B8; color: var(--text-primary); }
        .btn-soft {
            background: var(--brand-blue-soft);
            color: var(--brand-blue);
            border-color: rgba(22, 93, 255, 0.15);
        }
        .btn-soft:hover { background: #DCE8FF; color: var(--brand-blue-hover); }
        .btn-danger {
            background: var(--danger-bg);
            border-color: var(--danger-border);
            color: var(--danger);
        }
        .btn-danger:hover { background: #FEE2E2; color: #B91C1C; }
        .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

        /* ── 语义徽标 (带状态圆点) ── */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            line-height: 1.4;
            white-space: nowrap;
        }
        .badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
        .badge-green { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
        .badge-amber { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning-border); }
        .badge-red { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }
        .badge-blue { background: var(--brand-blue-soft); color: var(--brand-blue); border: 1px solid rgba(22, 93, 255, 0.18); }
        .badge-gray { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }

        /* ── 筛选工具栏 ── */
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 14px 24px;
            background: #FAFBFD;
            border-bottom: 1px solid var(--border-subtle);
        }
        .filter-bar select, .filter-bar input[type=text] {
            height: 34px;
            padding: 0 11px;
            border: 1px solid var(--border-strong);
            border-radius: 7px;
            font-size: 12.5px;
            background: #fff;
            color: var(--text-primary);
            outline: none;
        }
        .filter-bar select:focus, .filter-bar input[type=text]:focus {
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }

        /* ── 数据表格 ── */
        .table-wrap { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left;
            padding: 11px 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border-subtle);
            background: #F8FAFC;
            white-space: nowrap;
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #F1F5F9;
            font-size: 13px;
            vertical-align: middle;
        }
        tbody tr { transition: background .12s ease; }
        tbody tr:hover { background: #FAFBFD; }
        tr:last-child td { border-bottom: none; }

        /* ── 表单与输入控件 ── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .field { margin-bottom: 14px; }
        .field label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }
        .field-hint {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .field input[type=text],
        .field input[type=email],
        .field input[type=password],
        .field input[type=number],
        .field select,
        .field textarea {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-strong);
            border-radius: 8px;
            font-size: 13px;
            color: var(--text-primary);
            background: #fff;
            transition: all .15s;
        }
        .field input:focus, .field select:focus, .field textarea:focus {
            outline: none;
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.12);
        }

        /* ── 模态弹窗 (Modal / Slide-over) ── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.48);
            backdrop-filter: blur(4px);
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .modal-backdrop.is-open {
            display: flex;
        }
        .modal-dialog {
            background: #FFFFFF;
            border-radius: var(--radius-xl);
            width: 100%;
            max-width: 680px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-modal);
            border: 1px solid var(--border-subtle);
            animation: modalPop .16s ease-out;
        }
        .modal-dialog.modal-sm { max-width: 480px; }
        @keyframes modalPop {
            from { opacity: 0; transform: translateY(8px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .modal-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }
        .modal-close {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: none;
            background: var(--bg-subtle);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 15px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .modal-close:hover { background: #E2E8F0; color: var(--text-primary); }
        .modal-body {
            padding: 22px 24px;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 14px 24px;
            border-top: 1px solid var(--border-subtle);
            background: var(--bg-subtle);
            border-bottom-left-radius: var(--radius-xl);
            border-bottom-right-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        /* ── 状态进度条 (Stepper) ── */
        .stepper {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: var(--text-muted);
        }
        .step-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 9px;
            border-radius: 6px;
            background: #F1F5F9;
            color: #64748B;
            font-weight: 500;
        }
        .step-pill.done { background: var(--success-bg); color: var(--success); }
        .step-pill.current { background: var(--brand-blue-soft); color: var(--brand-blue); font-weight: 600; }

        /* ── 空状态 ── */
        .empty-box {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-secondary);
        }
        .empty-box-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: var(--brand-blue-soft);
            color: var(--brand-blue);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 12px;
        }
        .empty-box-title {
            font-size: 14.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .empty-box-desc {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .flash {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .flash-success { background: var(--success-bg); color: #065F46; border: 1px solid var(--success-border); }
        .flash-error { background: var(--danger-bg); color: #991B1B; border: 1px solid var(--danger-border); }

        @media (max-width: 1024px) {
            .sidebar { position: static; width: 100%; height: auto; }
            .sidebar-scroll { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 16px; }
            .nav-group-label { display: none; }
            .app-shell { flex-direction: column; }
            .main-wrapper { margin-left: 0; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
            .todo-grid { grid-template-columns: repeat(2, 1fr); }
            .form-grid, .form-grid-3 { grid-template-columns: 1fr; }
            .topbar, .container { padding-left: 16px; padding-right: 16px; }
        }
    </style>
</head>
<body>
@auth('merchant')
    @if(isset($currentMerchantShop))
        <div class="app-shell">
            {{-- 左侧企业级多租户导航栏 --}}
            <aside class="sidebar" aria-label="商家工作台主导航">
                <div class="sidebar-brand">
                    <span class="brand-logo">M</span>
                    <div>
                        <div class="brand-title">召唤机甲 · 商家中心</div>
                        <div class="brand-sub">Multi-Tenant Cloud</div>
                    </div>
                </div>

                <nav class="sidebar-scroll">
                    <div class="nav-group-label">经营总览</div>
                    <a href="{{ route('shop.merchant.dashboard') }}" class="nav-item {{ ($activeTab ?? '') === 'overview' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg>
                        <span>概览看板</span>
                    </a>

                    <div class="nav-group-label">商品与资产</div>
                    <a href="{{ route('shop.merchant.products') }}" class="nav-item {{ ($activeTab ?? '') === 'products' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                        <span>商品与租赁</span>
                    </a>
                    <a href="{{ route('shop.merchant.inventory') }}" class="nav-item {{ ($activeTab ?? '') === 'inventory' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <span>双轨库存</span>
                    </a>

                    <div class="nav-group-label">履约与服务</div>
                    <a href="{{ route('shop.merchant.orders') }}" class="nav-item {{ ($activeTab ?? '') === 'orders' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <span>订单履约</span>
                    </a>
                    <a href="{{ route('shop.merchant.after-sales') }}" class="nav-item {{ ($activeTab ?? '') === 'after_sales' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        <span>售后维保</span>
                    </a>

                    <div class="nav-group-label">财务与治理</div>
                    <a href="{{ route('shop.merchant.settlements') }}" class="nav-item {{ ($activeTab ?? '') === 'settlements' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        <span>结算开票</span>
                    </a>
                    <a href="{{ route('shop.merchant.settings') }}" class="nav-item {{ ($activeTab ?? '') === 'settings' ? 'active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        <span>设置与成员</span>
                    </a>
                </nav>

                <div class="sidebar-footer">
                    <a href="/dev" class="portal-switch-link" title="切换至开发者技能后台">
                        <span>🧑‍💻 开发者技能后台</span>
                        <span style="opacity: 0.7;">↗</span>
                    </a>
                </div>
            </aside>

            <div class="main-wrapper">
                <header class="topbar">
                    <div class="topbar-left">
                        <span class="badge badge-blue">
                            <span class="badge-dot"></span>
                            租户节点 #{{ $currentMerchantShop->seller_id }}
                        </span>
                        <span class="badge badge-gray">
                            角色：{{ $rolesConfig[$currentMerchantMember->role_code]['name'] ?? $currentMerchantMember->role_code }}
                        </span>
                        <span style="font-size: 12px; color: var(--text-muted);">
                            时区：{{ $currentMerchantShop->timezone ?? 'Asia/Shanghai' }}
                        </span>
                    </div>

                    <div class="topbar-right">
                        @if(isset($merchantShops) && count($merchantShops) > 0)
                            <form method="POST" action="{{ route('shop.merchant.shops.switch') }}" class="shop-switcher">
                                @csrf
                                <span style="color:var(--text-muted)">当前店铺</span>
                                <select name="shop_id" onchange="this.form.submit()" aria-label="切换经营店铺">
                                    @foreach($merchantShops as $s)
                                        <option value="{{ $s->id }}" {{ (int) $s->id === (int) $currentMerchantShop->id ? 'selected' : '' }}>
                                            {{ $s->shop_name }} (#{{ $s->seller_id }})
                                        </option>
                                    @endforeach
                                </select>
                            </form>
                        @endif

                        <form method="POST" action="{{ route('shop.merchant.logout') }}" style="margin:0">
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-sm">安全退出</button>
                        </form>
                    </div>
                </header>

                <main class="container">
                    @if(session('success'))
                        <div class="flash flash-success" role="status">
                            <span>✓ {{ session('success') }}</span>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="flash flash-error" role="alert">
                            <span>✕ {{ session('error') }}</span>
                        </div>
                    @endif
                    @if($errors->any())
                        <div class="flash flash-error" role="alert">
                            <div>
                                @foreach($errors->all() as $err)
                                    <div>· {{ $err }}</div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @yield('content')
                </main>
            </div>
        </div>
    @else
        {{-- 已登录但尚未开通店铺或无权限页 --}}
        <header class="standalone-topbar">
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="brand-logo">M</span>
                <span style="font-weight: 700; font-size: 15px;">召唤机甲 · 商家中心</span>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <a href="/dev" class="btn btn-secondary btn-sm">开发者技能后台 ↗</a>
                <form method="POST" action="{{ route('shop.merchant.logout') }}" style="margin:0">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">退出登录</button>
                </form>
            </div>
        </header>
        <main class="container">
            @if(session('success'))
                <div class="flash flash-success" role="status">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="flash flash-error" role="alert">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="flash flash-error" role="alert">
                    @foreach($errors->all() as $err)
                        <div>· {{ $err }}</div>
                    @endforeach
                </div>
            @endif
            @yield('content')
        </main>
    @endif
@else
    {{-- 未登录态顶栏 --}}
    <header class="standalone-topbar">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span class="brand-logo">M</span>
            <span style="font-weight: 700; font-size: 15px;">召唤机甲 · 商家中心</span>
            <span class="badge badge-blue">Enterprise SaaS</span>
        </div>
        <a href="/dev/login" class="btn btn-secondary btn-sm">前往开发者技能后台 ↗</a>
    </header>
    <main class="container">
        @if(session('success'))
            <div class="flash flash-success" role="status">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="flash flash-error" role="alert">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="flash flash-error" role="alert">
                @foreach($errors->all() as $err)
                    <div>· {{ $err }}</div>
                @endforeach
            </div>
        @endif
        @yield('content')
    </main>
@endauth

<script>
    function openMerchantModal(id) {
        var el = document.getElementById(id);
        if (el) {
            el.classList.add('is-open');
            var firstInput = el.querySelector('input:not([type=hidden]), select, textarea');
            if (firstInput) setTimeout(function() { firstInput.focus(); }, 40);
        }
    }
    function closeMerchantModal(id) {
        var el = document.getElementById(id);
        if (el) el.classList.remove('is-open');
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var openModals = document.querySelectorAll('.modal-backdrop.is-open');
            openModals.forEach(function(m) { m.classList.remove('is-open'); });
        }
    });
</script>
</body>
</html>
