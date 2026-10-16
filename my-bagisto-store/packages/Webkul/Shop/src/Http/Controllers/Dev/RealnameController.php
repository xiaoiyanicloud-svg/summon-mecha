<?php

namespace Webkul\Shop\Http\Controllers\Dev;

use Illuminate\Http\Request;
use Webkul\Shop\Http\Controllers\Controller;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Services\Developer\DeveloperRealnameService;

/**
 * 开发者门户 · 实名认证提交页 —— S-12b。
 *
 * 只做"校验 + 调 service + 渲染"。**归属与业务规则都在 service**：
 *   · 业务规则（两层已 approved 不许再提交）在 `DeveloperRealnameService::canSubmit()`；
 *   · 落库/落盘在 `DeveloperRealnameService::submit()`。
 *
 * ⚠ 对外口径：本页一律写「**平台人工审核**」。**严禁**出现"已完成身份核验 / 自动核验"——
 *   真 OCR / 工商核验未接入（拍板 §11.8 前置声明）。
 *
 * 字段级错误：校验走 `$request->validate()`，错误 key 就是字段名，前端 `@error` 精确指到字段。
 */
class RealnameController extends Controller
{
    public function __construct(protected DeveloperRealnameService $realname) {}

    public function form(Request $request)
    {
        $profile = $this->profile($request);

        return view('shop::dev.realname.form', [
            'profile'   => $profile,
            // 是否还能提交（两层都没 approved）；置 false 时表单只读、如实说明"改主体联系平台"
            'canSubmit' => $this->realname->canSubmit($profile),
        ]);
    }

    public function store(Request $request)
    {
        $profile = $this->profile($request);

        $payload = $request->validate([
            'company_name'        => ['required', 'string', 'max:191'],
            'company_credit_code' => ['required', 'string', 'regex:'.DeveloperRealnameService::CREDIT_CODE_PATTERN],
            'operator_name'       => ['required', 'string', 'max:191'],
            'operator_cert_no'    => ['required', 'string', 'max:64'],
            'license'             => [
                'required', 'file',
                'max:'.DeveloperRealnameService::MAX_LICENSE_KB,
                'mimes:jpg,jpeg,png,pdf',
            ],
        ], [
            'company_name.required'        => '请填写公司主体名称。',
            'company_credit_code.required' => '请填写统一社会信用代码。',
            'company_credit_code.regex'    => '统一社会信用代码格式不正确（应为 18 位，且不含字母 I/O/S/V/Z）。',
            'operator_name.required'       => '请填写操作人姓名。',
            'operator_cert_no.required'    => '请填写操作人证件号。',
            'license.required'             => '请上传营业执照附件。',
            'license.mimes'                => '营业执照附件仅支持 JPG / PNG / PDF。',
            'license.max'                  => '营业执照附件过大（上限 '.DeveloperRealnameService::MAX_LICENSE_KB.'KB）。',
        ]);

        $result = $this->realname->submit(
            $profile,
            [
                'name'        => $payload['company_name'],
                'credit_code' => strtoupper(trim($payload['company_credit_code'])),
            ],
            [
                'name'    => $payload['operator_name'],
                'cert_no' => $payload['operator_cert_no'],
            ],
            $request->file('license'),
        );

        if (! $result['ok']) {
            // 业务规则失败（如已通过 → 不许再提交）：错误挂在 realname，文案由 service 给（含错误码语义）
            return back()->withInput()->withErrors(['realname' => $result['message']]);
        }

        return redirect()
            ->route('shop.dev.realname')
            ->with('success', $result['message']);
    }

    protected function profile(Request $request): DeveloperProfile
    {
        // 由 `developer` 中间件放入（见 AuthenticateDeveloper）
        return $request->attributes->get('developer_profile');
    }
}
