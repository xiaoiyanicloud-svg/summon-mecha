<?php

declare(strict_types=1);

namespace Webkul\Shop\Services\SkillDelivery;

use Webkul\Shop\Models\SkillEntitlement;

/**
 * P0 的交付通道:应用内交付。
 *
 * 诚实边界 —— 它**不推送任何东西到设备**。它做的是:
 *   1. 把授权落成"已交付"状态,并确保 license_key 存在
 *   2. 有绑定设备 → granted(用户能看到"这台设备已获得技能X")
 *      没绑设备   → pending_bind(用户先看到技能已开通,等他去绑设备)
 *   3. 技能包通过 App 内的鉴权下载接口取(private 盘),不走这里
 *
 * 这一层的价值在于:Phase 1 的设备拉取通道要读的**就是这里写下的那一行**
 * (license_key + status + device_id),所以 P0 不是临时架子。
 *
 * 之所以选它做 MVP 唯一实现:零外部依赖。设备端现在完全不存在,任何需要设备配合的
 * 通道都没法先跑起来。
 */
class AppChannel implements DeliveryChannel
{
    /**
     * {@inheritdoc}
     */
    public function key(): string
    {
        return 'app';
    }

    /**
     * {@inheritdoc}
     */
    public function label(): string
    {
        return '应用内交付';
    }

    /**
     * {@inheritdoc}
     */
    public function canDeliver(SkillEntitlement $entitlement): bool
    {
        return $entitlement->isUsable();
    }

    /**
     * {@inheritdoc}
     *
     * 幂等:已交付过的直接返回 ok,不重复写库 —— 监听器会在建单事务内被触发,
     * 且 OrderRepository 失败会重试,这个方法一定会被多次调用。
     */
    public function deliver(SkillEntitlement $entitlement, array $context = []): DeliveryResult
    {
        if (! $this->canDeliver($entitlement)) {
            return DeliveryResult::fail('授权已撤销或已过期,无法交付');
        }

        // 已经交付过 —— 只补可能缺失的字段,不改状态(避免把 granted 回退成 pending_bind)
        $alreadyDelivered = $entitlement->granted_at !== null;

        $entitlement->delivery_channel = $this->key();

        if (! $entitlement->license_key) {
            $entitlement->license_key = SkillEntitlement::newLicenseKey();
        }

        if ($context['device_id'] ?? null) {
            $entitlement->device_id = $context['device_id'];
        }

        if (! $alreadyDelivered) {
            $entitlement->granted_at = now();
        }

        // 有设备才算真正交付完成;没设备就先挂着等用户绑
        $entitlement->status = $entitlement->device_id
            ? SkillEntitlement::STATUS_GRANTED
            : SkillEntitlement::STATUS_PENDING_BIND;

        $entitlement->save();

        return DeliveryResult::ok(
            $alreadyDelivered ? '已交付,无需重复' : '已交付到应用',
            [
                'license_key' => $entitlement->license_key,
                'status'      => $entitlement->status,
                'device_id'   => $entitlement->device_id,
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function revoke(SkillEntitlement $entitlement): void
    {
        if ($entitlement->status === SkillEntitlement::STATUS_REVOKED) {
            return;
        }

        $entitlement->status = SkillEntitlement::STATUS_REVOKED;
        $entitlement->revoked_at = now();
        $entitlement->save();
    }
}
