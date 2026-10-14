{{-- 后台通用提示条。用法：<x-admin::flash /> --}}
@if (session('success'))
    <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
        {{ session('success') }}
    </div>
@endif
@if (session('error'))
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
        {{ session('error') }}
    </div>
@endif
@if (isset($errors) && $errors->any())
    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
        <ul class="list-disc pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
