<x-admin::layouts>
    <x-slot:title>技能管理 · {{ $product->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
                {{ $product->name }} <span class="text-sm font-normal text-gray-500">的技能包版本</span>
            </p>
            <p class="text-xs text-gray-500">SKU: {{ $product->sku }}　商品 ID: {{ $product->id }}</p>
        </div>

        <a href="{{ route('admin.catalog.skills.index') }}"
           class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回列表</a>
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

    {{-- ============ 新建版本 ============ --}}
    <div class="box-shadow mb-4 rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 text-base font-semibold text-gray-800 dark:text-white">新建版本</p>

        <form method="POST" action="{{ route('admin.catalog.skills.versions.store', $product->id) }}">
            @csrf

            <div class="grid gap-4 md:grid-cols-4">
                <label class="block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">版本号 <b class="text-red-500">*</b></span>
                    <input type="text" name="version" value="{{ old('version') }}" placeholder="1.0.0" required
                           class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">渠道</span>
                    <select name="channel" class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="stable">stable（正式）</option>
                        <option value="beta">beta（测试）</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">授权方式</span>
                    <select name="license_mode" class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="per_device">按设备</option>
                        <option value="per_order">按订单</option>
                        <option value="per_account">按账号</option>
                        <option value="unlimited">不限</option>
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">有效期（天，留空=永久）</span>
                    <input type="number" name="valid_days" min="1"
                           class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                </label>
            </div>

            <label class="mt-4 block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">安装指引（用户照着它把技能包装进机器）</span>
                <textarea name="install_guide" rows="3" placeholder="例：1. 下载技能包并解压到 U 盘根目录&#10;2. 机器人开机，插入 U 盘&#10;3. 进入 设置 → 开发者模式 → 导入技能&#10;4. 选择目录并确认导入&#10;5. 重启机器人生效"
                          class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
            </label>

            <div class="mt-3">
                <button type="submit" class="primary-button">创建版本</button>
            </div>
        </form>
    </div>

    {{-- ============ 版本列表 ============ --}}
    @forelse ($versions as $version)
        @php
            $statusMap = [
                'draft'     => ['草稿', 'bg-gray-100 text-gray-700'],
                'published' => ['已发布', 'bg-green-100 text-green-800'],
                'archived'  => ['已归档', 'bg-yellow-100 text-yellow-800'],
            ];
            [$statusText, $statusClass] = $statusMap[$version->status] ?? [$version->status, 'bg-gray-100 text-gray-700'];
        @endphp

        <div class="box-shadow mb-4 rounded bg-white p-4 dark:bg-gray-900">
            <div class="mb-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <span class="text-base font-semibold text-gray-800 dark:text-white">v{{ $version->version }}</span>
                    <span class="rounded px-2 py-0.5 text-xs {{ $statusClass }}">{{ $statusText }}</span>
                    <span class="text-xs text-gray-500">{{ $version->channel }}</span>
                </div>

                <div class="flex items-center gap-2">
                    {{-- S-3b · 版本仍是 published 但商品被下架 → 商品级「重新上架」。
                         此时不能走左侧的 publish（状态机没有 published → published，曾经 500）。 --}}
                    @if ($version->status === 'published' && ! $productSellable)
                        <span class="text-xs text-gray-500">复上架仍需过闸门与包指纹校验</span>
                        <form method="POST" action="{{ route('admin.catalog.skills.versions.relist', $version->id) }}">
                            @csrf
                            <button type="submit" class="primary-button">重新上架</button>
                        </form>
                    @endif

                    @if ($version->status !== 'published')
                        <form method="POST" action="{{ route('admin.catalog.skills.versions.publish', $version->id) }}">
                            @csrf
                            <button type="submit" class="primary-button">发布</button>
                        </form>
                    @endif

                    @if ($version->status !== 'published')
                        <form method="POST" action="{{ route('admin.catalog.skills.versions.delete', $version->id) }}"
                              onsubmit="return confirm('确定删除版本 v{{ $version->version }} 及其交付物？');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="transparent-button text-red-600 hover:bg-red-50">删除</button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- 交付物 --}}
            <div class="mb-4 rounded border border-gray-200 p-3 dark:border-gray-700">
                <p class="mb-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    交付物
                    <span class="ml-1 text-xs font-normal text-gray-500">技能包文件，存在服务器私密目录，买家只能通过 App 鉴权下载</span>
                </p>

                @forelse ($version->assets as $asset)
                    <div class="mb-2 flex items-center justify-between rounded bg-gray-50 px-3 py-2 text-sm dark:bg-gray-800">
                        <span class="text-gray-800 dark:text-gray-100">
                            {{ $asset->file_name ?: $asset->carrier_type }}
                            @if ($asset->file_size)
                                <span class="text-xs text-gray-500">{{ number_format($asset->file_size / 1024, 1) }} KB</span>
                            @endif
                        </span>
                        <span class="text-xs text-gray-500">{{ $asset->carrier_type }}</span>
                    </div>
                @empty
                    <p class="mb-2 text-xs text-gray-400">还没有交付物，发布前必须至少上传一个。</p>
                @endforelse

                <form method="POST" action="{{ route('admin.catalog.skills.assets.store', $version->id) }}"
                      enctype="multipart/form-data" class="mt-2 flex flex-wrap items-end gap-2">
                    @csrf
                    <input type="hidden" name="carrier_type" value="file">

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">选择技能包文件</span>
                        <input type="file" name="file" required class="text-sm">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">包内入口（可选）</span>
                        <input type="text" name="entrypoint" placeholder="manifest.json"
                               class="rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    </label>

                    <button type="submit" class="secondary-button">{{ $version->assets->isEmpty() ? '上传' : '替换' }}</button>
                </form>
            </div>

            {{-- 授权参数 + 安装指引 --}}
            <form method="POST" action="{{ route('admin.catalog.skills.versions.update', $version->id) }}">
                @csrf
                @method('PUT')

                <div class="grid gap-4 md:grid-cols-3">
                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">授权方式</span>
                        <select name="license_mode" class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                            @foreach (['per_device' => '按设备', 'per_order' => '按订单', 'per_account' => '按账号', 'unlimited' => '不限'] as $k => $label)
                                <option value="{{ $k }}" @selected($version->license_mode === $k)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">最大激活数（留空=不限）</span>
                        <input type="number" name="max_activations" min="1" value="{{ $version->max_activations }}"
                               class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    </label>

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">有效期（天，留空=永久）</span>
                        <input type="number" name="valid_days" min="1" value="{{ $version->valid_days }}"
                               class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    </label>
                </div>

                <label class="mt-4 block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">
                        安装指引 <span class="text-gray-400">（买家在 App 的技能详情里能看到这段，按你自己机器人的导入方式写）</span>
                    </span>
                    <textarea name="install_guide" rows="5"
                              class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">{{ $version->install_guide }}</textarea>
                </label>

                <label class="mt-4 block">
                    <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">变更日志（可选）</span>
                    <textarea name="changelog" rows="2"
                              class="w-full rounded border px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">{{ $version->changelog }}</textarea>
                </label>

                <div class="mt-3">
                    <button type="submit" class="primary-button">保存</button>
                </div>
            </form>
        </div>
    @empty
        <div class="box-shadow rounded bg-white p-10 text-center text-gray-500 dark:bg-gray-900">
            这个技能还没有任何版本。先在上面「新建版本」，再上传技能包。
        </div>
    @endforelse
</x-admin::layouts>
