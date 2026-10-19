<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 赛事(Event)模型 —— 支撑首页生态中心「赛事活动」tab 的平台级闭环。
 *
 * 后台(Admin「赛事管理」)维护赛事 → 前台(Shop API)展示 → 用户报名落 event_registrations。
 */
class Event extends Model
{
    protected $table = 'events';

    protected $fillable = [
        'name',
        'type',
        'status',
        'date',
        'city',
        'location',
        'organizer',
        'hot',
        'teams',
        'prize',
        'schedule',
        'history',
        'detail',
        'icon',
        'bg_color',
        'sort',

        // G1-02 可信发布字段（ADR-0001 §4.2）。旧字段行为不变；
        // 新字段的并发写入口是 Services\Ecosystem\EventWriteService。
        'kind',
        'participation_mode',
        'publish_status',
        'disposition',
        'hosting_status',
        'timezone',
        'starts_at',
        'ends_at',
        'published_revision_id',
        'working_revision_id',
        // G1-08A：当前已批准候选版本（ADR 附录 B A-01）。
        // 普通写入口 EventWriteService 不透传；只由审核服务经受控校验写入。
        'approved_revision_id',
        // G1-08A 只建列；排期写入属于 G1-08B。
        'scheduled_publish_at',
        'first_published_at',
        'published_at',
        'row_version',

        // G1-03 来源与主办方（ADR-0001 §4.2）。三字段必须同时为空或同时齐全；
        // 并发写入口是 Services\Ecosystem\EventSourceService（草稿校验）→ EventWriteService。
        'source_type',
        'platform_role',
        'organizer_id',
    ];

    protected $casts = [
        // G1 时间字段以 UTC 存储（ADR G1-D04）。应用时区是 Asia/Shanghai，
        // 不能用默认 datetime cast 读取，否则会整体偏 8 小时。
        'starts_at'             => UtcDateTime::class,
        'ends_at'               => UtcDateTime::class,
        'first_published_at'    => UtcDateTime::class,
        'published_at'          => UtcDateTime::class,
        'published_revision_id' => 'integer',
        'working_revision_id'   => 'integer',
        'approved_revision_id'  => 'integer',
        'scheduled_publish_at'  => UtcDateTime::class,
        'row_version'           => 'integer',
        'organizer_id'          => 'integer',
    ];

    public function registrations()
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * 主办/承办主体（ADR-0001 G1-D01）。
     *
     * 只认 `organizer_id`，不得由商品 seller 或旧 `organizer` 自由文本推定。
     *
     * 关系名刻意用 `organizerEntity`：旧列 `organizer`（自由文本）仍保留，
     * 若关系也叫 `organizer()`，`$event->organizer` 会命中属性列而不是关系，
     * eager load 后同样容易被误读。读主体请显式 `$event->organizerEntity`。
     */
    public function organizerEntity()
    {
        return $this->belongsTo(EcosystemOrganizer::class, 'organizer_id');
    }

    /**
     * 全部修订版本（G1-04）。历史 revision 永不删除，按 revision_no 升序读取。
     */
    public function revisions()
    {
        return $this->hasMany(EventRevision::class, 'event_id');
    }

    /**
     * 规则版本（G1-04）。报名若绑定规则（G2），必须绑定具体 version_no 与指纹。
     */
    public function ruleVersions()
    {
        return $this->hasMany(EventRuleVersion::class, 'event_id');
    }

    /**
     * 公开版本指针。G1-04 只建外键，写入/切换由 G1-08 发布状态机负责。
     */
    public function publishedRevision()
    {
        return $this->belongsTo(EventRevision::class, 'published_revision_id');
    }

    /**
     * 工作版本指针。必须是同一活动且 review_state=draft 的 revision
     * （由 RevisionPointerValidator 在写入时校验）。
     */
    public function workingRevision()
    {
        return $this->belongsTo(EventRevision::class, 'working_revision_id');
    }

    /**
     * 当前已批准候选版本（G1-08A）。已发布对象的新版本在此等待发布，
     * 公开读取仍只读 `publishedRevision`。
     */
    public function approvedRevision()
    {
        return $this->belongsTo(EventRevision::class, 'approved_revision_id');
    }
}
