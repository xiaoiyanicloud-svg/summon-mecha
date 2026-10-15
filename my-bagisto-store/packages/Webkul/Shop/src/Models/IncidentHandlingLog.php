<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 事故处置记录 —— **append-only**(A5)。
 *
 * 为什么不是一个 JSON 列:此前处置记录写在 `incident.handling_log` 这样一列里,
 * 每次处置都是「读出整列 → 追加一条 → 整列写回」。两名管理员并发处置时,
 * 后写的会把先写的整列覆盖掉 —— **一条处置记录凭空消失**(经典 lost update)。
 *
 * 审计日志本来就不该是"可覆盖的一列":每次处置插一行,物理上不存在覆盖。
 * `incident.handling_log` 列保留但不再写入(历史数据已在迁移里搬入本表)。
 */
class IncidentHandlingLog extends Model
{
    protected $table = 'incident_handling_log';

    /** 只写不改:没有 updated_at。 */
    public const UPDATED_AT = null;

    protected $fillable = [
        'incident_id',
        'admin_id',
        'status',
        'responsibility',
        'note',
    ];

    protected $casts = [
        'incident_id'    => 'integer',
        'admin_id'       => 'integer',
        'created_at'     => 'datetime',
    ];
}
