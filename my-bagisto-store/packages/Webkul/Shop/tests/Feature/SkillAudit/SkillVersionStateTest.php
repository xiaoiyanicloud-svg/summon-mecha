<?php

use Illuminate\Http\Request;
use Webkul\Admin\Http\Controllers\Catalog\SkillReviewController;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\DeveloperProfile;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Developer\DeveloperSkillService;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\SkillVersionState;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

/**
 * 版本状态真相源收敛（2026-10-16 · 裁决 Q2）。
 *
 * `skill_versions.status` 此前被多处各写各的、且提审不写 —— 于是"提审后"看起来还是草稿，
 * L2 驳回与"从没提审过"在 status 上分不出，"已发布版本被重复提审"还会写出永远不进队列的黑洞。
 *
 * 现在：审核判决唯一事实 = `audit_record`；版本生命周期唯一写入口 = `SkillVersionState`；
 * 状态的**写入位置**统一在 `AuditService`（submitLayer0 / recordLayer2）与 `SkillPublisher`。
 *
 * 本文件锁的就是这套语义 + 那条最关键的连带改动（审核台队列改取 in_review/rejected，
 * 否则提审后版本再也进不了审核台 —— 供给链静默断掉）。
 */

// ==================== 造数据 ====================

/** 挂一个本地词表，避免内容扫描因词表缺失而 degraded（degraded 会 fail-closed 成 reject）。 */
function svsWords(string $content = "赌博\n"): void
{
    $path = tempnam(sys_get_temp_dir(), 'svswords').'.txt';
    file_put_contents($path, $content);

    config([
        'content_safety.driver'                      => 'local',
        'content_safety.drivers.local.words_path'    => $path,
    ]);
}

/** 一份能过 ManifestValidator、且内容干净的中立清单。 */
function svsManifest(array $overrides = []): array
{
    return array_merge([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '抓取技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'low',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
    ], $overrides);
}

function svsVersion(string $status = SkillVersion::STATUS_DRAFT, array $attrs = []): SkillVersion
{
    return SkillVersion::create(array_merge([
        'product_id'   => 950000 + random_int(1, 9999),
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => $status,
        'license_mode' => 'per_device',
    ], $attrs));
}

function svsSubmitL0(SkillVersion $version, array $manifest): array
{
    return app(AuditService::class)->submitLayer0($version->product_id, $version->id, $manifest);
}

/** 造一个真实的开发者主体（走入驻路由），保证 seller_id 等字段齐全。 */
function svsProfile(): DeveloperProfile
{
    $customer = Customer::create([
        'first_name'  => 'Dev',
        'last_name'   => 'User',
        'email'       => 'svs'.uniqid().'@example.com',
        'password'    => bcrypt('secret123'),
        'channel_id'  => core()->getDefaultChannel()->id,
        'status'      => 1,
        'is_verified' => 1,
    ]);

    actingAs($customer, 'developer');

    post(route('shop.dev.onboarding.store'), ['dev_type' => 'personal']);

    return DeveloperProfile::where('customer_id', $customer->id)->first();
}

// ==================== 状态迁移（AuditService 写入）====================

it('提审 L0 通过 → 版本生命周期落 in_review', function () {
    svsWords();

    $version = svsVersion();
    $result = svsSubmitL0($version, svsManifest());

    expect($result['result'])->toBe(AuditRecord::RESULT_PASS)
        ->and($result['status_transition_ok'])->toBeTrue()
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);
});

it('L0 未过 → rejected；整改后再提审（rejected → in_review）放行', function () {
    svsWords();

    $version = svsVersion();

    // 空清单 → 格式校验不过 → L0 reject
    $bad = svsSubmitL0($version, []);

    expect($bad['result'])->toBe(AuditRecord::RESULT_REJECT)
        ->and($bad['status_transition_ok'])->toBeTrue()
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_REJECTED);

    // 整改后再提：合规清单 → L0 pass → rejected → in_review
    $good = svsSubmitL0($version, svsManifest());

    expect($good['result'])->toBe(AuditRecord::RESULT_PASS)
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_IN_REVIEW);
});

it('canSubmit：只有「草稿」「被驳回」可提审；in_review / published 都不行', function () {
    $states = app(SkillVersionState::class);

    expect($states->canSubmit(svsVersion(SkillVersion::STATUS_DRAFT)))->toBeTrue()
        ->and($states->canSubmit(svsVersion(SkillVersion::STATUS_REJECTED)))->toBeTrue()
        ->and($states->canSubmit(svsVersion(SkillVersion::STATUS_IN_REVIEW)))->toBeFalse()
        ->and($states->canSubmit(svsVersion(SkillVersion::STATUS_PUBLISHED)))->toBeFalse();
});

it('非法迁移：直接调 transition 走 draft → published → ok=false + TRANSITION_INVALID', function () {
    $version = svsVersion(SkillVersion::STATUS_DRAFT);

    $transition = app(SkillVersionState::class)->transition($version, SkillVersion::STATUS_PUBLISHED);

    expect($transition['ok'])->toBeFalse()
        ->and($transition['code'])->toBe(SkillVersionState::INVALID)
        // 迁移没成功 → 不落库，版本仍是 draft
        ->and($version->fresh()->status)->toBe(SkillVersion::STATUS_DRAFT);
});

// ==================== 提审前置（堵重复提审已发布版本）====================

it('in_review 的版本再提审 → 被拒，code=VERSION_NOT_SUBMITTABLE', function () {
    $profile = svsProfile();

    $version = svsVersion(SkillVersion::STATUS_IN_REVIEW, ['seller_id' => $profile->seller_id]);

    $result = app(DeveloperSkillService::class)->submitForReview($profile, $version->id);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('VERSION_NOT_SUBMITTABLE')
        ->and($result['status'])->toBe(422);
});

it('已 published 的版本提审 → 被拒（原先可重复提审，写出不进队列的黑洞）', function () {
    $profile = svsProfile();

    $version = svsVersion(SkillVersion::STATUS_PUBLISHED, ['seller_id' => $profile->seller_id]);

    $result = app(DeveloperSkillService::class)->submitForReview($profile, $version->id);

    expect($result['ok'])->toBeFalse()
        ->and($result['code'])->toBe('VERSION_NOT_SUBMITTABLE');
});

// ==================== 审核台队列（改了状态没改队列 = 静默断链）====================

it('审核台队列：in_review 出现、published 不出现', function () {
    // 队列定义 = 已提审（in_review / rejected）。这里直接读控制器的视图数据、**不渲染**：
    // Shop 套件注册了店铺主题，渲染 Admin 布局会命中店铺的 Vite 清单而失败
    // （原因见 SkillReviewGateTest 末尾的说明）。断言的是**控制器真的按新口径取数**。
    $inReview  = svsVersion(SkillVersion::STATUS_IN_REVIEW);
    $published = svsVersion(SkillVersion::STATUS_PUBLISHED);

    foreach ([$inReview, $published] as $v) {
        AuditRecord::create([
            'skill_id'     => $v->product_id,
            'version'      => $v->id,
            'audit_type'   => 1,
            'layer'        => AuditRecord::LAYER_MACHINE,
            'result'       => AuditRecord::RESULT_PASS,
            'submitted_at' => now(),
            'audited_at'   => now(),
        ]);
    }

    $view = app(SkillReviewController::class)
        ->index(Request::create('/admin/catalog/skill-reviews', 'GET'));

    $queuedIds = collect($view->getData()['rows'])->map(fn ($row) => $row['version']->id);

    expect($queuedIds)->toContain($inReview->id)
        ->and($queuedIds)->not->toContain($published->id);
});
