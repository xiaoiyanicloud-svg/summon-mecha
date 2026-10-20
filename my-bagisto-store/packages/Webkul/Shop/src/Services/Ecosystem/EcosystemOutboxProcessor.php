<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Shop\Models\CompetitionAppeal;
use Webkul\Shop\Models\CompetitionEntry;
use Webkul\Shop\Models\CompetitionResult;
use Webkul\Shop\Models\CompetitionResultVersion;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOutbox;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventChange;
use Webkul\Shop\Models\EventRegistration;
use Webkul\Shop\Models\EventRegistrationAttempt;
use Webkul\Shop\Models\Notification;

/**
 * outbox 消费处理器（ADR-0001 G1-D07 / 规划 §5.3 / G1-09）。
 *
 * ── G1 handler 的语义 ──
 *   G1 没有接短信 / 微信 / 邮件等通知渠道。handler **只做事实核验与消费记账**，
 *   绝不因为 `status=sent` 就对外宣称“用户已收到”。`sent` 的意思是
 *   “这条 outbox 事实已被消费者正确确认”，不是“通知已送达”。
 *
 *   核验规则（按聚合重新读取父对象）：
 *     · event/content.published  → 父对象 published + disposition=normal +
 *                                  published_revision_id = payload.revision_id；
 *     · *.withheld               → disposition=withheld，公开指针未变；
 *     · *.restored               → disposition=normal + 仍 published；
 *     · *.retracted              → disposition=retracted（终态）。
 *   同时要求父对象 `row_version` 与 payload 一致。
 *
 * ── superseded ──
 *   如果父对象已被更新的 row_version 取代（例如 published 之后又 withhold），
 *   这条旧事实**不能**再投递给 G2 消费者 —— 直接标记 `dead`，`last_error`
 *   写稳定的 `superseded:` 前缀。重复执行不会产生第二个事实。
 *
 * ── 重试 ──
 *   只有数据库瞬时错误（QueryException / 锁等待 / 连接中断）回 pending 并按
 *   attempts 退避；未知 event_type、父对象不存在、状态不符、payload 非法等
 *   一律 dead，不做无限重试。
 *
 * ── 旧租约 ──
 *   结算 / 失败回报影响 0 行时抛 `OutboxLeaseLostException`：处理器捕获后只记
 *   warning 并丢弃结果，不写任何状态。
 */
class EcosystemOutboxProcessor
{
    public function __construct(
        private readonly OutboxLeaseService $leases,
    ) {}

    /**
     * 跑一轮有界批次（不无限循环；由调度器每分钟触发一次）。
     *
     * @return array<string, int|bool>
     */
    public function run(int $limit = 0, bool $dryRun = false): array
    {
        if ($dryRun) {
            $inspect = $this->leases->inspect();

            return [
                'dry_run'        => true,
                'recovered'      => 0,
                'claimed'        => 0,
                'sent'           => 0,
                'retried'        => 0,
                'failed'         => 0,
                'dead'           => 0,
                'lease_lost'     => 0,
                'errors'         => 0,
                'pending_due'    => $inspect['pending_due'],
                'expired_leases' => $inspect['expired_leases'],
            ];
        }

        $summary = [
            'dry_run'    => false,
            'recovered'  => $this->leases->recoverExpired(),
            'claimed'    => 0,
            'sent'       => 0,
            'retried'    => 0,
            'failed'     => 0,
            'dead'       => 0,
            'lease_lost' => 0,
            'errors'     => 0,
        ];

        $rows = $this->leases->claim($limit);
        $summary['claimed'] = count($rows);

        foreach ($rows as $row) {
            $this->processRow($row, $summary);
        }

        return $summary;
    }

