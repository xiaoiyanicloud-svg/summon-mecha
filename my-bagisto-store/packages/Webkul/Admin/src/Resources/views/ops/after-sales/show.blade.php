<x-admin::layouts>
    <x-slot:title>售后单 #{{ $afterSale->id }}</x-slot:title>

    @php
        $statusMap = [
            'applied'   => ['已受理（待批）', 'badge-pending'],
            'reviewing' => ['人工审核中', 'badge-pending'],
            'approved'  => ['已批准（退款中）', 'badge-pending'],
            'rejected'  => ['已驳回', 'badge-danger'],
            'refunded'  => ['已退款', 'badge-success'],
        ];
        [$statusLabel, $statusTone] = $statusMap[$afterSale->status] ?? [$afterSale->status, ''];

        $policyMap = [
            'not_installed' => '未下发·可退',
            'activated'     => '已激活·需人工审',
        ];
        $policyLabel = $policyMap[$afterSale->policy_basis] ?? ($afterSale->policy_basis ?: '—');

        $actionable = in_array($afterSale->status, ['applied', 'reviewing'], true);
    @endphp

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                售后单 #{{ $afterSale->id }}
                <span class="badge badge-md {{ $statusTone }}">{{ $statusLabel }}</span>
            </p>
            <p class="mt-1 text-xs text-gray-500">
                提交于 {{ $afterSale->created_at?->format('Y-m-d H:i:s') }}
                　政策依据：{{ $policyLabel }}
            </p>
        </div>
        <a href="{{ route('admin.after-sales.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回列表</span>
        </a>
    </div>

    <x-admin::flash />

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 申请详情 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">申请详情</p>

            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">客户</td>
                        <td class="py-2">客户 #{{ $afterSale->customer_id }}</td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">订单 / 条目</td>
                        <td class="py-2">订单 #{{ $afterSale->order_id }} · 条目 #{{ $afterSale->order_item_id }}</td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">技能授权</td>
                        <td class="py-2">
                            授权 #{{ $afterSale->entitlement_id ?? '—' }}
                            @if ($entitlement)
                                <span class="text-xs text-gray-500">
                                    · {{ $entitlement->product?->name ?? ('技能 #'.$entitlement->product_id) }}
                                    · 状态 {{ $entitlement->status }}
                                </span>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">售后类型</td>
                        <td class="py-2">{{ $afterSale->type }}</td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">原因分类</td>
                        <td class="py-2">{{ $afterSale->reason_category ?: '—' }}</td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 align-top text-xs text-gray-500">用户说明</td>
                        <td class="py-2">
                            @if (trim((string) $afterSale->detail) === '')
                                <span class="text-gray-400">未填写</span>
                            @else
                                <div class="whitespace-pre-wrap break-all">{{ $afterSale->detail }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 text-xs text-gray-500">复核</td>
                        <td class="py-2">
                            @if ($afterSale->reviewed_at)
                                {{ $afterSale->reviewed_at->format('Y-m-d H:i:s') }}
                                <span class="text-xs text-gray-500">by 管理员 #{{ $afterSale->reviewer_id }}</span>
                            @else
                                <span class="text-gray-400">未复核</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- 退款台账 + 人工审批 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">退款台账</p>

            @if ($refund)
                <div class="mb-4 rounded border border-gray-200 px-3 py-2 text-sm dark:border-gray-800">
                    <div>退款单号：<code>{{ $refund->wechat_refund_no }}</code></div>
                    <div>金额：<b>¥{{ number_format((float) $refund->amount, 2) }}</b></div>
                    <div>状态：{{ $refund->status }}
                        <span class="text-xs text-gray-500">
                            （pending=退款中，success=已到账，failed=打款未成功，待资质就绪后重打）
                        </span>
                    </div>
                </div>
            @else
                <p class="mb-4 text-sm text-gray-500">尚无退款单（批准后才会生成）。</p>
            @endif

            <p class="mb-3 font-semibold text-gray-800 dark:text-white">人工审批</p>

            @if ($actionable)
                <div class="space-y-4">
                    {{-- 批准：资金动作，二次确认 --}}
                    <form method="POST" action="{{ route('admin.after-sales.approve', $afterSale->id) }}"
                          onsubmit="return confirm('确认批准？将冻结该技能授权并调退款网关（金额取该条目实付）。此操作不可撤销。');">
                        @csrf
                        <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                            批准（冻结授权 + 走退款）
                        </button>
                        <p class="mt-1 text-xs text-gray-500">
                            金额/政策判定全在 <code>AfterSaleService::approve()</code> 内（含行锁防重 + 资金留痕）。
                        </p>
                    </form>

                    {{-- 驳回 --}}
                    <form method="POST" action="{{ route('admin.after-sales.reject', $afterSale->id) }}" class="space-y-2">
                        @csrf
                        <label class="block text-xs text-gray-600 dark:text-gray-300">驳回理由（可选）</label>
                        <textarea name="reason" rows="2"
                                  class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                        <button type="submit" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800">
                            驳回
                        </button>
                    </form>
                </div>
            @else
                <p class="text-sm text-gray-500">
                    该售后单已处理（{{ $statusLabel }}），不可再操作。
                </p>
            @endif

            <p class="mt-4 text-xs text-gray-500">
                ⚠ 部分/整单退款边界（A-12）尚未裁决 —— 本台不自创，只暴露既有的"批准 / 驳回"。
            </p>
        </div>
    </div>
</x-admin::layouts>
