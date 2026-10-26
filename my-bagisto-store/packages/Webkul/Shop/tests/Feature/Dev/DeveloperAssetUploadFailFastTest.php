<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\SkillPackage\PackageInspector;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * 开发者门户交付物上传即安检（Fail-Fast）测试 —— DEV-01 / DEV-02 / DEV-03 闭环。
 */

function failFastCustomer(): Customer
{
    return Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'Fast',
        'email'       => 'fast'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);
}

function makeValidSkillZip(string $entrypoint = 'runtime/main.py'): string
{
    $manifest = json_encode([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '测试即时安检技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['manual'],
        'resource_type'     => 'skill',
        'runtime'           => [
            'entrypoint' => $entrypoint,
        ],
        'safety'            => [
            'risk_level'         => 'low',
            'requires_open_area' => false,
            'estop_required'     => false,
            'max_duration_s'     => 10,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $path = tempnam(sys_get_temp_dir(), 'ffskill').'.skill';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', $manifest);
    $zip->addFromString('runtime/main.py', '#!/usr/bin/env python3\nprint("hello")');
    $zip->close();

    return $path;
}

function makeZipWithoutManifest(): string
{
    $path = tempnam(sys_get_temp_dir(), 'nomanifest').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('demo.txt', 'hello world without manifest');
    $zip->close();

    return $path;
}

beforeEach(function () {
    Storage::fake(SkillAsset::disk());
});

it('DEV-01: PackageInspector 对不存在文件与环境缺失优雅返回失败，绝不抛出 500 未捕获错误', function () {
    $inspector = app(PackageInspector::class);
    $result = $inspector->inspect('non_existent_file_path_123.skill');

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toContain('包文件不存在。');
});

it('DEV-02: 上传裸文本或非 ZIP 格式时立即触发 422 拒绝（Fail-Fast），绝不延迟到提审才报错', function () {
    $customer = failFastCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '安检测试技能', 'price' => 100]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);
    $version = SkillVersion::where('product_id', $productId)->first();

    // 上传普通裸文本文件（非 ZIP）
    $fakeFile = UploadedFile::fake()->create('raw_script.py', 5);

    $res = post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $fakeFile,
    ]);

    $res->assertSessionHasErrors(['file']);
    $error = session('errors')->first('file');
    expect($error)->toContain('技能包安检失败');
});

it('DEV-02: 上传缺失 manifest.json 的 ZIP 包时立即拦截并提示缺少清单', function () {
    $customer = failFastCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '无清单测试技能', 'price' => 100]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), ['version' => '1.0.0']);
    $version = SkillVersion::where('product_id', $productId)->first();

    $zipPath = makeZipWithoutManifest();
    $uploadedFile = new UploadedFile($zipPath, 'no_manifest.skill', 'application/zip', null, true);

    $res = post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $uploadedFile,
    ]);

    $res->assertSessionHasErrors(['file']);
    $error = session('errors')->first('file');
    expect($error)->toContain('技能包安检失败');
    expect($error)->toContain('缺少清单文件');
});

it('DEV-02 / DEV-03: 上传合规技能包成功，并自动从 manifest 提取 entrypoint', function () {
    $customer = failFastCustomer();
    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);
    post(route('shop.dev.agreements.sign'), [
        'accept_dev_agreement'     => 1,
        'accept_safety_commitment' => 1,
    ]);

    $profile = DeveloperProfile::where('customer_id', $customer->id)->first();
    $profile->update(['realname_status' => DeveloperProfile::REALNAME_APPROVED]);

    post(route('shop.dev.skills.store'), ['name' => '合法包测试技能', 'price' => 100]);
    $productId = DB::table('products')->where('seller_id', $profile->seller_id)->value('id');

    post(route('shop.dev.skills.versions.store', $productId), [
        'version'       => '1.0.0',
        'install_guide' => '请参考包内说明手动解压到目录',
    ]);
    $version = SkillVersion::where('product_id', $productId)->first();
    expect($version->install_guide)->toBe('请参考包内说明手动解压到目录');

    $validZipPath = makeValidSkillZip('runtime/custom_entry.py');
    $uploadedFile = new UploadedFile($validZipPath, 'valid.skill', 'application/zip', null, true);

    $res = post(route('shop.dev.versions.upload', $version->id), [
        'carrier_type' => SkillAsset::CARRIER_FILE,
        'file'         => $uploadedFile,
    ]);

    $res->assertSessionHasNoErrors();
    $res->assertRedirect();

    $asset = SkillAsset::where('skill_version_id', $version->id)->first();
    expect($asset)->not->toBeNull()
        ->and($asset->entrypoint)->toBe('runtime/custom_entry.py')
        ->and($asset->fileExists())->toBeTrue();
});
