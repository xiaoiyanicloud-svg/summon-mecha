<x-admin::layouts>
    <x-slot:title>人工赛务</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">人工赛务</p>
            <p class="text-xs text-gray-500">
                系列 / 届次 / 赛项 / 规则确认 / 赛程。规则未确认、活动未发布、写闸关闭时，
                页面会明确提示，不会用默认赛制补齐。
            </p>
        </div>
        <a href="{{ route('admin.catalog.ecosystem.index') }}" class="transparent-button">返回生态发布</a>
    </div>

    <x-admin::flash />

    @if ($errors->any())
        <div class="mb-4 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-xs text-amber-900">
        写闸：<strong>{{ config('ecosystem_competition.write_enabled') ? '开启' : '关闭' }}</strong>
        （<code>ECOSYSTEM_COMPETITION_WRITE_ENABLED</code>）。
        赛项激活 ≠ 报名开放：报名仍需在 G2 报名范围页单独激活。
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- 系列 --}}
        <div class="rounded bg-white p-4 shadow-sm dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">新建系列</p>
            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.series.store') }}" class="space-y-2 text-sm">
                @csrf
                <input name="name" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="系列名称" value="{{ old('name') }}" required>
                <input name="slug" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="slug（小写字母/数字/连字符）" value="{{ old('slug') }}" required>
                <input name="organizer_name" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="主办主体名称（快照）" value="{{ old('organizer_name') }}" required>
                <textarea name="description" class="w-full rounded border px-3 py-2 dark:bg-gray-800" rows="2" placeholder="简介（可选）">{{ old('description') }}</textarea>
                <button class="primary-button">创建系列草稿</button>
            </form>
        </div>

        {{-- 届次 --}}
        <div class="rounded bg-white p-4 shadow-sm dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">新建届次</p>
            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.editions.store') }}" class="space-y-2 text-sm">
                @csrf
                <select name="series_id" class="w-full rounded border px-3 py-2 dark:bg-gray-800" required>
                    <option value="">选择系列</option>
                    @foreach ($series as $item)
                        <option value="{{ $item->id }}">{{ $item->name }} (#{{ $item->id }})</option>
                    @endforeach
                </select>
                <select name="event_id" class="w-full rounded border px-3 py-2 dark:bg-gray-800" required>
                    <option value="">选择 competition 活动</option>
                    @foreach ($events as $event)
                        <option value="{{ $event->id }}">{{ $event->name }} (#{{ $event->id }} · {{ $event->publish_status }})</option>
                    @endforeach
                </select>
                <input name="edition_no" type="number" min="1" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="届次编号" required>
                <input name="name" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="届次名称" required>
                <input name="timezone" class="w-full rounded border px-3 py-2 dark:bg-gray-800" value="Asia/Shanghai" placeholder="IANA 时区">
                <div class="grid grid-cols-2 gap-2">
                    <input name="starts_at" type="datetime-local" class="w-full rounded border px-3 py-2 dark:bg-gray-800">
                    <input name="ends_at" type="datetime-local" class="w-full rounded border px-3 py-2 dark:bg-gray-800">
                </div>
                <button class="primary-button">创建届次草稿</button>
            </form>
        </div>

        {{-- 赛项 --}}
        <div class="rounded bg-white p-4 shadow-sm dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">新建赛项</p>
            <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.tracks.store') }}" class="space-y-2 text-sm">
                @csrf
                <select name="edition_id" class="w-full rounded border px-3 py-2 dark:bg-gray-800" required>
                    <option value="">选择届次</option>
                    @foreach ($editions as $edition)
                        <option value="{{ $edition->id }}">{{ $edition->name }} / {{ $edition->series?->name }} (#{{ $edition->id }})</option>
                    @endforeach
                </select>
                <input name="track_key" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="赛项 key（如 robowar）" required>
                <input name="name" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="赛项名称" required>
                <input name="discipline" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="学科/组别（可选）">
                <input name="capacity" type="number" min="0" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="队伍容量（0=关闭）">
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="confirm_capacity" value="1"> 我已确认容量</label>
                <select name="review_mode" class="w-full rounded border px-3 py-2 dark:bg-gray-800" required>
                    <option value="manual">人工审核</option>
                    <option value="auto">提交即确认</option>
                </select>
                <label class="text-xs text-gray-500">报名截止（本地时间）</label>
                <input name="registration_closes_at" type="datetime-local" class="w-full rounded border px-3 py-2 dark:bg-gray-800">
                <label class="text-xs text-gray-500">审核截止（仅人工审核）</label>
                <input name="review_closes_at" type="datetime-local" class="w-full rounded border px-3 py-2 dark:bg-gray-800">
                <input name="privacy_version" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="隐私/同意文本版本" required>
                <textarea name="privacy_summary" rows="2" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="同意摘要"></textarea>
                <label class="text-xs text-gray-500">作品提交截止（本地时间，必填）</label>
                <input name="submission_deadline_at" type="datetime-local" class="w-full rounded border px-3 py-2 dark:bg-gray-800" required>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="allow_multi_team" value="1"> 允许一人多队</label>
                <input name="rules_version_id" type="number" min="1" class="w-full rounded border px-3 py-2 dark:bg-gray-800" placeholder="规则版本 ID（event_rule_versions）">
                <textarea name="rules_profile" rows="6" class="w-full rounded border px-3 py-2 font-mono text-xs dark:bg-gray-800" placeholder='{"owners":{...},"eligibility":{...},"scoring":{"policy":"manual"},"tie":{"policy":"manual"},"appeal":{...},"safety":{...}}'></textarea>
                <p class="text-[11px] text-gray-500">规则 profile 可留空先建草稿；确认规则是独立动作，缺项会被拒绝。</p>
                <button class="primary-button">创建赛项草稿</button>
            </form>
        </div>
    </div>

    <div class="mt-6 rounded bg-white shadow-sm dark:bg-gray-900">
        <div class="border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">赛项列表（最近 100 条）</p>
        </div>
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">赛项</th>
                    <th class="px-4 py-3">届次 / 活动</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">规则</th>
                    <th class="px-4 py-3">报名范围</th>
                    <th class="px-4 py-3">行版本</th>
                    <th class="px-4 py-3">动作</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($tracks as $track)
                    <tr class="border-b align-top dark:border-gray-800">
                        <td class="px-4 py-3">{{ $track->id }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $track->name }}</p>
                            <p class="text-xs text-gray-500">{{ $track->track_key }}</p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ $track->edition?->name }}<br>
                            <span class="text-xs text-gray-500">{{ $track->edition?->event?->name }} (#{{ $track->edition?->event_id }})</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-gray-100 px-2 py-1 text-xs">{{ $track->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $track->rules_confirmed_at ? '已确认' : '未确认' }}<br>
                            <span class="text-gray-500">v{{ $track->rules_version_id ?? '-' }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $track->scope?->status ?? '无' }}<br>
                            <span class="text-gray-500">容量 {{ $track->scope?->capacity === null ? '不限' : $track->scope?->capacity }} / 已占 {{ $track->scope?->confirmed_count ?? 0 }}</span>
                        </td>
                        <td class="px-4 py-3">{{ $track->row_version }}</td>
                        <td class="px-4 py-3 space-y-2">
                            <a class="text-blue-600" href="{{ route('admin.catalog.ecosystem.competition.schedule', $track->id) }}">赛程</a>
                            <a class="text-blue-600" href="{{ route('admin.catalog.ecosystem.competition.results', $track->id) }}">成绩</a>
                            <a class="text-blue-600" href="{{ route('admin.catalog.ecosystem.competition.appeals', $track->id) }}">申诉</a>
                            <a class="text-blue-600" href="{{ route('admin.catalog.ecosystem.competition.awards', $track->id) }}">奖项</a>
                            <a class="text-blue-600" href="{{ route('admin.catalog.ecosystem.competition.showcases', $track->id) }}">展示</a>
                            @if ($track->edition?->event_id)
                                <a class="text-blue-600" href="{{ route('admin.catalog.ecosystem.registrations.scope', $track->edition->event_id) }}">报名范围</a>
                            @endif

                            @if ($track->scope && bouncer()->hasPermission('ecosystem.registrations.settings'))
                                @if ($track->scope->status !== 'open')
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.tracks.scope.activate', $track->id) }}">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $track->scope->row_version }}">
                                        <button class="secondary-button text-xs">开放报名</button>
                                    </form>
                                    <p class="text-[11px] text-gray-500">开放前需活动已发布、容量/隐私/截止已确认。</p>
                                @else
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.tracks.scope.close', $track->id) }}">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $track->scope->row_version }}">
                                        <input type="hidden" name="reason" value="admin_close">
                                        <button class="transparent-button text-xs">关闭报名</button>
                                    </form>
                                @endif
                            @endif

                            @if (bouncer()->hasPermission('ecosystem.competition.settings'))
                                <details>
                                    <summary class="cursor-pointer text-xs text-blue-600">确认规则</summary>
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.tracks.rules', $track->id) }}" class="mt-2 space-y-1">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $track->row_version }}">
                                        <input name="rules_version_id" type="number" min="1" class="w-full rounded border px-2 py-1 text-xs" placeholder="规则版本 ID" value="{{ $track->rules_version_id }}" required>
                                        <textarea name="rules_profile" rows="6" class="w-full rounded border px-2 py-1 font-mono text-[11px]" required>{{ $track->rules_profile ? json_encode($track->rules_profile, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '' }}</textarea>
                                        <button class="secondary-button text-xs">写入确认</button>
                                    </form>
                                </details>

                                @if ($track->status !== 'active')
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.tracks.activate', $track->id) }}">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $track->row_version }}">
                                        <button class="secondary-button text-xs">激活赛项</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.catalog.ecosystem.competition.tracks.close', $track->id) }}">
                                        @csrf
                                        <input type="hidden" name="expected_row_version" value="{{ $track->row_version }}">
                                        <input type="hidden" name="reason" value="admin_close">
                                        <button class="transparent-button text-xs">关闭赛项</button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($tracks->isEmpty())
            <p class="px-4 py-6 text-sm text-gray-500">还没有赛项。先创建系列、届次，再创建赛项。</p>
        @endif
    </div>
</x-admin::layouts>
