    @php
        $statusTones = [
            'open'       => 'support-tone-open',
            'processing' => 'support-tone-processing',
            'resolved'   => 'support-tone-resolved',
            'closed'     => 'support-tone-closed',
        ];
        $statusTone = $statusTones[$ticket->status] ?? $statusTones['closed'];

        $firstResponseLabel = $stats['first_response'] === null
            ? '—'
            : ($stats['first_response'] < 60
                ? $stats['first_response'].' 秒'
                : round($stats['first_response'] / 60).' 分钟');

        $ratingTagsLabel = implode(' / ', array_map(
            fn ($key) => \Webkul\Shop\Models\SupportTicket::RATING_TAG_LABELS[$key] ?? $key,
            $ticket->rating_tags ?? []
        ));

        $supportWorkspaceConfig = [
            'updatesUrl'           => route('admin.customers.tickets.updates', $ticket->id),
            'replyUrl'             => route('admin.customers.tickets.reply', $ticket->id),
            'readUrl'              => route('admin.customers.tickets.read', $ticket->id),
            'presenceUrl'          => route('admin.customers.support.presence'),
            'conversationsUrl'     => route('admin.customers.support.conversations'),
            'statusUrl'            => route('admin.customers.tickets.status', $ticket->id),
            'claimUrl'             => route('admin.customers.tickets.claim', $ticket->id),
            'releaseUrl'           => route('admin.customers.tickets.release', $ticket->id),
            'transferUrl'          => route('admin.customers.tickets.transfer', $ticket->id),
            'holdUrl'              => route('admin.customers.tickets.hold', $ticket->id),
            'resumeUrl'            => route('admin.customers.tickets.resume', $ticket->id),
            'noteUrl'              => route('admin.customers.tickets.notes.store', $ticket->id),
            'typingUrl'            => route('admin.customers.tickets.typing', $ticket->id),
            'quickReplyEventUrl'   => route('admin.customers.support.quick-replies.event'),
            'indexUrl'             => route('admin.customers.tickets.index'),
            'csrf'                 => csrf_token(),
            'ticketId'             => $ticket->id,
            'ticketNo'             => $ticket->ticket_no,
            'serviceMode'          => $ticket->service_mode,
            'status'               => $ticket->status,
            'assigneeId'           => $ticket->assignee_id,
            'held'                 => $ticket->isHeld(),
            'currentAdminId'       => $currentAdminId,
            'currentAdminName'     => $currentAdminName,
            'initialOnline'        => (bool) ($agentState?->isFresh() ?? false),
            'initialLastMessageId' => (int) ($ticket->messages->max('id') ?? 0),
            'statusTones'          => $statusTones,
            'titleBase'            => '客服工作台',
        ];
    @endphp

    <div id="support-workspace" data-support-config='@json($supportWorkspaceConfig)' class="mx-auto max-w-[1680px]">
        <x-admin::flash />

        {{-- 今日口径：先看数，再看会话。没有这排数字，工作台只能说“我在忙”。 --}}
        <div class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-xs text-gray-500 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <span>今日新会话 <b id="stat-new" class="font-semibold text-gray-800 dark:text-gray-200">{{ $stats['new_today'] }}</b></span>
            <span>已结单 <b id="stat-closed" class="font-semibold text-gray-800 dark:text-gray-200">{{ $stats['closed_today'] }}</b></span>
            <span>待回复 <b id="stat-waiting" class="font-semibold {{ $stats['waiting_reply'] > 0 ? 'support-warn-text' : 'text-gray-800 dark:text-gray-200' }}">{{ $stats['waiting_reply'] }}</b></span>
            <span>超时 <b id="stat-overdue" class="font-semibold {{ $stats['overdue'] > 0 ? 'support-danger-text' : 'text-gray-800 dark:text-gray-200' }}">{{ $stats['overdue'] }}</b></span>
            <span>挂起 <b id="stat-held" class="font-semibold text-gray-800 dark:text-gray-200">{{ $stats['held'] ?? 0 }}</b></span>
            <span>平均首响 <b id="stat-first" class="font-semibold text-gray-800 dark:text-gray-200">{{ $firstResponseLabel }}</b></span>
            <span>满意度 <b id="stat-rating" class="font-semibold support-warn-text">{{ $stats['rating_avg'] !== null ? $stats['rating_avg'] : '—' }}</b>@if ($stats['rating_count'] > 0)<span class="text-gray-400">（<span id="stat-rating-count">{{ $stats['rating_count'] }}</span> 条评价）</span>@endif</span>
            <span class="ml-auto text-gray-400">今日口径</span>
        </div>

        <div class="support-console-grid">
            {{-- 左侧：在线状态 + 会话队列 --}}
            <aside class="support-console-left flex min-h-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <div class="flex items-center justify-between gap-2">
                        <div class="min-w-0">
                            <a href="{{ route('admin.customers.tickets.index') }}" class="text-xs text-gray-500 transition hover:text-gray-900 dark:hover:text-white">← 返回工单</a>
                            <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-white">客服工作台</p>
                        </div>
                        <button type="button" id="presence-toggle" class="support-presence shrink-0 {{ $agentState?->isFresh() ? 'support-presence-online' : 'support-presence-offline' }}">
                            <span id="presence-dot" class="support-dot {{ $agentState?->isFresh() ? 'support-dot-online' : 'support-dot-offline' }}"></span>
                            <span id="presence-copy">{{ $agentState?->isFresh() ? '在线接待' : '上线接待' }}</span>
                        </button>
                    </div>
                    <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                        <span class="inline-flex items-center gap-1"><span class="support-dot support-dot-sky"></span><span id="online-agent-count">{{ $onlineAgents }} 位在线</span></span>
                        <span class="ml-auto text-gray-400" id="list-sync-state">实时刷新</span>
                        <button type="button" id="sound-toggle" class="support-toggle">提示音 开</button>
                        <button type="button" id="notify-toggle" class="support-toggle">桌面通知</button>
                    </div>

                    {{-- 筛选/搜索是静态的：下面每 3 秒只替换客户行，不能把客服正在输入的搜索词冲掉 --}}
                    <div class="mt-2">
                        <input id="inbox-search" type="search" placeholder="搜索客户 / 邮箱 / 工单号" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-800">
                        <div class="support-filter-row mt-1.5">
                            <button type="button" data-inbox-filter="all" class="support-filter support-filter-active">全部 <b id="inbox-total-count">{{ $inbox['counts']['total'] }}</b></button>
                            <button type="button" data-inbox-filter="mine" class="support-filter">我负责 <b id="mine-count">{{ $inbox['counts']['mine'] }}</b></button>
                            <button type="button" data-inbox-filter="waiting" class="support-filter">待回复 <b id="waiting-count">{{ $inbox['counts']['waiting'] }}</b></button>
                            <button type="button" data-inbox-filter="unread" class="support-filter">未读 <b id="unread-count">{{ $inbox['counts']['unread'] }}</b></button>
                        </div>
                        <div class="support-filter-row mt-1">
                            <button type="button" data-inbox-filter="queue" class="support-filter">排队 <b id="queue-count">{{ $inbox['counts']['queue'] }}</b></button>
                            <button type="button" data-inbox-filter="async" class="support-filter">留言 <b id="async-count">{{ $inbox['counts']['async'] }}</b></button>
                            <button type="button" data-inbox-filter="held" class="support-filter">挂起 <b id="held-count">{{ $inbox['counts']['held'] }}</b></button>
                        </div>
                    </div>
                </div>

                <div id="conversation-list" class="journal-scroll min-h-0 flex-1 overflow-y-auto">
                    @include('admin::customers.tickets.partials.conversation-list', [
                        'inbox'    => $inbox,
                        'activeId' => $ticket->id,
                    ])
                </div>
                <p id="inbox-filter-empty" class="hidden px-4 py-4 text-center text-xs text-gray-400">没有符合条件的客户</p>
            </aside>

            {{-- 中间：当前会话 --}}
            <section class="support-console-center flex min-h-0 flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-base font-semibold text-gray-900 dark:text-white">{{ $customerName }}</p>
                            <span id="chat-status-badge" data-status="{{ $ticket->status }}" class="support-tone {{ $statusTone }}">{{ $ticket->agentStatusLabel() }}</span>
                            <span id="chat-held-badge" class="support-tone support-tone-held {{ $ticket->isHeld() ? '' : 'hidden' }}">挂起</span>
                            <span class="support-tone {{ $ticket->service_mode === 'online' ? 'support-tone-online' : 'support-tone-async' }}">{{ $ticket->service_mode === 'online' ? '在线会话' : '留言工单' }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                            <span class="font-mono">{{ $ticket->ticket_no }}</span>
                            <span>{{ $ticket->categoryLabel() }}</span>
                            <span>受理人 <b id="assignee-name" class="font-medium text-gray-700 dark:text-gray-200">{{ $assigneeName ?: '未指派' }}</b></span>
                            <span>SLA {{ $ticket->sla_due_at?->format('m-d H:i') ?? '—' }}</span>
                            @if ($ticket->isOverdue())
                                <span class="font-medium support-danger-text">已超时</span>
                            @endif
                            @if ($ticket->rating)
                                <span class="font-medium support-warn-text" @if ($ratingTagsLabel !== '') title="{{ $ratingTagsLabel }}" @endif>评价 {{ $ticket->rating }}★</span>
                            @endif
                        </div>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <a href="{{ route('admin.customers.support.workbench', ['exclude' => $ticket->id]) }}" class="support-btn support-btn-ghost" title="按紧急度跳到下一条待处理会话">下一条待处理 →</a>
                        <span id="chat-sync-state" class="inline-flex items-center gap-1"><span class="support-dot support-dot-online"></span>实时同步</span>
                        <span id="customer-typing" class="hidden text-xs text-blue-600">用户正在输入…</span>
                    </div>
                </div>

                <div class="relative min-h-0 flex-1">
                    <div id="message-stream" data-last-message-id="{{ $ticket->messages->max('id') ?? 0 }}" class="journal-scroll h-full overflow-y-auto bg-[radial-gradient(circle_at_top_right,_rgba(59,130,246,0.06),_transparent_32%)] px-4 py-4 sm:px-6">
                        <div class="mb-5 flex flex-col items-center text-center">
                            <div class="support-brand-mark flex h-10 w-10 items-center justify-center rounded-2xl text-xs font-semibold shadow-sm">S</div>
                            <p class="mt-2 text-xs font-semibold text-gray-700 dark:text-gray-200">{{ $customerName }} · 服务会话</p>
                            <p class="mt-0.5 text-xs text-gray-400">工单创建于 {{ $ticket->created_at?->format('Y-m-d H:i') }}</p>
                        </div>

                        <div id="message-list" class="space-y-4">
                            @forelse ($ticket->messages as $message)
                                @php
                                    $isAdmin = $message->actor_type === 'admin';
                                    $isSystem = $message->actor_type === 'system';
                                    $isDeveloper = $message->actor_type === 'developer';
                                    $isNote = $message->actor_type === 'note';
                                    $actorLabel = $isNote
                                        ? '内部备注 · 仅客服可见'
                                        : ($isAdmin
                                            ? ((int) $message->actor_id === (int) $currentAdminId ? $currentAdminName : '平台客服')
                                            : ($isDeveloper ? '技术团队' : ($isSystem ? '系统' : '用户')));
                                    $actorMark = $isAdmin ? '服' : ($isDeveloper ? '技' : '客');
                                @endphp

                                @if ($isSystem)
                                    <div class="flex justify-center" data-message-id="{{ $message->id }}" data-actor-type="system">
                                        <div class="max-w-xl rounded-full bg-gray-100 px-4 py-2 text-center text-xs leading-relaxed text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                            {{ $message->content }}
                                        </div>
                                    </div>
                                @elseif ($isNote)
                                    <div class="flex justify-center" data-message-id="{{ $message->id }}" data-actor-type="note">
                                        <div class="support-note-bubble">
                                            <span class="support-note-kicker">内部备注 · 仅客服可见</span>
                                            <span class="support-note-body">{{ $message->content }}</span>
                                            <span class="support-note-time">{{ ($message->created_at ?? $ticket->created_at)?->format('m-d H:i') }}</span>
                                        </div>
                                    </div>
                                @else
                                    <div class="flex gap-3 {{ $isAdmin ? 'flex-row-reverse' : '' }}" data-message-id="{{ $message->id }}" data-actor-type="{{ $message->actor_type }}">
                                        <div class="support-avatar mt-1 h-8 w-8 flex-none {{ $isAdmin ? 'support-avatar-admin' : ($isDeveloper ? 'support-avatar-dev' : 'support-avatar-customer') }}">
                                            {{ $actorMark }}
                                        </div>
                                        <div class="flex max-w-[78%] flex-col {{ $isAdmin ? 'items-end text-right' : 'items-start' }}">
                                            <div class="mb-1 flex items-center gap-2 text-[10px] text-gray-400 {{ $isAdmin ? 'flex-row-reverse' : '' }}">
                                                <span>{{ $actorLabel }}</span>
                                                <span>{{ ($message->created_at ?? $ticket->created_at)?->format('m-d H:i') }}</span>
                                                @if ($isAdmin)
                                                    <span class="support-receipt {{ $message->read_at ? 'support-receipt-read' : '' }}" data-receipt-for="{{ $message->id }}">{{ $message->read_at ? '已读' : '未读' }}</span>
                                                @endif
                                            </div>
                                            <div class="support-bubble {{ $isAdmin ? 'support-bubble-admin' : 'support-bubble-customer' }}">{{ $message->content }}</div>
                                            @if ($message->attachments)
                                                <div class="mt-2 flex w-full flex-col gap-2 {{ $isAdmin ? 'items-end' : 'items-start' }}">
                                                    @foreach ($message->attachments as $attachment)
                                                        @php
                                                            $isProductAttachment = is_array($attachment) && (($attachment['type'] ?? null) === 'product');
                                                            $productAdminUrl = $isProductAttachment
                                                                && ! empty($attachment['product_id'])
                                                                && bouncer()->hasPermission('catalog.products.edit')
                                                                    ? route('admin.catalog.products.edit', (int) $attachment['product_id'])
                                                                    : null;
                                                        @endphp
                                                        @if ($isProductAttachment)
                                                            <a
                                                                class="support-product-attachment"
                                                                @if ($productAdminUrl) href="{{ $productAdminUrl }}" target="_blank" rel="noopener noreferrer" @endif
                                                            >
                                                                <span class="support-product-thumb">
                                                                    @if (! empty($attachment['image']))
                                                                        <img src="{{ $attachment['image'] }}" alt="" loading="lazy">
                                                                    @else
                                                                        <span>商</span>
                                                                    @endif
                                                                </span>
                                                                <span class="support-product-copy">
                                                                    <span class="support-product-kicker">商品咨询</span>
                                                                    <strong class="support-product-name">{{ $attachment['name'] ?? '商品' }}</strong>
                                                                    <span class="support-product-meta">
                                                                        @if (! empty($attachment['sku']))<span>{{ $attachment['sku'] }}</span>@endif
                                                                        @if (! empty($attachment['price']))<span>¥{{ $attachment['price'] }}</span>@endif
                                                                        @if ($productAdminUrl)<span class="support-product-link">查看商品 →</span>@endif
                                                                    </span>
                                                                </span>
                                                            </a>
                                                        @else
                                                            <span class="max-w-xs truncate rounded-lg bg-gray-50 px-2.5 py-1.5 text-xs text-blue-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-800 dark:ring-gray-700">{{ $attachment }}</span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @empty
                                <div class="rounded-xl border border-dashed border-gray-200 px-6 py-10 text-center text-xs text-gray-500 dark:border-gray-700">还没有消息。直接在下方向客户打招呼。</div>
                            @endforelse
                        </div>
                    </div>

                    <button type="button" id="new-message-tip" class="support-dark-pill absolute bottom-4 left-1/2 hidden -translate-x-1/2 rounded-full px-4 py-2 text-xs font-medium shadow-lg transition">↓ 有新消息</button>
                </div>

                <div class="border-t border-gray-100 bg-gray-50/70 px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                    <div class="mb-2 flex flex-wrap items-center gap-1.5">
                        <span class="mr-1 text-xs text-gray-500">快捷回复</span>
                        @foreach ($quickReplies as $group)
                            @if (count($quickReplies) > 1)
                                <span class="text-[10px] text-gray-400">{{ $group['label'] }}</span>
                            @endif
                            @foreach ($group['items'] as $item)
                                <button type="button" data-reply-key="{{ $item['key'] }}" data-reply="{{ $item['content'] }}" class="support-chip px-2.5 py-1 text-xs">{{ $item['label'] }}</button>
                            @endforeach
                        @endforeach
                        @if (! empty($quickReplyStats))
                            <button type="button" id="quick-stats-toggle" class="ml-auto text-[10px] text-gray-400 hover:text-gray-600">近 7 天话术效果</button>
                        @endif
                    </div>

                    <div id="quick-stats-panel" class="mb-2 hidden rounded-lg border border-gray-200 bg-white px-3 py-2 text-[10px] text-gray-500 dark:border-gray-700 dark:bg-gray-800">
                        @foreach ($quickReplyStats as $stat)
                            <div class="flex items-center justify-between gap-3 py-0.5">
                                <span class="truncate">{{ $stat['group'] }} · {{ $stat['label'] }}</span>
                                <span class="shrink-0">插入 {{ $stat['inserts'] }} · 发出 {{ $stat['sends'] }} · 采纳 {{ $stat['adoption'] === null ? '—' : $stat['adoption'].'%' }}</span>
                            </div>
                        @endforeach
                    </div>

                    <div class="mb-2 flex items-center gap-2">
                        <button type="button" data-composer-mode="reply" class="support-mode support-mode-active">回复用户</button>
                        <button type="button" data-composer-mode="note" class="support-mode">内部备注</button>
                        <span id="composer-mode-hint" class="text-[10px] text-gray-400">回复会实时进入用户会话</span>
                    </div>

                    <form id="reply-form" method="POST" action="{{ route('admin.customers.tickets.reply', $ticket->id) }}">
                        @csrf
                        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition focus-within:border-blue-400 focus-within:ring-4 focus-within:ring-blue-50 dark:border-gray-700 dark:bg-gray-800">
                            <textarea id="ticket-reply" name="content" rows="2" required placeholder="输入回复，Enter 发送…" class="max-h-40 w-full resize-none border-0 bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-0 dark:text-white">{{ old('content') }}</textarea>
                            <div class="flex items-center justify-between border-t border-gray-100 px-4 py-2 dark:border-gray-700">
                                <p class="text-[10px] text-gray-400">Enter 发送 · Shift + Enter 换行 · 回复会实时进入用户会话</p>
                                <button type="submit" class="support-btn support-btn-primary">
                                    发送 <span style="color:#93c5fd">↗</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>

            {{-- 右侧：客户与处置 --}}
            <aside class="support-console-right journal-scroll min-h-0 space-y-3 overflow-y-auto">
                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-start justify-between">
                        <div class="min-w-0">
                            <p class="text-[10px] font-medium uppercase tracking-[0.14em] text-gray-400">客户信息</p>
                            <p class="mt-1.5 truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $customerName }}</p>
                            <p class="mt-0.5 break-all text-xs text-gray-500">{{ $customer?->email ?? '—' }}</p>
                        </div>
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gray-100 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">客</div>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
                            <dt class="text-gray-400">客户 ID</dt>
                            <dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-200">#{{ $ticket->customer_id ?? '—' }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
                            <dt class="text-gray-400">优先级</dt>
                            <dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-200">{{ $ticket->priorityLabel() }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
                            <dt class="text-gray-400">开单时间</dt>
                            <dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-200">{{ $ticket->created_at?->format('m-d H:i') }}</dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-2.5 dark:bg-gray-800">
                            <dt class="text-gray-400">SLA 期限</dt>
                            <dd class="mt-0.5 font-medium {{ $ticket->isOverdue() ? 'text-rose-600' : 'text-gray-700 dark:text-gray-200' }}">{{ $ticket->sla_due_at?->format('m-d H:i') ?? '—' }}</dd>
                        </div>
                    </dl>

                    @if ($ticket->rating)
                        <div class="support-rating-card mt-2 text-xs">
                            <div class="flex items-center justify-between">
                                <span class="support-rating-title">满意度评价</span>
                                <span class="support-rating-title">{{ str_repeat('★', $ticket->rating) }}<span class="support-rating-tags">{{ str_repeat('☆', 5 - $ticket->rating) }}</span></span>
                            </div>
                            @if ($ratingTagsLabel !== '')
                                <p class="support-rating-tags mt-1">{{ $ratingTagsLabel }}</p>
                            @endif
                            @if ($ticket->rating_comment)
                                <p class="mt-1 leading-relaxed text-gray-600 dark:text-gray-300">{{ $ticket->rating_comment }}</p>
                            @endif
                        </div>
                    @endif
                </section>

                {{-- 客户历史：一个客户过去问过什么，不再靠左栏多行去猜 --}}
                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">客户历史</p>
                        <span class="text-[10px] text-gray-400">{{ $historyTickets->count() }} 条历史会话</span>
                    </div>

                    @forelse ($historyTickets as $history)
                        <a href="{{ route('admin.customers.tickets.show', $history->id) }}" data-workspace-link class="mt-2 block rounded-lg border border-gray-100 px-3 py-2 transition hover:border-blue-200 hover:bg-blue-50/40 dark:border-gray-800 dark:hover:bg-gray-800">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-xs font-medium text-gray-700 dark:text-gray-200">{{ $history->categoryLabel() }}</span>
                                <span class="shrink-0 text-[10px] text-gray-400">{{ $history->updated_at?->format('m-d H:i') }}</span>
                            </div>
                            <div class="mt-1 flex items-center justify-between gap-2 text-[10px] text-gray-500">
                                <span class="truncate font-mono">{{ $history->ticket_no }}</span>
                                <span class="shrink-0">{{ $history->agentStatusLabel() }}{{ $history->rating ? ' · ★'.$history->rating : '' }}</span>
                            </div>
                        </a>
                    @empty
                        <p class="mt-3 text-xs text-gray-500">没有其他历史会话。</p>
                    @endforelse
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">在线接待</p>
                        <span class="text-[10px] text-gray-400">实时更新</span>
                    </div>

                    @if ($ticket->service_mode === 'online')
                        @if (! $ticket->assignee_id && $ticket->status === 'open')
                            <p class="mt-3 text-xs leading-relaxed text-gray-500">当前会话在队列中。上线后可接入，接入后其他客服不会再抢到这张会话。</p>
                            <button type="button" id="claim-button" data-url="{{ route('admin.customers.tickets.claim', $ticket->id) }}" class="support-btn support-btn-success mt-3 w-full" {{ $agentState?->isFresh() ? '' : 'disabled' }}>接入在线会话</button>
                            <p class="mt-2 text-[10px] text-gray-400" id="claim-hint">{{ $agentState?->isFresh() ? '接入后即可直接回复。' : '请先在左上角上线接待。' }}</p>
                        @elseif ($ticket->assignee_id === $currentAdminId && $ticket->status === 'processing')
                            <div class="support-note support-note-success mt-3">你正在接待此会话。关闭页面后，在线状态将在 60 秒内自动过期。</div>
                            <button type="button" id="release-button" data-url="{{ route('admin.customers.tickets.release', $ticket->id) }}" class="support-btn support-btn-ghost mt-3 w-full">释放回队列</button>
                        @elseif ($ticket->assignee_id)
                            <div class="support-note support-note-info mt-3">当前由客服 {{ $assigneeName ?: '#'.$ticket->assignee_id }} 接待，其他客服不能代替回复。</div>
                        @else
                            <div class="support-note support-note-muted mt-3">当前状态不可接入。</div>
                        @endif
                    @else
                        <p class="mt-3 text-xs leading-relaxed text-gray-500">这是留言工单，不经过在线队列。回复后会进入处理中。</p>
                    @endif

                    <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">转接给同事</p>
                            <span class="text-[10px] text-gray-400">跨技能/交接班</span>
                        </div>

                        @if (count($transferableAgents))
                            <select id="transfer-agent" class="mt-2 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                                @foreach ($transferableAgents as $agent)
                                    <option value="{{ $agent['id'] }}">
                                        {{ $agent['name'] }} · {{ $agent['is_online'] ? '在线' : '离线' }} · 接待 {{ $agent['active_count'] }}/{{ $agent['max_concurrent'] }}
                                    </option>
                                @endforeach
                            </select>
                            <button type="button" id="transfer-button" data-url="{{ route('admin.customers.tickets.transfer', $ticket->id) }}" class="support-btn support-btn-ghost mt-2 w-full">转接给该客服</button>
                            <p class="mt-2 text-[10px] leading-relaxed text-gray-500">在线会话只能转给在线且未满负荷的客服；留言工单不受在线限制。</p>
                        @else
                            <p class="mt-2 text-xs text-gray-500">当前没有在线且未满负荷的客服可接转。</p>
                        @endif
                    </div>
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">挂起与恢复</p>
                        <span class="text-[10px] text-gray-400">{{ $ticket->isHeld() ? '等待客户补充' : '暂不处理时使用' }}</span>
                    </div>

                    @if ($ticket->isHeld())
                        <div class="support-note support-note-warning mt-3">
                            已挂起 · {{ $ticket->held_reason ?: '等待客户补充' }}<br>
                            客户回复后会自动恢复，也可手动恢复。
                        </div>
                        <button type="button" id="resume-button" data-url="{{ route('admin.customers.tickets.resume', $ticket->id) }}" class="support-btn support-btn-primary mt-3 w-full">恢复处理</button>
                    @else
                        <input id="hold-reason" type="text" maxlength="200" placeholder="挂起原因（仅客服可见，选填）" class="mt-3 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                        <button type="button" id="hold-button" data-url="{{ route('admin.customers.tickets.hold', $ticket->id) }}" class="support-btn support-btn-warning mt-2 w-full">挂起等待客户补充</button>
                        <p class="mt-2 text-[10px] leading-relaxed text-gray-500">挂起后不再计入超时与待回复指标；客户回复或客服回复都会自动恢复。</p>
                    @endif
                </section>

                <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">处置状态</p>
                        <span class="text-[10px] text-gray-400">及时同步用户</span>
                    </div>

                    <form id="status-form" method="POST" action="{{ route('admin.customers.tickets.status', $ticket->id) }}" data-url="{{ route('admin.customers.tickets.status', $ticket->id) }}" class="mt-3 space-y-2.5">
                        @csrf
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">状态</label>
                            <select name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                                <option value="open" @selected($ticket->status === 'open')>待受理</option>
                                <option value="processing" @selected($ticket->status === 'processing')>处理中</option>
                                <option value="resolved" @selected($ticket->status === 'resolved')>已解决</option>
                                <option value="closed" @selected($ticket->status === 'closed')>已关闭</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-gray-500">优先级</label>
                            <select name="priority" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                                <option value="low" @selected($ticket->priority === 'low')>低</option>
                                <option value="normal" @selected($ticket->priority === 'normal')>普通</option>
                                <option value="high" @selected($ticket->priority === 'high')>高</option>
                                <option value="urgent" @selected($ticket->priority === 'urgent')>紧急</option>
                            </select>
                        </div>
                        <button type="submit" class="support-btn support-btn-primary w-full">保存处置</button>
                        <p id="status-feedback" class="support-note support-note-success mt-2 hidden"></p>
                    </form>

                    <p class="support-note support-note-warning mt-3">
                        结单前先回复用户，不要让用户只看到状态变化。
                    </p>

                    {{-- 事故定性：低频操作，收进处置卡底部，不再单独占一张卡 --}}
                    <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-gray-700 dark:text-gray-200">事故定性</p>
                            <span class="text-[10px] text-gray-400">低频操作</span>
                        </div>

                        @if ($incident)
                            <div class="support-note support-note-warning mt-2">
                                <p class="font-semibold">本工单已定性为事故：{{ $incident->incident_no }}</p>
                                <p class="mt-0.5 text-xs">{{ $incident->severityLabel() }} · {{ $incident->statusLabel() }}</p>
                                <a href="{{ route('admin.customers.incidents.show', $incident->id) }}" class="mt-2 inline-block text-xs font-medium text-blue-600 hover:underline">查看事故记录 →</a>
                            </div>
                        @elseif (! $ticket->customer_id)
                            <p class="mt-2 text-xs text-gray-500">该工单没有关联客户，无法定性为事故。</p>
                        @else
                            <details class="group mt-2">
                                <summary class="cursor-pointer list-none rounded-lg border border-gray-200 px-3 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800">将本工单升级为事故 <span class="float-right transition group-open:rotate-180">⌄</span></summary>
                                <form method="POST" action="{{ route('admin.customers.tickets.escalate', $ticket->id) }}" class="mt-2.5 space-y-2.5">
                                    @csrf
                                    <div>
                                        <label class="mb-1 block text-xs text-gray-500">严重程度</label>
                                        <select name="severity" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                                            @foreach (\Webkul\Shop\Models\Incident::SEVERITY_LABELS as $val => $label)
                                                <option value="{{ $val }}" @selected($val === 'minor')>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-gray-500">事故描述</label>
                                        <textarea name="description" rows="3" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800" placeholder="定性依据 / 经过"></textarea>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-gray-500">发生时间（可选）</label>
                                        <input type="datetime-local" name="occurred_at" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-xs dark:border-gray-700 dark:bg-gray-800">
                                    </div>
                                    <button type="submit" class="support-btn support-btn-warning w-full">确认定性</button>
                                    <p class="text-[10px] leading-relaxed text-gray-500">证据链将在定性这一刻固化，并在本工单留一条系统消息。</p>
                                </form>
                            </details>
                        @endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
