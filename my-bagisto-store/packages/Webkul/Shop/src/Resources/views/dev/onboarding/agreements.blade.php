@extends('shop::dev.layouts.app')

@section('title', '签署协议 · 开发者中心')

@section('content')
    <div class="page-head">
        <div>
            <h1>签署协议</h1>
            <p class="page-head-sub">签署全部必签协议后，技能版本方可提交机审与申请上架。</p>
        </div>
        <div class="page-head-actions">
            <button type="button" class="btn ghost small" onclick="checkAllAgreements()">✓ 一键勾选全部协议</button>
            <a class="btn ghost small" href="{{ route('shop.dev.dashboard') }}">← 返回总览</a>
        </div>
    </div>

    <form method="POST" action="{{ route('shop.dev.agreements.sign') }}">
        @csrf

        @foreach ($docs as $docType => $doc)
            <div class="card">
                <div class="card-head">
                    <h2>📜 {{ $doc['title'] ?? $docType }}</h2>
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="code-chip">版本 {{ $doc['version'] ?? '1.0' }}</span>
                        @if (in_array($docType, $signedDocs, true))
                            <span class="badge ok">已签署（再次勾选将更新签署记录）</span>
                        @else
                            <span class="badge warn">待签署</span>
                        @endif
                    </div>
                </div>

                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin:10px 0;font-size:13px;line-height:1.75;white-space:pre-wrap;max-height:220px;overflow-y:auto">{{ $doc['text'] ?? '' }}</div>

                <label style="display:inline-flex;align-items:center;gap:8px;margin-top:10px;cursor:pointer">
                    <input type="checkbox" class="agreement-checkbox" name="accept_{{ $docType }}" value="1"
                           @checked(in_array($docType, $signedDocs, true)) style="width:auto">
                    <span>我已阅读并同意《{{ $doc['title'] ?? $docType }}》</span>
                </label>
            </div>
        @endforeach

        <div style="display:flex;gap:10px">
            <button type="submit">全部签署</button>
            <a class="btn ghost" href="{{ route('shop.dev.dashboard') }}">返回总览</a>
        </div>
    </form>

    <script>
        function checkAllAgreements() {
            document.querySelectorAll('.agreement-checkbox').forEach(function (cb) {
                cb.checked = true;
            });
        }
    </script>
@endsection
