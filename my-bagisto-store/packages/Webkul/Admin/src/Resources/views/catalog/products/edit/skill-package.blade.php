{{--
    技能包管理 —— 只在「技能」分类下的商品上显示。

    为什么全用 JS fetch 而不是普通表单:
    产品编辑页整体被 <x-admin::form> 包住,里面再放原生 <form> 会造成非法嵌套,
    HTML 解析器会把内层表单拆掉。场景管理的状态开关也是这么做的(fetch + CSRF),
    这里沿用同一套。

    数据直接查,不指望 ProductController 传 —— 免得动 Bagisto 原生控制器的签名。
--}}
@php
    $skillCategoryId = \Webkul\Category\Models\Category::query()
        ->whereHas('translations', fn ($q) => $q->where('slug', 'skill'))
        ->value('id');

    $isSkillProduct = $skillCategoryId
        && \Illuminate\Support\Facades\DB::table('product_categories')
            ->where('category_id', $skillCategoryId)
            ->where('product_id', $product->id)
            ->exists();

    $skillVersions = $isSkillProduct
        ? \Webkul\Shop\Models\SkillVersion::with('assets')
            ->where('product_id', $product->id)
            ->orderByDesc('id')
            ->get()
        : collect();

    $statusLabels = [
        'draft'     => ['草稿', 'bg-gray-100 text-gray-700'],
        'published' => ['已发布', 'bg-green-100 text-green-800'],
        'archived'  => ['已归档', 'bg-yellow-100 text-yellow-800'],
    ];
@endphp

