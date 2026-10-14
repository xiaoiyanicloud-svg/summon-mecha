{{-- 类目表单 —— 新建/编辑共用。期望 $category(可 null) 与 $parents --}}
@php
    $category = $category ?? null;
    $name = $category?->name ?? [];
@endphp

<div class="mt-3.5 grid gap-4 xl:grid-cols-2">
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">中文名</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="name_zh" rules="required" :value="$name['zh_CN'] ?? null" placeholder="展演" />
            <x-admin::form.control-group.error control-name="name_zh" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>英文名</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="name_en" :value="$name['en'] ?? null" placeholder="Performance" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">编码</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="code" rules="required" :value="$category?->code" placeholder="show" />
            <x-admin::form.control-group.error control-name="code" />
            <p class="mt-1 text-xs text-gray-500">
                全局唯一，前台与筛选链接按它引用 —— <b>建好之后不要改</b>。
            </p>
        </x-admin::form.control-group>
    </div>

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label>上级类目</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="select" name="parent_id" :value="$category?->parent_id">
                <option value="">—— 顶级类目 ——</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}">{{ $parent->nameIn() }}</option>
                @endforeach
            </x-admin::form.control-group.control>
            <p class="mt-1 text-xs text-gray-500">
                ⚠ 这套类目<b>不是</b> Bagisto 的商品分类 —— 商品归属看「目录 → 分类」。
            </p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">状态</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="select" name="status" :value="$category?->status ?? 'active'">
                <option value="active">启用 —— 出现在前台筛选</option>
                <option value="inactive">停用 —— 前台不再展示</option>
            </x-admin::form.control-group.control>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>排序权重（小在前）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" name="sort_order" :value="$category?->sort_order ?? 0" />
        </x-admin::form.control-group>
    </div>
</div>
