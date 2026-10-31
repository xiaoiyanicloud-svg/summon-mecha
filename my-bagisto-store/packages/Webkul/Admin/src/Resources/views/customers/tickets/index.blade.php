<x-admin::layouts>
    <x-slot:title>客服中心</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">客服中心</p>
            <p class="pb-2 text-xs text-gray-500">在线会话与留言工单共用同一份会话记录；在线会话先进队列，留言工单走 24 小时 SLA。</p>
        </div>
        <a href="{{ route('admin.customers.support.workbench') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:opacity-90">
            进入客服工作台 <span class="text-blue-200">→</span>
        </a>
    </div>

    <p class="mb-3 text-xs text-gray-500">
        接待前先在工作台左上角点「上线接待」，页面保持开启即可（心跳每 20 秒一次，60 秒无心跳自动离线）。
    </p>

    <x-admin::datagrid :src="route('admin.customers.tickets.index')" />
</x-admin::layouts>