<?php

use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\DeployTask;
use Webkul\Shop\Models\DeviceBinding;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\DeployTaskService;
use Webkul\Shop\Services\Deploy\SigningService;
use Webkul\Shop\Services\SkillPackage\PackageFingerprint;

use function Pest\Laravel\get;

/**
 * S-1 · 面向 Agent 的签名限时下载 + 真实 package_hash（最高杠杆）。
 *
 * ── 病 ──
 *   平台**从不下发技能包**：`DeployTaskService::create()` 的 `$packageUrl/$packageHash`
 *   默认 null，三个调用点（用户下发 / 试用 / 回收）一个都没传 → 设备拿到的指令里
 *   `package_url` 恒为空，参考 Agent 只能走 dry install。连带致命：`SigningService`
 *   的规范化串里 `package_hash` 取 `?? ''` —— **签名覆盖的是一个空哈希**，
 *   "防审核后换包"根本不成立。
 *
 * ── 本文件锁什么 ──
 *   1. 安装类下发任务**自动带**真实的包地址与指纹（不再为空）；
 *   2. 签名 URL 真能取到包，字节 sha256 == 指令声明的 package_sha256；
 *   3. 过期 / 篡改 / 无 Agent 凭据 —— 三条路都拿不到包；
 *   4. 落盘字节与 file_hash 不符 → 下载端点**拒绝输出**（不发出对不上的字节）；
 *   5. 非安装类操作（卸 / 回收）**不挂**包地址；无文件类交付物的版本**不发假地址**。
 *
 * ⚠ 端侧（真机 Agent）行为**未验证**——参考 Agent 模板里已写入复算逻辑，但本机无真机，
 *   待 E7。见交付说明的"未做到"一栏。
 */

function apdKey(): string
{
    return 'pest-agent-key';
}

function apdHeaders(): array
{
    return ['X-Agent-Key' => apdKey(), 'Accept' => 'application/json'];
}

function apdSkillId(): int
{
    return 970000 + random_int(1, 9999);
}

function apdBinding(bool $online = true): DeviceBinding
{
    return DeviceBinding::create([
        'did'            => 'did_apd_'.uniqid(),
        'sn'             => 'APD-'.uniqid(),
        'source'         => DeviceBinding::SOURCE_AGENT,
        'bind_status'    => DeviceBinding::BIND_STATUS_BOUND,
        'bound_at'       => now(),
        'last_online_at' => $online ? now() : now()->subHours(5),
    ]);
}

function apdVersion(?int $productId = null): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId ?? apdSkillId(),
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

/** 落一个**真实**的文件类交付物：字节写进假盘，file_hash 是它的真实 sha256。 */
function apdFile(SkillVersion $version, string $bytes = 'PKG-BYTES-v1', int $sort = 0): SkillAsset
{
    $path = "skills/apd/{$version->id}_{$sort}.skill";

    Storage::disk(SkillAsset::disk())->put($path, $bytes);

    return SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'sort_order'       => $sort,
        'file_path'        => $path,
        'file_name'        => "apd-{$sort}.skill",
        'file_size'        => strlen($bytes),
        'file_hash'        => hash('sha256', $bytes),
    ]);
}

/** 建一条安装类下发任务（走唯一漏斗 DeployTaskService::create）。 */
function apdInstallTask(DeviceBinding $binding, SkillVersion $version, int $operation = DeployTask::OP_INSTALL): DeployTask
{
    return app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: (int) $version->product_id,
        skillVersionId: $version->id,
        entitlementId: null,
        operation: $operation,
        createdBy: 'test',
    );
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
    config(['deploy_gateways.agent_key' => apdKey()]);
});

// ==================== 1 · 任务不再为空 ====================

