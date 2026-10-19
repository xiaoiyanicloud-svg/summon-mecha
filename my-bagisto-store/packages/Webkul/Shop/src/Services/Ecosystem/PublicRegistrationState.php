<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开报名状态计算（ADR-0001 G1-D03 §3.3.5 / §3.3.6 / G1-10）。
 *
 * G1 阶段站内报名写入口保持关闭：`can_register` **恒为 false**，
 * 本类只负责返回稳定的 `registration_reason` 与 `allowed_actions`。
 *
 * reason 优先级（第一条命中）：
 *   external_only > canceled > ended > registration_disabled
 *
 * `allowed_actions` 只允许两个 G1 值：`view_detail` 与外部活动的
 * `open_external_registration`；不得出现 `register` / `waitlist` / `login`。
 */
class PublicRegistrationState
{
    public const ACTION_VIEW_DETAIL = 'view_detail';

    public const ACTION_OPEN_EXTERNAL = 'open_external_registration';

    /**
     * @return array{can_register: bool, registration_reason: string, allowed_actions: array<int, string>}
     */
    public function forEvent(Event $event, EventRevision $revision): array
    {
        $sourceType = (string) ($revision->source_type ?? '');
        $platformRole = (string) ($revision->platform_role ?? '');
        $hostingStatus = (string) ($event->hosting_status ?? '');

        $externalOnly = $sourceType === 'external' || $platformRole === 'directory_only';

        $reason = match (true) {
            $externalOnly                          => 'external_only',
            $hostingStatus === 'canceled'          => 'canceled',
            $this->hasEnded($hostingStatus, $revision->ends_at) => 'ended',
            default                                => 'registration_disabled',
        };

        $actions = [self::ACTION_VIEW_DETAIL];

        if ($externalOnly && trim((string) $revision->external_registration_url) !== '') {
            $actions[] = self::ACTION_OPEN_EXTERNAL;
        }

        return [
            // G1 不接 G2 报名写入口：即使配置开关被误开也不得返回 true。
            'can_register'        => false,
            'registration_reason' => $reason,
            'allowed_actions'     => $actions,
        ];
    }

    private function hasEnded(string $hostingStatus, mixed $endsAt): bool
    {
        if ($hostingStatus === 'completed') {
            return true;
        }

        if (! $endsAt instanceof Carbon) {
            return false;
        }

        return $endsAt->lessThan(now('UTC'));
    }
}
