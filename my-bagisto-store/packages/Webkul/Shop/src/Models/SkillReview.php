<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 技能评价 —— 加固计划 §四 A1（增长功能）。
 *
 * 与举报（`SkillReport`）是**两条不同的轴**，别混：
 *   · 举报 = 合规（违规技能唯一用户侧通道，见 SkillReport）；
 *   · 评价 = 增长（真实使用反馈，决定后来者买不买）。
 * 两者都走同一套内容安全链路，但口径刻意不同（详见 SkillReviewService 注释）。
 *
 * ⚠ 与「审核台」的 `SkillReviewController` 只是英文重名：那边审的是**技能版本准入**
 *   （skill_versions），这边是**用户写的评价**（skill_review）。
 *
 * 状态机（三种，没有第四种）：
 *   `pending` 待人工看（机审没跑成）
 *   `visible` 可见（机审显式通过）
 *   `hidden`  不可见（机审命中违规，或人工隐藏）
 *
 * ⚠ 迁移 000007 把默认值从 `visible` 改成 `pending`，立场是「先审后发」：
 *   **要可见必须显式写下 `visible`** —— 也就是必须真有一个环节做了判断。
 *   本模型的写入方（SkillReviewService）严格遵守这条，不靠默认值。
 */
class SkillReview extends Model
{
    protected $table = 'skill_review';

    public const STATUS_PENDING = 'pending';

    public const STATUS_VISIBLE = 'visible';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_LABELS = [
        self::STATUS_PENDING => '待人工看',
        self::STATUS_VISIBLE => '可见',
        self::STATUS_HIDDEN  => '不可见',
    ];

    // ── 上限（校验与后台展示共用一处，避免两边各写一个数字）───────────────

    public const RATING_MIN = 1;

    public const RATING_MAX = 5;

    /** 标题：小程序评价卡片的主文案，太长会被截断，故比正文紧得多 */
    public const TITLE_MAX = 60;

    public const CONTENT_MAX = 1000;

    public const MEDIA_MAX = 3;

    public const MEDIA_MAX_KB = 5120;

    /**
     * 只收图片。
     *
     * ⚠ 不收视频：技能评价里的视频会是"实拍机器人现场"，里面极可能出现人脸/居家环境，
     *   而本层内容安全**只扫文本**（`LocalKeywordScanner` 的能力边界写得很清楚）。
     *   收下就等于承诺了一件我们查不了的事 —— 所以先不收，等有能审图/审视频的驱动再说。
     */
    public const MEDIA_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    protected $fillable = [
        'skill_id',
        'skill_version_id',
        'customer_id',
        'order_id',
        'title',
        'rating',
        'content',
        'media',
        'is_anonymous',
        'status',
        'reply',
        'replied_at',
        'moderation_note',
    ];

    protected $casts = [
        'skill_id'         => 'integer',
        'skill_version_id' => 'integer',
        'customer_id'      => 'integer',
        'order_id'         => 'integer',
        'rating'           => 'integer',
        'media'            => 'array',
        'is_anonymous'     => 'boolean',
        'reply'            => 'array',
        'replied_at'       => 'datetime',
    ];

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? (string) $this->status;
    }

    public function isVisible(): bool
    {
        return $this->status === self::STATUS_VISIBLE;
    }

    /** 对外可见的评价 —— 列表/详情要用的就只有这一种 */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VISIBLE);
    }
}
