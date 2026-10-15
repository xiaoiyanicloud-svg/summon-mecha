<?php

use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\post;

/**
 * 发布闸门唯一化（2026-10-16）。
 *
 * 背景：发布原先有两条路径 —— 运营后台那份带闸门，internal.key 的 API 通道
 * （`/api/skill-admin/versions/{id}/publish`）**没有闸门**。后者是一条静默旁路：
 * 没过审的技能可以经它直接上架，与 M2「可上架 · 垃圾被拒」的判据直接冲突。
 *
 * 本文件锁的是**行为**，不是文案：能不能上架、状态变成什么。
 * "两条通道走同一条闸门"这件事由 `SkillPublisher` 的结构保证，这里用 API 侧证明闸门确实生效。
 */

function spgHeaders(): array
{
    return [
        'X-Internal-Key' => 'test-internal-key',
        'Accept'         => 'application/json',
    ];
}

function spgVersion(string $status = SkillVersion::STATUS_DRAFT): SkillVersion
{
    $version = SkillVersion::create([
        'product_id'   => 910000 + random_int(1, 9999),
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => $status,
        'license_mode' => 'per_device',
    ]);

    spgAsset($version);

    return $version->fresh('assets');
}

function spgAsset(SkillVersion $version, string $name = 'spg-demo.skill'): SkillAsset
{
    return SkillAsset::create([
        'skill_version_id' => $version->id,
        'seller_id'        => null,
        'carrier_type'     => SkillAsset::CARRIER_FILE,
        'file_path'        => 'skills/'.$name,
        'file_name'        => $name,
        'file_size'        => 1024,
    ]);
}

function spgAudit(SkillVersion $version, int $layer, int $result): AuditRecord
{
    return AuditRecord::create([
        'skill_id'     => $version->product_id,
        'version'      => $version->id,
        'audit_type'   => $layer === AuditRecord::LAYER_HUMAN ? 2 : 1,
        'layer'        => $layer,
        'result'       => $result,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);
}

function spgUrl(SkillVersion $version): string
{
    return route('shop.api.skill-admin.versions.publish', $version->id);
}

beforeEach(function () {
    config(['skill_delivery.upload_key' => 'test-internal-key']);
});

it('旁路已堵：没过审的技能经 API 通道也发布不了（原先能静默上架）', function () {
    $version = spgVersion();

    post(spgUrl($version), [], spgHeaders())
        ->assertStatus(422)
        ->assertJsonPath('code', 'GATE_NOT_PASSED');

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);
});

it('闸门过了才放行：L0 + L2 都通过 → 发布成功', function () {
    $version = spgVersion();

    spgAudit($version, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    spgAudit($version, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    post(spgUrl($version), [], spgHeaders())->assertOk();

    $fresh = $version->fresh();

    expect($fresh->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and($fresh->released_at)->not->toBeNull();
});

it('驳回过、整改后再提 → 以每层最新一条判定为准，放行', function () {
    $version = spgVersion();

    spgAudit($version, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    spgAudit($version, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_REJECT);

    post(spgUrl($version), [], spgHeaders())->assertStatus(422);
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);

    spgAudit($version, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    post(spgUrl($version), [], spgHeaders())->assertOk();
    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED);
});

it('没有交付物 → 发布被拒（沿用原有理由码）', function () {
    $version = SkillVersion::create([
        'product_id' => 930000 + random_int(1, 9999),
        'version'    => '1.0.0',
        'channel'    => 'stable',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);

    post(spgUrl($version), [], spgHeaders())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VERSION_HAS_NO_ASSET');
});

it('已归档的版本不能发布', function () {
    $version = spgVersion(SkillVersion::STATUS_ARCHIVED);

    post(spgUrl($version), [], spgHeaders())
        ->assertStatus(422)
        ->assertJsonPath('code', 'VERSION_ARCHIVED');
});

it('同商品发布新版本 → 旧的 published 自动归档（同时只有一份生效）', function () {
    $old = spgVersion(SkillVersion::STATUS_PUBLISHED);

    $new = SkillVersion::create([
        'product_id' => $old->product_id,
        'version'    => '2.0.0',
        'channel'    => 'stable',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);
    spgAsset($new, 'spg-demo-2.skill');

    spgAudit($new, AuditRecord::LAYER_MACHINE, AuditRecord::RESULT_PASS);
    spgAudit($new, AuditRecord::LAYER_HUMAN, AuditRecord::RESULT_PASS);

    post(spgUrl($new), [], spgHeaders())->assertOk();

    expect($new->fresh()->status)->toBe(SkillVersion::STATUS_PUBLISHED)
        ->and($old->fresh()->status)->toBe(SkillVersion::STATUS_ARCHIVED);
});

it('未配置 internal key → 通道整体 503（旁路连门都进不来）', function () {
    config(['skill_delivery.upload_key' => null]);

    $version = spgVersion();

    post(spgUrl($version), [], spgHeaders())->assertStatus(503);

    expect($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);
});
