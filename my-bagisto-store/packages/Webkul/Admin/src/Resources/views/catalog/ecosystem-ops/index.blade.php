<x-admin::layouts>
    <x-slot:title>运营工作台</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">运营工作台</p>
            <p class="text-xs text-gray-500">
                每项给出可定位事实与处理入口；报名行受活动人员范围限制，无 PII 能力时只显示遮罩。
            </p>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @unless ($data['enabled'])
        <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            运营工作台未启用（ECOSYSTEM_OPS_WORKBENCH_ENABLED=false）。以下仅展示事实，不执行处置。
        </div>
    @endunless

    @php
        $responsible = $data['responsible'] ?? [];
    @endphp

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">待审 revision（{{ $data['reviews']['count'] }}）</p>
            <span class="text-xs text-gray-500">负责人：{{ $responsible['review'] ?? '未配置' }}</span>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-600 dark:text-gray-300">
                <tr><th class="px-4 py-2">类型</th><th class="px-4 py-2">标题</th><th class="px-4 py-2">提交时间</th><th class="px-4 py-2">等待</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse (array_merge($data['reviews']['events'], $data['reviews']['contents']) as $row)
                    <tr class="border-t dark:border-gray-800">
                        <td class="px-4 py-2 text-xs">{{ $row['type'] }}</td>
                        <td class="px-4 py-2">{{ $row['title'] }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $row['submitted_at'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['age_hours'] !== null ? $row['age_hours'].' 小时' : '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <a class="text-blue-600 hover:underline"
                                href="{{ $row['type'] === 'event'
                                    ? route('admin.catalog.ecosystem.events.show', $row['parent_id'])
                                    : route('admin.catalog.ecosystem.content.show', $row['parent_id']) }}">处理</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">没有待审 revision。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">待审报名（{{ $data['registrations']['count'] }}）</p>
            <span class="text-xs text-gray-500">
                负责人：{{ $responsible['registration'] ?? '未配置' }}
                · {{ ($data['registrations']['pii_visible'] ?? false) ? '含 PII（已授权）' : '仅遮罩' }}
            </span>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-600 dark:text-gray-300">
                <tr><th class="px-4 py-2">活动</th><th class="px-4 py-2">姓名</th><th class="px-4 py-2">手机</th><th class="px-4 py-2">提交时间</th><th class="px-4 py-2">等待</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse ($data['registrations']['items'] as $row)
                    <tr class="border-t dark:border-gray-800">
                        <td class="px-4 py-2">{{ $row['event_name'] }}</td>
                        <td class="px-4 py-2">{{ $row['name'] ?: '—' }}</td>
                        <td class="px-4 py-2">{{ $row['phone'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $row['submitted_at'] ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['age_hours'] !== null ? $row['age_hours'].' 小时' : '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            <a class="text-blue-600 hover:underline"
                                href="{{ route('admin.catalog.ecosystem.registrations.index', $row['event_id']) }}">审核</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">没有待审报名（或你没有这些活动的人员范围）。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">即将截止（{{ $data['closing_soon']['count'] }}）</p>
            <span class="text-xs text-gray-500">未来 {{ $data['closing_soon']['window_hours'] }} 小时 · 负责人：{{ $responsible['registration'] ?? '未配置' }}</span>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-600 dark:text-gray-300">
                <tr><th class="px-4 py-2">活动</th><th class="px-4 py-2">名额</th><th class="px-4 py-2">截止</th><th class="px-4 py-2">剩余</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse ($data['closing_soon']['items'] as $row)
                    <tr class="border-t dark:border-gray-800">
                        <td class="px-4 py-2">{{ $row['event_name'] }}</td>
                        <td class="px-4 py-2">{{ $row['capacity'] === null ? '不限' : $row['confirmed'].' / '.$row['capacity'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['closes_at'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['hours_left'] }} 小时</td>
                        <td class="px-4 py-2 text-right">
                            @if (bouncer()->hasPermission('ecosystem.registrations.settings'))
                                <a class="text-blue-600 hover:underline"
                                    href="{{ route('admin.catalog.ecosystem.registrations.scope', $row['event_id']) }}">报名设置</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">没有即将截止的报名。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">通知失败（{{ $data['notifications']['count'] }}）</p>
            <span class="text-xs text-gray-500">只列 failed 且未租用 · 负责人：{{ $responsible['notification'] ?? '未配置' }}</span>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-600 dark:text-gray-300">
                <tr><th class="px-4 py-2">ID</th><th class="px-4 py-2">事件</th><th class="px-4 py-2">聚合</th><th class="px-4 py-2">尝试</th><th class="px-4 py-2">错误</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody>
                @forelse ($data['notifications']['items'] as $row)
                    <tr class="border-t dark:border-gray-800">
                        <td class="px-4 py-2 text-xs">{{ $row['id'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['event_type'] }}</td>
                        <td class="px-4 py-2 text-xs">{{ $row['aggregate_type'] }}:{{ $row['aggregate_id'] }}</td>
                        <td class="px-4 py-2">{{ $row['attempts'] }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $row['last_error'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($canRetry)
                                <form method="POST" action="{{ route('admin.catalog.ecosystem.ops.outbox.retry', $row['id']) }}">
                                    @csrf
                                    <button type="submit" class="text-blue-600 hover:underline">重排</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">没有失败通知。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div class="box-shadow rounded bg-white dark:bg-gray-900">
            <div class="border-b px-4 py-3 dark:border-gray-800">
                <p class="font-semibold text-gray-800 dark:text-white">来源复核（{{ $data['sources']['count'] }}）</p>
                <p class="text-xs text-gray-500">超过 {{ $data['sources']['window_days'] }} 天未核验</p>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    @forelse (array_merge($data['sources']['events'], $data['sources']['contents']) as $row)
                        <tr class="border-t dark:border-gray-800">
                            <td class="px-4 py-2">
                                <a class="text-blue-600 hover:underline"
                                    href="{{ $row['type'] === 'event'
                                        ? route('admin.catalog.ecosystem.events.show', $row['parent_id'])
                                        : route('admin.catalog.ecosystem.content.show', $row['parent_id']) }}">{{ $row['title'] }}</a>
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $row['source_verified_at'] ?? '未核验' }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">没有待复核来源。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="box-shadow rounded bg-white dark:bg-gray-900">
            <div class="border-b px-4 py-3 dark:border-gray-800">
                <p class="font-semibold text-gray-800 dark:text-white">更正 / 撤稿（{{ $data['dispositions']['count'] }}）</p>
                <p class="text-xs text-gray-500">近 {{ $data['dispositions']['window_days'] }} 天</p>
            </div>
            <table class="w-full text-sm">
                <tbody>
                    @forelse (array_merge($data['dispositions']['events'], $data['dispositions']['contents']) as $row)
                        <tr class="border-t dark:border-gray-800">
                            <td class="px-4 py-2">
                                <a class="text-blue-600 hover:underline"
                                    href="{{ $row['type'] === 'event'
                                        ? route('admin.catalog.ecosystem.events.show', $row['id'])
                                        : route('admin.catalog.ecosystem.content.show', $row['id']) }}">{{ $row['title'] ?? $row['slug'] }}</a>
                            </td>
                            <td class="px-4 py-2 text-xs">{{ $row['disposition'] }}</td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $row['updated_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-center text-gray-500">没有近期更正/撤稿。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
