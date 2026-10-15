<x-admin::layouts>
    <x-slot:title>评价 #{{ $review->id }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">评价 #{{ $review->id }}</p>
            <p class="mt-1 text-xs text-gray-500">
                技能: {{ $product->name ?? ('#'.$review->skill_id) }}
                　评分: {{ $review->rating ?? '—' }} 分
                　提交于 {{ $review->created_at?->format('Y-m-d H:i:s') }}
            </p>
        </div>
        <a href="{{ route('admin.catalog.skill-review-moderation.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回列表</span>
        </a>
    </div>

    <x-admin::flash />

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 评价内容 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">评价内容</p>

            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">评价人</td>
                        <td class="py-2">
                            @if ($review->is_anonymous)
                                <span class="text-gray-500">匿名（用户选择了匿名，后台同样不显示真名）</span>
                            @elseif ($author)
                                {{ $author->first_name }} {{ $author->last_name }}
                                <span class="text-xs text-gray-500">（客户 #{{ $review->customer_id }}）</span>
                            @else
                                <span class="text-gray-400">客户 #{{ $review->customer_id ?? '—' }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">标题</td>
                        <td class="py-2">
                            @if (trim((string) $review->title) === '')
                                <span class="text-gray-400">未填写</span>
                            @else
                                {{ $review->title }}
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 align-top text-xs text-gray-500">正文</td>
                        <td class="py-2">
                            @if (trim((string) $review->content) === '')
                                <span class="text-gray-400">未填写</span>
                            @else
                                <div class="whitespace-pre-wrap break-all">{{ $review->content }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="py-2 align-top text-xs text-gray-500">附图</td>
                        <td class="py-2">
                            @php $media = (array) ($review->media ?? []); @endphp
                            @if ($media === [])
                                <span class="text-gray-400">无</span>
                            @else
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($media as $path)
                                        <a href="{{ \Illuminate\Support\Facades\Storage::url($path) }}" target="_blank">
                                            <img src="{{ \Illuminate\Support\Facades\Storage::url($path) }}"
                                                 alt="评价附图"
                                                 class="h-20 w-20 rounded border object-cover">
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- 机器判定 + 人工处置 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">机器判定</p>

            @php
                $map = [
                    'pass'   => ['通过', 'bg-green-100 text-green-800'],
                    'review' => ['未扫成（需人工看）', 'bg-amber-100 text-amber-800'],
                    'block'  => ['命中违禁词', 'bg-red-100 text-red-800'],
                ];
            @endphp

            @if ($scan)
                @php [$rl, $rt] = $map[$scan->result] ?? [$scan->result, 'bg-gray-100']; @endphp
                <div class="mb-3 text-sm">
                    <span class="rounded px-2 py-0.5 text-xs {{ $rt }}">{{ $rl }}</span>
                    <span class="ml-2 text-xs text-gray-500">驱动 {{ $scan->provider }}</span>
                    @if (! empty($scan->labels))
                        <div class="mt-1 text-red-700">命中：{{ implode('、', (array) $scan->labels) }}</div>
                    @endif
                    <div class="mt-1 text-xs text-gray-500">详见「内容安全」处置台对应记录。</div>
                </div>
            @else
                <p class="mb-3 text-sm text-gray-500">无扫描记录。</p>
            @endif

            <p class="mb-3 font-semibold text-gray-800 dark:text-white">人工处置</p>

            @if (trim((string) $review->moderation_note) !== '')
                <div class="mb-3 rounded border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-900">
                    上次处置理由：{{ $review->moderation_note }}
                </div>
            @endif

            <form method="POST" action="{{ route('admin.catalog.skill-review-moderation.moderate', $review->id) }}" class="space-y-3">
                @csrf

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">处置</label>
                    <select name="status" class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                        <option value="hidden" @selected(! $review->isVisible())>不可见（下架这条评价）</option>
                        <option value="visible" @selected($review->isVisible())>可见（对外展示）</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs text-gray-600 dark:text-gray-300">
                        理由 <span class="text-amber-700">（选「可见」时必填）</span>
                    </label>
                    <textarea name="note" rows="3"
                              class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800"></textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        把被机器拦下的评价重新显示出来，意味着你把风险收回来自己扛 —— 没有书面理由，事后无法解释。
                    </p>
                </div>

                <button type="submit" class="rounded bg-gray-800 px-4 py-2 text-sm text-white hover:bg-gray-900">
                    提交处置
                </button>
            </form>

            <p class="mt-3 text-xs text-gray-500">
                ⚠ 处置只能向前（可见 / 不可见），不能退回"待人工看"；
                也不联动技能上架状态与评分聚合 —— 这一台只回答"这条内容能不能被看见"。
            </p>
        </div>
    </div>
</x-admin::layouts>
