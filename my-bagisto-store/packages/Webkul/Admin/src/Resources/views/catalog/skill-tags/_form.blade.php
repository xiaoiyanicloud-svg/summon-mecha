{{-- 标签表单 —— 新建/编辑共用。期望 $tag(可 null) 与 $types --}}
@php $tag = $tag ?? null; @endphp

<div class="mt-3.5 grid gap-4 xl:grid-cols-2">
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">标签名</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="name" rules="required" :value="$tag?->name" placeholder="舞蹈" />
            <x-admin::form.control-group.error control-name="name" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">分组</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="select" name="type" :value="$tag?->type ?? 'motion_type'">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-admin::form.control-group.control>
            <p class="mt-1 text-xs text-gray-500">
                前台按分组分栏展示。分组是白名单，要在加一组得先改代码 —— 这是刻意的，
                免得出现 scene / Scene / scenes 三个拼写变体各自成栏。
            </p>
        </x-admin::form.control-group>
    </div>

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label>排序权重（小在前）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" name="sort_order" :value="$tag?->sort_order ?? 0" />
        </x-admin::form.control-group>
    </div>
</div>
