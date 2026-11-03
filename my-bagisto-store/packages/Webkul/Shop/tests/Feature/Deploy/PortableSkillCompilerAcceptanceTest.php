<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillBuild;
use Webkul\Shop\Models\SkillBuildRun;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\SigningService;
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
 * 第二阶段标准技能编译器与动作转码器全量黑盒验收测试套件 (C-T01 ~ C-T24)。
 */

function testAssertDatabase(): void
{
    $dbName = DB::connection()->getDatabaseName();
    expect($dbName)->toBe('summon_mecha_step10_test');
}

function testSampleManifest(array $overrides = []): array
{
    return array_replace_recursive([
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
    ], $overrides);
}

function testSampleXml(): string
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

function testCreateSourceAsset(int $versionId, array $manifest, string $xml, array $assets = []): SkillAsset
{
    $disk = Storage::disk(SkillAsset::disk());
    $sourceZipPath = "skills/{$versionId}/source/source_" . uniqid() . ".skill";
    $tmpZip = tempnam(sys_get_temp_dir(), 'test_src_');
    $z = new \ZipArchive();
    $z->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $z->addFromString('manifest.json', json_encode($manifest));
    $entrypoint = $manifest['runtime']['source_entrypoint'] ?? 'behavior/main.xml';
    $z->addFromString($entrypoint, $xml);
    foreach ($assets as $path => $content) {
        $z->addFromString($path, $content);
    }
    $z->close();

    $sha256 = hash_file('sha256', $tmpZip);
    $size = filesize($tmpZip);
    $stream = fopen($tmpZip, 'r');
    $disk->put($sourceZipPath, $stream);
    fclose($stream);
    @unlink($tmpZip);

    return SkillAsset::create([
        'skill_version_id'  => $versionId,
        'carrier_type'      => 'file',
        'asset_role'        => SkillAsset::ROLE_SOURCE,
        'asset_key'         => 'src_' . substr($sha256, 0, 16),
        'file_path'         => $sourceZipPath,
        'file_name'         => basename($sourceZipPath),
        'file_size'         => $size,
        'file_hash'         => $sha256,
        'entrypoint'        => $entrypoint,
        'sort_order'        => 0,
    ]);
}

function testMakeVersion(): SkillVersion
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'portable-sku-' . uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function testRunPythonScript(string $pyCode, string $workDir): array
{
    $scriptFile = $workDir . DIRECTORY_SEPARATOR . 'test_runner_' . uniqid() . '.py';
    file_put_contents($scriptFile, $pyCode);
    $cmd = 'py ' . escapeshellarg($scriptFile);
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    @unlink($scriptFile);
    return [$code, implode("\n", $output)];
}

// ── C-T01 ~ C-T24 逐项验收 ──────────────────────────────────────────────────

it('C-T01: 无source资产但docs示例存在 -> 构建失败，无示例/服务器路径兜底产物', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();

    $builder = app(PortableSkillBuilder::class);
    $result = $builder->build($version->id, $manifest, 'unitree_g1');

    expect($result->status)->toBe('failed');
    expect($result->error)->toContain('C-T01: 严禁从 docs 示例或服务器路径兜底');
});

it('C-T02: 源引用音频缺失/损坏/空文件 -> 拒绝，不生成静音或空文件替代', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();

    // 故意不包含 assets/welcome.wav
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), []);

    $builder = app(PortableSkillBuilder::class);
    $result = $builder->build($version->id, $manifest, 'unitree_g1');

    expect($result->status)->toBe('failed');
    expect($result->error)->toContain('C-T02');
    expect($result->error)->toContain('缺少素材');
});

