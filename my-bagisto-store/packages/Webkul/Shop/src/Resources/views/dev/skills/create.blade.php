@extends('shop::dev.layouts.app')

@section('title', '创建技能 · 开发者中心')

@section('content')
    <div class="page-head">
        <div>
            <h1>创建技能</h1>
            <p class="page-head-sub">登记机器人技能商品元数据；创建后进入版本管理与 .skill 包安检提审流程。</p>
        </div>
        <div class="page-head-actions">
            <a class="btn ghost" href="{{ route('shop.dev.skills.index') }}">← 返回技能列表</a>
        </div>
    </div>

    <div class="card" style="max-width:720px">
        <div class="card-head">
            <h2>🧩 技能商品基础信息</h2>
            <span class="badge">新建默认未上架</span>
        </div>

        <p class="muted" style="margin-top:0">
            创建后技能处于<strong>未上架</strong>状态。上传技能包并提交审核通过后，才可上架销售。
        </p>

        {{--
            建技能前置（S-12a）：未实名不能建技能。判定取自 DeveloperSkillService::canCreateSkill()
            （唯一实现），门户只呈现、不重算。弹窗是 S-12d，本卡只如实说明入口条件。
        --}}
        @unless ($createGate['ok'])
            <div class="notice">
                <strong>还不能创建技能：</strong>{{ $createGate['message'] }}
                @if (Route::has('shop.dev.realname'))
                    <a href="{{ route('shop.dev.realname') }}" style="font-weight:600;margin-left:6px">前往实名认证 →</a>
                @else
                    <span class="muted">（实名认证入口由平台开放后可用）</span>
                @endif
            </div>
        @endunless

        <form method="POST" action="{{ route('shop.dev.skills.store') }}">
            @csrf

            <div class="row">
                <div style="flex:2">
                    <label for="name">技能名称 <span style="color:var(--danger)">*</span></label>
                    <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="191"
                           placeholder="例如：Unitree G1 迎宾挥手动作包">
                </div>
                <div style="flex:1">
                    <label for="price">定价（元，买断） <span style="color:var(--danger)">*</span></label>
                    <input id="price" type="number" name="price" value="{{ old('price', 0) }}" min="0" step="0.01" required>
                </div>
            </div>

            <label for="short_description">一句话简介</label>
            <input id="short_description" type="text" name="short_description" value="{{ old('short_description') }}" maxlength="500"
                   placeholder="将在技能卡片与搜索结果列表中展示（500 字以内）">

            <label for="description">详细说明</label>
            <textarea id="description" name="description" rows="5" maxlength="5000"
                      placeholder="说明技能适用场景、动作/应用特性、硬件环境要求与注意事项">{{ old('description') }}</textarea>

            <div style="margin-top:20px;display:flex;gap:10px">
                <button type="submit">创建</button>
                <a class="btn ghost" href="{{ route('shop.dev.skills.index') }}">取消</a>
            </div>
        </form>
    </div>
@endsection
