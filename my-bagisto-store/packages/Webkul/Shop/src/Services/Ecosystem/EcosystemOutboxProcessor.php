<?php

namespace Webkul\Shop\Services\Ecosystem;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Shop\Models\ContentPost;
use Webkul\Shop\Models\EcosystemOutbox;
use Webkul\Shop\Models\Event;

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

            $target === 'dead' ? $summary['dead']++ : $summary['retried']++;

            if ($target === 'dead') {
                Log::error('ecosystem.outbox.dead', [
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
