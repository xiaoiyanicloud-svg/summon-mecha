@extends('shop::dev.layouts.app')

@section('title', '实名认证 · 开发者中心')

@section('content')
    <h1>实名认证</h1>

    @php
        $layerLabel = function (?string $status): array {
            return match ($status) {
                \Webkul\Shop\Models\DeveloperProfile::REALNAME_APPROVED => ['已通过', 'ok'],
                \Webkul\Shop\Models\DeveloperProfile::REALNAME_REJECTED => ['已驳回', ''],
                \Webkul\Shop\Models\DeveloperProfile::REALNAME_PENDING  => ['待审核', ''],
                default                                                 => ['未提交', ''],
            };
        };
        // 操作人证件号：只回显首尾，中间打码（敏感个人信息，页面不原样铺开）
        $maskCert = function (?string $no): ?string {
            if (! $no) {
                return null;
            }
            $len = mb_strlen($no);
            if ($len <= 6) {
                return str_repeat('*', $len);
            }

            return mb_substr($no, 0, 3).str_repeat('*', $len - 6).mb_substr($no, -3);
        };
    @endphp

    <div class="card" style="max-width:680px">
        {{--
            ⚠ 诚实口径（拍板 §11.8 前置声明）：
              · 一律写「**平台人工审核**」；**严禁**写"已完成身份核验 / 自动核验"（真 OCR / 工商核验未接入）。
              · 时长**不作承诺**（平台一期没有实名审核 SLA）。
        --}}
        <div class="notice">
            <strong>这是一份由「平台人工审核」的资料。</strong>
            平台目前<strong>没有接入</strong> OCR 或工商核验服务 —— 提交后由运营<strong>人工</strong>核对，
            <strong>不是</strong>自动核验，也<strong>不代表</strong>平台已完成身份核验。
            审核时长以平台实际处理为准，<strong>本页不承诺具体时限</strong>。
        </div>

        <h2 style="margin-top:18px">提交前请准备</h2>
        <ul class="muted" style="line-height:2;margin:0;padding-left:20px">
            <li><strong>营业执照</strong>附件（JPG / PNG / PDF，≤ {{ (int) (\Webkul\Shop\Services\Developer\DeveloperRealnameService::MAX_LICENSE_KB / 1024) }}MB）</li>
            <li>公司<strong>主体名称</strong>、<strong>统一社会信用代码</strong>（18 位）</li>
            <li>操作人<strong>姓名</strong>、<strong>证件号</strong>（一期只收文本，<strong>不收证件影像</strong>）</li>
        </ul>

        <h2 style="margin-top:18px">当前状态</h2>
        <table>
            <tr>
                <th style="width:170px">实名总状态</th>
                <td>
                    @php [$tl, $tc] = $layerLabel($profile->realname_status); @endphp
                    <span class="badge {{ $tc }}">{{ $tl }}</span>
                    <span class="muted">（由两层状态合并；两层都通过才算通过）</span>
                </td>
            </tr>
            <tr>
                <th>企业层</th>
                <td>
                    @php [$cl, $cc] = $layerLabel($profile->company_realname_status); @endphp
                    <span class="badge {{ $cc }}">{{ $cl }}</span>
                    @if ($profile->company_name)
                        <span class="muted">· {{ $profile->company_name }}（{{ $profile->company_credit_code }}）</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th>操作人层</th>
                <td>
                    @php [$ol, $oc] = $layerLabel($profile->operator_realname_status); @endphp
                    <span class="badge {{ $oc }}">{{ $ol }}</span>
                    @if ($profile->operator_name)
                        <span class="muted">· {{ $profile->operator_name }}（{{ $maskCert($profile->operator_cert_no) }}）</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th>营业执照附件</th>
                <td>
                    @if ($profile->company_cert_name)
                        {{ $profile->company_cert_name }}
                        <span class="muted">（已提交，存于平台私有存储）</span>
                    @else
                        <span class="muted">未提交</span>
                    @endif
                </td>
            </tr>
            <tr>
                <th>最近提交时间</th>
                <td>{{ $profile->realname_submitted_at?->format('Y-m-d H:i') ?? '—' }}</td>
            </tr>
        </table>
    </div>

    @if (! $canSubmit)
        {{-- 任一层已 approved → 不允许覆盖（改主体需联系平台） --}}
        <div class="card" style="max-width:680px">
            <h2>实名已通过</h2>
            <p class="muted">
                企业层或操作人层已审核通过，本页不再接受再次提交。
                如需<strong>变更实名主体</strong>（换公司 / 换操作人），请联系平台运营处理。
            </p>
        </div>
    @else
        <div class="card" style="max-width:680px">
            <h2>{{ $profile->realname_submitted_at ? '重新提交实名资料' : '提交实名资料' }}</h2>
            <p class="muted">
                重新提交会把<strong>两层一并退回「待审核」</strong>，由运营重新人工审核。
                重复提交同一份不会堆文件（同名覆盖，旧附件会删除）。
            </p>

            <form method="POST" action="{{ route('shop.dev.realname.store') }}" enctype="multipart/form-data">
                @csrf

                <label for="company_name">公司主体名称</label>
                <input id="company_name" type="text" name="company_name"
                       value="{{ old('company_name', $profile->company_name) }}" required maxlength="191">
                @error('company_name')<div class="flash error" style="margin-top:6px">{{ $message }}</div>@enderror

                <label for="company_credit_code">统一社会信用代码</label>
                <input id="company_credit_code" type="text" name="company_credit_code"
                       value="{{ old('company_credit_code', $profile->company_credit_code) }}" required maxlength="64"
                       placeholder="18 位，例如 91310000MA1K35XXXX">
                @error('company_credit_code')<div class="flash error" style="margin-top:6px">{{ $message }}</div>@enderror

                <label for="operator_name">操作人姓名</label>
                <input id="operator_name" type="text" name="operator_name"
                       value="{{ old('operator_name', $profile->operator_name) }}" required maxlength="191">
                @error('operator_name')<div class="flash error" style="margin-top:6px">{{ $message }}</div>@enderror

                <label for="operator_cert_no">操作人证件号</label>
                <input id="operator_cert_no" type="text" name="operator_cert_no"
                       value="{{ old('operator_cert_no') }}" required maxlength="64"
                       placeholder="仅收集文本，不收证件影像">
                @error('operator_cert_no')<div class="flash error" style="margin-top:6px">{{ $message }}</div>@enderror

                <label for="license">营业执照附件（JPG / PNG / PDF）</label>
                <input id="license" type="file" name="license" accept=".jpg,.jpeg,.png,.pdf" required>
                @error('license')<div class="flash error" style="margin-top:6px">{{ $message }}</div>@enderror

                @error('realname')<div class="flash error" style="margin-top:10px">{{ $message }}</div>@enderror

                <div style="margin-top:18px">
                    <button type="submit">提交实名资料</button>
                    <a class="btn ghost" href="{{ route('shop.dev.dashboard') }}">取消</a>
                </div>
            </form>
        </div>
    @endif
@endsection
