<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 合规备案台账（v3.4 计划 §4.17）。
 *
 * 为什么需要一张表而不是记在文档里：备案不是"办完就完了"—— 它有**有效期与年检**。
 * 漏掉一次年检，整套备案可能失效，而失效期是不打招呼的。
 * 台账的价值就在于**提前看见到期**（见 `isExpiringSoon()` 与 `nextDueAt()`）。
 *
 * ⚠ **本表没有删除入口**。备案记录是"你确实办过这件事"的凭据，
 *   能被抹掉的台账不是台账。办错了改状态、改备注，不要删。
 *   确实作废的，改成 `REVOKED` 并写明原因 —— 作废本身也是一条要留档的事实。
 */
class ComplianceFiling extends Model
{
    protected $table = 'compliance_filing';

    /**
     * 备案类型。值域白名单 —— 与计划里要办的几件事一一对应。
     */
    public const TYPE_ICP = 'icp';                                   // ICP 备案（门户 + H5）

    public const TYPE_ALGORITHM = 'algorithm';                       // 算法备案（AI 三层之一）

    public const TYPE_LLM = 'llm';                                   // 大模型备案

    public const TYPE_AFFECTIVE = 'affective_interaction';           // 拟人化互动备案

    public const TYPE_EDI = 'edi';                                   // EDI 增值电信许可

    public const TYPE_MLPS = 'mlps';                                 // 等保 2.0 定级备案

    public const TYPE_OTHER = 'other';

    public const TYPE_LABELS = [
        self::TYPE_ICP        => 'ICP 备案',
        self::TYPE_ALGORITHM  => '算法备案',
        self::TYPE_LLM        => '大模型备案',
        self::TYPE_AFFECTIVE  => '拟人化互动备案',
        self::TYPE_EDI        => 'EDI 增值电信许可',
        self::TYPE_MLPS       => '等保定级备案',
        self::TYPE_OTHER      => '其他',
    ];

    /**
     * 状态。
     */
    public const STATUS_PENDING = 'pending';       // 待提交

    public const STATUS_SUBMITTED = 'submitted';   // 已提交，审批中

    public const STATUS_FILED = 'filed';           // 已备案

    public const STATUS_REJECTED = 'rejected';     // 被驳回

    public const STATUS_EXPIRED = 'expired';       // 已过期

    public const STATUS_REVOKED = 'revoked';       // 已作废（写原因，不删行）

    public const STATUS_LABELS = [
        self::STATUS_PENDING   => '待提交',
        self::STATUS_SUBMITTED => '审批中',
        self::STATUS_FILED     => '已备案',
        self::STATUS_REJECTED  => '被驳回',
        self::STATUS_EXPIRED   => '已过期',
        self::STATUS_REVOKED   => '已作废',
    ];

    /**
     * "即将到期"的提前量（天）。默认 90 天 —— 年检要走流程、要准备材料，
     * 提前一个月才知道等于来不及。
     */
    public const EXPIRING_SOON_DAYS = 90;

    protected $fillable = [
        'filing_type',
        'name',
        'filing_no',
        'authority',
        'scope',
        'filed_at',
        'expires_at',
        'annual_review_at',
        'status',
        'attachments',
    ];

    protected $casts = [
        'filed_at'         => 'date',
        'expires_at'       => 'date',
        'annual_review_at' => 'date',
        'attachments'      => 'array',
    ];

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->filing_type] ?? $this->filing_type;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /**
     * 下一个需要动作的日期：年检优先于到期（年检没做，到期前就可能失效）。
     */
    public function nextDueAt(): ?\Illuminate\Support\Carbon
    {
        $dates = array_filter([$this->annual_review_at, $this->expires_at]);

        if ($dates === []) {
            return null;
        }

        return collect($dates)->sort()->first();
    }

    /**
     * 是否即将到期/需年检。
     *
     * ⚠ 只对**已备案**的记录报 —— "待提交"的东西还没有到期一说，
     *   把它算进来会让提醒永远响（那这个提醒就没人看了）。
     */
    public function isExpiringSoon(): bool
    {
        if ($this->status !== self::STATUS_FILED) {
            return false;
        }

        $due = $this->nextDueAt();

        if (! $due) {
            return false;
        }

        // now → due 的天数：未来为正、已过为负。所以「≤ 90」同时覆盖
        // 「90 天内到期」与「已经过期」——两种都需要动作。
        return now()->diffInDays($due, false) <= self::EXPIRING_SOON_DAYS;
    }
}