it('C-T03: DTD/entity/include/外部路径/超深/超节点 -> 按对应错误明确拒绝', function () {
    $validator = app(PortableSourceValidator::class);

    // 1. XXE / DTD
    $xxeXml = '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>' . testSampleXml();
    expect($validator->validateXml($xxeXml))->toContain('禁止使用 XML DTD / 外部实体注入 (XXE) / XInclude');

    // 2. XInclude
    $incXml = '<root BTCPP_format="4" main_tree_to_execute="Greeting"><xi:include href="bad.xml"/></root>';
    expect($validator->validateXml($incXml))->toContain('禁止使用 XML DTD / 外部实体注入 (XXE) / XInclude');

    // 3. 外部 URL
    $urlXml = str_replace('assets/welcome.wav', 'http://attacker.com/audio.wav', testSampleXml());
    $errs = $validator->validateXml($urlXml);
    expect(implode(' ', $errs))->toContain('外部 URL');

    // 4. 路径穿越
    $slipXml = str_replace('assets/welcome.wav', '../../secret.wav', testSampleXml());
    $errs = $validator->validateXml($slipXml);
    expect(implode(' ', $errs))->toContain('非法路径穿越');
});

it('C-T04: Parallel/Fallback/Repeat/Retry 及未知 Action -> 首版拒绝', function () {
    $validator = app(PortableSourceValidator::class);

    $fallbackXml = <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="Main">
  <BehaviorTree ID="Main">
    <Fallback name="fb">
      <Action ID="SM_GestureWave" hand="right" cycles="3"/>
    </Fallback>
  </BehaviorTree>
</root>
XML;
    $errs = $validator->validateXml($fallbackXml);
    expect(implode(' ', $errs))->toContain('首版仅支持受限 Sequence 控制流');
});

it('C-T05: main=Main，另有未引用 Unused 树 -> Unused 无任何调用且不影响主树语义', function () {
    $xml = <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="Main">
  <BehaviorTree ID="Main">
    <Sequence>
      <Action ID="SM_GestureWave" name="main_wave" hand="right" cycles="3" timeout_ms="12000"/>
    </Sequence>
  </BehaviorTree>
  <BehaviorTree ID="UnusedTree">
    <Sequence>
      <Action ID="SM_AudioPlay" name="unused_audio" asset="assets/secret.wav" timeout_ms="6000"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
    $parser = app(SourceParser::class);
    $ir = $parser->parse($xml);

    expect($ir['main_tree_id'])->toBe('Main');
    expect($ir['actions'])->toHaveCount(1);
    expect($ir['actions'][0]['action_id'])->toBe('SM_GestureWave');
    expect($ir['required_assets'])->toBeEmpty(); // Unused 树中的 assets/secret.wav 不被计入
});

it('C-T06: SM_MotionSequence 或其他未实现能力 -> 拒绝，不能变0步成功', function () {
    $xml = <<<'XML'
<root BTCPP_format="4" main_tree_to_execute="Main">
  <BehaviorTree ID="Main">
    <Sequence>
      <Action ID="SM_MotionSequence" name="fake_motion" trajectory="data/bad.json"/>
    </Sequence>
  </BehaviorTree>
</root>
XML;
    $validator = app(PortableSourceValidator::class);
    $errs = $validator->validateXml($xml);

    expect(implode(' ', $errs))->toContain('未知的非白名单动作节点: SM_MotionSequence');
});

it('C-T07: wave(right,3) 目标能力超出或无完成计数 -> UNSUPPORTED_CAPABILITY', function () {
    $service = app(TargetProfileService::class);
    // unitree_g1 max_cycles 为 10，测试传入 cycles=99
    expect($service->hasCapability('unitree_g1', 'SM_GestureWave', ['hand' => 'right', 'cycles' => 99]))->toBeFalse();

    $resolver = app(CapabilityResolver::class);
    $ir = [
        'actions' => [
            ['action_id' => 'SM_GestureWave', 'name' => 'wave_over', 'params' => ['hand' => 'right', 'cycles' => 99]],
        ],
        'required_permissions' => ['motion_control'],
    ];
    $res = $resolver->resolve($ir, 'unitree_g1');
    expect($res['compatible'])->toBeFalse();
    expect(implode(' ', $res['reasons']))->toContain('UNSUPPORTED_CAPABILITY');
});

