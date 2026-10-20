<x-admin::layouts>
    <x-slot:title>生态关联 · {{ $sourceLabel }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">生态关联 · {{ $sourceLabel }}</p>
            <p class="text-xs text-gray-500">
                {{ $sourceLabel }} #{{ $source->id }} · {{ $sourceName }}
                <span class="ml-2">目标只允许真实存在对象；公开端会再次复核，失效目标不展示。</span>
            </p>
        </div>

        <div class="flex items-center gap-x-2.5">
            <a href="{{ $backRoute }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回工作页</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 font-semibold text-gray-800 dark:text-white">添加 / 重新启用关联</p>

        <form method="POST" action="{{ $sourceType === 'event'
            ? route('admin.catalog.ecosystem.relations.events.store', $source->id)
            : route('admin.catalog.ecosystem.relations.content.store', $source->id) }}">
            @csrf

            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <label class="text-sm">
                    <span class="mb-1 block text-gray-600 dark:text-gray-300">目标类型</span>
                    <select name="target_type" class="w-full rounded border px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                        @foreach ($targetTypes as $type)
                            <option value="{{ $type }}" @selected(old('target_type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-gray-600 dark:text-gray-300">目标标识</span>
                    <input name="target_key" value="{{ old('target_key') }}" maxlength="191"
                        class="w-full rounded border px-3 py-2 dark:border-gray-700 dark:bg-gray-800"
                        placeholder="活动/机型/技能/场景填 ID；资讯填 slug">
                </label>

                <label class="text-sm">
                    <span class="mb-1 block text-gray-600 dark:text-gray-300">排序</span>
                    <input name="sort_order" type="number" min="0" max="65535" value="{{ old('sort_order', 0) }}"
                        class="w-full rounded border px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
                </label>

                <div class="flex items-end">
                    <button type="submit" class="primary-button">保存关联</button>
                </div>
            </div>

            <p class="mt-2 text-xs text-gray-500">
                event=活动 ID；content=资讯 slug；device_model=机型 ID；skill=商品 ID（需有已发布技能版本）；scene=场景 ID。
            </p>
        </form>
    </div>

    <div class="box-shadow overflow-hidden rounded bg-white dark:bg-gray-900">
        <div class="border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">当前关联（{{ $relations->count() }}）</p>
        </div>

        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">目标类型</th>
                    <th class="px-4 py-3">目标标识</th>
                    <th class="px-4 py-3">排序</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($relations as $relation)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $relation->id }}</td>
                        <td class="px-4 py-3">{{ $relation->target_type }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $relation->target_key }}</td>
                        <td class="px-4 py-3">{{ $relation->sort_order }}</td>
                        <td class="px-4 py-3">{{ $relation->status }}</td>
                        <td class="px-4 py-3 text-right">
                            <form method="POST" action="{{ $sourceType === 'event'
                                ? route('admin.catalog.ecosystem.relations.events.destroy', [$source->id, $relation->id])
                                : route('admin.catalog.ecosystem.relations.content.destroy', [$source->id, $relation->id]) }}">
                                @csrf
                                <button type="submit" class="text-red-600 hover:underline">删除</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">还没有关联。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
