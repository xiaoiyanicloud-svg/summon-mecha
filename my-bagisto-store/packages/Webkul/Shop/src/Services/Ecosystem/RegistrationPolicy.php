<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventRevision;

/**
 * G2 报名政策解释器（G2-01 / ADR G1-D03 §3.3.5~§3.3.6）。
 *
 * 这里是 `can_register` / `registration_reason` / `allowed_actions` 的**单一事实源**。
 * 页面、API、后台不得各写一份判断。
 *
 * 关键口径：
 *   · capacity NULL=不限、0=关闭、>0=有限；未知/未确认不自动当不限。
 *   · manual 的 submitted 不占确认席位；批准时才在同一事务取额。
 *   · 报名截止与审核截止分开；审核截止后不再批准。
 *   · `can_register` 只是提示，提交时仍必须在锁内重检。
 */
class RegistrationPolicy
{
    public const ACTION_VIEW_DETAIL = 'view_detail';

    public const ACTION_OPEN_EXTERNAL = 'open_external_registration';

    public const ACTION_LOGIN = 'login';

    public const ACTION_REGISTER = 'register';

    public const ACTION_VIEW_MY_REGISTRATION = 'view_my_registration';

    /**
     * kind → 计量单位（ADR G1-D04）。未核定 kind 不得开放报名。
     */
    public function unitForKind(?string $kind): ?string
    {
        return match ($kind) {
            'competition' => 'team',
            'salon', 'workshop', 'expo', 'open_day' => 'person',
            default => null,
        };
    }

    public function scopeEvent(EventRegistrationScope $scope): ?Event
    {
        return $scope->relationLoaded('event')
            ? $scope->event
            : Event::query()->find($scope->event_id);
    }

    /**
     * 激活前必须显式确认的配置。
     *
     * @throws RegistrationException
     */
    public function assertActivatable(Event $event, EventRegistrationScope $scope): void
    {
        $unit = $this->unitForKind($event->kind !== null ? (string) $event->kind : null);

        if ($unit === null) {
            throw RegistrationException::invalid('活动类型未核定，不能开放报名。');
        }

        if ((string) $scope->unit !== $unit) {
            throw RegistrationException::invalid(sprintf(
                '计量单位与活动类型不一致：活动要求 %s，scope 配置为 %s。',
                $unit,
                (string) $scope->unit,
            ));
        }

        if ($scope->capacity_confirmed_at === null) {
            throw RegistrationException::invalid('容量未显式确认（capacity_confirmed_at 为空），不能开放。');
        }

        // 注意：`policy_confirmed_at` 由激活动作写入（activate 本身就是政策确认），
        // 这里不再要求它预先非空 —— 否则激活永远无法发生。

        if ($scope->registration_closes_at === null) {
            throw RegistrationException::invalid('报名截止时间未配置，不能开放。');
        }

        if ($scope->registration_opens_at !== null
            && $scope->registration_opens_at->greaterThanOrEqualTo($scope->registration_closes_at)) {
            throw RegistrationException::invalid('报名开始时间必须早于报名截止时间。');
        }

        if ((string) $scope->review_mode === 'manual') {
            if ($scope->review_closes_at === null) {
                throw RegistrationException::invalid('人工审核活动必须配置审核截止时间。');
            }

            if ($scope->review_closes_at->lessThan($scope->registration_closes_at)) {
                throw RegistrationException::invalid('审核截止时间不得早于报名截止时间。');
            }
        } else {
            if ((string) $scope->review_mode !== 'auto') {
                throw RegistrationException::invalid('审核方式必须是 auto 或 manual。');
            }

            if ($scope->review_closes_at !== null
                && $scope->review_closes_at->lessThan($scope->registration_closes_at)) {
                throw RegistrationException::invalid('审核截止时间不得早于报名截止时间。');
            }
        }

        if ((string) $scope->privacy_version === '' || $scope->privacy_version === null) {
            throw RegistrationException::invalid('隐私/同意文本版本未配置，不能开放。');
        }

        if ((int) $scope->form_version < 1) {
            throw RegistrationException::invalid('报名表版本必须大于等于 1。');
        }

        if (! in_array((string) $scope->cancel_policy, ['before_close', 'never'], true)) {
            throw RegistrationException::invalid('取消政策必须是 before_close 或 never。');
        }

        if (! in_array((string) $scope->reapply_policy, ['allowed', 'never'], true)) {
            throw RegistrationException::invalid('重报政策必须是 allowed 或 never。');
        }

        if ((string) $scope->no_response_action !== 'none'
            && (string) $scope->no_response_action !== 'release') {
            throw RegistrationException::invalid('未响应政策必须是 none 或 release。');
        }

        // 已批准规则存在时，报名必须绑定具体版本（ADR §4.5）。
        $approvedRules = $event->ruleVersions()
            ->where('status', 'approved')
            ->orderByDesc('version_no')
            ->first();

        if ($approvedRules !== null && $scope->rule_version_id === null) {
            throw RegistrationException::invalid('活动已有已批准规则版本，报名必须绑定具体规则版本。');
        }

        if ($scope->rule_version_id !== null) {
            $belongs = $event->ruleVersions()
                ->whereKey($scope->rule_version_id)
                ->where('status', 'approved')
                ->exists();

            if (! $belongs) {
                throw RegistrationException::invalid('绑定规则版本不存在、不属于该活动或未批准。');
            }
        }
    }

