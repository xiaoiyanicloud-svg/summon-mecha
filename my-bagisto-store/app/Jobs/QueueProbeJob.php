<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 队列探针 —— 一个刻意"什么都不做"的任务，唯一作用是留下"我被取走了"的痕迹。
 *
 * 存在的理由：本项目已经因为"配置好了但没人跑"栽过一次 ——
 * `QUEUE_CONNECTION=database` 配得好好的，但**没有常驻 worker**，
 * 于是任务静静躺在 jobs 表里，页面不报错、日志不报错，谁都发现不了。
 *
 * 光看 `jobs` 表条数**证明不了有 worker**：条数为 0 既可能是"都消费完了"，
 * 也可能是"任务从来没进来过"。唯一能区分两者的，是**投一个任务进去看它会不会消失**。
 *
 * ⚠ 不要给它加副作用。它的价值全在"被消费"这一件事上；
 *   一旦它开始做正事，"探针失败"与"正事失败"就分不清了。
 */
class QueueProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  string  $nonce  本次探测的唯一标记（防止把上一次探针的痕迹当成这一次的）
     * @param  string  $dispatchedAt  投递时刻
     */
    public function __construct(
        public string $nonce,
        public string $dispatchedAt,
    ) {}

    public function handle(): void
    {
        file_put_contents(self::path(), json_encode([
            'nonce'         => $this->nonce,
            'dispatched_at' => $this->dispatchedAt,
            'consumed_at'   => now()->toDateTimeString(),
            // 消费它的那个进程 —— 能与 `queue:work` 的 pid 对上，才算真的有人在跑
            'worker_pid'    => getmypid(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 痕迹文件路径。命令与任务共用这一处，避免两边写岔。
     */
    public static function path(): string
    {
        return storage_path('app/queue-probe.json');
    }
}
