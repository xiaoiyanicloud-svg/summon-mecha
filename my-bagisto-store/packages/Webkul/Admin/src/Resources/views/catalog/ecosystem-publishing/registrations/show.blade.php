<x-admin::layouts>
    <x-slot:title>报名详情 · {{ $event->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">报名详情 · {{ $registration?->public_no }}</p>
            <p class="text-xs text-gray-500">活动 #{{ $event->id }} · 轮次 #{{ $attempt->attempt_no }} · 内部 ID #{{ $attempt->id }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.catalog.ecosystem.registrations.index', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回列表</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    <div class="mb-4 grid gap-3 md:grid-cols-4">
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">状态</p>
            <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $attempt->status }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">联系人</p>
            <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">{{ $name }} · {{ $phone }}</p>
            <p class="text-xs text-gray-500">{{ $city ?: '城市未填' }}{{ $canViewPii ? ' · 已解密（已审计）' : ' · 遮罩' }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">表单 / 隐私版本</p>
            <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">v{{ $attempt->form_version }} / {{ $attempt->privacy_version }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="text-xs text-gray-500">提交 / 处理时间</p>
            <p class="mt-1 text-xs text-gray-700 dark:text-gray-200">
                {{ $attempt->submitted_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?: '—' }}
                <br>
                {{ $attempt->decided_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?: '未处理' }}
            </p>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">轮次事实</p>
            <dl class="grid grid-cols-2 gap-2 text-xs text-gray-700 dark:text-gray-200">
                <dt class="text-gray-500">报名编号</dt><dd class="font-mono">{{ $registration?->public_no ?: '—' }}</dd>
                <dt class="text-gray-500">审核方式</dt><dd>{{ $attempt->review_mode }}</dd>
                <dt class="text-gray-500">规则版本</dt><dd>{{ $attempt->rules_version_id ?: '未绑定' }}</dd>
                <dt class="text-gray-500">同意时间</dt><dd>{{ $attempt->consent_accepted_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?: '—' }}</dd>
                <dt class="text-gray-500">处理人</dt><dd>{{ $attempt->decided_by ? '#'.$attempt->decided_by : '—' }}</dd>
                <dt class="text-gray-500">处理原因</dt><dd>{{ $attempt->decision_reason_code ?: '—' }}</dd>
                <dt class="text-gray-500">取消原因</dt><dd>{{ $attempt->cancel_reason_code ?: '—' }}</dd>
                <dt class="text-gray-500">签到时间</dt><dd>{{ $attempt->checked_in_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?: '未签到' }}</dd>
            </dl>
            @if ($attempt->decision_note)
                <p class="mt-3 text-xs text-gray-500">内部备注：{{ $attempt->decision_note }}</p>
            @endif
        </div>

        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">操作</p>

            @if ($attempt->status === 'submitted' && in_array('review', $capabilities, true))
                <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.approve', [$event->id, $attempt->id]) }}" class="mb-3">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $attempt->row_version }}">
                    <button type="submit" class="primary-button">批准并占位</button>
                </form>

                <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.reject', [$event->id, $attempt->id]) }}" class="mb-4 flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $attempt->row_version }}">
                    <input name="reason_code" required maxlength="50" placeholder="拒绝原因码（必填）" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <textarea name="note" rows="2" maxlength="2000" placeholder="内部备注（可选）" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900"></textarea>
                    <button type="submit" class="transparent-button">拒绝</button>
                </form>
            @endif

            @if (! in_array($attempt->status, ['canceled'], true) && in_array('cancel', $capabilities, true))
                <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.cancel', [$event->id, $attempt->id]) }}" class="flex flex-col gap-2 border-t pt-3 dark:border-gray-800">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $attempt->row_version }}">
                    <input name="reason_code" required maxlength="50" placeholder="取消原因码（必填，如 admin_correction）" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <textarea name="note" rows="2" maxlength="2000" placeholder="内部备注（可选）" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900"></textarea>
                    <button type="submit" class="transparent-button">运营取消 / 释放席位</button>
                </form>
            @endif

            @if (! in_array('review', $capabilities, true) && ! in_array('cancel', $capabilities, true))
                <p class="text-xs text-gray-500">当前账号只有查看能力，没有审核/取消授权。</p>
            @endif
        </div>
    </div>
</x-admin::layouts>
