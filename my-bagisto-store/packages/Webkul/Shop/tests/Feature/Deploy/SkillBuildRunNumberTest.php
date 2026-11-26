<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Services\SkillCompile\RunNumberAllocator;

/**
 * WBS B2：run_number 单一分配器。
 *
 * 覆盖：同 build_key 连续无重号、不同 build_key 隔离、事务回滚不消耗序号、
 * 仅对本序列表唯一键冲突重试、非唯一键错误立即抛出。
 */

function b2Attrs(int $versionId = 910001, string $model = 'unitree_g1'): array
{
    return [
        'skill_version_id'      => $versionId,
        'target_model_code'     => $model,
        'source_digest'         => 'sha256:'.str_repeat('a', 64),
        'target_profile_digest' => 'sha256:'.str_repeat('b', 64),
        'status'                => 'success',
        'carrier_type'          => 'file',
        'artifact_asset_id'     => null,
        'build_log'             => 'test',
    ];
}

it('B2 · 同一 build_key 连续分配 1..5，无重号', function () {
    $allocator = app(RunNumberAllocator::class);
    $key = 'bk_b2_seq_'.uniqid();

    $numbers = [];
    for ($i = 0; $i < 5; $i++) {
        $run = $allocator->createRun($key, b2Attrs());
        $numbers[] = (int) $run->run_number;
    }

    expect($numbers)->toBe([1, 2, 3, 4, 5])
        ->and(SkillBuildRun::where('build_key', $key)->count())->toBe(5)
        ->and(SkillBuildRun::where('build_key', $key)->distinct()->count('run_number'))->toBe(5);
});

it('B2 · 不同 build_key 的序号互相隔离', function () {
    $allocator = app(RunNumberAllocator::class);
    $a = 'bk_b2_a_'.uniqid();
    $b = 'bk_b2_b_'.uniqid();

    expect($allocator->createRun($a, b2Attrs())->run_number)->toBe(1)
        ->and($allocator->createRun($b, b2Attrs())->run_number)->toBe(1)
        ->and($allocator->createRun($a, b2Attrs())->run_number)->toBe(2)
        ->and($allocator->createRun($b, b2Attrs())->run_number)->toBe(2);
});

it('B2 · target_profile_digest 必须真正落库（fillable 未静默丢弃）', function () {
    $key = 'bk_b2_digest_'.uniqid();
    $digest = 'sha256:'.str_repeat('c', 64);

    $run = app(RunNumberAllocator::class)->createRun($key, array_merge(b2Attrs(), [
        'target_profile_digest' => $digest,
    ]));

    expect($run->fresh()->target_profile_digest)->toBe($digest);
});

it('B2 · 外层事务回滚时序号一起回滚，不产生空洞', function () {
    $allocator = app(RunNumberAllocator::class);
    $key = 'bk_b2_rollback_'.uniqid();

    DB::beginTransaction();
    $first = $allocator->createRun($key, b2Attrs())->run_number;
    DB::rollBack();

    // 回滚后序号与 Run 都消失，下一次仍从 1 开始。
    expect($first)->toBe(1)
        ->and(SkillBuildRun::where('build_key', $key)->count())->toBe(0)
        ->and($allocator->createRun($key, b2Attrs())->run_number)->toBe(1);
});

it('B2 · 只把本序列表唯一键冲突识别为可重试；别表冲突/死锁不重试', function () {
    $allocator = app(RunNumberAllocator::class);
    $ex = fn (string $message) => new QueryException('mysql', 'insert into skill_build_sequences ...', [], new PDOException($message));

    expect($allocator->isSequenceDuplicate(
        $ex("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'bk_x' for key 'uk_build_sequences_key'")
    ))->toBeTrue()
        ->and($allocator->isSequenceDuplicate(
            $ex("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'bk_x' for key 'skill_build_sequences.uk_build_sequences_key'")
        ))->toBeTrue()
        ->and($allocator->isSequenceDuplicate(
            $ex("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'DT250101' for key 'deploy_task.uk_deploy_task_no'")
        ))->toBeFalse()
        ->and($allocator->isSequenceDuplicate(
            $ex('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
        ))->toBeFalse();
});

it('B2 · build_key 为空直接拒绝，不落库', function () {
    expect(fn () => app(RunNumberAllocator::class)->createRun('   ', b2Attrs()))
        ->toThrow(InvalidArgumentException::class);
});
