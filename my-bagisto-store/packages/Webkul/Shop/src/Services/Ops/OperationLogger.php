<?php

namespace Webkul\Shop\Services\Ops;

use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Shop\Models\OperationLog;

/**
 * 操作审计写入 —— `operation_log` 的唯一入口。
 *
 * **为什么出错不抛异常**：审计留痕是"业务动作的旁路"。一次驳回判定不该因为
 * 审计表写不进去就整个失败 —— 那会让一次真实的人工判断凭空消失。
 * 但也**绝不静默**：写失败会以 error 级别进 Laravel 日志，运维能查到。
 *
 * 如果你需要"审计写不进去就不许操作"的强一致（比如金融放款），
 * 请用 `logOrFail()`，别改默认行为。
 */
class OperationLogger
{
    /**
     * 记一条操作。失败只记日志，不影响调用方。
     */
    public function log(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $payload = [],
        ?string $ip = null,
    ): ?OperationLog {
        try {
            return OperationLog::create([
                'actor_type'  => $actorType,
                'actor_id'    => $actorId,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'payload'     => $payload ?: null,
                'ip'          => $ip ?? $this->currentIp(),
                'created_at'  => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('operation_log 写入失败：'.$e->getMessage(), [
                'action' => $action,
                'target' => $targetType.':'.$targetId,
            ]);

            return null;
        }
    }

    /**
     * 强一致版本：审计写不进去就抛。给"留不下痕迹就不许做"的动作（如放款）用。
     */
    public function logOrFail(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $payload = [],
        ?string $ip = null,
    ): OperationLog {
        return OperationLog::create([
            'actor_type'  => $actorType,
            'actor_id'    => $actorId,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'payload'     => $payload ?: null,
            'ip'          => $ip ?? $this->currentIp(),
            'created_at'  => now(),
        ]);
    }

    protected function currentIp(): ?string
    {
        // 命令行（artisan/tinker/测试）里没有请求上下文，request() 会抛
        return app()->runningInConsole() ? null : request()->ip();
    }
}
