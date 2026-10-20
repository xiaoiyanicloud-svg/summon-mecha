<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EventStaffAssignment;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2-14 · 活动人员范围。
 *
 * 授权判定 = 全局 ACL **且** 本表有效 capability。控制器负责全局 ACL；
 * 本服务负责“这个人能不能作用于这个活动”。
 *
 * 三条硬约束：
 *   · 只有 `permission_type=all` 的超管能授予/撤销范围；
 *   · 有 `settings` 能力不等于能改本表（防止人员自增范围）；
 *   · 超管可越过范围执行，但每次动作仍由调用方写审计。
 */
class RegistrationStaffScope
{
    public function __construct(
        private readonly OperationLogger $logger,
    ) {}

    public function isSuperAdmin(?Admin $admin): bool
    {
        return $admin !== null
            && $admin->role !== null
            && (string) $admin->role->permission_type === 'all';
    }

    public function assertSuperAdmin(Admin $admin): void
    {
        if (! $this->isSuperAdmin($admin)) {
            throw RegistrationException::forbidden('只有平台超级管理员可以管理活动人员范围。');
        }
    }

    /**
     * 返回命中的 assignment；超管返回 null（表示“越过范围”）。
     *
     * @throws RegistrationException
     */
    public function assertCapability(int $eventId, string $capability, ?Admin $admin): ?EventStaffAssignment
    {
        if ($admin === null) {
            throw RegistrationException::forbidden('请先登录后台账号。');
        }

        $this->assertKnownCapability($capability);

        if ($this->isSuperAdmin($admin)) {
            return null;
        }

        $assignment = EventStaffAssignment::query()
            ->where('event_id', $eventId)
            ->where('admin_id', $admin->getKey())
            ->first();

        if ($assignment === null || ! $assignment->isActiveAt() || ! $assignment->hasCapability($capability)) {
            throw RegistrationException::forbidden(sprintf(
                '没有该活动的 %s 授权。',
                $capability,
            ));
        }

        return $assignment;
    }

    public function hasCapability(int $eventId, string $capability, ?Admin $admin): bool
    {
        try {
            $this->assertCapability($eventId, $capability, $admin);

            return true;
        } catch (RegistrationException) {
            return false;
        }
    }

    /**
     * @return array<int, string>
     */
    public function capabilitiesFor(int $eventId, ?Admin $admin): array
    {
        if ($admin === null) {
            return [];
        }

        if ($this->isSuperAdmin($admin)) {
            return EventStaffAssignment::CAPABILITIES;
        }

        $assignment = EventStaffAssignment::query()
            ->where('event_id', $eventId)
            ->where('admin_id', $admin->getKey())
            ->first();

        if ($assignment === null || ! $assignment->isActiveAt()) {
            return [];
        }

        return array_values(array_intersect(
            EventStaffAssignment::CAPABILITIES,
            (array) $assignment->capabilities,
        ));
    }

    /**
     * @param  array<int, string>  $capabilities
     */
    public function grant(
        int $eventId,
        int $adminId,
        array $capabilities,
        ?Carbon $validFrom,
        ?Carbon $validUntil,
        int $grantedBy,
        ?string $note = null,
    ): EventStaffAssignment {
        $grantor = Admin::query()->find($grantedBy);

        if ($grantor === null) {
            throw RegistrationException::forbidden('授权人不存在。');
        }

        $this->assertSuperAdmin($grantor);

        $target = Admin::query()->find($adminId);

        if ($target === null) {
            throw RegistrationException::invalid('被授权账号不存在。');
        }

        if (! (bool) $target->status) {
            throw RegistrationException::invalid('被授权账号已停用。');
        }

        $capabilities = $this->normalizeCapabilities($capabilities);

        if ($capabilities === []) {
            throw RegistrationException::invalid('至少需要一项活动能力。');
        }

        if ($validUntil !== null && $validFrom !== null && $validUntil->lessThan($validFrom)) {
            throw RegistrationException::invalid('授权失效时间不得早于生效时间。');
        }

        return DB::transaction(function () use ($eventId, $adminId, $capabilities, $validFrom, $validUntil, $grantedBy, $note): EventStaffAssignment {
            $assignment = EventStaffAssignment::query()
                ->where('event_id', $eventId)
                ->where('admin_id', $adminId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                $assignment = new EventStaffAssignment();
                $assignment->event_id = $eventId;
                $assignment->admin_id = $adminId;
            }

            $assignment->capabilities = $capabilities;
            $assignment->valid_from = $validFrom;
            $assignment->valid_until = $validUntil;
            $assignment->granted_by = $grantedBy;
            $assignment->note = $note;
            $assignment->save();

            $this->logger->logOrFail(
                'admin', $grantedBy, 'registration.staff.granted',
                'event_staff_assignment', (int) $assignment->getKey(),
                [
                    'event_id'     => $eventId,
                    'admin_id'     => $adminId,
                    'capabilities' => $capabilities,
                ],
            );

            return $assignment->refresh();
        });
    }

    public function revoke(int $eventId, int $adminId, int $actorId): void
    {
        $actor = Admin::query()->find($actorId);

        if ($actor === null) {
            throw RegistrationException::forbidden('操作人不存在。');
        }

        $this->assertSuperAdmin($actor);

        DB::transaction(function () use ($eventId, $adminId, $actorId): void {
            $assignment = EventStaffAssignment::query()
                ->where('event_id', $eventId)
                ->where('admin_id', $adminId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return;
            }

            $this->logger->logOrFail(
                'admin', $actorId, 'registration.staff.revoked',
                'event_staff_assignment', (int) $assignment->getKey(),
                ['event_id' => $eventId, 'admin_id' => $adminId],
            );

            $assignment->delete();
        });
    }

    /**
     * @param  array<int, string>  $capabilities
     * @return array<int, string>
     */
    private function normalizeCapabilities(array $capabilities): array
    {
        $capabilities = array_values(array_unique(array_map('strval', $capabilities)));

        foreach ($capabilities as $capability) {
            $this->assertKnownCapability($capability);
        }

        return array_values(array_intersect(EventStaffAssignment::CAPABILITIES, $capabilities));
    }

    private function assertKnownCapability(string $capability): void
    {
        if (! in_array($capability, EventStaffAssignment::CAPABILITIES, true)) {
            throw RegistrationException::invalid('未知活动能力：'.$capability);
        }
    }
}
