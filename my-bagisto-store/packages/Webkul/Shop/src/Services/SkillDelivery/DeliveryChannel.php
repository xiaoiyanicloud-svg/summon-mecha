<?php

declare(strict_types=1);

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\SkillEntitlement;

/**
 * 交付通道抽象。
 *
 * 这是「预留下发通道」的落点。技能买到之后怎么"到"用户手上,随阶段演进:
 *   app         P0 —— 交付到 App,用户看得见、下得了。零外部依赖。
 *   device_pull P1 —— 设备带 license_key 来拉。是"拉"模型,不要求设备有公网 IP、
 *                     不需要 broker、服务器不用主动找到设备,所以它比推送容易落地得多。
 *   device_push P3 —— MQTT/WebSocket 主动推。最贵,明确推迟。
 *
 * 换通道不应该改业务逻辑 —— 授权生成时按 config('skill_delivery.default') 选定并把
 * key 落到 skill_entitlements.delivery_channel,之后不再重新推导(否则改默认通道会
 * 追溯性地改变历史订单的行为)。
 *
 * ⚠ deliver() 必须幂等。它会在建单监听、OrderRepository 的失败重试(最多 2 次)、
 *   后台补发等场景被多次调用。
 */
interface DeliveryChannel
{
    /**
     * 通道标识,与 config/skill_delivery.php 里的 key 一致。
     */
    public function key(): string;

    /**
     * 交付方式的中文名 —— 会写进订单条目快照并展示在订单详情页。
     */
    public function label(): string;

    /**
     * 当前这条授权能不能走本通道(前置条件,如是否已绑设备、设备是否在线)。
     */
    public function canDeliver(SkillEntitlement $entitlement): bool;

    /**
     * 执行交付。必须幂等。
     *
     * @param  array  $context  调用方附加上下文(如 device_id、操作人)
     */
    public function deliver(SkillEntitlement $entitlement, array $context = []): DeliveryResult;

    /**
     * 撤销交付(订单取消/退款时对称调用)。
     */
    public function revoke(SkillEntitlement $entitlement): void;
}
