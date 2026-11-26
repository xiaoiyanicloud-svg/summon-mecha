<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/** 平台 Inbox 强类型事实。`(did,event_id)` 是唯一幂等域。 */
final class InboxEvent
{
    public const EVENT_TYPES = ['ack', 'task_fact', 'execution_fact', 'reconcile'];

    public function __construct(
        public readonly string $did,
        public readonly string $eventId,
        public readonly string $taskNo,
        public readonly string $eventType,
        public readonly string $stage,
        public readonly string $payloadHash,
        public readonly array $payload,
        public readonly ?int $eventSeq,
        public readonly bool $applied,
        public readonly ?string $resultStatus,
        public readonly bool $lateReport,
        public readonly ?int $processedAt,
        public readonly string $ackId,
    ) {
        foreach ([$did, $eventId, $taskNo, $stage, $ackId] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('InboxEvent 身份字段不得为空');
            }
        }
        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('InboxEvent.event_type 非法');
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', $payloadHash)) {
            throw new InvalidArgumentException('InboxEvent.payload_hash 必须为 SHA-256');
        }
        if ($eventSeq !== null && $eventSeq < 1) {
            throw new InvalidArgumentException('InboxEvent.event_seq 必须为正整数');
        }
        if ($processedAt !== null && $processedAt <= 0) {
            throw new InvalidArgumentException('InboxEvent.processed_at 非法');
        }
    }

    public function toArray(): array
    {
        return [
            'did' => $this->did,
            'event_id' => $this->eventId,
            'task_no' => $this->taskNo,
            'event_type' => $this->eventType,
            'stage' => $this->stage,
            'payload_hash' => $this->payloadHash,
            'payload' => $this->payload,
            'event_seq' => $this->eventSeq,
            'applied' => $this->applied,
            'result_status' => $this->resultStatus,
            'late_report' => $this->lateReport,
            'processed_at' => $this->processedAt,
            'ack_id' => $this->ackId,
        ];
    }
}