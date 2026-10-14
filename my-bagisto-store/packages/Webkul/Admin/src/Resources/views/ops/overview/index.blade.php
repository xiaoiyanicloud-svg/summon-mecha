<x-admin::layouts>
    <x-slot:title>运营总览</x-slot:title>

    @php
        // 分组：标题 => 指标集合。每个指标是 {value, basis, ready}
        $groups = [
            '交易（下单口径）'   => $trade,
            '技能供给'           => $supply,
            '审核与内容安全'     => $review,
            '设备'               => $devices,
            '授权与安装'         => $entitlements,
            '客服与安全'         => $service,
            '开发者'             => $developers,
            '合规'               => $compliance,
        ];
    @endphp

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">运营总览</p>
            <p class="mt-1 text-xs text-gray-500">
                技能平台口径的只读统计。每个数字下面都标了<b>口径</b> ——
                不知道口径的数字比没有数字更危险。
            </p>
        </div>
        <a href="{{ route('admin.alerts.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">看平台告警 →</span>
        </a>
    </div>

    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">先看这条：「没有数据」不等于「数据是零」</p>
        <p class="mt-1 leading-relaxed">
            本页对<b>数据源未接入</b>的维度显示「未接入」而不是 0。
            「下载量 0」和「下载量根本没在统计」对决策的含义完全相反，长得却一模一样 ——
            把它们混起来，看板就会开始骗人。未接入的维度列在本页最下方。
        </p>
    </div>

    @foreach ($groups as $title => $metrics)
        <div class="mb-4">
            <p class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $title }}</p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($metrics as $key => $m)
                    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                        <p class="text-xs text-gray-500">{{ $m['basis'] }}</p>

                        <p class="mt-2 text-2xl font-bold {{ $m['ready'] ? 'text-gray-800 dark:text-white' : 'text-gray-400' }}">
                            @if ($m['ready'])
                                {{ $m['value'] === null ? '—' : number_format((float) $m['value'], is_float($m['value']) ? 2 : 0) }}
                            @else
                                未接入
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- 显式列出未接入的维度 --}}
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-1 font-semibold text-gray-800 dark:text-white">本页未统计的维度（显式列出，而不是省略）</p>
        <p class="mb-3 text-xs text-gray-500">
            列出来而不是藏起来：藏起来的话，「看板上没有这一项」与「这一项是 0」分不清。
        </p>

        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-3 py-2">维度</th>
                    <th class="px-3 py-2">为什么没有</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($notConnected as $row)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-3 py-2 font-medium text-gray-800 dark:text-white">{{ $row['name'] }}</td>
                        <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-300">{{ $row['reason'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-admin::layouts>
