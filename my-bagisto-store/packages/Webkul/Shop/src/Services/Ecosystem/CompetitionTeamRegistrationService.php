<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionTeam;
use Webkul\Shop\Models\CompetitionTeamMember;
use Webkul\Shop\Models\CompetitionTrack;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationRequest;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * G3-03 / G3-04 · 队伍报名。
 *
 * 与 G2 个人报名共用同一套锁协议和幂等/outbox/审计：
 *   event → scope → track → team → registration → attempt。
 *
 * 与个人报名的区别：
 *   · subject_key=`t:{team_id}`，报名归属队伍而不是队长个人；
 *   · 只有当前队长可以提交；队员通过队伍关系读取；
 *   · 提交时冻结当届成员/资格/机器人声明快照（CompetitionEntry）。
 */
class CompetitionTeamRegistrationService
{
    public function __construct(
        private readonly RegistrationPolicy $policy,
        private readonly RegistrationIdempotency $idempotency,
        private readonly RegistrationContactVault $vault,
        private readonly CompetitionRuleProfile $rules,
        private readonly CompetitionEntryService $entries,
        private readonly OutboxWriter $outbox,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{registration: EventRegistration, attempt: EventRegistrationAttempt, entry: CompetitionEntry, replayed: bool}
     */
    public function submit(
        Event $event,
        CompetitionTrack $track,
        CompetitionTeam $team,
        Customer $captain,
        array $input,
    ): array {
        $this->rules->assertWriteEnabled();

        if ((string) $track->status !== 'active') {
            throw CompetitionException::trackNotActive();
        }

        if ((int) $team->captain_customer_id !== (int) $captain->getKey()) {
            throw CompetitionException::teamForbidden('只有队长可以提交队伍报名。');
        }

        $requestKey = (string) ($input['request_key'] ?? '');
        $formVersion = (int) ($input['form_version'] ?? 0);
        $privacyVersion = (string) ($input['privacy_version'] ?? '');
        $rulesVersionId = isset($input['rules_version_id']) && $input['rules_version_id'] !== null
            ? (int) $input['rules_version_id']
            : null;
        $consent = (bool) ($input['consent'] ?? false);
        $robot = isset($input['robot']) && is_array($input['robot']) ? $input['robot'] : [];

        if (! $consent) {
            throw CompetitionException::invalid('必须明确勾选规则与隐私同意。');
        }

        $payloadHash = $this->idempotency->payloadHash([
            'action'         => 'team_submit',
            'event_id'       => (int) $event->getKey(),
            'track_id'       => (int) $track->getKey(),
            'team_id'        => (int) $team->getKey(),
            'captain_id'     => (int) $captain->getKey(),
            'form_version'   => $formVersion,
            'privacy_version'=> $privacyVersion,
            'rules_version_id' => $rulesVersionId,
            'name'           => trim((string) ($input['name'] ?? '')),
            'phone'          => trim((string) ($input['phone'] ?? '')),
            'city'           => trim((string) ($input['city'] ?? '')),
            'note'           => trim((string) ($input['note'] ?? '')),
            'robot'          => $this->canonical($robot),
        ]);

        return DB::transaction(function () use (
            $event,
            $track,
            $team,
            $captain,
            $input,
            $requestKey,
            $formVersion,
            $privacyVersion,
            $rulesVersionId,
            $consent,
            $robot,
            $payloadHash,
        ): array {
            $begin = $this->idempotency->begin(
                'team',
                (int) $team->getKey(),
                'team_submit',
                $requestKey,
                $payloadHash,
                'event',
                (int) $event->getKey(),
            );

            if ($begin['replayed']) {
                return $this->replay($begin['request'], $team);
            }

            /** @var EventRegistrationRequest $request */
            $request = $begin['request'];

            $lockedEvent = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $lockedEvent->publish_status !== 'published'
                || (string) $lockedEvent->disposition !== 'normal'
                || $lockedEvent->published_revision_id === null) {
                throw CompetitionException::invalid('活动未公开，不能报名。');
            }

            /** @var EventRevision|null $revision */
            $revision = EventRevision::query()
                ->whereKey($lockedEvent->published_revision_id)
                ->where('review_state', 'approved')
                ->first();

            if (! $revision instanceof EventRevision) {
                throw CompetitionException::invalid('活动当前没有可报名的公开版本。');
            }

            $scope = EventRegistrationScope::query()
                ->whereKey($track->registration_scope_id)
                ->where('event_id', $lockedEvent->getKey())
                ->lockForUpdate()
                ->first();

            if ($scope === null) {
                throw CompetitionException::invalid('赛项报名范围不存在或不属于该活动。');
            }

            if ((string) $scope->unit !== 'team') {
                throw CompetitionException::invalid('赛项报名范围不是队伍单位。');
            }

            $lockedTrack = CompetitionTrack::query()->whereKey($track->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $lockedTrack->status !== 'active') {
                throw CompetitionException::trackNotActive();
            }

            // 取锁后确认赛项仍绑定我们锁住的 scope，防止后台换 scope 后写旧范围。
            if ((int) $lockedTrack->registration_scope_id !== (int) $scope->getKey()) {
                throw RegistrationException::transition('赛项报名范围已变化，请刷新后重试。', 'scope_changed');
            }

            $this->rules->assertTrackReady($lockedTrack);

            $lockedTeam = CompetitionTeam::query()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $lockedTeam->status !== CompetitionTeam::STATUS_ACTIVE
                || (int) $lockedTeam->track_id !== (int) $lockedTrack->getKey()) {
                throw CompetitionException::teamNotFound('队伍不存在或已解散。');
            }

            if ((int) $lockedTeam->captain_customer_id !== (int) $captain->getKey()) {
                throw CompetitionException::teamForbidden('只有队长可以提交队伍报名。');
            }

            $this->assertScopeOpen($lockedEvent, $revision, $scope, $formVersion, $privacyVersion, $rulesVersionId, $lockedTrack);

            $subjectKey = 't:'.(int) $lockedTeam->getKey();

            $registration = EventRegistration::query()
                ->where('scope_id', $scope->getKey())
                ->where('subject_key', $subjectKey)
                ->lockForUpdate()
                ->first();

            $currentAttempt = null;

            if ($registration !== null && $registration->current_attempt_id !== null) {
                $currentAttempt = EventRegistrationAttempt::query()
                    ->whereKey($registration->current_attempt_id)
                    ->where('registration_id', $registration->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($currentAttempt instanceof EventRegistrationAttempt && $currentAttempt->isActive()) {
                    throw new RegistrationException(
                        'REGISTRATION_ALREADY_ACTIVE',
                        409,
                        '该队伍已有有效报名。',
                        'already_registered',
                    );
                }
            }

            $nextAttemptNo = $registration === null
                ? 1
                : ((int) EventRegistrationAttempt::query()
                    ->where('registration_id', $registration->getKey())
                    ->max('attempt_no') + 1);

            if ($registration !== null && (string) $scope->reapply_policy === 'never') {
                throw RegistrationException::transition('该活动不允许重报。', 'reapply_disabled');
            }

            if ($scope->max_attempts !== null && $nextAttemptNo > (int) $scope->max_attempts) {
                throw RegistrationException::attemptLimit();
            }

            if (! $this->policy->capacityAllowsSubmission($scope)) {
                throw new RegistrationException('REGISTRATION_FULL', 422, '名额已满。', 'full');
            }

            // 资格检查必须在锁内、用当前成员事实执行。
            $eligibility = $this->entries->evaluate($lockedTrack, $lockedTeam, $robot);

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
                $registration->team_id = (int) $lockedTeam->getKey();
                $registration->subject_key = $subjectKey;
                $registration->customer_id = (int) $captain->getKey();
                $registration->identity_kind = EventRegistration::IDENTITY_TEAM;
                // 旧列仍为 NOT NULL；新流程不在这里写明文。
                $registration->name = '';
                $registration->phone = '';
                $registration->city = null;
                $registration->note = null;
                $registration->row_version = 0;
                $registration->public_no = $this->newPublicNo();
                $registration->save();
            } else {
                // 队长移交后，队伍主体不变；这里只记录本次提交人，不改归属。
                $registration->customer_id = (int) $captain->getKey();
                $registration->row_version = (int) $registration->row_version + 1;
                $registration->save();
            }

            $status = (string) $scope->review_mode === 'manual'
                ? EventRegistrationAttempt::STATUS_SUBMITTED
                : EventRegistrationAttempt::STATUS_CONFIRMED;

            if ($status === EventRegistrationAttempt::STATUS_CONFIRMED) {
                $this->takeSeat($scope);
            }

            $rulesFingerprint = $lockedTrack->rules_fingerprint;

            $attempt = new EventRegistrationAttempt();
            $attempt->fill([
                'registration_id'     => (int) $registration->getKey(),
                'event_id'            => (int) $lockedEvent->getKey(),
                'scope_id'            => (int) $scope->getKey(),
                'owner_customer_id'   => (int) $captain->getKey(),
                'attempt_no'          => $nextAttemptNo,
                'status'              => $status,
                'review_mode'         => (string) $scope->review_mode,
                'form_version'        => (int) $scope->form_version,
                'form_snapshot'       => [
                    'definition' => (array) ($scope->form_schema ?? []),
                    'values'     => [
                        'city'         => isset($input['city']) ? trim((string) $input['city']) : '',
                        'note_present' => isset($input['note']) && trim((string) $input['note']) !== '',
                        'team_id'      => (int) $lockedTeam->getKey(),
                        'track_key'    => (string) $lockedTrack->track_key,
                    ],
                ],
                'rules_version_id'    => $lockedTrack->rules_version_id,
                'rules_fingerprint'   => $rulesFingerprint,
                'privacy_version'     => (string) $scope->privacy_version,
                'privacy_fingerprint' => hash('sha256', (string) $scope->privacy_version.'|'.(string) $scope->privacy_summary),
                'consent_accepted_at' => now('UTC'),
                'contact_name_enc'    => $contact['contact_name_enc'],
                'contact_phone_enc'   => $contact['contact_phone_enc'],
                'contact_phone_hash'  => $contact['contact_phone_hash'],
                'contact_phone_last4' => $contact['contact_phone_last4'],
                'contact_city'        => $contact['contact_city'],
                'note_enc'            => $contact['note_enc'],
                'request_key'         => $requestKey,
                'submitted_at'        => now('UTC'),
                'row_version'         => 0,
            ]);
            $attempt->save();

            $registration->current_attempt_id = (int) $attempt->getKey();
            $registration->row_version = (int) $registration->row_version + 1;
            $registration->save();

            $entry = $this->entries->freezeForRegistration(
                $lockedTeam,
                $lockedTrack,
                $registration,
                $attempt,
                $robot,
            );

            $eventType = $status === EventRegistrationAttempt::STATUS_CONFIRMED
                ? 'registration.confirmed'
                : 'registration.submitted';

            $this->outbox->write(
                'registration',
                (int) $registration->getKey(),
                $eventType,
                [
                    'event_id'        => (int) $lockedEvent->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'attempt_id'      => (int) $attempt->getKey(),
                    'entry_id'        => (int) $entry->getKey(),
                    'status'          => $status,
                ],
                sprintf('registration:%d:%s', (int) $attempt->getKey(), $eventType),
            );

            $this->logger->logOrFail(
                'team',
                (int) $lockedTeam->getKey(),
                'competition.team.registration_submitted',
                'event_registration_attempt',
                (int) $attempt->getKey(),
                [
                    'event_id'        => (int) $lockedEvent->getKey(),
                    'track_id'        => (int) $lockedTrack->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'entry_id'        => (int) $entry->getKey(),
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
                'entry'        => $entry->refresh(),
                'replayed'     => false,
            ];
        });
    }

