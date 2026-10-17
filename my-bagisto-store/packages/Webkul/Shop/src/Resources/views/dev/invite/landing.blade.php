@extends('shop::dev.layouts.app')

@section('title', '邀请入驻 · 开发者中心')

@section('content')
    @if ($state === 'ok')
        <div class="card" style="max-width:680px;margin:40px auto">
            <h1>邀请入驻</h1>

            <p>
                你带来的邀请码是 <strong>{{ $code }}</strong>。
                它已保留在你这次会话里，接下来的注册 / 入驻步骤不必手抄。
            </p>

            <div class="notice">
                <strong>邀请码不是准入闸门。</strong>
                凭邀请链接进来后，<strong>仍需实名与协议</strong>：实名认证分「企业主体 + 操作人」两层，
                并要签署开发者协议，之后才能创建与上架技能。邀请码只用于确认你的来源与归属。
            </div>

            <h2>完整链路</h2>
            <p class="muted" style="line-height:1.9">
                注册 → 入驻 → 实名 → 协议 → 建技能
            </p>

            <h2>下一步</h2>
            <p class="muted">请选择与你情况相符的一条：</p>

            <div class="row" style="margin-top:12px">
                <div class="card" style="margin-bottom:0">
                    <h2>我已经有平台账号</h2>
                    <p class="muted">用同一账号登录开发者中心，登录后继续入驻流程。</p>
                    <a class="btn" href="{{ route('shop.dev.login') }}">去登录</a>
                </div>

                <div class="card" style="margin-bottom:0">
                    <h2>我还没有账号</h2>
                    <p class="muted">先在「召唤机甲」注册 C 端账号，再用同一账号登录开发者中心。</p>
                    <a class="btn ghost" href="{{ route('shop.customers.register.index') }}">去注册</a>
                </div>
            </div>
        </div>
    @else
        <div class="card" style="max-width:680px;margin:40px auto">
            <h1>{{ $state === 'missing' ? '邀请链接不完整' : '这个邀请码暂时不能用' }}</h1>

            <div class="flash error">{{ $message }}</div>

            <p class="muted">
                邀请码只影响「来源与归属」的留痕，<strong>不是入驻的准入条件</strong>。
                即使这个链接失效，你仍然可以直接走自助申请入驻，不会被卡住。
            </p>

            <h2>可以这样继续</h2>

            <div class="row" style="margin-top:12px">
                <div class="card" style="margin-bottom:0">
                    <h2>重新索取邀请码</h2>
                    <p class="muted">
                        请联系邀请你入驻的人，让他在运营端重新发一个可用的邀请码，
                        再打开新的邀请链接即可。
                    </p>
                </div>

                <div class="card" style="margin-bottom:0">
                    <h2>直接自助申请入驻</h2>
                    <p class="muted">无需邀请码：已有账号直接登录；没有账号先注册。</p>
                    <p>
                        <a class="btn small" href="{{ route('shop.dev.login') }}">去登录</a>
                        <a class="btn ghost small" href="{{ route('shop.customers.register.index') }}">去注册</a>
                    </p>
                </div>
            </div>
        </div>
    @endif
@endsection
