<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventRevision;

/**
 * 公开报名状态（ADR-0001 G1-D03 §3.3.5~§3.3.6 / G2-01）。
 *
 * 本类只做“取事实 → 交给 RegistrationPolicy 解释”，不复制 reason 优先级。
 * G1 双闸关闭时仍返回 `registration_disabled`，与旧公开合同兼容；
 * G2 打开后按 not_open / closed / requires_login / already_registered / full / ok
 * 返回稳定状态。
 */
class PublicRegistrationState
{
    public const ACTION_VIEW_DETAIL = RegistrationPolicy::ACTION_VIEW_DETAIL;

    public const ACTION_OPEN_EXTERNAL = RegistrationPolicy::ACTION_OPEN_EXTERNAL;

    public function __construct(
        private readonly RegistrationPolicy $policy,
    ) {}

    /**
     * @return array{can_register: bool, registration_reason: string, allowed_actions: array<int, string>}
     */
    public function forEvent(Event $event, EventRevision $revision, ?int $customerId = null): array
    {
        $scope = EventRegistrationScope::query()
            ->where('event_id', $event->getKey())
            ->where('scope_key', 'default')
            ->first();

        $activeAttempt = null;

        if ($scope !== null && $customerId !== null) {
            $registration = EventRegistration::query()
                ->where('scope_id', $scope->getKey())
                ->where('subject_key', 'c:'.$customerId)
                ->first();

            if ($registration !== null && $registration->current_attempt_id !== null) {
                $attempt = EventRegistrationAttempt::query()
                    ->whereKey($registration->current_attempt_id)
                    ->where('registration_id', $registration->getKey())
                    ->first();

                if ($attempt !== null && $attempt->isActive()) {
                    $activeAttempt = $attempt;
                }
            }
        }

        return $this->withFormContract(
            $this->policy->publicState($event, $revision, $scope, $customerId, $activeAttempt),
            $scope,
        );
    }

    /**
     * 已持有 scope/attempt 事实时直接解释（后台预览或测试用）。
     *
     * @return array{can_register: bool, registration_reason: string, allowed_actions: array<int, string>}
     */
    public function forScope(
        Event $event,
        EventRevision $revision,
        ?EventRegistrationScope $scope,
        ?int $customerId,
        ?EventRegistrationAttempt $activeAttempt,
    ): array {
        return $this->withFormContract(
            $this->policy->publicState($event, $revision, $scope, $customerId, $activeAttempt),
            $scope,
        );
    }

    /**
     * @param  array{can_register: bool, registration_reason: string, allowed_actions: array<int, string>}  $state
     * @return array<string, mixed>
     */
    private function withFormContract(array $state, ?EventRegistrationScope $scope): array
    {
        // G2：公开报名表元数据。只暴露 scope 当前版本；未配置时为 null，
        // 前端据此知道“不可报名”，而不是自己猜一个表单版本。
        $state['form_version'] = $scope?->form_version;
        $state['privacy_version'] = $scope?->privacy_version;
        $state['privacy_summary'] = $scope?->privacy_summary;
        $state['rules_version_id'] = $scope?->rule_version_id;

        return $state;
    }
}