@if ($isSkillProduct)
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" id="skill-package-panel">
        <div class="mb-1 flex items-center justify-between">
            <p class="text-base font-semibold text-gray-800 dark:text-white">技能包</p>
            <a href="{{ route('admin.catalog.skills.edit', $product->id) }}"
               class="cursor-pointer text-xs text-blue-600 hover:underline">在独立页管理 →</a>
        </div>

        <p class="mb-4 text-xs leading-relaxed text-gray-500">
            技能包存在服务器私密目录，买家下单后在 App 里鉴权下载。
            <b>安装指引</b>会展示给买家 —— 第三方成品机器人平台无法自动下发，用户要照着它自己装。
        </p>

        {{-- 版本列表 --}}
        @forelse ($skillVersions as $version)
            @php [$sText, $sClass] = $statusLabels[$version->status] ?? [$version->status, 'bg-gray-100 text-gray-700']; @endphp

            <div class="mb-3 rounded border border-gray-200 p-3 dark:border-gray-700">
                <div class="mb-2 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-800 dark:text-white">v{{ $version->version }}</span>
                        <span class="rounded px-2 py-0.5 text-xs {{ $sClass }}">{{ $sText }}</span>
                        @if ($version->assets->isNotEmpty())
                            <span class="text-xs text-gray-500">{{ $version->assets->first()->file_name }}</span>
                        @else
                            <span class="text-xs text-red-500">缺交付物</span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($version->status !== 'published')
                            <button type="button" class="secondary-button"
                                    onclick="skillAction('{{ route('admin.catalog.skills.versions.publish', $version->id) }}', 'POST')">
                                发布
                            </button>
                            <button type="button" class="transparent-button text-red-600 hover:bg-red-50"
                                    onclick="skillAction('{{ route('admin.catalog.skills.versions.delete', $version->id) }}', 'DELETE', true)">
                                删除
                            </button>
                        @endif
                    </div>
                </div>

                {{-- 上传交付物:input 刻意不给 name,避免被外层商品表单带上 --}}
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <input type="file" id="skill-file-{{ $version->id }}" class="text-xs">
                    <button type="button" class="secondary-button"
                            onclick="skillUpload({{ $version->id }})">
                        {{ $version->assets->isEmpty() ? '上传技能包' : '替换技能包' }}
                    </button>
                </div>

                {{-- 安装指引 --}}
                <label class="block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">安装指引</span>
                    <textarea id="skill-guide-{{ $version->id }}" rows="3"
                              placeholder="1. 下载技能包并解压到 U 盘根目录&#10;2. 机器人开机，插入 U 盘&#10;3. 进入 设置 → 开发者模式 → 导入技能"
                              class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">{{ $version->install_guide }}</textarea>
                </label>

                <div class="mt-2">
                    <button type="button" class="secondary-button"
                            onclick="skillSaveGuide({{ $version->id }})">保存指引</button>
                </div>
            </div>
        @empty
            <p class="mb-3 text-xs text-gray-400">还没有版本。先新建一个，再上传技能包。</p>
        @endforelse

        {{-- 新建版本 --}}
        <div class="mt-4 rounded border border-dashed border-gray-300 p-3 dark:border-gray-600">
            <p class="mb-2 text-xs font-medium text-gray-700 dark:text-gray-200">新建版本</p>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" id="skill-new-version" placeholder="版本号，如 1.0.0" class="rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                <select id="skill-new-license" class="rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="per_device">按设备授权</option>
                    <option value="per_order">按订单授权</option>
                    <option value="per_account">按账号授权</option>
                    <option value="unlimited">不限</option>
                </select>
                <input type="number" id="skill-new-days" min="1" placeholder="有效期(天,留空永久)" class="w-44 rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                <button type="button" class="primary-button" onclick="skillCreateVersion()">创建版本</button>
            </div>
            <p class="mt-2 text-xs text-gray-400">创建后是草稿，上传技能包并填好指引后点「发布」，买家才能买到。</p>
        </div>

        <p id="skill-msg" class="mt-3 hidden text-xs"></p>
    </div>

    @push('scripts')
        <script>
            window.__skillCsrf = '{{ csrf_token() }}';
            window.__skillProductId = {{ $product->id }};
            // 用 route() 生成,避免把 /admin 前缀硬编码进 JS
            window.__skillCreateUrl = '{{ route('admin.catalog.skills.versions.store', $product->id) }}';
            window.__skillAssetUrl = '{{ route('admin.catalog.skills.assets.store', ['versionId' => '__ID__']) }}';
            window.__skillVersionUrl = '{{ route('admin.catalog.skills.versions.update', ['versionId' => '__ID__']) }}';

            window.__skillMsg = function (text, ok) {
                var el = document.getElementById('skill-msg');
                el.textContent = text;
                el.className = 'mt-3 text-xs ' + (ok ? 'text-green-700' : 'text-red-600');
            };

            // 统一的 POST/PUT/DELETE 调用 —— 成功就整页刷新,让服务端重新渲染版本列表
            window.skillAction = function (url, method, confirmFirst) {
                if (confirmFirst && ! window.confirm('确定删除这个版本及其交付物？')) return;

                fetch(url, {
                    method: method,
                    headers: {
                        'X-CSRF-TOKEN': window.__skillCsrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function () { location.reload(); });
            };

            window.skillCreateVersion = function () {
                var version = document.getElementById('skill-new-version').value.trim();
                if (! version) { window.__skillMsg('请填版本号', false); return; }

                var body = new FormData();
                body.append('version', version);
                body.append('license_mode', document.getElementById('skill-new-license').value);
                var days = document.getElementById('skill-new-days').value;
                if (days) body.append('valid_days', days);

                fetch(window.__skillCreateUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': window.__skillCsrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).then(function () { location.reload(); });
            };

            window.skillUpload = function (versionId) {
                var input = document.getElementById('skill-file-' + versionId);
                if (! input.files.length) { window.__skillMsg('请先选择技能包文件', false); return; }

                var body = new FormData();
                body.append('file', input.files[0]);
                body.append('carrier_type', 'file');

                window.__skillMsg('上传中…', true);
                fetch(window.__skillAssetUrl.replace('__ID__', versionId), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': window.__skillCsrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).then(function () { location.reload(); });
            };

            window.skillSaveGuide = function (versionId) {
                var body = new FormData();
                body.append('_method', 'PUT');
                body.append('install_guide', document.getElementById('skill-guide-' + versionId).value);

                fetch(window.__skillVersionUrl.replace('__ID__', versionId), {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': window.__skillCsrf,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).then(function () { location.reload(); });
            };
        </script>
    @endpush
@endif
