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
     * 开发者门户收件箱的可见范围（任务卡 D-4）。
     *
     * 收件人是**开发者本人的 customer 账号** —— 开发者就是那个客户（同一账号），
     * 见 `LifecycleNotifier::customerIdForSkillVersion` 的注释。只取 `skill.` 前缀的
     * 技能类通知：把买家事务（退款/下发/试用）挡在开发者中心之外 —— 那是同一个人
     * 在**另一个身份**下的事务，混进来只会让人以为"我买的技能出问题了"。
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
            ->where('type', 'like', 'skill.%');
    }
}
