<?php

use Illuminate\Support\Carbon;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;

/**
 * S-9 · 测试造号的「随机闪红」防线。
 *
 * 本用例刻意**不**用"跑一遍是绿的"当证据 —— 那正是闪红看不出来的地方。
 * 它把原来的失败条件**放大到必然发生**:
 *
 *   ① 把时钟钉死在同一秒 —— 秒级时间戳这个"常量"被固定,唯一性只剩随机数;
 *   ② 用老写法(前缀 + ymdHis + 窄随机数)在同一秒连造 N 条,且 N > 随机区间容量
 *      → 抽屉原理(pigeonhole)保证**必有重复**,直接复现"闪红"的根因;
 *   ③ 换成 testUniqueNo() 再连造同样多条 → 断言**全部唯一**,而且不只在内存里,
 *      真落库 1200 条同秒任务,`uk_deploy_task_no` 不报唯一键冲突。
 *
 * 关键在①:"同一秒"必须被**固定死**。不固定时,跨秒的时间戳会把重复"洗掉" ——
 * 这就是为什么这类 bug 表现为"单跑绿、连跑红",伪装成回归。
 */
it('S-9 · 放大压力:同秒连造上千条,老写法必撞、testUniqueNo 全唯一且真能落库', function () {
    // ① 钉死时钟:整个用例都落在同一秒 → ymdHis 成了常量
    Carbon::setTestNow(Carbon::parse('2026-10-16 12:00:00'));

    $ymdHis = now()->format('ymdHis');

    // ② 老写法复现闪红:random_int(100, 999) 只有 900 档,同秒造 1200 条 > 900
    //    → 抽屉原理保证至少有一对重复。这正是 P11 用例实测撞 uk_deploy_task_no 的机理。
    $legacy = [];

    for ($i = 0; $i < 1200; $i++) {
        $legacy[] = 'DT'.$ymdHis.random_int(100, 999);
    }

    expect(count($legacy) - count(array_unique($legacy)))->toBeGreaterThan(0);

    // ③ 新写法:同样在同一秒里造 1200 条 —— 唯一性由进程内自增计数兜底,断言全唯一
    $fresh = [];

    for ($i = 0; $i < 1200; $i++) {
        $fresh[] = testUniqueNo('DT');
    }

    expect(count(array_unique($fresh)))->toBe(1200);

    // ④ 不止内存唯一 —— 真写库,1200 条同秒任务全部插入成功(唯一键不冲突才算数)
    $binding = DeviceBinding::create([
        'did'            => 'did_s9_'.testUniqueNo('D'),
        'sn'             => 'S9-'.testUniqueNo('SN'),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        'last_online_at' => now(),
    ]);

    for ($i = 0; $i < 1200; $i++) {
        DeployTask::create([
            'task_no'    => testUniqueNo('DT'),
            'did'        => $binding->did,
            'channel'    => 'agent',
            'skill_id'   => 980000 + $i,
            'operation'  => DeployTask::OP_INSTALL,
            'status'     => 'dispatching',
            'created_by' => 'test',
        ]);
    }

    expect(DeployTask::where('did', $binding->did)->count())->toBe(1200);

    Carbon::setTestNow();
});
