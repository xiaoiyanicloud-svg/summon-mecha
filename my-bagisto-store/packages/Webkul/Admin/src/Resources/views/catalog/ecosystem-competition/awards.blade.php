<x-admin::layouts>
    <x-slot:title>奖项 · {{ $track->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">奖项 · {{ $track->name }}</p>
            <p class="text-xs text-gray-500">
                {{ $track->edition?->event?->name }} / {{ $track->edition?->name }}
                · 发布必须引用当前已终审成绩；修订后旧奖项自动转待复核
            </p>
        </div>
        <a href="{{ route('admin.catalog.ecosystem.competition.index') }}" class="transparent-button">返回赛务</a>
    </div>

    <x-admin::flash />

    <div class="mb-6 rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">新建奖项草稿版本</p>
        <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.awards.version', $track->id) }}" class="grid gap-2 text-xs sm:grid-cols-6">
            @csrf
            <input name="award_key" class="rounded border px-2 py-1" placeholder="award_key（champion）" />
            <input name="name" class="rounded border px-2 py-1" placeholder="奖项名称" />
            <select name="entry_id" class="rounded border px-2 py-1">
                @foreach ($entries as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->entry_no }} · {{ $entry->team?->name }}</option>
                @endforeach
            </select>
            <input name="sort_order" type="number" min="0" class="rounded border px-2 py-1" placeholder="排序" />
            <input name="reason" class="rounded border px-2 py-1" placeholder="说明（可选）" />
            <button class="primary-button text-xs" type="submit">创建草稿</button>
        </form>
    </div>

    @if (empty($awards))
        <div class="rounded bg-white p-4 text-sm text-gray-500 shadow-sm dark:bg-gray-900">还没有奖项。</div>
    @else
        <div class="space-y-4">
            @foreach ($awards as $award)
                <div class="rounded bg-white p-4 text-xs shadow-sm dark:bg-gray-900">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $award['name'] }} <span class="text-gray-500">({{ $award['award_key'] }})</span></p>
                            <p class="text-gray-500">状态 {{ $award['status'] }} · 排序 {{ $award['sort_order'] }} · 当前版本 {{ $award['current_version_id'] ?? '-' }}</p>
                        </div>
                        <span class="rounded bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $award['status'] }}</span>
                    </div>

                    @foreach ($award['versions'] as $version)
                        <div class="mb-2 flex flex-wrap items-center gap-3 border-t py-2 dark:border-gray-800">
                            <span>v{{ $version['version_no'] }} · {{ $version['status'] }}</span>
                            <span>{{ $version['entry_no'] }} · {{ $version['team_name'] }}</span>
                            <span>成绩版本 #{{ $version['result_version_id'] }}</span>
                            <span>名次 {{ $version['rank_no'] ?? '-' }}</span>
                            @if ($version['published_at'])
                                <span class="text-gray-500">{{ $version['published_at'] }}</span>
                            @endif
                            @if ($version['status'] === 'draft')
                                <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.awards.publish', $award['id']) }}" class="flex gap-1">
                                    @csrf
                                    <input type="hidden" name="version_id" value="{{ $version['id'] }}" />
                                    <input type="hidden" name="expected_row_version" value="{{ $award['row_version'] }}" />
                                    <button class="primary-button text-xs" type="submit">发布</button>
                                </form>
                            @endif
                        </div>
                    @endforeach

                    @if ($award['status'] !== 'revoked')
                        <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.awards.revoke', $award['id']) }}" class="flex gap-2">
                            @csrf
                            <input type="hidden" name="expected_row_version" value="{{ $award['row_version'] }}" />
                            <input name="reason" class="w-64 rounded border px-2 py-1" placeholder="撤销理由（必填）" />
                            <button class="secondary-button text-xs" type="submit">撤销奖项</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-admin::layouts>
