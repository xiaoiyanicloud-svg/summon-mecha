<?php

use Webkul\Shop\Models\AuditRecord;
use Webkul\Shop\Models\SkillVersion;

use function Pest\Laravel\get;

/**
 * 审核台的**渲染冒烟测试**（Admin 套件）。
 *
 * 为什么单独放在 Admin 套件、而不是和闸门逻辑测试放在一起：
 * Shop 套件会注册店铺主题，Admin 布局再去解析主题资产时命中的是店铺的 Vite 清单
 * —— 渲染必炸，那是环境问题不是代码问题。Admin 套件里布局能正常解析。
 *
 * 这两条的价值是**抓 Blade 错误**（语法/变量名/拼写），`php -l` 抓不到那类问题。
 * 逻辑断言在 `Shop/tests/Feature/SkillAudit/SkillReviewGateTest.php`。
 */

it('审核台列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skill-reviews.index'))
        ->assertOk()
        ->assertSeeText('审核台')
        ->assertSeeText('发布闸门');
});

it('审核台详情页可以渲染，并显示每个层的状态', function () {
    $this->loginAsAdmin();

    $version = SkillVersion::create([
        'product_id' => 987001,
        'version'    => '1.0.0',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);

    AuditRecord::create([
        'skill_id'     => 987001,
        'version'      => $version->id,
        'audit_type'   => 1,
        'layer'        => AuditRecord::LAYER_MACHINE,
        'result'       => AuditRecord::RESULT_PASS,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);

    get(route('admin.catalog.skill-reviews.show', $version->id))
        ->assertOk()
        // L0 已过、L2 未判 → 闸门未过
        ->assertSeeText('闸门未过')
        ->assertSeeText('L0 机审')
        ->assertSeeText('L1 仿真预跑')
        ->assertSeeText('L2 人审')
        // L1 未接入必须如实显示，不能显示成"通过"
        ->assertSeeText('未接入')
        ->assertSeeText('跑 L0 机审');
});

it('审核人列：控制台写入的与外部通道写入的措辞必须不同（不可都写成 #id）', function () {
    $this->loginAsAdmin();

    $version = SkillVersion::create([
        'product_id' => 987002,
        'version'    => '1.0.0',
        'status'     => SkillVersion::STATUS_DRAFT,
    ]);

    // ① 控制台以登录身份写入的 —— 审核人可核验
    AuditRecord::create([
        'skill_id'     => 987002,
        'version'      => $version->id,
        'audit_type'   => 2,
        'layer'        => AuditRecord::LAYER_HUMAN,
        'result'       => AuditRecord::RESULT_PASS,
        'auditor_id'   => 42,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);

    // ② 外部机器通道写入的 —— 那条通道代表不了具体的人，所以不该出现"审核人"
    AuditRecord::create([
        'skill_id'     => 987002,
        'version'      => $version->id,
        'audit_type'   => 2,
        'layer'        => AuditRecord::LAYER_HUMAN,
        'result'       => AuditRecord::RESULT_SUPPLEMENT,
        'auditor_id'   => null,
        'submitted_at' => now(),
        'audited_at'   => now(),
    ]);

    get(route('admin.catalog.skill-reviews.show', $version->id))
        ->assertOk()
        // 有可核验的审核人 → 写明是哪个后台账号
        ->assertSeeText('平台审核员 #42')
        // 没有 → 明说没有，而不是留空让人自己猜，更不是编一个 id
        ->assertSeeText('外部通道提交（无可核验的审核人）');
});

it('技能管理页可以渲染（改动过发布闸门与上传委托）', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skills.index'))
        ->assertOk()
        ->assertSeeText('技能管理');
});
