<x-admin::layouts>
    <x-slot:title>客服工作台 {{ $ticket->ticket_no }}</x-slot:title>

    @include('admin::customers.tickets.partials.workspace')

    <div id="workspace-toast" class="support-dark-pill pointer-events-none fixed bottom-6 left-1/2 z-50 hidden -translate-x-1/2 rounded-lg px-4 py-2 text-xs font-medium shadow-lg"></div>

    @push('styles')
        {{-- 后台 Tailwind 是预编译的，产物里没有 bg-gray-900 / bg-emerald-600 等类。
             工作台的关键颜色与按钮在这里显式定义，避免“按钮透明、徽标掉色”。 --}}
        <style>
            .support-console-grid { display: grid; gap: 1rem; }
            @media (min-width: 1280px) {
                .support-console-grid {
                    grid-template-columns: 320px minmax(0, 1fr) 330px;
                    height: calc(100vh - 118px);
                    min-height: 640px;
                }
            }
            @media (min-width: 1024px) and (max-width: 1279px) {
                .support-console-grid { grid-template-columns: 300px minmax(0, 1fr); }
                .support-console-right {
                    grid-column: 1 / -1;
                    display: grid;
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                    gap: 1rem;
                }
            }
            @media (max-width: 1023px) {
                .support-console-grid { grid-template-columns: minmax(0, 1fr); }
                .support-console-left { max-height: 360px; }
                .support-console-center { height: 72vh; }
            }

            .support-tone { display: inline-flex; align-items: center; border-radius: 9999px; padding: 2px 10px; font-size: 11px; font-weight: 500; line-height: 1.5; white-space: nowrap; }
            .support-tone-open { background: #f0f9ff; color: #0369a1; box-shadow: inset 0 0 0 1px #bae6fd; }
            .support-tone-processing { background: #eff6ff; color: #1d4ed8; box-shadow: inset 0 0 0 1px #bfdbfe; }
            .support-tone-resolved { background: #ecfdf5; color: #047857; box-shadow: inset 0 0 0 1px #a7f3d0; }
            .support-tone-closed { background: #f3f4f6; color: #4b5563; box-shadow: inset 0 0 0 1px #e5e7eb; }
            .support-tone-online { background: #ecfdf5; color: #047857; box-shadow: inset 0 0 0 1px #a7f3d0; }
            .support-tone-async { background: #f3f4f6; color: #4b5563; box-shadow: inset 0 0 0 1px #e5e7eb; }
            .support-tone-overdue { background: #fff1f2; color: #be123c; box-shadow: inset 0 0 0 1px #fecdd3; }
            .support-tone-urgent { background: #fff7ed; color: #c2410c; box-shadow: inset 0 0 0 1px #fed7aa; }
            .support-tone-rating { background: #fffbeb; color: #b45309; box-shadow: inset 0 0 0 1px #fde68a; }
            .support-tone-held { background: #fffbeb; color: #b45309; box-shadow: inset 0 0 0 1px #fde68a; }
            /* .support-tone 自带 display:inline-flex，会盖掉 Tailwind 的 .hidden；
               徽标类元素要显式让 hidden 生效，否则「挂起」徽标会一直挂在头上。 */
            .support-tone.hidden { display: none; }

            .support-note-bubble { display: flex; flex-direction: column; gap: 3px; max-width: 32rem; padding: 10px 14px; border-radius: 12px; background: #fffbeb; color: #92400e; box-shadow: inset 0 0 0 1px #fde68a; text-align: left; }
            .support-note-kicker { font-size: 9px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #b45309; }
            .support-note-body { font-size: 12px; line-height: 1.6; white-space: pre-wrap; }
            .support-note-time { font-size: 9px; color: #b45309; opacity: .8; }
            .dark .support-note-bubble { background: #451a03; color: #fcd34d; box-shadow: inset 0 0 0 1px #92400e; }
            .dark .support-note-kicker, .dark .support-note-time { color: #fbbf24; }

            .support-receipt { font-size: 10px; color: #9ca3af; }
            .support-receipt-read { color: #059669; }

            .support-mode { border-radius: 9999px; padding: 3px 10px; font-size: 11px; font-weight: 500; line-height: 1.5; color: #6b7280; background: #fff; box-shadow: inset 0 0 0 1px #e5e7eb; cursor: pointer; }
            .support-mode-active { background: #2563eb; color: #fff; box-shadow: none; }
            .dark .support-mode { background: #1f2937; color: #d1d5db; box-shadow: inset 0 0 0 1px #374151; }
            .dark .support-mode-active { background: #2563eb; color: #fff; box-shadow: none; }

            .support-filter { display: inline-flex; align-items: center; gap: 3px; border-radius: 9999px; padding: 2px 8px; font-size: 10px; font-weight: 500; line-height: 1.6; color: #6b7280; background: #fff; box-shadow: inset 0 0 0 1px #e5e7eb; cursor: pointer; flex-shrink: 0; }
            .support-filter-row { display: flex; gap: 4px; overflow-x: auto; white-space: nowrap; padding-bottom: 2px; }
            .support-filter-row::-webkit-scrollbar { display: none; }

            .support-toggle { border-radius: 9999px; padding: 1px 7px; font-size: 10px; line-height: 1.7; color: #6b7280; background: #fff; box-shadow: inset 0 0 0 1px #e5e7eb; cursor: pointer; white-space: nowrap; }
            .support-toggle-on { color: #047857; box-shadow: inset 0 0 0 1px #a7f3d0; background: #ecfdf5; }
            .support-toggle:disabled { opacity: .5; cursor: not-allowed; }
            .dark .support-toggle { background: #1f2937; color: #d1d5db; box-shadow: inset 0 0 0 1px #374151; }
            .dark .support-toggle-on { background: #022c22; color: #6ee7b7; box-shadow: inset 0 0 0 1px #065f46; }
            .support-filter b { font-weight: 600; color: #374151; }
            .support-filter-active { background: #2563eb; color: #fff; box-shadow: none; }
            .support-filter-active b { color: #fff; }
            .dark .support-filter { background: #1f2937; color: #d1d5db; box-shadow: inset 0 0 0 1px #374151; }
            .dark .support-filter b { color: #e5e7eb; }
            .dark .support-filter-active { background: #2563eb; color: #fff; box-shadow: none; }
            .support-inbox-row.hidden { display: none; }

            .support-unread { display: inline-flex; align-items: center; justify-content: center; min-width: 16px; height: 16px; padding: 0 5px; border-radius: 9999px; background: #e11d48; color: #fff; font-size: 10px; font-weight: 600; line-height: 1; }

            .support-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: 8px; padding: 8px 14px; font-size: 12px; font-weight: 500; line-height: 1.2; cursor: pointer; transition: background-color .15s, opacity .15s; }
            .support-btn:disabled { opacity: .5; cursor: not-allowed; }
            .support-btn-primary { background: #111827; color: #fff; }
            .support-btn-primary:hover:not(:disabled) { background: #1f2937; }
            .support-btn-success { background: #059669; color: #fff; }
            .support-btn-success:hover:not(:disabled) { background: #047857; }
            .support-btn-warning { background: #d97706; color: #fff; }
            .support-btn-warning:hover:not(:disabled) { background: #b45309; }
            .support-btn-ghost { background: #fff; color: #4b5563; box-shadow: inset 0 0 0 1px #e5e7eb; }
            .support-btn-ghost:hover:not(:disabled) { background: #f9fafb; }
            .dark .support-btn-primary { background: #fff; color: #111827; }
            .dark .support-btn-primary:hover:not(:disabled) { background: #f3f4f6; }
            .dark .support-btn-ghost { background: #1f2937; color: #e5e7eb; box-shadow: inset 0 0 0 1px #374151; }
            .dark .support-btn-ghost:hover:not(:disabled) { background: #374151; }

            .support-presence { display: inline-flex; align-items: center; gap: 6px; border-radius: 8px; padding: 8px 10px; font-size: 11px; font-weight: 500; cursor: pointer; }
            .support-presence-online { background: #ecfdf5; color: #047857; box-shadow: inset 0 0 0 1px #a7f3d0; }
            .support-presence-offline { background: #f9fafb; color: #4b5563; box-shadow: inset 0 0 0 1px #e5e7eb; }
            .support-dot { width: 6px; height: 6px; border-radius: 9999px; flex-shrink: 0; }
            .support-dot-online { background: #10b981; }
            .support-dot-offline { background: #9ca3af; }
            .support-dot-warn { background: #f59e0b; }
            .support-dot-sky { background: #0ea5e9; }

            .support-avatar { display: flex; align-items: center; justify-content: center; border-radius: 10px; font-size: 11px; font-weight: 600; }
            .support-avatar-admin { background: #2563eb; color: #fff; }
            .support-avatar-customer { background: #f3f4f6; color: #4b5563; }
            .support-avatar-dev { background: #e0e7ff; color: #4f46e5; }

            .support-bubble { white-space: pre-wrap; border-radius: 16px; padding: 10px 16px; font-size: 14px; line-height: 1.75; box-shadow: 0 1px 2px rgba(0, 0, 0, .05); }
            .support-bubble-admin { background: #2563eb; color: #fff; border-radius: 16px 4px 16px 16px; }
            .support-bubble-customer { background: #fff; color: #374151; box-shadow: inset 0 0 0 1px #e5e7eb; border-radius: 4px 16px 16px 16px; }
            .support-product-attachment { display: flex; width: min(100%, 390px); gap: 12px; padding: 10px; border-radius: 12px; background: #fff; color: #374151; box-shadow: inset 0 0 0 1px #dbeafe, 0 4px 12px rgba(37, 99, 235, .06); text-align: left; text-decoration: none; transition: box-shadow .16s ease, transform .16s ease; }
            a.support-product-attachment:hover { box-shadow: inset 0 0 0 1px #93c5fd, 0 8px 20px rgba(37, 99, 235, .12); transform: translateY(-1px); }
            .support-product-thumb { width: 58px; height: 58px; flex: 0 0 58px; overflow: hidden; display: flex; align-items: center; justify-content: center; border-radius: 10px; background: #eff6ff; color: #2563eb; font-size: 16px; font-weight: 700; }
            .support-product-thumb img { width: 100%; height: 100%; object-fit: cover; }
            .support-product-copy { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 3px; }
            .support-product-kicker { color: #2563eb; font-size: 10px; font-weight: 600; }
            .support-product-name { overflow: hidden; color: #111827; font-size: 13px; line-height: 1.4; text-overflow: ellipsis; white-space: nowrap; }
            .support-product-meta { display: flex; flex-wrap: wrap; gap: 3px 10px; color: #6b7280; font-size: 10px; }
            .support-product-link { color: #2563eb; font-weight: 600; }
            .dark .support-product-attachment { background: #1f2937; color: #e5e7eb; box-shadow: inset 0 0 0 1px #1e40af; }
            .dark .support-product-name { color: #f3f4f6; }
            .dark .support-product-thumb { background: #172554; color: #93c5fd; }
            .dark .support-product-meta { color: #9ca3af; }
            .dark .support-bubble-customer { background: #1f2937; color: #f3f4f6; box-shadow: inset 0 0 0 1px #374151; }

            .support-note { border-radius: 8px; padding: 8px 12px; font-size: 11px; line-height: 1.6; }
            .support-note-success { background: #ecfdf5; color: #047857; box-shadow: inset 0 0 0 1px #a7f3d0; }
            .support-note-info { background: #eff6ff; color: #1d4ed8; box-shadow: inset 0 0 0 1px #bfdbfe; }
            .support-note-warning { background: #fffbeb; color: #92400e; box-shadow: inset 0 0 0 1px #fde68a; }
            .support-note-muted { background: #f9fafb; color: #6b7280; box-shadow: inset 0 0 0 1px #e5e7eb; }
            .dark .support-note-success { background: #022c22; color: #6ee7b7; box-shadow: inset 0 0 0 1px #065f46; }
            .dark .support-note-info { background: #172554; color: #93c5fd; box-shadow: inset 0 0 0 1px #1e3a8a; }
            .dark .support-note-warning { background: #451a03; color: #fcd34d; box-shadow: inset 0 0 0 1px #92400e; }
            .dark .support-note-muted { background: #1f2937; color: #9ca3af; box-shadow: inset 0 0 0 1px #374151; }

            .support-active { background: #eff6ff; border-left-color: #3b82f6; }
            .dark .support-active { background: rgba(23, 37, 84, .45); }
            .support-brand-mark { background: #111827; color: #fff; }
            .dark .support-brand-mark { background: #fff; color: #111827; }
            .support-warn-text { color: #d97706; }
            .support-danger-text { color: #e11d48; }
            .support-rating-card { background: #fffbeb; box-shadow: inset 0 0 0 1px #fde68a; border-radius: 8px; padding: 10px; }
            .support-rating-title { color: #b45309; font-weight: 600; }
            .support-rating-tags { color: #92400e; }
            .dark .support-rating-card { background: #451a03; box-shadow: inset 0 0 0 1px #92400e; }
            .dark .support-rating-title { color: #fcd34d; }
            .dark .support-rating-tags { color: #fbbf24; }
            .support-chip { display: inline-flex; align-items: center; border-radius: 9999px; background: #fff; color: #4b5563; box-shadow: inset 0 0 0 1px #e5e7eb; white-space: nowrap; }
            .support-chip:hover { background: #f9fafb; }
            .dark .support-chip { background: #1f2937; color: #d1d5db; box-shadow: inset 0 0 0 1px #374151; }
            .dark .support-chip:hover { background: #374151; }
            .support-dark-pill { background: #111827; color: #fff; }
            .dark .support-dark-pill { background: #fff; color: #111827; }
            .dark .support-tone-open,
            .dark .support-tone-processing,
            .dark .support-tone-resolved,
            .dark .support-tone-closed,
            .dark .support-tone-online,
            .dark .support-tone-async,
            .dark .support-tone-overdue,
            .dark .support-tone-urgent,
            .dark .support-tone-rating,
            .dark .support-tone-held {
                background: #1f2937;
                color: #e5e7eb;
                box-shadow: inset 0 0 0 1px #374151;
            }
        </style>
    @endpush

    @push('scripts')
        <script src="{{ route('admin.customers.support.workbench-script', ['v' => '20261030-product-card-1']) }}"></script>
    @endpush
</x-admin::layouts>