    /**
     * @param  array<string, int|bool>  $summary
     */
    private function processRow(EcosystemOutbox $row, array &$summary): void
    {
        $outboxId = (int) $row->getKey();
        $leaseOwner = (string) $row->lease_owner;
        $attempts = (int) $row->attempts;

        try {
            $deadReason = $this->verificationFailure($row);

            if ($deadReason !== null) {
                $this->leases->fail($outboxId, $leaseOwner, $attempts, $deadReason, false);
                $summary['dead']++;

                return;
            }

            if ((string) $row->aggregate_type === 'registration') {
                // G2-12：通知创建与 outbox 结算同事务；崩溃重投由 dedupe_key 收敛。
                $this->deliverRegistration($row, $leaseOwner);
                $summary['sent']++;

                return;
            }

            if ((string) $row->aggregate_type === 'competition_result') {
                $this->deliverCompetitionResult($row, $leaseOwner);
                $summary['sent']++;

                return;
            }

            if ((string) $row->aggregate_type === 'competition_appeal') {
                $this->deliverCompetitionAppeal($row, $leaseOwner);
                $summary['sent']++;

                return;
            }

            $this->leases->settle($outboxId, $leaseOwner);
            $summary['sent']++;
        } catch (OutboxLeaseLostException $exception) {
            // 旧 worker：租约已被恢复扫描或另一个 worker 接管，丢弃结果。
            Log::warning('ecosystem.outbox.lease_lost', [
                'outbox_id' => $outboxId,
                'lease'     => $leaseOwner,
            ]);
            $summary['lease_lost']++;
        } catch (QueryException $exception) {
            $this->failSafely($row, $exception, true, $summary);
        } catch (Throwable $exception) {
            $this->failSafely($row, $exception, false, $summary);
        }
    }

    /**
     * @param  array<string, int|bool>  $summary
     */
    private function failSafely(EcosystemOutbox $row, Throwable $exception, bool $retryable, array &$summary): void
    {
        try {
            $target = $this->leases->fail(
                (int) $row->getKey(),
                (string) $row->lease_owner,
                (int) $row->attempts,
                $exception::class.': '.$exception->getMessage(),
                $retryable,
            );

            match ($target) {
                'dead'   => $summary['dead']++,
                'failed' => $summary['failed']++,
                default  => $summary['retried']++,
            };

            if ($target === 'dead') {
                Log::error('ecosystem.outbox.dead', [
                    'outbox_id'  => (int) $row->getKey(),
                    'event_type' => (string) $row->event_type,
                    'error'      => $exception::class,
                ]);
            }

            if ($target === 'failed') {
                Log::error('ecosystem.outbox.failed', [
                    'outbox_id'  => (int) $row->getKey(),
                    'event_type' => (string) $row->event_type,
                    'error'      => $exception::class,
                ]);
            }
        } catch (OutboxLeaseLostException $leaseLost) {
            Log::warning('ecosystem.outbox.lease_lost', [
                'outbox_id' => (int) $row->getKey(),
                'lease'     => (string) $row->lease_owner,
            ]);
            $summary['lease_lost']++;
        } catch (Throwable $writeFailure) {
            // 连失败状态都写不进去（例如数据库整体不可用）：租约到期后由恢复扫描兜底。
            Log::error('ecosystem.outbox.fail_write_failed', [
                'outbox_id' => (int) $row->getKey(),
                'error'     => $writeFailure::class,
            ]);
            $summary['errors']++;
        }
    }

