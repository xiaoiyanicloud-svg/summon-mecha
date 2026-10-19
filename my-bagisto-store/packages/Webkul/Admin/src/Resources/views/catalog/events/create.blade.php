<x-admin::layouts>
    <x-slot:title>新建赛事</x-slot:title>

    <x-admin::form :action="route('admin.catalog.events.store')">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">新建赛事</p>

            <div class="flex items-center gap-x-2.5">
                <a href="{{ route('admin.catalog.events.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回</a>
                <button type="submit" class="primary-button">保存</button>
            </div>
        </div>

        @include('admin::catalog.events._form', ['event' => null])
    </x-admin::form>
</x-admin::layouts>