it('S-1 · 安装类下发任务自动带上真实包地址与指纹（不再为空）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $task = apdInstallTask($binding, $version);

    expect($task->package_url)->not->toBeNull()
        ->and($task->package_url)->toContain('/api/agent/packages/'.$version->id)
        // 指纹必须**复用** D-7 那处唯一实现，不是一个另算的值
        ->and($task->package_hash)->toBe(app(PackageFingerprint::class)->forVersion($version->id))
        ->and($task->package_hash)->not->toBeNull();

    // 空话不算数：指纹真的进了签名覆盖面 —— 换包（另一份字节）会让它变。
    // 用"就地改 file_hash"模拟覆盖上传（同 (version,carrier,sort) 幂等 upsert）。
    SkillAsset::where('skill_version_id', $version->id)->update(['file_hash' => hash('sha256', 'ANOTHER-BYTES')]);

    expect(app(PackageFingerprint::class)->forVersion($version->id))->not->toBe($task->package_hash);
});

// ==================== 2 · 真能拿到包 ====================

it('S-1 · 签名 URL 拉到的字节 sha256 == 指令声明的 package_sha256', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    $asset = apdFile($version, 'REAL-PACKAGE-BYTES');

    $task = apdInstallTask($binding, $version);

    // 指令里两个哈希分工不同，且都非空：
    //   package_hash   = 集合指纹（被签名覆盖）；
    //   package_sha256 = 单文件字节 sha256（端侧复算的靶子）。
    $command = app(DeployTaskService::class)->buildCommand($task);

    expect($command['package_hash'])->toBe(app(PackageFingerprint::class)->forVersion($version->id))
        ->and($command['package_sha256'])->toBe($asset->file_hash)
        ->and($command['package_sha256'])->not->toBeNull();

    $response = get($task->package_url, apdHeaders())->assertOk();

    $bytes = $response->streamedContent();

    expect(hash('sha256', $bytes))->toBe($command['package_sha256'])
        ->and($bytes)->toBe('REAL-PACKAGE-BYTES');

    // 响应体里绝不回泄私盘路径
    expect($response->headers->get('Content-Disposition'))->not->toContain('skills/apd');
});

it('S-1 · 签名覆盖的是真实哈希：改包即让签名对不上', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $task = apdInstallTask($binding, $version);

    $signed = app(SigningService::class);

    $cmd = app(DeployTaskService::class)->buildCommand($task);

    $correct = $signed->canonical([
        'task_id'        => $cmd['task_id'],
        'skill_id'       => $cmd['skill_id'],
        'version'        => $cmd['version'],
        'package_hash'   => $cmd['package_hash'],
        'package_sha256' => $cmd['package_sha256'],
        'ts'             => $cmd['ts'],
    ]);

    expect($signed->verify($correct, $cmd['signature']))->toBeTrue();

    // ⚠ 键证：本次改动后，签名串里 package_hash 那一段**不是空串**（此前的病）
    expect($task->package_hash)->not->toBe('')
        ->and($correct)->toContain($task->package_hash)
        ->and($correct)->toContain($cmd['package_sha256']);

    // 5 段老串（无 package_sha256）对不上 —— 串的构成确实变了
    $legacy = implode('|', [
        $cmd['task_id'], $cmd['skill_id'], $cmd['version'] ?? '', $cmd['package_hash'], $cmd['ts'],
    ]);

    // 5 段老串（没有 package_sha256）对不上 —— 证明串的构成真的变了（不是原地不动）
    expect($signed->verify($legacy, $cmd['signature']))->toBeFalse();
});

// ==================== 2b · package_sha256 必须被签住（本轮追加的核心验收） ====================

it('S-1 · 改 package_sha256 即失签（端侧复算的靶子真的进了签名串）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $cmd = app(DeployTaskService::class)->buildCommand(apdInstallTask($binding, $version));

    // 前提：这条指令确实带了被签住的单文件哈希
    expect($cmd['package_sha256'])->not->toBeNull()
        ->and($cmd['package_sha256'])->not->toBe('');

    $signed = app(SigningService::class);

    // 攻击者把靶子换成自己那份恶意文件的哈希（意图让端侧复算"通过"）
    $tampered = $signed->canonical([
        'task_id'        => $cmd['task_id'],
        'skill_id'       => $cmd['skill_id'],
        'version'        => $cmd['version'],
        'package_hash'   => $cmd['package_hash'],
        'package_sha256' => str_repeat('0', 64),
        'ts'             => $cmd['ts'],
    ]);

    // 原签名对它不成立 —— 不持私钥就无法换掉端侧的比对靶子
    expect($signed->verify($tampered, $cmd['signature']))->toBeFalse();
});

