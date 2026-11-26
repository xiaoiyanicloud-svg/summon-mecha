<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Builders\MotionCarrierBuilder;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;

/**
 * B6 —— 动作类构建登记 fail-closed。
 *
 * 背景：动作重定向算法在平台外，平台只登记"该技能需要为哪些机型重定向"。
 * 但**登记会被下游工具当输入消费** —— 脏参数（乱格式、0 fps、穿越路径、非 GA 机型）
 * 一旦进了待办队列，就等于让下游照着脏参数干活。所以登记也必须 fail-closed。
 *
 * 诚实边界不变：合法登记仍然是 `pending`（平台侧没有生产者），产物恒为 NULL。
 */

function b6Motion(array $overrides = []): array
{
    return array_replace_recursive([
        'schema'  => 'zhaohuan.skill/v2',
        'kind'    => 'motion',
        'name'    => ['zh_CN' => 'B6 动作用例'],
        'version' => '1.0.0',
        'motion'  => [
            'format' => 'bvh',
            'fps'    => 30,
            'file'   => 'payload/motion.bvh',
        ],
        'safety'  => ['max_duration_s' => 60],
    ], $overrides);
}

function b6Version(): SkillVersion
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'b6-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_DRAFT,
        'license_mode' => 'per_device',
    ]);
}

function b6Build(SkillVersion $version, array $manifest, string $model = 'unitree_g1')
{
    return app(MotionCarrierBuilder::class)->build($version->id, $manifest, $model);
}

// ==================== ① 合法登记仍然是 pending（D-9 口径不变） ====================

it('B6-01 合法 motion 登记 → pending，产物为 NULL，携带 motion_digest 与 profile 摘要', function () {
    $version = b6Version();
    $result = b6Build($version, b6Motion());

    expect($result->status)->toBe('pending')
        ->and($result->artifactAssetId)->toBeNull()
        ->and($result->carrierType)->toBe('motion_data')
        ->and($result->retargetConf['motion_digest'])->toStartWith('sha256:')
        ->and($result->retargetConf['target_profile_digest'])->toStartWith('sha256:')
        ->and($result->retargetConf['fps'])->toBe(30)
        ->and($result->retargetConf['source_format'])->toBe('bvh');
});

it('B6-02 同样输入两次登记 → motion_digest 完全相同（登记身份可复现）', function () {
    $a = b6Build(b6Version(), b6Motion());
    $b = b6Build(b6Version(), b6Motion());

    expect($a->retargetConf['motion_digest'])->toBe($b->retargetConf['motion_digest']);
});

// ==================== ② 格式白名单 ====================

it('B6-03 格式不在白名单（exe）→ failed BUILD_MOTION_FORMAT_UNSUPPORTED', function () {
    $result = b6Build(b6Version(), b6Motion(['motion' => ['format' => 'exe']]));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_FORMAT_UNSUPPORTED);
});

it('B6-04 缺少 motion 段 → failed BUILD_MOTION_MANIFEST_INVALID', function () {
    $manifest = b6Motion();
    unset($manifest['motion']);

    $result = b6Build(b6Version(), $manifest);

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_MANIFEST_INVALID);
});

// ==================== ③ fps ====================

it('B6-05 fps=0 / 负数 / 字符串 / 超上限 → 一律 failed BUILD_MOTION_FPS_INVALID', function () {
    foreach ([0, -30, 'abc', 100000] as $fps) {
        $result = b6Build(b6Version(), b6Motion(['motion' => ['fps' => $fps]]));

        expect($result->status)->toBe('failed')
            ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_FPS_INVALID);
    }
});

// ==================== ④ 文件路径 ====================

it('B6-06 motion.file 路径穿越 / 绝对路径 / 外部 URL → failed BUILD_MOTION_FILE_INVALID', function () {
    foreach (['../../etc/passwd.bvh', '/abs/motion.bvh', 'C:/m.bvh', 'https://evil.com/m.bvh'] as $file) {
        $result = b6Build(b6Version(), b6Motion(['motion' => ['file' => $file]]));

        expect($result->status)->toBe('failed')
            ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_FILE_INVALID);
    }
});

it('B6-07 声明 bvh 实际给 .py 文件 → failed（禁止声明一套、实际另一套）', function () {
    $result = b6Build(b6Version(), b6Motion(['motion' => ['file' => 'payload/run.py']]));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_FILE_INVALID);
});

// ==================== ⑤ 时长 ====================

it('B6-08 duration_s 超过清单 safety.max_duration_s → failed BUILD_MOTION_DURATION_INVALID', function () {
    $result = b6Build(b6Version(), b6Motion([
        'motion' => ['duration_s' => 999],
        'safety' => ['max_duration_s' => 60],
    ]));

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_DURATION_INVALID);
});

// ==================== ⑥ GA 闸（非 GA / 已废止机型不得进队列） ====================

it('B6-09 目标机型 agibot_a2（已废止，非 GA）→ failed BUILD_TARGET_NOT_GA', function () {
    $result = b6Build(b6Version(), b6Motion(), 'agibot_a2');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_TARGET_NOT_GA);
});

it('B6-10 目标机型拼写错误（unitree_g9）→ failed BUILD_TARGET_NOT_GA', function () {
    $result = b6Build(b6Version(), b6Motion(), 'unitree_g9');

    expect($result->status)->toBe('failed')
        ->and($result->errorCode)->toBe(MotionCarrierBuilder::ERR_TARGET_NOT_GA);
});

// ==================== ⑦ 源包溯源 ====================

it('B6-11 存在源资产时，登记必须带 source_asset_id / source_digest（可追到字节）', function () {
    $version = b6Version();
    Storage::fake(SkillAsset::disk());

    $rel = "skills/{$version->id}/source/motion.bvh";
    Storage::disk(SkillAsset::disk())->put($rel, "HIERARCHY\nROOT Hips\n");

    SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => 'file',
        'asset_role'       => SkillAsset::ROLE_SOURCE,
        'asset_key'        => 'src_b6',
        'file_path'        => $rel,
        'file_name'        => 'motion.bvh',
        'file_size'        => 22,
        'file_hash'        => hash('sha256', "HIERARCHY\nROOT Hips\n"),
        'entrypoint'       => 'motion.bvh',
        'sort_order'       => 0,
    ]);

    $result = b6Build($version, b6Motion());

    expect($result->status)->toBe('pending')
        ->and($result->retargetConf['source_asset_id'])->not->toBeNull()
        ->and($result->retargetConf['source_digest'])->toStartWith('sha256:');
});

// ==================== ⑧ 服务层：拒绝必须落库为 failed，且不产生任何资产 ====================

it('B6-12 SkillBuildService：坏 motion 记为 failed，好 motion 记为 pending，均不产出资产', function () {
    $bad = b6Version();
    $badResults = app(SkillBuildService::class)->compile($bad->id, b6Motion(['motion' => ['format' => 'exe']]), ['unitree_g1']);

    $good = b6Version();
    $goodResults = app(SkillBuildService::class)->compile($good->id, b6Motion(), ['unitree_g1']);

    expect($badResults[0]['status'])->toBe('failed')
        ->and($badResults[0]['error_code'])->toBe(MotionCarrierBuilder::ERR_FORMAT_UNSUPPORTED)
        ->and($goodResults[0]['status'])->toBe('pending');

    expect(SkillAsset::where('skill_version_id', $bad->id)->count())->toBe(0)
        ->and(SkillAsset::where('skill_version_id', $good->id)->count())->toBe(0);
});
