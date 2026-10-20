<x-admin::layouts>
    <x-slot:title>赛程 · {{ $track->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">赛程 · {{ $track->name }}</p>
            <p class="text-xs text-gray-500">
                {{ $track->edition?->event?->name }} / {{ $track->edition?->name }} · {{ $track->track_key }}
                · 状态 {{ $track->status }}
            </p>
        </div>
        <a href="{{ route('admin.catalog.ecosystem.competition.index') }}" class="transparent-button">返回赛务</a>
    </div>

    <x-admin::flash />

    <div class="mb-6 rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">CSV 预览导入</p>
        <p class="mb-3 text-xs text-gray-500">
            必须带时区偏移（如 2026-11-01T09:00:00+08:00）。预览不写库；确认导入需要同一文件。
            已发布阶段/场次不会被覆盖。
        </p>
        <div class="flex flex-wrap items-center gap-2">
            <input id="csv-file" type="file" accept=".csv,text/csv" class="rounded border px-3 py-2 text-sm">
            <button id="csv-preview" class="secondary-button" type="button">预览</button>
            <button id="csv-import" class="primary-button hidden" type="button">确认导入</button>
        </div>
        <p id="csv-message" class="mt-2 text-xs"></p>
        <div id="csv-result" class="mt-3 max-h-96 overflow-auto"></div>
    </div>

    <div class="mb-6 rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">阶段（{{ $stages->count() }}）</p>
            <span class="text-xs text-gray-500">发布阶段会把该阶段全部未取消场次一并公开</span>
        </div>
        @forelse ($stages as $stage)
            <div class="mb-3 rounded border px-3 py-2 dark:border-gray-800">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="font-medium">{{ $stage->name }} <span class="text-xs text-gray-500">{{ $stage->stage_key }}</span></p>
                        <p class="text-xs text-gray-500">顺序 {{ $stage->sort_order }} · 状态 {{ $stage->status }} · v{{ $stage->row_version }}</p>
                    </div>
                    @if ($stage->status !== 'published' && bouncer()->hasPermission('ecosystem.competition.publish'))
                        <button
                            type="button"
                            class="primary-button text-xs publish-stage"
                            data-url="{{ route('admin.catalog.ecosystem.competition.stages.publish', $stage->id) }}"
                            data-version="{{ $stage->row_version }}"
                        >发布阶段</button>
                    @endif
                </div>
                <table class="mt-2 w-full text-xs">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-1">场次</th>
                            <th class="py-1">轮次</th>
                            <th class="py-1">时间（UTC 存储）</th>
                            <th class="py-1">场地</th>
                            <th class="py-1">对阵</th>
                            <th class="py-1">状态</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matches->where('stage_id', $stage->id) as $match)
                            <tr class="border-t dark:border-gray-800">
                                <td class="py-1">{{ $match->match_key }}</td>
                                <td class="py-1">{{ $match->round_no }}</td>
                                <td class="py-1">{{ $match->starts_at?->toIso8601String() }} → {{ $match->ends_at?->toIso8601String() }}</td>
                                <td class="py-1">{{ $match->venue ?: '-' }}</td>
                                <td class="py-1">
                                    {{ $entries->firstWhere('id', $match->home_entry_id)?->team?->name ?? '待定' }}
                                    vs
                                    {{ $entries->firstWhere('id', $match->away_entry_id)?->team?->name ?? '待定' }}
                                </td>
                                <td class="py-1">{{ $match->status }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-sm text-gray-500">还没有阶段。先用 CSV 预览导入。</p>
        @endforelse
    </div>

    <div class="rounded bg-white p-4 shadow-sm dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">已确认参赛队伍</p>
        @if ($entries->isEmpty())
            <p class="text-sm text-gray-500">没有已确认的参赛条目。队伍报名并通过审核后才会出现在这里。</p>
        @else
            <ul class="grid gap-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($entries as $entry)
                    <li>{{ $entry->entry_no }} · {{ $entry->team?->name }}（{{ count((array) $entry->roster_snapshot) }} 人）</li>
                @endforeach
            </ul>
        @endif
    </div>

    <script>
        (function () {
            const csrf = '{{ csrf_token() }}';
            const previewBtn = document.getElementById('csv-preview');
            const importBtn = document.getElementById('csv-import');
            const fileInput = document.getElementById('csv-file');
            const message = document.getElementById('csv-message');
            const result = document.getElementById('csv-result');
            let previewHash = null;
            let previewFile = null;

            previewBtn.addEventListener('click', async () => {
                const file = fileInput.files[0];
                if (!file) { message.textContent = '请选择 CSV 文件。'; return; }

                const form = new FormData();
                form.append('file', file);

                message.textContent = '预览中…';
                const response = await fetch('{{ route('admin.catalog.ecosystem.competition.schedule.preview', $track->id) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: form,
                });
                const json = await response.json();

                if (!response.ok || !json.success) {
                    message.textContent = json.message || '预览失败。';
                    result.innerHTML = '';
                    importBtn.classList.add('hidden');
                    return;
                }

                previewHash = json.data.preview_hash;
                previewFile = file;
                const s = json.data.summary;
                message.textContent = `共 ${s.total} 行：有效 ${s.valid}，错误 ${s.errors}，新建 ${s.creates}，更新 ${s.updates}，跳过 ${s.skips}`;
                importBtn.classList.toggle('hidden', s.errors > 0);
                result.innerHTML = renderRows(json.data.rows);
            });

            importBtn.addEventListener('click', async () => {
                if (!previewFile || !previewHash) return;

                const form = new FormData();
                form.append('file', previewFile);
                form.append('preview_hash', previewHash);

                importBtn.disabled = true;
                message.textContent = '导入中…';
                const response = await fetch('{{ route('admin.catalog.ecosystem.competition.schedule.import', $track->id) }}', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: form,
                });
                const json = await response.json();
                importBtn.disabled = false;

                if (!response.ok || !json.success) {
                    message.textContent = json.message || '导入失败。';
                    if (json.details && json.details.rows) result.innerHTML = renderRows(json.details.rows);
                    return;
                }

                message.textContent = '导入成功，刷新页面查看阶段与场次。';
                window.location.reload();
            });

            document.querySelectorAll('.publish-stage').forEach((button) => {
                button.addEventListener('click', async () => {
                    if (!confirm('发布后该阶段场次将对公众可见，确认？')) return;

                    const form = new FormData();
                    form.append('expected_row_version', button.dataset.version);
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: form,
                    });
                    const json = await response.json();

                    if (!response.ok || !json.success) { alert(json.message || '发布失败。'); return; }
                    window.location.reload();
                });
            });

            function escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            }

            function renderRows(rows) {
                if (!rows || !rows.length) return '';
                const body = rows.map((row) => {
                    const errors = (row.errors || []).map(escapeHtml).join('<br>');
                    return `<tr class="border-t dark:border-gray-800">
                        <td class="py-1">${row.row_no}</td>
                        <td class="py-1">${escapeHtml(row.status)}</td>
                        <td class="py-1">${escapeHtml(row.action || '')}</td>
                        <td class="py-1">${escapeHtml(row.data?.stage_key)} / ${escapeHtml(row.data?.match_key)}</td>
                        <td class="py-1">${escapeHtml(row.data?.starts_at)} → ${escapeHtml(row.data?.ends_at)}</td>
                        <td class="py-1 text-red-600">${errors}</td>
                    </tr>`;
                }).join('');

                return `<table class="w-full text-xs">
                    <thead class="text-left text-gray-500"><tr><th class="py-1">行</th><th>状态</th><th>动作</th><th>阶段/场次</th><th>时间</th><th>错误</th></tr></thead>
                    <tbody>${body}</tbody></table>`;
            }
        })();
    </script>
</x-admin::layouts>
