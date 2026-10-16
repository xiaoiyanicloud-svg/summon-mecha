<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Webkul\Customer\Models\Customer;

/**
 * 开发者主体 —— v3.4 步骤 9（开发者门户）。
 *
 * 语义：**开发者 = 一行 customers + 一行 developer_profile**（customer_id 唯一）。
 *   - 有 profile 才算开发者；
 *   - `seller_id` 是**归属锚点** —— 技能（products.seller_id）与版本
 *     （skill_versions.seller_id）都由它派生。平台自营的 seller_id 为 NULL，
 *     与开发者（恒非 NULL）天然隔离，这是"两条通道不串"的机制。
 *
 * ⚠ realname_status 的审核动作属平台端，Admin 入驻审核 UI 已实现
 *   （`Webkul\Admin\Http\Controllers\Customers\DeveloperController::approveRealname` / `rejectRealname`）。
 *   它**不拦提审**（拦提审的唯一闸门是"协议已签"），但**拦建技能**（S-12a · 2026-10-16 拍板改判）
 *   与**拦上架**（任务卡 D-5 · 裁决 Q6）：
 *     · 建技能 —— 门户 `shop.dev.skills.store` 与 API `shop.api.dev.skills.store` 都要求
 *       realname_status = approved（判定统一走 `DeveloperSkillService::canCreateSkill()`，fail-closed）；
 *     · 上架 —— SkillPublisher 要求主体 realname_status = approved 才准发布。
 *   （早前本注释写"实名不影响建技能与提审"，S-12a 把拦截点前移后那句话已腐坏，据实改写。）
 *   不要为了"看起来完整"伪造 approved。
 *
 * ⚠ 实名**拆两层**（S-12a）：除总状态 `realname_status` 外，另有
 *   `company_realname_status`（公司认证）与 `operator_realname_status`（操作人认证）两列。
 *   两层由 `recomputeRealnameStatus()` **显式重算**回总状态（两层都 approved → approved；
 *   任一层 rejected → rejected；否则 pending）。S-12a 只加列与方法；**S-12b 接入提交调用点**
 *   （开发者门户实名提交页 → `DeveloperRealnameService::submit()` 写两层=pending 并重算总状态）；
 *   运营两层审批台（S-12c）后续接入。
 *   **存量不追溯**：历史主体只写了 realname_status=approved、两层列为 NULL，照旧放行。
 *
 *   S-12b 另加了四个**结构化文本**列（`company_name` / `company_credit_code` /
 *   `operator_name` / `operator_cert_no`）—— 拍板 E2 的口径是"加列、**不复用 `cert_payload`**、
 *   不建独立表"，故文本字段落在这里，**不可**改塞进 cert_payload。
 */
class DeveloperProfile extends Model
{
    public const DEV_TYPE_PERSONAL = 'personal';

    public const DEV_TYPE_COMPANY = 'company';

    public const REALNAME_PENDING = 'pending';

    public const REALNAME_APPROVED = 'approved';

    public const REALNAME_REJECTED = 'rejected';

    /**
     * 实名两层（S-12a）—— 开发者必须**两层都通过**才准建技能。
     *   company  公司认证（一期收营业执照文本 + 一张附件）
     *   operator 操作人认证（一期只收文本，不收证件影像 —— 收窄敏感信息存储面）
     * 取值沿用上面 `REALNAME_*` 那套（pending/approved/rejected），**不新造取值**。
     */
    public const REALNAME_LAYER_COMPANY = 'company';

    public const REALNAME_LAYER_OPERATOR = 'operator';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'developer_profile';

    protected $fillable = [
        'customer_id',
        'seller_id',
        'invite_id',
        'dev_type',
        'realname_status',
        'company_realname_status',
        'operator_realname_status',
        'realname_submitted_at',
        'company_cert_path',
        'company_cert_name',
        // S-12b · 实名「结构化文本」（E2：加列、不复用 cert_payload、不建表）
        'company_name',
        'company_credit_code',
        'operator_name',
        'operator_cert_no',
        'cert_payload',
        'agreement_signed_at',
        'api_quota',
        'status',
    ];

    protected $casts = [
        'customer_id'          => 'integer',
        'seller_id'            => 'integer',
        'invite_id'            => 'integer',
        'cert_payload'         => 'array',
        'api_quota'            => 'array',
        'agreement_signed_at'  => 'datetime',
        'realname_submitted_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(DeveloperAgreement::class, 'developer_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(DeveloperTeamMember::class, 'developer_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * 是否已签署全部必需协议（提审的流程闸门）。
     */
    public function hasSignedAllAgreements(): bool
    {
        $required = array_keys((array) config('developer_portal.agreements', []));

        if (empty($required)) {
            return true;
        }

        $signed = $this->agreements()
            ->whereIn('doc_type', $required)
            ->whereNotNull('signed_at')
            ->pluck('doc_type')
            ->all();

        return count(array_intersect($required, $signed)) === count($required);
    }

    /**
     * 实名是否已通过 —— **建技能前置**的判定依据（S-12a）。
     *
     * ⚠ 判据是**总状态** `realname_status`，**不是**两层的列，理由两条：
     *   ① 存量不追溯 —— 历史主体只写了 realname_status=approved、两层列为 NULL；
     *      若按两层列判，会把它们算回 pending，**大面积误拦**（拍板明确不许）；
     *   ② 口径一致 —— 建技能（此处）与上架（`SkillPublisher::ownerQualification`）读的是
     *      **同一个总状态**，不会出现"能建技能却永远上不了架"的自相矛盾。
     *   两层 → 总状态的合并只发生在 `recomputeRealnameStatus()`（唯一规则），
     *   谁触发它由后续卡（S-12b/c）接。
     */
    public function isRealnameApproved(): bool
    {
        return $this->realname_status === self::REALNAME_APPROVED;
    }

    /**
     * 由两层状态**显式重算**总状态 `realname_status`（S-12a）。
     *
     * 规则（拍板）：两层都 approved → approved；任一层 rejected → rejected；否则 pending。
     *
     * ⚠ **只算不改库、也不自动触发** —— 调用方负责 `save()`（后续卡在审批动作后调）。
     *   本卡**不接任何调用点**：存量 realname_status 原样不动（不追溯），
     *   新流程由 S-12b/c 接入后才生效。
     */
    public function recomputeRealnameStatus(): void
    {
        $company  = $this->company_realname_status;
        $operator = $this->operator_realname_status;

        if ($company === self::REALNAME_REJECTED || $operator === self::REALNAME_REJECTED) {
            $this->realname_status = self::REALNAME_REJECTED;

            return;
        }

        if ($company === self::REALNAME_APPROVED && $operator === self::REALNAME_APPROVED) {
            $this->realname_status = self::REALNAME_APPROVED;

            return;
        }

        $this->realname_status = self::REALNAME_PENDING;
    }
}
