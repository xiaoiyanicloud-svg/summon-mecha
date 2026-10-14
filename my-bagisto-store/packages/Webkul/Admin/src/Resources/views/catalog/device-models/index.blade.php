<x-admin::layouts>
    <x-slot:title>机型库</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">机型库</p>

        <a href="{{ route('admin.catalog.device-models.create') }}">
            <div class="primary-button">新建机型</div>
        </a>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">这张表决定「这个技能能不能装到这台机器人上」</p>
        <p class="mt-1 leading-relaxed">
            兼容性判定 = <b>技能声明的通道 ∩ 机型声明的通道</b>。所以在这里勾掉一个通道，
            等价于把该机型上的一类安装方式下架。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 机型<b>不要删</b> —— 设备按 <code>model_code</code> 引用、设备绑定按主键引用。
            已有引用的机型删除会被拒绝（那是保护，不是 bug）；下架请改成「仅展示」。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.device-models.index')" />
</x-admin::layouts>
