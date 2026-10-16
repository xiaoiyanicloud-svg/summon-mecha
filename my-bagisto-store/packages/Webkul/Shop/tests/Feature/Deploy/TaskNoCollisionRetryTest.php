<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Services\Deploy\AgentGatewayManager;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\LifecycleNotifier;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;

/**
 * S-10 · 下发任务号撞唯一键 `uk_deploy_task_no` → 换号重试自愈（修生产 500）。
 *
 * 根因:`newTaskNo()` 曾是 `'DT'.now()->format('ymdHis').random_int(1000, 9999)` ——
 *   秒级时间戳在同秒内是常量,唯一性只剩 **9000 档**随机数。而 `create()` 只锁**各自的
 *   `device_binding`** 行(刻意的,见其注释),**跨设备不互斥** —— 两台不同设备在同一秒
 *   下发,完全可能抽到同一个后缀 → 插 `deploy_task` 撞唯一键 → 裸 QueryException → 下发 500。
 *   这是**交付主路径**上的崩溃,必须自愈,不能让用户看到 500。
 *
 * 本用例覆盖四件事:
 *   1. 撞号能自愈(换号重试成功,不留脏数据);
 *   2. 试满上限仍撞 → 抛出(不静默返回 null / 半条记录);
 *   3. 非「本表撞号」的 QueryException 照常抛,且**不重试**(不许被 catch 吞掉);
 *   4. 回归:新号仍是 `DT` + 时间戳前缀(运维可按时间检索),且同秒熵已放宽。
 *
 * ⚠ 覆写 `newTaskNo()` 用「子类 + app()->instance() 替换」,而**不是**在生产代码里加
 *   测试 seam —— 生产代码不留测试专用分支。
 */

/** 在线且已绑定的设备(dispatch 走 dispatching,任务能被正常建出来)。 */
function s10Binding(): DeviceBinding
{
    // uniqid() 基于真实 microtime,而 Carbon::setTestNow 只冻 Laravel 的"现在",不冻
    // PHP 的时钟 —— 但为免同微秒偶发撞 did,再叠一个进程内自增,确保 did 全局唯一。
    static $seq = 0;

    return DeviceBinding::create([
        'did'            => 'did_s10_'.++$seq.'_'.uniqid(),
        'sn'             => 'S10-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => now(),
    ]);
}

/** 直接落一条任务(绕过 service,用于造"某个号已被占用"的现场)。 */
function s10Task(string $taskNo, string $did): DeployTask
{
    return DeployTask::create([
        'task_no'    => $taskNo,
        'did'        => $did,
        'channel'    => 'agent',
        'skill_id'   => 990000 + random_int(1, 9999),
        'operation'  => DeployTask::OP_INSTALL,
        'status'     => 'dispatching',
        'created_by' => 'test',
    ]);
}

/**
 * 造一个覆写了 `newTaskNo()` 的 DeployTaskService 子类并注册进容器,返回该实例。
 *
 * @param  callable():string  $taskNoFactory  每次造号时调用,由用例决定返回什么号
 */
function s10FakeService(callable $taskNoFactory): DeployTaskService
{
    $fake = new class extends DeployTaskService {
        /** @var callable */
        public $taskNoFactory;

        public function __construct()
        {
            // 依赖从容器现取 —— 与真实服务同源,只是把造号这一步换成受控实现。
            parent::__construct(
                app(SigningService::class),
                app(AgentGatewayManager::class),
                app(LifecycleNotifier::class),
                app(PackageFingerprint::class),
            );
        }

        protected function newTaskNo(): string
        {
            return ($this->taskNoFactory)();
        }
    };

    $fake->taskNoFactory = $taskNoFactory;

    app()->instance(DeployTaskService::class, $fake);

    return $fake;
}

/** 用真实实现建一条任务(不覆写造号)—— 四处测试的公共调用参数。 */
function s10Create(DeployTaskService $service, string $did, int $skillId): DeployTask
{
    return $service->create(
        did: $did,
        skillId: $skillId,
        skillVersionId: null,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        channel: 'agent',
        createdBy: 'test',
    );
}

// ==================== 1 · 撞号能自愈 ====================

it('S-10 · 撞号能自愈:造号器先给一个已存在的号、再给新号 → create() 成功返回新号任务', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));

    $target = s10Binding();               // 要建任务的设备
    $holder = s10Binding();               // 另一台设备,持有一个"待撞"的号(跨设备同秒撞号的真实现场)
    $collisionNo = 'DT'.now()->format('ymdHis').'-EXIST1';
    s10Task($collisionNo, $holder->did);

    // 造号器:第一次返回已存在的号(必撞唯一键),之后回落到一个**新的**号。
    $calls = 0;
    $fake = s10FakeService(function () use (&$calls, $collisionNo) {
        $calls++;

        return $calls === 1 ? $collisionNo : 'DT'.now()->format('ymdHis').'-FRESH1';
    });

    $task = s10Create($fake, $target->did, 992001);

    expect($task)->toBeInstanceOf(DeployTask::class)          // 成功返回任务(不是抛异常)
        ->and($calls)->toBe(2)                                 // 真的撞了一次、换号重试一次
        ->and($task->task_no)->not->toBe($collisionNo)         // 最终号不是撞上的那个
        ->and($task->task_no)->toBe('DT'.now()->format('ymdHis').'-FRESH1')
        ->and($task->did)->toBe($target->did)
        // 被撞的旧任务原样保留,新任务也真落了库 —— 两条都在,没有互相覆盖
        ->and(DeployTask::query()->where('task_no', $collisionNo)->count())->toBe(1)
        ->and(DeployTask::query()->where('task_no', $task->task_no)->count())->toBe(1);

    Carbon::setTestNow();
});

