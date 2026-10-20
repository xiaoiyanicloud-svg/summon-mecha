<x-admin::layouts>
    <x-slot:title>签到 · {{ $event->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">签到 · {{ $event->name }}</p>
            <p class="text-xs text-gray-500">只接受当前有效轮次的凭证；旧轮次/已取消/已过期凭证会被拒绝。重复扫描返回原签到结果。</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.catalog.ecosystem.registrations.index', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">报名列表</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.checkin.store', $event->id) }}" class="flex flex-col gap-3">
            @csrf
            <label class="text-xs text-gray-600">
                凭证 token 或二维码内容
                <input name="token" required autofocus maxlength="200" placeholder="粘贴 token 或 SUMMONMECHA-CHECKIN:..." class="mt-1 w-full rounded-md border px-3 py-2 font-mono text-sm dark:border-gray-800 dark:bg-gray-900">
                @error('token') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>
            <p class="text-xs text-gray-500">
                令牌不写入日志/审计；服务端只保存 SHA-256。签到成功后仍占名额。
            </p>
            <div>
                <button type="submit" class="primary-button">核验并签到</button>
            </div>
        </form>
    </div>
</x-admin::layouts>
