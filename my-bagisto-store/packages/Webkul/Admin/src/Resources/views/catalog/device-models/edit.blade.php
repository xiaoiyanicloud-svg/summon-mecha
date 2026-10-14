<x-admin::layouts>
    <x-slot:title>编辑机型 · {{ $model->model_name }}</x-slot:title>

    <x-admin::form :action="route('admin.catalog.device-models.update', $model->id)" method="PUT">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
                编辑机型 · {{ $model->model_name }}
                <span class="text-sm font-normal text-gray-500">{{ $model->model_code }}</span>
            </p>

            <div class="flex items-center gap-x-2.5">
                <a href="{{ route('admin.catalog.device-models.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回</a>
                <button type="submit" class="primary-button">保存</button>
            </div>
        </div>

        @include('admin::catalog.device-models._form', ['model' => $model, 'channels' => $channels])
    </x-admin::form>
</x-admin::layouts>
