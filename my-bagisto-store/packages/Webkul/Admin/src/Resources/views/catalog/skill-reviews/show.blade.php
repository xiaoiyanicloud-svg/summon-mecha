<x-admin::layouts>
    <x-slot:title>审核 · {{ $version->product?->name ?? ('商品 #'.$version->product_id) }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                {{ $version->product?->name ?? ('商品 #'.$version->product_id) }}
                <span class="text-base font-normal text-gray-500">v{{ $version->version }}</span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
                商品 ID: {{ $version->product_id }}　版本 ID: {{ $version->id }}
                版本状态: {{ $version->status }}　交付物: {{ $version->assets->count() }} 个
            </p>
        </div>
        <a href="{{ route('admin.catalog.skill-reviews.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回审核台</span>
        </a>
    </div>

    <x-admin::flash />

    {{-- 闸门总判定 --}}
    <div class="mb-3 rounded-lg border px-4 py-3 text-sm {{ $evaluation['ok']
        ? 'border-green-200 bg-green-50 text-green-900'
        : 'border-red-200 bg-red-50 text-red-900' }}">
        <p class="font-semibold">
            {{ $evaluation['ok'] ? '闸门已过 —— 可以发布' : '闸门未过 —— 不能发布' }}
        </p>
        <p class="mt-1 leading-relaxed">{{ $evaluation['message'] }}</p>
    </div>

    {{-- 三层状态 --}}
    <div class="mb-4 box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">层</th>
                    <th class="px-4 py-3">是否参与闸门</th>
                    <th class="px-4 py-3">最新判定</th>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">说明</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($evaluation['layers'] as $state)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">{{ $state['label'] }}</td>
                        <td class="px-4 py-3">
                            @if ($state['required'])
                                <span class="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-800">必须通过</span>
                            @else
                                <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">不参与</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @php
                                $tone = match (true) {
                                    ! $state['available']  => 'bg-gray-100 text-gray-600',
                                    $state['result'] === 1 => 'bg-green-100 text-green-800',
                                    $state['result'] === 2 => 'bg-red-100 text-red-800',
                                    $state['result'] === 3 => 'bg-amber-100 text-amber-800',
                                    default                => 'bg-gray-100 text-gray-700',
                                };
                            @endphp
                            <span class="rounded px-2 py-0.5 text-xs {{ $tone }}">
                                {{ $state['available'] ? $state['result_label'] : '未接入' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                            {{ $state['audited_at'] ? $state['audited_at']->format('Y-m-d H:i') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                            @if (! $state['available'])
                                仿真器未接入，本层跑不起来 —— 因此也不纳入闸门（不会伪装成"通过"）。
                            @elseif ($state['reason_category'])
                                {{ \Webkul\Shop\Services\SkillAudit\RejectionReason::label($state['reason_category']) }}
                                @if ($state['reason_detail'])　{{ $state['reason_detail'] }} @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 触发机审 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-2 font-semibold text-gray-800 dark:text-white">① 跑 L0 机审</p>
            <p class="mb-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                机审对象是交付物里的 <b>.skill 包</b>，取它的中立清单做格式校验与内容关键字扫描。
                @if (! $hasPackage)
                    <br><span class="text-red-700">当前版本没有可读的 .skill 包文件，机审跑不了 —— 请先到「技能管理」上传。</span>
                @endif
            </p>
            <form method="POST" action="{{ route('admin.catalog.skill-reviews.layer0', $version->id) }}">
                @csrf
                <button type="submit"
                        class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700 disabled:opacity-50"
                        @disabled(! $hasPackage)>
                    跑 L0 机审
                </button>
            </form>
        </div>

        {{-- L2 人审 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-2 font-semibold text-gray-800 dark:text-white">② L2 人审判定</p>
            <p class="mb-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
                判定人会记成<b>你当前登录的管理员</b> —— 不是表单里填的名字（这是与 API 通道的关键差别）。
            </p>
            <form method="POST" action="{{ route('admin.catalog.skill-reviews.judge', $version->id) }}" class="space-y-3">
                @csrf

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">结论</label>
                    <select name="result" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="1">通过</option>
                        <option value="2">驳回</option>
                        <option value="3">要求补充材料</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">
                        理由类别（驳回 / 待补充时<b>必填</b>）
                    </label>
                    <select name="reason_category" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="">—— 不选 ——</option>
                        @foreach ($reasons as $code => $label)
                            <option value="{{ $code }}">{{ $label }}（{{ $code }}）</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">具体说明（会原样给开发者看）</label>
                    <textarea name="reason_detail" rows="2"
                              class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">整改建议</label>
                    <textarea name="suggestion" rows="2"
                              class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                </div>

                <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                    提交判定
                </button>
            </form>
        </div>
    </div>

    {{-- 发布 --}}
    <div class="mt-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">③ 发布</p>
        <p class="mb-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            发布是<b>闸门生效的地方</b>：未过闸门点发布会被拒绝，没有绕过入口。
            发布后该版本成为该商品唯一的 published 版本，下单时锁定它。
        </p>
        <div class="flex items-center gap-3">
            <form method="POST" action="{{ route('admin.catalog.skills.versions.publish', $version->id) }}">
                @csrf
                <button type="submit"
                        class="rounded px-4 py-2 text-sm text-white {{ $evaluation['ok'] ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-400' }}"
                        @disabled(! $evaluation['ok'] || $version->assets->isEmpty())>
                    发布 v{{ $version->version }}
                </button>
            </form>
            <a href="{{ route('admin.catalog.skills.edit', $version->product_id) }}">
                <span class="text-sm text-blue-600 hover:underline">去技能管理（传包 / 改安装指引）</span>
            </a>
        </div>
    </div>

    {{-- 审核流水 --}}
    <div class="mt-4 box-shadow rounded bg-white dark:bg-gray-900">
        <p class="border-b px-4 py-3 font-semibold text-gray-800 dark:border-gray-800 dark:text-white">
            审核流水（全部记录，最新在上）
        </p>
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">时间</th>
                    <th class="px-4 py-3">层</th>
                    <th class="px-4 py-3">判定</th>
                    <th class="px-4 py-3">审核人</th>
                    <th class="px-4 py-3">理由</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                            {{ ($record->audited_at ?? $record->submitted_at ?? $record->created_at)?->format('Y-m-d H:i') }}
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $gate->label($record->layer) }}</td>
                        <td class="px-4 py-3 text-xs">{{ $gate->resultLabel($record->result) }}</td>
                        <td class="px-4 py-3 text-xs">
                            {{-- ⚠ 两种措辞对应两类可信度不同的来源，不能都写成 `#id`：
                                 非空 = 控制台以**登录身份**写入的（可核验）；
                                 为空 = 外部机器通道提交的 —— 那条通道代表不了任何具体的人，
                                 所以它连"审核人"都不该声称。此前这里统一渲染成 `#<id>`，
                                 等于把请求体里随便填的数字显示成了"某位真人的签字"。 --}}
                            @if ($record->auditor_id)
                                <span title="由平台控制台以登录身份写入">平台审核员 #{{ $record->auditor_id }}</span>
                            @else
                                <span
                                    class="text-amber-600 dark:text-amber-400"
                                    title="该通道不代表具体的人，无法核验审核人身份"
                                >外部通道提交（无可核验的审核人）</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                            @if ($record->reason_category)
                                {{ \Webkul\Shop\Services\SkillAudit\RejectionReason::label($record->reason_category) }}<br>
                            @endif
                            {{ $record->reason_detail }}
                            @if ($record->suggestion)
                                <br><span class="text-gray-500">建议：{{ $record->suggestion }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500">还没有审核记录。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
