{{-- 备案表单 —— 新建/编辑共用。期望 $filing(可 null) 与 $types --}}
@php
    $filing = $filing ?? null;
    $attachmentsText = old('attachments', implode("\n", (array) ($filing?->attachments ?? [])));
@endphp

<div class="mt-3.5 grid gap-4 xl:grid-cols-2">
    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">备案类型</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="select" name="filing_type" :value="$filing?->filing_type">
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}" @selected($filing?->filing_type === $value)>{{ $label }}</option>
                @endforeach
            </x-admin::form.control-group.control>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">名称 / 主体</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="name" rules="required" :value="$filing?->name" placeholder="召唤机甲平台 · 门户站点" />
            <x-admin::form.control-group.error control-name="name" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>备案号 / 许可证号</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="filing_no" :value="$filing?->filing_no" placeholder="粤ICP备00000000号-1" />
            <p class="mt-1 text-xs text-gray-500">拿到号之前留空是<b>正常</b>的 —— 别为了填满而编。</p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>主管单位</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="authority" :value="$filing?->authority" placeholder="广东省通信管理局" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>适用范围 / 备注</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="text" name="scope" :value="$filing?->scope" />
        </x-admin::form.control-group>
    </div>

    <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <x-admin::form.control-group>
            <x-admin::form.control-group.label class="required">状态</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="select" name="status" :value="$filing?->status ?? 'pending'">
                @foreach (\Webkul\Shop\Models\ComplianceFiling::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}" @selected($filing?->status === $value)>{{ $label }}</option>
                @endforeach
            </x-admin::form.control-group.control>
            <p class="mt-1 text-xs text-gray-500">
                作废请选「已作废」并在备注里写明原因 —— <b>不要删行</b>，作废本身也是要留档的事实。
            </p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>提交 / 通过日期</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="date" name="filed_at" :value="$filing?->filed_at?->format('Y-m-d')" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>年检日期</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="date" name="annual_review_at" :value="$filing?->annual_review_at?->format('Y-m-d')" />
            <p class="mt-1 text-xs text-gray-500">
                到期前 <b>{{ \Webkul\Shop\Models\ComplianceFiling::EXPIRING_SOON_DAYS }} 天</b>会在列表里标红。
            </p>
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>到期日期</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="date" name="expires_at" :value="$filing?->expires_at?->format('Y-m-d')" />
        </x-admin::form.control-group>

        <x-admin::form.control-group>
            <x-admin::form.control-group.label>附件（一行一条，文字或链接）</x-admin::form.control-group.label>
            <x-admin::form.control-group.control type="textarea" name="attachments" :value="$attachmentsText" placeholder="回执存放位置 / 内部链接" />
            <p class="mt-1 text-xs text-amber-700">
                ⚠ 本轮<b>只收文字与链接，不做文件上传</b> —— 备案回执扫描件属证件类材料，
                需要访问控制与加密存储方案（依赖对象存储与等保要求）。
            </p>
        </x-admin::form.control-group>
    </div>
</div>