    /**
     * 返回 NULL 表示事实核验通过；返回字符串表示不可重试的 dead 原因。
     */
    private function verificationFailure(EcosystemOutbox $row): ?string
    {
        $eventType = (string) $row->event_type;
        $aggregateType = (string) $row->aggregate_type;

        if (! in_array($eventType, (array) config('ecosystem_outbox.event_types', []), true)) {
            Log::error('ecosystem.outbox.unknown_event_type', [
                'outbox_id'  => (int) $row->getKey(),
                'event_type' => $eventType,
            ]);

            return 'unknown_event_type:'.$eventType;
        }

        if ($aggregateType === 'registration') {
            return str_starts_with($eventType, 'registration.')
                ? $this->registrationVerificationFailure($row)
                : 'event_type_aggregate_mismatch';
        }

        if ($aggregateType === 'competition_result') {
            return str_starts_with($eventType, 'competition_result.')
                ? $this->competitionResultVerificationFailure($row)
                : 'event_type_aggregate_mismatch';
        }

        if ($aggregateType === 'competition_appeal') {
            return str_starts_with($eventType, 'competition_appeal.')
                ? $this->competitionAppealVerificationFailure($row)
                : 'event_type_aggregate_mismatch';
        }

        if (! in_array($aggregateType, ['event', 'content'], true)) {
            return 'unknown_aggregate_type:'.$aggregateType;
        }

        if (! str_starts_with($eventType, $aggregateType.'.')) {
            return 'event_type_aggregate_mismatch';
        }

        $payload = (array) ($row->payload ?? []);
        $rowVersion = $payload['row_version'] ?? null;
        $revisionId = $payload['revision_id'] ?? null;

        if (! is_int($rowVersion) && ! (is_string($rowVersion) && ctype_digit($rowVersion))) {
            return 'invalid_payload:row_version';
        }

        if (! is_int($revisionId) && ! (is_string($revisionId) && ctype_digit($revisionId))) {
            return 'invalid_payload:revision_id';
        }

        $rowVersion = (int) $rowVersion;
        $revisionId = (int) $revisionId;

        $parent = $aggregateType === 'event'
            ? Event::query()->find((int) $row->aggregate_id)
            : ContentPost::query()->find((int) $row->aggregate_id);

        if ($parent === null) {
            return 'aggregate_not_found';
        }

        if ((int) $parent->row_version !== $rowVersion) {
            return sprintf('superseded:row_version=%d<current=%d', $rowVersion, (int) $parent->row_version);
        }

        if ($aggregateType === 'content' && trim((string) $parent->slug) === '') {
            // payload 不含 slug（PII/内容边界），只能核对父对象仍有公开地址。
            return 'content_slug_missing';
        }

        $disposition = (string) $parent->disposition;
        $status = (string) $parent->publish_status;
        $publishedRevisionId = $parent->published_revision_id === null
            ? null
            : (int) $parent->published_revision_id;
        $action = substr($eventType, strlen($aggregateType) + 1);

        return match ($action) {
            'published' => ($status === 'published'
                && $disposition === 'normal'
                && $revisionId > 0
                && $publishedRevisionId === $revisionId)
                ? null
                : 'fact_mismatch:published',
            'withheld' => $disposition === 'withheld'
                && $this->pointerMatches($revisionId, $publishedRevisionId)
                ? null
                : 'fact_mismatch:withheld',
            'restored' => $status === 'published'
                && $disposition === 'normal'
                && $this->pointerMatches($revisionId, $publishedRevisionId)
                ? null
                : 'fact_mismatch:restored',
            'retracted' => $disposition === 'retracted'
                && $this->pointerMatches($revisionId, $publishedRevisionId)
                ? null
                : 'fact_mismatch:retracted',
            default => 'unknown_event_type:'.$eventType,
        };
    }

