<x-admin::layouts>
    <x-slot:title>生态资讯</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">生态资讯</p>
        <a href="{{ route('admin.catalog.ecosystem.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回工作台</a>
    </div>

    <x-admin::flash />

    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 font-semibold text-gray-800 dark:text-white">新建文章壳（草稿）</p>

        @if (bouncer()->hasPermission('ecosystem.content.create'))
            <form method="POST" action="{{ route('admin.catalog.ecosystem.content.store') }}" class="grid gap-3 md:grid-cols-4">
                @csrf

                <label class="text-xs text-gray-600">
                    类型
                    <select name="type" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_content.content_types', []) as $type)
                            <option value="{{ $type }}" @selected(old('type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-xs text-gray-600">
                    slug（小写 ASCII / 数字 / 连字符）
                    <input name="slug" value="{{ old('slug') }}" maxlength="120" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600">
                    分类（可选）
                    <input name="category" value="{{ old('category') }}" maxlength="64" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <div class="flex items-end">
                    <button type="submit" class="primary-button">创建草稿</button>
                </div>
            </form>

            @error('slug') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @else
            <p class="text-sm text-gray-500">没有创建文章权限。</p>
        @endif
    </div>

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">slug</th>
                    <th class="px-4 py-3">类型</th>
                    <th class="px-4 py-3">发布状态</th>
                    <th class="px-4 py-3">处置</th>
                    <th class="px-4 py-3">行版本</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($posts as $post)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $post->id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">{{ $post->slug }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->type }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->publish_status }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->disposition }}</td>
                        <td class="px-4 py-3 text-xs">{{ $post->row_version }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.catalog.ecosystem.content.show', $post->id) }}" class="text-blue-600 hover:underline">工作页</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500">还没有资讯记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
