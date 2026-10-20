{{-- 报名范围表单字段（创建/编辑共用）。$scope 为 null 时是创建。 --}}
@php
    $tz = config('app.timezone');
    $dt = function ($value) use ($tz) {
        return $value ? $value->timezone($tz)->format('Y-m-d\TH:i') : '';
    };
@endphp

<label class="text-xs text-gray-600">
    容量（留空=不限，0=关闭，&gt;0=有限）
    <input name="capacity" type="number" min="0" value="{{ old('capacity', $scope->capacity ?? '') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
    @error('capacity') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    审核方式
    <select name="review_mode" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
        @foreach (['auto' => '自动确认（提交即占位）', 'manual' => '人工审核（批准才占位）'] as $value => $label)
            <option value="{{ $value }}" @selected(old('review_mode', $scope->review_mode ?? 'auto') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @error('review_mode') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    报名开始（留空=立即）
    <input name="registration_opens_at" type="datetime-local" value="{{ old('registration_opens_at', $dt($scope->registration_opens_at ?? null)) }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
    @error('registration_opens_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    报名截止（激活必填）
    <input name="registration_closes_at" type="datetime-local" value="{{ old('registration_closes_at', $dt($scope->registration_closes_at ?? null)) }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
    @error('registration_closes_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    审核截止（人工审核必填，且不早于报名截止）
    <input name="review_closes_at" type="datetime-local" value="{{ old('review_closes_at', $dt($scope->review_closes_at ?? null)) }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
    @error('review_closes_at') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    取消政策
    <select name="cancel_policy" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
        <option value="before_close" @selected(old('cancel_policy', $scope->cancel_policy ?? 'before_close') === 'before_close')>截止前可自助取消</option>
        <option value="never" @selected(old('cancel_policy', $scope->cancel_policy ?? '') === 'never')>不允许自助取消</option>
    </select>
    @error('cancel_policy') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    重报政策
    <select name="reapply_policy" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
        <option value="allowed" @selected(old('reapply_policy', $scope->reapply_policy ?? 'allowed') === 'allowed')>允许（创建新轮次）</option>
        <option value="never" @selected(old('reapply_policy', $scope->reapply_policy ?? '') === 'never')>不允许</option>
    </select>
    @error('reapply_policy') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    最大报名次数（留空=不限，含首次）
    <input name="max_attempts" type="number" min="1" max="100" value="{{ old('max_attempts', $scope->max_attempts ?? '') }}" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
    @error('max_attempts') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    隐私/同意文本版本（激活必填）
    <input name="privacy_version" value="{{ old('privacy_version', $scope->privacy_version ?? '') }}" maxlength="50" placeholder="如 2026-10-19" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
    @error('privacy_version') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    绑定规则版本（有已批准规则时必填）
    <select name="rule_version_id" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
        <option value="">未绑定</option>
        @foreach ($ruleVersions as $rule)
            <option value="{{ $rule->id }}" @selected((int) old('rule_version_id', $scope->rule_version_id ?? 0) === (int) $rule->id)>
                v{{ $rule->version_no }} · {{ $rule->title }} · {{ $rule->status }}
            </option>
        @endforeach
    </select>
    @error('rule_version_id') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="text-xs text-gray-600">
    未响应变更的处理
    <select name="no_response_action" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
        <option value="none" @selected(old('no_response_action', $scope->no_response_action ?? 'none') === 'none')>保留席位</option>
        <option value="release" @selected(old('no_response_action', $scope->no_response_action ?? '') === 'release')>逾期释放席位</option>
    </select>
    @error('no_response_action') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>

<label class="flex items-center gap-2 text-xs text-gray-700">
    <input type="checkbox" name="change_ack_required" value="1" @checked(old('change_ack_required', $scope->change_ack_required ?? false))>
    活动变更需要报名人显式确认
</label>

<label class="flex items-center gap-2 text-xs text-gray-700">
    <input type="checkbox" name="decline_releases_seat" value="1" @checked(old('decline_releases_seat', $scope->decline_releases_seat ?? false))>
    报名人拒绝变更时释放席位
</label>

<label class="text-xs text-gray-600 md:col-span-2">
    隐私/同意摘要（公开给报名人）
    <textarea name="privacy_summary" rows="3" maxlength="5000" class="mt-1 w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">{{ old('privacy_summary', $scope->privacy_summary ?? '') }}</textarea>
    @error('privacy_summary') <span class="mt-1 block text-red-600">{{ $message }}</span> @enderror
</label>
