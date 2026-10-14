@extends('shop::dev.layouts.app')

@section('title', '总览 · 开发者中心')

@section('content')
    <h1>总览</h1>

    @if (! $profile)
        <div class="card">
            <h2>还没有开发者主体</h2>
            <p class="muted">完成入驻后即可创建技能、上传技能包并提交审核。</p>
            <a class="btn" href="{{ route('shop.dev.onboarding') }}">开始入驻</a>
        </div>
    @else
        <div class="card">
            <h2>开发者信息</h2>
            <table>
                <tr>
                    <th style="width:180px">主体类型</th>
                    <td>{{ $profile->dev_type === 'company' ? '企业' : '个人' }}</td>
                </tr>
                <tr>
                    <th>实名状态</th>
                    <td>
                        {{-- 如实展示：本轮审核动作属平台端，未做，故停在 pending，不粉饰 --}}
                        <span class="badge">{{ $profile->realname_status }}</span>
                        <span class="muted">（由平台审核，不影响你建技能与提审）</span>
                    </td>
                </tr>
                <tr>
                    <th>协议签署</th>
                    <td>
                        @if ($agreementsOk)
                            <span class="badge ok">已签署</span>
                        @else
                            <span class="badge">未签署</span>
                            <a class="btn small" href="{{ route('shop.dev.agreements') }}" style="margin-left:8px">去签署</a>
                        @endif
                    </td>
                </tr>
            </table>

            <div style="margin-top:16px">
                <a class="btn" href="{{ route('shop.dev.skills.index') }}">我的技能</a>
                <a class="btn ghost" href="{{ route('shop.dev.skills.create') }}">创建技能</a>
            </div>
        </div>
    @endif
@endsection
