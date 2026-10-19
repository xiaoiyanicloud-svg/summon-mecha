<?php

namespace Webkul\Shop\Services\Ecosystem;

use InvalidArgumentException;
use Webkul\Shop\Models\EcosystemOutbox;

/**
 * 发布事实 → outbox 的唯一构造入口（ADR-0001 §4.11 / 附录 B A-03 / G1-08B）。
 *
 * 边界：
 *   · 只在**已发生**的公开事实（published / withheld / restored / retracted）上调用；
 *     排期（schedule / unschedule）不写 outbox，未来时间只存父对象；
 *   · 不自开事务 —— 必须与发布服务在同一事务内执行，业务回滚则 outbox 一起回滚；
 *   · payload 只放 ID / 版本 / 原因码 / `fingerprint_version`，不放正文、来源、理由文本；
 *   · `dedupe_key` 由事件类型 + 聚合 + revision + 变更后的 `row_version` 组成，
 *     同一事实重放命中同一行，不新增记录。
 */
class PublicationOutbox
{
    public function __construct(
        private readonly OutboxWriter $writer,
    ) {}

    /**
     * @param  'event'|'content'  $domain
     * @param  'publish'|'withhold'|'restore'|'retract'  $action
     * @param  int  $revisionId  当前公开 revision；从未发布对象撤稿时为 0
     *
     * @throws InvalidArgumentException 未登记的域 / 动作 / outbox 事件类型
     */
    public function record(
        string $domain,
        int $aggregateId,
        string $action,
        int $revisionId,
        int $revisionNo,
        int $rowVersion,
        string $reasonCode,
    ): EcosystemOutbox {
        $eventType = config('ecosystem_publishing.outbox_event_types.'.$domain.'.'.$action);

        if (! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException(sprintf(
                '未登记的发布 outbox 映射：%s.%s。',
                $domain,
                $action,
            ));
        }

        $payload = [
            'revision_id'         => $revisionId,
            'revision_no'         => $revisionNo,
            'row_version'         => $rowVersion,
            'fingerprint_version' => (int) config('ecosystem_revisions.fingerprint_version', 1),
            'reason_code'         => $reasonCode,
        ];

        return $this->writer->write(
            $domain,
            $aggregateId,
            $eventType,
            $payload,
            $this->dedupeKey($aggregateId, $eventType, $revisionId, $rowVersion),
        );
    }

    /**
     * 稳定格式：`{event_type}#{aggregate_id}#r{revision_id}#v{row_version}`。
     *
     * 同一行的同一状态变更只可能有一个 row_version，因此重放不会造第二条；
     * 两个不同事实必然 row_version 不同，不会误命中。
     */
    private function dedupeKey(
        int $aggregateId,
        string $eventType,
        int $revisionId,
        int $rowVersion,
    ): string {
        return sprintf(
            '%s#%d#r%d#v%d',
            $eventType,
            $aggregateId,
            $revisionId,
            $rowVersion,
        );
    }
}
