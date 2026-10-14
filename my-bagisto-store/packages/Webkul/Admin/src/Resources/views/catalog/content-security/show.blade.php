<x-admin::layouts>
    <x-slot:title>内容安全记录 #{{ $record->id }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">内容安全记录 #{{ $record->id }}</p>
            <p class="mt-1 text-xs text-gray-500">
                对象: {{ $record->target_type }} #{{ $record->target_id }}
                　驱动: {{ $record->provider }}　类型: {{ $record->scan_type }}
                　扫描于 {{ $record->scanned_at?->format('Y-m-d H:i:s') }}
            </p>
        </div>
        <a href="{{ route('admin.catalog.content-security.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回列表</span>
        </a>
    </div>

    <x-admin::flash />

    @php
        $raw = (array) ($record->raw ?? []);
        $hits = (array) ($record->labels ?? []);
    @endphp

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 机器判定 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">机器判定</p>

            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">结果</td>
                        <td class="py-2">
                            @php
                                $map = [
                                    'pass'   => ['通过', 'bg-green-100 text-green-800'],
                                    'review' => ['需人工看', 'bg-amber-100 text-amber-800'],
                                    'block'  => ['拦截', 'bg-red-100 text-red-800'],
                                ];
                                [$rl, $rt] = $map[$record->result] ?? [$record->result, 'bg-gray-100'];
                            @endphp
                            <span class="rounded px-2 py-0.5 text-xs {{ $rt }}">{{ $rl }}</span>
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">命中词</td>
                        <td class="py-2">
                            @if ($hits === [])
                                <span class="text-gray-400">无</span>
                            @else
                                <span class="text-red-700">{{ implode('、', $hits) }}</span>
                            @endif
                        </td>
                    </tr>
                    @if (! empty($raw['degraded']))
                        <tr class="border-b dark:border-gray-800">
                            <td class="py-2 text-xs text-gray-500">未扫成的原因</td>
                            <td class="py-2 text-amber-700">{{ $raw['message'] ?? '原因未知' }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="py-2 text-xs text-gray-500">上下文</td>
                        <td class="py-2 text-xs">
                            <pre class="whitespace-pre-wrap break-all rounded bg-gray-50 p-2 dark:bg-gray-800">{{ json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                        </td>
                    </tr>
                </tbody>
            </table>

            @if ($version)
                <div class="mt-3 rounded border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                    对应技能版本：
                    <b>{{ $version->product?->name ?? ('商品 #'.$version->product_id) }} v{{ $version->version }}</b>
                    （版本 ID {{ $version->id }}，状态 {{ $version->status }}）
                    <br>
                    <a href="{{ route('admin.catalog.skill-reviews.show', $version->id) }}" class="text-blue-700 underline">
                        去审核台看这个版本的准入判定
                    </a>
                    <br>
                    <span class="text-blue-800">
                        处置本记录<b>不会</b>自动改技能状态 —— "内容违规"与"该不该卖"是两个判断，
                        要下架请到审核台走驳回。
                    </span>
                </div>
            @endif
        </div>

        {{-- 人工处置 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">人工处置</p>

            @if ($record->disposition !== \Webkul\Shop\Models\ContentSecurityRecord::DISPOSITION_PENDING)
                <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-900">
                    已处置：{{ $record->disposition === 'cleared' ? '放行' : '按违规处置' }}
                    @if ($record->disposition_by)
                        （管理员 #{{ $record->disposition_by }}）
                    @endif
                    @if ($record->disposition_at)
                        于 {{ $record->disposition_at->format('Y-m-d H:i') }}
                    @endif
                    @if ($record->disposition_note)
                        <div class="mt-1 text-xs">理由：{{ $record->disposition_note }}</div>
                    @endif
                </div>
            @endif

            @if ($record->result === \Webkul\Shop\Models\ContentSecurityRecord::RESULT_PASS)
                <p class="text-sm text-gray-500">机器判定为通过，无需人工处置。</p>
            @else
                <form method="POST" action="{{ route('admin.catalog.content-security.dispose', $record->id) }}" class="space-y-3">
                    @csrf

                    <div>
                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">处置</label>
                        <select name="disposition" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                            <option value="actioned">按违规处置（驳回 / 通知整改 / 下架）</option>
                            <option value="cleared">放行（确认是误判）</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">
                            理由 <span class="text-amber-700">（选「放行」时必填）</span>
                        </label>
                        <textarea name="note" rows="3"
                                  class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                        <p class="mt-1 text-xs text-gray-500">
                            推翻机器拦截意味着把风险收回来自己扛 —— 没有书面理由，事后无法解释。
                        </p>
                    </div>

                    <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                        提交处置
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-admin::layouts>
