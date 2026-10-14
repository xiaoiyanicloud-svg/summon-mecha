<?php

use Webkul\Shop\Models\DeviceModel;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/**
 * 机型库 Admin CRUD（v3.4 步骤 5 的待做项）。
 *
 * 机型库是**兼容性校验的输入** —— 这里的每个字段都会影响"用户能不能装某个技能"，
 * 所以测试不只验 CRUD 能跑，还验两条**保护性约束**：
 *   ① model_code 唯一（重复会让校验认错机型）
 *   ② 被引用的机型不许删（删了会让设备静默降级成"未知机型"）
 */

it('机型库列表页可以渲染', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.device-models.index'))
        ->assertOk()
        ->assertSeeText('机型库');
});

it('新建机型页面可以渲染，且显示通道选项', function () {
    $this->loginAsAdmin();

    get(route('admin.catalog.device-models.create'))
        ->assertOk()
        ->assertSeeText('新建机型')
        ->assertSeeText('可用通道');
});

it('新建机型：通道与固件版本正确入库', function () {
    $this->loginAsAdmin();

    $code = 'test_bot_'.uniqid();

    post(route('admin.catalog.device-models.store'), [
        'model_code'         => $code,
        'model_name'         => '测试机型',
        'brand'              => 'testvendor',
        'degrees_of_freedom' => 12,
        'comm_channels'      => ['manual'],
        'firmware_versions'  => "1.0.0\n1.1.0",
        'status'             => DeviceModel::STATUS_ACTIVE,
        'sort_order'         => 90,
    ])->assertRedirect(route('admin.catalog.device-models.index'));

    $model = DeviceModel::where('model_code', $code)->first();

    expect($model)->not->toBeNull()
        ->and($model->comm_channels)->toBe(['manual'])
        ->and($model->firmware_versions)->toBe(['1.0.0', '1.1.0'])
        ->and($model->status)->toBe(DeviceModel::STATUS_ACTIVE);
});

it('新建机型：model_code 重复会被拒（唯一性）', function () {
    $this->loginAsAdmin();

    $code = 'dup_bot_'.uniqid();

    post(route('admin.catalog.device-models.store'), [
        'model_code' => $code,
        'model_name' => 'A',
        'brand'      => 'x',
        'status'     => DeviceModel::STATUS_ACTIVE,
    ])->assertRedirect();

    post(route('admin.catalog.device-models.store'), [
        'model_code' => $code,
        'model_name' => 'B',
        'brand'      => 'x',
        'status'     => DeviceModel::STATUS_ACTIVE,
    ])->assertSessionHasErrors('model_code');

    expect(DeviceModel::where('model_code', $code)->count())->toBe(1);
});

it('新建机型：通道值域受白名单限制，不能自造通道', function () {
    $this->loginAsAdmin();

    post(route('admin.catalog.device-models.store'), [
        'model_code'    => 'evil_bot_'.uniqid(),
        'model_name'    => 'C',
        'brand'         => 'x',
        'comm_channels' => ['telepathy'],   // 不存在的通道
        'status'        => DeviceModel::STATUS_ACTIVE,
    ])->assertSessionHasErrors('comm_channels.0');
});

it('编辑机型：固件版本留空存 null（"未知"与"已知但为空"必须能区分）', function () {
    $this->loginAsAdmin();

    $model = DeviceModel::create([
        'brand'             => 'x',
        'model_code'        => 'fw_bot_'.uniqid(),
        'model_name'        => '固件测试',
        'comm_channels'     => ['manual'],
        'firmware_versions' => ['1.0.0'],
        'status'            => DeviceModel::STATUS_ACTIVE,
        'sort_order'        => 99,
    ]);

    put(route('admin.catalog.device-models.update', $model->id), [
        'model_code'        => $model->model_code,
        'model_name'        => $model->model_name,
        'brand'             => $model->brand,
        'comm_channels'     => ['manual'],
        'firmware_versions' => '',          // 清空
        'status'            => DeviceModel::STATUS_ACTIVE,
    ])->assertRedirect();

    expect($model->fresh()->firmware_versions)->toBeNull();
});

it('删除机型：有设备绑定时被拒（不能把绑定指向不存在的机型）', function () {
    $this->loginAsAdmin();

    $model = DeviceModel::create([
        'brand'         => 'x',
        'model_code'    => 'bound_bot_'.uniqid(),
        'model_name'    => '被引用的机型',
        'comm_channels' => ['manual'],
        'status'        => DeviceModel::STATUS_ACTIVE,
    ]);

    DB::table('device_binding')->insert([
        'did'          => 'did_test_'.uniqid(),
        'sn'           => 'sn_'.uniqid(),
        'model_id'     => $model->id,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    deleteJson(route('admin.catalog.device-models.delete', $model->id))
        ->assertStatus(422);

    expect(DeviceModel::find($model->id))->not->toBeNull();
});

it('删除机型：未被引用时可删', function () {
    $this->loginAsAdmin();

    $model = DeviceModel::create([
        'brand'         => 'x',
        'model_code'    => 'free_bot_'.uniqid(),
        'model_name'    => '没人用',
        'comm_channels' => ['manual'],
        'status'        => DeviceModel::STATUS_ACTIVE,
    ]);

    deleteJson(route('admin.catalog.device-models.delete', $model->id))->assertOk();

    expect(DeviceModel::find($model->id))->toBeNull();
});
