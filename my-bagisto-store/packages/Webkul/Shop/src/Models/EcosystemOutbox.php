<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Webkul\Shop\Casts\UtcDateTime;

/**
 * 生态 outbox(ADR-0001 §4.11 / G1-D07)。
 *
 * 一行 = 一个“已与业务写同事务落库、等待消费”的事实。G1 只建立基础与消费接口，
 * 真正的 worker / 租约结算在 G1-09；报名通知扇出留 G2-12。
 *
 * ⚠ `payload` 只承载 ID / 版本 / 原因码，不得含手机号、证件、令牌或完整联系人。
 *   写入入口 `Services\Ecosystem\OutboxWriter` 负责在落库前拒绝 PII 键。
 */
class EcosystemOutbox extends Model
{
    protected $table = 'ecosystem_outbox';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'dedupe_key',
        'status',
        'attempts',
        'available_at',
        'lease_owner',
        'lease_until',
        'last_error',
    ];

    protected $casts = [
        'payload'      => 'array',
        // available_at / lease_until 以 UTC 落 DATETIME；应用默认时区是上海，
        // 必须用 UtcDateTime 读取，否则会差 8 小时（ADR G1-D04）。
        'available_at' => UtcDateTime::class,
        'lease_until'  => UtcDateTime::class,
    ];
}
