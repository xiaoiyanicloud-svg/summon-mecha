<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 技能/评价举报 —— 加固计划 §四 A1。
 *
 * 这是**违规技能唯一的用户侧举报通道**：`content_security_record` 记的是机器扫了什么，
 * `skill_blocklist` 是平台主动熔断，两者都不是"用户能按的那个按钮"。
 * 平台对外承诺"先审后发 + 内容安全"，那是**上线前的**闸门；上线之后
 * （技能已下发、已在真实机器上跑）唯一能兜底的就是这里。
 *
 * ⚠ 举报**不要求举报人持有该技能**。这是刻意的，也是与工单/事故（A4 要求
 *   `skill_id` 属于当前客户）**相反**的口径：
 *   工单/事故挂的是"我自己的东西出了问题"，而举报的对象恰恰**不该**是我买的技能 ——
 *   若要求持有才能举报，被举报的技能越违规、越没人能报，通道等于不存在。
 *   代价是任何人都能举报任意技能，故用去重（同一对象未处置期间只受理一条）
 *   + 限流（见 `throttle:report`）两件套兜住滥用。
 */
class SkillReport extends Model
{
    protected $table = 'skill_report';

    // ── 举报对象 ────────────────────────────────────────────────────────

    /** target_id = `product_id`（本项目里"技能"就是商品） */
    public const TARGET_SKILL = 'skill';

    /** target_id = `skill_review.id` */
    public const TARGET_REVIEW = 'review';

    /**
     * ⚠ 表注释里还写着第三个值 `developer`（target_id = 开发者）。
     *   本轮**不实现**：第三方开发者目前为 0（见项目简报"当前阶段是自营 + 客户需求两方在先"），
     *   为一个还不存在的对象做举报入口，就是为不存在的供给方设计功能。
     *   等开发者门户有真实供给、且能校验 seller_id 归属时再开。
     */
    public const TARGET_TYPES = [self::TARGET_SKILL, self::TARGET_REVIEW];

    // ── 理由类别 ────────────────────────────────────────────────────────

    /** 违法违规 */
    public const REASON_ILLEGAL = 'illegal';

    /** 安全风险 —— 可能伤人/损坏设备。机器人技能特有的类别，不是从电商抄来的 */
    public const REASON_SAFETY = 'safety';

    /** 侵权 —— 盗用他人作品/形象/商标 */
    public const REASON_INFRINGEMENT = 'infringement';

    /** 虚假宣传 / 欺诈 */
    public const REASON_FRAUD = 'fraud';

    /** 侵犯隐私 —— 未告知地采集人脸 / 录音 / 影像 */
    public const REASON_PRIVACY = 'privacy';

    public const REASON_OTHER = 'other';

    public const REASON_LABELS = [
        self::REASON_ILLEGAL      => '违法违规',
        self::REASON_SAFETY       => '安全风险（可能伤人 / 损坏设备）',
        self::REASON_INFRINGEMENT => '侵权（盗用他人作品 / 形象）',
        self::REASON_FRAUD        => '虚假宣传 / 欺诈',
        self::REASON_PRIVACY      => '侵犯隐私（未告知采集人脸 / 录音）',
        self::REASON_OTHER        => '其他',
    ];

    // ── 处置状态 ────────────────────────────────────────────────────────

    /** 待处置 —— 也是唯一占用去重槽位的状态 */
    public const STATUS_PENDING = 'pending';

    /** 举报成立（已按违规处置） */
    public const STATUS_ACCEPTED = 'accepted';

    /** 举报不成立（驳回） */
    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_PENDING  => '待处置',
        self::STATUS_ACCEPTED => '举报成立',
        self::STATUS_REJECTED => '举报不成立',
    ];

    /**
     * 去重槽位的占用值。
     *
     * 只有"未处置"才占用槽位：**处置完必须释放**（置 NULL），
     * 否则用户报了一次、被判不成立之后，这个对象就再也没人能举报了 ——
     * 那正是举报通道最该管用的场景。
     */
    public const OPEN_SLOT = 'open';

    protected $fillable = [
        'reporter_customer_id',
        'target_type',
        'target_id',
        'reason_category',
        'detail',
        'evidence',
        'status',
        'open_slot',
        'handler_id',
        'handled_at',
        'handling_note',
    ];

    protected $casts = [
        'reporter_customer_id' => 'integer',
        'target_id'            => 'integer',
        'evidence'             => 'array',
        'handler_id'           => 'integer',
        'handled_at'           => 'datetime',
    ];

    /**
     * 某个状态对应的去重槽位值（未处置 = 'open'，已处置 = NULL）。单一事实源。
     */
    public static function openSlotFor(string $status): ?string
    {
        return $status === self::STATUS_PENDING ? self::OPEN_SLOT : null;
    }

    public function reasonLabel(): string
    {
        return self::REASON_LABELS[$this->reason_category] ?? (string) $this->reason_category;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** 未处置的举报 —— 去重判断的基准集合 */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('open_slot', self::OPEN_SLOT);
    }
}
