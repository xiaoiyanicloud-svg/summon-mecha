<x-admin::layouts>
    <x-slot:title>举报 #{{ $report->id }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">举报 #{{ $report->id }}</p>
            <p class="mt-1 text-xs text-gray-500">
                对象: {{ $report->target_type }} #{{ $report->target_id }}
                　理由: {{ $report->reasonLabel() }}
                　提交于 {{ $report->created_at?->format('Y-m-d H:i:s') }}
            </p>
        </div>
        <a href="{{ route('admin.catalog.skill-reports.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回列表</span>
        </a>
    </div>

    <x-admin::flash />

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 举报内容 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">举报内容</p>

            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">举报人</td>
                        <td class="py-2">
                            @if ($reporter)
                                {{ $reporter->first_name }} {{ $reporter->last_name }}
                                <span class="text-xs text-gray-500">（客户 #{{ $report->reporter_customer_id }}）</span>
                            @else
                                <span class="text-gray-400">未知（客户 #{{ $report->reporter_customer_id ?? '—' }}）</span>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">理由类别</td>
                        <td class="py-2">{{ $report->reasonLabel() }}</td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 align-top text-xs text-gray-500">正文</td>
                        <td class="py-2">
                            @if (trim((string) $report->detail) === '')
                                <span class="text-gray-400">用户未填写正文（正文是选填的：能指出"哪里不对"即够立案）</span>
                            @else
                                <div class="whitespace-pre-wrap break-all">{{ $report->detail }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">被举报对象</td>
                        <td class="py-2">
                            @if ($report->target_type === \Webkul\Shop\Models\SkillReport::TARGET_SKILL)
                                @if ($product)
                                    技能：<b>{{ $product->name ?? ('商品 #'.$product->id) }}</b>
                                    <span class="text-xs text-gray-500">（商品 #{{ $product->id }}）</span>
                                @else
                                    <span class="text-amber-700">技能 #{{ $report->target_id }} 已不存在（可能已被删除）</span>
                                @endif
                            @else
                                @if ($review)
                                    评价 #{{ $review->id }}（评分 {{ $review->rating ?? '—' }}，状态 {{ $review->status }}）
                                    @if ($product)
                                        <br>所属技能：<b>{{ $product->name ?? ('商品 #'.$product->id) }}</b>
                                        <span class="text-xs text-gray-500">（商品 #{{ $product->id }}）</span>
                                    @endif
                                @else
                                    <span class="text-amber-700">评价 #{{ $report->target_id }} 已不存在</span>
                                @endif
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 text-xs text-gray-500">内容安全扫描</td>
                        <td class="py-2 text-xs">
                            @if ($scan)
                                <span class="rounded px-2 py-0.5
                                    {{ $scan->result === 'block' ? 'bg-red-100 text-red-800' : ($scan->result === 'review' ? 'bg-amber-100 text-amber-800' : 'bg-green-100 text-green-800') }}">
                                    {{ $scan->result === 'block' ? '命中违禁词' : ($scan->result === 'review' ? '未扫成（需人工看）' : '通过') }}
                                </span>
                                @if (! empty($scan->labels))
                                    <span class="ml-1 text-red-700">{{ implode('、', (array) $scan->labels) }}</span>
                                @endif
                                <br>
                                <span class="text-gray-500">
                                    举报正文<b>不会因为命中违禁词被拒收</b> —— 举报人要描述违规，往往必须引述违规内容；
                                    拦掉它等于把通道关死。命中只在这里提示，实际判断以人工结论为准。
                                </span>
                            @else
                                <span class="text-gray-400">无扫描记录</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>

            @if ($report->target_type === \Webkul\Shop\Models\SkillReport::TARGET_SKILL)
                <div class="mt-3 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    处置本举报<b>不会</b>自动改这个技能的上架状态。要下架/驳回请去
                    <a href="{{ route('admin.catalog.skill-reviews.index') }}" class="text-blue-700 underline">审核台</a>
                    （版本准入）或熔断台，那里各有独立的理由与留痕。
                </div>
            @endif
        </div>

        {{-- 处置 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">处置</p>

            @if (! $report->isPending())
                <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900">
                    已处置：{{ $report->statusLabel() }}
                    @if ($report->handler_id)
                        （管理员 #{{ $report->handler_id }}）
                    @endif
                    @if ($report->handled_at)
                        于 {{ $report->handled_at->format('Y-m-d H:i') }}
                    @endif
                    @if ($report->handling_note)
                        <div class="mt-1 text-xs">理由：{{ $report->handling_note }}</div>
                    @endif
                    <div class="mt-1 text-xs">
                        结案后该用户可再次举报同一对象（未处置去重槽位已释放）。
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.catalog.skill-reports.dispose', $report->id) }}" class="space-y-3">
                @csrf

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">结论</label>
                    <select name="status" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="accepted">举报成立（已按违规处理）</option>
                        <option value="rejected">举报不成立（驳回）</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">
                        处置说明 <span class="text-amber-700">（选「举报不成立」时必填）</span>
                    </label>
                    <textarea name="note" rows="3"
                              class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        驳回一个举报 = 认定"没问题" = 把风险收回来自己扛，没有书面理由事后无法解释。
                    </p>
                </div>

                <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                    提交处置
                </button>
            </form>

            <p class="mt-3 text-xs text-gray-500">
                ⚠ 处置只能向前（成立 / 不成立），不能退回"待处置" ——
                退回会让已结案的记录重新占用去重槽位，历史结论与去重语义互相打架。
            </p>
        </div>
    </div>
</x-admin::layouts>
