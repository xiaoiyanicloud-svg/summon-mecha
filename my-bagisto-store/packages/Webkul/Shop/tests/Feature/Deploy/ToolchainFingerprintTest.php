<?php

use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Services\SkillCompile\BuildResult;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;
use Webkul\Shop\Services\SkillCompile\ToolchainFingerprint;

/**
 * B5.5：每次构建 Run 记录工具链版本与镜像/二进制摘要。
 *
 * 关键的诚实约束：没有独立构建镜像时，`container_image_digest` 必须是 null、
 * 状态必须是 NOT_RUN —— 不能用一个假 digest 让"可复现"看起来已经成立。
 */

it('B5-5 · 工具链指纹包含版本与构建器清单，镜像摘要明确 NOT_RUN', function () {
    $fingerprint = ToolchainFingerprint::current();

    expect($fingerprint['php_version'])->toBe(PHP_VERSION)
        ->and($fingerprint['php_sapi'])->toBe(PHP_SAPI)
        ->and($fingerprint['os_family'])->toBe(PHP_OS_FAMILY)
        ->and($fingerprint['builders'])->toBeArray()
        ->and($fingerprint['container_image_digest'])->toBeNull()
        ->and($fingerprint['container_image_status'])->toBe('NOT_RUN')
        ->and($fingerprint['recorded_at'])->toBeString();
});

it('B5-5 · 构建 Run 落库时带上工具链指纹（可直接从历史 Run 追溯到工具链）', function () {
    $service = new class extends SkillBuildService {
        public function persistForTest(int $versionId, string $modelCode, BuildResult $result): SkillBuildRun
        {
            $this->persist($versionId, $modelCode, $result);

            return SkillBuildRun::query()->orderByDesc('id')->firstOrFail();
        }
    };

    $result = BuildResult::success(
        carrierType: 'file',
        log: 'toolchain test',
        buildKey: 'bk_toolchain_'.uniqid(),
        runNumber: null,
    );

    $run = $service->persistForTest(990001, 'unitree_g1', $result);

    expect($run->toolchain_json)->toBeArray()
        ->and($run->toolchain_json['php_version'])->toBe(PHP_VERSION)
        ->and($run->toolchain_json['container_image_status'])->toBe('NOT_RUN')
        ->and($run->toolchain_json['container_image_digest'])->toBeNull();
});

// ==================== T5 · 独立构建镜像摘要（第十批） ====================

it('B5-5 · 未注入镜像摘要 → image_status=NOT_RUN 且 digest 为 null（不伪造）', function () {
    $saved = [getenv('SKILL_BUILD_IMAGE_DIGEST'), getenv('SKILL_BUILD_IMAGE_REF')];
    putenv('SKILL_BUILD_IMAGE_DIGEST');
    putenv('SKILL_BUILD_IMAGE_REF');

    try {
        $fingerprint = ToolchainFingerprint::current();

        expect($fingerprint['image_ref'])->toBeNull()
            ->and($fingerprint['image_digest'])->toBeNull()
            ->and($fingerprint['image_status'])->toBe(ToolchainFingerprint::STATUS_NOT_RUN)
            // 第八批键名保持同值
            ->and($fingerprint['container_image_digest'])->toBeNull()
            ->and($fingerprint['container_image_status'])->toBe('NOT_RUN');
    } finally {
        foreach (['SKILL_BUILD_IMAGE_DIGEST', 'SKILL_BUILD_IMAGE_REF'] as $index => $name) {
            $value = $saved[$index];
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
    }
});

it('B5-5 · 注入合法 sha256 摘要 → PROBED 并随 Run 落进 toolchain_json', function () {
    $digest = 'sha256:'.str_repeat('a1', 32); // 64 位十六进制
    $saved = [getenv('SKILL_BUILD_IMAGE_DIGEST'), getenv('SKILL_BUILD_IMAGE_REF')];

    putenv("SKILL_BUILD_IMAGE_DIGEST={$digest}");
    putenv('SKILL_BUILD_IMAGE_REF=summon-mecha/skill-build:local');

    try {
        $fingerprint = ToolchainFingerprint::current();

        expect($fingerprint['image_digest'])->toBe($digest)
            ->and($fingerprint['image_status'])->toBe(ToolchainFingerprint::STATUS_PROBED)
            ->and($fingerprint['image_ref'])->toBe('summon-mecha/skill-build:local')
            ->and($fingerprint['container_image_digest'])->toBe($digest)
            ->and($fingerprint['container_image_status'])->toBe('PROBED');

        $service = new class extends SkillBuildService {
            public function persistForTest(int $versionId, string $modelCode, BuildResult $result): SkillBuildRun
            {
                $this->persist($versionId, $modelCode, $result);

                return SkillBuildRun::query()->orderByDesc('id')->firstOrFail();
            }
        };

        $run = $service->persistForTest(990002, 'unitree_g1', BuildResult::success(
            carrierType: 'file',
            log: 'image digest test',
            buildKey: 'bk_image_'.uniqid(),
            runNumber: null,
        ));

        expect($run->toolchain_json['image_status'])->toBe('PROBED')
            ->and($run->toolchain_json['image_digest'])->toBe($digest);
    } finally {
        foreach (['SKILL_BUILD_IMAGE_DIGEST', 'SKILL_BUILD_IMAGE_REF'] as $index => $name) {
            $value = $saved[$index];
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
    }
});

it('B5-5 · 格式不对的注入值 → INVALID_DIGEST，绝不把非摘要字符串当 digest', function () {
    $saved = getenv('SKILL_BUILD_IMAGE_DIGEST');

    putenv('SKILL_BUILD_IMAGE_DIGEST=latest');

    try {
        $fingerprint = ToolchainFingerprint::current();

        expect($fingerprint['image_digest'])->toBeNull()
            ->and($fingerprint['image_status'])->toBe(ToolchainFingerprint::STATUS_INVALID_DIGEST)
            ->and($fingerprint['container_image_digest'])->toBeNull();
    } finally {
        $saved === false
            ? putenv('SKILL_BUILD_IMAGE_DIGEST')
            : putenv("SKILL_BUILD_IMAGE_DIGEST={$saved}");
    }
});

