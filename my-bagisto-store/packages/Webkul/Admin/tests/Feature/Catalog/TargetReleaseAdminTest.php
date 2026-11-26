<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\SkillAsset;
use Webkul\Shop\Models\SkillTargetRelease;
use Webkul\Shop\Models\SkillTargetReleaseEvent;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\SkillCompile\Portable\TargetProfileService;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * WBS B3：目标发布审核 Admin API + 四角色 ACL。
 *
 * viewer / reviewer / revoker / super 的权限矩阵；approve/reject/revoke 的状态机、
 * stale profile、跨 seller、404/403/409、只追加审计事件。
 */

function trAdmin(array $permissions = [], string $type = 'custom'): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $type,
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function trVersion(?int $sellerId = null): SkillVersion
{
    $productId = DB::table('products')->insertGetId([
        'sku'        => 'tr-sku-'.uniqid(),
        'type'       => 'simple',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return SkillVersion::create([
        'product_id'   => $productId,
        'seller_id'    => $sellerId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

function trRelease(
    string $status = SkillTargetRelease::STATUS_PENDING,
    ?SkillVersion $version = null,
    string $model = 'unitree_g1',
): SkillTargetRelease {
    $version ??= trVersion();
    $sourceDigest = 'sha256:'.str_repeat('a', 64);

    $artifact = SkillAsset::create([
        'skill_version_id'  => $version->id,
        'carrier_type'      => 'file',
        'asset_role'        => SkillAsset::ROLE_ARTIFACT,
        'asset_key'         => 'tr_'.uniqid(),
        'file_path'         => 'skills/tr/'.uniqid().'.skill',
        'file_name'         => 'tr.skill',
        'file_size'         => 10,
        'file_hash'         => hash('sha256', uniqid('', true)),
        'target_model_code' => $model,
        'build_key'         => 'bk_tr_'.uniqid(),
        'source_digest'     => $sourceDigest,
        'sort_order'        => 0,
    ]);

    return SkillTargetRelease::create([
        'skill_version_id'      => $version->id,
        'target_model_code'     => $model,
        'artifact_asset_id'     => $artifact->id,
        'build_key'             => $artifact->build_key,
        'target_profile_digest' => app(TargetProfileService::class)->getDigest($model),
        'source_digest'         => $sourceDigest,
        'review_status'         => $status,
        'reviewed_at'           => now(),
    ]);
}

it('B3 · viewer 只能看，不能 approve/reject/revoke（403）', function () {
    $release = trRelease();
    actingAs(trAdmin(['catalog', 'catalog.target-releases', 'catalog.target-releases.view']), 'admin');

    get(route('admin.catalog.target-releases.index'))->assertOk();
    get(route('admin.catalog.target-releases.show', $release->id))->assertOk();

    postJson(route('admin.catalog.target-releases.approve', $release->id), [])->assertStatus(403);
    postJson(route('admin.catalog.target-releases.reject', $release->id), ['reason' => 'x'])->assertStatus(403);
    postJson(route('admin.catalog.target-releases.revoke', $release->id), ['reason' => 'x'])->assertStatus(403);
});

it('B3 · reviewer 能 approve/reject，不能 revoke', function () {
    actingAs(trAdmin([
        'catalog',
        'catalog.target-releases',
        'catalog.target-releases.view',
        'catalog.target-releases.approve',
        'catalog.target-releases.reject',
    ]), 'admin');

    $approve = trRelease();
    post(route('admin.catalog.target-releases.approve', $approve->id))->assertRedirect();
    expect($approve->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_APPROVED);

    $reject = trRelease();
    post(route('admin.catalog.target-releases.reject', $reject->id), ['reason' => '产物不符'])->assertRedirect();
    expect($reject->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_REJECTED);

    postJson(route('admin.catalog.target-releases.revoke', $approve->id), ['reason' => 'x'])->assertStatus(403);
});

it('B3 · revoker 能撤销已通过产物，不能 approve', function () {
    actingAs(trAdmin([
        'catalog',
        'catalog.target-releases',
        'catalog.target-releases.view',
        'catalog.target-releases.revoke',
    ]), 'admin');

    $release = trRelease(SkillTargetRelease::STATUS_APPROVED);

    post(route('admin.catalog.target-releases.revoke', $release->id), ['reason' => '事故熔断'])->assertRedirect();
    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_REVOKED);

    postJson(route('admin.catalog.target-releases.approve', $release->id), [])->assertStatus(403);
});

it('B3 · super（permission_type=all）可执行全部动作', function () {
    actingAs(trAdmin([], 'all'), 'admin');

    $approve = trRelease();
    post(route('admin.catalog.target-releases.approve', $approve->id))->assertRedirect();
    expect($approve->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_APPROVED);

    post(route('admin.catalog.target-releases.revoke', $approve->id), ['reason' => 'super revoke'])->assertRedirect();
    expect($approve->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_REVOKED);
});

it('B3 · 已撤销不能再通过（409），审计事件只追加不覆盖', function () {
    actingAs(trAdmin([], 'all'), 'admin');
    $release = trRelease(SkillTargetRelease::STATUS_APPROVED);

    post(route('admin.catalog.target-releases.revoke', $release->id), ['reason' => 'revoke'])->assertRedirect();

    postJson(route('admin.catalog.target-releases.approve', $release->id), [])
        ->assertStatus(409);

    $events = SkillTargetReleaseEvent::query()
        ->where('skill_version_id', $release->skill_version_id)
        ->where('target_model_code', $release->target_model_code)
        ->orderBy('id')
        ->pluck('event_type')
        ->all();

    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_REVOKED)
        ->and($events)->toBe(['revoked']);
});

it('B3 · 未通过的产物不能撤销（409）', function () {
    actingAs(trAdmin([], 'all'), 'admin');
    $release = trRelease(SkillTargetRelease::STATUS_PENDING);

    postJson(route('admin.catalog.target-releases.revoke', $release->id), ['reason' => 'x'])
        ->assertStatus(409);

    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_PENDING);
});

it('B3 · 驳回必须给理由（422）', function () {
    actingAs(trAdmin([], 'all'), 'admin');
    $release = trRelease();

    postJson(route('admin.catalog.target-releases.reject', $release->id), [])
        ->assertStatus(422);

    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_PENDING);
});

it('B3 · stale profile digest → 409', function () {
    actingAs(trAdmin([], 'all'), 'admin');
    $release = trRelease();

    $release->target_profile_digest = 'sha256:'.str_repeat('f', 64);
    $release->save();

    postJson(route('admin.catalog.target-releases.approve', $release->id), [])
        ->assertStatus(409);

    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_PENDING);
});

