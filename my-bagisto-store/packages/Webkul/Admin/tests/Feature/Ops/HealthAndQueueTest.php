<?php

use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

/**
 * 健康深检 + 队列告警（v3.4 第 10 步）。
 *
 * 这两个是"运维能不能看见问题"的基础设施：
 *   - `/up` 原先只回 200，库挂了也照样 200 → 负载均衡器一直打流量过来
 *   - 队列积压没有任何信号 → worker 挂了没人知道（本项目实情：19 条积压躺了 25 小时）
 */

function oqPushJob(int $ageMinutes, string $queue = 'default'): void
{
    $at = now()->subMinutes($ageMinutes)->timestamp;

    DB::table('jobs')->insert([
        'queue'        => $queue,
        'payload'      => json_encode(['displayName' => 'TestJob', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']),
        'attempts'     => 0,
        'available_at' => $at,
        'created_at'   => $at,
    ]);
}

/**
 * ⚠ 开发库里**本来就有真实积压**（19 条从未被消费的 job）。
 *   不清掉的话，"不该报警"这类断言会永远失败 —— 那是环境噪音，不是被测逻辑。
 *   清空发生在事务内，`DatabaseTransactions` 会回滚，真实数据不受影响。
 */
beforeEach(function () {
    DB::table('jobs')->delete();
    DB::table('failed_jobs')->delete();
});

// ==================== 健康深检 ====================

it('健康端点：一切正常时返回 200', function () {
    get('/up')->assertStatus(200);
});

it('健康端点：探针覆盖 DB / 缓存 / 队列三者', function () {
    // 结构性断言：三个探针都在（不是只探了一个就交差）
    $source = file_get_contents(base_path('app/Health/PlatformHealthCheck.php'));

    expect($source)->toContain('checkDatabase')
        ->and($source)->toContain('checkCache')
        ->and($source)->toContain('checkQueue');
});

it('健康端点：已挂到 DiagnosingHealth 事件上（否则探针永不执行）', function () {
    $listeners = app('events')->getListeners(\Illuminate\Foundation\Events\DiagnosingHealth::class);

    expect($listeners)->not->toBeEmpty();
});

it('健康端点：队列积压不参与判定（积压是运营指标，不是存活指标）', function () {
    // 塞 200 条积压
    for ($i = 0; $i < 200; $i++) {
        oqPushJob(600);
    }

    // 仍然健康 —— 把积压当不健康会让每次高峰都表现成"服务挂了"
    get('/up')->assertStatus(200);
});

// ==================== 队列告警 ====================

it('队列告警：最老的 job 滞留超阈值 → 发告警', function () {
    $admin = $this->loginAsAdmin();

    oqPushJob(120);   // 滞留 2 小时，阈值默认 30 分钟

    artisan('ops:alert-queue-backlog')->assertSuccessful();

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 'queue_stuck')
        ->exists())->toBeTrue();
});

it('队列告警：判据是「最老滞留时长」而不是「积压条数」', function () {
    $this->loginAsAdmin();

    // 200 条积压，但都很新鲜（worker 正在飞速消费的正常高峰）
    for ($i = 0; $i < 200; $i++) {
        oqPushJob(1);
    }

    artisan('ops:alert-queue-backlog')->assertSuccessful();

    // 不该报警 —— 单看条数，"正常高峰"与"worker 挂了"长得一模一样
    expect(DB::table('notification')->where('type', 'queue_stuck')->count())->toBe(0);
});

it('队列告警：队列为空时不报警', function () {
    $this->loginAsAdmin();

    artisan('ops:alert-queue-backlog')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'queue_stuck')->count())->toBe(0);
});

it('队列告警：阈值可用 --minutes 调整', function () {
    $this->loginAsAdmin();

    oqPushJob(20);   // 滞留 20 分钟

    // 默认 30 分钟阈值下不报
    artisan('ops:alert-queue-backlog')->assertSuccessful();
    expect(DB::table('notification')->where('type', 'queue_stuck')->count())->toBe(0);

    // 阈值收到 10 分钟就报
    artisan('ops:alert-queue-backlog --minutes=10')->assertSuccessful();
    expect(DB::table('notification')->where('type', 'queue_stuck')->count())->toBeGreaterThan(0);
});

it('队列告警：失败队列有条目就报（不需要阈值 —— 失败任务不会自愈）', function () {
    $admin = $this->loginAsAdmin();

    DB::table('failed_jobs')->insert([
        'uuid'       => (string) \Illuminate\Support\Str::uuid(),
        'connection' => 'database',
        'queue'      => 'default',
        'payload'    => '{}',
        'exception'  => 'boom',
        'failed_at'  => now(),
    ]);

    artisan('ops:alert-queue-backlog')->assertSuccessful();

    expect(DB::table('notification')
        ->where('recipient_id', $admin->id)
        ->where('type', 'queue_failed')
        ->exists())->toBeTrue();
});

it('队列告警：去重键随失败数变化（同一个键会被永久去重吞掉）', function () {
    $admin = $this->loginAsAdmin();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database',
        'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now(),
    ]);

    artisan('ops:alert-queue-backlog');

    $afterOne = DB::table('notification')->where('type', 'queue_failed')->count();

    // 又失败了一条 —— 条数变了，应该能再报一次
    DB::table('failed_jobs')->insert([
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database',
        'queue' => 'default', 'payload' => '{}', 'exception' => 'boom2', 'failed_at' => now(),
    ]);

    artisan('ops:alert-queue-backlog');

    expect(DB::table('notification')->where('type', 'queue_failed')->count())
        ->toBeGreaterThan($afterOne);
});

it('队列告警：--dry-run 不写库', function () {
    $this->loginAsAdmin();

    oqPushJob(120);

    artisan('ops:alert-queue-backlog --dry-run')->assertSuccessful();

    expect(DB::table('notification')->where('type', 'queue_stuck')->count())->toBe(0);
});
