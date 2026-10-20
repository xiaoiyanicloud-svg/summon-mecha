<x-admin::layouts>
    <x-slot:title>运营指标</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">运营指标</p>
            <p class="text-xs text-gray-500">
                口径与数字同页输出；没有事实源的指标显示“不可用”，不以 0 冒充。
            </p>
        </div>
    </div>

    @unless ($data['enabled'])
        <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            指标能力未启用（ECOSYSTEM_METRICS_ENABLED=false）。下表只展示口径，不展示会被误读的数字。
        </div>
    @endunless

    <div class="mb-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <form method="GET" class="flex flex-wrap items-end gap-3 text-sm">
            <label>
                <span class="mb-1 block text-gray-600 dark:text-gray-300">开始日期</span>
                <input type="date" name="from" value="{{ $data['range']['from'] }}"
                    class="rounded border px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
            </label>
            <label>
                <span class="mb-1 block text-gray-600 dark:text-gray-300">结束日期</span>
                <input type="date" name="to" value="{{ $data['range']['to'] }}"
                    class="rounded border px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
            </label>
            <button class="primary-button" type="submit">查询</button>
            <span class="text-xs text-gray-500">
                区间 {{ $data['range']['from'] }} ~ {{ $data['range']['to'] }}（{{ $data['range']['timezone'] }}）
            </span>
        </form>
    </div>

    @php
        $definitions = $data['definitions'];
        $disabled = ! $data['enabled'];
        $fmt = fn ($value) => $value === null ? '—' : number_format((int) $value);
    @endphp

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">报名与席位</p>
            <p class="text-xs text-gray-500">按 attempt 计；重放不重复计；人数与队伍不混算。</p>
        </div>

        @if (! $data['registrations']['available'])
            <p class="px-4 py-6 text-sm text-gray-500">报名事实源不可用。</p>
        @elseif ($disabled)
            <p class="px-4 py-6 text-sm text-gray-500">指标未启用。</p>
        @else
            <div class="grid grid-cols-2 gap-3 p-4 md:grid-cols-5">
                @foreach (['submitted' => '报名提交数', 'confirmed_seats' => '确认席位', 'checked_in' => '签到数', 'canceled' => '取消数', 'reapplications' => '重报数'] as $key => $label)
                    <div class="rounded border px-3 py-2 dark:border-gray-800">
                        <p class="text-xs text-gray-500">{{ $label }}</p>
                        <p class="text-lg font-semibold text-gray-800 dark:text-white">{{ $fmt($data['registrations'][$key]) }}</p>
                        <p class="text-[11px] text-gray-400">{{ $definitions[$key]['source'] }}</p>
                    </div>
                @endforeach
            </div>

            @if (! empty($data['registrations']['by_event']))
                <table class="w-full border-t text-sm dark:border-gray-800">
                    <thead class="text-left text-gray-600 dark:text-gray-300">
                        <tr><th class="px-4 py-2">活动</th><th class="px-4 py-2">提交数</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($data['registrations']['by_event'] as $row)
                            <tr class="border-t dark:border-gray-800">
                                <td class="px-4 py-2">
                                    <a class="text-blue-600 hover:underline" href="{{ route('admin.catalog.ecosystem.events.show', $row['event_id']) }}">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="px-4 py-2">{{ $fmt($row['total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>

    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">内容访问</p>
            <p class="text-xs text-gray-500">日聚合；同一访客 1 小时去重；不存 IP / UA / 用户 ID。</p>
        </div>

        @if (! $data['content']['available'])
            <p class="px-4 py-6 text-sm text-gray-500">
                不可用：{{ $data['content']['reason'] === 'content_views_disabled' ? '访问记录开关未启用' : '事实源不可用' }}。
            </p>
        @elseif ($disabled)
            <p class="px-4 py-6 text-sm text-gray-500">指标未启用。</p>
        @else
            <div class="grid grid-cols-1 gap-3 p-4 md:grid-cols-3">
                <div class="rounded border px-3 py-2 dark:border-gray-800">
                    <p class="text-xs text-gray-500">总访问</p>
                    <p class="text-lg font-semibold text-gray-800 dark:text-white">{{ $fmt($data['content']['total']) }}</p>
                </div>
            </div>
            @if (! empty($data['content']['top']))
                <table class="w-full border-t text-sm dark:border-gray-800">
                    <thead class="text-left text-gray-600 dark:text-gray-300">
                        <tr><th class="px-4 py-2">对象</th><th class="px-4 py-2">访问</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($data['content']['top'] as $row)
                            <tr class="border-t dark:border-gray-800">
                                <td class="px-4 py-2">
                                    {{ $row['title'] }}
                                    <span class="ml-1 text-xs text-gray-400">{{ $row['target_type'] }}:{{ $row['target_key'] }}</span>
                                </td>
                                <td class="px-4 py-2">{{ $fmt($row['views']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-2">
        <div class="box-shadow rounded bg-white dark:bg-gray-900">
            <div class="border-b px-4 py-3 dark:border-gray-800">
                <p class="font-semibold text-gray-800 dark:text-white">作品与转化</p>
            </div>
            @if (! $data['competition']['available'])
                <p class="px-4 py-6 text-sm text-gray-500">事实源不可用。</p>
            @elseif ($disabled)
                <p class="px-4 py-6 text-sm text-gray-500">指标未启用。</p>
            @else
                <div class="grid grid-cols-3 gap-3 p-4">
                    <div><p class="text-xs text-gray-500">作品提交版本</p><p class="text-lg font-semibold">{{ $fmt($data['competition']['submission_versions']) }}</p></div>
                    <div><p class="text-xs text-gray-500">展示发布</p><p class="text-lg font-semibold">{{ $fmt($data['competition']['showcases_published']) }}</p></div>
                    <div><p class="text-xs text-gray-500">进入技能提审</p><p class="text-lg font-semibold">{{ $fmt($data['competition']['developer_handoff']) }}</p></div>
                </div>
            @endif
        </div>

        <div class="box-shadow rounded bg-white dark:bg-gray-900">
            <div class="border-b px-4 py-3 dark:border-gray-800">
                <p class="font-semibold text-gray-800 dark:text-white">发布</p>
            </div>
            @if ($disabled)
                <p class="px-4 py-6 text-sm text-gray-500">指标未启用。</p>
            @else
                <div class="grid grid-cols-2 gap-3 p-4">
                    <div><p class="text-xs text-gray-500">活动发布</p><p class="text-lg font-semibold">{{ $fmt($data['publishing']['events']) }}</p></div>
                    <div><p class="text-xs text-gray-500">资讯发布</p><p class="text-lg font-semibold">{{ $fmt($data['publishing']['content_posts']) }}</p></div>
                </div>
            @endif
        </div>
    </div>

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <div class="border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">口径</p>
        </div>
        <table class="w-full text-sm">
            <thead class="text-left text-gray-600 dark:text-gray-300">
                <tr><th class="px-4 py-2">指标</th><th class="px-4 py-2">事实源</th><th class="px-4 py-2">说明</th></tr>
            </thead>
            <tbody>
                @foreach ($definitions as $definition)
                    <tr class="border-t dark:border-gray-800">
                        <td class="px-4 py-2">{{ $definition['label'] }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $definition['source'] }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $definition['note'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin::layouts>
