<x-admin::layouts>
    <x-slot:title>发票（开票登记）</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">发票（开票登记）</p>
            <p class="mt-1 text-xs text-gray-500">
                已支付订单会自动登记一条开票记录（支付回调内生成，游客单除外）。
            </p>
        </div>
        <a href="{{ route('admin.dashboard.ops') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">运营总览 →</span>
        </a>
    </div>

    {{-- 这一块必须排在所有数字之前：先讲清楚"做到哪一步了"，再看数字 --}}
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">这里只到「登记」，还没有「开具」</p>
        <p class="mt-1 leading-relaxed">
            平台<b>未接入</b>税控／开票服务商，所以状态会停在「已登记待开具」，开具时间与票面文件
            <b>本来就是空的</b> —— 不是"开票失败"，是"还没有这个能力"。
            本页因此<b>不提供</b>「标记为已开具」的按钮：手工点出来的"已开票"没有税务依据，
            比没有记录更坏。真正的开具要等接入开票服务商后回填 <code>issued_at</code> 与票面文件。
        </p>
        <p class="mt-1 leading-relaxed">
            用户侧<b>还没有</b>填抬头／税号的入口，所以抬头默认取订单上的客户姓名、税号为空
            —— 空税号代表"还没问过用户"，不代表"用户是个人"。
        </p>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">已登记开票记录</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($registered) }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">其中待开具</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($applied) }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">登记金额合计（下单口径，非已收款）</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white">¥{{ number_format($amount, 2) }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">已支付但未登记开票的订单</p>
            <p class="mt-2 text-2xl font-bold {{ $gap > 0 ? 'text-amber-700 dark:text-amber-500' : 'text-gray-800 dark:text-white' }}">
                {{ number_format($gap) }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
                含 M9 上线前的存量订单，需人工补开。游客单不在比对范围内 —— 它开不出票。
            </p>
        </div>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">同批四张表里，只有发票做了</p>
        <p class="mt-1 leading-relaxed">
            <b>结算</b>／<b>提现</b>／<b>对账</b> 依赖真实支付资质与分账主体，
            当前<b>未接入</b>（不是 0）—— 见
            <a href="{{ route('admin.dashboard.ops') }}" class="underline">运营总览</a>页末「本页未统计的维度」。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.invoices.index')" />
</x-admin::layouts>
