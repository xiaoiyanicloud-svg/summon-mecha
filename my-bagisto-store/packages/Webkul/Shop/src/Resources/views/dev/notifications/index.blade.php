@extends('shop::dev.layouts.app')

@section('title', '通知 · 开发者中心')

@section('content')
    <div class="page-head">
        <div>
            <h1>通知@if ($unread > 0)<span class="muted">（{{ $unread }} 条未读）</span>@endif</h1>
            <p class="page-head-sub">接收技能机审、人工复核、发布上架与实名审核状态变更通知。</p>
        </div>
        <div class="page-head-actions">
            @if ($unread > 0)
                <form method="POST" action="{{ route('shop.dev.notifications.read-all') }}" style="margin:0">
                    @csrf
                    <button type="submit" class="btn ghost small">✓ 全部标记已读 ({{ $unread }})</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card">
        @if ($notifications->isNotEmpty())
            <div class="filter-bar">
                <div class="tab-bar">
                    <button type="button" class="tab-pill active" onclick="filterNotifRows('all', this)">全部 ({{ $notifications->count() }})</button>
                    <button type="button" class="tab-pill" onclick="filterNotifRows('unread', this)">未读 ({{ $unread }})</button>
                </div>
            </div>

            <table id="dev-notif-table">
                <thead>
                    <tr>
                        <th style="width:28px"></th>
                        <th>通知内容</th>
                        <th style="width:170px">时间</th>
                        <th style="width:120px;text-align:right">状态 / 操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($notifications as $n)
                        <tr class="notif-row" data-read="{{ $n->read_at ? 'read' : 'unread' }}">
                            <td style="width:28px;text-align:center">
                                {{-- 未读圆点：已读态靠"有没有点"，不只是加粗，色盲用户也能分辨 --}}
                                @unless ($n->read_at)
                                    <span title="未读" style="color:#2563eb;font-size:15px">●</span>
                                @endunless
                            </td>
                            <td>
                                <div style="font-weight:{{ $n->read_at ? '400' : '600' }};font-size:14px">{{ $n->title }}</div>
                                <div class="muted" style="margin-top:3px">{{ $n->content }}</div>

                                {{-- payload 里的 product_id 用来给"去处理"一个落点：驳回后开发者要回技能页改东西 --}}
                                @php $p = (array) $n->payload; @endphp
                                @if (! empty($p['product_id']))
                                    <div style="margin-top:6px">
                                        <a href="{{ route('shop.dev.skills.show', $p['product_id']) }}" style="font-weight:600;font-size:12.5px">查看技能 →</a>
                                    </div>
                                @endif
                            </td>
                            <td class="muted">{{ optional($n->created_at)->toDateTimeString() }}</td>
                            <td style="text-align:right">
                                @unless ($n->read_at)
                                    <form method="POST" action="{{ route('shop.dev.notifications.read', $n->id) }}" style="margin:0">
                                        @csrf
                                        <button class="btn ghost small" type="submit">标记已读</button>
                                    </form>
                                @else
                                    <span class="muted">已读</span>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty-state">
                <div class="empty-state-icon">🔔</div>
                <p class="muted">还没有审核相关的通知。技能提审后，机审与人审的结果会出现在这里。</p>
            </div>
        @endif
    </div>

    <script>
        function filterNotifRows(mode, btn) {
            if (btn && btn.parentNode) {
                btn.parentNode.querySelectorAll('.tab-pill').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
            }
            document.querySelectorAll('#dev-notif-table .notif-row').forEach(function (tr) {
                var show = (mode === 'all') || (tr.getAttribute('data-read') === mode);
                tr.style.display = show ? '' : 'none';
            });
        }
    </script>
@endsection
