<?php

namespace Webkul\Shop\Services\Ecosystem;

use InvalidArgumentException;
use Webkul\Shop\Services\Ops\OperationLogger;

/**
 * 生态审核动作审计（ADR-0001 §8 / 附录 B A-05 / G1-08A）。
 *
 * 约束：
 *   · 必须用 `OperationLogger::logOrFail()`：审计写不进去时整个业务事务回滚；
 *   · payload 只允许 `config('ecosystem_publishing.audit_payload_keys')` 中的键
 *     （revision_id / revision_no / from / to / fingerprint_version / reason_code）；
 *   · 不写自由文本、审核意见、联系人、手机号、证件、令牌 —— 审核意见留在
 *     revision.review_note（内部字段），不进入审计 payload；
 *   · target_id 写父对象 ID；revision 归属与版本细节由 payload 承载。
 *     `operation_log.target_id` 当前是 32 位，与 BIGINT 主键的边界风险
 *     已在 G1 规划 GAP-15 记录；本批不扩列。
 *   · 系统动作（G1-09 排期扫描 / 到期发布）用 `recordSystem()` 写
 *     `actor_type=system`，不得伪装成人工审核。
 */
class PublicationAudit
{
    /**
     * 即使调用方误传也必须拒绝的 PII / 自由文本键。
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_KEYS = [
        'name',
        'author_name',
        'review_note',
        'reason',
        'phone',
        'mobile',
        'email',
        'id_card',
        'contact',
        'token',
    ];

    public function __construct(
        private readonly OperationLogger $logger,
    ) {}

    /**
     * @param  'event'|'content'  $domain
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $domain,
        string $action,
        int $actorId,
        int $targetId,
        array $payload,
    ): void {
        $this->assertAction($domain, $action);
        $this->assertPayloadKeys($payload);

        $this->logger->logOrFail(
            'admin',
            $actorId,
            $action,
            $domain === 'event' ? 'event' : 'content_post',
            $targetId,
            $payload,
        );
    }

    /**
     * 系统动作（G1-09 排期扫描 / 到期发布）没有后台账号。
     *
     * 写 `actor_type=system`、`actor_id=NULL`，与人工动作可区分；
     * 仍然使用 `logOrFail()`，审计失败必须让发布事务整体回滚。
     *
     * @param  'event'|'content'  $domain
     * @param  array<string, mixed>  $payload
     */
    public function recordSystem(
        string $domain,
        string $action,
        int $targetId,
        array $payload,
    ): void {
        $this->assertAction($domain, $action);
        $this->assertPayloadKeys($payload);

        $this->logger->logOrFail(
            'system',
            null,
            $action,
            $domain === 'event' ? 'event' : 'content_post',
            $targetId,
            $payload,
        );
    }

    private function assertAction(string $domain, string $action): void
    {
        $allowedActions = (array) config('ecosystem_publishing.audit_actions.'.$domain, []);

        if (! in_array($action, $allowedActions, true)) {
            throw new InvalidArgumentException(sprintf(
                '未知的 %s 审计动作「%s」；只允许：%s。',
                $domain,
                $action,
                implode(', ', $allowedActions),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPayloadKeys(array $payload): void
    {
        $allowed = array_values((array) config('ecosystem_publishing.audit_payload_keys', []));
        $forbidden = array_values(array_intersect(array_keys($payload), self::FORBIDDEN_KEYS));

        if ($forbidden !== []) {
            throw new InvalidArgumentException(sprintf(
                '审计 payload 不得包含字段：%s。',
                implode(', ', $forbidden),
            ));
        }

        $unknown = array_values(array_diff(array_keys($payload), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                '审计 payload 只允许字段：%s；收到：%s。',
                implode(', ', $allowed),
                implode(', ', $unknown),
            ));
        }
    }
}
