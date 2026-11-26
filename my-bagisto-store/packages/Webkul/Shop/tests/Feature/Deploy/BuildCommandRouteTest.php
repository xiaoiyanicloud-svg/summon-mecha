<?php

use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuildCommand;
use Webkul\Shop\Services\SkillCompile\BuildCommand;
use Webkul\Shop\Services\SkillCompile\BuildCommandService;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;

use function Pest\Laravel\post;

/**
 * WBS B1：生产入口（internal.key compile 路由）真实经过 BuildCommand + 幂等命令表。
 *
 * 用匿名 builder 子类替代真实编译，只验证入口编排；真实编译由 C-T 套件覆盖。
 */

function b1RouteSource(int $versionId): SkillAsset
{
    return SkillAsset::create([
        'skill_version_id' => $versionId,
        'carrier_type'     => 'file',
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'route_src_'.uniqid(),
        'file_path'        => 'skills/route/'.uniqid().'.skill',
        'file_name'        => 'route.skill',
        'file_size'        => 10,
        'file_hash'        => hash('sha256', 'route-source'),
        'sort_order'       => 0,
    ]);
}

function b1RouteFake(): SkillBuildService
{
    return new class extends SkillBuildService {
        public int $calls = 0;

        public function compileWithCommand(BuildCommand $command, array $manifest, array $options = []): BuildResult
        {
            $this->calls++;

            return BuildResult::success(
                carrierType: 'file',
                artifactAssetId: 1,
                retargetConf: ['build_key' => 'bk_route_'.$command->targetModelCode],
                log: 'route fake',
                buildKey: 'bk_route_'.$command->targetModelCode,
                runNumber: 1,
                targetModelCode: $command->targetModelCode,
                packageSha256: 'route-sha',
                targetProfileDigest: $command->targetProfileDigest,
            );
        }
    };
}

function b1RoutePost(int $versionId, array $body)
{
    return post(route('shop.api.skill-supply.compile', $versionId), $body, [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ]);
}

beforeEach(function () {
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

it('B1 · compile 路由落命令表，同键同载荷复用原结果', function () {
    $versionId = 980000 + random_int(1, 9000);
    b1RouteSource($versionId);

    $fake = b1RouteFake();
    app()->instance(SkillBuildService::class, $fake);
    app()->forgetInstance(BuildCommandService::class);

    $body = [
        'manifest' => [
            'kind'    => 'composite',
            'runtime' => ['source_format' => 'btcpp/xml4'],
        ],
        'target_models'  => ['unitree_g1'],
        'idempotency_key' => 'route-idem-1',
    ];

    b1RoutePost($versionId, $body)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'success');

    $command = SkillBuildCommand::where('idempotency_key', 'route-idem-1:unitree_g1')->firstOrFail();

    expect($command->status)->toBe(SkillBuildCommand::STATUS_COMPLETED)
        ->and($fake->calls)->toBe(1);

    // 同键同载荷：不重复执行，返回同一命令
    b1RoutePost($versionId, $body)
        ->assertOk()
        ->assertJsonPath('data.0.status', 'success');

    expect(SkillBuildCommand::where('idempotency_key', 'route-idem-1:unitree_g1')->count())->toBe(1)
        ->and($fake->calls)->toBe(1);
});

it('B1 · compile 路由同键异载荷 → 409 BUILD_COMMAND_CONFLICT', function () {
    $versionId = 980000 + random_int(1, 9000);
    b1RouteSource($versionId);

    $fake = b1RouteFake();
    app()->instance(SkillBuildService::class, $fake);
    app()->forgetInstance(BuildCommandService::class);

    b1RoutePost($versionId, [
        'manifest' => [
            'kind'    => 'composite',
            'runtime' => ['source_format' => 'btcpp/xml4'],
        ],
        'target_models'  => ['unitree_g1'],
        'idempotency_key' => 'route-idem-conflict',
    ])->assertOk();

    b1RoutePost($versionId, [
        'manifest' => [
            'kind'    => 'motion',
            'runtime' => ['source_format' => 'btcpp/xml4'],
        ],
        'target_models'  => ['unitree_g1'],
        'idempotency_key' => 'route-idem-conflict',
    ])->assertStatus(409)->assertJsonPath('code', 'BUILD_COMMAND_CONFLICT');
});
