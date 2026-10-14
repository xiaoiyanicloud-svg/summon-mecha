<x-admin::layouts>
    <x-slot:title>客服工单</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">客服工单</p>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">用户开的单在这里受理</p>
        <p class="mt-1 leading-relaxed">
            用户在 App 里提的工单会带 <b>24 小时 SLA</b>。列表里「SLA 期限」一列会标红已超时且
            <b>尚未结单</b>的单 —— 已结单的不再报超时（超时只对"还欠着用户"的单有意义）。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            本轮只做<b>平台受理</b>：技能作者（开发者）侧的回复链还没做，
            所以没有"转派给开发者"的按钮 —— 宁可没有，也不要一个点了没反应的假功能。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.customers.tickets.index')" />
</x-admin::layouts>
