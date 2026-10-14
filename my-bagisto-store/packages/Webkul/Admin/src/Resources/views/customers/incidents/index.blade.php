<x-admin::layouts>
    <x-slot:title>事故受理</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">事故受理</p>
    </div>

    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
        <p class="font-semibold">技能是在真实机器人上物理执行的 —— 这份列表是平台的安全风险敞口</p>
        <p class="mt-1 leading-relaxed">
            <b>未结案</b>的排在前面（已结案的是历史，未结案的是当下）。用户报案时，
            <code>IncidentService::traceFor()</code> 已经把证据链<b>冻结</b>在记录里 ——
            因为事后版本会归档、包会被替换、设备会解绑，回头再查看到的是"现在的样子"而不是"出事时的样子"。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-red-800">
            ⚠ 严重/重大（带 ⚠ 标记）是应急响应触发线。本平台<b>尚未接入</b>对外事故上报通道
            （依赖合规备案），所以这个标记只是提醒，不代你上报。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.customers.incidents.index')" />
</x-admin::layouts>