it('S-1 · 无包（dry install）任务仍能验签通过（空哈希不让整条签名失效）', function () {
    $binding = apdBinding(true);
    $version = apdVersion(); // 该版本一个交付物都没有

    $task = apdInstallTask($binding, $version);

    expect($task->package_hash)->toBeNull();

    $cmd = app(DeployTaskService::class)->buildCommand($task);

    expect($cmd['package_url'])->toBeNull()
        ->and($cmd['package_sha256'])->toBeNull();

    $signed = app(SigningService::class);

    $canonical = $signed->canonical([
        'task_id'        => $cmd['task_id'],
        'skill_id'       => $cmd['skill_id'],
        'version'        => $cmd['version'],
        'package_hash'   => $cmd['package_hash'],
        'package_sha256' => $cmd['package_sha256'],
        'ts'             => $cmd['ts'],
    ]);

    // ⚠ 两个哈希皆空 → 串里是空段，但**签名照常成立、照常可验**（没有因空而失效）
    expect($signed->verify($canonical, $cmd['signature']))->toBeTrue()
        // 空段确有其事（不是被塞了占位串）
        ->and($canonical)->toContain('||');
});

// ==================== 3 · 过期 / 篡改 / 无凭据 ====================

it('S-1 · URL 过期即失效（限时）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $task = apdInstallTask($binding, $version);

    $ttl = (int) config('deploy_gateways.package_url_ttl_minutes');

    // TTL 之内可取
    get($task->package_url, apdHeaders())->assertOk();

    // 越过 TTL → 拒绝（签名里 expires 已过期）
    $this->travel($ttl + 1)->minutes();
    get($task->package_url, apdHeaders())->assertStatus(403);
    $this->travelBack();
});

it('S-1 · 篡改 versionId 或去掉签名即失效（防篡改）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $task = apdInstallTask($binding, $version);
    $url = $task->package_url;

    // 去掉签名参数 → 不是签名 URL
    get(strtok($url, '?'), apdHeaders())->assertStatus(403);

    // 保留签名、把 versionId 改掉 → 签名失配
    $tampered = preg_replace('#/packages/\d+#', '/packages/'.($version->id + 1), $url);
    expect($tampered)->not->toBe($url);
    get($tampered, apdHeaders())->assertStatus(403);
});

it('S-1 · 无 Agent 凭据 → 401（URL 泄露也拿不到包）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $task = apdInstallTask($binding, $version);

    // 签名有效，但没有 agent 凭据 → 第二重闸挡住
    get($task->package_url, ['Accept' => 'application/json'])->assertStatus(401);
});

// ==================== 4 · 自检：字节与记录不符则拒绝输出 ====================

it('S-1 · 落盘字节与 file_hash 不符 → 拒绝输出（绝不发出对不上的字节）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    $asset = apdFile($version, 'ORIGINAL-BYTES');

    $task = apdInstallTask($binding, $version);

    // 只改盘上的字节，**不动 file_hash** —— 模拟"包在审核之后被悄悄换掉"
    Storage::disk(SkillAsset::disk())->put($asset->file_path, 'TAMPERED-BYTES');

    get($task->package_url, apdHeaders())
        ->assertStatus(409)
        ->assertJsonPath('code', 'PACKAGE_INTEGRITY_MISMATCH');
});

it('S-1 · 文件整个不在盘上 → 明确报"文件缺失"（不是静默 200 空体）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    $asset = apdFile($version);

    $task = apdInstallTask($binding, $version);

    Storage::disk(SkillAsset::disk())->delete($asset->file_path);

    get($task->package_url, apdHeaders())
        ->assertStatus(404)
        ->assertJsonPath('code', 'SKILL_ASSET_FILE_MISSING');
});

