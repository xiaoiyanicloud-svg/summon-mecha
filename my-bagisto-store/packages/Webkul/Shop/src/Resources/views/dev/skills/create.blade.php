@extends('shop::dev.layouts.app')

@section('title', '创建技能 · 开发者中心')

@section('content')
    <h1>创建技能</h1>

    <div class="card" style="max-width:640px">
        <p class="muted">
            创建后技能处于<strong>未上架</strong>状态。上传技能包并提交审核通过后，才可上架销售。
        </p>

        <form method="POST" action="{{ route('shop.dev.skills.store') }}">
            @csrf

            <label for="name">技能名称</label>
            <input id="name" type="text" name="name" value="{{ old('name') }}" required maxlength="191">

            <label for="price">定价（元，买断）</label>
            <input id="price" type="number" name="price" value="{{ old('price', 0) }}" min="0" step="0.01" required>

            <label for="short_description">一句话简介</label>
            <input id="short_description" type="text" name="short_description" value="{{ old('short_description') }}" maxlength="500">

            <label for="description">详细说明</label>
            <textarea id="description" name="description" maxlength="5000">{{ old('description') }}</textarea>

            <div style="margin-top:18px">
                <button type="submit">创建</button>
                <a class="btn ghost" href="{{ route('shop.dev.skills.index') }}">取消</a>
            </div>
        </form>
    </div>
@endsection
