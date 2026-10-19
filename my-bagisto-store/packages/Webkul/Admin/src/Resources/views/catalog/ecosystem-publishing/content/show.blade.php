<x-admin::layouts>
    <x-slot:title>资讯工作页</x-slot:title>

    @php
        $canSubmit = bouncer()->hasPermission('ecosystem.content.submit');
        $canReview = bouncer()->hasPermission('ecosystem.content.review');
        $canSchedule = bouncer()->hasPermission('ecosystem.content.schedule');
        $canPublish = bouncer()->hasPermission('ecosystem.content.publish');
        $canWithhold = bouncer()->hasPermission('ecosystem.content.withhold');
        $canRetract = bouncer()->hasPermission('ecosystem.content.retract');
        $canEditMediaRefs = bouncer()->hasPermission('ecosystem.content.edit');
        $canReviewMediaRefs = bouncer()->hasPermission('ecosystem.media.review');
        $revisions = $post->revisions;
        $draftRevisions = $revisions->where('review_state', 'draft');
        $latestDraft = $draftRevisions->sortByDesc('revision_no')->first();
        $draftSources = $latestDraft
            ? $latestDraft->sources->sortBy('sort_order')->values()
            : collect();
        // 后台时间输入统一按应用时区（北京时间）回显；保存时再转 UTC。
        $appLocalInput = fn ($value) => $value
            ? \Illuminate\Support\Carbon::parse($value)->timezone(config('app.timezone', 'Asia/Shanghai'))->format('Y-m-d\TH:i')
            : '';
        $mediaRoles = (array) config('ecosystem_media.roles', []);
    @endphp

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">{{ $post->slug }} <span class="text-sm text-gray-500">#{{ $post->id }}</span></p>
            <p class="text-xs text-gray-500">
                type=<b>{{ $post->type }}</b>
                publish_status=<b>{{ $post->publish_status }}</b>
                disposition=<b>{{ $post->disposition }}</b>
                row_version=<b>{{ $post->row_version }}</b>
                公开 revision=<b>{{ $post->published_revision_id ?? '—' }}</b>
                候选 revision=<b>{{ $post->approved_revision_id ?? '—' }}</b>
                排期=<b>{{ $post->scheduled_publish_at ? $post->scheduled_publish_at->utc()->format('Y-m-d H:i') . ' UTC' : '—' }}</b>
            </p>
        </div>

        <div class="flex items-center gap-x-2.5">
            <a href="{{ route('admin.catalog.ecosystem.content.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">文章列表</a>
            <a href="{{ route('admin.catalog.ecosystem.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回工作台</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if (in_array($post->disposition, ['withheld', 'retracted'], true))
        <div class="mb-3 rounded border px-4 py-3 text-sm {{ $post->disposition === 'retracted' ? 'border-red-300 bg-red-50 text-red-800' : 'border-amber-300 bg-amber-50 text-amber-900' }}">
            @if ($post->disposition === 'retracted')
                该文章已撤稿（终态）：任何发布 / 恢复 / 编辑都会被状态机拒绝。公开页返回 410。
            @else
                该文章已暂缓：公开页不可见。恢复会重新执行全部发布前置校验，不会自动恢复。
            @endif
        </div>
    @endif

    {{-- ── 文章元数据 ─────────────────────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">文章元数据（type / slug / category）</p>
            <span class="text-xs text-gray-500">只有 draft 文章可编辑；首次发布后 slug 冻结</span>
        </div>

        @if (bouncer()->hasPermission('ecosystem.content.edit'))
            <form method="POST" action="{{ route('admin.catalog.ecosystem.content.metadata', $post->id) }}" class="grid gap-3 md:grid-cols-4">
                @csrf
                <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">

                <label class="text-xs text-gray-600">
                    type
                    <select name="type" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_content.content_types', []) as $type)
                            <option value="{{ $type }}" @selected(old('type', $post->type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    @error('type') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600 md:col-span-2">
                    slug（小写 ASCII / 数字 / 连字符）
                    <input name="slug" value="{{ old('slug', $post->slug) }}" maxlength="120" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('slug') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    category（可选）
                    <input name="category" value="{{ old('category', $post->category) }}" maxlength="64" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('category') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="md:col-span-4 flex items-center gap-3">
                    <button type="submit" class="primary-button">保存元数据</button>
                    <span class="text-xs text-gray-500">
                        @if ($post->first_published_at)
                            该文章已首次发布（{{ $post->first_published_at->utc()->format('Y-m-d H:i') }} UTC），slug 不可再修改。
                        @else
                            slug 全局唯一；撤稿后也不复用。
                        @endif
                    </span>
                </div>
            </form>
        @else
            <p class="text-sm text-gray-500">当前账号没有编辑文章元数据的权限。</p>
        @endif
    </div>

    {{-- ── 新建 revision ─────────────────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">新建资讯 revision（草稿）</p>
            <span class="text-xs text-gray-500">支持 1~N 条来源；空行会被忽略，至少保留一条</span>
        </div>

        @if (bouncer()->hasPermission('ecosystem.content.edit'))
            <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.store', $post->id) }}" class="grid gap-3 md:grid-cols-3">
                @csrf
                <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">

                <label class="text-xs text-gray-600 md:col-span-2">
                    标题
                    <input name="title" value="{{ old('title') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('title') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    source_type
                    <select name="source_type" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_content.source_types', []) as $type)
                            <option value="{{ $type }}" @selected(old('source_type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    @error('source_type') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    摘要
                    <input name="summary" value="{{ old('summary') }}" maxlength="500" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    正文 HTML（服务端会净化；link_summary 必须留空）
                    <textarea name="body_html" rows="6" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('body_html') }}</textarea>
                    @error('body_html') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    作者
                    <input name="author_name" value="{{ old('author_name') }}" maxlength="191" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('author_name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    作者机构
                    <input name="author_org" value="{{ old('author_org') }}" maxlength="191" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600">
                    原文发布时间（北京时间）
                    <input type="datetime-local" name="original_published_at" value="{{ old('original_published_at') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    更正说明（公开）
                    <textarea name="correction_note" rows="2" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('correction_note') }}</textarea>
                </label>

                <div class="md:col-span-3 mt-2 border-t pt-3 dark:border-gray-800">
                    <p class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                        来源（1~N 条；全空行忽略，至少一条）
                    </p>
                    <p class="mb-2 text-xs text-gray-500">
                        authorized_repost 必须为每条来源填写 rights_scope 与 evidence_ref；
                        核验时间按北京时间填写，且不得晚于当前。
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-gray-600 dark:text-gray-300">
                                <tr>
                                    <th class="px-1 py-1">#</th>
                                    <th class="px-1 py-1">来源名称</th>
                                    <th class="px-1 py-1">来源 URL</th>
                                    <th class="px-1 py-1">核验时间</th>
                                    <th class="px-1 py-1">rights_basis</th>
                                    <th class="px-1 py-1">rights_scope</th>
                                    <th class="px-1 py-1">evidence_ref（私有）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @for ($i = 0; $i < 5; $i++)
                                    <tr>
                                        <td class="px-1 py-1 text-gray-500">{{ $i + 1 }}</td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][source_name]" value="{{ old('sources.'.$i.'.source_name') }}" maxlength="191" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][source_url]" value="{{ old('sources.'.$i.'.source_url') }}" maxlength="2048" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <input type="datetime-local" name="sources[{{ $i }}][source_verified_at]" value="{{ old('sources.'.$i.'.source_verified_at') }}" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <select name="sources[{{ $i }}][rights_basis]" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                                <option value="">（未填写）</option>
                                                @foreach ((array) config('ecosystem_content.rights_bases', []) as $basis)
                                                    <option value="{{ $basis }}" @selected(old('sources.'.$i.'.rights_basis') === $basis)>{{ $basis }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][rights_scope]" value="{{ old('sources.'.$i.'.rights_scope') }}" maxlength="255" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][evidence_ref]" value="{{ old('sources.'.$i.'.evidence_ref') }}" maxlength="255" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>

                    @error('sources') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    @foreach ($errors->get('sources.*') as $messages)
                        @foreach ($messages as $message)
                            <span class="mt-1 block text-red-600">{{ $message }}</span>
                        @endforeach
                    @endforeach
                </div>

                <label class="text-xs text-gray-600 md:col-span-2">
                    变更说明（必填）
                    <input name="change_summary" value="{{ old('change_summary') }}" maxlength="500" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('change_summary') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="flex items-end">
                    <button type="submit" class="primary-button">创建 revision</button>
                </div>
            </form>
        @else
            <p class="text-sm text-gray-500">当前账号没有创建资讯 revision 的权限。</p>
        @endif
    </div>

    {{-- ── 编辑草稿（仅最新 draft） ───────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">编辑草稿（最新 draft）</p>
            <span class="text-xs text-gray-500">
                只有 draft 可编辑；提交审核 / 批准 / 拒绝后不可再改。保存时按指纹校验，不会覆盖他人修改。
            </span>
        </div>

        @if (! $latestDraft)
            <p class="text-sm text-gray-500">当前没有 draft revision；创建草稿后才能二次编辑。</p>
        @elseif (! bouncer()->hasPermission('ecosystem.content.edit'))
            <p class="text-sm text-gray-500">当前账号没有编辑资讯 revision 的权限。</p>
        @else
            <p class="mb-3 text-xs text-gray-500">
                Revision #{{ $latestDraft->revision_no }}（ID {{ $latestDraft->id }}），
                当前指纹 <code title="{{ $latestDraft->content_fingerprint }}">{{ substr((string) $latestDraft->content_fingerprint, 0, 12) }}…</code>。
                来源为整组替换：表格中的 1~N 行会覆盖原有来源；媒体引用在下方单独维护，编辑正文不会清空。
            </p>

            <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.update', [$post->id, $latestDraft->id]) }}" class="grid gap-3 md:grid-cols-3">
                @csrf
                <input type="hidden" name="expected_fingerprint" value="{{ $latestDraft->content_fingerprint }}">

                <label class="text-xs text-gray-600 md:col-span-2">
                    标题
                    <input name="title" value="{{ old('title', $latestDraft->title) }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('title') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    source_type
                    <select name="source_type" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_content.source_types', []) as $type)
                            <option value="{{ $type }}" @selected(old('source_type', $latestDraft->source_type) === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                    @error('source_type') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    摘要
                    <input name="summary" value="{{ old('summary', $latestDraft->summary) }}" maxlength="500" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    正文 HTML（服务端会净化；link_summary 必须留空）
                    <textarea name="body_html" rows="6" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('body_html', $latestDraft->body_html) }}</textarea>
                    @error('body_html') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    作者
                    <input name="author_name" value="{{ old('author_name', $latestDraft->author_name) }}" maxlength="191" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('author_name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    作者机构
                    <input name="author_org" value="{{ old('author_org', $latestDraft->author_org) }}" maxlength="191" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600">
                    原文发布时间（北京时间）
                    <input type="datetime-local" name="original_published_at" value="{{ old('original_published_at', $appLocalInput($latestDraft->original_published_at)) }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-3">
                    更正说明（公开）
                    <textarea name="correction_note" rows="2" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('correction_note', $latestDraft->correction_note) }}</textarea>
                </label>

                <div class="md:col-span-3 mt-2 border-t pt-3 dark:border-gray-800">
                    <p class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                        来源（整组替换：1~N 条；全空行忽略，至少一条）
                    </p>
                    <p class="mb-2 text-xs text-gray-500">
                        authorized_repost 必须为每条来源填写 rights_scope 与 evidence_ref；
                        核验时间按北京时间填写，且不得晚于当前。
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="text-left text-gray-600 dark:text-gray-300">
                                <tr>
                                    <th class="px-1 py-1">#</th>
                                    <th class="px-1 py-1">来源名称</th>
                                    <th class="px-1 py-1">来源 URL</th>
                                    <th class="px-1 py-1">核验时间</th>
                                    <th class="px-1 py-1">rights_basis</th>
                                    <th class="px-1 py-1">rights_scope</th>
                                    <th class="px-1 py-1">evidence_ref（私有）</th>
                                </tr>
                            </thead>
                            <tbody>
                                @for ($i = 0; $i < 5; $i++)
                                    @php $source = $draftSources[$i] ?? null; @endphp
                                    <tr>
                                        <td class="px-1 py-1 text-gray-500">{{ $i + 1 }}</td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][source_name]" value="{{ old('sources.'.$i.'.source_name', $source?->source_name) }}" maxlength="191" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][source_url]" value="{{ old('sources.'.$i.'.source_url', $source?->source_url) }}" maxlength="2048" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <input type="datetime-local" name="sources[{{ $i }}][source_verified_at]" value="{{ old('sources.'.$i.'.source_verified_at', $appLocalInput($source?->source_verified_at)) }}" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <select name="sources[{{ $i }}][rights_basis]" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                                <option value="">（未填写）</option>
                                                @foreach ((array) config('ecosystem_content.rights_bases', []) as $basis)
                                                    <option value="{{ $basis }}" @selected(old('sources.'.$i.'.rights_basis', $source?->rights_basis) === $basis)>{{ $basis }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][rights_scope]" value="{{ old('sources.'.$i.'.rights_scope', $source?->rights_scope) }}" maxlength="255" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                        <td class="px-1 py-1">
                                            <input name="sources[{{ $i }}][evidence_ref]" value="{{ old('sources.'.$i.'.evidence_ref', $source?->evidence_ref) }}" maxlength="255" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                        </td>
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>

                    @error('sources') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                    @foreach ($errors->get('sources.*') as $messages)
                        @foreach ($messages as $message)
                            <span class="mt-1 block text-red-600">{{ $message }}</span>
                        @endforeach
                    @endforeach
                </div>

                <label class="text-xs text-gray-600 md:col-span-2">
                    变更说明（必填）
                    <input name="change_summary" value="{{ old('change_summary', $latestDraft->change_summary) }}" maxlength="500" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('change_summary') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="flex items-end">
                    <button type="submit" class="primary-button">保存草稿</button>
                </div>
            </form>
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
                        <th class="px-4 py-3">标题 / 来源</th>
                        <th class="px-4 py-3">变更说明</th>
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
                                @if ((int) $post->published_revision_id === (int) $revision->id)
                                    <div class="text-green-700">当前公开</div>
                                @endif
                                @if ((int) $post->approved_revision_id === (int) $revision->id)
                                    <div class="text-blue-700">已批准候选</div>
                                @endif
                                @if ((int) $post->working_revision_id === (int) $revision->id)
                                    <div class="text-amber-700">工作草稿</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded px-2 py-0.5 text-xs {{ $revision->review_state === 'approved' ? 'bg-green-100 text-green-800' : ($revision->review_state === 'rejected' ? 'bg-red-100 text-red-800' : ($revision->review_state === 'in_review' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-700')) }}">
                                    {{ $revision->review_state }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="font-medium text-gray-800 dark:text-white">{{ $revision->title }}</div>
                                @foreach ($revision->sources as $source)
                                    <div class="mt-1 text-gray-500">
                                        {{ $source->source_name }} · {{ $source->rights_basis }}
                                        @if ($source->rights_scope) · {{ $source->rights_scope }} @endif
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3 text-xs">{{ $revision->change_summary }}</td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ $revision->review_note ?: '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex min-w-[240px] flex-col gap-2">
                                    @if (bouncer()->hasPermission('ecosystem.content.view'))
                                        <a href="{{ route('admin.catalog.ecosystem.content.revisions.preview', [$post->id, $revision->id]) }}" target="_blank" class="text-blue-600 hover:underline text-xs">安全预览</a>
                                    @endif

                                    @if ($canSubmit && $revision->review_state === 'draft')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.submit', [$post->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <button type="submit" class="primary-button !py-1 !text-xs">提交审核</button>
                                        </form>
                                    @endif

                                    @if ($canReview && $revision->review_state === 'in_review')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.approve', [$post->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <input name="review_note" placeholder="批准备注（可选）" maxlength="5000" class="w-40 rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="primary-button !py-1 !text-xs">批准</button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.reject', [$post->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <input name="reason" required placeholder="拒绝原因（必填）" maxlength="5000" class="w-40 rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="transparent-button !py-1 !text-xs">拒绝</button>
                                        </form>
                                    @endif

                                    @if ($canSchedule && $revision->review_state === 'approved')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.schedule', [$post->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                                            <input type="hidden" name="expected_revision_id" value="{{ $revision->id }}">
                                            <input type="datetime-local" name="scheduled_publish_at" required title="按北京时间填写" class="w-44 rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="transparent-button !py-1 !text-xs">排期</button>
                                        </form>
                                    @endif

                                    @if ($canPublish && $revision->review_state === 'approved')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.publish', [$post->id, $revision->id]) }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
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

    {{-- ── 媒体引用（仅 draft） ────────────────────────────────── --}}
    <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <div class="mb-3 flex items-center justify-between">
            <p class="font-semibold text-gray-800 dark:text-white">Revision 媒体引用（仅 draft 可编辑）</p>
            <span class="text-xs text-gray-500">
                只展示元数据，不展示 storage_key / 私有路径。引用审核通过后才能提审。
            </span>
        </div>

        @if ($draftRevisions->isEmpty())
            <p class="text-sm text-gray-500">当前没有 draft revision；创建草稿后才能挂媒体。</p>
        @elseif ($approvedMedia->isEmpty())
            <p class="mb-3 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                还没有 approved 媒体。请先到
                @if (bouncer()->hasPermission('ecosystem.media.view'))
                    <a href="{{ route('admin.catalog.ecosystem.media.index') }}" class="text-blue-600 hover:underline">媒体工作台</a>
                @else
                    媒体工作台
                @endif
                上传并审核通过后再添加引用。
            </p>
        @endif

        @foreach ($draftRevisions as $revision)
            <div class="mb-5 rounded border p-3 dark:border-gray-800">
                <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">
                    Revision #{{ $revision->revision_no }}（ID {{ $revision->id }}）
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="px-2 py-2">media_id</th>
                                <th class="px-2 py-2">媒体</th>
                                <th class="px-2 py-2">role</th>
                                <th class="px-2 py-2">alt</th>
                                <th class="px-2 py-2">媒体状态</th>
                                <th class="px-2 py-2">引用审核</th>
                                <th class="px-2 py-2">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($revision->mediaRefs as $ref)
                                <tr class="border-b last:border-b-0 dark:border-gray-800">
                                    <td class="px-2 py-2">{{ $ref->media_id }}</td>
                                    <td class="px-2 py-2">
                                        {{ $ref->media?->original_name ?: '（媒体 #'.$ref->media_id.'）' }}
                                        <div class="text-gray-500">{{ $ref->media?->mime ?: '—' }}</div>
                                    </td>
                                    <td class="px-2 py-2">{{ $ref->role }}</td>
                                    <td class="px-2 py-2">{{ $ref->alt_text ?: '—' }}</td>
                                    <td class="px-2 py-2">{{ $ref->media?->status ?: 'missing' }}</td>
                                    <td class="px-2 py-2">
                                        <span class="rounded px-2 py-0.5 {{ $ref->review_status === 'approved' ? 'bg-green-100 text-green-800' : ($ref->review_status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
                                            {{ $ref->review_status }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-2">
                                        @if ($canReviewMediaRefs && $ref->review_status === 'pending')
                                            <div class="flex gap-2">
                                                <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.media_refs.review', [$post->id, $revision->id, $ref->id]) }}">
                                                    @csrf
                                                    <input type="hidden" name="expected_review_status" value="{{ $ref->review_status }}">
                                                    <input type="hidden" name="status" value="approved">
                                                    <button type="submit" class="primary-button !py-1 !text-xs">批准引用</button>
                                                </form>
                                                <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.media_refs.review', [$post->id, $revision->id, $ref->id]) }}">
                                                    @csrf
                                                    <input type="hidden" name="expected_review_status" value="{{ $ref->review_status }}">
                                                    <input type="hidden" name="status" value="rejected">
                                                    <button type="submit" class="transparent-button !py-1 !text-xs">驳回引用</button>
                                                </form>
                                            </div>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-2 py-4 text-center text-gray-500">还没有媒体引用。</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($canEditMediaRefs)
                    <form method="POST" action="{{ route('admin.catalog.ecosystem.content.revisions.media_refs.store', [$post->id, $revision->id]) }}" class="mt-3">
                        @csrf
                        <p class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">
                            整组替换引用（最多 {{ (int) config('ecosystem_media.max_refs_per_revision', 20) }} 条；按行顺序展示）
                        </p>

                        <div class="grid gap-2 md:grid-cols-5">
                            @for ($i = 0; $i < 5; $i++)
                                <div class="rounded border p-2 dark:border-gray-800">
                                    <label class="block text-[11px] text-gray-500">
                                        媒体
                                        <select name="refs[{{ $i }}][media_id]" class="mt-1 w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <option value="">（空行）</option>
                                            @foreach ($approvedMedia as $item)
                                                <option value="{{ $item->id }}">#{{ $item->id }} {{ $item->original_name }} · {{ $item->mime }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="mt-1 block text-[11px] text-gray-500">
                                        role
                                        <select name="refs[{{ $i }}][role]" class="mt-1 w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            @foreach ($mediaRoles as $role)
                                                <option value="{{ $role }}" @selected($role === 'cover')>{{ $role }}</option>
                                            @endforeach
                                        </select>
                                    </label>

                                    <label class="mt-1 block text-[11px] text-gray-500">
                                        alt
                                        <input name="refs[{{ $i }}][alt_text]" maxlength="255" class="mt-1 w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                    </label>
                                </div>
                            @endfor
                        </div>

                        <button type="submit" class="primary-button mt-2">保存媒体引用</button>
                        <span class="ml-2 text-xs text-gray-500">
                            替换会重算指纹；提交后必须重新审核，旧批准不会继承。
                        </span>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ── 处置动作 ─────────────────────────────────────────── --}}
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-3 font-semibold text-gray-800 dark:text-white">发布处置</p>
        <p class="mb-3 text-xs text-gray-500">
            暂缓 / 撤稿原因必填，但只写内部日志与稳定审计原因码，不进入公开字段。
            撤稿是终态；恢复必须重新执行全部发布前置校验。
        </p>

        <div class="flex flex-wrap items-end gap-4">
            @if ($canSchedule && $post->scheduled_publish_at)
                <form method="POST" action="{{ route('admin.catalog.ecosystem.content.unschedule', $post->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                    <button type="submit" class="transparent-button">取消排期</button>
                </form>
            @endif

            @if ($canWithhold && $post->publish_status === 'published' && $post->disposition === 'normal')
                <form method="POST" action="{{ route('admin.catalog.ecosystem.content.withhold', $post->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                    <input name="reason" required placeholder="暂缓原因（必填）" maxlength="500" class="w-56 rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <button type="submit" class="transparent-button">暂缓公开</button>
                </form>
            @endif

            @if ($canWithhold && $post->disposition === 'withheld')
                <form method="POST" action="{{ route('admin.catalog.ecosystem.content.restore', $post->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
                    <button type="submit" class="primary-button">恢复公开</button>
                </form>
            @endif

            @if ($canRetract && $post->disposition !== 'retracted')
                <form method="POST" action="{{ route('admin.catalog.ecosystem.content.retract', $post->id) }}" class="flex items-end gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $post->row_version }}">
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
