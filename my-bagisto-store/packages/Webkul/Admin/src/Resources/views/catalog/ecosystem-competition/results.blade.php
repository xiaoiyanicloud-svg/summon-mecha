<x-admin::layouts>
    <x-slot:title>成绩 · {{ $track->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">成绩 · {{ $track->name }}</p>
            <p class="text-xs text-gray-500">
                {{ $track->edition?->event?->name }} / {{ $track->edition?->name }} · {{ $track->track_key }}
                · 规则 v{{ $track->rules_version_id }} · 赛项 v{{ $track->row_version }}
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.catalog.ecosystem.competition.schedule', $track->id) }}" class="transparent-button">赛程</a>
            <a href="{{ route('admin.catalog.ecosystem.competition.index') }}" class="transparent-button">返回赛务</a>
        </div>
    </div>

    <x-admin::flash />

    <div class="mb-4 rounded bg-white p-4 text-xs shadow-sm dark:bg-gray-900">
        <p class="mb-1 font-semibold text-gray-800 dark:text-white">计分规则</p>
        <p class="text-gray-500">
            策略 {{ $contract['policy'] }} · 精度 {{ $contract['scale'] }} 位 · 同分 {{ $contract['tie_policy'] }}
            · 缺赛 {{ $contract['missing'] }} · 弃权 {{ $contract['forfeit'] }}
        </p>
        <p class="mt-1 text-gray-500">
            @forelse ($contract['fields'] as $field)
                <span class="mr-3">{{ $field['label'] }}({{ $field['key'] }} · {{ $field['direction'] }})</span>
            @empty
                未定义数值字段：只能人工排名。
            @endforelse
        </p>
        <p class="mt-1 text-gray-500">
            分数只接受十进制字符串（如 "12.50"），不接受浮点/表达式；名次未确定不能发布。
        </p>
    </div>

    <div class="mb-6 rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">已确认参赛条目（{{ $entries->count() }}）</p>
        @if ($entries->isEmpty())
            <p class="text-sm text-gray-500">没有已确认参赛条目。</p>
        @else
            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.results.draft', $track->id) }}" class="grid gap-2 text-xs sm:grid-cols-3 lg:grid-cols-5">
                @csrf
                <select name="entry_id" class="rounded border px-2 py-1">
                    @foreach ($entries as $entry)
                        <option value="{{ $entry->id }}">{{ $entry->entry_no }} · {{ $entry->team?->name }}</option>
                    @endforeach
                </select>
                <input name="match_id" class="rounded border px-2 py-1" placeholder="场次 ID（可空=赛项总成绩）" />
                @foreach ($contract['fields'] as $field)
                    <input name="values[{{ $field['key'] }}]" class="rounded border px-2 py-1" placeholder="{{ $field['label'] }}" />
                @endforeach
                <select name="row_status" class="rounded border px-2 py-1">
                    <option value="none">正常</option>
                    <option value="absent">整行缺赛</option>
                    <option value="forfeit">整行弃权</option>
                    <option value="disqualified">整行取消资格</option>
                </select>
                <input name="expected_row_version" type="number" min="0" class="rounded border px-2 py-1" placeholder="期望版本（新记录填 0）" />
                <input name="reason" class="rounded border px-2 py-1" placeholder="变更说明（可选）" />
                <button class="primary-button text-xs" type="submit">保存草稿</button>
            </form>
        @endif
    </div>

    <div class="mb-6 rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold text-gray-800 dark:text-white">成绩记录（{{ count($results) }}）</p>
            <div class="flex gap-2">
                <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.results.publish', $track->id) }}">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $track->row_version }}" />
                    <button class="primary-button text-xs" type="submit">暂定发布</button>
                </form>
                <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.results.finalize', $track->id) }}">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $track->row_version }}" />
                    <button class="secondary-button text-xs" type="submit">终审公布</button>
                </form>
            </div>
        </div>

        @if (empty($results))
            <p class="text-sm text-gray-500">还没有成绩。先保存草稿。</p>
        @else
            <table class="w-full text-xs">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="py-1">条目</th>
                        <th class="py-1">队伍</th>
                        <th class="py-1">范围</th>
                        <th class="py-1">状态</th>
                        <th class="py-1">当前版本</th>
                        <th class="py-1">公布版本</th>
                        <th class="py-1">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($results as $row)
                        <tr class="border-t align-top dark:border-gray-800">
                            <td class="py-1">{{ $row['entry_no'] }}</td>
                            <td class="py-1">{{ $row['team_name'] }}</td>
                            <td class="py-1">{{ $row['scope_key'] }}</td>
                            <td class="py-1">{{ $row['status'] }}</td>
                            <td class="py-1">
                                @if ($row['current_version'])
                                    v{{ $row['current_version']['version_no'] }} · {{ $row['current_version']['status'] }}
                                    · 总分 {{ $row['current_version']['total_scaled'] ?? '-' }}
                                    · 名次 {{ $row['current_version']['rank_no'] ?? '待定' }}
                                    · 弃权 {{ $row['current_version']['forfeit'] }}
                                @else - @endif
                            </td>
                            <td class="py-1">
                                @if ($row['published_version'])
                                    #{{ $row['published_version']['id'] }} · {{ $row['published_version']['status'] }}
                                    · 名次 {{ $row['published_version']['rank_no'] ?? '-' }}
                                @else - @endif
                            </td>
                            <td class="space-y-1 py-1">
                                @if ($row['current_version'] && $row['current_version']['status'] === 'draft')
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.results.submit', $row['id']) }}">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $row['row_version'] }}" />
                                        <button class="mini-button text-xs" type="submit">提交复核</button>
                                    </form>
                                @endif
                                @if (in_array($row['status'], ['provisional', 'final'], true))
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.results.revise', $row['id']) }}" class="flex gap-1">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $row['row_version'] }}" />
                                        <input name="reason" class="w-40 rounded border px-1 py-0.5" placeholder="修订理由（必填）" />
                                        <button class="mini-button text-xs" type="submit">发起修订</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">名次（草稿/复核版本）</p>
        <p class="mb-2 text-xs text-gray-500">
            自动策略可先预览；并列由规则决定。人工名次必须逐条填写；不出现的条目会清空名次。
        </p>
        <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.results.ranking', $track->id) }}" class="text-xs">
            @csrf
            <input name="match_id" class="mb-2 rounded border px-2 py-1" placeholder="场次 ID（可空=赛项总成绩）" />
            <table class="w-full">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="py-1">条目</th>
                        <th class="py-1">名次</th>
                        <th class="py-1">并列组（可选）</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($entries as $entry)
                        <tr class="border-t dark:border-gray-800">
                            <td class="py-1">
                                {{ $entry->entry_no }} · {{ $entry->team?->name }}
                                <input type="hidden" name="ranking[{{ $loop->index }}][entry_id]" value="{{ $entry->id }}" />
                            </td>
                            <td class="py-1">
                                <input name="ranking[{{ $loop->index }}][rank_no]" type="number" min="1" class="w-20 rounded border px-1 py-0.5" />
                            </td>
                            <td class="py-1">
                                <input name="ranking[{{ $loop->index }}][tie_group]" class="w-32 rounded border px-1 py-0.5" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <button class="primary-button mt-2 text-xs" type="submit">写入选定名次</button>
        </form>

        @if (! empty($preview))
            <p class="mt-3 mb-1 text-xs font-semibold text-gray-700 dark:text-white">自动排名预览（不写库）</p>
            <ul class="text-xs text-gray-500">
                @foreach ($preview as $row)
                    <li>条目 #{{ $row['entry_id'] }} → 名次 {{ $row['rank_no'] ?? '待人工核准' }}{{ $row['tie_group'] ? ' · '.$row['tie_group'] : '' }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</x-admin::layouts>
