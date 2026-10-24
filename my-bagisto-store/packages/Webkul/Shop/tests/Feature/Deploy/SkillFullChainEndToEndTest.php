<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\InstallRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillEntitlement;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillPackage\PackageInspector;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * 技能供需全链路端到端闭环验证（开发者上传/自动封装 → 机审/人审/上架 → 买家下单/支付守卫/下载 → 设备 Agent 验签/落盘解压/全生命周期运维）。
 */
beforeEach(function () {
    Storage::fake(SkillAsset::disk());
    config([
        'skill_delivery.upload_key'       => 'e2e-internal-key',
        'deploy_gateways.agent_key'       => 'e2e-agent-shared-key',
        'deploy_gateways.agent_auth_mode' => 'auto',
    ]);
});

it('PackageInspector：app/composite 技能允许合规 .py/.sh 脚本，但拦截高危破坏指令与 motion 脚本夹带', function () {
    $inspector = app(PackageInspector::class);

    // 1) kind=app 携带正常 main.py → 通过检查
    $appZipPath = tempnam(sys_get_temp_dir(), 'e2e_app_').'.skill';
    $zip = new ZipArchive;
    $zip->open($appZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'app',
        'name'              => ['zh_CN' => '应用脚本技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['agent', 'manual'],
        'safety'            => ['risk_level' => 'low'],
        'entrypoint'        => 'payload/main.py',
    ], JSON_UNESCAPED_UNICODE));
    $zip->addFromString('payload/main.py', "print('hello robot')\n");
    $zip->close();

    $resApp = $inspector->inspect($appZipPath);
    @unlink($appZipPath);

    expect($resApp['ok'])->toBeTrue()
        ->and($resApp['errors'])->toBeEmpty();

    // 2) kind=app 携带含 rm -rf / 的恶意脚本 → 拦截
    $evilZipPath = tempnam(sys_get_temp_dir(), 'e2e_evil_').'.skill';
    $zip2 = new ZipArchive;
    $zip2->open($evilZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip2->addFromString('manifest.json', json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'app',
        'name'              => ['zh_CN' => '恶意脚本技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['agent'],
        'safety'            => ['risk_level' => 'low'],
    ], JSON_UNESCAPED_UNICODE));
    $zip2->addFromString('payload/install.sh', "#!/bin/sh\nrm -rf / *\n");
    $zip2->close();

    $resEvil = $inspector->inspect($evilZipPath);
    @unlink($evilZipPath);

    expect($resEvil['ok'])->toBeFalse()
        ->and(implode(' ', $resEvil['errors']))->toContain('高危系统破坏指令');

    // 3) kind=motion 夹带 .py 脚本 → 依然严格拦截
    $motionScriptZip = tempnam(sys_get_temp_dir(), 'e2e_motion_').'.skill';
    $zip3 = new ZipArchive;
    $zip3->open($motionScriptZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip3->addFromString('manifest.json', json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '动作夹带脚本'],
        'version'           => '1.0.0',
        'delivery_channels' => ['agent'],
        'safety'            => ['risk_level' => 'low'],
    ], JSON_UNESCAPED_UNICODE));
    $zip3->addFromString('sneaky.py', "print('sneaky')\n");
    $zip3->close();

    $resMotion = $inspector->inspect($motionScriptZip);
    @unlink($motionScriptZip);

    expect($resMotion['ok'])->toBeFalse()
        ->and(implode(' ', $resMotion['errors']))->toContain('禁止的可执行/脚本文件');
});