// ==================== 5 · 不该带包的，绝不带 ====================

it('S-1 · 非安装类操作（卸载 / 回收）不带包地址', function () {
    $version = apdVersion();
    apdFile($version);

    $deploy = app(DeployTaskService::class);

    $uninstall = $deploy->create(
        did: apdBinding(true)->did,
        skillId: (int) $version->product_id,
        skillVersionId: $version->id,
        entitlementId: null,
        operation: DeployTask::OP_UNINSTALL,
        createdBy: 'test',
    );

    expect($uninstall->package_url)->toBeNull()
        ->and($uninstall->package_hash)->toBeNull();

    $reclaim = $deploy->create(
        did: apdBinding(true)->did,
        skillId: (int) $version->product_id,
        skillVersionId: $version->id,
        entitlementId: null,
        operation: DeployTask::OP_RECLAIM,
        createdBy: 'test',
    );

    expect($reclaim->package_url)->toBeNull()
        ->and($reclaim->package_hash)->toBeNull();
});

it('S-1 · 该版本没有文件类交付物 → 不产生可下载的假地址，且如实记录无包', function () {
    $binding = apdBinding(true);
    $version = apdVersion();

    // 只有一条非文件类交付物（云端配置）—— 按设计不落盘，没有字节可发
    SkillAsset::create([
        'skill_version_id' => $version->id,
        'carrier_type'     => SkillAsset::CARRIER_CLOUD_CONFIG,
        'sort_order'       => 0,
        'payload'          => ['endpoint' => 'https://x.example.com'],
    ]);

    expect(app(PackageFingerprint::class)->forVersion($version->id))->toBeNull();

    $task = apdInstallTask($binding, $version);

    expect($task->package_url)->toBeNull()
        ->and($task->package_hash)->toBeNull()
        // ⚠ "无包"是被**记录**下来的原因，不是"忘了填" —— 事后能一眼分辨两者
        ->and($task->error_code)->toBe(DeployTask::ERR_PKG_MISSING);

    // 指令里同样不带假地址
    $command = app(DeployTaskService::class)->buildCommand($task);
    expect($command['package_url'])->toBeNull()
        ->and($command['package_sha256'])->toBeNull();
});

// ==================== 6 · 显式覆盖仍可用（契约只加不改） ====================

it('S-1 · 显式 packageUrl/packageHash 覆盖仍生效（测试与平台特例的口子）', function () {
    $binding = apdBinding(true);
    $version = apdVersion();
    apdFile($version);

    $task = app(DeployTaskService::class)->create(
        did: $binding->did,
        skillId: (int) $version->product_id,
        skillVersionId: $version->id,
        entitlementId: null,
        operation: DeployTask::OP_INSTALL,
        createdBy: 'test',
        packageUrl: 'https://example.com/override.pkg',
        packageHash: str_repeat('a', 64),
    );

    expect($task->package_url)->toBe('https://example.com/override.pkg')
        ->and($task->package_hash)->toBe(str_repeat('a', 64));
});

// ==================== 7 · 参考 Agent：端侧复算哈希 ====================

it('S-1 · 参考 Agent 模板带下载→复算→比对逻辑，错误码用常量替换（未真机验证）', function () {
    $body = get(route('shop.agent.py'))->assertOk()->getContent();

    expect($body)->toContain(DeployTask::ERR_PKG_HASH_MISMATCH)
        // 占位符没被替换掉 = 脚本会把字面量报给服务端
        ->and($body)->not->toContain('__ERR_PKG_HASH_MISMATCH__')
        // 端侧复算逻辑：下载 → 流式 sha256 → 与指令声明的哈希比对
        ->and($body)->toContain('fetch_sha256')
        ->and($body)->toContain('package_sha256')
        // 比对失败不安装：走自己的分支、上报 PKG_HASH_MISMATCH，不复用 done
        ->and($body)->toContain('包哈希不符');
});
