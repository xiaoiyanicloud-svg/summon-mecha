@extends('shop::ecosystem.layout')

@section('content')
    <div class="card">
        <h1 style="margin:0 0 6px;font-size:24px;">赛事活动</h1>
        <p class="muted" style="margin:0 0 18px;">只展示当前已公开发布的活动；草稿与撤稿不会出现在这里。</p>

        <form class="filters" method="GET" action="{{ url('/events') }}">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="50" placeholder="搜索活动 / 城市 / 地点">
            <select name="kind">
                <option value="">全部类型</option>
                @foreach (['competition' => '竞赛', 'salon' => '沙龙', 'workshop' => '工作坊', 'expo' => '展会', 'open_day' => '开放日'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['kind'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="text" name="city" value="{{ $filters['city'] ?? '' }}" maxlength="100" placeholder="城市">
            <select name="registration_state">
                <option value="">全部报名状态</option>
                @foreach (['open' => '报名中', 'upcoming' => '未开始', 'full' => '已满', 'closed' => '已截止', 'ended' => '已结束', 'canceled' => '已取消', 'external' => '外部报名', 'unavailable' => '暂无报名'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['registration_state'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit">筛选</button>
            @if ($hasFilters)
                <a class="btn-line" href="{{ url('/events') }}">清除</a>
            @endif
        </form>

        @if ($cards === [])
            <div class="notice">暂无符合条件的活动。赛事发布后会在这里展示。</div>
        @else
            <div class="grid">
                @foreach ($cards as $card)
                    <a class="card list-card" href="{{ url('/events/'.$card['id']) }}">
                        @if ($card['cover'])
                            <img class="cover" src="{{ $card['cover'] }}" alt="{{ $card['name'] }}">
                        @endif
                        <div class="tags" style="margin-top:10px;">
                            <span>{{ $card['kind'] ?: '活动' }}</span>
                            @if ($card['city'])<span>{{ $card['city'] }}</span>@endif
                        </div>
                        <h2 style="margin:8px 0 4px;font-size:19px;">{{ $card['name'] }}</h2>
                        <p class="muted" style="margin:0;">{{ $card['starts_at'] ?: '时间待定' }}</p>
                        @if ($card['summary'])
                            <p style="margin:8px 0 0;">{{ $card['summary'] }}</p>
                        @endif
                    </a>
                @endforeach
            </div>

            <div class="pager">
                <div>
                    @if ($paginator->currentPage() > 1)
                        <a class="btn-line" href="{{ request()->fullUrlWithQuery(['page' => $paginator->currentPage() - 1]) }}">上一页</a>
                    @endif
                </div>
                <div class="muted">第 {{ $paginator->currentPage() }} / {{ max(1, $paginator->lastPage()) }} 页</div>
                <div>
                    @if ($paginator->currentPage() < $paginator->lastPage())
                        <a class="btn-line" href="{{ request()->fullUrlWithQuery(['page' => $paginator->currentPage() + 1]) }}">下一页</a>
                    @endif
                </div>
            </div>
        @endif
    </div>
@endsection