    private function assertScopeOpen(
        Event $event,
        EventRevision $revision,
        EventRegistrationScope $scope,
        int $formVersion,
        string $privacyVersion,
        ?int $rulesVersionId,
        CompetitionTrack $track,
    ): void {
        if (! $this->policy->writeGateOpen()) {
            throw RegistrationException::disabled();
        }

        if (! $this->policy->scopeOpen($scope)) {
            throw RegistrationException::disabled('该赛项报名范围尚未开放。');
        }

        $now = Carbon::now('UTC');

        if ($scope->registration_opens_at !== null && $now->lessThan($scope->registration_opens_at)) {
            throw RegistrationException::notOpen();
        }

        if ($scope->registration_closes_at === null || $now->greaterThan($scope->registration_closes_at)) {
            throw RegistrationException::closed();
        }

        if ((string) $event->hosting_status === 'completed') {
            throw RegistrationException::transition('活动已结束，不能报名。', 'ended');
        }

        if ($revision->ends_at instanceof Carbon && Carbon::now('UTC')->greaterThan($revision->ends_at)) {
            throw RegistrationException::transition('活动已结束，不能报名。', 'ended');
        }

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

        if ((int) $scope->rule_version_id !== (int) $track->rules_version_id) {
            throw CompetitionException::rulesUnconfirmed('报名范围绑定的规则版本与赛项规则版本不一致。');
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

    /**
     * @return array{registration: EventRegistration, attempt: EventRegistrationAttempt, entry: CompetitionEntry, replayed: bool}
     */
    private function replay(EventRegistrationRequest $request, CompetitionTeam $team): array
    {
        $registration = EventRegistration::query()->find((int) $request->result_id);

        if ($registration === null || (int) $registration->team_id !== (int) $team->getKey()) {
            throw CompetitionException::entryNotFound('原报名记录不存在。');
        }

        $attempt = $registration->current_attempt_id !== null
            ? EventRegistrationAttempt::query()
                ->whereKey($registration->current_attempt_id)
                ->where('registration_id', $registration->getKey())
                ->first()
            : null;

        $entry = $attempt !== null
            ? CompetitionEntry::query()->where('attempt_id', $attempt->getKey())->first()
            : null;

        if ($attempt === null || $entry === null) {
            throw CompetitionException::entryNotFound('原报名轮次或参赛条目不存在。');
        }

        return [
            'registration' => $registration,
            'attempt'      => $attempt,
            'entry'        => $entry,
            'replayed'     => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function canonical(array $value): array
    {
        ksort($value);

        return $value;
    }
}
