<x-admin::layouts>
    <x-slot:title>售后审批</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">售后审批</p>
            <p class="mt-1 text-xs text-gray-500">
                虚拟商品（技能）的售后/退款申请。用户侧申请已通，这里补的是<b>平台端审批</b>那一头。
            </p>
        </div>
        <a href="{{ route('admin.dashboard.ops') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">运营总览 →</span>
        </a>
    </div>

    <x-admin::flash />

    {{-- 先讲清楚"这台能做什么、不能做什么"，再看数字与列表 --}}
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <p class="font-semibold">按 D10 政策处理；部分/整单边界尚未裁决</p>
        <p class="mt-1 leading-relaxed">
            政策（D10）：<b>试用</b>不退、<b>买断·未下发</b>可退、<b>买断·已激活</b>原则上不退（走人工审）。
            申请单进来自带 <code>policy_basis</code>，本台<b>照实展示</b>它。
        </p>
        <p class="mt-1 leading-relaxed">
            ⚠ <b>部分/整单退款边界（A-12）尚未裁决</b>，本台<b>不自创</b>：
            批准走的是既有 <code>AfterSaleService::approve()</code>，金额取该订单条目<b>实付</b>（整条）。
            边界定下来前，这里不会出现"部分退款"输入框。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-amber-800">
            ⚠ <b>批准是资金动作</b>：会冻结授权并调退款网关（支付资质就绪前走 mock）。
            请在详情页看清金额与政策依据后，经二次确认再批准。
        </p>
    </div>

    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">待处理（已受理 / 人工审核中）</p>
            <p class="mt-2 text-2xl font-bold {{ $pending > 0 ? 'text-amber-700 dark:text-amber-500' : 'text-gray-800 dark:text-white' }}">
                {{ number_format($pending) }}
            </p>
            <p class="mt-1 text-xs text-gray-500">这些单子没人批就会一直停在"已受理"。</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">售后单总数</p>
            <p class="mt-2 text-2xl font-bold text-gray-800 dark:text-white">{{ number_format($total) }}</p>
        </div>
    </div>

    <x-admin::datagrid :src="route('admin.after-sales.index')" />
</x-admin::layouts>
