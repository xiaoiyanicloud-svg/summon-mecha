<x-admin::layouts>
    <x-slot:title>平台告警</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">平台告警</p>
            <p class="mt-1 text-xs text-gray-500">
                系统定时任务扫出来的待办：备案到期、工单超时、事故未结案。
                @if ($unread > 0)
                    　<span class="rounded bg-red-100 px-2 py-0.5 text-red-800">未读 {{ $unread }}</span>
                @endif
            </p>
        </div>

        @if ($unread > 0)
            <form method="POST" action="{{ route('admin.alerts.read-all') }}">
                @csrf
                <button type="submit" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">
                    全部标为已读
                </button>
            </form>
        @endif
    </div>

    <x-admin::flash />

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">这些告警从哪来</p>
        <p class="mt-1 leading-relaxed">
            由三个定时任务产出：<code>ops:alert-compliance-due</code>（备案到期/年检）、
            <code>ops:escalate-overdue-tickets</code>（工单超 SLA）、
            <code>ops:alert-open-incidents</code>（事故未结案）。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 同一件事有<b>去重</b>：未读的不会重复提醒，读过之后 {{ \Webkul\Shop\Services\Ops\AdminAlertService::QUIET_DAYS }} 天内也不再重复。
            另外，<b>调度器没起的话这些任务不会跑</b>（见部署清单里的 <code>schedule:work</code>）。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.alerts.index')" />
</x-admin::layouts>
