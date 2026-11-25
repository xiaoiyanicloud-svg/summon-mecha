@php
    $brand = $brand ?? null;
    $aliasesText = old('aliases', implode(', ', (array) ($brand?->aliases ?? [])));
    $deviceBrand = old('device_brand', $brand?->device_brand ?? '');
@endphp

<div class="mt-3.5 grid gap-4 xl:grid-cols-2">
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">基础信息</p>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">品牌编码（唯一）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="code"
                rules="required"
                :value="old('code', $brand?->code)"
                placeholder="例如 yushu"
            />
            <x-admin::form.control-group.error control-name="code" />
            <p class="mt-1 text-xs text-gray-500">仅限字母、数字、下划线或短横线，前台与接口均按此编码筛选。</p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">品牌名称（中文）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="name"
                rules="required"
                :value="old('name', $brand?->name)"
                placeholder="例如 宇树"
            />
            <x-admin::form.control-group.error control-name="name" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>英文 / 厂商名称</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="name_en"
                :value="old('name_en', $brand?->name_en)"
                placeholder="例如 Unitree"
            />
            <x-admin::form.control-group.error control-name="name_en" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>搜索 / 兼容匹配别名（逗号分隔）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="aliases"
                :value="$aliasesText"
                placeholder="例如 宇树, Unitree, G1, Go2"
            />
            <x-admin::form.control-group.error control-name="aliases" />
            <p class="mt-1 text-xs text-gray-500">用于前台搜索联想，以及对尚未手选「品牌」属性的老商品做回退匹配。</p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>品牌简介</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="textarea"
                name="description"
                :value="old('description', $brand?->description)"
                placeholder="可填写厂商定位、主打品类与技术特点"
            />
            <x-admin::form.control-group.error control-name="description" />
        </x-admin::form.control-group>
    </div>

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">RaaS 生态映射与展示配置</p>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>关联机型库厂商（<code>device_model.brand</code>）</x-admin::form.control-group.label>
            <input
                type="text"
                name="device_brand"
                list="device-brand-options"
                value="{{ $deviceBrand }}"
                placeholder="例如 unitree / agibot（选填）"
                class="w-full rounded border border-gray-300 px-3 py-2 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300"
            />
            <datalist id="device-brand-options">
                @foreach ($deviceBrands as $dbBrand)
                    <option value="{{ $dbBrand }}"></option>
                @endforeach
            </datalist>
            <x-admin::form.control-group.error control-name="device_brand" />
            <p class="mt-1 text-xs text-gray-500">映射后自动聚合该厂商在机型库中声明的可交付机型数与 Agent/人工下发通道。</p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>品牌官网地址</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="official_url"
                :value="old('official_url', $brand?->official_url)"
                placeholder="https://..."
            />
            <x-admin::form.control-group.error control-name="official_url" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>排序权重（越小越靠前）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="number"
                name="sort_order"
                :value="old('sort_order', $brand?->sort_order ?? 100)"
                placeholder="100"
            />
            <x-admin::form.control-group.error control-name="sort_order" />
        </x-admin::form.control-group>

        <div class="mb-4">
            <label class="mb-1.5 block text-xs font-medium text-gray-800 dark:text-white">品牌 Logo（PNG / JPG / WebP，最大 2MB）</label>
            @if ($brand?->logo_url)
                <div class="mb-2 flex items-center gap-3">
                    <img src="{{ $brand->logo_url }}" alt="{{ $brand->name }}" class="h-14 w-14 rounded-lg border border-gray-200 object-contain bg-white p-1" />
                    <label class="inline-flex items-center gap-1.5 text-xs text-red-600">
                        <input type="checkbox" name="remove_logo" value="1" />
                        移除当前 Logo
                    </label>
                </div>
            @endif
            <input
                type="file"
                name="logo_file"
                accept="image/*"
                class="block w-full cursor-pointer rounded-md border border-gray-300 p-2 text-sm text-gray-600 dark:border-gray-800 dark:text-gray-300"
            />
            <x-admin::form.control-group.error control-name="logo_file" />
        </div>

        <div class="mt-4 flex flex-wrap gap-6 border-t border-gray-100 pt-4 dark:border-gray-800">
            <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                <input type="hidden" name="is_hot" value="0" />
                <input type="checkbox" name="is_hot" value="1" @checked((bool) old('is_hot', $brand?->is_hot ?? false)) />
                设为热门 TOP 品牌
            </label>

            <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                <input type="hidden" name="status" value="0" />
                <input type="checkbox" name="status" value="1" @checked((bool) old('status', $brand?->status ?? true)) />
                启用展示（前台品牌栏可见）
            </label>
        </div>

        @if (! empty($refs))
            <div class="mt-4 rounded bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                当前引用状态：已绑定商品属性 <b>{{ $refs['products'] }}</b> 个 · 机型库关联 <b>{{ $refs['models'] }}</b> 条
                @if ($brand?->attribute_option_id)
                    · 同步属性选项 ID <code>#{{ $brand->attribute_option_id }}</code>
                @endif
            </div>
        @endif
    </div>
</div>
