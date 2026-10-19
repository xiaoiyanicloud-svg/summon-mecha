<?php

namespace Webkul\Shop\Services\Ecosystem;

use Webkul\Shop\Models\Event;

/**
 * 活动来源三字段的写入口（ADR-0001 G1-03）。
 *
 * 只做一件事：先按草稿边界校验来源组合，再交给 `EventWriteService`
 * 在行锁 + 乐观并发下更新 `source_type` / `platform_role` / `organizer_id`。
 *
 * 为什么先校验再调用：
 *   `EventWriteService` 会在 fill 后再校验一次（防止其他调用路径绕过），
 *   但 assign 在进入事务前就把明显非法的组合挡掉，避免无意义地拿行锁；
 *   两次校验是刻意的，不是重复代码。
 *
 * 空串视为“清空”：统一转成 NULL 后写入，避免库里出现空串来源。
 */
class EventSourceService
{
    public function __construct(
        private readonly EventSourceValidator $validator,
        private readonly EventWriteService $writeService,
    ) {}

    public function assign(
        Event $event,
        int $expectedRowVersion,
        ?string $sourceType,
        ?string $platformRole,
        ?int $organizerId,
    ): Event {
        $sourceType = $sourceType === '' ? null : $sourceType;
        $platformRole = $platformRole === '' ? null : $platformRole;

        $this->validator->assertDraftValues($sourceType, $platformRole, $organizerId);

        return $this->writeService->update($event, $expectedRowVersion, [
            'source_type'   => $sourceType,
            'platform_role' => $platformRole,
            'organizer_id'  => $organizerId,
        ]);
    }
}
