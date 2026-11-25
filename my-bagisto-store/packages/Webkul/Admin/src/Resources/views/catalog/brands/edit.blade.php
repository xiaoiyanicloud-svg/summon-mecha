<x-admin::layouts>
    <x-slot:title>编辑品牌 · {{ $brand->name }}</x-slot:title>

    <x-admin::form :action="route('admin.catalog.brands.update', $brand->id)" method="PUT" enctype="multipart/form-data">
        <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
                编辑品牌 · {{ $brand->name }}
                <code class="ml-2 rounded bg-gray-100 px-2 py-0.5 text-sm font-normal text-gray-600">{{ $brand->code }}</code>
            </p>

            <div class="flex items-center gap-x-2.5">
                <a href="{{ route('admin.catalog.brands.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回</a>
                <button type="submit" class="primary-button">保存修改</button>
            </div>
        </div>

        @include('admin::catalog.brands._form', ['brand' => $brand, 'deviceBrands' => $deviceBrands, 'refs' => $refs])
    </x-admin::form>
</x-admin::layouts>
