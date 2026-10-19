<x-admin::layouts>
    <x-slot:title>生态媒体</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">生态媒体工作台</p>
            <p class="text-xs text-gray-500">
                媒体落在私有盘；本页只展示 MIME / 大小 / 尺寸 / 状态，绝不展示 storage_key 或私有路径。
                上传与审核是两个独立权限。
            </p>
        </div>

        <div class="flex items-center gap-x-2.5">
            <a href="{{ route('admin.catalog.ecosystem.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回工作台</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if (bouncer()->hasPermission('ecosystem.media.upload'))
        <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">上传媒体（上传后为 pending）</p>

            <form method="POST" action="{{ route('admin.catalog.ecosystem.media.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <label class="text-xs text-gray-600">
                    文件（JPEG / PNG / WebP / PDF；图片 ≤ 5MB，文档 ≤ 10MB）
                    <input type="file" name="file" required accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-1 block w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('file') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <button type="submit" class="primary-button">上传</button>
            </form>

            <p class="mt-2 text-xs text-gray-500">
                服务端用 finfo 解码真实 MIME，并校验扩展名、getimagesize / PDF 头部与 sha256；
                相同内容不允许重复上传或原位替换。
            </p>
        </div>
    @endif

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <div class="flex items-center justify-between border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">媒体（最近 100 条）</p>
            <span class="text-xs text-gray-500">pending → approved / rejected；pending | approved → quarantined</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">文件</th>
                        <th class="px-4 py-3">真实 MIME</th>
                        <th class="px-4 py-3">大小</th>
                        <th class="px-4 py-3">尺寸</th>
                        <th class="px-4 py-3">状态</th>
                        <th class="px-4 py-3">上传人 / 时间</th>
                        <th class="px-4 py-3">审核操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($media as $item)
                        <tr class="border-b last:border-b-0 align-top dark:border-gray-800">
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $item->id }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800 dark:text-white">{{ $item->original_name ?: '（未命名）' }}</div>
                                @if (bouncer()->hasPermission('ecosystem.media.view'))
                                    <a href="{{ route('admin.catalog.ecosystem.media.preview', $item->id) }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs text-blue-600 hover:underline">预览</a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $item->mime }}</td>
                            <td class="px-4 py-3 text-xs">{{ $item->byte_size }} B</td>
                            <td class="px-4 py-3 text-xs">
                                {{ $item->width && $item->height ? $item->width.'×'.$item->height.' px' : '—' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs {{ $item->status === 'approved' ? 'bg-green-100 text-green-800' : ($item->status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
                                    {{ $item->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                #{{ $item->uploaded_by }}
                                <div>{{ $item->created_at ? $item->created_at->utc()->format('Y-m-d H:i') : '—' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                @if (bouncer()->hasPermission('ecosystem.media.review'))
                                    <div class="flex flex-col gap-2">
                                        @if ($item->status === 'pending')
                                            <form method="POST" action="{{ route('admin.catalog.ecosystem.media.approve', $item->id) }}">
                                                @csrf
                                                <input type="hidden" name="expected_status" value="{{ $item->status }}">
                                                <button type="submit" class="primary-button !py-1 !text-xs">批准</button>
                                            </form>

                                            <form method="POST" action="{{ route('admin.catalog.ecosystem.media.reject', $item->id) }}">
                                                @csrf
                                                <input type="hidden" name="expected_status" value="{{ $item->status }}">
                                                <button type="submit" class="transparent-button !py-1 !text-xs">拒绝</button>
                                            </form>
                                        @endif

                                        @if (in_array($item->status, ['pending', 'approved'], true))
                                            <form method="POST" action="{{ route('admin.catalog.ecosystem.media.quarantine', $item->id) }}">
                                                @csrf
                                                <input type="hidden" name="expected_status" value="{{ $item->status }}">
                                                <button type="submit" class="transparent-button !py-1 !text-xs">隔离</button>
                                            </form>
                                        @endif

                                        @if (! in_array($item->status, ['pending', 'approved'], true))
                                            <span class="text-xs text-gray-400">不可原地恢复；请新建媒体</span>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-10 text-center text-gray-500">还没有媒体记录。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-admin::layouts>
