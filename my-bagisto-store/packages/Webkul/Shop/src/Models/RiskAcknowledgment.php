<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 风险告知确认（B-5 · 计划 §4.14「用户分级告知留痕」/ D6 三层责任模型）。
 *
 * 表 `risk_acknowledgment` 在批次 1（`2026_10_14_000001`）就建好了，但**全仓零引用** ——
 * 有表、没模型、没接口、没前端弹窗，等于"留痕"这半条一直空转。
 * 本模型是它的第一个消费者。
 *
 * 只增不改：表只有 `created_at`，没有 `updated_at`（与 `operation_log` 同构）。
 * 留痕的意义是"当时确实告知过、他确实勾了"，事后改写就把它变成了别的东西。
 *
 * `ack_payload` 的字段形状刻意对齐开发者协议签署（`DeveloperAgreement.sign_payload`）：
 * 勾选明细 + 告知文案指纹 + IP + UA + 时间 —— 将来若要证明"告知内容无误导"，
 * 靠的是**当时那份文案的 sha256**，不是"我们现在还在用这套文案"。
 */
class RiskAcknowledgment extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'risk_acknowledgment';

    protected $fillable = [
        'customer_id',
        'entitlement_id',
        'skill_version_id',
        'channel',
        'ack_payload',
        'ip',
        'created_at',
    ];

    protected $casts = [
        'customer_id'      => 'integer',
        'entitlement_id'   => 'integer',
        'skill_version_id' => 'integer',
        'ack_payload'      => 'array',
        'created_at'       => 'datetime',
    ];

    /**
     * 该客户是否对某版本**已留痕**（用于下单前/下发前的闸门判断，将来可复用）。
     */
    public static function acknowledged(int $customerId, int $skillVersionId): bool
    {
        return self::query()
            ->where('customer_id', $customerId)
            ->where('skill_version_id', $skillVersionId)
            ->exists();
    }
}
