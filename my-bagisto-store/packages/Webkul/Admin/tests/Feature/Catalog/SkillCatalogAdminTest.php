<?php

use Webkul\Shop\Models\SkillCategory;
use Webkul\Shop\Models\SkillTag;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * 技能类目 / 标签 Admin CRUD（v3.4 步骤 6 的 Admin CRUD）。
 *
 * 这两套 taxonomy 是**前台技能市场的筛选维度**，公开接口直接暴露给 C 端 ——
 * 所以测试不只看 CRUD 能跑，还看三条防脏数据的约束：
 *   ① code（类目）/ (name,type)（标签）唯一 —— 重复会在前台出现两个一样的筛选项
 *   ② 标签 type 是白名单 —— 否则会出现 scene / Scene / scenes 三个拼写变体
 *   ③ 被引用的不许删（父类目 / 已被技能使用的标签）
 */

// ==================== 类目 ====================

it('技能类目列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skill-categories.index'))
        ->assertOk()
        ->assertSeeText('技能类目');
});

it('新建类目：多语言名称按 JSON 入库', function () {
    $this->loginAsAdmin();

    $code = 'cat_'.uniqid();

    post(route('admin.catalog.skill-categories.store'), [
        'code'       => $code,
        'name_zh'    => '测试品类',
        'name_en'    => 'Test Category',
        'sort_order' => 77,
        'status'     => 'active',
    ])->assertRedirect(route('admin.catalog.skill-categories.index'));

    $category = SkillCategory::where('code', $code)->first();

    // ⚠ 不要对 name 整个数组用 toBe —— MySQL 的 JSON 列会重排键序，
    //   断言会因顺序而非内容失败。逐键断言。
    expect($category)->not->toBeNull()
        ->and($category->name['zh_CN'])->toBe('测试品类')
        ->and($category->name['en'])->toBe('Test Category')
        ->and($category->nameIn())->toBe('测试品类')
        ->and($category->nameIn('en'))->toBe('Test Category');
});

it('新建类目：英文名留空时不写 en 键（不塞空串）', function () {
    $this->loginAsAdmin();

    $code = 'cat_noen_'.uniqid();

    post(route('admin.catalog.skill-categories.store'), [
        'code'    => $code,
        'name_zh' => '只有中文',
        'status'  => 'active',
    ]);

    expect(SkillCategory::where('code', $code)->first()->name)->toBe(['zh_CN' => '只有中文']);
});

it('新建类目：code 重复被拒', function () {
    $this->loginAsAdmin();

    $code = 'cat_dup_'.uniqid();

    $payload = ['code' => $code, 'name_zh' => 'A', 'status' => 'active'];

    post(route('admin.catalog.skill-categories.store'), $payload)->assertRedirect();
    post(route('admin.catalog.skill-categories.store'), $payload)->assertSessionHasErrors('code');

    expect(SkillCategory::where('code', $code)->count())->toBe(1);
});

it('删除类目：有子类目时被拒', function () {
    $this->loginAsAdmin();

    $parent = SkillCategory::create([
        'code' => 'cat_p_'.uniqid(), 'name' => ['zh_CN' => '父'], 'status' => 'active',
    ]);

    SkillCategory::create([
        'parent_id' => $parent->id,
        'code'      => 'cat_c_'.uniqid(), 'name' => ['zh_CN' => '子'], 'status' => 'active',
    ]);

    deleteJson(route('admin.catalog.skill-categories.delete', $parent->id))->assertStatus(422);

    expect(SkillCategory::find($parent->id))->not->toBeNull();
});

it('删除类目：没有子类目时可删', function () {
    $this->loginAsAdmin();

    $category = SkillCategory::create([
        'code' => 'cat_solo_'.uniqid(), 'name' => ['zh_CN' => '独苗'], 'status' => 'active',
    ]);

    deleteJson(route('admin.catalog.skill-categories.delete', $category->id))->assertOk();

    expect(SkillCategory::find($category->id))->toBeNull();
});

