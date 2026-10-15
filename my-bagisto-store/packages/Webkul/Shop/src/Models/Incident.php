<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 事故上报与追溯（v3.4 计划 §4.19）。
 *
 * 为什么这个模块值得单独存在：本平台卖的是**会在真实机器人上物理执行的技能**。
 * 一旦伤了人或毁了物，"谁的责任"必须能被查清 —— 而查清的前提是
 * **报案那一刻就把证据链固化下来**（见 `IncidentService::traceFor()`）。
 * 事后版本会归档、包会被替换、设备绑定会改，那时候再回头查就查不清了。
 *
 * ⚠ `trace` 里的内容是**快照**，不要拿它跟当前数据比对后"更新" —— 它记的是历史。
 */
class Incident extends Model
{
    protected $table = 'incident';

    /**
     * 严重程度。
     */
    public const SEVERITY_MINOR = 'minor';       // 轻微：无人员伤害，轻微财产损失

    public const SEVERITY_MODERATE = 'moderate'; // 一般：需要停机检修

    public const SEVERITY_SEVERE = 'severe';     // 严重：人员受伤 / 重大财产损失

    public const SEVERITY_CRITICAL = 'critical'; // 重大：人身重伤或更重

    /**
     * 处理状态。
     */
    public const STATUS_REPORTED = 'reported';           // 已上报，未受理

    public const STATUS_INVESTIGATING = 'investigating'; // 调查中

    public const STATUS_ATTRIBUTED = 'attributed';       // 已定责

    public const STATUS_RESOLVED = 'resolved';           // 已解决

    public const STATUS_CLOSED = 'closed';               // 已结案

    /**
     * 责任归属。
     *
     * ⚠ `UNKNOWN` 是**默认且合法**的值 —— 报案时通常还不知道是谁的责任。
     *   不要为了"填满字段"而预设一个责任方，那是往追溯里掺假。
     */
    public const RESPONSIBILITY_UNKNOWN = 'unknown';

    public const RESPONSIBILITY_DEVELOPER = 'developer';

    public const RESPONSIBILITY_PLATFORM = 'platform';

    public const RESPONSIBILITY_VENDOR = 'vendor';

    public const RESPONSIBILITY_USER = 'user';

    public const RESPONSIBILITY_LABELS = [
        self::RESPONSIBILITY_UNKNOWN   => '尚未认定',
        self::RESPONSIBILITY_DEVELOPER => '开发者（技能）',
        self::RESPONSIBILITY_PLATFORM  => '平台',
        self::RESPONSIBILITY_VENDOR    => '厂商（设备）',
        self::RESPONSIBILITY_USER      => '用户（操作）',
    ];

    public const SEVERITY_LABELS = [
        self::SEVERITY_MINOR    => '轻微',
        self::SEVERITY_MODERATE => '一般',
        self::SEVERITY_SEVERE   => '严重',
        self::SEVERITY_CRITICAL => '重大',
    ];

    public const STATUS_LABELS = [
        self::STATUS_REPORTED      => '待受理',
        self::STATUS_INVESTIGATING => '调查中',
        self::STATUS_ATTRIBUTED    => '已定责',
        self::STATUS_RESOLVED      => '已解决',
        self::STATUS_CLOSED        => '已结案',
    ];