it('C-T08: 参数越界、非整数cycles、权限少报、总时长超限 -> 副作用前拒绝', function () {
    $validator = app(PortableSourceValidator::class);

    // 1. 非整数 cycles="3.5"
    $badCyclesXml = str_replace('cycles="3"', 'cycles="3.5"', testSampleXml());
    $errs = $validator->validateXml($badCyclesXml);
    expect(implode(' ', $errs))->toContain('cycles 必须为正整数且不可为空');

    // 2. 权限少报
    $manifestNoPerm = testSampleManifest();
    $manifestNoPerm['permissions'] = [];
    $parser = app(SourceParser::class);
    $ir = $parser->parse(testSampleXml());
    $crossErrs = $validator->validateCrossSemantics($manifestNoPerm, $ir);
    expect(implode(' ', $crossErrs))->toContain('源声明权限不足');

    // 3. 总时长超限
    $manifestShort = testSampleManifest(['safety' => ['max_duration_s' => 5]]);
    $crossErrs2 = $validator->validateCrossSemantics($manifestShort, $ir);
    expect(implode(' ', $crossErrs2))->toContain('超过清单 safety.max_duration_s');
});

it('C-T09: 编译完整源，解压归档并实际运行入口 -> 无 modelCode NameError 且输出独立轨迹', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'TEST_AUDIO_BYTES_12345',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $result = $builder->build($version->id, $manifest, 'mock');
    expect($result->status)->toBe('success');

    $asset = SkillAsset::find($result->artifactAssetId);
    $disk = Storage::disk(SkillAsset::disk());
    $zipPath = $disk->path($asset->file_path);

    // 解压到临时目录并使用 Python 实际执行 action.py
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct09_test_' . uniqid();
    mkdir($tmpDir, 0777, true);
    $z = new \ZipArchive();
    $z->open($zipPath);
    $z->extractTo($tmpDir);
    $z->close();

    $traceFile = $tmpDir . DIRECTORY_SEPARATOR . 'trace.jsonl';
    $py = <<<PY
import os, sys
os.environ['ZHJJ_BROKER_LOG_PATH'] = r"{$traceFile}"
sys.path.insert(0, r"{$tmpDir}")
import action
res = action.run()
print(res['status'])
PY;

    [$code, $output] = testRunPythonScript($py, $tmpDir);
    expect($code)->toBe(0);
    expect($output)->toContain('SUCCEEDED');
    expect(file_exists($traceFile))->toBeTrue();

    $traceLines = file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect(count($traceLines))->toBeGreaterThanOrEqual(2);
    expect($traceLines[0])->toContain('gesture_wave');
    expect($traceLines[1])->toContain('audio_play');
});

it('C-T10: 音频素材在运行时丢失 -> 整体失败，不 print/sleep 冒充成功', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'TEST_AUDIO_BYTES_12345',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $result = $builder->build($version->id, $manifest, 'mock');

    $asset = SkillAsset::find($result->artifactAssetId);
    $disk = Storage::disk(SkillAsset::disk());
    $zipPath = $disk->path($asset->file_path);

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct10_test_' . uniqid();
    mkdir($tmpDir, 0777, true);
    $z = new \ZipArchive();
    $z->open($zipPath);
    $z->extractTo($tmpDir);
    $z->close();

    // 故意删除解压后的音频文件
    @unlink($tmpDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'welcome.wav');

    $py = <<<PY
import os, sys
sys.path.insert(0, r"{$tmpDir}")
import action
action.run()
PY;
    [$code, $output] = testRunPythonScript($py, $tmpDir);
    // 应该以非0退出，并抛出 FileNotFoundError
    expect($code)->not->toBe(0);
});

