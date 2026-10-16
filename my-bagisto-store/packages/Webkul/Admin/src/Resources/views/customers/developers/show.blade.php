<x-admin::layouts>
    <x-slot:title>开发者 #{{ $profile->id }}</x-slot:title>

    <div class="flex items-center justify-between gap-4 max-sm:flex-wrap">
        <div class="py-3">
            <p class="text-xl font-bold text-gray-800 dark:text-white">
                {{ $profile->seller?->name ?? $profile->customer?->email ?? ('开发者 #'.$profile->id) }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
                开发者 ID: {{ $profile->id }}　账号: {{ $profile->customer?->email ?? '—' }}
                　店铺主体 ID: {{ $profile->seller_id ?? '—' }}（归属锚点）　已建技能: {{ $skillCount }}
            </p>
        </div>
        <a href="{{ route('admin.customers.developers.index') }}">
            <span class="cursor-pointer text-sm text-blue-600 hover:underline">← 回列表</span>
        </a>
    </div>

    <x-admin::flash />

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- 主体信息 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-3 font-semibold text-gray-800 dark:text-white">主体信息</p>

            <table class="w-full text-sm">
                <tbody>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">主体类型</td>
                        <td class="py-2">
                            {{ $profile->dev_type === \Webkul\Shop\Models\DeveloperProfile::DEV_TYPE_COMPANY ? '企业' : '个人' }}
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">账号状态</td>
                        <td class="py-2">
                            @if ($profile->status === \Webkul\Shop\Models\DeveloperProfile::STATUS_ACTIVE)
                                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">正常</span>
                            @else
                                <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-800">已停用</span>
                            @endif
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">实名审核</td>
                        <td class="py-2">
                            @php
                                $map = [
                                    'pending'  => ['待审', 'bg-amber-100 text-amber-800'],
                                    'approved' => ['已通过（人工确认）', 'bg-green-100 text-green-800'],
                                    'rejected' => ['已驳回', 'bg-red-100 text-red-800'],
                                ];
                                [$rl, $rt] = $map[$profile->realname_status] ?? [$profile->realname_status, 'bg-gray-100'];
                            @endphp
                            <span class="rounded px-2 py-0.5 text-xs {{ $rt }}">{{ $rl }}</span>
                        </td>
                    </tr>
                    <tr class="border-b dark:border-gray-800">
                        <td class="py-2 text-xs text-gray-500">入驻时间</td>
                        <td class="py-2 text-xs">{{ $profile->created_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="py-2 text-xs text-gray-500">协议签署</td>
                        <td class="py-2 text-xs">
                            @if ($profile->agreements->isEmpty())
                                <span class="text-red-700">未签署</span>
                            @else
                                @foreach ($profile->agreements as $agreement)
                                    <div>{{ $agreement->doc_type }} v{{ $agreement->doc_version ?? '?' }}
                                        <span class="text-gray-500">{{ $agreement->signed_at?->format('Y-m-d H:i') }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- 提交的资质留档 --}}
        <div class="box-shadow rounded bg-white p-4 dark:bg-gray-900">
            <p class="mb-1 font-semibold text-gray-800 dark:text-white">提交的资质留档</p>
            <p class="mb-3 text-xs text-amber-700">
                ⚠ 这是入驻时**原样留档**的全部内容。看得出来：它<b>不含身份证件或营业执照</b> —— 证据强度就到这儿。
            </p>

            @php $cert = (array) ($profile->cert_payload ?? []); @endphp

            @if ($cert === [])
                <p class="text-sm text-gray-500">（入驻时未提交任何资质字段）</p>
            @else
                <table class="w-full text-sm">
                    <tbody>
                        @foreach ($cert as $key => $value)
                            <tr class="border-b last:border-b-0 dark:border-gray-800">
                                <td class="py-2 text-xs text-gray-500">{{ $key }}</td>
                                <td class="py-2">{{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <div class="mt-4 space-y-3">
                <form method="POST" action="{{ route('admin.customers.developers.realname.approve', $profile->id) }}">
                    @csrf
                    <input type="text" name="note" placeholder="审核备注（可选）"
                           class="mb-2 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <button type="submit" class="rounded bg-green-600 px-4 py-2 text-sm text-white hover:bg-green-700"
                            @disabled($profile->realname_status === \Webkul\Shop\Models\DeveloperProfile::REALNAME_APPROVED)>
                        人工确认通过
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.customers.developers.realname.reject', $profile->id) }}">
                    @csrf
                    <input type="text" name="reason" placeholder="驳回原因（必填）"
                           class="mb-2 w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                    <button type="submit" class="rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">
                        驳回
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- 实名两层资料（S-12b）—— 运营**只读**展示，看得见再决定批不批（逐层审批台 S-12c 再做） --}}
    <div class="mt-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-1 font-semibold text-gray-800 dark:text-white">实名两层资料</p>
        <p class="mb-3 text-xs text-amber-700">
            ⚠ 这是开发者在门户提交的实名资料（<b>结构化文本 + 营业执照附件</b>）。
            平台<b>人工审核</b>，<b>未接入</b> OCR / 工商核验 —— 「通过」≠ 已完成身份核验。
        </p>

        @php
            $layer = function (?string $status): array {
                return match ($status) {
                    \Webkul\Shop\Models\DeveloperProfile::REALNAME_APPROVED => ['已通过', 'bg-green-100 text-green-800'],
                    \Webkul\Shop\Models\DeveloperProfile::REALNAME_REJECTED => ['已驳回', 'bg-red-100 text-red-800'],
                    \Webkul\Shop\Models\DeveloperProfile::REALNAME_PENDING  => ['待审核', 'bg-amber-100 text-amber-800'],
                    default                                                 => ['未提交', 'bg-gray-100 text-gray-700'],
                };
            };
        @endphp

        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">企业层（公司认证）</td>
                    <td class="py-2">
                        @php [$l1, $c1] = $layer($profile->company_realname_status); @endphp
                        <span class="rounded px-2 py-0.5 text-xs {{ $c1 }}">{{ $l1 }}</span>
                    </td>
                </tr>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">公司主体名</td>
                    <td class="py-2">{{ $profile->company_name ?? '—' }}</td>
                </tr>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">统一社会信用代码</td>
                    <td class="py-2">{{ $profile->company_credit_code ?? '—' }}</td>
                </tr>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">营业执照附件</td>
                    <td class="py-2">
                        @if ($profile->company_cert_name)
                            <span>{{ $profile->company_cert_name }}</span>
                            <a href="{{ route('admin.customers.developers.cert.download', $profile->id) }}"
                               class="ml-2 text-blue-600 hover:underline">下载</a>
                        @else
                            <span class="text-gray-500">未提交</span>
                        @endif
                    </td>
                </tr>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">操作人层（操作人认证）</td>
                    <td class="py-2">
                        @php [$l2, $c2] = $layer($profile->operator_realname_status); @endphp
                        <span class="rounded px-2 py-0.5 text-xs {{ $c2 }}">{{ $l2 }}</span>
                    </td>
                </tr>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">操作人姓名</td>
                    <td class="py-2">{{ $profile->operator_name ?? '—' }}</td>
                </tr>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">操作人证件号</td>
                    <td class="py-2">{{ $profile->operator_cert_no ?? '—' }}</td>
                </tr>
                <tr>
                    <td class="py-2 text-xs text-gray-500">最近提交时间</td>
                    <td class="py-2 text-xs">{{ $profile->realname_submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            </tbody>
        </table>

        <p class="mt-3 text-xs text-gray-500">
            本卡只做<b>只读</b>展示；<b>逐层审批</b>与留痕是 S-12c。一期仍用下方「人工确认通过」置<b>总状态</b>。
        </p>
    </div>

    {{-- 账号状态 --}}
    <div class="mt-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-2 font-semibold text-gray-800 dark:text-white">账号处置</p>
        <p class="mb-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">
            停用<strong>不删数据</strong>：已售出的技能仍要能被下载（用户付过钱），
            停掉的只是"继续发布新技能"的能力。
        </p>
        <form method="POST" action="{{ route('admin.customers.developers.status', $profile->id) }}">
            @csrf
            <button type="submit"
                    class="rounded px-4 py-2 text-sm text-white {{ $profile->status === \Webkul\Shop\Models\DeveloperProfile::STATUS_ACTIVE ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }}">
                {{ $profile->status === \Webkul\Shop\Models\DeveloperProfile::STATUS_ACTIVE ? '停用该开发者' : '恢复该开发者' }}
            </button>
        </form>
    </div>

    {{-- 团队 --}}
    <div class="mt-4 box-shadow rounded bg-white dark:bg-gray-900">
        <p class="border-b px-4 py-3 font-semibold text-gray-800 dark:border-gray-800 dark:text-white">团队</p>
        <table class="w-full text-sm">
            <thead class="border-b text-left text-gray-600 dark:border-gray-800 dark:text-gray-300">
                <tr>
                    <th class="px-4 py-3">客户 ID</th>
                    <th class="px-4 py-3">角色</th>
                    <th class="px-4 py-3">状态</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($profile->teamMembers as $member)
                    <tr class="border-b last:border-b-0 dark:border-gray-800">
                        <td class="px-4 py-3">#{{ $member->customer_id }}</td>
                        <td class="px-4 py-3">{{ $member->role }}</td>
                        <td class="px-4 py-3">{{ $member->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">无团队成员记录。</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin::layouts>
