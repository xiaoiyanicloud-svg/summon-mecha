<?php

use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\RollbackRecord;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\GrayReleaseService;

use function Pest\Laravel\getJson;

/**
 * P4 · 幂等缺失（三条，都指向"重复执行"）。
 *
 *   1. `DeployTaskService::create()` 建任务**无去重** —— 迁移自称"幂等由应用层保证"，
 *      而应用层没有：双击一次下发 = 同设备两条任务，设备取指令时两条都装。
 *   2. `AgentController::commands()` 每次返回该 did 下**全部** `dispatching/pending_online`，
 *      无投递标记 —— 设备在"取到指令"与"回报"之间重启，会把同一条指令再取走一遍。
 *   3. `GrayReleaseService::abort()` 无"已 aborted"守卫 —— 重复调用会**再建一整轮**
 *      回滚任务 + 再写一条 rollback_record。
 *
 * ⚠ 第 2 条的取舍写在这里，别当成 bug 再改回去：
 *   "取走过就不再给"意味着**投递响应丢失**的那次不会自动补发，这条任务会停在
 *   `dispatching` 直到 `ops:timeout-deploy-tasks` 把它转成 timeout（P6 的服务端兜底）。
 *   两害相权：补发会在"设备重启"这个**常见**场景下重装，超时只是让**罕见**的丢包
 *   多等一个阈值 —— 故取"不补发 + 兜底超时"。
 */

// ==================== 造数据 ====================

function p4Binding(bool $online = true): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_p4_'.uniqid(),
        'sn'             => 'P4-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => 'bound',
        'bound_at'       => now(),
        // 在线判定读 last_online_at（窗口见 DeviceBinding::ONLINE_WINDOW_SECONDS）
        'last_online_at' => $online ? now() : now()->subHours(3),
    ]);
}

function p4Version(int $productId, string $version = '1.0.0'): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => $version,
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function p4SkillId(): int
{
    return 900000 + random_int(1, 9999);
}

/** 直接落一条任务（不走 service，绕开去重，用于造"设备手上已有一条指令"的现场）。 */
function p4Task(DeviceBinding $binding, ?int $versionId, string $status = 'dispatching'): DeployTask
{
    return DeployTask::create([
        'task_no'          => 'DT'.now()->format('ymdHis').random_int(1000, 9999),
        'did'              => $binding->did,
        'channel'          => 'agent',
        'skill_id'         => p4SkillId(),
        'skill_version_id' => $versionId,
        'operation'        => DeployTask::OP_INSTALL,
        'status'           => $status,
        'created_by'       => 'test',
    ]);
}

beforeEach(function () {
    config(['deploy_gateways.agent_key' => 'pest-p4-key']);
});

function p4AgentHeaders(): array
{
    return ['X-Agent-Key' => 'pest-p4-key'];
}

// ==================== 1 · 建任务去重 ====================

it('建任务去重：同设备 + 同版本 + 同操作连建两次，只有一条任务（双击不再落两条）', function () {
    $binding = p4Binding();
    $version = p4Version(p4SkillId());

    $service = app(DeployTaskService::class);

    $args = [
        'did'              => $binding->did,
        'skillId'          => $version->product_id,
        'skillVersionId'   => $version->id,
        'entitlementId'    => null,
        'operation'        => DeployTask::OP_INSTALL,
        'channel'          => 'agent',
        'createdBy'        => 'test',
    ];

    $first = $service->create(...$args);
    $second = $service->create(...$args);

    // 第二次短路返回**同一条**任务（前端拿到的 task_no 不变，轮询还是那一条）
    expect($second->task_no)->toBe($first->task_no)
        ->and(DeployTask::query()->where('did', $binding->did)->count())->toBe(1);
});

it('去重不挡重来：上一条已终结（failed）之后再建是合法的', function () {
    $binding = p4Binding();
    $version = p4Version(p4SkillId());

    $service = app(DeployTaskService::class);

    $args = [
        'did'            => $binding->did,
        'skillId'        => $version->product_id,
        'skillVersionId' => $version->id,
        'entitlementId'  => null,
        'operation'      => DeployTask::OP_INSTALL,
        'channel'        => 'agent',
        'createdBy'      => 'test',
    ];

    $first = $service->create(...$args);
    $service->transition($first, 'failed');

    $second = $service->create(...$args);

    expect($second->task_no)->not->toBe($first->task_no)
        ->and(DeployTask::query()->where('did', $binding->did)->count())->toBe(2);
});