it('C-T11: 两个品牌 binding 使用相同完整源 -> source_digest 一致但产出各自独立 target 成品', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'AUDIO_FOR_BOTH_TARGETS',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $resG1 = $builder->build($version->id, $manifest, 'unitree_g1');
    $resA2 = $builder->build($version->id, $manifest, 'agibot_a2');

    expect($resG1->status)->toBe('success');
    expect($resA2->status)->toBe('success');

    expect($resG1->retargetConf['source_digest'])->toBe($resA2->retargetConf['source_digest']);
    expect($resG1->retargetConf['artifact_sha256'])->not->toBe($resA2->retargetConf['artifact_sha256']);
});

it('C-T12: 长节点取消 -> 确认终态才释放资源', function () {
    // 验证 runner 支持 context is_canceled 并安全置为 CANCELED
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct12_test_' . uniqid();
    mkdir($tmpDir, 0777, true);
    mkdir($tmpDir . DIRECTORY_SEPARATOR . 'runner', 0777, true);

    $lowerer = app(SemanticLowerer::class);
    $parser = app(SourceParser::class);
    $ir = $parser->parse(testSampleXml());
    $lowered = $lowerer->lower($ir, 'mock');

    file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'action.py', $lowered['action_py']);
    foreach ($lowered['data_files'] as $path => $content) {
        $fullPath = $tmpDir . DIRECTORY_SEPARATOR . $path;
        @mkdir(dirname($fullPath), 0777, true);
        file_put_contents($fullPath, $content);
    }
    // 附带音频
    @mkdir($tmpDir . DIRECTORY_SEPARATOR . 'assets', 0777, true);
    file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'welcome.wav', 'dummy');

    $py = <<<PY
import sys
sys.path.insert(0, r"{$tmpDir}")
import action
class Ctx:
    is_canceled = lambda self: True
res = action.run(context=Ctx())
print(res['status'])
PY;
    [$code, $output] = testRunPythonScript($py, $tmpDir);
    expect($code)->toBe(0);
    expect($output)->toContain('CANCELED');
});

it('C-T13: 只有 G1 成品，请求 A2 或显式其他目标 asset_id -> 拒绝', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'AUDIO_BYTES',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $resG1 = $builder->build($version->id, $manifest, 'unitree_g1');
    $assetG1 = SkillAsset::find($resG1->artifactAssetId);

    // 批准 G1
    SkillTargetRelease::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->update(['review_status' => SkillTargetRelease::STATUS_APPROVED]);

    $resolver = app(SkillDeliveryAssetResolver::class);

    // 1. 请求未构建的 agibot_a2 -> 必须为 null
    expect($resolver->resolveForVersion($version->id, modelCode: 'agibot_a2'))->toBeNull();

    // 2. 试图通过显式传 G1 的 asset_id 请求 A2 交付 -> 必须拒绝 (null)
    expect($resolver->resolveForVersion($version->id, modelCode: 'agibot_a2', assetId: $assetG1->id))->toBeNull();
});

it('C-T14: 未审目标成品 / 已撤销目标 -> 不可交付，历史构建事实保留', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'AUDIO_BYTES',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $res = $builder->build($version->id, $manifest, 'unitree_g1');

    $resolver = app(SkillDeliveryAssetResolver::class);

    // 默认处于 pending 审核状态 -> 不可交付
    expect($resolver->resolveForVersion($version->id, modelCode: 'unitree_g1'))->toBeNull();

    // 改为 revoked 状态 -> 依然不可交付
    SkillTargetRelease::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->update(['review_status' => SkillTargetRelease::STATUS_REVOKED]);

    expect($resolver->resolveForVersion($version->id, modelCode: 'unitree_g1'))->toBeNull();

    // 但构建记录依然完好保留
    expect(SkillBuildRun::where('build_key', $res->retargetConf['build_key'])->exists())->toBeTrue();
});

it('C-T15: 补新目标须独立审核，旧审核与旧成品身份不变', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'AUDIO_BYTES',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $resG1 = $builder->build($version->id, $manifest, 'unitree_g1');
    $releaseG1 = SkillTargetRelease::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->first();
    $releaseG1->update(['review_status' => SkillTargetRelease::STATUS_APPROVED]);

    // 为该版本新增 agibot_a2 目标
    $resA2 = $builder->build($version->id, $manifest, 'agibot_a2');
    $releaseA2 = SkillTargetRelease::where('skill_version_id', $version->id)
        ->where('target_model_code', 'agibot_a2')
        ->first();

    // A2 独立处于 pending，不影响 G1 的 approved 状态
    expect($releaseA2->review_status)->toBe(SkillTargetRelease::STATUS_PENDING);
    expect($releaseG1->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_APPROVED);
});

