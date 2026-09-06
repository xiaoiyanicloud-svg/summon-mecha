<x-admin::layouts>
    <x-slot:title>赛事管理</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">赛事管理</p>

        <div class="flex items-center gap-x-2.5">
            <a href="{{ route('admin.catalog.events.registrations') }}">
                <div class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">报名列表</div>
            </a>
            <a href="{{ route('admin.catalog.events.create') }}">
                <div class="primary-button">新建赛事</div>
            </a>
        </div>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.events.index')" />
</x-admin::layouts>
