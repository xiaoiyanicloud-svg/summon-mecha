<x-admin::layouts>
    <x-slot:title>生态主办方</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">生态主办方</p>
        <a href="{{ route('admin.catalog.ecosystem.index') }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回工作台</a>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if (bouncer()->hasPermission('ecosystem.organizers.manage'))
        <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">新建主办方（一律 pending）</p>

            <form method="POST" action="{{ route('admin.catalog.ecosystem.organizers.store') }}" class="grid gap-3 md:grid-cols-2">
                @csrf

                <label class="text-xs text-gray-600">
                    名称
                    <input name="name" value="{{ old('name') }}" maxlength="191" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    平台角色
                    <select name="platform_role" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ((array) config('ecosystem_organizers.platform_roles', []) as $role)
                            <option value="{{ $role }}" @selected(old('platform_role') === $role)>{{ $role }}</option>
                        @endforeach
                    </select>
                    @error('platform_role') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    官方来源 URL（发布前置）
                    <input name="source_url" value="{{ old('source_url') }}" maxlength="2048" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @error('source_url') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="text-xs text-gray-600">
                    私有依据引用（永不公开）
                    <input name="private_evidence_ref" value="{{ old('private_evidence_ref') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-2">
                    公开资料
                    <textarea name="public_materials" rows="3" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('public_materials') }}</textarea>
                </label>

                <label class="text-xs text-gray-600 md:col-span-2">
                    核验说明（可选）
                    <textarea name="verification_note" rows="2" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('verification_note') }}</textarea>
                </label>

                <div class="md:col-span-2">
                    <button type="submit" class="primary-button">创建主办方</button>
                </div>
            </form>
        </div>
    @endif

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">ID</th>
                    <th class="px-4 py-3">名称</th>
                    <th class="px-4 py-3">平台角色</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">核验人 / 时间</th>
                    <th class="px-4 py-3">核验 / 驳回 / 暂停</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($organizers as $organizer)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $organizer->id }}</td>
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">
                            {{ $organizer->name }}
                            <div class="text-xs text-gray-500">{{ $organizer->source_url ?: '未提供官方来源 URL' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ $organizer->platform_role }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs {{ $organizer->status === 'verified' ? 'bg-green-100 text-green-800' : ($organizer->status === 'pending' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
                                {{ $organizer->status }}
                            </span>
                            @if ($organizer->verification_note)
                                <div class="mt-1 text-xs text-gray-500">{{ $organizer->verification_note }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs">
                            {{ $organizer->verified_by ? '#'.$organizer->verified_by : '—' }}
                            <div class="text-gray-500">{{ $organizer->verified_at ? $organizer->verified_at->utc()->format('Y-m-d H:i') : '—' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if (bouncer()->hasPermission('ecosystem.organizers.verify'))
                                <div class="flex flex-col gap-2">
                                    @if (in_array($organizer->status, ['pending', 'rejected', 'suspended'], true))
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.organizers.verify', $organizer->id) }}" class="flex flex-col gap-1">
                                            @csrf
                                            <input type="hidden" name="expected_status" value="{{ $organizer->status }}">
                                            <input name="verification_note" placeholder="核验说明（可选）" maxlength="5000" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="primary-button !py-1 !text-xs">核验通过</button>
                                        </form>
                                    @endif

                                    @if ($organizer->status === 'pending')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.organizers.reject', $organizer->id) }}" class="flex flex-col gap-1">
                                            @csrf
                                            <input type="hidden" name="expected_status" value="{{ $organizer->status }}">
                                            <input name="reason" required placeholder="驳回原因（必填）" maxlength="5000" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="transparent-button !py-1 !text-xs">驳回</button>
                                        </form>
                                    @endif

                                    @if ($organizer->status === 'verified')
                                        <form method="POST" action="{{ route('admin.catalog.ecosystem.organizers.suspend', $organizer->id) }}" class="flex flex-col gap-1">
                                            @csrf
                                            <input type="hidden" name="expected_status" value="{{ $organizer->status }}">
                                            <input name="reason" required placeholder="暂停原因（必填）" maxlength="5000" class="w-full rounded-md border px-2 py-1 text-xs dark:border-gray-800 dark:bg-gray-900">
                                            <button type="submit" class="transparent-button !py-1 !text-xs">暂停</button>
                                        </form>
                                    @endif

                                    @if (! in_array($organizer->status, ['pending', 'rejected', 'suspended', 'verified'], true))
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">还没有主办方记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
