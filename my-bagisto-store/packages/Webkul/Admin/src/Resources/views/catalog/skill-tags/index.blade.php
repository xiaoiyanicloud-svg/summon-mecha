<x-admin::layouts>
    <x-slot:title>技能标签</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">技能标签</p>

        <a href="{{ route('admin.catalog.skill-tags.create') }}">
            <div class="primary-button">新建标签</div>
        </a>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">标签是技能市场的筛选维度</p>
        <p class="mt-1 leading-relaxed">
            通过 <code>GET /api/skill-catalog/tags?type=</code> 公开给前端。
            已被技能使用的标签<b>不能删除</b> —— 删了会在前台留下空白筛选项。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.skill-tags.index')" />
</x-admin::layouts>
