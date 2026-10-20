<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventChange;
use Webkul\Shop\Models\EventChangeAcknowledgment;
use Webkul\Shop\Models\EventCheckinToken;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ops\OperationLogger;
use Webkul\User\Models\Admin;

/**
 * G2-09 · 免费入场凭证与签到。
 *
 * 凭证：
 *   · 32 字节随机 token，数据库只存 SHA-256；原始 token 只出现一次；
 *   · 绑定 attempt；取消/重报/重发会吊销旧 token；
 *   · QR payload 为固定前缀 + token，不含姓名/手机号。
 *
 * 签到：
 *   · 必须同时满足员工活动 checkin 范围、活动未取消/结束、当前轮次
 *     为 confirmed/checked_in、变更确认未被阻塞；
 *   · 重复扫描返回同一签到事实，不重复计数；
 *   · 旧轮次/已取消/已吊销 token 一律拒绝。
 */
class RegistrationCredentialService
{
    public function __construct(
        private readonly RegistrationStaffScope $staff,
        private readonly OutboxWriter $outbox,
        private readonly OperationLogger $logger,
    ) {}

    /**
     * 本人领取/重发凭证。每次签发都会吊销该轮次旧 token。
     *
     * @return array{token: string, qr_payload: string, expires_at: string, attempt_id: int, public_no: string}
     */
    public function issueForCustomer(int $registrationId, int $customerId): array
    {
        return DB::transaction(function () use ($registrationId, $customerId): array {
            $registration = EventRegistration::query()->whereKey($registrationId)->lockForUpdate()->first();

            if ($registration === null || (int) $registration->customer_id !== $customerId) {
                throw RegistrationException::notFound('报名不存在。');
            }

            $attempt = $registration->current_attempt_id !== null
                ? EventRegistrationAttempt::query()
                    ->whereKey($registration->current_attempt_id)
                    ->where('registration_id', $registration->getKey())
                    ->lockForUpdate()
                    ->first()
                : null;

            if ($attempt === null) {
                throw RegistrationException::notFound('报名轮次不存在。');
            }

            if ((string) $attempt->status !== EventRegistrationAttempt::STATUS_CONFIRMED
                && (string) $attempt->status !== EventRegistrationAttempt::STATUS_CHECKED_IN) {
                throw RegistrationException::transition('当前轮次尚未确认，不能领取凭证。', 'not_confirmed');
            }

            $event = Event::query()->whereKey($attempt->event_id)->first();

            if ($event === null) {
                throw RegistrationException::notFound('活动不存在。');
            }

            if ((string) $event->hosting_status === 'canceled') {
                throw RegistrationException::transition('活动已取消，凭证不可用。', 'event_canceled');
            }

            if ((string) $event->hosting_status === 'completed') {
                throw RegistrationException::transition('活动已结束，凭证不可用。', 'event_completed');
            }

            $expiresAt = $event->ends_at !== null
                ? $event->ends_at->copy()->utc()
                : now('UTC')->addSeconds((int) config('ecosystem_registration.checkin.fallback_ttl_seconds', 2592000));

            if ($expiresAt->lessThanOrEqualTo(now('UTC'))) {
                throw RegistrationException::transition('活动已结束，凭证不可用。', 'event_ended');
            }

            // 重发即吊销旧 token；旧二维码不得继续可用。
            $this->revokeTokens((int) $attempt->getKey());

            $raw = $this->newToken();
            $prefix = (string) config('ecosystem_registration.checkin.qr_prefix', 'SUMMONMECHA-CHECKIN:');

            EventCheckinToken::query()->create([
                'event_id'        => (int) $attempt->event_id,
                'registration_id' => (int) $registration->getKey(),
                'attempt_id'      => (int) $attempt->getKey(),
                'token_hash'      => hash('sha256', $raw),
                'issued_at'       => now('UTC'),
                'expires_at'      => $expiresAt,
                'revoked_at'      => null,
                'issued_by'       => $customerId,
                'issued_source'   => 'customer',
            ]);

            $this->logger->logOrFail(
                'customer',
                $customerId,
                'registration.credential.issued',
                'event_registration_attempt',
                (int) $attempt->getKey(),
                ['event_id' => (int) $attempt->event_id, 'registration_id' => (int) $registration->getKey()],
            );

            return [
                'token'      => $raw,
                'qr_payload' => $prefix.$raw,
                'expires_at' => $expiresAt->toIso8601String(),
                'attempt_id' => (int) $attempt->getKey(),
                'public_no'  => (string) $registration->public_no,
            ];
        });
    }

    public function checkIn(string $rawToken, Admin $admin, string $source = 'admin_manual'): EventRegistrationAttempt
    {
        $rawToken = trim($rawToken);

        if ($rawToken === '') {
            throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '签到凭证为空。', 'token_empty');
        }

        // 扫码枪/复制粘贴带的是完整 QR payload；存储哈希只针对原始 token。
        $qrPrefix = (string) config('ecosystem_registration.checkin.qr_prefix', 'SUMMONMECHA-CHECKIN:');

        if ($qrPrefix !== '' && str_starts_with($rawToken, $qrPrefix)) {
            $rawToken = substr($rawToken, strlen($qrPrefix));
        }

