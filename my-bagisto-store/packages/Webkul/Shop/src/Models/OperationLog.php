<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 操作审计（v3.4 计划 §3.2）—— 谁在什么时候对什么做了什么。
 *
 * 与 `audit_record`（技能准入三层审核）**不是一回事**，别混：
 *   - `audit_record` = 技能能不能上架的准入判定
 *   - `operation_log` = 平台/开发者的操作留痕（谁点了发布、谁驳回了、谁改了状态）
 *
 * 表只有 `created_at`，没有 `updated_at` —— 审计行**只增不改**。
 */
class OperationLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'operation_log';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'target_type',
        'target_id',
        'payload',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'actor_id'   => 'integer',
        'target_id'  => 'integer',
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SELLER = 'seller';

    public const ACTOR_SYSTEM = 'system';
}
