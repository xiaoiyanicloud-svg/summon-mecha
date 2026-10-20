<x-admin::layouts>
    <x-slot:title>申诉 · {{ $track->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">申诉 · {{ $track->name }}</p>
            <p class="text-xs text-gray-500">
                {{ $track->edition?->event?->name }} / {{ $track->edition?->name }} · 规则 v{{ $track->rules_version_id }}
            </p>
        </div>
        <a href="{{ route('admin.catalog.ecosystem.competition.index') }}" class="transparent-button">返回赛务</a>
    </div>

    <x-admin::flash />

    @if ($appeals->isEmpty())
        <div class="rounded bg-white p-4 text-sm text-gray-500 shadow-sm dark:bg-gray-900">还没有申诉。</div>
    @else
        <div class="space-y-4">
            @foreach ($appeals as $appeal)
                <div class="rounded bg-white p-4 text-xs shadow-sm dark:bg-gray-900">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $appeal->appeal_no }} · {{ $appeal->entry?->team?->name }}</p>
                            <p class="text-gray-500">
                                状态 {{ $appeal->status }} · 提交客户 #{{ $appeal->submitted_by_customer_id }}
                                · 截止 {{ $appeal->deadline_at?->toIso8601String() }}
                                · 绑定结果版本 #{{ $appeal->result_version_id }}
                                · 当前公布版本 #{{ $appeal->result?->published_version_id }}
                            </p>
                        </div>
                        <span class="rounded bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $appeal->status }}</span>
                    </div>

                    <p class="mb-2 whitespace-pre-line">{{ $appeal->reason }}</p>

                    @if ($appeal->decision_result)
                        <p class="mb-2 text-gray-600">
                            裁决：{{ $appeal->decision_result }} · 适用版本 #{{ $appeal->decision_result_version_id }}
                            · {{ $appeal->decision_reason }}
                        </p>
                    @endif

                    @if ($appeal->attachments->isNotEmpty())
                        <p class="mb-2">证据：
                            @foreach ($appeal->attachments as $attachment)
                                <a class="mr-3 text-blue-600" href="{{ route('admin.catalog.ecosystem.competition.appeal_attachments.download', $attachment->id) }}">
                                    {{ $attachment->original_name }} ({{ $attachment->mime }})
                                </a>
                            @endforeach
                        </p>
                    @endif

                    @if ($appeal->events->isNotEmpty())
                        <details class="mb-2">
                            <summary class="cursor-pointer text-gray-500">时间线（{{ $appeal->events->count() }}）</summary>
                            <ul class="mt-1 space-y-1 text-gray-500">
                                @foreach ($appeal->events->sortBy('id') as $event)
                                    <li>{{ $event->created_at?->toIso8601String() }} · {{ $event->event_type }} · {{ $event->actor_type }} #{{ $event->actor_id }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    @if ($appeal->status === 'submitted')
                        <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.appeals.accept', $appeal->id) }}" class="mb-2 flex flex-wrap gap-2">
                            @csrf
                            <input type="hidden" name="expected_row_version" value="{{ $appeal->row_version }}" />
                            <input name="note" class="rounded border px-2 py-1" placeholder="受理说明（可选）" />
                            <button class="primary-button text-xs" type="submit">受理</button>
                        </form>
                    @endif

                    @if (in_array($appeal->status, ['accepted', 'investigating'], true))
                        <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.appeals.decide', $appeal->id) }}" class="grid gap-2 sm:grid-cols-4">
                            @csrf
                            <input type="hidden" name="expected_row_version" value="{{ $appeal->row_version }}" />
                            <input type="hidden" name="decision_result_version_id" value="{{ $appeal->result?->published_version_id }}" />
                            <select name="decision_result" class="rounded border px-2 py-1">
                                <option value="upheld">申诉成立</option>
                                <option value="partially_upheld">部分成立</option>
                                <option value="rejected">申诉不成立</option>
                                <option value="inadmissible">不予受理</option>
                            </select>
                            <input name="decision_reason" class="rounded border px-2 py-1 sm:col-span-2" placeholder="裁决理由（必填，4~4000 字）" />
                            <button class="primary-button text-xs" type="submit">裁决</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-admin::layouts>