        if ($rawToken === '') {
            throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '签到凭证为空。', 'token_empty');
        }

        return DB::transaction(function () use ($rawToken, $admin, $source): EventRegistrationAttempt {
            // token 先锁：签发只插入新行，不会与这里形成 event→token 的反向等待。
            $token = EventCheckinToken::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if ($token === null || ! $token->isUsableAt()) {
                throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '签到凭证无效、已过期或已吊销。', 'token_invalid');
            }

            $probe = EventRegistrationAttempt::query()->find($token->attempt_id);

            if ($probe === null) {
                throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '签到凭证对应的报名轮次不存在。', 'attempt_missing');
            }

            $event = Event::query()->whereKey($probe->event_id)->lockForUpdate()->first();

            if ($event === null) {
                throw RegistrationException::notFound('活动不存在。');
            }

            $scope = EventRegistrationScope::query()->whereKey($probe->scope_id)->lockForUpdate()->first();

            if ($scope === null) {
                throw RegistrationException::notFound('报名范围不存在。');
            }

            $registration = EventRegistration::query()->whereKey($probe->registration_id)->lockForUpdate()->first();

            if ($registration === null) {
                throw RegistrationException::notFound('报名记录不存在。');
            }

            $attempt = EventRegistrationAttempt::query()->whereKey($probe->getKey())->lockForUpdate()->first();

            if ($attempt === null) {
                throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '签到凭证对应的报名轮次不存在。', 'attempt_missing');
            }

            // 逐层归属 + 当前轮次校验：旧轮次 token 不能作用于新轮次。
            if ((int) $registration->current_attempt_id !== (int) $attempt->getKey()) {
                throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '该凭证属于旧轮次，已失效。', 'old_attempt');
            }

            if ((int) $attempt->event_id !== (int) $event->getKey()
                || (int) $attempt->scope_id !== (int) $scope->getKey()
                || (int) $attempt->registration_id !== (int) $registration->getKey()
                || (int) $scope->event_id !== (int) $event->getKey()) {
                throw new RegistrationException('CHECKIN_TOKEN_INVALID', 422, '签到凭证归属不一致。', 'ownership_mismatch');
            }

            $this->staff->assertCapability((int) $event->getKey(), 'checkin', $admin);

            if ((string) $event->hosting_status === 'canceled') {
                throw new RegistrationException('CHECKIN_NOT_ALLOWED', 422, '活动已取消，不能签到。', 'event_canceled');
            }

            if ((string) $event->hosting_status === 'completed') {
                throw new RegistrationException('CHECKIN_NOT_ALLOWED', 422, '活动已结束，不能签到。', 'event_completed');
            }

            if (! in_array((string) $event->hosting_status, ['scheduled', 'rescheduled'], true)) {
                throw new RegistrationException('CHECKIN_NOT_ALLOWED', 422, '活动当前安排不允许签到。', 'hosting_status');
            }

            if ((string) $attempt->status === EventRegistrationAttempt::STATUS_CHECKED_IN) {
                // 重复扫描幂等：返回原签到事实，不重复写 outbox/审计。
                return $attempt;
            }

            if ((string) $attempt->status !== EventRegistrationAttempt::STATUS_CONFIRMED) {
                throw new RegistrationException('CHECKIN_NOT_ALLOWED', 422, '当前轮次不是已确认状态，不能签到。', 'not_confirmed');
            }

            if ($this->hasUnacknowledgedChange((int) $event->getKey(), (int) $attempt->getKey())) {
                throw new RegistrationException('CHECKIN_NOT_ALLOWED', 422, '存在待确认的活动变更，请先确认后再签到。', 'change_ack_required');
            }

            $attempt->status = EventRegistrationAttempt::STATUS_CHECKED_IN;
            $attempt->checked_in_at = now('UTC');
            $attempt->checked_in_by = (int) $admin->getKey();
            $attempt->checkin_source = $source;
            $attempt->row_version = (int) $attempt->row_version + 1;
            $attempt->save();

            // 签到仍占名额：confirmed_count 不变。
            $this->outbox->write(
                'registration',
                (int) $registration->getKey(),
                'registration.checked_in',
                [
                    'event_id'            => (int) $event->getKey(),
                    'registration_id'     => (int) $registration->getKey(),
                    'attempt_id'          => (int) $attempt->getKey(),
                    'status'              => EventRegistrationAttempt::STATUS_CHECKED_IN,
                    'attempt_row_version' => (int) $attempt->row_version,
                ],
                sprintf('registration:%d:registration.checked_in', (int) $attempt->getKey()),
            );

            $this->logger->logOrFail(
                'admin',
                (int) $admin->getKey(),
                'registration.checked_in',
                'event_registration_attempt',
                (int) $attempt->getKey(),
                [
                    'event_id'        => (int) $event->getKey(),
                    'registration_id' => (int) $registration->getKey(),
                    'source'          => $source,
                ],
            );

            return $attempt->refresh();
        });
    }

    public function revokeTokens(int $attemptId): void
    {
        EventCheckinToken::query()
            ->where('attempt_id', $attemptId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now('UTC'), 'updated_at' => now()]);
    }

    private function hasUnacknowledgedChange(int $eventId, int $attemptId): bool
    {
        $pending = EventChange::query()
            ->where('event_id', $eventId)
            ->where('status', 'published')
            ->where('requires_ack', true)
            ->where(function ($query): void {
                $query->whereNull('ack_deadline')->orWhere('ack_deadline', '>=', now('UTC'));
            })
            ->whereDoesntHave('acknowledgments', function ($query) use ($attemptId): void {
                $query->where('attempt_id', $attemptId)
                    ->whereIn('response', [
                        EventChangeAcknowledgment::RESPONSE_ACCEPTED,
                        EventChangeAcknowledgment::RESPONSE_DECLINED,
                    ]);
            })
            ->exists();

        return $pending;
    }

    private function newToken(): string
    {
        $bytes = max(16, min(64, (int) config('ecosystem_registration.checkin.token_bytes', 32)));

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
