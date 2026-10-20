<x-admin::layouts>
    <x-slot:title>活动人员范围 · {{ $event->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">活动人员范围 · {{ $event->name }}</p>
            <p class="text-xs text-gray-500">全局 ACL 只决定“有没有能力”，本页决定“能不能作用于这个活动”。超管可越过范围，但仍写审计。</p>
        </div>
        <a href="{{ route('admin.catalog.ecosystem.registrations.scope', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回报名范围</a>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if (! $isSuperAdmin)
        <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            只有平台超级管理员可以授予或撤销活动范围；当前账号可以查看本活动已有授权。
        </div>
    @endif

    @if ($isSuperAdmin)
        <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">授予 / 更新范围</p>

            <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.staff.grant', $event->id) }}" class="grid gap-3 md:grid-cols-2">
                @csrf

                <label class="text-xs text-gray-600">
                    后台账号
                    <select name="admin_id" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        @foreach ($admins as $admin)
                            <option value="{{ $admin->id }}" @selected((int) old('admin_id') === (int) $admin->id)>
                                #{{ $admin->id }} · {{ $admin->name }} · {{ $admin->email }}{{ $admin->status ? '' : '（停用）' }}
                            </option>
                        @endforeach
                    </select>
                    @error('admin_id') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </label>

                <div class="text-xs text-gray-600">
                    能力
                    <div class="mt-1 grid grid-cols-2 gap-1 rounded-md border px-3 py-2 dark:border-gray-800">
                        @foreach ($capabilities as $capability)
                            <label class="flex items-center gap-2">
                                <input type="checkbox" name="capabilities[]" value="{{ $capability }}" @checked(in_array($capability, (array) old('capabilities', []), true))>
                                {{ $capability }}
                            </label>
                        @endforeach
                    </div>
                    @error('capabilities') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                </div>

                <label class="text-xs text-gray-600">
                    生效时间（留空=立即）
                    <input name="valid_from" type="datetime-local" value="{{ old('valid_from') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600">
                    失效时间（留空=长期）
                    <input name="valid_until" type="datetime-local" value="{{ old('valid_until') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <label class="text-xs text-gray-600 md:col-span-2">
                    备注（可选）
                    <input name="note" value="{{ old('note') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                </label>

                <div class="md:col-span-2">
                    <button type="submit" class="primary-button">保存人员范围</button>
                </div>
            </form>
        </div>
    @endif

    <div class="box-shadow rounded bg-white dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">后台账号</th>
                    <th class="px-4 py-3">能力</th>
                    <th class="px-4 py-3">有效期</th>
                    <th class="px-4 py-3">授权人</th>
                    <th class="px-4 py-3">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($assignments as $assignment)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3 font-medium text-gray-800 dark:text-white">
                            #{{ $assignment->admin_id }}
                            <div class="text-xs text-gray-500">{{ $assignment->note ?: '—' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">{{ implode(', ', (array) $assignment->capabilities) }}</td>
                        <td class="px-4 py-3 text-xs">
                            {{ $assignment->valid_from ? $assignment->valid_from->timezone(config('app.timezone'))->format('Y-m-d H:i') : '立即' }}
                            →
                            {{ $assignment->valid_until ? $assignment->valid_until->timezone(config('app.timezone'))->format('Y-m-d H:i') : '长期' }}
                            <div class="text-gray-500">{{ $assignment->isActiveAt() ? '有效' : '已失效' }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">#{{ $assignment->granted_by ?: '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($isSuperAdmin)
                                <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.staff.revoke', [$event->id, $assignment->admin_id]) }}">
                                    @csrf
                                    <button type="submit" class="transparent-button !py-1 !text-xs">撤销</button>
                                </form>
                            @else
                                <span class="text-xs text-gray-400">仅超管可操作</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-500">尚无活动人员授权；非超管将无法处理本活动报名。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
