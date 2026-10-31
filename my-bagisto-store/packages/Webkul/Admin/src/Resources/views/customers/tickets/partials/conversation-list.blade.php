@forelse ($inbox['rows'] as $row)
    @php
        $isActive = (int) $row['ticket_id'] === (int) $activeId;
        $statusTone = [
            'open' => 'support-tone-open',
            'processing' => 'support-tone-processing',
            'resolved' => 'support-tone-resolved',
            'closed' => 'support-tone-closed',
        ][$row['status']] ?? 'support-tone-closed';
    @endphp

    <a
        href="{{ route('admin.customers.tickets.show', $row['ticket_id']) }}"
        data-inbox-row
        data-customer-id="{{ $row['customer_id'] }}"
        data-ticket-id="{{ $row['ticket_id'] }}"
        data-ticket-no="{{ $row['ticket_no'] }}"
        data-unread="{{ $row['unread'] }}"
        data-waiting="{{ $row['waiting_minutes'] === null ? '' : $row['waiting_minutes'] }}"
        data-queue="{{ $row['is_queue'] ? '1' : '0' }}"
        data-async="{{ $row['has_async'] ? '1' : '0' }}"
        data-held="{{ $row['is_held'] ? '1' : '0' }}"
        data-mine="{{ $row['is_mine'] ? '1' : '0' }}"
        data-search="{{ $row['search'] }}"
        title="{{ $row['ticket_no'] }} · {{ $row['assignee_label'] }}"
        @if ($isActive) aria-current="true" @endif
        class="support-inbox-row block border-l-2 border-l-transparent px-4 py-3 transition {{ $isActive ? 'support-active' : 'hover:bg-gray-50 dark:hover:bg-gray-800' }}"
    >
        <div class="flex items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-1.5">
                <span class="truncate text-sm font-semibold text-gray-800 dark:text-white">{{ $row['customer_name'] }}</span>
                @if ($row['unread'] > 0)
                    <span class="support-unread shrink-0">{{ $row['unread'] > 99 ? '99+' : $row['unread'] }}</span>
                @endif
            </div>
            <span class="shrink-0 text-[10px] {{ $row['waiting_minutes'] !== null ? 'support-warn-text' : 'text-gray-400' }}">
                {{ $row['waiting_label'] !== '' ? $row['waiting_label'] : $row['updated_label'] }}
            </span>
        </div>

        <p class="mt-1 truncate text-xs text-gray-500 dark:text-gray-400">{{ $row['preview'] }}</p>

        <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
            <span class="support-tone {{ $statusTone }}">{{ $row['status_label'] }}</span>

            @if ($row['is_mine'])
                <span class="support-tone support-tone-processing">我负责</span>
            @endif

            @if ($row['is_held'])
                <span class="support-tone support-tone-held">挂起</span>
            @endif

            @if ($row['is_queue'])
                <span class="support-tone support-tone-open">排队</span>
            @endif

            @if ($row['is_overdue'])
                <span class="support-tone support-tone-overdue">超时</span>
            @endif

            @if (in_array($row['priority'], ['high', 'urgent'], true))
                <span class="support-tone support-tone-urgent">{{ $row['priority_label'] }}</span>
            @endif

            @if ($row['rating'])
                <span class="support-tone support-tone-rating">★{{ $row['rating'] }}</span>
            @endif

            <span class="text-[10px] text-gray-400 dark:text-gray-500">
                {{ $row['service_mode'] === 'online' ? '在线' : '留言' }} · {{ $row['category_label'] }} · {{ $row['assignee_label'] }}
            </span>

            @if ($row['ticket_count'] > 1)
                <span class="text-[10px] font-medium support-warn-text">{{ $row['ticket_count'] }} 个会话</span>
            @endif
        </div>
    </a>
@empty
    <p class="px-4 py-8 text-center text-xs text-gray-400">没有进行中的客户会话</p>
@endforelse
