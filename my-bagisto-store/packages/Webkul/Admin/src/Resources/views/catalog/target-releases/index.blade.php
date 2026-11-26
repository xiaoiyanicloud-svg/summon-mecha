<x-admin::layouts>
    <x-slot:title>目标发布审核</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">目标发布审核</p>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">每个机型的目标产物独立审核</p>
        <p class="mt-1 leading-relaxed">
            这里判的是 <code>build_key + artifact_asset_id + target_profile_digest</code> 同时匹配的
            <b>某一机型具体产物</b>；撤销后交付解析会 fail-closed。
        </p>
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <label class="text-sm">
            状态
            <select name="status" class="ml-1 rounded border px-2 py-1">
                <option value="">全部</option>
                @foreach ($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected($status === $statusOption)>{{ $statusOption }}</option>
                @endforeach
            </select>
        </label>

        <label class="text-sm">
            机型
            <input name="model_code" value="{{ $model }}" class="ml-1 rounded border px-2 py-1" placeholder="unitree_g1">
        </label>

        <label class="text-sm">
            Seller ID
            <input name="seller_id" value="{{ $seller }}" class="ml-1 rounded border px-2 py-1" placeholder="留空 = 平台范围">
        </label>

        <button class="primary-button">筛选</button>
    </form>

    <div class="overflow-x-auto rounded border">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="px-3 py-2">ID</th>
                    <th class="px-3 py-2">版本</th>
                    <th class="px-3 py-2">机型</th>
                    <th class="px-3 py-2">build_key</th>
                    <th class="px-3 py-2">状态</th>
                    <th class="px-3 py-2">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($releases as $release)
                    <tr class="border-t">
                        <td class="px-3 py-2">{{ $release->id }}</td>
                        <td class="px-3 py-2">{{ $release->skill_version_id }}</td>
                        <td class="px-3 py-2">{{ $release->target_model_code }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $release->build_key }}</td>
                        <td class="px-3 py-2">{{ $release->review_status }}</td>
                        <td class="px-3 py-2">
                            <a class="text-blue-600" href="{{ route('admin.catalog.target-releases.show', $release->id) }}">详情</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-3 py-6 text-center text-gray-500">没有目标发布记录。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $releases->links() }}</div>
</x-admin::layouts>
