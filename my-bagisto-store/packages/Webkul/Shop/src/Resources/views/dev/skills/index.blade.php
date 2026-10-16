@extends('shop::dev.layouts.app')

@section('title', '我的技能 · 开发者中心')

@section('content')
    <div style="display:flex;align-items:center;justify-content:space-between">
        <h1>我的技能</h1>
        {{-- 未实名：不跳创建页，改为打开实名引导弹窗（S-12d）；已实名正常跳转。
             判定取自 createGate（DeveloperSkillService::canCreateSkill() 唯一实现），门户不重算。 --}}
        @if ($createGate['ok'])
            <a class="btn" href="{{ route('shop.dev.skills.create') }}">创建技能</a>
        @else
            <a class="btn" href="#realname-modal">创建技能</a>
        @endif
    </div>

    <div class="card">
        @forelse ($skills as $skill)
            <table>
                <tr>
                    <td style="width:60%">
                        <a href="{{ route('shop.dev.skills.show', $skill->id) }}"><strong>{{ $skill->name }}</strong></a>
                        <div class="muted">SKU: {{ $skill->sku }}</div>
                    </td>
                    <td>￥{{ number_format((float) $skill->price, 2) }}</td>
                    <td style="width:120px">
                        {{-- 未上架是常态：新建即草稿，上架由审核通过后决定 --}}
                        <span class="badge {{ $skill->status ? 'ok' : '' }}">
                            {{ $skill->status ? '已上架' : '未上架' }}
                        </span>
                    </td>
                </tr>
            </table>
        @empty
            <p class="muted">还没有技能。点右上角「创建技能」开始。</p>
        @endforelse
    </div>

    {{-- 未实名时的实名引导弹窗（纯 CSS :target，partial 复用） --}}
    @unless ($createGate['ok'])
        @include('shop::dev.partials.realname-modal')
    @endunless
@endsection