    /**
     * G2-12：报名事件的核验。
     *
     * 只认当前轮次与当前事实；旧轮次/已被后续状态取代的事件直接 dead，
     * 不再给用户发一条“过期状态”的通知。
     */
    private function registrationVerificationFailure(EcosystemOutbox $row): ?string
    {
        $payload = (array) ($row->payload ?? []);
        $attemptId = $payload['attempt_id'] ?? null;

        if (! is_int($attemptId) && ! (is_string($attemptId) && ctype_digit($attemptId))) {
            return 'invalid_payload:attempt_id';
        }

        $attempt = EventRegistrationAttempt::query()->find((int) $attemptId);

        if ($attempt === null) {
            return 'attempt_not_found';
        }

        if ((int) $attempt->registration_id !== (int) $row->aggregate_id) {
            return 'aggregate_mismatch:registration';
        }

        $registration = EventRegistration::query()->find((int) $attempt->registration_id);

        if ($registration === null) {
            return 'registration_not_found';
        }

        if ((int) $registration->current_attempt_id !== (int) $attempt->getKey()) {
            return 'superseded:old_attempt';
        }

        $eventType = (string) $row->event_type;

        if (in_array($eventType, [
            'registration.change_requested',
            'registration.change_expired',
            'registration.change_acknowledged',
        ], true)) {
            $changeId = $payload['change_id'] ?? null;

            if (! is_int($changeId) && ! (is_string($changeId) && ctype_digit($changeId))) {
                return 'invalid_payload:change_id';
            }

            $change = EventChange::query()
                ->whereKey((int) $changeId)
                ->where('event_id', $attempt->event_id)
                ->first();

            if ($change === null) {
                return 'change_not_found';
            }

            if ($eventType === 'registration.change_requested'
                && (! $change->requires_ack || (string) $change->status !== 'published')) {
                return 'fact_mismatch:change_requested';
            }

            return null;
        }

        $status = (string) ($payload['status'] ?? '');

        if ($status === '' || (string) $attempt->status !== $status) {
            return sprintf('fact_mismatch:status=%s<current=%s', $status, (string) $attempt->status);
        }

        $rowVersion = $payload['attempt_row_version'] ?? null;

        if (! is_int($rowVersion) && ! (is_string($rowVersion) && ctype_digit($rowVersion))) {
            return 'invalid_payload:attempt_row_version';
        }

        if ((int) $rowVersion !== (int) $attempt->row_version) {
            return sprintf('superseded:row_version=%d<current=%d', (int) $rowVersion, (int) $attempt->row_version);
        }

        return null;
    }

    /**
     * 创建站内信并与 outbox 结算同一事务。
     *
     * 未接短信/微信/支付：只写 inapp，`sent` 只代表事实被消费。
     */
    private function deliverRegistration(EcosystemOutbox $row, string $leaseOwner): void
    {
        $payload = (array) ($row->payload ?? []);
        $attempt = EventRegistrationAttempt::query()->find((int) ($payload['attempt_id'] ?? 0));

        if ($attempt === null) {
            throw new \RuntimeException('报名事件缺少 attempt。');
        }

        $eventType = (string) $row->event_type;

        // 客户主动动作（签到/确认）不必再给自己发一条站内信；仍要结算事实。
        $customerFacing = in_array($eventType, [
            'registration.submitted',
            'registration.confirmed',
            'registration.rejected',
            'registration.canceled',
            'registration.event_canceled',
            'registration.change_requested',
            'registration.change_expired',
        ], true);

        DB::transaction(function () use ($row, $leaseOwner, $attempt, $eventType, $customerFacing): void {
            if ($customerFacing) {
                $dedupe = 'notification:'.(string) ($row->dedupe_key ?: 'outbox:'.$row->getKey());
                $registration = EventRegistration::query()->find((int) $attempt->registration_id);

                Notification::query()->firstOrCreate(
                    ['dedupe_key' => $dedupe],
                    [
                        'recipient_type' => 'customer',
                        'recipient_id'   => (int) $attempt->owner_customer_id,
                        'type'           => $eventType,
                        'title'          => $this->notificationTitle($eventType),
                        'content'        => '请到「我的报名」查看当前状态与详情。',
                        'payload'        => [
                            'event_id'        => (int) $attempt->event_id,
                            'registration_id' => (int) $attempt->registration_id,
                            'attempt_id'      => (int) $attempt->getKey(),
                            'public_no'       => $registration?->public_no,
                            'change_id'       => isset($row->payload['change_id']) ? (int) $row->payload['change_id'] : null,
                        ],
                        'channel'        => Notification::CHANNEL_INAPP,
                        'sent_at'        => now(),
                    ],
                );
            }

            $this->leases->settle((int) $row->getKey(), $leaseOwner);
        });
    }

