<x-admin::layouts>
    <x-slot:title>活动工作页</x-slot:title>

    @php
        $tz = $event->timezone ?: config('app.timezone', 'Asia/Shanghai');
        $localInput = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->timezone($tz)->format('Y-m-d\TH:i') : '';
        $canSubmit = bouncer()->hasPermission('ecosystem.events.submit');
        $canReview = bouncer()->hasPermission('ecosystem.events.review');
        $canSchedule = bouncer()->hasPermission('ecosystem.events.schedule');
        $canPublish = bouncer()->hasPermission('ecosystem.events.publish');
        $canWithhold = bouncer()->hasPermission('ecosystem.events.withhold');
        $canRetract = bouncer()->hasPermission('ecosystem.events.retract');
        $revisions = $event->revisions;
    @endphp

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">{{ $event->name }} <span class="text-sm text-gray-500">#{{ $event->id }}</span></p>
            <p class="text-xs text-gray-500">
                publish_status=<b>{{ $event->publish_status }}</b>
                disposition=<b>{{ $event->disposition }}</b>
                row_version=<b>{{ $event->row_version }}</b>
                公开 revision=<b>{{ $event->published_revision_id ?? '—' }}</b>
                候选 revision=<b>{{ $event->approved_revision_id ?? '—' }}</b>
                排期=<b>{{ $event->scheduled_publish_at ? $event->scheduled_publish_at->utc()->format('Y-m-d H:i') . ' UTC' : '—' }}</b>
            </p>
        </div>

        <a href="{{ route('admin.catalog.ecosystem.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回工作台</a>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if (in_array($event->disposition, ['withheld', 'retracted'], true))
        <div class="mb-3 rounded border px-4 py-3 text-sm {{ $event->disposition === 'retracted' ? 'border-red-300 bg-red-50 text-red-800' : 'border-amber-300 bg-amber-50 text-amber-900' }}">
            @if ($event->disposition === 'retracted')
                该活动已撤稿（终态）：任何发布 / 恢复 / 编辑都会被状态机拒绝。公开页返回 410。
            @else
                该活动已暂缓：公开页不可见。恢复会重新执行全部发布前置校验，不会自动恢复。
            @endif
        </div>
    @endif

    {{-- ── 元数据 ─────────────────────────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">活动元数据</p>
            <span class="text-xs text-gray-500">时间按活动时区（{{ $tz }}）填写；保存时转 UTC</span>
        </div>

        @if (bouncer()->hasPermission('ecosystem.events.edit'))
            <form method="POST" action="{{ route('admin.catalog.ecosystem.events.metadata', $event->id) }}" class="grid gap-3 md:grid-cols-3">
                @csrf
                <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">

                <label class="text-xs text-gray-600">
                    kind
                    <select name="kind" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_publishing.event_kinds', []) as $kind)
                            <option value="{{ $kind }}" @selected(old('kind', $event->kind) === $kind)>{{ $kind }}</option>
                        @endforeach
                    </select>
                    @error('kind') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    participation_mode
                    <select name="participation_mode" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        <option value="">（未填写）</option>
                        @foreach ((array) config('ecosystem_revisions.participation_modes', []) as $mode)
                            <option value="{{ $mode }}" @selected(old('participation_mode', $event->participation_mode) === $mode)>{{ $mode }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-xs text-gray-600">
                    hosting_status
                    <select name="hosting_status" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_publishing.review_allowed_hosting_statuses', []) as $status)
                            <option value="{{ $status }}" @selected(old('hosting_status', $event->hosting_status) === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                    @error('hosting_status') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    <span class="mt-1 block text-gray-500">
                        B-06 未签署：只允许 {{ implode(' / ', (array) config('ecosystem_publishing.review_allowed_hosting_statuses', [])) }}。
                        当前值 {{ $event->hosting_status ?: '—' }} 若不在其中，保存时必须改选。
                    </span>
                </label>

                <label class="text-xs text-gray-600">
                    timezone（IANA）
                    <input name="timezone" list="eco-tz-list" value="{{ old('timezone', $event->timezone) }}" maxlength="64" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('timezone') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    starts_at
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $localInput($event->starts_at)) }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('starts_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    ends_at
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $localInput($event->ends_at)) }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('ends_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    source_type
                    <select name="source_type" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_organizers.source_types', []) as $sourceType)
                            <option value="{{ $sourceType }}" @selected(old('source_type', $event->source_type) === $sourceType)>{{ $sourceType }}</option>
                        @endforeach
                    </select>
                    @error('source_type') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    platform_role
                    <select name="platform_role" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_organizers.platform_roles', []) as $role)
                            <option value="{{ $role }}" @selected(old('platform_role', $event->platform_role) === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                    @error('platform_role') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    organizer_id
                    <select name="organizer_id" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ($organizers as $organizer)
                            <option value="{{ $organizer->id }}" @selected((int) old('organizer_id', $event->organizer_id) === (int) $organizer->id)>
                                #{{ $organizer->id }} {{ $organizer->name }}（{{ $organizer->platform_role }} / {{ $organizer->status }}）
                            </option>
                        @endforeach
                    </select>
                    @error('organizer_id') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="flex items-end">
                    <button type="submit" class="primary-button">保存元数据</button>
                </div>
            </form>

            <datalist id="eco-tz-list">
                <option value="Asia/Shanghai"></option>
                <option value="Asia/Tokyo"></option>
                <option value="Europe/London"></option>
                <option value="America/New_York"></option>
                <option value="UTC"></option>
            </datalist>
        @else
            <p class="text-sm text-gray-500">当前账号没有编辑活动元数据的权限。</p>
        @endif

        <div class="mt-3 text-xs text-gray-500">
            活动快照来源：source_type={{ $event->source_type ?: '—' }} /
            platform_role={{ $event->platform_role ?: '—' }} /
            organizer={{ $event->organizerEntity?->name ?? '—' }}。
            修改来源或主办方后，已有 revision 的来源快照不会自动变化；发布前置会要求两者一致。
        </div>
    </div>

    {{-- ── 新建 revision ─────────────────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">新建活动 revision（草稿）</p>
            <span class="text-xs text-gray-500">若已有工作草稿或正在审核，服务会拒绝并给出原因</span>
        </div>

        @if (bouncer()->hasPermission('ecosystem.events.create'))
            @if ($event->organizer_id === null || $event->source_type === null || $event->platform_role === null)
                <div class="mb-3 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    活动尚未完整配置 source_type / platform_role / organizer_id；请先保存元数据。
                </div>
            @endif

            <form method="POST" action="{{ route('admin.catalog.ecosystem.events.revisions.store', $event->id) }}" class="grid gap-3 md:grid-cols-3">
                @csrf
                <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">

                <label class="text-xs text-gray-600 md:col-span-2">
                    活动名称
                    <input name="name" value="{{ old('name') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    participation_mode
                    <select name="participation_mode" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        <option value="">（未填写）</option>
                        @foreach ((array) config('ecosystem_revisions.participation_modes', []) as $mode)
                            <option value="{{ $mode }}" @selected(old('participation_mode') === $mode)>{{ $mode }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    摘要
                    <input name="summary" value="{{ old('summary') }}" maxlength="500" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    正文 HTML（服务端会净化；媒体 ID 列表暂不支持）
                    <textarea name="detail_html" rows="6" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('detail_html') }}</textarea>
                    @error('detail_html') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    城市
                    <input name="city" value="{{ old('city') }}" maxlength="100" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-2">
                    地点描述
                    <input name="location_text" value="{{ old('location_text') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600">
                    timezone（IANA）
                    <input name="timezone" list="eco-tz-list" value="{{ old('timezone', $tz) }}" maxlength="64" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('timezone') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    starts_at
                    <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('starts_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    ends_at
                    <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('ends_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    来源名称
                    <input name="source_name" value="{{ old('source_name') }}" maxlength="191" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('source_name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    来源 URL
                    <input name="source_url" value="{{ old('source_url') }}" maxlength="2048" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('source_url') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    来源核验时间（活动时区）
                    <input type="datetime-local" name="source_verified_at" value="{{ old('source_verified_at') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('source_verified_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    authorization_basis
                    <select name="authorization_basis" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_revisions.authorization_bases', []) as $basis)
                            <option value="{{ $basis }}" @selected(old('authorization_basis') === $basis)>{{ $basis }}</option>
                        @endforeach
                    </select>
                    @error('authorization_basis') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    授权依据引用（私有，永不公开）
                    <input name="authorization_evidence_ref" value="{{ old('authorization_evidence_ref') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600">
                    外部报名 URL（可选）
                    <input name="external_registration_url" value="{{ old('external_registration_url') }}" maxlength="2048" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('external_registration_url') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600 md:col-span-2">
                    变更说明（必填，500 字内）
                    <input name="change_summary" value="{{ old('change_summary') }}" maxlength="500" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('change_summary') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="flex items-end">
                    <button type="submit" class="primary-button">创建 revision</button>
                </div>
            </form>
        @else
            <p class="text-sm text-gray-500">当前账号没有创建活动 revision 的权限。</p>
        @endif
    </div>

    {{-- ── revision 列表 ─────────────────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white dark:bg-gray-900">
        <div class="border-b px-4 py-3 dark:border-gray-800">
            <p class="font-semibold text-gray-800 dark:text-white">Revision 列表</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">审核态</th>
                        <th class="px-4 py-3">变更说明</th>
                        <th class="px-4 py-3">指纹</th>
                        <th class="px-4 py-3">审核备注</th>
                        <th class="px-4 py-3">操作</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($revisions as $revision)
                        <tr class="border-b last:border-b-0 align-top dark:border-gray-800">
                            <td class="px-4 py-3 font-semibold">{{ $revision->revision_no }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">
                                {{ $revision->id }}
                                @if ((int) $event->published_revision_id === (int) $revision->id)
                                    <div class="text-green-700">当前公开</div>
                                @endif
                                @if ((int) $event->approved_revision_id === (int) $revision->id)
                                    <div class="text-blue-700">已批准候选</div>
                                @endif
                                @if ((int) $event->working_revision_id === (int) $revision->id)
                                    <div class="text-amber-700">工作草稿</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs {{ $revision->review_state === 'approved' ? 'bg-green-100 text-green-800' : ($revision->review_state === 'rejected' ? 'bg-red-100 text-red-800' : ($revision->review_state === 'in_review' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700')) }}">
                                    {{ $revision->review_state }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $revision->change_summary }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500" title="{{ $revision->content_fingerprint }}">
                                {{ substr((string) $revision->content_fingerprint, 0, 12) }}…
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $revision->review_note ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex min-w-[240px] flex-col gap-2">
                                    @if (bouncer()->hasPermission('ecosystem.events.view'))
                                        <a href="{{ route('admin.catalog.ecosystem.events.revisions.preview', [$event->id, $revision->id]) }}" target="_blank" class="text-blue-600 hover:underline text-xs">安全预览</a>
                                    @endif

                                    @if ($canSubmit && $revision->review_state === 'draft')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.events.revisions.submit', [$event->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <button type="submit" class="primary-button !py-1 !text-xs">提交审核</button>
                                        </form>
                                    @endif

                                    @if ($canReview && $revision->review_state === 'in_review')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.events.revisions.approve', [$event->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <input name="review_note" placeholder="批准备注（可选）" maxlength="5000" class="w-40 rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="primary-button !py-1 !text-xs">批准</button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.events.revisions.reject', [$event->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <input name="reason" required placeholder="拒绝原因（必填）" maxlength="5000" class="w-40 rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="transparent-button !py-1 !text-xs">拒绝</button>
                                        </form>
                                    @endif

                                    @if ($canSchedule && $revision->review_state === 'approved')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.events.revisions.schedule', [$event->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <input type="datetime-local" name="scheduled_publish_at" required title="按北京时间填写" class="w-44 rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="transparent-button !py-1 !text-xs">排期</button>
                                        </form>
                                    @endif

                                    @if ($canPublish && $revision->review_state === 'approved')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.events.revisions.publish', [$event->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <button type="submit" class="primary-button !py-1 !text-xs">立即发布</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500">还没有 revision；先创建一条草稿。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ── 处置动作 ─────────────────────────────────────────── --}}
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 font-semibold text-gray-800 dark:text-white">发布处置</p>
        <p class="mb-3 text-xs text-gray-500">
            暂缓 / 撤稿原因必填，但只写内部日志与稳定审计原因码，不进入公开字段。
            撤稿是终态；恢复必须重新执行全部发布前置校验。
        </p>

        <div class="flex flex-wrap items-end gap-4">
            @if ($canSchedule && $event->scheduled_publish_at)
                <form method="POST" action="{{ route('admin.catalog.ecosystem.events.unschedule', $event->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                    <button type="submit" class="transparent-button">取消排期</button>
                </form>
            @endif

            @if ($canWithhold && $event->publish_status === 'published' && $event->disposition === 'normal')
                <form method="POST" action="{{ route('admin.catalog.ecosystem.events.withhold', $event->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                    <input name="reason" required placeholder="暂缓原因（必填）" maxlength="500" class="w-56 rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <button type="submit" class="transparent-button">暂缓公开</button>
                </form>
            @endif

            @if ($canWithhold && $event->disposition === 'withheld')
                <form method="POST" action="{{ route('admin.catalog.ecosystem.events.restore', $event->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                    <button type="submit" class="primary-button">恢复公开</button>
                </form>
            @endif

            @if ($canRetract && $event->disposition !== 'retracted')
                <form method="POST" action="{{ route('admin.catalog.ecosystem.events.retract', $event->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $event->row_version }}">
                    <input name="reason" required placeholder="撤稿原因（必填）" maxlength="500" class="w-56 rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <button type="submit" class="transparent-button">撤稿（终态）</button>
                </form>
            @endif

            @unless ($canSchedule || $canWithhold || $canRetract)
                <p class="text-sm text-gray-500">当前账号没有发布处置权限。</p>
            @endunless
        </div>
    </div>
</x-admin::layouts>
