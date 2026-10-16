<?php

namespace Webkul\Shop\Services\Developer;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;

/**
 * 开发者门户 · 实名资料提交 —— S-12b。
 *
 * 拍板（`docs/skill-dev-step9-closure-plan.md` §11.8）：
 *   · 一期 = **结构化文本 + 平台人工审核**：企业层收 公司主体名 + 统一社会信用代码 + 营业执照附件；
 *     操作人层收 姓名 + 证件号文本（**不收证件影像**）。
 *   · **不做**工商核验 / OCR 对接；对外口径只能是「**平台人工审核**」，
 *     **严禁**写"已完成身份核验 / 自动核验"。
 *
 * ⚠ 归属与鉴权在控制器/中间件完成；本服务只负责"业务规则 + 落库 + 落盘"。
 *   唯一的业务规则：**两层都 approved 的不许再提交**（已通过的实名不能被覆盖，
 *   改主体需联系平台）；两层是 pending/rejected/未提交的可以覆盖重提。
 *
 * ⚠ 落盘一律走**私有盘** `SkillAsset::disk()`（与技能包同一套私盘约束）：
 *   **严禁**落 public 盘，**严禁**把 file_path 透出到任何买家侧接口。
 *   路径/原文件名入 `company_cert_path` / `company_cert_name`。
 */
class DeveloperRealnameService
{
    /** 两层已 approved → 拒绝再次提交（改主体需联系平台）。 */
    public const ERR_ALREADY_APPROVED = 'REALNAME_ALREADY_APPROVED';

    /** 缺营业执照附件。 */
    public const ERR_LICENSE_REQUIRED = 'REALNAME_LICENSE_REQUIRED';

    /** 营业执照附件落盘失败。 */
    public const ERR_LICENSE_STORE_FAILED = 'REALNAME_LICENSE_STORE_FAILED';

    /**
     * 统一社会信用代码格式 —— 18 位，GB 32100-2015 字符集（**不含 I O S V Z**）。
     *
     * ⚠ 本仓**没有**既有的工商/信用代码校验惯例（rg 全仓无先例），故按拍板做
     *   "长度 + 字符集"的基本格式校验，**不做**工商核验（未接服务商，故意不假装能核验）。
     *   大小写不敏感（`i`）：输入先 strtoupper 归一，不强求用户敲大写。
     */
    public const CREDIT_CODE_PATTERN = '/^[0-9A-HJ-NPQRTUWXY]{18}$/i';

    /** 营业执照附件大小上限（KB）。≤ PHP upload_max_filesize（本机 20M）。 */
    public const MAX_LICENSE_KB = 10240;

    /**
     * 提交实名资料（企业层 + 操作人层 + 营业执照附件）。
     *
     * @param  array{name:string, credit_code:string}  $company   企业层（已由控制器校验）
     * @param  array{name:string, cert_no:string}      $operator  操作人层（已由控制器校验）
     * @return array{ok:bool, code:?string, message:string}
     */
    public function submit(DeveloperProfile $profile, array $company, array $operator, ?UploadedFile $license): array
    {
        // 业务规则：任一层已 approved → 拒绝再次提交（覆盖已通过的实名会毁掉审核依据）
        if (! $this->canSubmit($profile)) {
            return $this->fail(
                self::ERR_ALREADY_APPROVED,
                '实名已通过，不能再次提交；如需变更实名主体，请联系平台运营。'
            );
        }

        if (! $license || ! $license->isValid()) {
            return $this->fail(self::ERR_LICENSE_REQUIRED, '请上传营业执照附件（或上传失败，请重试）。');
        }

        // basename 清洗，防目录穿越（客户端可控的文件名）—— 与 AssetUploadService 同款
        $safeName = basename($license->getClientOriginalName());
        $safeName = preg_replace('/[^\w.\-\x{4e00}-\x{9fa5}]+/u', '_', $safeName) ?: 'license.bin';

        // 按归属锚点分目录；无归属（seller_id 为 NULL）退回 profile id，避免落到共用根目录
        $directory = 'realname/'.($profile->seller_id ?: $profile->id);

        $previousPath = $profile->company_cert_path;

        // 私有盘落盘。同名 storeAs 会覆盖 → 重复提交同一份不会堆文件（幂等）
        $path = $license->storeAs($directory, $safeName, SkillAsset::disk());

        if (! $path) {
            return $this->fail(self::ERR_LICENSE_STORE_FAILED, '营业执照附件落盘失败，请重试。');
        }

        // 换了文件名 → 新路径；先把旧文件删掉，别只往上堆（幂等）
        if ($previousPath && $previousPath !== $path
            && Storage::disk(SkillAsset::disk())->exists($previousPath)) {
            Storage::disk(SkillAsset::disk())->delete($previousPath);
        }

        // 两层文本落库
        $profile->company_name        = $company['name'];
        $profile->company_credit_code = $company['credit_code'];
        $profile->operator_name       = $operator['name'];
        $profile->operator_cert_no    = $operator['cert_no'];

        // 重新提交 → 两层一并退回 pending，等待平台人工审核
        $profile->company_realname_status  = DeveloperProfile::REALNAME_PENDING;
        $profile->operator_realname_status = DeveloperProfile::REALNAME_PENDING;
        $profile->realname_submitted_at    = now();
        $profile->company_cert_path        = $path;
        $profile->company_cert_name        = $safeName;

        // 显式重算总状态（唯一规则在模型里）—— 两层 pending → 总状态 pending
        $profile->recomputeRealnameStatus();
        $profile->save();

        return [
            'ok'      => true,
            'code'    => null,
            'message' => '实名资料已提交，等待平台人工审核（公司认证 + 操作人认证两层）。',
        ];
    }

    /**
     * 是否允许提交/重新提交 —— 两层**都未 approved** 才为真。
     *
     * 只要任一层 approved，就不允许覆盖（表单置只读、提交动作拒绝），
     * 与视图、控制器**共用这一处判定**（两处迟早漂移）。
     */
    public function canSubmit(DeveloperProfile $profile): bool
    {
        return $profile->company_realname_status !== DeveloperProfile::REALNAME_APPROVED
            && $profile->operator_realname_status !== DeveloperProfile::REALNAME_APPROVED;
    }

    /**
     * @return array{ok:false, code:string, message:string}
     */
    protected function fail(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