it('B3 · 跨 seller 作用域 → 409；同 seller 放行', function () {
    actingAs(trAdmin([], 'all'), 'admin');
    $version = trVersion(10);
    $release = trRelease(SkillTargetRelease::STATUS_PENDING, $version);

    postJson(route('admin.catalog.target-releases.approve', $release->id), ['seller_id' => 11])
        ->assertStatus(409);

    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_PENDING);

    postJson(route('admin.catalog.target-releases.approve', $release->id), ['seller_id' => 10])
        ->assertOk();

    expect($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_APPROVED);
});

it('B3 · 不存在 → 404', function () {
    actingAs(trAdmin([], 'all'), 'admin');

    get(route('admin.catalog.target-releases.show', 999999))->assertNotFound();
    postJson(route('admin.catalog.target-releases.approve', 999999), [])->assertStatus(404);
});

it('B3 · 审计事件按动作只追加，历史不被覆盖', function () {
    actingAs(trAdmin([], 'all'), 'admin');
    $release = trRelease();

    post(route('admin.catalog.target-releases.approve', $release->id))->assertRedirect();
    post(route('admin.catalog.target-releases.revoke', $release->id), ['reason' => 'revoke'])->assertRedirect();

    $events = SkillTargetReleaseEvent::query()
        ->where('skill_version_id', $release->skill_version_id)
        ->where('target_model_code', $release->target_model_code)
        ->orderBy('id')
        ->pluck('event_type')
        ->all();

    expect($events)->toBe(['approved', 'revoked'])
        ->and($release->fresh()->review_status)->toBe(SkillTargetRelease::STATUS_REVOKED);
});
