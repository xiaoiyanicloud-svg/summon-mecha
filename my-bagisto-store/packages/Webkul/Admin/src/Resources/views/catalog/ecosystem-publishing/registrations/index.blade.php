<x-admin::layouts>
    <x-slot:title>报名列表 · {{ $event->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">报名列表 · {{ $event->name }}</p>
            <p class="text-xs text-gray-500">活动 #{{ $event->id }} · 只显示本活动；联系人默认遮罩。完整联系人需本活动 pii 能力。</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.catalog.ecosystem.registrations.scope', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">报名设置</a>
            <a href="{{ route('admin.catalog.ecosystem.registrations.staff', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">人员范围</a>
            @if (in_array('checkin', $capabilities, true))
                <a href="{{ route('admin.catalog.ecosystem.registrations.checkin', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">签到</a>
            @endif
        </div>
    </div>

    <x-admin::flash />

    <form method="GET" action="{{ route('admin.catalog.ecosystem.registrations.index', $event->id) }}" class="mb-4 flex items-end gap-2">
        <label class="text-xs text-gray-600">
            状态
            <select name="status" class="mt-1 rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                <option value="">全部</option>
                @foreach (['submitted' => '待审核', 'confirmed' => '已确认', 'checked_in' => '已签到', 'rejected' => '已拒绝', 'canceled' => '已取消'] as $value => $label)
                    <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="submit" class="primary-button !py-2">筛选</button>
    </form>

    @if (in_array('export', $capabilities, true))
        <div class="mb-4 flex flex-wrap items-end gap-3 rounded bg-white p-3 text-xs dark:bg-gray-900">
            <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.export', $event->id) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="status" value="{{ $status }}">
                @if (in_array('pii', $capabilities, true))
                    <label class="flex items-center gap-2 text-gray-700 dark:text-gray-200">
                        <input type="checkbox" name="include_pii" value="1">
                        导出完整联系人（需 pii 能力，已审计）
                    </label>
                @endif
                <button type="submit" class="transparent-button">生成 CSV（10 分钟有效）</button>
            </form>

            @if (session('export_download_url'))
                <a href="{{ session('export_download_url') }}" class="text-blue-600 hover:underline">下载刚生成的导出文件</a>
            @endif
        </div>
    @endif

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">报名编号</th>
                    <th class="px-4 py-3">轮次</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">联系人（遮罩）</th>
                    <th class="px-4 py-3">提交时间</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 font-mono text-xs">{{ $row['public_no'] ?: '—' }}</td>
                        <td class="px-4 py-3 text-xs">#{{ $row['attempt']->attempt_no }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs
                                {{ $row['attempt']->status === 'confirmed' ? 'bg-green-100 text-green-800' : '' }}
                                {{ $row['attempt']->status === 'submitted' ? 'bg-amber-100 text-amber-800' : '' }}
                                {{ $row['attempt']->status === 'checked_in' ? 'bg-blue-100 text-blue-800' : '' }}
                                {{ in_array($row['attempt']->status, ['rejected', 'canceled'], true) ? 'bg-gray-100 text-gray-700' : '' }}">
                                {{ ['submitted' => '待审核', 'confirmed' => '已确认', 'checked_in' => '已签到', 'rejected' => '已拒绝', 'canceled' => '已取消'][$row['attempt']->status] ?? $row['attempt']->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $row['name'] }} · {{ $row['phone'] }} · {{ $row['city'] }}</td>
                        <td class="px-4 py-3 text-xs">{{ $row['attempt']->submitted_at ? $row['attempt']->submitted_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '—' }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.catalog.ecosystem.registrations.show', [$event->id, $row['attempt']->id]) }}" class="text-blue-600 hover:underline">详情</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">本活动暂无报名。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $attempts->links() }}</div>
</x-admin::layouts>
