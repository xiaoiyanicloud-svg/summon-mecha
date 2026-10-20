@extends('shop::ecosystem.layout')

@section('content')
    <article class="card">
        @php
            $registrationLabels = [
                'external_only' => '该活动由外部主办方举办，请在来源页面查看参与方式。',
                'canceled' => '活动已取消。',
                'ended' => '活动已结束。',
                'registration_disabled' => '站内报名暂未开放。',
                'not_open' => '报名尚未开始。',
                'closed' => '报名已截止。',
                'requires_login' => '请登录后报名。',
                'already_registered' => '你已报名，可在「我的报名」查看当前状态。',
                'full' => '名额已满。',
            ];
            $reason = $event['registration']['registration_reason'] ?? '';
            $reasonText = $registrationLabels[$reason] ?? '暂不可报名。';
            $cover = null;
            foreach (($event['media'] ?? []) as $item) {
                if (($item['role'] ?? '') === 'cover') { $cover = $item; break; }
            }
            $cover ??= ($event['media'][0] ?? null);
        @endphp

        @if ($cover)
            <img class="cover" src="{{ url($cover['url']) }}" alt="{{ $cover['alt_text'] ?: $event['name'] }}">
        @endif

        <div class="tags" style="margin-top:14px;">
            <span>{{ $event['kind'] ?: '活动' }}</span>
            @if ($event['city'])<span>{{ $event['city'] }}</span>@endif
            @if ($event['hosting_status'])<span>{{ $event['hosting_status'] }}</span>@endif
        </div>

        <h1 style="margin:10px 0 6px;font-size:26px;">{{ $event['name'] }}</h1>

        @if ($event['summary'])
            <p style="font-size:16px;color:#374151;">{{ $event['summary'] }}</p>
        @endif

        <p class="muted">
            {{ $event['starts_at'] ?: '时间待定' }}
            @if ($event['ends_at']) – {{ $event['ends_at'] }} @endif
            @if ($event['location_text']) · {{ $event['location_text'] }} @endif
        </p>

        <p class="muted">
            主办：{{ $event['organizer']['name'] ?: '未提供' }}
            @if ($event['source']['name']) · 来源：{{ $event['source']['name'] }} @endif
        </p>

        <div class="notice" style="margin:16px 0;">
            {{ $reasonText }}
            @if (! empty($event['external_registration_url']))
                <a href="{{ $event['external_registration_url'] }}" rel="nofollow noopener" target="_blank">前往官方报名页面</a>
            @endif
        </div>

        @if (! empty($event['body_html']))
            <div class="body-html" style="margin-top:18px;">{!! $event['body_html'] !!}</div>
        @endif

        @if (! empty($event['source']['url']))
            <p class="muted" style="margin-top:16px;">
                来源链接：<a href="{{ $event['source']['url'] }}" rel="nofollow noopener" target="_blank">{{ $event['source']['url'] }}</a>
            </p>
        @endif

        @php
            $groups = [
                'events' => '相关活动',
                'contents' => '相关资讯',
                'device_models' => '相关机型',
                'skills' => '相关技能',
                'scenes' => '相关场景',
            ];
            $hasRelated = collect($event['related'] ?? [])->contains(fn ($items) => ! empty($items));
        @endphp

        @if ($hasRelated)
            <section class="related">
                <h2>相关内容</h2>
                @foreach ($groups as $key => $label)
                    @if (! empty($event['related'][$key]))
                        <div class="related-group">
                            <b>{{ $label }}</b><br>
                            @foreach ($event['related'][$key] as $item)
                                @if ($key === 'events')
                                    <a href="{{ url('/events/'.$item['id']) }}">{{ $item['name'] }}</a>
                                @elseif ($key === 'contents')
                                    <a href="{{ url('/content/'.$item['slug']) }}">{{ $item['title'] }}</a>
                                @elseif ($key === 'device_models')
                                    <span>{{ $item['brand'] }} {{ $item['name'] }}（{{ $item['availability'] === 'display_only' ? '仅展示' : '可展示' }}）</span>
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
