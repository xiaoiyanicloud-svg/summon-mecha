<x-admin::layouts>
    <x-slot:title>登记备案</x-slot:title>

    <x-admin::form :action="route('admin.settings.compliance.store')">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">登记备案</p>

            <div class="flex items-center gap-x-2.5">
                <a href="{{ route('admin.settings.compliance.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回</a>
                <button type="submit" class="primary-button">保存</button>
            </div>
        </div>

        @include('admin::settings.compliance._form', ['filing' => null, 'types' => $types])
    </x-admin::form>
</x-admin::layouts>
