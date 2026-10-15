<x-admin::layouts>
    <x-slot:title>工单 {{ $ticket->ticket_no }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                工单 {{ $ticket->ticket_no }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
                用户: {{ $customer?->email ?? '—' }}　类别: {{ $ticket->category ?? '—' }}
                　开单: {{ $ticket->created_at?->format('Y-m-d H:i') }}
                　SLA: {{ $ticket->sla_due_at?->format('Y-m-d H:i') ?? '—' }}
                　受理人: {{ $ticket->assignee_id ? '#'.$ticket->assignee_id : '未指派' }}
            </p>
        </div>
        <a href="{{ route('admin.customers.tickets.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回工单列表</span>
        </a>
    </div>

    <x-admin::flash />

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- 消息流 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900 lg:col-span-2">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">对话</p>

            <div class="space-y-3">
                @forelse ($ticket->messages as $message)
                    @php
                        $isAdmin = $message->actor_type === 'admin';
                    @endphp
                    <div class="rounded-lg border px-3 py-2 {{ $isAdmin
                        ? 'border-blue-200 bg-blue-50'
                        : 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800' }}">
                        <div class="mb-1 flex items-center justify-between text-xs text-gray-500">
                            <span>
                                {{ $isAdmin ? '平台' : '用户' }}
                                @if ($message->actor_id) #{{ $message->actor_id }} @endif
                            </span>
                            <span>{{ $message->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100">{{ $message->content }}</div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">还没有消息。</p>
                @endforelse
            </div>

            <form method="POST" action="{{ route('admin.customers.tickets.reply', $ticket->id) }}" class="mt-4">
                @csrf
                <textarea name="content" rows="3" required
                          placeholder="回复用户（会显示在用户的工单详情里）"
                          class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                <button type="submit" class="mt-2 rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">
                    回复
                </button>
                <p class="mt-1 text-xs text-gray-500">
                    首次回复会自动把状态推到「处理中」，并在无人认领时把你记为受理人。
                </p>
            </form>
        </div>

        {{-- 处置 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">处置</p>

            <form method="POST" action="{{ route('admin.customers.tickets.status', $ticket->id) }}" class="space-y-3">
                @csrf

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">状态</label>
                    <select name="status" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="open" @selected($ticket->status === 'open')>待受理</option>
                        <option value="processing" @selected($ticket->status === 'processing')>处理中</option>
                        <option value="resolved" @selected($ticket->status === 'resolved')>已解决</option>
                        <option value="closed" @selected($ticket->status === 'closed')>已关闭</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">优先级</label>
                    <select name="priority" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="low" @selected($ticket->priority === 'low')>低</option>
                        <option value="normal" @selected($ticket->priority === 'normal')>普通</option>
                        <option value="high" @selected($ticket->priority === 'high')>高</option>
                        <option value="urgent" @selected($ticket->priority === 'urgent')>紧急</option>
                    </select>
                </div>

                <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                    保存
                </button>
            </form>

            <p class="mt-3 text-xs leading-relaxed text-amber-700">
                ⚠ 直接改成「已解决」而不回复，用户只会看到状态变了、没有任何说明。
                结单前请先在左边回一句。
            </p>

            {{-- B-10:工单 ↔ 事故互链 --}}
            <div class="mt-4 border-t pt-4 dark:border-gray-800">
                <p class="mb-2 font-semibold text-gray-800 dark:text-white">事故定性</p>

                @if ($incident)
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        <p>本工单已定性为事故：<b>{{ $incident->incident_no }}</b>
                            （{{ $incident->severityLabel() }} · {{ $incident->statusLabel() }}）</p>
                        <a href="{{ route('admin.customers.incidents.show', $incident->id) }}"
                           class="mt-1 inline-block text-blue-600 hover:underline">查看事故记录 →</a>
                    </div>
                @elseif (! $ticket->customer_id)
                    <p class="text-xs text-gray-500">该工单没有关联客户，无法定性为事故。</p>
                @else
                    <form method="POST" action="{{ route('admin.customers.tickets.escalate', $ticket->id) }}" class="space-y-2">
                        @csrf
                        <div>
                            <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">严重程度</label>
                            <select name="severity" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                                @foreach (\Webkul\Shop\Models\Incident::SEVERITY_LABELS as $val => $label)
                                    <option value="{{ $val }}" @selected($val === 'minor')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">事故描述（必填）</label>
                            <textarea name="description" rows="3" required
                                      class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"
                                      placeholder="定性依据 / 经过"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">发生时间（可选，默认现在）</label>
                            <input type="datetime-local" name="occurred_at"
                                   class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        </div>
                        <button type="submit" class="rounded bg-amber-600 px-4 py-2 text-sm text-white hover:bg-amber-700">
                            定性为事故
                        </button>
                        <p class="text-xs text-gray-500">
                            会走与用户报案同一个事故服务（证据链在定性这一刻固化），并在本工单留一条系统留言。
                        </p>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-admin::layouts>
