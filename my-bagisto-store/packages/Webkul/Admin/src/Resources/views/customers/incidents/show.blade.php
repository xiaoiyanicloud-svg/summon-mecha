<x-admin::layouts>
    <x-slot:title>事故 {{ $incident->incident_no }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                事故 {{ $incident->incident_no }}
                @if ($incident->requiresEscalation())
                    <span class="ml-2 rounded bg-red-600 px-2 py-0.5 text-sm text-white">需应急响应</span>
                @endif
            </p>
            <p class="mt-1 text-xs text-gray-500">
                报案人: {{ $customer?->email ?? '—' }}
                　技能: {{ $productName ?? ($incident->skill_id ? '商品 #'.$incident->skill_id : '未指明') }}
                　发生: {{ $incident->occurred_at?->format('Y-m-d H:i') ?? '—' }}
                　上报: {{ $incident->created_at?->format('Y-m-d H:i') }}
            </p>
            {{-- B-10:来源工单(本起事故由用户投诉升级而来时) --}}
            @if ($ticket)
                <p class="mt-1 text-xs">
                    来源工单:
                    <a href="{{ route('admin.customers.tickets.show', $ticket->id) }}" class="text-blue-600 hover:underline">
                        {{ $ticket->ticket_no }}
                    </a>
                </p>
            @endif
        </div>
        <a href="{{ route('admin.customers.incidents.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回事故列表</span>
        </a>
    </div>

    <x-admin::flash />

    @php
        $trace = (array) ($incident->trace ?? []);
        $missing = (array) ($trace['missing'] ?? []);
    @endphp

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            {{-- 案情 --}}
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-2 font-semibold text-gray-800 dark:text-white">案情描述（报案人填写）</p>
                <p class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100">{{ $incident->description }}</p>

                @if (! empty($incident->evidence))
                    <p class="mt-3 mb-1 text-xs text-gray-500">报案人提交的证据</p>
                    <ul class="list-disc pl-5 text-sm">
                        @foreach ((array) $incident->evidence as $item)
                            <li class="break-all">{{ is_scalar($item) ? $item : json_encode($item, JSON_UNESCAPED_UNICODE) }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- 证据链 —— 本页最重要的部分 --}}
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-1 font-semibold text-gray-800 dark:text-white">证据链快照</p>
                <p class="mb-3 text-xs text-gray-500">
                    冻结于 {{ $trace['captured_at'] ?? '—' }}（报案那一刻）。<b>只读</b> ——
                    能改的证据链不是证据链。
                </p>

                @if ($missing !== [])
                    <div class="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                        <p class="font-semibold">以下环节<b>没有查到</b>，不是"没问题"：</p>
                        <ul class="mt-1 list-disc pl-5">
                            @foreach ($missing as $m)
                                <li>{{ $m }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid gap-3 md:grid-cols-2">
                    @foreach ([
                        'skill_version' => '技能版本',
                        'deliverable'   => '交付物（sha256 是最硬的一条证据）',
                        'device'        => '设备',
                        'entitlement'   => '授权',
                        'install'       => '安装记录',
                    ] as $key => $label)
                        <div class="rounded border border-gray-200 p-3 dark:border-gray-700">
                            <p class="mb-2 text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $label }}</p>
                            @php $section = $trace[$key] ?? null; @endphp
                            @if (! $section)
                                <p class="text-xs text-red-700">未查到</p>
                            @else
                                <table class="w-full text-xs">
                                    <tbody>
                                        @foreach ($section as $k => $v)
                                            <tr>
                                                <td class="py-0.5 pr-2 align-top text-gray-500">{{ $k }}</td>
                                                <td class="py-0.5 break-all">
                                                    @if (is_array($v))
                                                        {{ json_encode($v, JSON_UNESCAPED_UNICODE) }}
                                                    @else
                                                        {{ $v ?? '—' }}
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- 处理日志 --}}
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-3 font-semibold text-gray-800 dark:text-white">处理记录（内部）</p>
                @forelse ($incident->handlingEntries as $entry)
                    <div class="mb-2 rounded border border-gray-200 px-3 py-2 text-xs dark:border-gray-700">
                        <div class="text-gray-500">
                            {{ optional($entry->created_at)->toDateTimeString() }}
                            @if ($entry->admin_id) · 管理员 #{{ $entry->admin_id }} @endif
                            @if ($entry->status) · {{ \Webkul\Shop\Models\Incident::STATUS_LABELS[$entry->status] ?? $entry->status }} @endif
                            @if ($entry->responsibility)
                                · 定责：{{ \Webkul\Shop\Models\Incident::RESPONSIBILITY_LABELS[$entry->responsibility] ?? $entry->responsibility }}
                            @endif
                        </div>
                        @if ($entry->note)
                            <div class="mt-1 whitespace-pre-wrap text-gray-800 dark:text-gray-100">{{ $entry->note }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500">还没有处理记录。</p>
                @endforelse
                <p class="mt-2 text-xs text-amber-700">
                    ⚠ 这段是<b>内部材料</b>，不会返回给报案人（用户侧接口只给状态与结论）。
                </p>
            </div>
        </div>

        {{-- 处置 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">处置</p>

            <div class="mb-3 text-xs text-gray-600 dark:text-gray-300">
                当前：<b>{{ $incident->statusLabel() }}</b>　{{ $incident->severityLabel() }}
                {{ $incident->responsibilityLabel() }}
            </div>

            <form method="POST" action="{{ route('admin.customers.incidents.handle', $incident->id) }}" class="space-y-3">
                @csrf

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">处理状态</label>
                    <select name="status" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        @foreach (\Webkul\Shop\Models\Incident::STATUS_LABELS as $value => $label)
                            {{-- A6 · 只列出当前状态与合法流转目标,不给出点了会被拒的选项 --}}
                            @continue($incident->status !== $value && ! $incident->canTransitionTo($value))
                            <option value="{{ $value }}" @selected($incident->status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">责任认定</label>
                    <select name="responsibility" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        @foreach (\Webkul\Shop\Models\Incident::RESPONSIBILITY_LABELS as $value => $label)
                            <option value="{{ $value }}" @selected($incident->responsibility === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-amber-700">
                        改责任方<b>必须</b>写说明 —— "尚未认定"是合法且常见的状态，不必急着填。
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">说明 / 结论（定责与结案时必填）</label>
                    <textarea name="note" rows="4"
                              class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                </div>

                <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                    保存处理进展
                </button>
            </form>
        </div>
    </div>
</x-admin::layouts>
