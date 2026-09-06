<x-admin::layouts>
    <x-slot:title>赛事报名</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">赛事报名</p>

        <a href="{{ route('admin.catalog.events.index') }}">
            <div class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回赛事管理</div>
        </a>
    </div>

    <x-admin::datagrid :src="route('admin.catalog.events.registrations')" />
</x-admin::layouts>