    /**
     * 全局双重闸 + scope 状态。任一不满足都不能写。
     */
    public function writeGateOpen(): bool
    {
        return (bool) config('ecosystem.events.registration_enabled', false)
            && (bool) config('ecosystem.events.registration_write_verified', false)
            && (bool) config('ecosystem_registration.enabled', false);
    }

    public function scopeOpen(EventRegistrationScope $scope): bool
    {
        return (string) $scope->status === 'open'
            && $scope->policy_confirmed_at !== null
            && $scope->capacity_confirmed_at !== null;
    }

    public function isRegistrationWindowOpen(EventRegistrationScope $scope, ?Carbon $now = null): bool
    {
        $now = $now ? $now->copy()->utc() : now('UTC');

        if ($scope->registration_closes_at === null || $now->greaterThan($scope->registration_closes_at)) {
            return false;
        }

        return $scope->registration_opens_at === null
            || $now->greaterThanOrEqualTo($scope->registration_opens_at);
    }

    public function isReviewWindowOpen(EventRegistrationScope $scope, ?Carbon $now = null): bool
    {
        if ((string) $scope->review_mode !== 'manual') {
            return true;
        }

        if ($scope->review_closes_at === null) {
            return false;
        }

        $now = $now ? $now->copy()->utc() : now('UTC');

        return $now->lessThanOrEqualTo($scope->review_closes_at);
    }

    /**
     * 提交时是否还有名额。manual 的 submitted 不占位，但容量已满时默认拒绝新申请。
     */
    public function capacityAllowsSubmission(EventRegistrationScope $scope): bool
    {
        if ($scope->capacity === null) {
            return true;
        }

        return (int) $scope->capacity > 0
            && (int) $scope->confirmed_count < (int) $scope->capacity;
    }

    public function capacityAllowsConfirmation(EventRegistrationScope $scope): bool
    {
        return $this->capacityAllowsSubmission($scope);
    }

