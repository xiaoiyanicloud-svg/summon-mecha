<x-admin::layouts>
    <x-slot:title>报名范围 · {{ $event->name }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">报名范围 · {{ $event->name }}</p>
            <p class="text-xs text-gray-500">活动 #{{ $event->id }} · {{ $event->kind ?: '类型未核定' }} · {{ $event->publish_status }} / {{ $event->hosting_status ?: '举办状态未核定' }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('admin.catalog.ecosystem.events.show', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">返回活动</a>
            <a href="{{ route('admin.catalog.ecosystem.registrations.index', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">报名列表</a>
            <a href="{{ route('admin.catalog.ecosystem.registrations.staff', $event->id) }}" class="transparent-button hover:bg-gray-200 dark:text-white dark:hover:bg-gray-800">人员范围</a>
        </div>
    </div>

    <x-admin::flash />

    @if ($errors->has('action'))
        <div class="mb-3 rounded border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if (! $scope)
        <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-1 font-semibold text-gray-800 dark:text-white">创建报名范围（draft）</p>
            <p class="mb-4 text-xs text-gray-500">
                创建不会开放报名。必须另外执行“激活”，且激活前确认容量与政策。
                capacity 留空=不限；0=关闭；&gt;0=有限，不能低于已确认席位。
            </p>

            <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.scope.store', $event->id) }}" class="grid gap-3 md:grid-cols-2">
                @csrf
                @include('admin::catalog.ecosystem-publishing.registrations._scope-fields', ['scope' => null])

                <label class="flex items-center gap-2 text-xs text-gray-700 md:col-span-2">
                    <input type="checkbox" name="confirm_capacity" value="1" @checked(old('confirm_capacity'))>
                    我确认已与活动负责人核对容量含义（留空=不限 / 0=关闭 / &gt;0=有限）
                </label>

                <div class="md:col-span-2">
                    <button type="submit" class="primary-button">创建报名范围</button>
                </div>
            </form>
        </div>
    @else
        <div class="mb-4 grid gap-3 md:grid-cols-4">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-xs text-gray-500">状态</p>
                <p class="mt-1 text-lg font-semibold {{ $scope->status === 'open' ? 'text-green-700' : ($scope->status === 'closed' ? 'text-amber-700' : 'text-gray-700') }}">{{ $scope->status }}</p>
            </div>
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-xs text-gray-500">已确认 / 容量</p>
                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $scope->confirmed_count }} / {{ $scope->capacity === null ? '不限' : $scope->capacity }}</p>
            </div>
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-xs text-gray-500">计量单位 / 审核</p>
                <p class="mt-1 text-lg font-semibold text-gray-800 dark:text-white">{{ $scope->unit }} · {{ $scope->review_mode }}</p>
            </div>
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="text-xs text-gray-500">表单 / 政策确认</p>
                <p class="mt-1 text-sm font-semibold text-gray-800 dark:text-white">v{{ $scope->form_version }} · {{ $scope->policy_confirmed_at ? $scope->policy_confirmed_at->timezone(config('app.timezone'))->format('Y-m-d H:i') : '未确认' }}</p>
            </div>
        </div>

        @if ($scope->capacity_confirmed_at === null)
            <div class="mb-4 rounded border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                容量尚未确认。修改容量后必须重新勾选确认，否则不能激活。
            </div>
        @endif

        <div class="mb-6 box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-4 font-semibold text-gray-800 dark:text-white">配置</p>

            <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.scope.update', $event->id) }}" class="grid gap-3 md:grid-cols-2">
                @csrf
                <input type="hidden" name="expected_row_version" value="{{ $scope->row_version }}">
                @include('admin::catalog.ecosystem-publishing.registrations._scope-fields', ['scope' => $scope])

                <label class="flex items-center gap-2 text-xs text-gray-700 md:col-span-2">
                    <input type="checkbox" name="confirm_capacity" value="1" @checked(old('confirm_capacity'))>
                    我确认已核对容量含义与“不低于已占席位”限制
                </label>

                <div class="md:col-span-2">
                    <button type="submit" class="primary-button">保存配置</button>
                </div>
            </form>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-3 font-semibold text-gray-800 dark:text-white">开放 / 关闭</p>

                @if ($scope->status !== 'open')
                    <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.scope.activate', $event->id) }}" class="mb-3 flex flex-col gap-2">
                        @csrf
                        <input type="hidden" name="expected_row_version" value="{{ $scope->row_version }}">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="policy_confirmed" value="1" required>
                            我已确认报名政策、容量、时间窗、取消/重报与隐私文本
                        </label>
                        <button type="submit" class="primary-button">激活报名范围</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.scope.close', $event->id) }}" class="mb-3 flex flex-col gap-2">
                        @csrf
                        <input type="hidden" name="expected_row_version" value="{{ $scope->row_version }}">
                        <input name="reason" placeholder="关闭原因（可选）" maxlength="500" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        <button type="submit" class="transparent-button">立即关闭报名</button>
                    </form>
                @endif

                @if ($scope->status === 'closed')
                    <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.scope.reopen', $event->id) }}">
                        @csrf
                        <input type="hidden" name="expected_row_version" value="{{ $scope->row_version }}">
                        <button type="submit" class="transparent-button">重新开放</button>
                    </form>
                @endif
            </div>

            <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
                <p class="mb-3 font-semibold text-gray-800 dark:text-white">调整容量</p>
                <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.scope.capacity', $event->id) }}" class="flex flex-col gap-2">
                    @csrf
                    <input type="hidden" name="expected_row_version" value="{{ $scope->row_version }}">
                    <input name="capacity" type="number" min="0" value="{{ $scope->capacity }}" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <input name="reason" required placeholder="调整原因（必填）" maxlength="500" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                    <label class="flex items-center gap-2 text-xs text-gray-700">
                        <input type="checkbox" name="confirm_capacity" value="1" required>
                        我确认新容量不低于已确认席位
                    </label>
                    <button type="submit" class="transparent-button">保存容量</button>
                </form>
            </div>
        </div>

        <div class="mt-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-1 font-semibold text-gray-800 dark:text-white">活动变更（取消 / 改期）</p>

            @if (! $lifecycleEnabled)
                <p class="mb-3 text-xs text-amber-700">
                    B-06 取消/改期政策未签署：生命周期写能力保持关闭。请先用 G1 的“暂缓 / 撤稿”停止展示。
                </p>
            @else
                <div class="grid gap-4 md:grid-cols-2">
                    <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.changes.cancel', $event->id) }}" class="flex flex-col gap-2">
                        @csrf
                        <p class="text-xs font-semibold text-red-700">取消活动（终态）</p>
                        <textarea name="summary" rows="2" required maxlength="500" placeholder="取消说明（公开给报名人）" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900"></textarea>
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="policy_confirmed" value="1" required>
                            我确认取消政策已签署，取消会立即停写并释放席位
                        </label>
                        <button type="submit" class="transparent-button">取消活动</button>
                    </form>

                    <form method="POST" action="{{ route('admin.catalog.ecosystem.registrations.changes.reschedule', $event->id) }}" class="flex flex-col gap-2">
                        @csrf
                        <p class="text-xs font-semibold text-gray-700">记录改期（先走 G1 发布新版本）</p>
                        <input name="summary" required maxlength="500" placeholder="改期说明" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="requires_ack" value="1">
                            需要报名人确认（必须配置截止时间）
                        </label>
                        <input name="ack_deadline" type="datetime-local" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                        <label class="flex items-center gap-2 text-xs text-gray-700">
                            <input type="checkbox" name="decline_releases_seat" value="1">
                            拒绝确认时释放席位
                        </label>
                        <select name="no_response_action" class="w-full rounded-md border px-3 py-2 text-sm dark:border-gray-800 dark:bg-gray-900">
                            <option value="none">逾期未响应：保留席位</option>
                            <option value="release">逾期未响应：释放席位</option>
                        </select>
                        <button type="submit" class="transparent-button">记录改期</button>
                    </form>
                </div>
            @endif

            @if ($changes->isNotEmpty())
                <div class="mt-4">
                    <p class="mb-2 text-xs font-semibold text-gray-600">最近变更</p>
                    <ul class="space-y-1 text-xs text-gray-600">
                        @foreach ($changes as $change)
                            <li>
                                v{{ $change->change_version }} · {{ $change->type }} · {{ $change->summary }}
                                <span class="text-gray-400">{{ $change->published_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endif
</x-admin::layouts>
