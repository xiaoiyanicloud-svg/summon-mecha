<x-admin::layouts>
    <x-slot:title>展示 · {{ $track->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">作品展示 · {{ $track->name }}</p>
            <p class="text-xs text-gray-500">
                {{ $track->edition?->event?->name }} / {{ $track->edition?->name }}
                · 必须由队长授权具体作品版本；获奖不会自动上架/发 license/下发真机
            </p>
        </div>
        <a href="{{ route('admin.catalog.ecosystem.competition.index') }}" class="transparent-button">返回赛务</a>
    </div>

    <x-admin::flash />

    <div class="mb-6 rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">创建展示草稿</p>
        <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.showcases.store', $track->id) }}" class="grid gap-2 text-xs sm:grid-cols-3 lg:grid-cols-6">
            @csrf
            <select name="showcase_type" class="rounded border px-2 py-1">
                <option value="recap">回顾/展示</option>
                <option value="award">获奖展示</option>
            </select>
            <select name="entry_id" class="rounded border px-2 py-1">
                @foreach ($entries as $entry)
                    <option value="{{ $entry->id }}">{{ $entry->entry_no }} · {{ $entry->team?->name }}</option>
                @endforeach
            </select>
            <select name="submission_version_id" class="rounded border px-2 py-1">
                @foreach ($versions as $version)
                    <option value="{{ $version->id }}">v{{ $version->version_no }} · {{ $version->title }}</option>
                @endforeach
            </select>
            <select name="award_id" class="rounded border px-2 py-1">
                <option value="">无奖项（回顾）</option>
                @foreach ($awards as $award)
                    <option value="{{ $award->id }}">{{ $award->name }}</option>
                @endforeach
            </select>
            <input name="title" class="rounded border px-2 py-1" placeholder="展示标题" />
            <input name="summary" class="rounded border px-2 py-1" placeholder="展示摘要（10~2000 字）" />
            <button class="primary-button text-xs" type="submit">创建草稿</button>
        </form>
    </div>

    @if (empty($showcases))
        <div class="rounded bg-white p-4 text-sm text-gray-500 shadow-sm dark:bg-gray-900">还没有展示。</div>
    @else
        <div class="space-y-4">
            @foreach ($showcases as $showcase)
                <div class="rounded bg-white p-4 text-xs shadow-sm dark:bg-gray-900">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="font-semibold">{{ $showcase['title'] }} · {{ $showcase['team_name'] }}</p>
                            <p class="text-gray-500">
                                {{ $showcase['showcase_type'] }} · {{ $showcase['status'] }}
                                · 作品版本 #{{ $showcase['submission_version_id'] }} · {{ $showcase['submission_title'] }}
                                · 奖项 {{ $showcase['award_name'] ?: '-' }}
                                · 授权 {{ $showcase['consent_valid'] ? '有效' : '未授权/已失效' }}
                            </p>
                        </div>
                        <span class="rounded bg-gray-100 px-2 py-1 dark:bg-gray-800">{{ $showcase['status'] }}</span>
                    </div>

                    <p class="mb-2 whitespace-pre-line">{{ $showcase['summary'] }}</p>

                    @if ($showcase['rejected_reason'])
                        <p class="mb-2 text-red-600">驳回：{{ $showcase['rejected_reason'] }}</p>
                    @endif

                    @if ($showcase['skill_version_id'])
                        <p class="mb-2 text-gray-500">
                            技能提审交接：版本 #{{ $showcase['skill_version_id'] }} · {{ $showcase['developer_handoff_at'] }}
                        </p>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        @if (in_array($showcase['status'], ['draft', 'submitted'], true))
                            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.showcases.review', $showcase['id']) }}">
                                @csrf
                                <input type="hidden" name="expected_row_version" value="{{ $showcase['row_version'] }}" />
                                <button class="primary-button text-xs" type="submit">审核通过</button>
                            </form>
                        @endif
                        @if (in_array($showcase['status'], ['draft', 'submitted', 'approved'], true))
                            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.showcases.reject', $showcase['id']) }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="expected_row_version" value="{{ $showcase['row_version'] }}" />
                                <input name="reason" class="w-48 rounded border px-2 py-1" placeholder="驳回理由" />
                                <button class="secondary-button text-xs" type="submit">驳回</button>
                            </form>
                        @endif
                        @if ($showcase['status'] === 'approved')
                            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.showcases.publish', $showcase['id']) }}">
                                @csrf
                                <input type="hidden" name="expected_row_version" value="{{ $showcase['row_version'] }}" />
                                <button class="primary-button text-xs" type="submit">发布</button>
                            </form>
                        @endif
                        @if ($showcase['status'] === 'published')
                            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.showcases.withdraw', $showcase['id']) }}" class="flex gap-1">
                                @csrf
                                <input type="hidden" name="expected_row_version" value="{{ $showcase['row_version'] }}" />
                                <input name="reason" class="w-48 rounded border px-2 py-1" placeholder="撤回理由" />
                                <button class="secondary-button text-xs" type="submit">撤回</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-admin::layouts>
