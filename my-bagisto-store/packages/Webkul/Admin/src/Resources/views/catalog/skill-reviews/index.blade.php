<x-admin::layouts>
    <x-slot:title>审核台</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">审核台</p>
    </div>

    <x-admin::flash />

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">这里决定一个技能能不能上架</p>
        <p class="mt-1 leading-relaxed">
            队列里是<b>已经提审</b>的技能版本 —— 既包含<b>还没过闸门</b>的，也包含
            <b>已过闸门、等待平台上架</b>的（后者等的是运营点发布）。发布闸门要求下列层全部通过才允许发布
            （在「技能管理」页点发布时生效）：
        </p>
        <ul class="mt-1 list-disc pl-5 leading-relaxed">
            @foreach ($gate->allLayers() as $layer)
                <li>
                    {{ $gate->label($layer) }} ——
                    @if ($gate->isRequired($layer))
                        <b>必须通过</b>
                    @else
                        不参与闸门
                    @endif
                    @unless ($gate->isAvailable($layer))
                        <span class="text-amber-700">（该层尚未接入，因此无法审核，也未纳入闸门）</span>
                    @endunless
                </li>
            @endforeach
        </ul>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 「未接入」不等于「已通过」—— 一个没跑起来的层不会被当成合格。
            仿真器接入后把 L1 加回闸门即可（改 <code>SKILL_AUDIT_REQUIRED_LAYERS</code>）。
        </p>
    </div>

    <div class="mb-3 flex flex-wrap gap-2 text-sm">
        @php
            $tabs = [
                'pending'  => ['待审', $counts['pending']],
                'rejected' => ['已驳回', $counts['rejected']],
                'approved' => ['已过闸门', $counts['approved']],
                'all'      => ['全部', $counts['all']],
            ];
        @endphp
        @foreach ($tabs as $key => [$label, $count])
            @php
                $active = $filter === $key;
                // 「已过闸门」有货时醒目提示（任务卡 S-3a）：这些版本已具备上架条件，
                // 等的是运营点发布 —— 别让它沉在页签里被漏掉。**只加样式**，不改默认页签、不改文案。
            @endphp
            <a href="{{ route('admin.catalog.skill-reviews.index', ['status' => $key]) }}"
               class="rounded-full border px-3 py-1 {{ $active
                   ? 'border-blue-500 bg-blue-500 text-white'
                   : ($key === 'approved' && $count > 0
                       ? 'border-amber-400 bg-amber-50 font-semibold text-amber-800 ring-2 ring-amber-300 dark:border-amber-500 dark:bg-amber-900/30 dark:text-amber-100 dark:ring-amber-700'
                       : 'border-gray-300 bg-white text-gray-700 hover:border-blue-400 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200') }}">
                {{ $label }} <span class="opacity-75">({{ $count }})</span>
            </a>
        @endforeach
    </div>

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">技能 / 版本</th>
                    <th class="px-4 py-3">各层状态</th>
                    <th class="px-4 py-3">提审时间</th>
                    <th class="px-4 py-3">卡在哪</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $v = $row['version']; @endphp
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-800 dark:text-white">
                                {{ $v->product?->name ?? '（商品 #'.$v->product_id.'）' }}
                            </div>
                            <div class="text-xs text-gray-500">
                                v{{ $v->version }}　商品 ID: {{ $v->product_id }}　版本 ID: {{ $v->id }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($row['gate']['layers'] as $state)
                                    @php
                                        $tone = match (true) {
                                            ! $state['available']             => 'bg-gray-100 text-gray-600',
                                            $state['result'] === 1            => 'bg-green-100 text-green-800',
                                            $state['result'] === 2            => 'bg-red-100 text-red-800',
                                            $state['result'] === 3            => 'bg-amber-100 text-amber-800',
                                            default                           => 'bg-gray-100 text-gray-700',
                                        };
                                    @endphp
                                    <span class="rounded px-2 py-0.5 text-xs {{ $tone }}">
                                        {{ $state['label'] }}:
                                        {{ $state['available'] ? $state['result_label'] : '未接入' }}
                                    </span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-300">
                            {{ $row['submitted_at'] ? \Illuminate\Support\Carbon::parse($row['submitted_at'])->format('Y-m-d H:i') : '—' }}
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @if ($row['gate']['ok'])
                                <span class="text-green-700">闸门已过，可发布</span>
                                {{-- 开发者已申请上架（任务卡 S-3a）—— 交接信号，运营据此排发布 --}}
                                @if ($row['listing_requested_at'] ?? null)
                                    <div class="mt-1 font-semibold text-amber-700">
                                        开发者已申请上架（{{ $row['listing_requested_at'] }}）
                                    </div>
                                @endif
                            @else
                                <span class="text-red-700">
                                    {{ implode('；', array_map(fn ($u) => $u['label'].'（'.$u['reason'].'）', $row['gate']['unmet'])) }}
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.catalog.skill-reviews.show', $v->id) }}">
                                <span class="cursor-pointer text-blue-600 hover:underline">去审核</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-gray-500">
                            这个筛选下没有记录。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
