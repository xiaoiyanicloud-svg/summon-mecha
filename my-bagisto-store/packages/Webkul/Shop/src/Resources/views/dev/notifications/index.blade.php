@extends('shop::dev.layouts.app')

@section('title', '通知 · 开发者中心')

@section('content')
    <h1>通知@if ($unread > 0)<span class="muted">（{{ $unread }} 条未读）</span>@endif</h1>

    <div class="card">
        @forelse ($notifications as $n)
            <table>
                <tr>
                    <td style="width:16px">
                        {{-- 未读圆点：已读态靠"有没有点"，不只是加粗，色盲用户也能分辨 --}}
                        @unless ($n->read_at)
                            <span title="未读" style="color:#2563eb">●</span>
                        @endunless
                    </td>
                    <td>
                        <div style="font-weight:{{ $n->read_at ? '400' : '600' }}">{{ $n->title }}</div>
                        <div class="muted">{{ $n->content }}</div>

                        {{-- payload 里的 product_id 用来给"去处理"一个落点：驳回后开发者要回技能页改东西 --}}
                        @php $p = (array) $n->payload; @endphp
                        @if (! empty($p['product_id']))
                            <div style="margin-top:4px">
                                <a href="{{ route('shop.dev.skills.show', $p['product_id']) }}">查看技能 →</a>
                            </div>
                        @endif
                    </td>
                    <td style="width:170px" class="muted">{{ optional($n->created_at)->toDateTimeString() }}</td>
                    <td style="width:110px">
                        @unless ($n->read_at)
                            <form method="POST" action="{{ route('shop.dev.notifications.read', $n->id) }}">
                                @csrf
                                <button class="btn ghost small" type="submit">标记已读</button>
                            </form>
                        @else
                            <span class="muted">已读</span>
                        @endunless
                    </td>
                </tr>
            </table>
        @empty
            <p class="muted">还没有审核相关的通知。技能提审后，机审与人审的结果会出现在这里。</p>
        @endforelse
    </div>
@endsection