it('编辑类目：父级候选里不含自己（避免自环）', function () {
    $this->loginAsAdmin();

    $category = SkillCategory::create([
        'code' => 'cat_self_'.uniqid(), 'name' => ['zh_CN' => '自己'], 'status' => 'active',
    ]);

    $response = get(route('admin.catalog.skill-categories.edit', $category->id));

    $response->assertOk();

    // 页面里的父级下拉不应把自己列成自己的父级
    expect($response->viewData('parents')->pluck('id')->all())->not->toContain($category->id);
});

// ==================== 标签 ====================

it('技能标签列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.skill-tags.index'))
        ->assertOk()
        ->assertSeeText('技能标签');
});

it('新建标签：同分组下同名被拒', function () {
    $this->loginAsAdmin();

    $name = '测标签'.substr(uniqid(), -6);

    post(route('admin.catalog.skill-tags.store'), [
        'name' => $name, 'type' => SkillTag::TYPE_SCENE, 'sort_order' => 0,
    ])->assertRedirect();

    post(route('admin.catalog.skill-tags.store'), [
        'name' => $name, 'type' => SkillTag::TYPE_SCENE, 'sort_order' => 0,
    ])->assertSessionHasErrors('name');

    expect(SkillTag::where('name', $name)->where('type', SkillTag::TYPE_SCENE)->count())->toBe(1);
});

it('新建标签：同名但不同分组是合法的', function () {
    $this->loginAsAdmin();

    $name = '双分组'.substr(uniqid(), -6);

    post(route('admin.catalog.skill-tags.store'), ['name' => $name, 'type' => SkillTag::TYPE_MOTION]);
    post(route('admin.catalog.skill-tags.store'), ['name' => $name, 'type' => SkillTag::TYPE_SCENE]);

    expect(SkillTag::where('name', $name)->count())->toBe(2);
});

it('新建标签：分组必须是白名单，不能自造（防止 scene/Scene/scenes 各成一栏）', function () {
    $this->loginAsAdmin();

    post(route('admin.catalog.skill-tags.store'), [
        'name' => '乱来', 'type' => 'Scene',
    ])->assertSessionHasErrors('type');
});

it('删除标签：已被技能使用时被拒', function () {
    $this->loginAsAdmin();

    $tag = SkillTag::create([
        'name' => '被用着'.substr(uniqid(), -6), 'type' => SkillTag::TYPE_SCENE, 'sort_order' => 0,
    ]);

    DB::table('skill_tag_pivot')->insert(['skill_id' => 999001, 'tag_id' => $tag->id]);

    deleteJson(route('admin.catalog.skill-tags.delete', $tag->id))->assertStatus(422);

    expect(SkillTag::find($tag->id))->not->toBeNull();
});

it('删除标签：没被使用时可删', function () {
    $this->loginAsAdmin();

    $tag = SkillTag::create([
        'name' => '没人用'.substr(uniqid(), -6), 'type' => SkillTag::TYPE_SCENE, 'sort_order' => 0,
    ]);

    deleteJson(route('admin.catalog.skill-tags.delete', $tag->id))->assertOk();

    expect(SkillTag::find($tag->id))->toBeNull();
});

it('编辑标签页可以渲染', function () {
    $this->loginAsAdmin();

    $tag = SkillTag::create([
        'name' => '可编辑'.substr(uniqid(), -6), 'type' => SkillTag::TYPE_DIFFICULTY, 'sort_order' => 0,
    ]);

    put(route('admin.catalog.skill-tags.update', $tag->id), [
        'name' => $tag->name.'(改)', 'type' => SkillTag::TYPE_DIFFICULTY, 'sort_order' => 5,
    ])->assertRedirect();

    expect($tag->fresh()->name)->toContain('(改)')
        ->and($tag->fresh()->sort_order)->toBe(5);
});
