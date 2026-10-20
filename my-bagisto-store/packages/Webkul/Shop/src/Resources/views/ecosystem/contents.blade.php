@extends('shop::ecosystem.layout')

@section('content')
    <div class="card">
        <h1 style="margin:0 0 6px;font-size:24px;">动态资讯</h1>
        <p class="muted" style="margin:0 0 18px;">只展示当前已公开发布的文章；更正与撤稿状态以服务端为准。</p>

        <form class="filters" method="GET" action="{{ url('/content') }}">
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="50" placeholder="搜索标题 / 摘要">
            <select name="type">
                <option value="">全部类型</option>
                @foreach (['news' => '资讯', 'announcement' => '公告', 'case' => '案例', 'competition_report' => '赛事报道'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input type="text" name="category" value="{{ $filters['category'] ?? '' }}" maxlength="64" placeholder="分类">
            <button class="btn" type="submit">筛选</button>
            @if ($hasFilters)
                <a class="btn-line" href="{{ url('/content') }}">清除</a>
            @endif
        </form>

        @if ($cards === [])
            <div class="notice">暂无符合条件的资讯。文章发布后会在这里展示。</div>
        @else
            <div class="grid">
                @foreach ($cards as $card)
                    <a class="card list-card" href="{{ url('/content/'.$card['slug']) }}">
                        @if ($card['cover'])
                            <img class="cover" src="{{ $card['cover'] }}" alt="{{ $card['title'] }}">
                        @endif
                        <div class="tags" style="margin-top:10px;">
                            <span>{{ $card['type'] }}</span>
                        </div>
                        <h2 style="margin:8px 0 4px;font-size:19px;">{{ $card['title'] }}</h2>
                        <p class="muted" style="margin:0;">{{ $card['published_at'] ?: '' }}</p>
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