    /**
     * G3-08：成绩事件的核验。
     *
     * 只认“当前公布版本 + 当前状态 + 当前 row_version”。后续终审/修订会让
     * 旧的暂定事件变成 superseded，避免给队长发一条已被覆盖的结论。
     */
    private function competitionResultVerificationFailure(EcosystemOutbox $row): ?string
    {
        $payload = (array) ($row->payload ?? []);
        $resultId = $payload['result_id'] ?? null;
        $versionId = $payload['version_id'] ?? null;
        $status = (string) ($payload['status'] ?? '');
        $rowVersion = $payload['row_version'] ?? null;

        if (! is_int($resultId) && ! (is_string($resultId) && ctype_digit($resultId))) {
            return 'invalid_payload:result_id';
        }

        if (! is_int($versionId) && ! (is_string($versionId) && ctype_digit($versionId))) {
            return 'invalid_payload:version_id';
        }

        if (! is_int($rowVersion) && ! (is_string($rowVersion) && ctype_digit($rowVersion))) {
            return 'invalid_payload:row_version';
        }

        if ((int) $resultId !== (int) $row->aggregate_id) {
            return 'aggregate_mismatch:competition_result';
        }

        $result = CompetitionResult::query()->find((int) $resultId);
        $version = CompetitionResultVersion::query()->find((int) $versionId);

        if ($result === null || $version === null) {
            return 'competition_result_not_found';
        }

        if ((int) $version->result_id !== (int) $result->getKey()) {
            return 'version_result_mismatch';
        }

        if ($result->published_version_id === null || (int) $result->published_version_id !== (int) $version->getKey()) {
            return 'superseded:published_version';
        }

        if ((string) $result->status !== $status || (string) $version->status !== $status) {
            return sprintf('fact_mismatch:status=%s<current=%s', $status, (string) $result->status);
        }

        if ((int) $rowVersion !== (int) $result->row_version) {
            return sprintf('superseded:row_version=%d<current=%d', (int) $rowVersion, (int) $result->row_version);
        }

        return null;
    }

    private function deliverCompetitionResult(EcosystemOutbox $row, string $leaseOwner): void
    {
        $payload = (array) ($row->payload ?? []);
        $result = CompetitionResult::query()->find((int) ($payload['result_id'] ?? 0));
        $version = CompetitionResultVersion::query()->find((int) ($payload['version_id'] ?? 0));

        if ($result === null || $version === null) {
            throw new \RuntimeException('成绩事件缺少 result/version。');
        }

        $entry = CompetitionEntry::query()->find((int) $result->entry_id);
        $recipientId = $entry !== null ? (int) $entry->captain_customer_id : 0;

        if ($recipientId <= 0) {
            throw new \RuntimeException('成绩事件找不到队长收件人。');
        }

        $eventType = (string) $row->event_type;

        DB::transaction(function () use ($row, $leaseOwner, $result, $version, $eventType, $recipientId): void {
            $dedupe = 'notification:'.(string) ($row->dedupe_key ?: 'outbox:'.$row->getKey());

            Notification::query()->firstOrCreate(
                ['dedupe_key' => $dedupe],
                [
                    'recipient_type' => 'customer',
                    'recipient_id'   => $recipientId,
                    'type'           => $eventType,
                    'title'          => $this->competitionNotificationTitle($eventType),
                    'content'        => '请到「我的赛事」查看成绩、排名与申诉截止时间。',
                    'payload'        => [
                        'event_id'   => (int) ($result->track?->edition?->event_id ?? 0),
                        'track_id'   => (int) $result->track_id,
                        'result_id'  => (int) $result->getKey(),
                        'version_id' => (int) $version->getKey(),
                        'entry_id'   => (int) $result->entry_id,
                    ],
                    'channel'        => Notification::CHANNEL_INAPP,
                    'sent_at'        => now(),
                ],
            );

            $this->leases->settle((int) $row->getKey(), $leaseOwner);
        });
    }

