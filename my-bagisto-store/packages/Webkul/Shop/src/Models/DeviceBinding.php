<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 全局设备绑定 —— 设备唯一真相源(v3.4 步骤 3 新增)。
 *
 * 语义分层(计划 §3.2):
 *   devices        = 用户自报登记(弱验证,verify_status 标记)
 *   device_binding = 平台确权设备(强验证:Agent 握手 / 厂商 API 校验)
 *   下发链路只认 binding。
 *
 * ⚠ 只保留 devices.binding_id 单向引用,本表不再反向指 devices —— 避免双向可空循环。
 *
 * ⚠⚠ **`online_status` 这一列不表示"现在在线"，不要再拿它当在线状态读。**
 *   全仓只有四处写它，**每一处都写 `true`，没有任何地方写 `false`**
 *   （`AgentController` 的握手/心跳/回报/取指令）。也就是说一台三个月前就断线的设备，
 *   这一列至今还写着"在线"。读它得到的其实是**"这台设备曾经连上过 Agent"**。
 *
 *   判断"现在在线"请一律用 `isOnlineNow()` / `scopeOnlineNow()` —— 基于 `last_online_at`
 *   的 90 秒窗口，**这是全仓唯一定义**（此前 `HttpPollAgentGateway` 里另有一份同义实现，
 *   而另外几个读点读的却是那个永不复位的粘滞位 —— 两套判断必然不一致）。
 *
 *   保留这一列是因为它作为"曾经配对过"的旁证仍有价值，但别再当在线状态读。
 */
class DeviceBinding extends Model
{
    protected $table = 'device_binding';

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_VENDOR = 'vendor';

    public const SOURCE_MANUAL = 'manual';

    /**
     * "在线"的判定窗口（秒）。
     *
     * 为什么是 90：设备端 Agent 是主动轮询拉指令的（`HttpPollAgentGateway`），
     * 轮询间隔远小于这个数。窗口太短会把"刚好卡在两次轮询之间"误判成离线；
     * 太长则断线后仍显示在线。90 秒是"至少漏过一次轮询"的量级。
     */
    public const ONLINE_WINDOW_SECONDS = 90;

    protected $fillable = [
        'did',
        'national_code',
        'source',
        'vendor_code',
        'sn',
        'model_id',
        'owner_customer_id',
        'devices_id',
        'bind_status',
        'online_status',
        'online_channel',
        'last_online_at',
        'bound_at',
        'unbound_at',
        'meta',
    ];

    protected $casts = [
        'model_id'       => 'integer',
        'online_status'  => 'boolean',
        'last_online_at' => 'datetime',
        'bound_at'       => 'datetime',
        'unbound_at'     => 'datetime',
        'meta'           => 'array',
    ];

    public function model(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'model_id');
    }

    /**
     * 这条绑定记录**此刻**是否在线（读时判断，不看粘滞列）。
     */
    public function isOnlineNow(): bool
    {
        return $this->last_online_at !== null
            && $this->last_online_at->greaterThanOrEqualTo(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }

    /**
     * 记一次"这台设备刚连上来"（握手 / 心跳 / 回报 / 取指令都走这里）。
     *
     * ⚠ 只设置字段，**不落库** —— 调用方随后自行 `save()`（中间往往还要改 `meta`）。
     *
     * ⚠ 两个字段语义不同，别混：
     *   - `last_online_at` = **真相**。在线判定读它（`isOnlineNow()` / `scopeOnlineNow()`）。
     *   - `online_status`  = **历史标记**。只写成 `true`、全仓从没写过 `false`，
     *     它记的是"这台设备曾经配对成功过"，**不是"现在在线"**。
     *     保留它是因为配对证据有用；判断在线不要读它。
     */
    public function markSeen(?string $channel = null): void
    {
        $this->online_status = true;
        $this->last_online_at = now();

        if ($channel !== null) {
            $this->online_channel = $channel;
        }
    }

    /**
     * 在线绑定的查询作用域 —— 统计/列表用它，别在 SQL 里手写时间窗口。
     *
     * ⚠ 与 `isOnlineNow()` 是**同一套判据的两种形态**：一个给单条记录用，
     *   一个给集合用。改窗口时只会改到上面那个常量。
     */
    public function scopeOnlineNow(Builder $query): Builder
    {
        return $query->whereNotNull('last_online_at')
            ->where('last_online_at', '>=', now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }
}
