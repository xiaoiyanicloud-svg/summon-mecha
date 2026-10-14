<x-admin::layouts>
    <x-slot:title>技能类目</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">技能类目</p>

        <a href="{{ route('admin.catalog.skill-categories.create') }}">
            <div class="primary-button">新建类目</div>
        </a>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">这是技能市场的品类，不是商品分类</p>
        <p class="mt-1 leading-relaxed">
            这里维护的是前台「技能市场」的筛选品类（展演 / 接待 / 互动 / 定制 / 生产力），
            通过公开接口 <code>GET /api/skill-catalog/categories</code> 给前端。
            商品能不能被搜到，看的是它挂在 Bagisto 的哪个<b>商品分类</b>下 —— 那是另一套。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.skill-categories.index')" />
</x-admin::layouts>