it('去重按 (did, 版本, 操作) 三元组：换版本或换操作都不算重复', function () {
    $binding = p4Binding();
    $productId = p4SkillId();
    $v1 = p4Version($productId, '1.0.0');
    $v2 = p4Version($productId, '2.0.0');

    $service = app(DeployTaskService::class);

    $service->create(
        did: $binding->did, skillId: $productId, skillVersionId: $v1->id,
        entitlementId: null, operation: DeployTask::OP_INSTALL, createdBy: 'test',
    );
    $service->create(
        did: $binding->did, skillId: $productId, skillVersionId: $v2->id,
        entitlementId: null, operation: DeployTask::OP_INSTALL, createdBy: 'test',
    );
    $service->create(
        did: $binding->did, skillId: $productId, skillVersionId: $v1->id,
        entitlementId: null, operation: DeployTask::OP_UNINSTALL, createdBy: 'test',
    );

    expect(DeployTask::query()->where('did', $binding->did)->count())->toBe(3);
});

// ==================== 2 · 投递标记 ====================

it('投递标记：取走过一次的指令，设备重启后再拉不再返回（不重装）', function () {
    $binding = p4Binding();
    $task = p4Task($binding, null);

    // 第一次拉取 —— 拿到
    $first = getJson(route('shop.api.agent.commands').'?did='.$binding->did, p4AgentHeaders())
        ->assertOk()
        ->json('data');

    expect($first)->toHaveCount(1)
        ->and($first[0]['task_id'])->toBe($task->task_no);

    // 设备在回报之前重启，重启后的第一次轮询 —— 同一条指令**不再返回**
    $second = getJson(route('shop.api.agent.commands').'?did='.$binding->did, p4AgentHeaders())
        ->assertOk()
        ->json('data');

    expect($second)->toHaveCount(0)
        ->and($task->fresh()->delivered_at)->not->toBeNull();
});

it('投递标记不误伤离线挂起：pending_online 从未投递过，设备上线后照常取到', function () {
    $binding = p4Binding(online: false);
    $version = p4Version(p4SkillId());

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: $version->product_id,
        skillVersionId: $version->id,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        createdBy: 'test',
    );

    expect($task->status)->toBe('pending_online')
        ->and($task->delivered_at)->toBeNull();

    // 设备上线（心跳刷新在线窗口）
    $binding->markSeen('agent');
    $binding->save();

    $commands = getJson(route('shop.api.agent.commands').'?did='.$binding->did, p4AgentHeaders())
        ->assertOk()
        ->json('data');

    expect($commands)->toHaveCount(1)
        ->and($commands[0]['task_id'])->toBe($task->task_no)
        ->and($task->fresh()->status)->toBe('dispatching');
});

// ==================== 3 · 灰度 abort 幂等 ====================

it('灰度 abort 幂等：重复调用不再建第二轮回滚任务，也不再多写 rollback_record', function () {
    $binding = p4Binding();
    $productId = p4SkillId();
    $v1 = p4Version($productId, '1.0.0');
    $v2 = p4Version($productId, '2.0.0');

    $gray = app(GrayReleaseService::class);
    $release = $gray->start($v2->id, ['percent' => 5], 'P4-'.uniqid());

    $deploy = app(DeployTaskService::class);

    // 先装 v1，再"升级"到 v2 —— 走的是真实回报链路，install_record 落 updated
    $t1 = p4Task($binding, $v1->id);
    $deploy->onEvent($t1->task_no, 'done');
    $t2 = p4Task($binding, $v2->id);
    $deploy->onEvent($t2->task_no, 'done');

    $first = $gray->abort($release->fresh(), '第一次', 'manual');
    $second = $gray->abort($release->fresh(), '第二次', 'manual');

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(RollbackRecord::query()->where('gray_release_id', $release->id)->count())->toBe(1)
        ->and(DeployTask::query()
            ->where('did', $binding->did)
            ->where('operation', DeployTask::OP_ROLLBACK)
            ->count())->toBe(1);
});
