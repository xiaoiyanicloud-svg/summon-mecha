<x-admin::layouts>
    <x-slot:title>目标发布详情</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">目标发布 #{{ $release->id }}</p>
        <a class="text-blue-600" href="{{ route('admin.catalog.target-releases.index') }}">返回列表</a>
    </div>

    <div class="mb-4 rounded border p-4 text-sm">
        <p>版本：{{ $release->skill_version_id }}</p>
        <p>机型：{{ $release->target_model_code }}</p>
        <p>build_key：<span class="font-mono">{{ $release->build_key }}</span></p>
        <p>artifact_asset_id：{{ $release->artifact_asset_id }}</p>
        <p>profile digest：<span class="font-mono text-xs">{{ $release->target_profile_digest }}</span></p>
        <p>状态：<b>{{ $release->review_status }}</b></p>
    </div>

    <div class="grid gap-4 md:grid-cols-3">
        @if (bouncer()->hasPermission('catalog.target-releases.approve'))
            <form method="POST" action="{{ route('admin.catalog.target-releases.approve', $release->id) }}" class="rounded border p-4">
                @csrf
                <p class="mb-2 font-semibold">通过</p>
                <button class="primary-button">确认通过</button>
            </form>
        @endif

        @if (bouncer()->hasPermission('catalog.target-releases.reject'))
            <form method="POST" action="{{ route('admin.catalog.target-releases.reject', $release->id) }}" class="rounded border p-4">
                @csrf
                <p class="mb-2 font-semibold">驳回</p>
                <textarea name="reason" class="mb-2 w-full rounded border px-2 py-1" placeholder="驳回理由（必填）"></textarea>
                <button class="primary-button">确认驳回</button>
            </form>
        @endif

        @if (bouncer()->hasPermission('catalog.target-releases.revoke'))
            <form method="POST" action="{{ route('admin.catalog.target-releases.revoke', $release->id) }}" class="rounded border p-4">
                @csrf
                <p class="mb-2 font-semibold">撤销</p>
                <textarea name="reason" class="mb-2 w-full rounded border px-2 py-1" placeholder="撤销理由（必填）"></textarea>
                <button class="primary-button">确认撤销</button>
            </form>
        @endif
    </div>

    <div class="mt-6">
        <p class="mb-2 font-semibold">审计事件（只追加）</p>
        <div class="overflow-x-auto rounded border">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th class="px-3 py-2">时间</th>
                        <th class="px-3 py-2">事件</th>
                        <th class="px-3 py-2">操作者</th>
                        <th class="px-3 py-2">理由</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $event)
                        <tr class="border-t">
                            <td class="px-3 py-2">{{ $event->created_at }}</td>
                            <td class="px-3 py-2">{{ $event->event_type }}</td>
                            <td class="px-3 py-2">{{ $event->actor_id }}</td>
                            <td class="px-3 py-2">{{ $event->reason }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-3 py-6 text-center text-gray-500">还没有审计事件。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
