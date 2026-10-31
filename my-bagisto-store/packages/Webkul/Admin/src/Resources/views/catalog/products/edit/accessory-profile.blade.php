{{--
    配件皮肤 · 后台维护（P1）。

    只在「配件」分类下的商品上显示。
    商品本身的分类/图片/价格/描述仍走 Bagisto 原生字段；这里只维护配件域的两件事：
      1. 配件档案（安装方式、包装清单、质保、交期、材质、官方/定制标记）
      2. 适配关系（商品 × device_model.model_code，带直装/需转接/需定制/不适配等级）

    用 fetch + CSRF 而不是嵌套 <form>：外层商品编辑页已经是 <x-admin::form>，
    内层再放原生 form 会被 HTML 解析器拆掉（与 skill-package 同一处理）。
--}}
@php
    $accessoryCategoryId = \Webkul\Category\Models\Category::query()
        ->whereHas('translations', fn ($q) => $q->where('slug', 'accessory'))
        ->value('id');

    $accessoryChildIds = $accessoryCategoryId
        ? \Webkul\Category\Models\Category::query()->where('parent_id', $accessoryCategoryId)->pluck('id')->all()
        : [];

    $isAccessoryProduct = $accessoryCategoryId
        && \Illuminate\Support\Facades\DB::table('product_categories')
            ->where('product_id', $product->id)
            ->whereIn('category_id', [$accessoryCategoryId, ...$accessoryChildIds])
            ->exists();

    $accessoryProfile = $isAccessoryProduct
        ? \Webkul\Shop\Models\AccessoryProfile::where('product_id', $product->id)->first()
        : null;

    $accessoryFitments = $isAccessoryProduct
        ? \Webkul\Shop\Models\AccessoryFitment::where('product_id', $product->id)->orderBy('id')->get()
        : collect();

    $deviceModels = $isAccessoryProduct
        ? \Webkul\Shop\Models\DeviceModel::orderBy('sort_order')->orderBy('id')->get()
        : collect();

    $installLabels = \Webkul\Shop\Models\AccessoryProfile::INSTALL_LABELS;
    $fitLabels = \Webkul\Shop\Models\AccessoryFitment::FIT_LABELS;
    $sourceLabels = \Webkul\Shop\Models\AccessoryFitment::SOURCE_LABELS;
@endphp