    private function competitionAppealVerificationFailure(EcosystemOutbox $row): ?string
    {
        $payload = (array) ($row->payload ?? []);
        $appealId = $payload['appeal_id'] ?? null;
        $status = (string) ($payload['status'] ?? '');
        $rowVersion = $payload['row_version'] ?? null;

        if (! is_int($appealId) && ! (is_string($appealId) && ctype_digit($appealId))) {
            return 'invalid_payload:appeal_id';
        }

        if (! is_int($rowVersion) && ! (is_string($rowVersion) && ctype_digit($rowVersion))) {
            return 'invalid_payload:row_version';
        }

        if ((int) $appealId !== (int) $row->aggregate_id) {
            return 'aggregate_mismatch:competition_appeal';
        }

        $appeal = CompetitionAppeal::query()->find((int) $appealId);

        if ($appeal === null) {
            return 'competition_appeal_not_found';
        }

        if ((string) $appeal->status !== $status) {
            return sprintf('fact_mismatch:status=%s<current=%s', $status, (string) $appeal->status);
        }

        if ((int) $rowVersion !== (int) $appeal->row_version) {
            return sprintf('superseded:row_version=%d<current=%d', (int) $rowVersion, (int) $appeal->row_version);
        }

        return null;
    }

    private function deliverCompetitionAppeal(EcosystemOutbox $row, string $leaseOwner): void
    {
        $payload = (array) ($row->payload ?? []);
        $appeal = CompetitionAppeal::query()->find((int) ($payload['appeal_id'] ?? 0));

        if ($appeal === null) {
            throw new \RuntimeException('申诉事件缺少 appeal。');
        }

        $eventType = (string) $row->event_type;

        DB::transaction(function () use ($row, $leaseOwner, $appeal, $eventType): void {
            $dedupe = 'notification:'.(string) ($row->dedupe_key ?: 'outbox:'.$row->getKey());

            Notification::query()->firstOrCreate(
                ['dedupe_key' => $dedupe],
                [
                    'recipient_type' => 'customer',
                    'recipient_id'   => (int) $appeal->submitted_by_customer_id,
                    'type'           => $eventType,
                    'title'          => $this->competitionNotificationTitle($eventType),
                    'content'        => '请到「我的赛事」查看申诉进度与裁决。',
                    'payload'        => [
                        'appeal_id'  => (int) $appeal->getKey(),
                        'track_id'   => (int) $appeal->track_id,
                        'result_id'  => (int) $appeal->result_id,
                        'status'     => (string) $appeal->status,
                    ],
                    'channel'        => Notification::CHANNEL_INAPP,
                    'sent_at'        => now(),
                ],
            );

            $this->leases->settle((int) $row->getKey(), $leaseOwner);
        });
    }

    private function competitionNotificationTitle(string $eventType): string
    {
        return match ($eventType) {
            'competition_result.provisional' => '成绩已暂定公布，可在申诉期内提出申诉',
            'competition_result.final'       => '成绩已终审公布',
            'competition_appeal.accepted'    => '申诉已受理',
            'competition_appeal.decided'     => '申诉已裁决',
            'competition_appeal.withdrawn'   => '申诉已撤回',
            default                          => '赛务状态更新',
        };
    }

    private function notificationTitle(string $eventType): string
    {
        return match ($eventType) {
            'registration.submitted'       => '报名已提交，等待审核',
            'registration.confirmed'       => '报名已确认',
            'registration.rejected'        => '报名未通过',
            'registration.canceled'        => '报名已取消',
            'registration.event_canceled'  => '活动已取消',
            'registration.change_requested'=> '活动安排有变更，请确认',
            'registration.change_expired'  => '变更确认已逾期',
            default                        => '报名状态更新',
        };
    }

    /**
     * revision_id=0 表示“从未发布对象的撤稿”；此时不要求公开指针。
     */
    private function pointerMatches(int $payloadRevisionId, ?int $publishedRevisionId): bool
    {
        if ($payloadRevisionId === 0) {
            return $publishedRevisionId === null;
        }

        return $publishedRevisionId === $payloadRevisionId;
    }
}
