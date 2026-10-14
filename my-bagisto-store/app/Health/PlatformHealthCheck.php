<?php

namespace App\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * 平台健康深检 —— 挂在 Laravel 的 `/up` 端点上（见 bootstrap/app.php 的 `health: '/up'`）。
 *
 * 为什么需要它：Laravel 内建的 `/up` **只回 200，什么都不探**。
 * 一个数据库连不上的实例照样回 200，负载均衡器会把流量照常打过来 ——
 * 那这个端点的价值就等于装饰。
 *
 * 三个探针（零新依赖）：
 *   - DB：跑一次 `select 1`
 *   - Cache：写一个随机值再读回来 —— 只 put 不 get 是探不出"写进去了但读不出来"的
 *   - Queue：`Queue::size()` 能返回就说明队列后端可达
 *
 * ⚠ **队列的"积压条数"不参与判定**：健康 = "这个实例现在能不能服务请求"，
 *   积压是运营指标，不是存活指标。把积压当不健康，会让每次积压都表现成"服务挂了"，
 *   运维会开始无视这个端点 —— 那是把健康检查变成噪音。
 *   积压该走告警（见 `ops:alert-queue-backlog`）。
 *
 * 任一探针失败 → 抛异常 → `/up` 返回 500（框架已提供该语义）。
 *
 * ⚠ **探针自己不带信息进响应**：异常消息只进日志。响应长什么样由 `APP_DEBUG` 决定 ——
 *   `false`（生产）是通用 500 页，`true`（开发）是 Laravel 调试页。
 *   也就是说"健康端点泄露内部信息"这件事的真实开关是 **APP_DEBUG**，
 *   不是这个类。生产务必 `APP_DEBUG=false`（`.env.example` 已注明）。
 *   实测：库连不上时 `/up` 返回 500，正常时返回 200。
 */
class PlatformHealthCheck
{
    public function __invoke(): void
    {
        $this->checkDatabase();
        $this->checkCache();
        $this->checkQueue();
    }

    protected function checkDatabase(): void
    {
        try {
            DB::select('select 1');
        } catch (\Throwable $e) {
            throw new RuntimeException('数据库不可用：'.$e->getMessage(), 0, $e);
        }
    }

    protected function checkCache(): void
    {
        $token = bin2hex(random_bytes(8));

        try {
            Cache::put('health:probe', $token, 10);
            $read = Cache::get('health:probe');
        } catch (\Throwable $e) {
            throw new RuntimeException('缓存后端不可用：'.$e->getMessage(), 0, $e);
        }

        // 写得进、读不出 —— 比"直接报错"更隐蔽，后果一样严重（限流/锁全失效）
        if ($read !== $token) {
            throw new RuntimeException('缓存读写不一致（写入成功但读回的值不同）—— 缓存后端可能已分裂或只读');
        }
    }

    protected function checkQueue(): void
    {
        try {
            // 只调用，不看值 —— 能返回就说明后端可达（见类注释）
            Queue::size();
        } catch (\Throwable $e) {
            throw new RuntimeException('队列后端不可用：'.$e->getMessage(), 0, $e);
        }
    }
}
