<?php

namespace Webkul\Shop\Services\Deploy\Contracts;

use InvalidArgumentException;

/**
 * Agent 事件正文信封 v3（WBS A1）。
 *
 * 与下行 AgentTaskEnvelopeV2 不同：本信封由**设备私钥**签名，平台用登记证书验签，
 * 覆盖的是事件正文本身（event_id/did/task_no/event_seq/phase/execution_fact/evidence/reported_at），
 * 不是只签 method/path/timestamp。
 *
 * 规范字节：UTF-8("summon-mecha/agent-fact/v3") || 0x0A || UTF-8(JCS(signed))
 */
final class AgentFactEnvelopeV3
{
    public const PROTOCOL = 'agent-fact/v3';

    public const PREFIX = "summon-mecha/agent-fact/v3\n";

    public function __construct(
        public readonly string $eventId,
        public readonly string $did,
        public readonly string $taskNo,
        public readonly ?int $eventSeq,
        public readonly string $phase,
        public readonly ?array $executionFact,
        public readonly array $evidence,
        public readonly int $reportedAt,
        public readonly string $signature,
        public readonly string $keyId,
    ) {
        foreach ([
            'event_id' => $eventId,
            'did' => $did,
            'task_no' => $taskNo,
            'phase' => $phase,
            'signature' => $signature,
            'key_id' => $keyId,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("AgentFactEnvelopeV3 {$name} 不得为空");
            }
        }

        if ($reportedAt <= 0) {
            throw new InvalidArgumentException('AgentFactEnvelopeV3 reported_at 非法');
        }

        if ($eventSeq !== null && $eventSeq < 1) {
            throw new InvalidArgumentException('AgentFactEnvelopeV3 event_seq 非法');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            eventId: (string) ($data['event_id'] ?? ''),
            did: (string) ($data['did'] ?? ''),
            taskNo: (string) ($data['task_no'] ?? ''),
            eventSeq: isset($data['event_seq']) ? (int) $data['event_seq'] : null,
            phase: (string) ($data['phase'] ?? ''),
            executionFact: is_array($data['execution_fact'] ?? null) ? $data['execution_fact'] : null,
            evidence: is_array($data['evidence'] ?? null) ? $data['evidence'] : [],
            reportedAt: (int) ($data['reported_at'] ?? 0),
            signature: (string) ($data['signature'] ?? ''),
            keyId: (string) ($data['key_id'] ?? ''),
        );
    }

    /**
     * 固定签名域：字段顺序无关（JCS 会排序），但**字段集合**不可增删。
     */
    public function signedFields(): array
    {
        return [
            'protocol'      => self::PROTOCOL,
            'event_id'      => $this->eventId,
            'did'           => $this->did,
            'task_no'       => $this->taskNo,
            'event_seq'     => $this->eventSeq,
            'phase'         => $this->phase,
            'execution_fact' => $this->executionFact,
            // ⚠ `evidence` 在契约里是 **JSON 对象**，不是数组。空值必须规范成 `{}`：
            //   PHP 的空 array 经 JCS 会变成 `[]`，而 Python 侧发的是 `{}` ——
            //   两边签出来的字节不同，验签必然失败。这个坑真实发生过一次
            //   （Agent 直连 live 测试报 AGENT_FACT_SIGNATURE_INVALID，而 PHP 侧自签自验的用例全绿）。
            'evidence'      => $this->evidence === [] ? (object) [] : $this->evidence,
            'reported_at'   => $this->reportedAt,
        ];
    }

    public static function canonicalFor(array $signed): string
    {
        return self::PREFIX . \Webkul\Shop\Services\Deploy\SigningService::jcsCanonicalize($signed);
    }

    public function canonical(): string
    {
        return self::canonicalFor($this->signedFields());
    }

    public function toArray(): array
    {
        return $this->signedFields() + [
            'signature' => $this->signature,
            'key_id'    => $this->keyId,
        ];
    }
}
