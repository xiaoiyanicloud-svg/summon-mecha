<x-admin::layouts>
    <x-slot:title>技能导入</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">技能导入</p>
        <a href="{{ route('admin.catalog.skills.index') }}"
           class="text-sm text-blue-600 hover:underline">← 回技能管理</a>
    </div>

    @if (session('success'))
        <div class="mb-3 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm text-green-800">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
            {{ session('error') }}
        </div>
    @endif

    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">这里是干什么的</p>
        <p class="mt-1 leading-relaxed">
            把他平台 / 动捕技能包的**元数据**贴进来,导入器会把它映射成平台**中立清单**
            (manifest)。支持的两种来源:
            <b>宇树 UniStore</b>(含 <code>base_image</code> / <code>startup_commands</code>)、
            <b>通用动捕</b>(含 <code>motion_format</code>:bvh / smplx / fbx / macp)。
            无法自动映射的部分会列成「警告」,并**自动收紧**兼容机型与交付通道 ——
            导入后不会出现"能下发到不兼容设备"的技能。
        </p>
        <p class="mt-1 leading-relaxed text-blue-800">
            这份能力与开发者/CI 走的 <code>POST /api/skill-supply/import</code> 是**同一套实现**,
            只是多了一个界面入口。
        </p>
    </div>

    {{-- ===== 导入表单 ===== --}}
    <div class="box-shadow mb-4 rounded bg-white p-4 dark:bg-gray-900">
        <form method="POST" action="{{ route('admin.catalog.skills.import.store') }}" class="space-y-3">
            @csrf

            <div class="flex items-center justify-between">
                <label class="text-sm font-semibold text-gray-700 dark:text-gray-200">源元数据(JSON)</label>
                <div class="flex gap-2 text-xs">
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50"
                            data-fill="{{ $exampleUnitree }}">填入宇树样例</button>
                    <button type="button" class="rounded border border-gray-300 px-2 py-1 hover:bg-gray-50"
                            data-fill="{{ $exampleMotion }}">填入动捕样例</button>
                </div>
            </div>

            <textarea id="metadata" name="metadata" rows="10" required
                      placeholder='{"name":"我的技能","version":"1.0.0","motion_format":"bvh"}'
                      class="w-full rounded border border-gray-300 px-3 py-2 font-mono text-xs dark:border-gray-700 dark:bg-gray-800">{{ old('metadata') }}</textarea>

            @error('metadata')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">来源备注(可选)</label>
                    <input type="text" name="source_ref" value="{{ old('source_ref') }}" maxlength="255"
                           placeholder="如: https://unistore.example.com/app/123"
                           class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">
                        挂到商品(可选 —— 选了就落一条草稿版本)
                    </label>
                    <select name="product_id" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">— 只导入留痕,不挂商品 —</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>
                                #{{ $product->id }} {{ $product->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">导入</button>
                <span class="text-xs text-gray-500">
                    若挂了商品:版本号取清单里的 version,落为<b>草稿</b> —— 仍需在「技能管理」上传交付物并在「审核台」过闸门后才能发布。
                </span>
            </div>
        </form>
    </div>

    {{-- ===== 导入结果预览 ===== --}}
    @php $result = session('import_result'); @endphp
    @if ($result)
        <div class="box-shadow mb-4 rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-2 font-semibold text-gray-800 dark:text-white">
                导入结果 <span class="text-xs font-normal text-gray-500">(留痕 ID #{{ $result['import_id'] }})</span>
            </p>

            @if (! empty($result['created_version']))
                <p class="mb-2 text-sm text-green-700">
                    ✅ 已创建草稿版本:商品 #{{ $result['created_product'] }} 的版本 id {{ $result['created_version'] }}。
                    <a href="{{ route('admin.catalog.skills.edit', $result['created_product']) }}" class="text-blue-600 hover:underline">去管理版本</a>
                </p>
            @endif

            @if (! empty($result['locked_models']) || ! empty($result['locked_channels']))
                <div class="mb-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    自动收紧的约束 —
                    机型:<b>{{ implode(', ', $result['locked_models']) ?: '未锁定' }}</b>
                    通道:<b>{{ implode(', ', $result['locked_channels']) ?: '未锁定' }}</b>
                </div>
            @endif

            @if (! empty($result['warnings']))
                <div class="mb-2 rounded border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <p class="font-semibold">警告</p>
                    <ul class="mt-1 list-disc pl-4">
                        @foreach ($result['warnings'] as $w)<li>{{ $w }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <p class="mb-1 text-xs font-semibold text-gray-600 dark:text-gray-300">映射出的中立清单</p>
            <pre class="max-h-96 overflow-auto rounded bg-gray-50 p-3 text-xs text-gray-800 dark:bg-gray-800 dark:text-gray-100">{{ json_encode($result['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    {{-- ===== 导入留痕 ===== --}}
    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <p class="border-b px-4 py-3 font-semibold text-gray-800 dark:text-white">最近导入留痕</p>
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-2">#</th>
                    <th class="px-4 py-2">来源格式</th>
                    <th class="px-4 py-2">来源备注</th>
                    <th class="px-4 py-2">状态</th>
                    <th class="px-4 py-2">时间</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($imports as $import)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $import->id }}</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $import->source_format ?: '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $import->source_ref ?: '—' }}</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-200">{{ $import->status ?: '—' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $import->created_at?->format('Y-m-d H:i') ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">还没有导入记录。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- 样例填入:用 data 属性携带 JSON(由 Blade 转义),避免把双引号塞进 onclick 属性 --}}
    <script>
        document.querySelectorAll('[data-fill]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                document.getElementById('metadata').value = btn.getAttribute('data-fill');
            });
        });
    </script>
</x-admin::layouts>
