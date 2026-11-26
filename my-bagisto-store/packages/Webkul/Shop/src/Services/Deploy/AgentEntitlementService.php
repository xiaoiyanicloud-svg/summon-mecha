<?php

namespace Webkul\Shop\Services\Deploy;

use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;

/**
 * 设备侧技能授权校验（WBS A5）。
 *
 * 目的：堵住"试用回收被拔线绕过"—— 设备在执行前必须能独立于指令流问一次
 * "我这个 DID 现在还有没有这个技能的授权"。
 *
 * ── 判定顺序（每一层都 fail-closed）──
 *   1. DID 必须已绑定到某个 devices 行（未绑定 = 无法证明归属）
 *   2. 必须有 product 匹配的授权（device_id 精确匹配）
 *   3. 请求指定了版本时，授权必须是"该版本"或"跟随最新"（NULL）
 *   4. 授权状态不能是 revoked / expired / expiring，且 expires_at 未过
 *   5. 来源订单必须已支付（processing / completed）
 *
 * 拒绝时返回**稳定 reason**，不返回"未授权"三个字：
 *   DEVICE_NOT_BOUND / NO_ENTITLEMENT / VERSION_MISMATCH / REVOKED / EXPIRED / NOT_PAID
 *
 * ⚠ 本服务只做**读**：不写 activations、不推进状态机、不回收。执行计数与回收
 *   仍由 DeployTaskService 负责（两处同时写会让计数翻倍）。
 */
class AgentEntitlementService
{
    public const REASON_OK = 'OK';
    public const REASON_DEVICE_NOT_BOUND = 'DEVICE_NOT_BOUND';
    public const REASON_NO_ENTITLEMENT = 'NO_ENTITLEMENT';
    public const REASON_VERSION_MISMATCH = 'VERSION_MISMATCH';
    public const REASON_REVOKED = 'REVOKED';
    public const REASON_EXPIRED = 'EXPIRED';
    public const REASON_NOT_PAID = 'NOT_PAID';

    /** 多个候选授权都不可用时，按严重程度选一个最该解释的原因。 */
    protected const REASON_PRIORITY = [
        self::REASON_REVOKED         => 5,
        self::REASON_EXPIRED         => 4,
        self::REASON_NOT_PAID        => 3,
        self::REASON_VERSION_MISMATCH => 2,
        self::REASON_NO_ENTITLEMENT  => 1,
    ];

    /**
     * @return array{
     *     allowed: bool, reason: string, entitlement_id: int|null,
     *     expires_at: int|null, license_mode: string|null,
     *     max_activations: int|null, activations: int|null
     * }
     */
    public function check(DeviceBinding $binding, int $skillId, ?int $skillVersionId = null): array
    {
        if (empty($binding->devices_id)) {
            return $this->denied(self::REASON_DEVICE_NOT_BOUND);
        }

        $candidates = SkillEntitlement::query()
            ->where('device_id', (int) $binding->devices_id)
            ->where('product_id', $skillId)
            ->orderByDesc('id')
            ->get();

        if ($candidates->isEmpty()) {
            return $this->denied(self::REASON_NO_ENTITLEMENT);
        }

        $worst = self::REASON_NO_ENTITLEMENT;

        foreach ($candidates as $entitlement) {
            $reason = $this->rejectReason($entitlement, $skillVersionId);

            if ($reason === null) {
                return [
                    'allowed'         => true,
                    'reason'          => self::REASON_OK,
                    'entitlement_id'  => (int) $entitlement->id,
                    'expires_at'      => $entitlement->expires_at?->timestamp,
                    'license_mode'    => $this->licenseMode($entitlement),
                    'max_activations' => $entitlement->max_activations === null ? null : (int) $entitlement->max_activations,
                    'activations'     => (int) $entitlement->activations,
                ];
            }

            if (self::REASON_PRIORITY[$reason] > self::REASON_PRIORITY[$worst]) {
                $worst = $reason;
            }
        }

        return $this->denied($worst);
    }

    /**
     * 返回 null = 可用；否则返回拒绝原因。
     */
    protected function rejectReason(SkillEntitlement $entitlement, ?int $skillVersionId): ?string
    {
        if ($entitlement->status === SkillEntitlement::STATUS_REVOKED || $entitlement->revoked_at !== null) {
            return self::REASON_REVOKED;
        }

        if (in_array($entitlement->status, [
            SkillEntitlement::STATUS_EXPIRED,
            SkillEntitlement::STATUS_EXPIRING,
        ], true)) {
            return self::REASON_EXPIRED;
        }

        if ($entitlement->expires_at && $entitlement->expires_at->isPast()) {
            return self::REASON_EXPIRED;
        }

        if ($skillVersionId !== null
            && $entitlement->skill_version_id !== null
            && (int) $entitlement->skill_version_id !== $skillVersionId) {
            return self::REASON_VERSION_MISMATCH;
        }

        if (! $entitlement->isOrderPaid()) {
            return self::REASON_NOT_PAID;
        }

        return null;
    }

    protected function licenseMode(SkillEntitlement $entitlement): ?string
    {
        $version = $entitlement->skill_version_id
            ? SkillVersion::query()->find((int) $entitlement->skill_version_id)
            : null;

        return $version?->license_mode;
    }

    /**
     * @return array{allowed:false,reason:string,entitlement_id:null,expires_at:null,license_mode:null,max_activations:null,activations:null}
     */
    protected function denied(string $reason): array
    {
        return [
            'allowed'         => false,
            'reason'          => $reason,
            'entitlement_id'  => null,
            'expires_at'      => null,
            'license_mode'    => null,
            'max_activations' => null,
            'activations'     => null,
        ];
    }
}
