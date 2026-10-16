@extends('shop::dev.layouts.app')

@section('title', '入驻 · 开发者中心')

@section('content')
    <h1>开发者入驻</h1>

    <div class="card" style="max-width:560px">
        <p class="muted">
            提交后即完成入驻。<strong>实名审核（公司认证 + 操作人认证两层）由平台人工完成</strong>；
            <strong>两层实名都通过后才能创建技能</strong>，未通过实名也不能上架。
            实名资料在入驻后到
            <a href="{{ route('shop.dev.realname') }}">实名认证</a>
            页提交（公司主体名 + 统一社会信用代码 + 营业执照；操作人姓名 + 证件号）。
        </p>

        <form method="POST" action="{{ route('shop.dev.onboarding.store') }}">
            @csrf

            <label for="dev_type">主体类型</label>
            <select id="dev_type" name="dev_type" required>
                @foreach ($devTypes as $type)
                    <option value="{{ $type }}" @selected(old('dev_type') === $type)>
                        {{ $type === 'company' ? '企业' : '个人' }}
                    </option>
                @endforeach
            </select>

            <label for="seller_name">开发者名称</label>
            <input id="seller_name" type="text" name="seller_name" value="{{ old('seller_name') }}"
                   placeholder="个人填姓名，企业填公司名" maxlength="191">

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

            <div style="margin-top:18px">
                <button type="submit">提交入驻</button>
            </div>
        </form>
    </div>
@endsection
