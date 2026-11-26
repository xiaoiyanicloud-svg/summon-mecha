<?php

use Webkul\Shop\Services\SkillCompile\RegistrationCommand;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;

/**
 * B1.2：登记型构建的强类型入口。
 *
 * 三类校验必须在**进入 builder 之前**完成：目标机型闭集、manifest 摘要一致、版本存在。
 * 这些负例都不需要真实构建，正好用来证明"入口不再裸调 compile()"。
 */

it('B1-2 · 登记型构建拒绝非 GA 机型（BUILD_TARGET_NOT_GA），且不落任何构建', function () {
    $manifest = ['kind' => 'motion', 'motion' => ['format' => 'bvh', 'fps' => 30]];
    $command = RegistrationCommand::fromManifest(1, $manifest, ['agibot_a2']);

    $results = app(SkillBuildService::class)->registerWithCommand($command, $manifest);

    expect($results)->toHaveCount(1)
        ->and($results[0]['model'])->toBe('agibot_a2')
        ->and($results[0]['status'])->toBe('failed')
        ->and($results[0]['error_code'])->toBe('BUILD_TARGET_NOT_GA');
});

it('B1-2 · manifest 摘要与命令不一致 → BUILD_MANIFEST_DIGEST_MISMATCH', function () {
    $signedManifest = ['kind' => 'motion', 'motion' => ['format' => 'bvh', 'fps' => 30]];
    $actualManifest = ['kind' => 'motion', 'motion' => ['format' => 'bvh', 'fps' => 60]];

    $command = RegistrationCommand::fromManifest(1, $signedManifest, ['unitree_g1']);

    $results = app(SkillBuildService::class)->registerWithCommand($command, $actualManifest);

    expect($results[0]['status'])->toBe('failed')
        ->and($results[0]['error_code'])->toBe('BUILD_MANIFEST_DIGEST_MISMATCH');
});

it('B1-2 · 无匹配构建器 → BUILD_BUILDER_NOT_FOUND（不静默登记）', function () {
    $manifest = ['kind' => 'kind_without_builder'];
    $command = RegistrationCommand::fromManifest(1, $manifest, ['unitree_g1']);

    $results = app(SkillBuildService::class)->registerWithCommand($command, $manifest);

    expect($results[0]['status'])->toBe('failed')
        ->and($results[0]['error_code'])->toBe('BUILD_BUILDER_NOT_FOUND');
});

it('B1-2 · 命令的稳定键只由版本/摘要/目标构成，不含自由文本', function () {
    $command = RegistrationCommand::fromManifest(7, ['kind' => 'motion'], ['unitree_g1', 'agibot_x2']);

    expect($command->scopedKey())->toBe('register:7:'.$command->manifestDigest.':unitree_g1,agibot_x2')
        ->and($command->manifestDigest)->toStartWith('sha256:');
});
