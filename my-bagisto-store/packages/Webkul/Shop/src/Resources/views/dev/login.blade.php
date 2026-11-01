@extends('shop::dev.layouts.app')

@section('title', '登录 · 开发者中心')

@section('content')
    <div class="card" style="max-width:440px;margin:56px auto;padding:28px 30px">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
            <div style="width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#2563EB,#6366F1);color:#FFF;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700">⚡</div>
            <div>
                <h1 style="margin:0;font-size:20px">开发者登录</h1>
                <p class="muted" style="margin:2px 0 0">使用平台账号登录（与 C 端同一账号体系）。</p>
            </div>
        </div>

        <form method="POST" action="{{ route('shop.dev.login.attempt') }}">
            @csrf

            <label for="email">邮箱</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus placeholder="developer@example.com">

            <label for="password">密码</label>
            <input id="password" type="password" name="password" required placeholder="请输入登录密码">

            <div style="margin-top:20px">
                <button type="submit" style="width:100%;padding:10px 18px">登录</button>
            </div>
        </form>

        <p class="muted" style="margin-top:18px;margin-bottom:0;line-height:1.7">
            还没有账号？请在「召唤机甲」小程序/官网注册后，用同一账号登录这里。
        </p>
    </div>
@endsection