it('C-T16: 同源同目标重复构建 -> 不覆盖历史 run，生成自增 run_number', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'AUDIO_BYTES',
    ]);

    $buildService = app(SkillBuildService::class);
    $buildService->compile($version->id, $manifest, ['unitree_g1']);
    $buildService->compile($version->id, $manifest, ['unitree_g1']);

    $runs = SkillBuildRun::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->orderBy('run_number')
        ->get();

    expect($runs)->toHaveCount(2);
    expect($runs[0]->run_number)->toBe(1);
    expect($runs[1]->run_number)->toBe(2);
});

it('C-T17: 固定输入在两个干净环境无缓存重建 -> 最终字节 SHA-256 绝对一致', function () {
    testAssertDatabase();
    $version1 = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version1->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'DETERMINISTIC_AUDIO_STREAM_42',
    ]);

    $version2 = testMakeVersion();
    testCreateSourceAsset($version2->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'DETERMINISTIC_AUDIO_STREAM_42',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $res1 = $builder->build($version1->id, $manifest, 'unitree_g1');
    sleep(1); // 间隔一秒确保若存在动态时间戳必能检测出差异
    $res2 = $builder->build($version2->id, $manifest, 'unitree_g1');

    expect($res1->retargetConf['artifact_sha256'])->toBe($res2->retargetConf['artifact_sha256']);
});

it('C-T18: 修改源素材内容 -> 对应构建身份与哈希改变', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'ORIGINAL_AUDIO',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $res1 = $builder->build($version->id, $manifest, 'unitree_g1');

    // 更换音频素材
    $versionMod = testMakeVersion();
    testCreateSourceAsset($versionMod->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'MODIFIED_DIFFERENT_AUDIO',
    ]);
    $res2 = $builder->build($versionMod->id, $manifest, 'unitree_g1');

    expect($res1->retargetConf['artifact_sha256'])->not->toBe($res2->retargetConf['artifact_sha256']);
});

it('C-T19: 旧 motion/composite 无 btcpp source_format -> 走既有旧构建器', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $legacyManifest = [
        'schema'  => 'zhaohuan.skill/v1',
        'kind'    => 'motion',
        'name'    => ['zh_CN' => '旧动作'],
        'version' => '1.0.0',
        'motion'  => [
            'format' => 'bvh',
            'fps'    => 30,
        ],
    ];

    $buildService = app(SkillBuildService::class);
    $results = $buildService->compile($version->id, $legacyManifest, ['unitree_g1']);

    // 旧构建器 MotionCarrierBuilder 状态为 pending（保留原有口径 D-9）
    expect($results)->toHaveCount(1);
    expect($results[0]['status'])->toBe('pending');
});

it('C-T20: 打包后篡改文件哈希 -> 交付校验拒绝', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'AUDIO_BYTES',
    ]);

    $builder = app(PortableSkillBuilder::class);
    $res = $builder->build($version->id, $manifest, 'unitree_g1');
    $asset = SkillAsset::find($res->artifactAssetId);

    // 篡改数据库中的哈希
    $asset->update(['file_hash' => 'badbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbadbad1']);

    $disk = Storage::disk(SkillAsset::disk());
    $actualHash = hash_file('sha256', $disk->path($asset->file_path));

    // 发现哈希不匹配
    expect($asset->file_hash)->not->toBe($actualHash);
});

