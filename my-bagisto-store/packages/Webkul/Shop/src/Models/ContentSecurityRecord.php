<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 内容安全检测记录（v3.4 计划 §4.16）—— 机器扫了什么 + 人怎么处置的。
 *
 * ⚠ **`target_id` 指向什么，由 `target_type` 决定**（表只有一列 id，没有多态约束）：
 *   - `skill_meta` → **`skill_versions.id`**（提审时扫的是"这个版本的元信息"）
 *   - `review` / `icon` / `preview` → 对应业务对象各自的 id
 *   - `skill_report` → **`skill_report.id`**（用户举报的正文；举报单本身也要过词表）
 *   查之前先确认 target_type，别默认它是 skill_id。
 *
 * 与 `audit_record` 的关系：`audit_record` 记的是**准入判定**（过/不过），
 * 本表记的是**扫描明细**（扫了什么、命中什么、谁处置的）。一个版本可以有多条扫描记录。
 */
class ContentSecurityRecord extends Model
{
    protected $table = 'content_security_record';

    /**
     * 扫描结果（机器给的）。
     */
    public const RESULT_PASS = 'pass';

    /** 需要人工看 —— 包括"扫出了东西"和"根本没扫成"两种情况 */
    public const RESULT_REVIEW = 'review';

    public const RESULT_BLOCK = 'block';

    /**
     * 人工处置状态。
     */
    public const DISPOSITION_PENDING = 'pending';

    /** 人工放行：确认是误判 / 可以接受 */
    public const DISPOSITION_CLEARED = 'cleared';

    /** 已按违规处置：下架 / 驳回 / 通知整改 */
    public const DISPOSITION_ACTIONED = 'actioned';

    public const TARGET_SKILL_META = 'skill_meta';

    /** `skill_report.id` —— 用户举报的正文（见加固计划 §四 A1） */
    public const TARGET_SKILL_REPORT = 'skill_report';

    /** `skill_review.id` —— 用户评价的标题/正文 */
    public const TARGET_REVIEW = 'review';

    protected $fillable = [
        'target_type',
        'target_id',
        'provider',
        'scan_type',
        'result',
        'labels',
        'raw',
        'disposition',
        'disposition_note',
        'disposition_by',
        'disposition_at',
        'scanned_at',
    ];

    protected $casts = [
        'target_id'      => 'integer',
        'labels'         => 'array',
        'raw'            => 'array',
        'disposition_by' => 'integer',
        'disposition_at' => 'datetime',
        'scanned_at'     => 'datetime',
    ];

    /**
     * 该记录是否还等人处理。
     *
     * ⚠ `pass` 的记录**不需要人工处置**（机器扫过没问题，没人需要再看一遍）。
     *   把 pass 也塞进待办队列会让队列永远清不完，真正需要人看的那些被淹掉。
     */
    public function needsHumanAttention(): bool
    {
        return $this->result !== self::RESULT_PASS
            && $this->disposition === self::DISPOSITION_PENDING;
    }
}