it('全链路闭环：开发者传原始脚本自动封装 .skill → L0/L2 审核上架 → 买家下单/未付拦截/模拟支付/下载 → Agent 验签下载落盘解压与启停卸载', function () {
    // ==================== 1. 开发者入驻、实名、建技能、自动封装上传、提审 ====================
    $devCustomer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Master',
        'email'       => 'dev_e2e_'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($devCustomer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal'])->assertRedirect();
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ])->assertRedirect();

    $profile = DeveloperProfile::where('customer_id', $devCustomer->id)->firstOrFail();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    $skillName = '迎宾挥手巡检技能_'.substr(uniqid(), -5);
    post(route('shop.dev.skills.store'), [
        'name'              => $skillName,
        'price'             => 299,
        'short_description' => '支持宇树 G1 与智元 A2 的迎宾挥手复合技能',
        'description'       => '全链路端到端验证技能商品',
    ])->assertRedirect();

    $productId = (int) DB::table('products')->where('seller_id', $profile->seller_id)->orderByDesc('id')->value('id');
    expect($productId)->toBeGreaterThan(0);

    // 开发者新建版本（带安装说明 install_guide）
    post(route('shop.dev.skills.versions.store', $productId), [
        'version'       => '1.0.0',
        'changelog'     => '首个正式版，含动作与控制脚本',
        'install_guide' => "## 手工安装说明\n1. 解压技能包\n2. 运行 `python3 payload/main.py`",
    ])->assertRedirect();

    $version = SkillVersion::where('product_id', $productId)->firstOrFail();
    expect($version->install_guide)->toContain('python3 payload/main.py');

    // 验证开发者可下载标准 .skill 模板包
    $sampleRes = get(route('shop.dev.versions.sample', ['versionId' => $version->id, 'kind' => 'motion']));
    $sampleRes->assertOk();

    // 开发者直接上传一个裸 Python 脚本 main.py（选 app_package 载体 + 开启 auto_pack）
    $rawPyFile = UploadedFile::fake()->createWithContent(
        'main.py',
        "#!/usr/bin/env python3\nimport json\nprint(json.dumps({'skill': 'wave_hello', 'version': '1.0.0'}))\n"
    );

    post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type'  => SkillAsset::CARRIER_APP_PACKAGE,
        'kind'          => 'app',
        'risk_level'    => 'low',
        'target_models' => 'unitree_g1,agibot_a2',
        'auto_pack'     => 1,
        'file'          => $rawPyFile,
    ])->assertRedirect();

    // 自动封装同时落了 app_package 与 canonical file 载体，且盘上文件是合法的 .skill ZIP
    $fileAsset = SkillAsset::where('skill_version_id', $version->id)
        ->where('carrier_type', SkillAsset::CARRIER_FILE)
        ->firstOrFail();
    expect($fileAsset->fileExists())->toBeTrue()
        ->and($fileAsset->file_name)->toEndWith('.skill');

    // 开发者提交审核 → L0 机审通过 → 状态变为 in_review
    post(route('shop.dev.versions.submit', $version->id))->assertRedirect();
    $version->refresh();
    expect($version->status)->toBe(SkillVersion::STATUS_IN_REVIEW)
        ->and($version->manifest_schema)->toBe('zhaohuan.skill/v2');

    // ==================== 2. 平台 L2 人审通过并发布上架 ====================
    app(AuditService::class)->recordLayer2($productId, $version->id, AuditRecord::RESULT_PASS, null);

    post(route('shop.api.skill-admin.versions.publish', $version->id), [], [
        'X-Internal-Key' => 'e2e-internal-key',
        'Accept'         => 'application/json',
    ])->assertOk();

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);

    // ==================== 3. 买家注册设备、Agent 配对、下单与未支付拦截 ====================
    $buyer = Customer::create([
        'first_name'  => 'Buyer',
        'last_name'   => 'RobotOwner',
        'email'       => 'buyer_e2e_'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($buyer, 'customer');

    // 买家登记设备并生成配对码
    $sn = 'G1-E2E-'.strtoupper(substr(uniqid(), -6));
    $devStoreRes = postJson(route('shop.api.devices.store'), [
        'sn'          => $sn,
        'device_name' => '我的宇树 G1',
        'model'       => 'unitree_g1',
    ])->assertStatus(201);

    $deviceId = (int) $devStoreRes->json('data.id');
    $pairRes = postJson(route('shop.api.devices.agent-token', $deviceId))->assertOk();
    $enrollToken = (string) $pairRes->json('data.token');

    // 设备侧 Agent 用一次性配对码 enroll 换取身份凭据
    $enrollRes = postJson(route('shop.api.agent.enroll'), [
        'token'            => $enrollToken,
        'model_code'       => 'unitree_g1',
        'firmware_version' => '1.2.0',
    ])->assertOk();

    $did = (string) $enrollRes->json('data.did');
    $deviceSecret = (string) $enrollRes->json('data.device_secret');
    $serverPubKey = (string) $enrollRes->json('data.server_public_key');
    $agentHeaders = [
        'X-Agent-Did'    => $did,
        'X-Agent-Secret' => $deviceSecret,
    ];

    // 买家下单购买该技能（同时传入已选 device_id）
    $orderRes = postJson('/api/tob-order', [
        'product_id'   => $productId,
        'product_name' => $skillName,
        'price'        => 299,
        'qty'          => 1,
        'type'         => 'skill',
        'device_id'    => $deviceId,
        'name'         => '张机甲',
        'phone'        => '13800138000',
        'city'         => '上海市',
        'address'      => '浦东新区张江机器人谷 1 号',
    ])->assertOk();

    $orderId = (int) $orderRes->json('order_id');
    $entitlement = SkillEntitlement::where('order_id', $orderId)->where('customer_id', $buyer->id)->firstOrFail();

    // 下单时传了 device_id，授权已自动绑定到该设备
    expect((int) $entitlement->device_id)->toBe($deviceId);

    // ── 支付前守卫验证：未支付订单既不能下载交付物，也不能发起 Agent 下发 ──
    getJson(route('shop.api.skills.download', [
        'entitlementId' => $entitlement->id,
        'assetId'       => $fileAsset->id,
    ]))->assertStatus(409)->assertJsonPath('code', 'ENTITLEMENT_UNPAID');

    postJson(route('shop.api.deploy.store'), [
        'device_id'        => $deviceId,
        'skill_id'         => $productId,
        'skill_version_id' => $version->id,
        'entitlement_id'   => $entitlement->id,
        'operation'        => DeployTask::OP_INSTALL,
    ])->assertStatus(409)->assertJsonPath('code', 'ENTITLEMENT_UNPAID');

    // ==================== 4. 买家发起支付并完成模拟支付回调 ====================
    $payRes = postJson(route('shop.api.payment.create', $orderId))->assertOk();
    $outTradeNo = (string) $payRes->json('data.out_trade_no');
    expect($outTradeNo)->toStartWith('MOCK-');

    postJson(route('shop.api.callback.payment'), [
        'out_trade_no' => $outTradeNo,
        'mock_paid'    => 1,
        'amount'       => 299.00,
    ])->assertOk();

    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('processing');

    // 支付完成后：C 端买家可正常下载 .skill 包，且字节哈希与落盘文件一致
    $dlRes = get(route('shop.api.skills.download', [
        'entitlementId' => $entitlement->id,
        'assetId'       => $fileAsset->id,
    ]))->assertOk();
    expect(hash('sha256', $dlRes->streamedContent()))->toBe($fileAsset->file_hash);

    // ==================== 5. 买家发起一键下发 → 设备 Agent 轮询、验签、下载、解压落盘 ====================
    $deployRes = postJson(route('shop.api.deploy.store'), [
        'device_id'        => $deviceId,
        'skill_id'         => $productId,
        'skill_version_id' => $version->id,
        'entitlement_id'   => $entitlement->id,
        'operation'        => DeployTask::OP_INSTALL,
    ])->assertStatus(201);

    $taskNo = (string) $deployRes->json('data.task_no');
    expect($taskNo)->not->toBeEmpty();

    // 设备侧 Agent 轮询拉取待执行指令
    $cmdRes = getJson(route('shop.api.agent.commands').'?did='.urlencode($did), $agentHeaders)->assertOk();
    $commands = $cmdRes->json('data');
    expect($commands)->toBeArray()->toHaveCount(1);

    $cmd = $commands[0];
    expect($cmd['task_id'])->toBe($taskNo)
        ->and($cmd['operation'])->toBe(DeployTask::OP_INSTALL)
        ->and($cmd['package_sha256'])->toBe($fileAsset->file_hash)
        ->and($cmd['package_url'])->not->toBeEmpty();

    // Agent 凭签名 URL + 设备身份头下载技能包字节流
    $pkgRes = get($cmd['package_url'], $agentHeaders)->assertOk();
    $pkgBytes = $pkgRes->streamedContent();
    expect(hash('sha256', $pkgBytes))->toBe($cmd['package_sha256']);

    // 用真实的 agent.py 脚本逻辑执行本地解压安装与状态登记
    $agentPyCode = get(route('shop.agent.py'))->assertOk()->getContent();
    $tmpAgentDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zhjj_agent_e2e_'.uniqid();
    mkdir($tmpAgentDir, 0777, true);
    $agentScriptPath = $tmpAgentDir.DIRECTORY_SEPARATOR.'agent.py';
    $downloadedPkgPath = $tmpAgentDir.DIRECTORY_SEPARATOR.'downloaded.skill';
    file_put_contents($agentScriptPath, $agentPyCode);
    file_put_contents($downloadedPkgPath, $pkgBytes);

    // 调用 agent.py 内的 verify + install_skill_package + apply_lifecycle_operation 验证签名、解压安装与启停卸载
    $pyRunner = $tmpAgentDir.DIRECTORY_SEPARATOR.'run_verify_and_install.py';
    file_put_contents($pyRunner, <<<'PY'
import json, sys, os
sys.path.insert(0, os.path.dirname(__file__))
import agent

cmd = json.loads(sys.argv[1])
pubkey = sys.argv[2]
pkg_path = sys.argv[3]
install_dir = sys.argv[4]

assert agent.verify(cmd, pubkey), "RSA signature verification failed"
entry = agent.install_skill_package(
    pkg_path,
    install_dir,
    cmd["skill_id"],
    cmd["version"],
    cmd["package_sha256"],
    cmd["operation"],
)
disabled_entry = agent.apply_lifecycle_operation(install_dir, cmd["skill_id"], cmd["version"], 5)
enabled_entry = agent.apply_lifecycle_operation(install_dir, cmd["skill_id"], cmd["version"], 4)
print(json.dumps({
    "ok": True,
    "entry": entry,
    "disabled_enabled": disabled_entry.get("enabled"),
    "enabled_enabled": enabled_entry.get("enabled"),
}))
PY);

    $pythonCandidates = [
        'C:\Users\Administrator\.workbuddy\binaries\python\versions\3.13.12\python.exe',
        'C:\Users\Administrator\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe',
        'python3',
        'python',
    ];
    $pythonBin = 'python';
    foreach ($pythonCandidates as $candidate) {
        if (is_file($candidate)) {
            $pythonBin = $candidate;
            break;
        }
    }

    $env = getenv();
    $gitBin = 'C:\Program Files\Git\usr\bin';
    if (is_dir($gitBin)) {
        $env['PATH'] = $gitBin.PATH_SEPARATOR.($env['PATH'] ?? $env['Path'] ?? '');
        unset($env['Path']);
    }

    $proc = proc_open(
        [$pythonBin, $pyRunner, json_encode($cmd), $serverPubKey, $downloadedPkgPath, $tmpAgentDir],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $tmpAgentDir,
        $env
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    expect($exitCode)->toBe(0, "Python agent install failed: {$stderr}")
        ->and(json_decode(trim($stdout), true))->toMatchArray([
            'ok'               => true,
            'disabled_enabled' => false,
            'enabled_enabled'  => true,
        ]);
    $pyOut = json_decode(trim($stdout), true);
    expect($pyOut['entry']['status'] ?? null)->toBe('installed')
        ->and($pyOut['entry']['enabled'] ?? null)->toBeTrue()
        ->and($pyOut['entry']['files'] ?? [])->toContain('manifest.json')
        ->and($pyOut['entry']['files'] ?? [])->toContain('payload/main.py');

    // 验证磁盘上真实解压出了 manifest.json、README.md 和 payload/main.py
    $installedSkillDir = $tmpAgentDir.DIRECTORY_SEPARATOR.'skills'.DIRECTORY_SEPARATOR.$productId.DIRECTORY_SEPARATOR.'v1.0.0';
    expect(is_file($installedSkillDir.DIRECTORY_SEPARATOR.'manifest.json'))->toBeTrue()
        ->and(is_file($installedSkillDir.DIRECTORY_SEPARATOR.'README.md'))->toBeTrue()
        ->and(is_file($installedSkillDir.DIRECTORY_SEPARATOR.'payload'.DIRECTORY_SEPARATOR.'main.py'))->toBeTrue();

    // Agent 回报 downloading → installing → done
    postJson(route('shop.api.agent.event'), ['task_no' => $taskNo, 'did' => $did, 'phase' => 'downloading'], $agentHeaders)->assertOk();
    postJson(route('shop.api.agent.event'), ['task_no' => $taskNo, 'did' => $did, 'phase' => 'installing'], $agentHeaders)->assertOk();
    postJson(route('shop.api.agent.event'), ['task_no' => $taskNo, 'did' => $did, 'phase' => 'done'], $agentHeaders)->assertOk();

    // 验证任务完成、InstallRecord 落为 installed、SkillEntitlement 自动激活为 active
    expect(DeployTask::where('task_no', $taskNo)->value('status'))->toBe('success')
        ->and(InstallRecord::where('did', $did)->where('skill_version_id', $version->id)->value('status'))->toBe('installed')
        ->and($entitlement->fresh()->status)->toBe(SkillEntitlement::STATUS_ACTIVE)
        ->and($entitlement->fresh()->activations)->toBe(1);

    // ==================== 6. 远程卸载 (OP_UNINSTALL) 闭环验证 ====================
    $uninstallRes = postJson(route('shop.api.deploy.store'), [
        'device_id'        => $deviceId,
        'skill_id'         => $productId,
        'skill_version_id' => $version->id,
        'entitlement_id'   => $entitlement->id,
        'operation'        => DeployTask::OP_UNINSTALL,
    ])->assertStatus(201);

    $uninstallTaskNo = (string) $uninstallRes->json('data.task_no');

    // Agent 执行 apply_lifecycle_operation(OP_UNINSTALL) 并无错误码上报 done
    postJson(route('shop.api.agent.event'), ['task_no' => $uninstallTaskNo, 'did' => $did, 'phase' => 'downloading'], $agentHeaders)->assertOk();
    postJson(route('shop.api.agent.event'), ['task_no' => $uninstallTaskNo, 'did' => $did, 'phase' => 'installing'], $agentHeaders)->assertOk();
    postJson(route('shop.api.agent.event'), ['task_no' => $uninstallTaskNo, 'did' => $did, 'phase' => 'done'], $agentHeaders)->assertOk();

    expect(DeployTask::where('task_no', $uninstallTaskNo)->value('status'))->toBe('success')
        ->and(InstallRecord::where('did', $did)->where('skill_version_id', $version->id)->value('status'))->toBe('uninstalled');
});
