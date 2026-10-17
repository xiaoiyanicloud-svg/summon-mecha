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
        {{--
            R3 · 存量非阻断提示：未实名的开发者看到一条**只提示、不阻断**的横条。
            **不动其已有技能**（存量不追溯，拍板 §11.6）。可关闭 —— 纯 CSS（`:target` + 相邻兄弟
            选择器），不引 JS：点「×」把 fragment 设成 `#hide-realname-banner`，
            空 span 命中 `:target` → 紧邻的横条隐藏。
        --}}
        @if ($createGate && ! $createGate['ok'])
            <style>
                .realname-banner {
                    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
                    background: #fffbeb; border: 1px solid #fde68a; color: #92400e;
                    border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px;
                }
                .realname-banner .spacer { flex: 1; }
                .realname-banner-close { color: #92400e; font-size: 20px; line-height: 1; padding: 0 4px; }
                /* 关闭：命中隐藏锚点时，隐藏紧邻的横条（纯 CSS，无 JS） */
                #hide-realname-banner:target + .realname-banner { display: none; }
            </style>
            <span id="hide-realname-banner"></span>
            <div class="realname-banner">
                <span>你还没有完成<strong>实名认证</strong>，暂时不能<strong>创建新技能</strong>；已有技能不受影响。</span>
                <span class="spacer"></span>
                <a class="btn small" href="{{ route('shop.dev.realname') }}">去实名</a>
                <a class="realname-banner-close" href="#hide-realname-banner" aria-label="关闭提示">×</a>
            </div>
        @endif

        {{--
            S-11e · 入驻进度卡：五步 + 三态，**零 JS**。
            顺序即指引 —— 第一个未完成的那步标「进行中」并给直达链接。
            口径硬约束：实名一律写「平台人工审核（人工确认，非身份核验）」，
            严禁出现"实名认证通过 / 已核验身份"（真 OCR / 工商核验未接入）。
        --}}
        <style>
            .onboarding-steps { list-style: none; margin: 0; padding: 0; }
            .onboarding-step {
                display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
                padding: 10px 0; border-bottom: 1px solid #eef0f3;
            }
            .onboarding-step:last-child { border-bottom: 0; }
            .onboarding-step .step-label { min-width: 88px; }
            .onboarding-step .step-detail { flex: 1; }
            .onboarding-step[data-state="current"] .step-label { color: #1d4ed8; }
            .onboarding-step .badge.current { background: #dbeafe; color: #1d4ed8; }
        </style>

        <div class="card">
            <h2>入驻进度</h2>
            <p class="muted" style="margin-top:0">
                下面是入驻的五个步骤，<strong>顺序即指引</strong>：第一个未完成的就是当前要做的事。
                实名与协议均由<strong>平台人工审核（人工确认，非身份核验）</strong>。
            </p>

            <ul class="onboarding-steps">
                @foreach ($onboardingSteps as $step)
                    @php
                        $stepState = $step['done'] ? 'done' : ($step['current'] ? 'current' : 'pending');
                    @endphp
                    <li class="onboarding-step" data-step="{{ $step['key'] }}" data-state="{{ $stepState }}">
                        <span class="badge {{ $step['done'] ? 'ok' : ($step['current'] ? 'current' : '') }}">
                            {{ $step['done'] ? '完成' : ($step['current'] ? '进行中' : '未开始') }}
                        </span>
                        <strong class="step-label">{{ $step['label'] }}</strong>
                        <span class="muted step-detail">{{ $step['detail'] }}</span>
                        @if ($step['current'] && $step['url'])
                            <a class="btn small" href="{{ $step['url'] }}">去完成</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>

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
                        {{-- 如实展示：审核动作属平台端；未通过**不能建技能**（S-12a）且**会挡住上架**，但不拦提审 --}}
                        <span class="badge">{{ $profile->realname_status }}</span>
                        <span class="muted">（由平台审核；未通过不能建技能，也不能上架）</span>
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
                {{-- 未实名：不跳创建页，改为打开实名引导弹窗（S-12d）；已实名正常跳转 --}}
                @if ($createGate && $createGate['ok'])
                    <a class="btn ghost" href="{{ route('shop.dev.skills.create') }}">创建技能</a>
                @else
                    <a class="btn ghost" href="#realname-modal">创建技能</a>
                @endif
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

        {{-- 未实名时的实名引导弹窗（纯 CSS :target，partial 复用） --}}
        @if ($createGate && ! $createGate['ok'])
            @include('shop::dev.partials.realname-modal')
        @endif
    @endif
@endsection
