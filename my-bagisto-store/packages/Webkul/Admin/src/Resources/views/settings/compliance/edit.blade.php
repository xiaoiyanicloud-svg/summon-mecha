<x-admin::layouts>
    <x-slot:title>编辑备案 · {{ $filing->name }}</x-slot:title>

    <x-admin::form :action="route('admin.settings.compliance.update', $filing->id)" method="PUT">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
                编辑备案 · {{ $filing->name }}
                <span class="text-sm font-normal text-gray-500">{{ $filing->typeLabel() }}</span>
            </p>

            <div class="flex items-center gap-x-2.5">
                <a href="{{ route('admin.settings.compliance.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回</a>
                <button type="submit" class="primary-button">保存</button>
            </div>
        </div>

        @include('admin::settings.compliance._form', ['filing' => $filing, 'types' => $types])
    </x-admin::form>
</x-admin::layouts>