// ==================== 2 · 试满上限仍撞 → 抛出,不留脏数据 ====================

it('S-10 · 试满上限仍撞 → 抛出(不静默返回 null / 半条记录),且库里不留脏数据', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));

    $target = s10Binding();
    $holder = s10Binding();
    $collisionNo = 'DT'.now()->format('ymdHis').'-STUCK1';
    s10Task($collisionNo, $holder->did);

    // 造号器**永远**返回那个已存在的号 → 每次都撞,直到重试耗尽。
    $calls = 0;
    $fake = s10FakeService(function () use (&$calls, $collisionNo) {
        $calls++;

        return $collisionNo;
    });

    $before = DeployTask::query()->count();

    expect(fn () => s10Create($fake, $target->did, 993001))
        ->toThrow(QueryException::class);

    expect($calls)->toBe(5)   // 初始一次 + 重试到上限(与 TicketController 的 5 一致),不是第一次就放弃
        ->and(DeployTask::query()->where('did', $target->did)->count())->toBe(0)  // 目标设备没留下任何任务
        ->and(DeployTask::query()->count())->toBe($before);                       // 总量不变:没有半条脏记录

    Carbon::setTestNow();
});

// ==================== 3 · 非「本表撞号」照常抛,且不重试 ====================

it('S-10 · 非「本表撞号」的 QueryException 照常抛,且不重试(不允许被 catch 吞掉)', function () {
    $target = s10Binding();

    // 让事务内的第一处查询抛一条**别的表**唯一键冲突 —— 换号根本救不了它。
    // 行为断言:必须原样冒出去,而且**只尝试了一次**(没被误当成撞号反复重试)。
    $fake = new class extends DeployTaskService {
        public int $findCalls = 0;

        public function __construct()
        {
            parent::__construct(
                app(SigningService::class),
                app(AgentGatewayManager::class),
                app(LifecycleNotifier::class),
                app(PackageFingerprint::class),
            );
        }

        protected function findOpenTask(string $did, ?int $skillVersionId, int $operation): ?DeployTask
        {
            $this->findCalls++;

            throw new QueryException(
                'mysql',
                'select * from deploy_task where did = ?',
                [$did],
                new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'T2501010000001-ABCDEF' for key 'uk_support_ticket_no'"),
            );
        }
    };

    app()->instance(DeployTaskService::class, $fake);

    expect(fn () => s10Create($fake, $target->did, 995001))
        ->toThrow(QueryException::class);

    expect($fake->findCalls)->toBe(1);   // 未被当成撞号重试
});

it('S-10 · 判重只认本表 uk_deploy_task_no:别的键冲突 / 非唯一键错误都不算「撞号」', function () {
    $service = app(DeployTaskService::class);

    $method = new ReflectionMethod(DeployTaskService::class, 'isTaskNoDuplicate');
    $method->setAccessible(true);

    $ex = fn (string $msg) => new QueryException('mysql', 'insert into deploy_task ...', [], new PDOException($msg));
    $isDup = fn (string $msg) => $method->invoke($service, $ex($msg));

    // 本表撞号(MySQL 8.0 报文,带表名前缀)→ 认
    expect($isDup("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'DT251016120000-ABC123' for key 'deploy_task.uk_deploy_task_no'"))->toBeTrue()
        // 本表撞号(MySQL 5.7 报文,只有索引名)→ 也认
        ->and($isDup("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'DT251016120000-ABC123' for key 'uk_deploy_task_no'"))->toBeTrue()
        // 本表撞号(SQLite 报文,只有列名)→ 也认
        ->and($isDup('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: deploy_task.task_no'))->toBeTrue()
        // **别表**唯一键冲突 → 不认(换号救不了它,重试只会掩盖根因)
        ->and($isDup("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'T2501010000001-ABCDEF' for key 'uk_support_ticket_no'"))->toBeFalse()
        // 非唯一键错误(如死锁)→ 不认
        ->and($isDup('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'))->toBeFalse();
});

// ==================== 4 · 回归:号的可读前缀不变 + 熵已放宽 ====================

it('S-10 · 回归:新号仍是 DT + ymdHis 时间戳(运维可按时间检索),同秒多条不再撞', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));

    $ymdHis = now()->format('ymdHis');

    $a = s10Binding();
    $b = s10Binding();

    $service = app(DeployTaskService::class);   // 真实实现,不覆写造号

    $t1 = s10Create($service, $a->did, 994001);
    $t2 = s10Create($service, $b->did, 994002);

    foreach ([$t1, $t2] as $t) {
        // 前缀 `DT` + 秒级时间戳原样保留;后缀已从 9000 档放宽到 6 位 base36。
        expect($t->task_no)->toStartWith('DT'.$ymdHis)
            ->and($t->task_no)->toMatch('/^DT\d{12}-[A-Z0-9]{6}$/');
    }

    // 同一秒两条 → 号不同(熵放宽的直接体现;9000 档时代这里就可能撞)
    expect($t1->task_no)->not->toBe($t2->task_no);

    Carbon::setTestNow();
});