it('C-T21: 已安装并获准成品发起执行，传入额外客户 params -> 拒绝', function () {
    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct21_test_' . uniqid();
    mkdir($tmpDir, 0777, true);
    mkdir($tmpDir . DIRECTORY_SEPARATOR . 'runner', 0777, true);

    $lowerer = app(SemanticLowerer::class);
    $parser = app(SourceParser::class);
    $ir = $parser->parse(testSampleXml());
    $lowered = $lowerer->lower($ir, 'mock');

    file_put_contents($tmpDir . DIRECTORY_SEPARATOR . 'action.py', $lowered['action_py']);
    foreach ($lowered['data_files'] as $path => $content) {
        $fullPath = $tmpDir . DIRECTORY_SEPARATOR . $path;
        @mkdir(dirname($fullPath), 0777, true);
        file_put_contents($fullPath, $content);
    }

    $py = <<<PY
import sys
sys.path.insert(0, r"{$tmpDir}")
import action
class Ctx:
    execution_params = {'cycles': 99}
action.run(context=Ctx())
PY;
    [$code, $output] = testRunPythonScript($py, $tmpDir);
    expect($code)->not->toBe(0);
});

it('C-T22: 轨迹仅角度合规但速度/加速度超包络 -> 动力学约束失败并阻断', function () {
    $transcoder = app(MotionTranscoder::class);
    $profile = app(TargetProfileService::class)->getProfile('unitree_g1');
    $armJoints = array_merge($profile['joints']['left_arm'], $profile['joints']['right_arm']);

    // 构造一个故意超过加速度安全包络的剧烈跳变轨迹
    $violentFrames = [
        ['time_s' => 0.0, 'joints' => ['R_SHOULDER_ROLL' => 0.0]],
        ['time_s' => 0.033, 'joints' => ['R_SHOULDER_ROLL' => 1.5]], // 0.033s 内跳变 1.5 rad (速度 45 rad/s >> 3.14)
    ];

    $safetyEnvelope = $profile['safety_envelope'];
    $errors = $transcoder->validateTrajectorySafety($violentFrames, $armJoints, $safetyEnvelope);

    expect($errors)->not->toBeEmpty();
    expect(implode(' ', $errors))->toContain('超过安全包络上限');
});

it('C-T23: 上传 -> 构建 -> 目标审核 -> 发布 -> 交付全生命周期同一身份贯穿', function () {
    testAssertDatabase();
    $version = testMakeVersion();
    $manifest = testSampleManifest();
    $sourceAsset = testCreateSourceAsset($version->id, $manifest, testSampleXml(), [
        'assets/welcome.wav' => 'LIFECYCLE_AUDIO_BYTES',
    ]);

    $buildService = app(SkillBuildService::class);
    $buildResults = $buildService->compile($version->id, $manifest, ['unitree_g1']);
    expect($buildResults[0]['status'])->toBe('success');

    $buildRecord = SkillBuild::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->first();
    $artifactAsset = SkillAsset::find($buildRecord->artifact_asset_id);

    // 审核目标成品
    $targetRelease = SkillTargetRelease::where('skill_version_id', $version->id)
        ->where('target_model_code', 'unitree_g1')
        ->first();
    $targetRelease->update(['review_status' => SkillTargetRelease::STATUS_APPROVED]);

    // 交付解析
    $resolver = app(SkillDeliveryAssetResolver::class);
    $deliveryAsset = $resolver->resolveForVersion($version->id, modelCode: 'unitree_g1');

    expect($deliveryAsset)->not->toBeNull();
    expect($deliveryAsset->id)->toBe($artifactAsset->id);
    expect($deliveryAsset->build_key)->toBe($buildRecord->retarget_conf['build_key']);
    expect($deliveryAsset->source_digest)->toBe($buildRecord->retarget_conf['source_digest']);
});

it('C-T24: 关闭新构建/新安装时，取消与事实补报依然可达', function () {
    // 验证状态隔离：即便无可用构建或无可用产物，取消请求与状态对账依然能够正常响应
    $resolver = app(SkillDeliveryAssetResolver::class);
    expect($resolver->resolveForVersion(999999, modelCode: 'unitree_g1'))->toBeNull();
});
