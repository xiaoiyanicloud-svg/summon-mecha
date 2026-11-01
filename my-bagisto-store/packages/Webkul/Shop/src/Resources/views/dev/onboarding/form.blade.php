@extends('shop::dev.layouts.app')

@section('title', '入驻 · 开发者中心')

@section('content')
    <div class="page-head">
        <div>
            <h1>开发者入驻</h1>
            <p class="page-head-sub">建立独立开发者主体归属（seller_id），开启机器人技能研发与商业化变现。</p>
        </div>
    </div>

    {{--
        S-11e · 邀请横幅：只在 session 里确实有码时出现（**无码时不许出现空横幅**）。
        码**不明文回显** —— 这里只说明它记录来源，不展示内容。
        口径：邀请码不是准入闸门，实名与协议仍必须完成。
    --}}
    @if (session('dev_invite_code'))
        <div class="notice" data-invite-banner="1" style="max-width:640px">
            <strong>你正在通过邀请链接入驻。</strong>
            邀请码只用于<strong>记录来源</strong>，<strong>不是准入条件</strong>；
            你<strong>仍需完成实名与协议</strong>。
        </div>
    @endif

    <div class="card" style="max-width:640px">
        <div class="card-head">
            <h2>🚀 填写主体基础信息</h2>
            <span class="badge info">步骤 1 / 5</span>
        </div>

        <p class="muted" style="margin-top:0;line-height:1.75">
            提交后即完成入驻。<strong>实名审核（公司认证 + 操作人认证两层）由平台人工完成</strong>；
            <strong>两层实名都通过后才能创建技能</strong>，未通过实名也不能上架。
            实名资料在入驻后到
            <a href="{{ route('shop.dev.realname') }}">实名认证</a>
            页提交（公司主体名 + 统一社会信用代码 + 营业执照；操作人姓名 + 证件号）。
        </p>

        <form method="POST" action="{{ route('shop.dev.onboarding.store') }}">
            @csrf

            <div class="row">
                <div>
                    <label for="dev_type">主体类型 <span style="color:var(--danger)">*</span></label>
                    <select id="dev_type" name="dev_type" required>
                        @foreach ($devTypes as $type)
                            <option value="{{ $type }}" @selected(old('dev_type') === $type)>
                                {{ $type === 'company' ? '企业' : '个人' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="seller_name">开发者名称</label>
                    <input id="seller_name" type="text" name="seller_name" value="{{ old('seller_name') }}"
                           placeholder="个人填姓名，企业填公司名" maxlength="191">
                </div>
            </div>

            <div class="row">
                <div>
                    <label for="contact_name">联系人</label>
                    <input id="contact_name" type="text" name="contact_name" value="{{ old('contact_name') }}" maxlength="191">
                </div>
                <div>
                    <label for="contact_phone">联系电话</label>
                    <input id="contact_phone" type="text" name="contact_phone" value="{{ old('contact_phone') }}" maxlength="32">
                </div>
            </div>

            <div style="margin-top:20px">
                <button type="submit">提交入驻</button>
            </div>
        </form>
    </div>
@endsection
