<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillSupply\AssetUploadService;

use function Pest\Laravel\artisan;

/**
 * 技能包存储盘的可切换性（v3.4 步骤 3 · 对象存储接入）。
 *
 * 这两组测试要证明的核心命题：
 *   **换存储盘 = 改一行配置 + 跑一次搬命令，业务代码零改动。**
 *
 * 若哪天有人把 `SkillAsset::disk()` 换回硬编码常量，这里会红。
 */

beforeEach(function () {
    Storage::fake('private');
    Storage::fake('alt');

    $this->version = SkillVersion::create([
        'product_id'   => 900000 + random_int(1, 9999),
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_DRAFT,
        'license_mode' => 'per_device',
    ]);
});

it('SkillAsset::disk() 跟随配置，缺省回落到 private', function () {
    config(['skill_delivery.asset_disk' => 'alt']);
    expect(SkillAsset::disk())->toBe('alt');

    config(['skill_delivery.asset_disk' => null]);
    expect(SkillAsset::disk())->toBe('private');

    config(['skill_delivery.asset_disk' => 'private']);
    expect(SkillAsset::disk())->toBe('private');
});

it('上传按配置落到目标盘，而不是写死的 private', function () {
    config(['skill_delivery.asset_disk' => 'alt']);

    $result = app(AssetUploadService::class)->store(
        $this->version,
        ['carrier_type' => SkillAsset::CARRIER_FILE],
        UploadedFile::fake()->create('demo.skill', 8)
    );

    expect($result->ok)->toBeTrue();

    $asset = SkillAsset::where('skill_version_id', $this->version->id)->firstOrFail();

    Storage::disk('alt')->assertExists($asset->file_path);
    Storage::disk('private')->assertMissing($asset->file_path);

    // fileExists() 也必须跟着配置走，否则下载/校验会找不到文件
    expect($asset->fileExists())->toBeTrue();

    config(['skill_delivery.asset_disk' => 'private']);
    expect($asset->fresh()->fileExists())->toBeFalse();
});

it('skill:move-assets 在两个盘之间搬文件；不加 --delete-source 时保留源', function () {
    config(['skill_delivery.asset_disk' => 'private']);

    Storage::disk('private')->put('skills/1/1.0.0/a.skill', 'hello-package');

    $asset = SkillAsset::create([
        'skill_version_id' => $this->version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/1/1.0.0/a.skill',
        'file_name'        => 'a.skill',
        'file_size'        => 13,
        'file_hash'        => hash('sha256', 'hello-package'),
        'sort_order'       => 0,
    ]);

    artisan('skill:move-assets', ['--from' => 'private', '--to' => 'alt'])->assertSuccessful();

    Storage::disk('alt')->assertExists('skills/1/1.0.0/a.skill');
    Storage::disk('private')->assertExists('skills/1/1.0.0/a.skill'); // 源保留

    // 关键：数据库里的路径**不变**（路径与盘无关，切盘不改数据）
    expect($asset->fresh()->file_path)->toBe('skills/1/1.0.0/a.skill');
});

it('skill:move-assets 幂等：重复跑不失败也不重复搬', function () {
    Storage::disk('private')->put('skills/9/1.0.0/b.skill', 'x');

    SkillAsset::create([
        'skill_version_id' => $this->version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/9/1.0.0/b.skill',
        'file_name'        => 'b.skill',
        'file_size'        => 1,
        'sort_order'       => 0,
    ]);

    artisan('skill:move-assets', ['--from' => 'private', '--to' => 'alt'])->assertSuccessful();
    artisan('skill:move-assets', ['--from' => 'private', '--to' => 'alt'])->assertSuccessful();

    Storage::disk('alt')->assertExists('skills/9/1.0.0/b.skill');
});

it('skill:move-assets --dry-run 不写任何东西', function () {
    Storage::disk('private')->put('skills/3/1.0.0/c.skill', 'z');

    SkillAsset::create([
        'skill_version_id' => $this->version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/3/1.0.0/c.skill',
        'file_name'        => 'c.skill',
        'file_size'        => 1,
        'sort_order'       => 0,
    ]);

    artisan('skill:move-assets', ['--from' => 'private', '--to' => 'alt', '--dry-run' => true])
        ->assertSuccessful();

    Storage::disk('alt')->assertMissing('skills/3/1.0.0/c.skill');
    Storage::disk('private')->assertExists('skills/3/1.0.0/c.skill');
});

it('skill:move-assets --delete-source 在后校验通过才删源', function () {
    Storage::disk('private')->put('skills/5/1.0.0/d.skill', 'payload-123');

    SkillAsset::create([
        'skill_version_id' => $this->version->id,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/5/1.0.0/d.skill',
        'file_name'        => 'd.skill',
        'file_size'        => 11,
        'sort_order'       => 0,
    ]);

    artisan('skill:move-assets', [
        '--from' => 'private', '--to' => 'alt', '--delete-source' => true,
    ])->assertSuccessful();

    Storage::disk('alt')->assertExists('skills/5/1.0.0/d.skill');
    Storage::disk('private')->assertMissing('skills/5/1.0.0/d.skill');
    expect(Storage::disk('alt')->get('skills/5/1.0.0/d.skill'))->toBe('payload-123');
});

it('skill:move-assets 缺 --to 或同盘时报错退出', function () {
    artisan('skill:move-assets', ['--from' => 'private'])->assertFailed();
    artisan('skill:move-assets', ['--from' => 'private', '--to' => 'private'])->assertFailed();
});