    /**
     * 公开报名状态（reason 优先级严格按 ADR §3.3.6）。
     *
     * @return array{can_register: bool, registration_reason: string, allowed_actions: array<int, string>}
     */
    public function publicState(
        Event $event,
        EventRevision $revision,
        ?EventRegistrationScope $scope,
        ?int $customerId,
        ?EventRegistrationAttempt $activeAttempt,
        ?Carbon $now = null,
    ): array {
        $sourceType = (string) ($revision->source_type ?? '');
        $platformRole = (string) ($revision->platform_role ?? '');
        $externalOnly = $sourceType === 'external' || $platformRole === 'directory_only';

        $actions = [self::ACTION_VIEW_DETAIL];

        if ($externalOnly && trim((string) $revision->external_registration_url) !== '') {
            $actions[] = self::ACTION_OPEN_EXTERNAL;
        }

        $globalDisabled = ! $this->writeGateOpen() || $scope === null || ! $this->scopeOpen($scope);

        $reason = match (true) {
            $externalOnly => 'external_only',
            (string) $event->hosting_status === 'canceled' => 'canceled',
            $this->hasEnded($event, $revision, $now) => 'ended',
            $globalDisabled => 'registration_disabled',
            ! $this->isRegistrationWindowOpen($scope, $now) && $this->beforeOpen($scope, $now) => 'not_open',
            ! $this->isRegistrationWindowOpen($scope, $now) => 'closed',
            $customerId === null => 'requires_login',
            $activeAttempt !== null => 'already_registered',
            ! $this->capacityAllowsSubmission($scope) => 'full',
            default => 'ok',
        };

        $canRegister = $reason === 'ok';

        if ($reason === 'requires_login') {
            $actions[] = self::ACTION_LOGIN;
        }

        if ($canRegister) {
            $actions[] = self::ACTION_REGISTER;
        }

        if ($activeAttempt !== null) {
            $actions[] = self::ACTION_VIEW_MY_REGISTRATION;
        }

        return [
            'can_register'        => $canRegister,
            'registration_reason' => $reason,
            'allowed_actions'     => array_values(array_unique($actions)),
        ];
    }

    /**
     * 提交/重报前的权威守卫。必须在行锁和当前读之后调用。
     *
     * @throws RegistrationException
     */
    public function assertSubmissionAllowed(
        Event $event,
        EventRevision $revision,
        EventRegistrationScope $scope,
        ?int $customerId,
        bool $hasActiveAttempt,
        ?Carbon $now = null,
    ): void {
        if (! $this->writeGateOpen()) {
            throw RegistrationException::disabled();
        }

        $state = $this->publicState($event, $revision, $scope, $customerId, null, $now);

        if ($state['registration_reason'] === 'requires_login') {
            throw RegistrationException::forbidden('请先登录后再报名。');
        }

        // ADR 优先级：already_registered 在 full 之前。即使容量已满，
        // 已有有效轮次的本人应看到“已报名”，而不是“名额已满”。
        if ($hasActiveAttempt
            && in_array($state['registration_reason'], ['ok', 'full'], true)) {
            throw RegistrationException::alreadyActive();
        }

        if ($state['registration_reason'] !== 'ok' && $state['registration_reason'] !== 'already_registered') {
            throw match ($state['registration_reason']) {
                'not_open' => RegistrationException::notOpen(),
                'closed' => RegistrationException::closed(),
                'full' => RegistrationException::full(),
                'canceled' => RegistrationException::transition('活动已取消，不能报名。', 'canceled'),
                'ended' => RegistrationException::transition('活动已结束，不能报名。', 'ended'),
                default => RegistrationException::disabled(),
            };
        }

        if ($hasActiveAttempt) {
            throw RegistrationException::alreadyActive();
        }
    }

    private function beforeOpen(EventRegistrationScope $scope, ?Carbon $now): bool
    {
        if ($scope->registration_opens_at === null) {
            return false;
        }

        $now = $now ? $now->copy()->utc() : now('UTC');

        return $now->lessThan($scope->registration_opens_at);
    }

    private function hasEnded(Event $event, EventRevision $revision, ?Carbon $now): bool
    {
        if ((string) $event->hosting_status === 'completed') {
            return true;
        }

        $endsAt = $revision->ends_at;

        if (! $endsAt instanceof Carbon) {
            return false;
        }

        $now = $now ? $now->copy()->utc() : now('UTC');

        return $endsAt->lessThan($now);
    }
}
