{{-- 赛事表单 —— 新建/编辑共用。期望传入 $event(新建时为 null)。 --}}
@php
    $event = $event ?? null;
@endphp

<div class="mt-3.5 grid gap-4 xl:grid-cols-2">
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">赛事名称</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="name"
                rules="required|max:191"
                maxlength="191"
                :value="old('name', $event?->name ?? '')"
            />
            <x-admin::form.control-group.error control-name="name" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">状态</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="select"
                name="status"
                rules="required"
                :value="old('status', $event?->status ?? 'registering')"
            >
                <option value="registering" @selected(old('status', $event?->status ?? 'registering') === 'registering')>报名中</option>
                <option value="ongoing" @selected(old('status', $event?->status ?? 'registering') === 'ongoing')>进行中</option>
                <option value="upcoming" @selected(old('status', $event?->status ?? 'registering') === 'upcoming')>即将开始</option>
                <option value="ended" @selected(old('status', $event?->status ?? 'registering') === 'ended')>已结束</option>
            </x-admin::form.control-group.control>
            <x-admin::form.control-group.error control-name="status" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>类型</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="type"
                maxlength="191"
                :value="old('type', $event?->type ?? '格斗竞技')"
                placeholder="格斗竞技 / 竞速赛 / 展览…"
            />
            <x-admin::form.control-group.error control-name="type" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>时间</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="date"
                maxlength="191"
                :value="old('date', $event?->date ?? '')"
                placeholder="2026年10月1日 8:00-16:00"
            />
            <x-admin::form.control-group.error control-name="date" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>城市</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="city"
                maxlength="191"
                :value="old('city', $event?->city ?? '')"
            />
            <x-admin::form.control-group.error control-name="city" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>地点</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="location"
                maxlength="191"
                :value="old('location', $event?->location ?? '')"
            />
            <x-admin::form.control-group.error control-name="location" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>主办方</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="organizer"
                maxlength="191"
                :value="old('organizer', $event?->organizer ?? '')"
            />
            <x-admin::form.control-group.error control-name="organizer" />
        </x-admin::form.control-group>
    </div>

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label>热度 (0-100)</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="number"
                name="hot"
                min="0"
                max="100"
                :value="old('hot', $event?->hot ?? 60)"
            />
            <x-admin::form.control-group.error control-name="hot" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>参赛队伍数</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="number"
                name="teams"
                min="0"
                max="4294967295"
                :value="old('teams', $event?->teams ?? 0)"
            />
            <x-admin::form.control-group.error control-name="teams" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>图标 emoji</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="icon"
                maxlength="191"
                :value="old('icon', $event?->icon ?? '🏆')"
            />
            <x-admin::form.control-group.error control-name="icon" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>背景色</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="bg_color"
                maxlength="20"
                :value="old('bg_color', $event?->bg_color ?? '#E8F0FE')"
                placeholder="#E8F0FE"
            />
            <x-admin::form.control-group.error control-name="bg_color" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>排序权重(大在前)</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="number"
                name="sort"
                min="-2147483648"
                max="2147483647"
                :value="old('sort', $event?->sort ?? 0)"
            />
            <x-admin::form.control-group.error control-name="sort" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>奖项设置</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="textarea"
                name="prize"
                maxlength="16000"
                :value="old('prize', $event?->prize ?? '')"
            />
            <x-admin::form.control-group.error control-name="prize" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>赛程安排</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="textarea"
                name="schedule"
                maxlength="16000"
                :value="old('schedule', $event?->schedule ?? '')"
            />
            <x-admin::form.control-group.error control-name="schedule" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>往届回顾</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="textarea"
                name="history"
                maxlength="16000"
                :value="old('history', $event?->history ?? '')"
            />
            <x-admin::form.control-group.error control-name="history" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>赛事简介</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="textarea"
                name="detail"
                maxlength="16000"
                :value="old('detail', $event?->detail ?? '')"
            />
            <x-admin::form.control-group.error control-name="detail" />
        </x-admin::form.control-group>
    </div>
</div>
