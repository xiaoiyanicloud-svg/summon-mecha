<x-admin::layouts>
    <x-slot:title>合作申请详情 · {{ $application->application_no }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.customers.cooperations.index') }}" class="text-sm text-blue-600 hover:underline">&larr; 返回列表</a>
            <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">
                合作申请详情 · {{ $application->application_no }}
            </p>
        </div>
    </div>

    <x-admin::flash />

    <div class="grid gap-4 md:grid-cols-3">
        <div class="box-shadow rounded bg-white p-5 dark:bg-gray-900 md:col-span-2">
            <p class="mb-4 border-b border-gray-100 pb-2 text-base font-bold text-gray-800 dark:border-gray-800 dark:text-white">
                申请资料
            </p>

            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-gray-500">申请单号</dt>
                    <dd class="mt-1 font-mono font-semibold text-gray-800 dark:text-white">{{ $application->application_no }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">合作类型</dt>
                    <dd class="mt-1 font-semibold text-gray-800 dark:text-white">
                        {{ \Webkul\Shop\Models\CooperationApplication::typeLabel($application->coop_type) }}
                        <span class="ml-1 text-xs text-gray-400">({{ $application->coop_type }})</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">主体/企业/达人名称</dt>
                    <dd class="mt-1 font-semibold text-gray-800 dark:text-white">{{ $application->org_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">合作方向</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">{{ $application->coop_direction ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">联系人姓名</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">{{ $application->contact_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">联系电话</dt>
                    <dd class="mt-1 font-mono text-gray-800 dark:text-white">{{ $application->contact_phone }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">微信号 / 邮箱</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">{{ $application->contact_wechat ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">所在地区 / 城市</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">{{ $application->region ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">关联客户 ID</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">{{ $application->customer_id ?: '游客提交' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">提交时间</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">{{ $application->created_at?->format('Y-m-d H:i:s') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">在跟进人员</dt>
                    <dd class="mt-1 font-semibold text-blue-700 dark:text-blue-400">
                        {{ $application->meta['follower_name'] ?? $application->followup_person ?? '暂未指派' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">跟进人联系方式</dt>
                    <dd class="mt-1 text-gray-800 dark:text-white">
                        {{ $application->meta['follower_contact'] ?? '—' }}
                    </dd>
                </div>
            </dl>

            <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                <p class="text-xs text-gray-500">合作意向与补充说明</p>
                <p class="mt-1 whitespace-pre-wrap text-sm text-gray-800 dark:text-white">{{ $application->summary ?: '（未填写）' }}</p>
            </div>

            @php
                $formattedMeta = \Webkul\Shop\Models\CooperationApplication::formatMetaForDisplay($application->meta);
            @endphp
            @if (! empty($formattedMeta))
                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <p class="mb-2 text-xs text-gray-500">专项扩展字段与邀请码留痕</p>
                    <div class="grid grid-cols-1 gap-2 rounded bg-gray-50 p-3 text-xs dark:bg-gray-950 sm:grid-cols-2">
                        @foreach ($formattedMeta as $row)
                            <div>
                                <span class="font-medium text-gray-500">{{ $row['label'] }}：</span>
                                <span class="text-gray-800 dark:text-gray-200">{{ $row['value'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if (! empty($application->meta['issued_invite_code']))
                <div class="mt-4 rounded border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                    <div class="font-semibold">已签发专属供应链/生态邀请码：<span class="font-mono text-sm">{{ $application->meta['issued_invite_code'] }}</span></div>
                    <div class="mt-1 text-emerald-700 dark:text-emerald-300">
                        有效期：{{ $application->meta['issued_invite_expires_at'] ?? '长期有效' }} ·
                        受邀链接：<span class="font-mono">{{ url('/dev/join?code=' . urlencode($application->meta['issued_invite_code'])) }}</span>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="box-shadow rounded bg-white p-5 dark:bg-gray-900">
                <p class="mb-4 border-b border-gray-100 pb-2 text-base font-bold text-gray-800 dark:border-gray-800 dark:text-white">
                    审核与商务跟进处置
                </p>

                @php
                    $currentAdminName = auth()->guard('admin')->user()?->name ?? '平台商务顾问';
                    $defaultIssueInvite = old(
                        'issue_invite',
                        ($application->coop_type === 'supplier_invite' && empty($application->meta['issued_invite_code'])) ? '1' : null
                    );
                @endphp

                <form method="POST" action="{{ route('admin.customers.cooperations.status', $application->id) }}" class="space-y-4">
                    @csrf

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">当前状态</span>
                        <select name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                            <option value="pending" @selected($application->status === 'pending')>待审核</option>
                            <option value="contacting" @selected($application->status === 'contacting')>商务跟进中</option>
                            <option value="approved" @selected($application->status === 'approved')>已通过</option>
                            <option value="rejected" @selected($application->status === 'rejected')>未通过（需填原因）</option>
                        </select>
                    </label>

                    <div class="rounded border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-800 dark:bg-gray-950">
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-1">
                            <span class="text-xs font-semibold text-gray-800 dark:text-gray-200">在跟进人员指派（C 端申请人可见）</span>
                            @if (! empty($adminUsers) && $adminUsers->isNotEmpty())
                                <div class="flex flex-wrap items-center gap-1">
                                    <span class="text-[11px] text-gray-400">快捷填入：</span>
                                    @foreach ($adminUsers as $u)
                                        <button
                                            type="button"
                                            onclick="document.getElementById('coop-follower-name').value = @js($u->name)"
                                            class="rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[11px] text-blue-600 hover:border-blue-400 dark:border-gray-700 dark:bg-gray-900 dark:text-blue-400"
                                        >
                                            {{ $u->name }}
                                        </button>
                                    @endforeach
                                    <button
                                        type="button"
                                        onclick="document.getElementById('coop-follower-name').value = ''"
                                        class="text-[11px] text-gray-400 hover:text-red-500"
                                    >
                                        清空
                                    </button>
                                </div>
                            @endif
                        </div>

                        <label class="block">
                            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">在跟进人员姓名 / 商务负责人</span>
                            <input
                                type="text"
                                id="coop-follower-name"
                                name="followup_person"
                                maxlength="64"
                                value="{{ old('followup_person', $application->followup_person ?: ($application->meta['follower_name'] ?? '')) }}"
                                placeholder="例如：张经理 / 供应链商务组"
                                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            >
                            @error('followup_person')
                                <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="mt-2.5 block">
                            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">跟进人联系电话 / 微信号（选填）</span>
                            <input
                                type="text"
                                name="follower_contact"
                                maxlength="80"
                                value="{{ old('follower_contact', $application->meta['follower_contact'] ?? '') }}"
                                placeholder="例如：138xxxx8888 或企业微信号"
                                class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                            >
                            @error('follower_contact')
                                <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                            @enderror
                        </label>
                    </div>

                    <div class="rounded border border-blue-100 bg-blue-50/60 p-3 dark:border-gray-800 dark:bg-gray-950">
                        <label class="flex items-start gap-2 text-xs font-medium text-gray-800 dark:text-gray-200">
                            <input
                                type="checkbox"
                                name="issue_invite"
                                value="1"
                                class="mt-0.5 rounded border-gray-300 text-blue-600"
                                @checked($defaultIssueInvite)
                            >
                            <span>
                                同步签发专属供应链/生态邀请码
                                <span class="block text-[11px] font-normal text-gray-500">
                                    适用于「无码申请供应链邀约」或审核通过的合伙人。勾选后将自动生成 1 次性受邀码并下发至申请人 C 端卡片与站内通知。
                                </span>
                            </span>
                        </label>

                        <label class="mt-2 block">
                            <span class="mb-1 block text-[11px] text-gray-500">签发邀请码有效期</span>
                            <select name="invite_expires_days" class="block w-full rounded border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                <option value="30" selected>30 天内有效（推荐）</option>
                                <option value="7">7 天内有效</option>
                                <option value="90">90 天内有效</option>
                                <option value="0">长期有效</option>
                            </select>
                        </label>
                    </div>

                    <label class="block">
                        <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">商务跟进备注 / 审核反馈（C 端申请人可见）</span>
                        <textarea
                            name="admin_note"
                            rows="4"
                            maxlength="500"
                            placeholder="填写对接进展、跟进结论或驳回原因（会同步展示给申请人）"
                            class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                        >{{ old('admin_note', $application->admin_note) }}</textarea>
                        @error('admin_note')
                            <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                        @enderror
                    </label>

                    @if ($application->reviewed_at)
                        <p class="text-xs text-gray-400">
                            最近处置时间：{{ $application->reviewed_at->format('Y-m-d H:i:s') }}
                        </p>
                    @endif

                    <button type="submit" class="primary-button w-full justify-center">保存处置结果</button>
                </form>
            </div>

            {{-- 独立一键发码卡片：无需修改审核状态也可随时单独生成/重新签发专属邀请码 --}}
            <div class="box-shadow rounded border border-emerald-100 bg-white p-5 dark:border-gray-800 dark:bg-gray-900">
                <div class="mb-1 flex items-center justify-between">
                    <p class="text-sm font-bold text-gray-800 dark:text-white">🔑 专属受邀码签发</p>
                    @if (! empty($application->meta['issued_invite_code']))
                        <span class="rounded bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            已签发：{{ $application->meta['issued_invite_code'] }}
                        </span>
                    @endif
                </div>
                <p class="mb-3 text-xs text-gray-500">
                    可在此直接生成专属邀请码并自动绑定至当前合作档案，实时推送至申请人 C 端卡片与站内通知。
                </p>

                <form method="POST" action="{{ route('admin.customers.cooperations.issue_invite', $application->id) }}" class="space-y-3">
                    @csrf

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">自定义码（选填，留空自动生成）</span>
                            <input
                                type="text"
                                name="custom_code"
                                maxlength="40"
                                placeholder="如 VIP-{{ substr($application->application_no, -4) }}"
                                class="block w-full rounded border border-gray-300 px-2.5 py-1.5 font-mono text-xs uppercase dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                            >
                            @error('custom_code')
                                <span class="mt-1 block text-xs text-red-600">{{ $message }}</span>
                            @enderror
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">可用次数</span>
                            <select name="max_uses" class="block w-full rounded border border-gray-300 px-2.5 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                <option value="1" selected>1 次（单企专属，推荐）</option>
                                <option value="5">5 次（团队多人使用）</option>
                                <option value="10">10 次</option>
                                <option value="100">100 次</option>
                                <option value="255">255 次（长期不限）</option>
                            </select>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">有效期</span>
                            <select name="invite_expires_days" class="block w-full rounded border border-gray-300 px-2.5 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                                <option value="30" selected>30 天内有效（推荐）</option>
                                <option value="7">7 天内有效</option>
                                <option value="90">90 天内有效</option>
                                <option value="0">长期有效（不过期）</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">备注说明（选填）</span>
                            <input
                                type="text"
                                name="note_suffix"
                                maxlength="100"
                                placeholder="例如：商务专员加急配发"
                                class="block w-full rounded border border-gray-300 px-2.5 py-1.5 text-xs dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                            >
                        </label>
                    </div>

                    <button type="submit" class="primary-button w-full justify-center text-xs py-2">
                        {{ ! empty($application->meta['issued_invite_code']) ? '重新生成并换发新邀请码' : '立即生成并签发专属邀请码' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-admin::layouts>
