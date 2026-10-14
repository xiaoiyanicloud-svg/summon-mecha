<x-admin::layouts>
    <x-slot:title>内容安全</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">内容安全</p>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">机器扫 + 人工判，两段都在这里</p>
        <p class="mt-1 leading-relaxed">
            每次技能提审都会跑一次内容扫描并把明细留在本表（扫了什么、命中什么、用的哪个驱动）。
            机器判定分三种：
            <b>通过</b>（无需人处置）、<b>需人工看</b>（扫出可疑，或<b>根本没扫成</b>）、<b>拦截</b>。
            后两种需要人来处置。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 「需人工看」里有很大一部分是<b>「未扫成」</b>（词表缺失 / 驱动不可用）。
            那不是"干净"，是"不知道" —— 请按未确认处理。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.content-security.index')" />
</x-admin::layouts>
