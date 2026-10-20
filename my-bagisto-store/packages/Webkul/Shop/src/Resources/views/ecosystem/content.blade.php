@extends('shop::ecosystem.layout')

@section('content')
    <article class="card">
        @php
            $cover = null;
            foreach (($post['media'] ?? []) as $item) {
                if (($item['role'] ?? '') === 'cover') { $cover = $item; break; }
            }
            $cover ??= ($post['media'][0] ?? null);
        @endphp

        @if ($cover)
            <img class="cover" src="{{ url($cover['url']) }}" alt="{{ $cover['alt_text'] ?: $post['title'] }}">
        @endif

        <div class="tags" style="margin-top:14px;">
            <span>{{ $post['type'] }}</span>
        </div>

        <h1 style="margin:10px 0 6px;font-size:26px;">{{ $post['title'] }}</h1>

        @if ($post['summary'])
            <p style="font-size:16px;color:#374151;">{{ $post['summary'] }}</p>
        @endif

        <p class="muted">
            {{ $post['author']['name'] ?: '未署名' }}
            @if ($post['author']['org']) · {{ $post['author']['org'] }} @endif
            @if ($post['first_published_at']) · 首次公开 {{ $post['first_published_at'] }} @endif
        </p>

        @if ($post['correction_note'])
            <div class="notice" style="margin:14px 0;">更正说明：{{ $post['correction_note'] }}</div>
        @endif

        @if (! empty($post['body_html']))
            <div class="body-html" style="margin-top:18px;">{!! $post['body_html'] !!}</div>
        @endif

        @if (! empty($post['sources']))
            <div class="related">
                <h2>来源</h2>
                @foreach ($post['sources'] as $source)
                    <p class="muted" style="margin:4px 0;">
                        {{ $source['name'] }}
                        @if ($source['url'])
                            · <a href="{{ $source['url'] }}" rel="nofollow noopener" target="_blank">{{ $source['url'] }}</a>
                        @endif
                        @if ($source['rights_basis']) · {{ $source['rights_basis'] }} @endif
                    </p>
                @endforeach
            </div>
        @endif

        @php
            $groups = [
                'events' => '相关活动',
                'contents' => '相关资讯',
                'device_models' => '相关机型',
                'skills' => '相关技能',
                'scenes' => '相关场景',
            ];
            $hasRelated = collect($post['related'] ?? [])->contains(fn ($items) => ! empty($items));
        @endphp

        @if ($hasRelated)
            <section class="related">
                <h2>相关内容</h2>
                @foreach ($groups as $key => $label)
                    @if (! empty($post['related'][$key]))
                        <div class="related-group">
                            <b>{{ $label }}</b><br>
                            @foreach ($post['related'][$key] as $item)
                                @if ($key === 'events')
                                    <a href="{{ url('/events/'.$item['id']) }}">{{ $item['name'] }}</a>
                                @elseif ($key === 'contents')
                                    <a href="{{ url('/content/'.$item['slug']) }}">{{ $item['title'] }}</a>
                                @elseif ($key === 'device_models')
                                    <span>{{ $item['brand'] }} {{ $item['name'] }}</span>
                                @elseif ($key === 'skills')
                                    <span>{{ $item['name'] }}</span>
                                @elseif ($key === 'scenes')
                                    <span>{{ $item['name'] }}</span>
                                @endif
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </section>
        @endif
    </article>
@endsection