    /**
     * 合法状态流转表（A6）。
     *
     * 为什么需要它:此前 `advance()` 只校验"目标状态在枚举里",不校验"当前→目标是否合法"。
     * 于是 `closed → reported` 这种把已结案的案子打回"待受理"的跳跃能落库,
     * 且 `resolved_at` 不会被清 —— SLA 统计(`isOpen()` / 超时口径)随之失真。
     *
     * 表的方向:
     *   - 主线**只能向前**:reported → investigating → attributed → resolved → closed;
     *   - 但允许**有限回退**(重新打开/退回重查),因为现实里确实会:
     *     resolved/closed → investigating(重开),attributed → investigating(退回补查)。
     *     回退时 `resolved_at` 会被清空(见 `IncidentService::advance()`),让 SLA 口径回到"未结"。
     *   - 不允许从终态直接跳回 reported —— 那等于把已投入的处理记录"归零",没有业务含义。
     *
     * ⚠ 同状态视为合法(幂等):运营可能只为了补一句处置说明/改定责而重复提交同一状态。
     */
    public const STATUS_TRANSITIONS = [
        self::STATUS_REPORTED      => [self::STATUS_INVESTIGATING, self::STATUS_ATTRIBUTED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_INVESTIGATING => [self::STATUS_ATTRIBUTED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_ATTRIBUTED    => [self::STATUS_INVESTIGATING, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_RESOLVED      => [self::STATUS_INVESTIGATING, self::STATUS_CLOSED],
        self::STATUS_CLOSED        => [self::STATUS_INVESTIGATING],
    ];

    protected $fillable = [
        'incident_no',
        'skill_id',
        'skill_version_id',
        'device_binding_id',
        'customer_id',
        // B-10:本起事故若由某张工单定性而来,记下肇事工单(可空)
        'ticket_id',
        'severity',
        'occurred_at',
        'description',
        'evidence',
        'responsibility',
        'trace',
        'handling_log',
        'status',
        'insurance_claim',
        'resolved_at',
    ];

    protected $casts = [
        'skill_id'          => 'integer',
        'skill_version_id'  => 'integer',
        'device_binding_id' => 'integer',
        'customer_id'       => 'integer',
        'ticket_id'         => 'integer',
        'occurred_at'       => 'datetime',
        'resolved_at'       => 'datetime',
        'evidence'          => 'array',
        'trace'             => 'array',
        'handling_log'      => 'array',
        'insurance_claim'   => 'array',
    ];

    /**
     * 处置记录(append-only,A5)。
     *
     * ⚠ 处置记录**不再**读写 `handling_log` 那一列(见 `IncidentHandlingLog` 类注释:
     *   整列读改写会丢并发记录)。该列只为兼容历史数据保留。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function handlingEntries()
    {
        return $this->hasMany(IncidentHandlingLog::class, 'incident_id')->orderBy('id');
    }

    /**
     * 肇事工单(B-10)—— 本起事故若由某张用户工单定性而来,这里是它。
     *
     * 关系是"事故 → 工单"单向存储(`incident.ticket_id`);反方向(工单是否已定性事故)
     * 用 `SupportTicket::incident()` 反查,**不双写**,避免两处链接漂移。
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? ($this->severity ?: '未填');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function responsibilityLabel(): string
    {
        return self::RESPONSIBILITY_LABELS[$this->responsibility] ?? ($this->responsibility ?: '尚未认定');
    }

    /**
     * 是否还没结案。
     *
     * 用来把"还欠着处理的"和"已闭环的"分开 —— 未结案的事故是平台的风险敞口。
     */
    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    /**
     * 从当前状态可以流转到哪些状态（不含"保持原状"）。
     *
     * @return string[]
     */
    public function allowedTransitions(): array
    {
        return self::STATUS_TRANSITIONS[$this->status] ?? [];
    }

    /**
     * 当前 → $target 是否是一次合法流转（A6）。
     *
     * 同状态永远合法（幂等:只补说明/改定责）。
     */
    public function canTransitionTo(string $target): bool
    {
        if ($target === $this->status) {
            return true;
        }

        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * 是否达到"必须立即上报"的量级。
     *
     * 计划要求重伤以上走应急流程 —— 这里只做判定，**不自动对外上报**
     * （对外上报是人的动作，且依赖平台是否已完成备案，见第 10 步）。
     */
    public function requiresEscalation(): bool
    {
        return in_array($this->severity, [self::SEVERITY_SEVERE, self::SEVERITY_CRITICAL], true);
    }
}
