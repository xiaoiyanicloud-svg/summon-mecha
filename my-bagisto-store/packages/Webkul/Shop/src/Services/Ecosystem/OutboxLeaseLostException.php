<?php

namespace Webkul\Shop\Services\Ecosystem;

use RuntimeException;

/**
 * 旧 worker 的结算 / 失败回报被拒绝（G1-09 / 规划 §8.6）。
 *
 * 触发条件：条件更新 `status=processing + lease_owner + lease_until >= now`
 * 影响 0 行。说明租约已过期并被恢复扫描置回 pending，或被另一个 worker
 * 重新领取 —— 此时旧 worker **必须丢弃结果**，不得写 sent / dead / pending，
 * 也不得覆盖新 worker 即将写入的状态。
 *
 * 本异常只用于进程内控制流；处理器捕获后记 warning，不对外暴露。
 */
class OutboxLeaseLostException extends RuntimeException
{
    public static function forRow(int $outboxId, string $leaseOwner): self
    {
        return new self(sprintf(
            'outbox 行 %d 的租约已失效（lease_owner=%s）；本次结算被丢弃。',
            $outboxId,
            $leaseOwner,
        ));
    }
}
