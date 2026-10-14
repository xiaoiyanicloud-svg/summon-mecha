{{-- 机型表单 —— 新建/编辑共用。期望传入 $model(可为 null) 与 $channels --}}
@php
    $model = $model ?? null;
    $selectedChannels = old('comm_channels', $model?->comm_channels ?? []);
    $selectedChannels = is_array($selectedChannels) ? $selectedChannels : [];
    $firmwareText = old('firmware_versions', implode("\n", (array) ($model?->firmware_versions ?? [])));
@endphp

<div class="mt-3.5 grid gap-4 xl:grid-cols-2">
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">机型编码</x-admin::form.control-group.label>
            <x-admin::form.control-group.control
                type="text"
                name="model_code"
                rules="required"
                :value="$model?->model_code"
                placeholder="unitree_g1"
            />
            <x-admin::form.control-group.error control-name="model_code" />
            <p class="mt-1 text-xs text-gray-500">
                全局唯一。技能与设备都按这个编码关联机型 —— 建好之后<b>不要改</b>，改了等于换了一个机型。
            </p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">机型名称</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="model_name" rules="required" :value="$model?->model_name" placeholder="宇树 G1" />
            <x-admin::form.control-group.error control-name="model_name" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">厂商品牌</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="brand" rules="required" :value="$model?->brand" placeholder="unitree" />
            <x-admin::form.control-group.error control-name="brand" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>厂商编码</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="vendor_code" :value="$model?->vendor_code" placeholder="unitree" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>自由度</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" name="degrees_of_freedom" :value="$model?->degrees_of_freedom" placeholder="23" />
            <x-admin::form.control-group.error control-name="degrees_of_freedom" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>算力平台</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="compute_platform" :value="$model?->compute_platform" placeholder="ARM(Orin,EDU)" />
            <p class="mt-1 text-xs text-gray-500">多数厂商不公开 —— <b>留空是正常的</b>，别为了填满而编。</p>
        </x-admin::form.control-group>
    </div>

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">
                可用通道（决定用户能怎么装技能）
            </x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="multiselect" name="comm_channels[]" label="可用通道">
                @foreach ($channels as $channel)
                    <option value="{{ $channel }}" {{ in_array($channel, $selectedChannels, true) ? 'selected' : '' }}>
                        {{ $channel === 'agent' ? '自动下发（Agent）' : '人工安装（manual）' }}
                    </option>
                @endforeach
            </x-admin::form.control-group.control>
            <p class="mt-1 text-xs leading-relaxed text-amber-700">
                ⚠ 这里勾的通道会与技能声明的通道<b>取交集</b>，交集为空时用户会看到「无可用通道」。
                在真机 Agent 跑通之前，P0 机型也<b>只应勾「人工安装」</b>，别提前勾上自动下发。
            </p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>已知固件版本</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="firmware_versions" :value="$firmwareText" placeholder="一行一个版本号" />
            <p class="mt-1 text-xs text-gray-500">
                一行一个。留空 = <b>未知</b>（会跳过固件版本比对，而不是判"固件过低"）。
            </p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>最低 Agent 版本</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="min_agent_version" :value="$model?->min_agent_version" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">分级</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="select" name="status" :value="$model?->status ?? 'active'">
                <option value="active">可交付 —— 能进选型、能下单</option>
                <option value="display_only">仅展示 —— 出现但明确不承诺能装</option>
            </x-admin::form.control-group.control>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>图标</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="icon" :value="$model?->icon" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>排序权重（小在前）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="number" name="sort_order" :value="$model?->sort_order ?? 0" />
        </x-admin::form.control-group>
    </div>
</div>
