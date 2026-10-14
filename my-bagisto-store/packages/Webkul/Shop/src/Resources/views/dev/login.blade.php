@extends('shop::dev.layouts.app')

@section('title', '登录 · 开发者中心')

@section('content')
    <div class="card" style="max-width:420px;margin:60px auto">
        <h1>开发者登录</h1>
        <p class="muted">使用平台账号登录（与 C 端同一账号体系）。</p>

        <form method="POST" action="{{ route('shop.dev.login.attempt') }}">
            @csrf

            <label for="email">邮箱</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>

            <label for="password">密码</label>
            <input id="password" type="password" name="password" required>

            <div style="margin-top:18px">
                <button type="submit">登录</button>
            </div>
        </form>

        <p class="muted" style="margin-top:16px">
            还没有账号？请在「召唤机甲」小程序/官网注册后，用同一账号登录这里。
        </p>
    </div>
@endsection
