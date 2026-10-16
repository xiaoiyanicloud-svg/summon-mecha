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
 *   它**不拦提审**（拦提审的唯一闸门是"协议已签"），但**拦上架** ——
 *   SkillPublisher 要求主体 realname_status = approved 才准发布（任务卡 D-5 · 裁决 Q6）。
 *   不要为了"看起来完整"伪造 approved。
 */
class DeveloperProfile extends Model
{
    public const DEV_TYPE_PERSONAL = 'personal';

    public const DEV_TYPE_COMPANY = 'company';

    public const REALNAME_PENDING = 'pending';

    public const REALNAME_APPROVED = 'approved';

    public const REALNAME_REJECTED = 'rejected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'developer_profile';

    protected $fillable = [
        'customer_id',
        'seller_id',
        'invite_id',
        'dev_type',
        'realname_status',
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
}
