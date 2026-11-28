<x-admin::layouts>
    <x-slot:title>客服中心</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">客服中心 · 客服工单</p>
            <p class="pb-2 text-xs text-gray-500">在线会话与留言工单统一受理；支持按问题分类检索与工作台实时重新归类，接待前请在工作台左上角点「上线接待」。</p>
        </div>
        <a href="{{ route('admin.customers.support.workbench') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:opacity-90">
            进入客服工作台 <span class="text-blue-200">→</span>
        </a>
    </div>

    @if (! empty($overview))
        <div class="mb-4 grid gap-3 rounded-xl border border-gray-200 bg-white p-3.5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-500">
                <span>进行中工单 <b class="font-semibold text-gray-900 dark:text-white">{{ $overview['active_total'] }}</b></span>
                <span>待回复 <b class="font-semibold {{ $overview['waiting_reply'] > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-white' }}">{{ $overview['waiting_reply'] }}</b></span>
                <span>已超时 <b class="font-semibold {{ $overview['overdue'] > 0 ? 'text-rose-600' : 'text-gray-900 dark:text-white' }}">{{ $overview['overdue'] }}</b></span>
                <span>挂起中 <b class="font-semibold text-gray-900 dark:text-white">{{ $overview['held'] }}</b></span>
                <span>今日新增 <b class="font-semibold text-gray-900 dark:text-white">{{ $overview['new_today'] }}</b></span>
                <span>今日结单 <b class="font-semibold text-emerald-600">{{ $overview['closed_today'] }}</b></span>
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-2.5 text-xs dark:border-gray-800">
                <span class="text-gray-400">待处理归类分布</span>
                @foreach ($overview['categories'] as $cat)
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-0.5 text-xs text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <span>{{ $cat['label'] }}</span>
                        <b class="font-semibold {{ $cat['count'] > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400' }}">{{ $cat['count'] }}</b>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    <x-admin::datagrid :src="route('admin.customers.tickets.index')" />
</x-admin::layouts>
