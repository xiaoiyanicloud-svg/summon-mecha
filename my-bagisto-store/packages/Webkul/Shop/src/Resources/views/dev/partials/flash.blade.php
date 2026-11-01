{{-- 后台统一顶部反馈横条 --}}
@if (session('success'))
    <div class="flash success">
        <span>✅ {{ session('success') }}</span>
    </div>
@endif

@if (session('error'))
    <div class="flash error">
        <span>⚠️ {{ session('error') }}</span>
    </div>
@endif

@if ($errors->any())
    <div class="flash error">
        <div>
            @foreach ($errors->all() as $error)
                <div>⚠️ {{ $error }}</div>
            @endforeach
        </div>
    </div>
@endif
