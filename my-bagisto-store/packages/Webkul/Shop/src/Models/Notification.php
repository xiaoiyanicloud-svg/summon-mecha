<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 通知中心(v3.4 步骤 4)。
 *
 * channel: inapp(站内信,本次实现) / subscribe_msg(微信订阅消息,待小程序配置) / sms
 */
class Notification extends Model
{
    protected $table = 'notification';

    public const CHANNEL_INAPP = 'inapp';

    public const CHANNEL_SUBSCRIBE = 'subscribe_msg';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'type',
        'title',
        'content',
        'payload',
        'dedupe_key',
        'channel',
        'read_at',
        'sent_at',
    ];

    protected $casts = [
        'recipient_id' => 'integer',
        'payload'      => 'array',
        'read_at'      => 'datetime',
        'sent_at'      => 'datetime',
    ];

    /**
     * 开发者门户收件箱的可见范围（任务卡 D-4；S-12c 修 · 见下）。
     *
     * 收件人是**开发者本人的 customer 账号** —— 开发者就是那个客户（同一账号），
     * 见 `LifecycleNotifier::customerIdForSkillVersion` 的注释。可见类型**只两类**：
     *   - `skill.%`   技能类通知（提审/审核/上架/下发/试用）；
     *   - `realname.%` 开发者**实名审批**类通知（S-12c 加）。
     * 其余（买家事务 退款/售后 …）**一律挡住** —— 那是同一个人在**另一个身份**下的事务，
     * 混进来只会让人以为"我买的技能出问题了"。
     *
     * ⚠ **S-12c 为什么加 `realname.%`**：运营逐层驳回开发者实名后会发
     *   `realname.rejected`（见 `LifecycleNotifier::developerRealnameRejected`）。
     *   它收件人是开发者本人、C 端消息中心看得到，但门户收件箱此前只认 `skill.%`，
     *   于是门户里**看不到"我实名被驳回了"** —— 这正是 S-12c 主动报告的一处未闭合。
     *   修法是**放宽读侧可见类型**（而非把 type 硬凑成 `skill.realname.*` 前缀 —— 那是自欺）。
     *
     * ⚠⚠ **OR 组必须用闭包包起来**：`where('recipient_id',…)` 之外一旦裸着
     *   `orWhere('type','like',…)`，SQL 会变成
     *   `recipient_type='customer' AND recipient_id=? AND type LIKE 'skill.%' OR type LIKE 'realname.%'`
     *   —— 运算符优先级下，那半截 `orWhere` 会把 `recipient_id` 条件**短路掉**，
     *   于是**别人的 `realname.%` 通知也会被放进来**（严重越权）。
     *   闭包让分组塌成 `… AND (type LIKE 'skill.%' OR type LIKE 'realname.%')`，
     *   归属过滤始终是 AND 的一环。回归测试钉死了"看不到别人的通知"。
     *
     * 为什么写成 scope 而不是各写一份：读侧有三处（列表 / 标记已读 / 顶栏未读角标），
     * 三处必须**同一把尺子** —— 尺子分散了，迟早出现"列表看得到、角标数不到"的裂缝；
     * 而且这是一切写路径的**归属过滤**，只在一处定义才审计得动。
     */
    public function scopeDeveloperInbox(Builder $query, int $customerId): Builder
    {
        return $query
            ->where('recipient_type', 'customer')
            ->where('recipient_id', $customerId)
            ->where(function (Builder $q) {
                $q->where('type', 'like', 'skill.%')
                    ->orWhere('type', 'like', 'realname.%');
            });
    }
}
