@extends('shop::dev.layouts.app')

@section('title', '签署协议 · 开发者中心')

@section('content')
    <h1>签署协议</h1>

    <form method="POST" action="{{ route('shop.dev.agreements.sign') }}">
        @csrf

        @foreach ($docs as $docType => $doc)
            <div class="card">
                <h2>{{ $doc['title'] ?? $docType }}</h2>
                <p class="muted">版本 {{ $doc['version'] ?? '1.0' }}</p>

                <div style="background:#f9fafb;border:1px solid #eef0f3;border-radius:8px;padding:12px;margin:10px 0;font-size:13px;line-height:1.7;white-space:pre-wrap">{{ $doc['text'] ?? '' }}</div>

                @if (in_array($docType, $signedDocs, true))
                    <span class="badge ok">已签署（再次勾选将更新签署记录）</span>
                @endif

                <label style="display:flex;align-items:center;gap:8px;margin-top:12px">
                    <input type="checkbox" name="accept_{{ $docType }}" value="1" style="width:auto">
                    <span>我已阅读并同意《{{ $doc['title'] ?? $docType }}》</span>
                </label>
            </div>
        @endforeach

        <button type="submit">全部签署</button>
    </form>
@endsection
