<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationRequest;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G2-03 / G2-04 / G2-07 · 报名提交与重报。
 *
 * 锁顺序严格为：event → scope → registration → attempt。
 * 取得锁后重新读取当前时间、发布版本、scope 状态、容量与已有轮次；
 * 不用请求开始时的快照放行。
 *
 * 自动确认在提交事务内占位；人工审核只写 submitted，不占位。
 * 失败/回滚不增加 confirmed_count；outbox 与业务同一事务。
 */
class RegistrationSubmissionService
{
    public function __construct(
        private readonly RegistrationPolicy $policy,
        private readonly RegistrationIdempotency $idempotency,
        private readonly RegistrationContactVault $vault,
        private readonly OutboxWriter $outbox,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{registration: EventRegistration, attempt: EventRegistrationAttempt, replayed: bool}
     */
    public function submit(Event $event, Customer $customer, array $input, string $action = 'submit'): array
    {
        $requestKey = (string) ($input['request_key'] ?? '');
        $formVersion = (int) ($input['form_version'] ?? 0);
        $privacyVersion = (string) ($input['privacy_version'] ?? '');
        $rulesVersionId = isset($input['rules_version_id']) && $input['rules_version_id'] !== null
            ? (int) $input['rules_version_id']
            : null;
        $consent = (bool) ($input['consent'] ?? false);

        if (! $consent) {
            throw RegistrationException::invalid('必须明确勾选规则与隐私同意。');
        }

        $payloadHash = $this->idempotency->payloadHash([
            'action'          => $action,
            'event_id'        => (int) $event->getKey(),
            'customer_id'     => (int) $customer->getKey(),
            'form_version'    => $formVersion,
            'privacy_version' => $privacyVersion,
            'rules_version_id'=> $rulesVersionId,
            'name'            => trim((string) ($input['name'] ?? '')),
            'phone'           => trim((string) ($input['phone'] ?? '')),
            'city'            => trim((string) ($input['city'] ?? '')),
            'note'            => trim((string) ($input['note'] ?? '')),
            'expected_attempt_id' => isset($input['expected_attempt_id']) ? (int) $input['expected_attempt_id'] : null,
        ]);

        return DB::transaction(function () use (
            $event,
            $customer,
            $input,
            $action,
            $requestKey,
            $formVersion,
            $privacyVersion,
            $rulesVersionId,
            $payloadHash,
        ): array {
            $begin = $this->idempotency->begin(
                'customer',
                (int) $customer->getKey(),
                $action,
                $requestKey,
                $payloadHash,
                'event',
                (int) $event->getKey(),
            );

            if ($begin['replayed']) {
                return [
                    'registration' => $this->registrationForReplay($begin['request']),
                    'attempt'      => $this->currentAttemptForReplay($begin['request']),
                    'replayed'     => true,
                ];
            }

            /** @var EventRegistrationRequest $request */
            $request = $begin['request'];

            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $lockedEvent->publish_status !== 'published'
                || (string) $lockedEvent->disposition !== 'normal'
                || $lockedEvent->published_revision_id === null) {
                throw RegistrationException::disabled('活动未公开，不能报名。');
            }

            /** @var EventRevision|null $revision */
            $revision = EventRevision::query()
                ->whereKey($lockedEvent->published_revision_id)
                ->where('review_state', 'approved')
                ->first();

            if (! $revision instanceof EventRevision) {
                throw RegistrationException::disabled('活动当前没有可报名的公开版本。');
            }

            $scope = EventRegistrationScope::query()
                ->where('event_id', $lockedEvent->getKey())
                ->where('scope_key', 'default')
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                throw RegistrationException::disabled('该活动尚未配置报名范围。');
            }

            // G3-02：team 单位的 scope 只能走队伍报名，个人报名入口不得直接占位。
            if ((string) $scope->unit !== 'person') {
                throw new RegistrationException(
                    'COMPETITION_UNIT_MISMATCH',
                    422,
                    '该赛项按队伍报名，请先组队后由队长提交。',
                    'unit_mismatch',
                );
            }

            $this->assertVersionContract($scope, $formVersion, $privacyVersion, $rulesVersionId);

            $subjectKey = 'c:'.(int) $customer->getKey();

            $registration = EventRegistration::query()
                ->where('scope_id', $scope->getKey())
                ->where('subject_key', $subjectKey)
                ->lockForUpdate()
                ->first();

            $hasActiveAttempt = false;
            $currentAttempt = null;

            if ($registration !== null) {
                $currentAttempt = $registration->current_attempt_id !== null
                    ? EventRegistrationAttempt::query()
                        ->whereKey($registration->current_attempt_id)
                        ->where('registration_id', $registration->getKey())
                        ->lockForUpdate()
                        ->first()
                    : null;

                $hasActiveAttempt = $currentAttempt instanceof EventRegistrationAttempt
                    && $currentAttempt->isActive();
            }

            $this->policy->assertSubmissionAllowed(
                $lockedEvent,
                $revision,
                $scope,
                (int) $customer->getKey(),
                $hasActiveAttempt,
            );

            // 重报：旧轮次必须是终态，且调用方显式钉住当前轮次时不得错位。
            if ($registration !== null) {
                $expectedAttemptId = isset($input['expected_attempt_id']) && $input['expected_attempt_id'] !== null
                    ? (int) $input['expected_attempt_id']
                    : null;

                if ($expectedAttemptId !== null
                    && (int) ($registration->current_attempt_id ?? 0) !== $expectedAttemptId) {
                    throw RegistrationException::transition('报名轮次已变化，请刷新后重试。', 'attempt_changed');
                }

                if ($currentAttempt instanceof EventRegistrationAttempt && $currentAttempt->isActive()) {
                    throw RegistrationException::alreadyActive();
                }

                if ((string) $scope->reapply_policy === 'never') {
                    throw RegistrationException::transition('该活动不允许重报。', 'reapply_disabled');
                }
            }

            $nextAttemptNo = $registration === null
                ? 1
                : ((int) EventRegistrationAttempt::query()
                    ->where('registration_id', $registration->getKey())
                    ->max('attempt_no') + 1);

            if ($scope->max_attempts !== null && $nextAttemptNo > (int) $scope->max_attempts) {
                throw RegistrationException::attemptLimit();
            }

            if (! $this->policy->capacityAllowsSubmission($scope)) {
                throw RegistrationException::full();
            }

            $contact = $this->vault->buildContact(
                (string) ($input['name'] ?? ''),
                (string) ($input['phone'] ?? ''),
                isset($input['city']) ? (string) $input['city'] : null,
                isset($input['note']) ? (string) $input['note'] : null,
            );

            if ($registration === null) {
                $registration = new EventRegistration();
                $registration->event_id = (int) $lockedEvent->getKey();
                $registration->scope_id = (int) $scope->getKey();
                $registration->subject_key = $subjectKey;
                $registration->customer_id = (int) $customer->getKey();
                $registration->identity_kind = EventRegistration::IDENTITY_CUSTOMER;
                // 旧列仍为 NOT NULL；新流程不向这里写明文。
                $registration->name = '';
                $registration->phone = '';
                $registration->city = null;
                $registration->note = null;
                $registration->row_version = 0;
                $registration->public_no = $this->newPublicNo();
                $registration->save();
            }

            $status = (string) $scope->review_mode === 'manual'
                ? EventRegistrationAttempt::STATUS_SUBMITTED
                : EventRegistrationAttempt::STATUS_CONFIRMED;

            if ($status === EventRegistrationAttempt::STATUS_CONFIRMED) {
                $this->takeSeat($scope);
            }

            $rulesFingerprint = null;

            if ($scope->rule_version_id !== null) {
                $rule = $lockedEvent->ruleVersions()->whereKey($scope->rule_version_id)->first();
                $rulesFingerprint = $rule?->content_fingerprint;
            }

            $attempt = new EventRegistrationAttempt();
            $attempt->fill([
                'registration_id'    => (int) $registration->getKey(),
                'event_id'           => (int) $lockedEvent->getKey(),
                'scope_id'           => (int) $scope->getKey(),
                'owner_customer_id'  => (int) $customer->getKey(),
                'attempt_no'         => $nextAttemptNo,
                'status'             => $status,
                'review_mode'        => (string) $scope->review_mode,
                'form_version'       => (int) $scope->form_version,
                'form_snapshot'      => [
                    'definition' => (array) ($scope->form_schema ?? []),
                    'values'     => [
                        'city'         => isset($input['city']) ? trim((string) $input['city']) : '',
                        'note_present' => isset($input['note']) && trim((string) $input['note']) !== '',
                    ],
                ],
                'rules_version_id'   => $scope->rule_version_id,
                'rules_fingerprint'  => $rulesFingerprint,
                'privacy_version'    => (string) $scope->privacy_version,
                'privacy_fingerprint'=> hash('sha256', (string) $scope->privacy_version.'|'.(string) $scope->privacy_summary),
                'consent_accepted_at'=> now('UTC'),
                'contact_name_enc'   => $contact['contact_name_enc'],
                'contact_phone_enc'  => $contact['contact_phone_enc'],
                'contact_phone_hash' => $contact['contact_phone_hash'],
                'contact_phone_last4'=> $contact['contact_phone_last4'],
                'contact_city'       => $contact['contact_city'],
                'note_enc'           => $contact['note_enc'],
                'request_key'        => $requestKey,
                'submitted_at'       => now('UTC'),
                'row_version'        => 0,
            ]);
            $attempt->save();

            $registration->current_attempt_id = (int) $attempt->getKey();
            $registration->row_version = (int) $registration->row_version + 1;
            $registration->save();

            $eventType = $status === EventRegistrationAttempt::STATUS_CONFIRMED
                ? 'registration.confirmed'
                : 'registration.submitted';

            $this->outbox->write(
                'registration',
                (int) $registration->getKey(),
                $eventType,
                [
                    'event_id'            => (int) $lockedEvent->getKey(),
                    'registration_id'     => (int) $registration->getKey(),
                    'attempt_id'          => (int) $attempt->getKey(),
                    'status'              => $status,
                    'attempt_row_version' => (int) $attempt->row_version,
                ],
                sprintf('registration:%d:%s', (int) $attempt->getKey(), $eventType),
            );

            $this->logger->logOrFail(
                'customer',
                (int) $customer->getKey(),
                $eventType,
                'event_registration_attempt',
                (int) $attempt->getKey(),
                [
                    'event_id'        => (int) $lockedEvent->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'attempt_no'      => $nextAttemptNo,
                ],
            );

            $this->idempotency->complete(
                $request,
                'registration',
                (int) $registration->getKey(),
                (string) $registration->public_no,
            );

            return [
                'registration' => $registration->refresh(),
                'attempt'      => $attempt->refresh(),
                'replayed'     => false,
            ];
        });
    }

    private function assertVersionContract(
        EventRegistrationScope $scope,
        int $formVersion,
        string $privacyVersion,
        ?int $rulesVersionId,
    ): void {
        if ($formVersion !== (int) $scope->form_version) {
            throw RegistrationException::transition('报名表版本已更新，请刷新后重新阅读。', 'form_version_stale');
        }

        if ($privacyVersion !== (string) $scope->privacy_version) {
            throw RegistrationException::transition('隐私/同意文本已更新，请重新阅读并勾选。', 'privacy_version_stale');
        }

        $scopeRules = $scope->rule_version_id !== null ? (int) $scope->rule_version_id : null;

        if ($scopeRules !== $rulesVersionId) {
            throw RegistrationException::transition('规则版本与当前活动不一致，请刷新后重试。', 'rules_version_stale');
        }
    }

    private function takeSeat(EventRegistrationScope $scope): void
    {
        $next = (int) $scope->confirmed_count + 1;

        if ($scope->capacity !== null) {
            if ((int) $scope->capacity <= 0 || $next > (int) $scope->capacity) {
                throw RegistrationException::full();
            }
        }

        $scope->confirmed_count = $next;
        $scope->row_version = (int) $scope->row_version + 1;
        $scope->save();
    }

    private function newPublicNo(): string
    {
        $prefix = (string) config('ecosystem_registration.public_no_prefix', 'EV');

        for ($i = 0; $i < 5; $i++) {
            $candidate = strtoupper($prefix.bin2hex(random_bytes(8)));

            if (! EventRegistration::query()->where('public_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('无法生成唯一报名编号。');
    }

    private function registrationForReplay(EventRegistrationRequest $request): EventRegistration
    {
        $registration = EventRegistration::query()->find((int) $request->result_id);

        if ($registration === null) {
            throw RegistrationException::notFound('原报名记录不存在。');
        }

        return $registration;
    }

    private function currentAttemptForReplay(EventRegistrationRequest $request): EventRegistrationAttempt
    {
        $registration = $this->registrationForReplay($request);

        $attempt = $registration->current_attempt_id !== null
            ? EventRegistrationAttempt::query()
                ->whereKey($registration->current_attempt_id)
                ->where('registration_id', $registration->getKey())
                ->first()
            : null;

        if ($attempt === null) {
            throw RegistrationException::notFound('原报名轮次不存在。');
        }

        return $attempt;
    }
}
