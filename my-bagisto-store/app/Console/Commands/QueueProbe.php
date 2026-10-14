<?php

namespace App\Console\Commands;

use App\Jobs\QueueProbeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 验证"队列有没有人在消费"。
 *
 * 为什么需要一条专门的命令：`jobs` 表条数是**不能证明任何事**的指标 ——
 * 0 条既可能是"都消费完了"，也可能是"任务从来没进来过"，
 * 甚至可能是"表被清过"。唯一能区分的是**投一个任务进去，看它会不会消失**。
 *
 * 用法：
 *   php artisan ops:queue-probe            # 投一个探针，最多等 20 秒
 *   php artisan ops:queue-probe --wait=60  # 慢机器上多等一会儿
 *
 * 退出码：0 = 被消费了；1 = 没被消费（worker 没在跑）。
 * 所以它可以直接进部署脚本 / 监控 —— 不需要人读输出。
 */
class QueueProbe extends Command
{
    protected $signature = 'ops:queue-probe
                            {--wait=20 : 最多等多少秒}';

    protected $description = '投一个合成任务并等它被消费，用来证明队列真的有 worker 在跑';

    public function handle(): int
    {
        $connection = (string) config('queue.default');

        $this->line("队列连接：<info>{$connection}</info>");

        if ($connection === 'sync') {
            // sync 下任务在请求内同步执行，根本不经过 worker ——
            // 这时候"探针成功"是假象，必须说出来。
            $this->warn('⚠ QUEUE_CONNECTION=sync：任务在请求内同步执行，这条探针**证明不了有 worker**。');
            $this->warn('  对外服务的环境必须是 database/redis + 起 worker。');

            return self::FAILURE;
        }

        $path = QueueProbeJob::path();
        @unlink($path);

        $nonce = bin2hex(random_bytes(8));
        $pendingBefore = DB::table('jobs')->count();

        QueueProbeJob::dispatch($nonce, now()->toDateTimeString());

        $this->line("已投递探针 <comment>{$nonce}</comment>（投递前 jobs={$pendingBefore}），等待被消费…");

        $deadline = time() + max(1, (int) $this->option('wait'));

        while (time() < $deadline) {
            usleep(300_000);

            if (! is_file($path)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($path), true) ?: [];

            // 只认自己这一次的标记：文件可能是上一次探针留下的
            if (($data['nonce'] ?? null) !== $nonce) {
                continue;
            }

            $this->info('✅ 已被消费');
            $this->line("   投递 {$data['dispatched_at']} → 消费 {$data['consumed_at']}（worker pid {$data['worker_pid']}）");
            $this->line('   jobs 表现在剩 '.DB::table('jobs')->count().' 条');

            return self::SUCCESS;
        }

        $this->error('❌ 等了 '.$this->option('wait').' 秒仍未消费 —— 队列没有 worker 在跑。');

        $pending = DB::table('jobs')->count();
        $this->line("   jobs 表现在 {$pending} 条（含刚投进去的那条）");
        $this->line('   起 worker：<comment>php artisan queue:work --tries=3</comment>');
        $this->line('   ⚠ 没起 worker 是**静默失败**：页面不报错，任务只是躺着。');

        if (app()->isDownForMaintenance()) {
            $this->warn('   ⚠ 另外：应用当前处于维护模式，queue:work 会拒绝消费。先 `php artisan up`。');
        }

        return self::FAILURE;
    }
}