@if ($isAccessoryProduct)
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900" id="accessory-profile-panel">
        <div class="mb-1 flex items-center justify-between">
            <p class="text-base font-semibold text-gray-800 dark:text-white">配件档案与适配</p>
            <span class="text-xs text-gray-500">买家端详情页会直接展示这里的安装/质保/交期与适配等级</span>
        </div>

        <div class="mt-4 grid gap-3 md:grid-cols-2">
            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">一句话摘要</span>
                <input id="acc-summary" type="text" maxlength="255" value="{{ $accessoryProfile->summary ?? '' }}"
                       class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">安装方式</span>
                <select id="acc-install" class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                    @foreach ($installLabels as $value => $label)
                        <option value="{{ $value }}" @selected(($accessoryProfile->install_method ?? 'self') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">质保（月）</span>
                <input id="acc-warranty" type="number" min="0" max="120" value="{{ $accessoryProfile->warranty_months ?? '' }}"
                       class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">交期（天）</span>
                <input id="acc-lead" type="number" min="0" max="365" value="{{ $accessoryProfile->lead_time_days ?? '' }}"
                       class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">材质 / 工艺</span>
                <input id="acc-material" type="text" maxlength="64" value="{{ $accessoryProfile->material ?? '' }}"
                       class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">供电 / 接口要求</span>
                <input id="acc-power" type="text" maxlength="128" value="{{ $accessoryProfile->power_requirement ?? '' }}"
                       class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
            </label>
        </div>

        <label class="mt-3 block">
            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">包装清单（每行一项）</span>
            <textarea id="acc-package" rows="3"
                      class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">{{ $accessoryProfile->package_contents ?? '' }}</textarea>
        </label>

        <label class="mt-3 block">
            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">通用适配说明（没有具体机型关系时必填）</span>
            <textarea id="acc-note" rows="2"
                      class="w-full rounded border px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">{{ $accessoryProfile->compatibility_note ?? '' }}</textarea>
        </label>

        <div class="mt-3 flex flex-wrap items-center gap-5">
            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                <input id="acc-official" type="checkbox" @checked($accessoryProfile->official ?? false)>
                官方自营
            </label>
            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                <input id="acc-custom" type="checkbox" @checked($accessoryProfile->custom_service ?? false)>
                支持定制（走报价，不在线下单）
            </label>
            <label class="flex items-center gap-2 text-xs text-gray-700 dark:text-gray-200">
                定制起价
                <input id="acc-custom-price" type="number" min="0" step="0.01" value="{{ $accessoryProfile->custom_price_from ?? '' }}"
                       class="w-32 rounded border px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800">
            </label>
            <button type="button" class="primary-button" onclick="accessorySaveProfile()">保存配件档案</button>
        </div>

        <hr class="my-5 border-gray-200 dark:border-gray-700">

        <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">适配机型</p>
        <p class="mb-3 text-xs text-gray-500">
            机型来自平台机型库（device_model）。<b>直装</b>=原接口安装；<b>需转接</b>=需另购转接件；
            <b>需定制</b>=先报价；<b>不适配</b>=明确不卖。不要在没核实的情况下填“直装”。
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-gray-700">
                        <th class="py-2 pr-2">机型</th>
                        <th class="py-2 pr-2">适配等级</th>
                        <th class="py-2 pr-2">转接件 SKU</th>
                        <th class="py-2 pr-2">备注</th>
                        <th class="py-2 pr-2">来源</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($accessoryFitments as $fitment)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pr-2 font-medium text-gray-800 dark:text-white">{{ $fitment->model_code }}</td>
                            <td class="py-2 pr-2">{{ $fitLabels[$fitment->fit_level] ?? $fitment->fit_level }}</td>
                            <td class="py-2 pr-2">{{ $fitment->adapter_sku ?: '—' }}</td>
                            <td class="py-2 pr-2">{{ $fitment->note ?: '—' }}</td>
                            <td class="py-2 pr-2">{{ $sourceLabels[$fitment->source] ?? $fitment->source }}</td>
                            <td class="py-2 text-right">
                                <button type="button" class="text-red-600 hover:underline"
                                        onclick="accessoryDeleteFitment('{{ route('admin.catalog.accessories.fitments.delete', [$product->id, $fitment->id]) }}')">
                                    删除
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-3 text-gray-400">还没有适配关系。可以在下面新增一条。</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 rounded border border-dashed border-gray-300 p-3 dark:border-gray-600">
            <p class="mb-2 text-xs font-medium text-gray-700 dark:text-gray-200">新增 / 更新一条适配</p>
            <div class="flex flex-wrap items-center gap-2">
                <select id="acc-fit-model" class="rounded border px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800">
                    <option value="">选择机型</option>
                    @foreach ($deviceModels as $model)
                        <option value="{{ $model->model_code }}">{{ $model->model_name }}（{{ $model->model_code }}）</option>
                    @endforeach
                </select>

                <select id="acc-fit-level" class="rounded border px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800">
                    @foreach ($fitLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <input id="acc-fit-adapter" type="text" placeholder="转接件 SKU（可空）"
                       class="w-40 rounded border px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800">
                <input id="acc-fit-note" type="text" placeholder="备注，如“仅限 EDU 版”"
                       class="w-56 rounded border px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800">

                <select id="acc-fit-source" class="rounded border px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-800">
                    @foreach ($sourceLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <button type="button" class="secondary-button" onclick="accessorySaveFitment()">保存适配</button>
            </div>
        </div>

        <p id="acc-msg" class="mt-3 hidden text-xs"></p>
    </div>

    @push('scripts')
        <script>
            window.__accCsrf = '{{ csrf_token() }}';
            window.__accProfileUrl = '{{ route('admin.catalog.accessories.profile.update', $product->id) }}';
            window.__accFitmentUrl = '{{ route('admin.catalog.accessories.fitments.store', $product->id) }}';

            window.accessoryMsg = function (text, ok) {
                var el = document.getElementById('acc-msg');
                el.textContent = text;
                el.className = 'mt-3 text-xs ' + (ok ? 'text-green-700' : 'text-red-600');
            };

            window.accessoryRequest = function (url, method, body) {
                return fetch(url, {
                    method: method,
                    headers: {
                        'X-CSRF-TOKEN': window.__accCsrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify(body || {})
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        if (! response.ok) throw new Error(data.message || '保存失败');
                        return data;
                    });
                });
            };

            window.accessorySaveProfile = function () {
                var numberOrNull = function (id) {
                    var value = document.getElementById(id).value.trim();
                    return value === '' ? null : Number(value);
                };

                window.accessoryRequest(window.__accProfileUrl, 'POST', {
                    summary: document.getElementById('acc-summary').value.trim() || null,
                    install_method: document.getElementById('acc-install').value,
                    package_contents: document.getElementById('acc-package').value.trim() || null,
                    warranty_months: numberOrNull('acc-warranty'),
                    lead_time_days: numberOrNull('acc-lead'),
                    material: document.getElementById('acc-material').value.trim() || null,
                    official: document.getElementById('acc-official').checked,
                    custom_service: document.getElementById('acc-custom').checked,
                    custom_price_from: numberOrNull('acc-custom-price'),
                    compatibility_note: document.getElementById('acc-note').value.trim() || null,
                    power_requirement: document.getElementById('acc-power').value.trim() || null
                }).then(function () { location.reload(); })
                  .catch(function (error) { window.accessoryMsg(error.message, false); });
            };

            window.accessorySaveFitment = function () {
                var model = document.getElementById('acc-fit-model').value;
                if (! model) { window.accessoryMsg('请选择机型', false); return; }

                window.accessoryRequest(window.__accFitmentUrl, 'POST', {
                    model_code: model,
                    fit_level: document.getElementById('acc-fit-level').value,
                    adapter_sku: document.getElementById('acc-fit-adapter').value.trim() || null,
                    note: document.getElementById('acc-fit-note').value.trim() || null,
                    source: document.getElementById('acc-fit-source').value
                }).then(function () { location.reload(); })
                  .catch(function (error) { window.accessoryMsg(error.message, false); });
            };

            window.accessoryDeleteFitment = function (url) {
                if (! window.confirm('确定删除这条适配关系？')) return;
                window.accessoryRequest(url, 'DELETE', {})
                    .then(function () { location.reload(); })
                    .catch(function (error) { window.accessoryMsg(error.message, false); });
            };
        </script>
    @endpush
@endif
