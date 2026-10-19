<x-admin::layouts>
    <x-slot:title>新建活动草稿</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">新建活动草稿</p>
            <p class="text-xs text-gray-500">
                创建的是 G1 草稿壳：publish_status=draft、disposition=normal、row_version=0，
                没有任何 revision，因此不能直接发布。创建后进入活动工作页。
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

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <form method="POST" action="{{ route('admin.catalog.ecosystem.events.store') }}" class="grid gap-3 md:grid-cols-3">
            @csrf

            <label class="text-xs text-gray-600 md:col-span-2">
                活动名称（必填）
                <input name="name" value="{{ old('name') }}" maxlength="255" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                @error('name') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                kind
                <select name="kind" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @foreach ((array) config('ecosystem_publishing.event_kinds', []) as $kind)
                        <option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ $kind }}</option>
                    @endforeach
                </select>
                @error('kind') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                participation_mode
                <select name="participation_mode" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <option value="">（未填写）</option>
                    @foreach ((array) config('ecosystem_revisions.participation_modes', []) as $mode)
                        <option value="{{ $mode }}" @selected(old('participation_mode') === $mode)>{{ $mode }}</option>
                    @endforeach
                </select>
                @error('participation_mode') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                hosting_status
                <select name="hosting_status" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @foreach ((array) config('ecosystem_publishing.review_allowed_hosting_statuses', []) as $status)
                        <option value="{{ $status }}" @selected(old('hosting_status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                @error('hosting_status') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                <span class="mt-1 block text-gray-500">
                    B-06 未签署：只允许 {{ implode(' / ', (array) config('ecosystem_publishing.review_allowed_hosting_statuses', [])) }}。
                </span>
            </label>

            <label class="text-xs text-gray-600">
                timezone（IANA）
                <input name="timezone" list="eco-tz-list-create" value="{{ old('timezone', config('app.timezone', 'Asia/Shanghai')) }}" maxlength="64" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                @error('timezone') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                starts_at（按活动时区填写）
                <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                @error('starts_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                ends_at（按活动时区填写）
                <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                @error('ends_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <div class="md:col-span-3 mt-2 border-t pt-3 dark:border-gray-800">
                <p class="mb-2 text-xs font-semibold text-gray-700 dark:text-gray-200">来源与主办方（三字段必须同时填写）</p>
            </div>

            <label class="text-xs text-gray-600">
                source_type
                <select name="source_type" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @foreach ((array) config('ecosystem_organizers.source_types', []) as $sourceType)
                        <option value="{{ $sourceType }}" @selected(old('source_type') === $sourceType)>{{ $sourceType }}</option>
                    @endforeach
                </select>
                @error('source_type') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                platform_role
                <select name="platform_role" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    @foreach ((array) config('ecosystem_organizers.platform_roles', []) as $role)
                        <option value="{{ $role }}" @selected(old('platform_role') === $role)>{{ $role }}</option>
                    @endforeach
                </select>
                @error('platform_role') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
            </label>

            <label class="text-xs text-gray-600">
                organizer_id
                <select name="organizer_id" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <option value="">（请选择）</option>
                    @foreach ($organizers as $organizer)
                        <option value="{{ $organizer->id }}" @selected((int) old('organizer_id') === (int) $organizer->id)>
                            #{{ $organizer->id }} {{ $organizer->name }}（{{ $organizer->platform_role }} / {{ $organizer->status }}）
                        </option>
                    @endforeach
                </select>
                @error('organizer_id') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
                <span class="mt-1 block text-gray-500">
                    只有 pending / verified 的主体可选；platform_role 必须与主体角色一致。
                </span>
            </label>

            <div class="md:col-span-3 rounded border border-blue-200 bg-blue-50 px-4 py-3 text-xs text-blue-900">
                旧展示字段（type / status / city / location / organizer / hot / teams / icon / bg_color / sort / date）
                由服务端写入安全默认值，仅用于兼容旧列表，不是公开 DTO，也不接受本表单透传。
            </div>

            <div class="md:col-span-3">
                <button type="submit" class="primary-button">创建活动草稿</button>
            </div>
        </form>

        <datalist id="eco-tz-list-create">
            <option value="Asia/Shanghai"></option>
            <option value="Asia/Tokyo"></option>
            <option value="Europe/London"></option>
            <option value="America/New_York"></option>
            <option value="UTC"></option>
        </datalist>
    </div>
</x-admin::layouts>
