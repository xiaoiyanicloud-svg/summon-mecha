@extends('shop::dev.layouts.app')

@section('title', '编辑技能 · 开发者中心')

@section('content')
    <h1>编辑技能信息</h1>

    <div class="card" style="max-width:640px">
        <p class="muted">
            这里只改<strong>名称、简介、详情、定价</strong>。
            上下架状态、归属、SKU 由平台管理，不在此处修改。
        </p>

        <form method="POST" action="{{ route('shop.dev.skills.update', $product->id) }}">
            @csrf
            {{-- 表单只能 GET/POST，用 method 伪造 PUT，对上路由里那条 PUT --}}
            @method('PUT')

            <label for="name">技能名称</label>
            <input id="name" type="text" name="name" value="{{ old('name', $product->name) }}" required maxlength="191">
            @error('name') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror

            <label for="price">定价（元，买断）</label>
            <input id="price" type="number" name="price" value="{{ old('price', $product->price) }}" min="0" step="0.01" required>
            @error('price') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror

            <label for="short_description">一句话简介</label>
            <input id="short_description" type="text" name="short_description" value="{{ old('short_description', $product->short_description) }}" maxlength="500">
            @error('short_description') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror

            <label for="description">详细说明</label>
            <textarea id="description" name="description" maxlength="5000">{{ old('description', $product->description) }}</textarea>
            @error('description') <div class="muted" style="color:#b91c1c">{{ $message }}</div> @enderror

            <div style="margin-top:18px">
                <button type="submit">保存</button>
                <a class="btn ghost" href="{{ route('shop.dev.skills.show', $product->id) }}">取消</a>
            </div>
        </form>
    </div>
@endsection
