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
                        {{-- 如实展示：审核动作属平台端；未通过**不影响**建技能与提审，但**会挡住上架** --}}
                        <span class="badge">{{ $profile->realname_status }}</span>
                        <span class="muted">（由平台审核；未通过不影响你建技能与提审，但不能上架）</span>
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

        @if ($subjectIncomplete)
            <div class="card">
                <h2>主体未就绪</h2>
                <p class="muted">
                    你的开发者主体还没有完成归属登记，因此这里<strong>一条数据都不展示</strong>。
                    展示一份不属于你的数字（或一片 0）比不展示更糟 —— 请联系平台运营处理归属。
                </p>
            </div>
        @else
            {{--
                ⚠ 这块的**顺序是刻意的，别调整**：状态句 → 暂不提供的数据 → 已统计的数字。

                理由：开发者打开这个页面，真正的问题是「有多少人在用」。
                而平台现在恰恰没有曝光量与安装量的统计 —— 第一眼看到一串 0 或个位数，
                读到的结论会是"我的技能没人用"，而真相是"这一项平台没在统计"。
                两者结论相反，长得却一样。所以要在他读到任何数字**之前**先把边界说清楚。
                这条顺序有测试锁着（assertSeeTextInOrder）。
            --}}
            <div class="card">
                <h2>我的数据</h2>

                <div class="notice">
                    <strong>先说清楚这份数据的边界：它是不完整的。</strong>
                    平台目前没有接入曝光量与安装量的统计 ——
                    <strong>下面的数字回答不了「有多少人在用」</strong>。
                    每一项缺什么、为什么缺，就列在紧下面。
                </div>

                <h2 style="margin-top:18px">暂不提供的数据</h2>
                <table>
                    @foreach ($gaps as $gap)
                        <tr>
                            <th style="width:200px; vertical-align:top">{{ $gap['name'] }}</th>
                            <td class="muted">{{ $gap['reason'] }}</td>
                        </tr>
                    @endforeach
                </table>

                <h2 style="margin-top:18px">已统计的部分</h2>
                <table>
                    @foreach ($metrics as $key => $m)
                        <tr>
                            <th style="width:200px; vertical-align:top">{{ $m['basis'] }}</th>
                            <td>
                                @if (! $m['ready'])
                                    {{-- 未接入就不给数字。这条分支现在走不到（forSeller 只回可算的），
                                         留着是为了将来有人往里加"暂时算不出来"的维度时，默认是诚实的。 --}}
                                    <span class="badge">未接入</span>
                                @else
                                    <span class="num">{{ $m['value'] === null ? '—' : number_format((float) $m['value'], is_float($m['value']) ? 2 : 0) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>

                <p class="muted" style="margin-top:12px">
                    这些数字都只统计<strong>你自己的</strong>技能，实时取自平台数据库。
                </p>
            </div>
        @endif
    @endif
@endsection
