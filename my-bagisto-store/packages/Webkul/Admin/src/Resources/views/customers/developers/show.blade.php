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

            <div class="mt-4 rounded border border-dashed border-gray-300 p-3 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-300">
                实名审批已改为<b>按层</b>进行（企业层 / 操作人层各自裁定，见下方「实名两层资料」卡的<b>逐层审批</b>）。
                <br>
                上面原来的「直接置总状态」两个入口已从界面撤下（路由保留，仅为兼容老的整状态流程）。
            </div>
        </div>
    </div>

    {{-- 实名两层资料 + 逐层审批台（S-12b 只读展示 · S-12c 加动作） --}}
    <div class="mt-4 box-shadow rounded bg-white p-4 dark:bg-gray-900">
        <p class="mb-1 font-semibold text-gray-800 dark:text-white">实名两层资料 · 逐层审批</p>
        <p class="mb-3 text-xs text-amber-700">
            ⚠ 这是开发者在门户提交的实名资料（<b>结构化文本 + 营业执照附件</b>）。
            平台<b>人工审核</b>，<b>未接入</b> OCR / 工商核验 —— 「通过」≠ 已完成身份核验。
        </p>

        @php
            $layerBadge = function (?string $status): array {
                return match ($status) {
                    \Webkul\Shop\Models\DeveloperProfile::REALNAME_APPROVED => ['已通过', 'bg-green-100 text-green-800'],
                    \Webkul\Shop\Models\DeveloperProfile::REALNAME_REJECTED => ['已驳回', 'bg-red-100 text-red-800'],
                    \Webkul\Shop\Models\DeveloperProfile::REALNAME_PENDING  => ['待审核', 'bg-amber-100 text-amber-800'],
                    default                                                 => ['未提交', 'bg-gray-100 text-gray-700'],
                };
            };

            // 两层定义（用模型常量，界面里不写裸字符串）
            $layerDefs = [
                [
                    'key'    => \Webkul\Shop\Models\DeveloperProfile::REALNAME_LAYER_COMPANY,
                    'label'  => '企业层（公司认证）',
                    'status' => $profile->company_realname_status,
                ],
                [
                    'key'    => \Webkul\Shop\Models\DeveloperProfile::REALNAME_LAYER_OPERATOR,
                    'label'  => '操作人层（操作人认证）',
                    'status' => $profile->operator_realname_status,
                ],
            ];
        @endphp

        <table class="w-full text-sm">
            <tbody>
                <tr class="border-b dark:border-gray-800">
                    <td class="py-2 text-xs text-gray-500">企业层（公司认证）</td>
                    <td class="py-2">
                        @php [$l1, $c1] = $layerBadge($profile->company_realname_status); @endphp
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
                        @php [$l2, $c2] = $layerBadge($profile->operator_realname_status); @endphp
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

        {{-- 逐层审批：两层各自「通过 / 驳回（填理由）」 --}}
        <div class="mt-5 border-t pt-4 dark:border-gray-800">
            <p class="mb-1 font-semibold text-gray-800 dark:text-white">逐层审批</p>
            <p class="mb-3 text-xs leading-relaxed text-amber-700">
                ⚠ <b>驳回任一层</b> → 实名总状态即 <b>rejected</b> → 该主体<b>既不能创建技能，也不能上架</b>。
                这是<b>有意</b>语义：实名被撤销即失去资格（需修正后重新提交）。
                <br>
                ⚠ 存量主体（总状态已通过、两层为空）请直接点<b>「两层一起通过」</b>补审，避免逐层分别操作。
            </p>

            <div class="space-y-4">
                @foreach ($layerDefs as $layerDef)
                    <div class="rounded border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm text-gray-700 dark:text-gray-200">{{ $layerDef['label'] }}</span>
                            @php [$lbl, $cls] = $layerBadge($layerDef['status']); @endphp
                            <span class="rounded px-2 py-0.5 text-xs {{ $cls }}">{{ $lbl }}</span>
                        </div>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            <form method="POST"
                                  action="{{ route('admin.customers.developers.realname.layer.approve', [$profile->id, $layerDef['key']]) }}">
                                @csrf
                                <button type="submit"
                                        class="w-full rounded bg-green-600 px-4 py-2 text-sm text-white hover:bg-green-700">
                                    通过本层
                                </button>
                            </form>

                            <form method="POST"
                                  action="{{ route('admin.customers.developers.realname.layer.reject', [$profile->id, $layerDef['key']]) }}"
                                  class="flex items-start gap-2">
                                @csrf
                                <input type="text" name="reason" placeholder="驳回理由（必填）"
                                       class="w-full rounded border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800">
                                <button type="submit"
                                        class="shrink-0 rounded bg-red-600 px-4 py-2 text-sm text-white hover:bg-red-700">
                                    驳回本层
                                </button>
                            </form>
                        </div>

                        @error('reason')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach
            </div>

            <form method="POST"
                  action="{{ route('admin.customers.developers.realname.layer.approve_all', $profile->id) }}"
                  class="mt-4">
                @csrf
                <button type="submit"
                        class="rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">
                    两层一起通过
                </button>
                <span class="ml-2 text-xs text-gray-500">便捷入口 —— 企业层 / 操作人层各留<b>一条</b>审核痕（共两条）。</span>
            </form>
        </div>
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
