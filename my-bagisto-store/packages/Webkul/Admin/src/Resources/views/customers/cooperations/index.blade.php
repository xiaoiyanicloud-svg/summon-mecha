<x-admin::layouts>
    <x-slot:title>生态合作申请</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <p class="py-3 text-xl font-bold text-gray-800 dark:text-white">生态合作申请管理</p>
        <a
            href="{{ route('admin.customers.developer-invites.index') }}"
            class="secondary-button text-xs"
        >
            查看全部邀请码台账 &rarr;
        </a>
    </div>

    <x-admin::flash />

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-5">
        <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
            <p class="text-xs text-gray-500">全部申请</p>
            <p class="mt-1 text-xl font-bold text-gray-800 dark:text-white">{{ $counts['total'] ?? 0 }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
            <p class="text-xs text-amber-600">待审核</p>
            <p class="mt-1 text-xl font-bold text-amber-600">{{ $counts['pending'] ?? 0 }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
            <p class="text-xs text-blue-600">商务跟进中</p>
            <p class="mt-1 text-xl font-bold text-blue-600">{{ $counts['contacting'] ?? 0 }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
            <p class="text-xs text-emerald-600">已通过</p>
            <p class="mt-1 text-xl font-bold text-emerald-600">{{ $counts['approved'] ?? 0 }}</p>
        </div>
        <div class="box-shadow rounded bg-white p-3 dark:bg-gray-900">
            <p class="text-xs text-red-600">未通过</p>
            <p class="mt-1 text-xl font-bold text-red-600">{{ $counts['rejected'] ?? 0 }}</p>
        </div>
    </div>

    {{-- 快速创建供应链 / 生态邀请码面板 --}}
    <div class="mb-4 box-shadow rounded border border-emerald-100 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <p class="text-sm font-bold text-gray-800 dark:text-white">快速创建供应链 / 生态受邀入驻邀请码</p>
                <p class="text-xs text-gray-500">
                    在此直接生成邀请码，复制后可发给供应商或合作方在 C 端「我的 &rarr; 供应商邀请」或「生态合作」中核验使用。
                </p>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.customers.cooperations.invites.store') }}" class="grid items-end gap-3 md:grid-cols-5">
            @csrf

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">自定义码（选填）</span>
                <input
                    type="text"
                    name="custom_code"
                    maxlength="40"
                    value="{{ old('custom_code') }}"
                    placeholder="留空自动生成"
                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm uppercase font-mono dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">邀请码备注（发给哪家供应商）</span>
                <input
                    type="text"
                    name="note"
                    maxlength="191"
                    value="{{ old('note') }}"
                    placeholder="例如：定向邀约-某某供应链-张经理"
                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">有效期</span>
                <select name="expires_in_days" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="30" selected>30 天（推荐）</option>
                    <option value="7">7 天</option>
                    <option value="90">90 天</option>
                    <option value="0">长期有效</option>
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">可用次数</span>
                <select name="max_uses" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="1" selected>1 次（单企专属）</option>
                    <option value="5">5 次（团队）</option>
                    <option value="10">10 次</option>
                    <option value="100">100 次</option>
                    <option value="255">255 次（长期不限）</option>
                </select>
            </label>

            <div>
                <button type="submit" class="primary-button w-full justify-center">立即创建邀请码</button>
            </div>
        </form>

        @if (! empty($recentInvites) && count($recentInvites) > 0)
            <div class="mt-3 border-t border-gray-100 pt-3 dark:border-gray-800">
                <p class="mb-2 text-xs font-semibold text-gray-600 dark:text-gray-300">最近生成的邀请码（点击邀请码可全选复制）：</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($recentInvites as $inv)
                        <div class="inline-flex items-center gap-2 rounded border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs dark:border-gray-700 dark:bg-gray-950">
                            <code class="select-all font-mono font-bold text-emerald-700 dark:text-emerald-400">{{ $inv->code }}</code>
                            <span class="text-gray-500">{{ $inv->note ?: '无备注' }}</span>
                            <span class="rounded bg-white px-1.5 py-0.5 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                已用 {{ (int) $inv->times_used }}/{{ (int) $inv->max_uses }}
                            </span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="mb-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <form method="GET" action="{{ route('admin.customers.cooperations.index') }}" class="grid items-end gap-3 md:grid-cols-5">
            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">合作类型</span>
                <select name="coop_type" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="">全部类型</option>
                    <option value="supplier_invite" @selected($coopType === 'supplier_invite')>供应链受邀入驻（{{ $counts['supplier_invite'] ?? 0 }}）</option>
                    <option value="manufacturer" @selected($coopType === 'manufacturer')>本体厂商（{{ $counts['manufacturer'] ?? 0 }}）</option>
                    <option value="raas" @selected($coopType === 'raas')>RaaS合伙人（{{ $counts['raas'] ?? 0 }}）</option>
                    <option value="influencer" @selected($coopType === 'influencer')>网红达人（{{ $counts['influencer'] ?? 0 }}）</option>
                    <option value="partner_referral" @selected($coopType === 'partner_referral')>合伙人推荐（{{ $counts['partner_referral'] ?? 0 }}）</option>
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">审核状态</span>
                <select name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white">
                    <option value="">全部状态</option>
                    <option value="pending" @selected($status === 'pending')>待审核</option>
                    <option value="contacting" @selected($status === 'contacting')>商务跟进中</option>
                    <option value="approved" @selected($status === 'approved')>已通过</option>
                    <option value="rejected" @selected($status === 'rejected')>未通过</option>
                </select>
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">跟进人员</span>
                <input
                    type="text"
                    name="followup_person"
                    value="{{ $followupPerson }}"
                    placeholder="输入跟进人姓名"
                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
            </label>

            <label class="block">
                <span class="mb-1 block text-xs text-gray-600 dark:text-gray-300">关键词（单号 / 主体 / 手机）</span>
                <input
                    type="text"
                    name="q"
                    value="{{ $keyword }}"
                    placeholder="输入单号、公司或手机号"
                    class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >
            </label>

            <div class="flex gap-2">
                <button type="submit" class="primary-button">筛选</button>
                <a href="{{ route('admin.customers.cooperations.index') }}" class="secondary-button">重置</a>
            </div>
        </form>
    </div>

    <div class="box-shadow overflow-x-auto rounded bg-white dark:bg-gray-900">
        <table class="w-full text-left text-sm text-gray-700 dark:text-gray-200">
            <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-600 dark:border-gray-800 dark:bg-gray-950 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">申请单号</th>
                    <th class="px-4 py-3">合作类型</th>
                    <th class="px-4 py-3">主体/企业/达人名称</th>
                    <th class="px-4 py-3">合作方向</th>
                    <th class="px-4 py-3">联系人 & 电话</th>
                    <th class="px-4 py-3">在跟进人员</th>
                    <th class="px-4 py-3">状态</th>
                    <th class="px-4 py-3">提交时间</th>
                    <th class="px-4 py-3 text-right">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                @forelse ($applications as $item)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $item->application_no }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800 dark:bg-gray-800 dark:text-gray-200">
                                {{ \Webkul\Shop\Models\CooperationApplication::typeLabel($item->coop_type) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium">
                            <div>{{ $item->org_name }}</div>
                            @if (! empty($item->meta['invite_code']))
                                <div class="mt-0.5 text-xs text-emerald-700 dark:text-emerald-400">
                                    提交码：<span class="select-all font-mono">{{ $item->meta['invite_code'] }}</span>
                                    @if (! empty($item->meta['invite_verified']))
                                        <span class="ml-1 rounded bg-emerald-50 px-1 py-0.2 text-[10px] text-emerald-700">已核验</span>
                                    @endif
                                </div>
                            @endif
                            @if (! empty($item->meta['issued_invite_code']))
                                <div class="mt-0.5 text-xs text-blue-700 dark:text-blue-400">
                                    已签发码：<span class="select-all font-mono font-semibold">{{ $item->meta['issued_invite_code'] }}</span>
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $item->coop_direction ?: '—' }}</td>
                        <td class="px-4 py-3 text-xs">
                            <div>{{ $item->contact_name }}</div>
                            <div class="text-gray-500">{{ $item->contact_phone }}</div>
                        </td>
                        <td class="px-4 py-3 text-xs">
                            @php
                                $followerName = $item->meta['follower_name'] ?? $item->followup_person ?? null;
                            @endphp
                            @if (! empty($followerName))
                                <div class="font-medium text-gray-800 dark:text-white">{{ $followerName }}</div>
                                @if (! empty($item->meta['follower_contact']))
                                    <div class="text-gray-500">{{ $item->meta['follower_contact'] }}</div>
                                @endif
                            @else
                                <span class="text-gray-400">未指派</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded px-2 py-0.5 text-xs font-semibold
                                @if ($item->status === 'approved') bg-emerald-100 text-emerald-800
                                @elseif ($item->status === 'contacting') bg-blue-100 text-blue-800
                                @elseif ($item->status === 'rejected') bg-red-100 text-red-800
                                @else bg-amber-100 text-amber-800 @endif">
                                {{ \Webkul\Shop\Models\CooperationApplication::statusLabel($item->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $item->created_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="inline-flex items-center justify-end gap-2">
                                @if (empty($item->meta['issued_invite_code']))
                                    <form method="POST" action="{{ route('admin.customers.cooperations.issue_invite', $item->id) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="invite_expires_days" value="30">
                                        <button type="submit" class="rounded border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100">
                                            一键发邀请码
                                        </button>
                                    </form>
                                @endif
                                <a href="{{ route('admin.customers.cooperations.show', $item->id) }}" class="text-blue-600 hover:underline">
                                    查看 / 处置
                                </a>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-400">暂无合作申请记录</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $applications->links() }}
    </div>
</x-admin::layouts>
