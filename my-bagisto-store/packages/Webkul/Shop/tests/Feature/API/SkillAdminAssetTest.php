<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\post;

/**
 * internal.key 上传通道（`/api/skill-admin`）的**回归测试**。
 *
 * ⚠ 存在的原因：第 9 步要把 `SkillAdminController::storeAsset` 的上传安全核
 *   抽成共享 service（`AssetUploadService`），供开发者门户复用。抽取**之前**先把
 *   既有行为锁住 —— 私盘落盘 / sha256 复核 / 文件名清洗 / carrier 白名单 / 鉴权。
 *   抽取之后这套测试必须**原样通过**（这是"不改坏既有通道"的判据）。
 */

beforeEach(function () {
    config(['skill_delivery.upload_key' => 'test-internal-key']);

    Storage::fake(SkillAsset::DISK);

    $this->version = SkillVersion::create([
        'product_id'   => 900000 + random_int(1, 9999),
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_DRAFT,
        'license_mode' => 'per_device',
    ]);

    $this->headers = [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ];

    $this->url = fn () => route('shop.api.skill-admin.assets.store', $this->version->id);
});

it('上传交付物：落 private 盘 + 记录 sha256 + 不回泄 file_path', function () {
    $file = UploadedFile::fake()->create('demo.skill', 10);
    $real = hash_file('sha256', $file->getRealPath());

    $res = post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'sha256'       => $real,
        'file'         => $file,
    ], $this->headers);

    $res->assertStatus(201)
        ->assertJsonPath('data.file_ready', true)
        // 红线：file_path 绝不出现在响应里
        ->assertJsonMissingPath('data.file_path');

    $asset = SkillAsset::where('skill_version_id', $this->version->id)->first();

    expect($asset)->not->toBeNull()
        ->and($asset->file_hash)->toBe($real)
        ->and($asset->file_size)->toBeGreaterThan(0);

    Storage::disk(SkillAsset::DISK)->assertExists($asset->file_path);
    // 红线：同一个文件绝不能同时存在于 public 盘
    Storage::disk('public')->assertMissing($asset->file_path);
});

it('声明 sha256 与实际不符 → 422 且不落盘', function () {
    $file = UploadedFile::fake()->create('demo.skill', 10);

    post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'sha256'       => str_repeat('a', 64),
        'file'         => $file,
    ], $this->headers)->assertStatus(422);

    expect(SkillAsset::where('skill_version_id', $this->version->id)->count())->toBe(0);
    expect(Storage::disk(SkillAsset::DISK)->allFiles())->toBeEmpty();
});

it('越界文件名被 basename + 正则清洗（防目录穿越）', function () {
    $file = UploadedFile::fake()->create('../../evil name!.sh', 5);

    post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $file,
    ], $this->headers)->assertStatus(201);

    $asset = SkillAsset::where('skill_version_id', $this->version->id)->first();

    expect($asset->file_name)->not->toContain('/')
        ->and($asset->file_name)->not->toContain('..');

    // 落盘路径不得逃出 skills/ 前缀
    expect($asset->file_path)->toStartWith('skills/');
    Storage::disk(SkillAsset::DISK)->assertExists($asset->file_path);
});

it('carrier_type 不在白名单 → 422', function () {
    post(($this->url)(), [
        'carrier_type' => 'evil_carrier',
        'file'         => UploadedFile::fake()->create('a.bin', 1),
    ], $this->headers)->assertStatus(422);
});

it('carrier_type=file 但没带文件 → 422', function () {
    post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
    ], $this->headers)->assertStatus(422);
});

it('非文件类载体（cloud_config）只存 payload、不落盘', function () {
    post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG,
        'payload'      => ['endpoint' => 'https://example.com'],
    ], $this->headers)->assertStatus(201)
        ->assertJsonPath('data.carrier_type', SkillAsset::CARRIER_CLOUD_CONFIG);

    $asset = SkillAsset::where('skill_version_id', $this->version->id)->first();

    expect($asset->file_path)->toBeNull()
        ->and($asset->payload)->toBe(['endpoint' => 'https://example.com']);
    expect(Storage::disk(SkillAsset::DISK)->allFiles())->toBeEmpty();
});

it('重复上传同一 (version, carrier, sort) 覆盖并清掉旧文件', function () {
    $first = UploadedFile::fake()->create('v1.skill', 3);

    post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $first,
    ], $this->headers)->assertStatus(201);

    $oldPath = SkillAsset::where('skill_version_id', $this->version->id)->value('file_path');

    $second = UploadedFile::fake()->create('v2.skill', 4);

    post(($this->url)(), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $second,
    ], $this->headers)->assertStatus(201);

    expect(SkillAsset::where('skill_version_id', $this->version->id)->count())->toBe(1);

    $newPath = SkillAsset::where('skill_version_id', $this->version->id)->value('file_path');

    Storage::disk(SkillAsset::DISK)->assertExists($newPath);
    Storage::disk(SkillAsset::DISK)->assertMissing($oldPath);
});

it('未配置 internal key → 503', function () {
    config(['skill_delivery.upload_key' => null]);

    post(($this->url)(), ['carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG], $this->headers)
        ->assertStatus(503);
});

it('internal key 不对 → 401', function () {
    post(($this->url)(), ['carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG], [
        'X-Internal-Key' => 'wrong',
        'Accept'         => 'application/json',
    ])->assertStatus(401);
});

it('版本不存在 → 404', function () {
    post(route('shop.api.skill-admin.assets.store', 999999), [
        'carrier_type' => SkillAsset::CARRIER_CLOUD_CONFIG,
    ], $this->headers)->assertStatus(404);
});
