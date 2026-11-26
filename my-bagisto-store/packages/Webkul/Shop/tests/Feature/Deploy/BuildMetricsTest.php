<?php

use Webkul\Shop\Models\SkillBuildCommand;
use Webkul\Shop\Services\SkillCompile\BuildCommand;
use Webkul\Shop\Services\SkillCompile\BuildCommandService;
use Webkul\Shop\Services\SkillCompile\BuildMetricsService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;

/**
 * WBS B8：构建指标只读快照。
 *
 * 指标从命令表派生，因此同键重试不会重复计数；label 只有有界 status 与 build_key。
 */

function b8Command(array $overrides = []): SkillBuildCommand
{
    return SkillBuildCommand::create(array_merge([
        'seller_id'             => 1,
        'idempotency_key'       => 'b8_'.uniqid(),
        'request_hash'          => str_repeat('0', 64),
        'status'                => SkillBuildCommand::STATUS_QUEUED,
        'build_key'             => 'bk_b8_metrics',
        'skill_version_id'      => 940001,
        'source_asset_id'       => 940002,
        'source_digest'         => 'sha256:'.str_repeat('a', 64),
        'target_model_code'     => 'unitree_g1',
        'target_profile_digest' => 'sha256:'.str_repeat('b', 64),
        'compiler_version'      => 'portable-v1',
        'requested_by'          => 'test',
    ], $overrides));
}

it('B8 · 按状态与时长输出有界指标', function () {
    b8Command([
        'status' => SkillBuildCommand::STATUS_COMPLETED,
        'result_json' => ['status' => 'success'],
        'started_at' => '2026-11-05 00:00:00',
        'finished_at' => '2026-11-05 00:00:02',
    ]);
    b8Command([
        'status' => SkillBuildCommand::STATUS_COMPLETED,
        'result_json' => ['status' => 'pending'],
        'started_at' => '2026-11-05 00:00:00',
        'finished_at' => '2026-11-05 00:00:01',
    ]);
    b8Command([
        'status' => SkillBuildCommand::STATUS_FAILED,
        'result_json' => ['status' => 'failed'],
        'started_at' => '2026-11-05 00:00:00',
        'finished_at' => '2026-11-05 00:00:03',
    ]);
    b8Command(['status' => SkillBuildCommand::STATUS_CANCELED]);
    b8Command(['status' => SkillBuildCommand::STATUS_QUEUED]);
    b8Command([
        'status' => SkillBuildCommand::STATUS_RUNNING,
        'started_at' => '2026-11-05 00:00:00',
    ]);

    $snapshot = app(BuildMetricsService::class)->snapshot('bk_b8_metrics');

    expect($snapshot['build_accepted'])->toBe(6)
        ->and($snapshot['build_started'])->toBe(4)
        ->and($snapshot['build_succeeded'])->toBe(2)
        ->and($snapshot['build_failed'])->toBe(1)
        ->and($snapshot['build_canceled'])->toBe(1)
        ->and($snapshot['build_queued'])->toBe(1)
        ->and($snapshot['build_running'])->toBe(1)
        ->and($snapshot['build_duration_ms']['samples'])->toBe(3)
        ->and($snapshot['build_duration_ms']['total'])->toBe(6000.0)
        ->and($snapshot['build_duration_ms']['avg'])->toBe(2000.0)
        ->and($snapshot['build_duration_ms']['max'])->toBe(3000.0)
        ->and($snapshot['result_status_breakdown']['success'])->toBe(1)
        ->and($snapshot['result_status_breakdown']['pending'])->toBe(1)
        ->and($snapshot['result_status_breakdown']['failed'])->toBe(1)
        ->and($snapshot['build_key'])->toBe('bk_b8_metrics');
});

it('B8 · build_key 过滤只统计该关联 ID', function () {
    b8Command(['status' => SkillBuildCommand::STATUS_COMPLETED, 'build_key' => 'bk_b8_a']);
    b8Command(['status' => SkillBuildCommand::STATUS_FAILED, 'build_key' => 'bk_b8_b']);

    $a = app(BuildMetricsService::class)->snapshot('bk_b8_a');
    $b = app(BuildMetricsService::class)->snapshot('bk_b8_b');

    expect($a['build_accepted'])->toBe(1)
        ->and($a['build_succeeded'])->toBe(1)
        ->and($a['build_failed'])->toBe(0)
        ->and($b['build_accepted'])->toBe(1)
        ->and($b['build_failed'])->toBe(1);
});

it('B8 · 同键重试不重复计数', function () {
    $fake = new class extends SkillBuildService {
        public function compileWithCommand(BuildCommand $command, array $manifest, array $options = []): BuildResult
        {
            return BuildResult::success(
                carrierType: 'file',
                artifactAssetId: 1,
                retargetConf: ['build_key' => 'bk_b8_retry'],
                log: 'ok',
                buildKey: 'bk_b8_retry',
                runNumber: 1,
                targetModelCode: $command->targetModelCode,
                packageSha256: 'sha',
                targetProfileDigest: $command->targetProfileDigest,
            );
        }
    };

    $service = new BuildCommandService($fake);
    $command = new BuildCommand(
        skillVersionId: 940001,
        sourceAssetId: 940002,
        sourceDigest: 'sha256:'.str_repeat('a', 64),
        targetModelCode: 'unitree_g1',
        targetProfileDigest: 'sha256:'.str_repeat('b', 64),
        compilerVersion: 'portable-v1',
        idempotencyKey: 'b8_retry',
        requestedBy: 'test',
        sellerId: 1,
    );

    $service->submit($command, ['kind' => 'composite']);
    $service->submit($command, ['kind' => 'composite']);

    $snapshot = app(BuildMetricsService::class)->snapshot('bk_b8_retry');

    expect($snapshot['build_accepted'])->toBe(1)
        ->and($snapshot['build_succeeded'])->toBe(1)
        ->and($snapshot['build_started'])->toBe(1);
});
