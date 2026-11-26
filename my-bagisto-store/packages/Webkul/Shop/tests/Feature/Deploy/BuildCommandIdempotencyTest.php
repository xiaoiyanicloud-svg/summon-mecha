<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillBuildCommand;
use Webkul\Shop\Services\SkillCompile\BuildCommand;
use Webkul\Shop\Services\SkillCompile\BuildCommandConflictException;
use Webkul\Shop\Services\SkillCompile\BuildCommandService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;

/**
 * WBS B1：构建命令幂等 / outbox / claim / cancel。
 *
 * 用匿名子类替换 builder，只验证编排与持久化语义；真实编译由既有 C-T 套件覆盖。
 */

function b1FakeBuilder(?BuildResult $result = null): SkillBuildService
{
    return new class($result) extends SkillBuildService {
        public int $calls = 0;

        /** @var array<int, bool> builder 执行时命令行是否已存在 */
        public array $sawRow = [];

        /** @var array<int, int> builder 执行时的 DB 事务层级 */
        public array $sawTransactionLevel = [];

        private ?BuildResult $result;

        public function __construct(?BuildResult $result = null)
        {
            $this->result = $result;
        }

        public function compileWithCommand(BuildCommand $command, array $manifest, array $options = []): BuildResult
        {
            $this->calls++;
            $this->sawRow[] = SkillBuildCommand::query()
                ->where('seller_id', $command->sellerId)
                ->where('idempotency_key', $command->idempotencyKey)
                ->exists();
            $this->sawTransactionLevel[] = DB::transactionLevel();

            return $this->result ?? BuildResult::success(
                carrierType: 'file',
                artifactAssetId: 123,
                retargetConf: ['build_key' => 'bk_b1_'.$command->targetModelCode],
                log: 'ok',
                buildKey: 'bk_b1_'.$command->targetModelCode,
                runNumber: 1,
                targetModelCode: $command->targetModelCode,
                packageSha256: 'abc',
                targetProfileDigest: $command->targetProfileDigest,
            );
        }
    };
}

function b1Command(string $key = 'idem-1', int $seller = 1, string $target = 'unitree_g1'): BuildCommand
{
    return new BuildCommand(
        skillVersionId: 930001,
        sourceAssetId: 930002,
        sourceDigest: 'sha256:'.str_repeat('a', 64),
        targetModelCode: $target,
        targetProfileDigest: 'sha256:'.str_repeat('b', 64),
        compilerVersion: 'portable-v1',
        idempotencyKey: $key,
        requestedBy: 'test',
        sellerId: $seller,
    );
}

function b1CreateCommand(string $key, string $status = SkillBuildCommand::STATUS_QUEUED): SkillBuildCommand
{
    return SkillBuildCommand::create([
        'seller_id'             => 1,
        'idempotency_key'       => $key,
        'request_hash'          => str_repeat('0', 64),
        'status'                => $status,
        'skill_version_id'      => 930001,
        'source_asset_id'       => 930002,
        'source_digest'         => 'sha256:'.str_repeat('a', 64),
        'target_model_code'     => 'unitree_g1',
        'target_profile_digest' => 'sha256:'.str_repeat('b', 64),
        'compiler_version'      => 'portable-v1',
        'requested_by'          => 'test',
    ]);
}

it('B1 · 同键同载荷只执行一次，第二次返回原结果', function () {
    $fake = b1FakeBuilder();
    $service = new BuildCommandService($fake);
    $command = b1Command('idem-same');
    $manifest = ['kind' => 'composite'];

    $first = $service->submit($command, $manifest);
    $second = $service->submit($command, $manifest);

    expect($first['duplicate'])->toBeFalse()
        ->and($first['status'])->toBe(SkillBuildCommand::STATUS_COMPLETED)
        ->and($second['duplicate'])->toBeTrue()
        ->and($second['command_id'])->toBe($first['command_id'])
        ->and($fake->calls)->toBe(1)
        ->and(SkillBuildCommand::where('idempotency_key', 'idem-same')->count())->toBe(1);
});

it('B1 · 同键异载荷 → 冲突异常，不执行第二次', function () {
    $fake = b1FakeBuilder();
    $service = new BuildCommandService($fake);
    $command = b1Command('idem-conflict');

    $service->submit($command, ['kind' => 'composite']);

    expect(fn () => $service->submit($command, ['kind' => 'motion']))
        ->toThrow(BuildCommandConflictException::class);

    expect($fake->calls)->toBe(1)
        ->and(SkillBuildCommand::where('idempotency_key', 'idem-conflict')->count())->toBe(1);
});

