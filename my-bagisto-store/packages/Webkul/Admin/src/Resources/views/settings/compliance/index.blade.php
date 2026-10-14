<x-admin::layouts>
    <x-slot:title>备案台账</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">备案台账</p>

        <a href="{{ route('admin.settings.compliance.create') }}">
            <div class="primary-button">登记备案</div>
        </a>
    </div>

    <div class="mb-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-semibold">备案不是"办完就完了" —— 它有有效期与年检</p>
        <p class="mt-1 leading-relaxed">
            漏掉一次年检，整套备案可能失效，而失效期是不打招呼的。列表按
            <b>最近的年检/到期日</b>排序，到期前
            {{ \Webkul\Shop\Models\ComplianceFiling::EXPIRING_SOON_DAYS }} 天标红 ——
            台账存在的意义就是提前看见。
        </p>
        <p class="mt-1 text-xs leading-relaxed text-blue-800">
            ⚠ 本台账<b>没有删除入口</b>：备案记录是"你确实办过这件事"的凭据，能被抹掉的台账不是台账。
            办错了改状态、写备注；确实作废的改成「已作废」并写明原因。
        </p>
    </div>

    <x-admin::datagrid :src="route('admin.settings.compliance.index')" />
</x-admin::layouts>