it('B1 · 跨 seller 同键互不干扰', function () {
    $fake = b1FakeBuilder();
    $service = new BuildCommandService($fake);

    $a = $service->submit(b1Command('idem-cross', 1), ['kind' => 'composite']);
    $b = $service->submit(b1Command('idem-cross', 2), ['kind' => 'composite']);

    expect($a['command_id'])->not->toBe($b['command_id'])
        ->and($fake->calls)->toBe(2)
        ->and(SkillBuildCommand::where('idempotency_key', 'idem-cross')->count())->toBe(2);
});

it('B1 · 命令先落库、builder 在 submit 事务之外执行', function () {
    $fake = b1FakeBuilder();
    $service = new BuildCommandService($fake);
    $before = DB::transactionLevel();

    $service->submit(b1Command('idem-order'), ['kind' => 'composite']);

    expect($fake->sawRow)->toBe([true])
        ->and($fake->sawTransactionLevel)->toBe([$before]);
});

it('B1 · 失败结果映射为 failed 并保留 error_code', function () {
    $fake = b1FakeBuilder(BuildResult::failed('stale source', 'BUILD_SOURCE_DIGEST_STALE'));
    $service = new BuildCommandService($fake);

    $response = $service->submit(b1Command('idem-fail'), ['kind' => 'composite']);

    expect($response['status'])->toBe(SkillBuildCommand::STATUS_FAILED)
        ->and($response['error_code'])->toBe('BUILD_SOURCE_DIGEST_STALE')
        ->and(SkillBuildCommand::where('idempotency_key', 'idem-fail')->first()->status)->toBe(SkillBuildCommand::STATUS_FAILED);
});

it('B1 · claim 原子性：同一条命令只有一个 worker 领取成功', function () {
    $record = b1CreateCommand('claim-once');
    $service = new BuildCommandService(b1FakeBuilder());

    expect($service->claim((int) $record->id, 'w1'))->toBeTrue()
        ->and($service->claim((int) $record->id, 'w2'))->toBeFalse()
        ->and($record->fresh()->worker_id)->toBe('w1');
});

it('B1 · cancel 只对 queued/running 生效，终态不可取消', function () {
    $queued = b1CreateCommand('cancel-queued');
    $done = b1CreateCommand('cancel-done', SkillBuildCommand::STATUS_COMPLETED);
    $service = new BuildCommandService(b1FakeBuilder());

    expect($service->cancel((int) $queued->id))->toBeTrue()
        ->and($service->cancel((int) $queued->id))->toBeFalse()
        ->and($service->cancel((int) $done->id))->toBeFalse();
});

it('B1 · 已取消的命令不会被迟到 worker 执行或覆盖', function () {
    $record = b1CreateCommand('late-worker');
    $fake = b1FakeBuilder();
    $service = new BuildCommandService($fake);

    $service->claim((int) $record->id, 'w1');
    $service->cancel((int) $record->id);

    $response = $service->run($record->fresh());

    expect($fake->calls)->toBe(0)
        ->and($response['notice'])->toBe('COMMAND_NOT_RUNNING')
        ->and($record->fresh()->status)->toBe(SkillBuildCommand::STATUS_CANCELED);
});

it('B1 · async 模式只落 queued，worker 领取后执行一次', function () {
    config(['skill_build.async_enabled' => true]);

    $fake = b1FakeBuilder();
    $service = new BuildCommandService($fake);

    $accepted = $service->submit(b1Command('idem-async'), ['kind' => 'composite']);

    expect($accepted['status'])->toBe(SkillBuildCommand::STATUS_QUEUED)
        ->and($fake->calls)->toBe(0);

    $summary = $service->runQueued(10);

    expect($summary['claimed'])->toBe(1)
        ->and($summary['completed'])->toBe(1)
        ->and($fake->calls)->toBe(1)
        ->and(SkillBuildCommand::where('idempotency_key', 'idem-async')->first()->status)->toBe(SkillBuildCommand::STATUS_COMPLETED);

    config(['skill_build.async_enabled' => false]);
});
