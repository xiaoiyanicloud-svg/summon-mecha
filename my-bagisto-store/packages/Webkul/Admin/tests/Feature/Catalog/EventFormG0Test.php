<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * G0-05 · 赛事后台编辑回填与字段校验。
 *
 * 覆盖:
 *   - 编辑页完整回填全部现有字段(含 bg_color),status 用 selected 绑定;
 *   - 只改 name 时其余字段原样保留;非空列收到空串不清空;
 *   - 校验失败后 old() 值仍保留在表单里;
 *   - name/bg_color 长度边界、status 枚举、hot 范围、teams 范围、sort 32 位范围;
 *   - 新建时 type/hot/teams/icon/bg_color/sort 由应用层归一化为默认值。
 *
 * 运行必须指向独立测试库(APP_ENV=testing,库名以 _test/_testing 结尾);
 * 用例继承 AdminTestCase -> DatabaseTransactions,所有写入在事务内回滚。
 * 本批次只处理表单回填/校验,不含状态转移合法性(G1)。
 */

beforeEach(function () {
    expect(app()->environment())->toBe('testing');

    expect(DB::connection()->getDatabaseName())
        ->toMatch('/(?:_test|_testing)$/')
        ->not->toBe('root');
});

/**
 * 显式创建 permission_type=all 的超级管理员,不依赖 role_id=1 的种子数据。
 */
function eventFormG0SuperAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'all',
        'permissions'     => [],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function eventFormG0Event(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name'      => '回填测试赛事',
        'type'      => '竞速赛',
        'status'    => 'ongoing',
        'date'      => '2026年10月1日 8:00-16:00',
        'city'      => '上海',
        'location'  => '浦东新区某体育馆',
        'organizer' => '召唤机甲',
        'hot'       => 88,
        'teams'     => 32,
        'prize'     => '一等奖：冠军奖杯',
        'schedule'  => '09:00 开幕式',
        'history'   => '往届回顾内容',
        'detail'    => '赛事简介内容',
        'icon'      => '🤖',
        'bg_color'  => '#123456',
        'sort'      => 7,
    ], $overrides));
}

function eventFormG0ExpectPreserved(Event $event): void
{
    expect($event->type)->toBe('竞速赛')
        ->and($event->date)->toBe('2026年10月1日 8:00-16:00')
        ->and($event->city)->toBe('上海')
        ->and($event->location)->toBe('浦东新区某体育馆')
        ->and($event->organizer)->toBe('召唤机甲')
        ->and((int) $event->hot)->toBe(88)
        ->and((int) $event->teams)->toBe(32)
        ->and($event->prize)->toBe('一等奖：冠军奖杯')
        ->and($event->schedule)->toBe('09:00 开幕式')
        ->and($event->history)->toBe('往届回顾内容')
        ->and($event->detail)->toBe('赛事简介内容')
        ->and($event->icon)->toBe('🤖')
        ->and($event->bg_color)->toBe('#123456')
        ->and((int) $event->sort)->toBe(7);
}

it('新建页与编辑页使用同一套字段并展示应用层默认值', function () {
    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    get(route('admin.catalog.events.create'))
        ->assertOk()
        ->assertSee('背景色')
        ->assertSee('value="格斗竞技"', false)
        ->assertSee('value="60"', false)
        ->assertSee('value="0"', false)
        ->assertSee('🏆')
        ->assertSee('value="#E8F0FE"', false)
        ->assertSee('value="registering" selected', false);
});

it('编辑页完整回填全部现有字段(含 bg_color)', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    get(route('admin.catalog.events.edit', $event->id))
        ->assertOk()
        ->assertSee('回填测试赛事')
        ->assertSee('竞速赛')
        ->assertSee('2026年10月1日 8:00-16:00')
        ->assertSee('上海')
        ->assertSee('浦东新区某体育馆')
        ->assertSee('召唤机甲')
        ->assertSee('一等奖：冠军奖杯')
        ->assertSee('09:00 开幕式')
        ->assertSee('往届回顾内容')
        ->assertSee('赛事简介内容')
        ->assertSee('🤖')
        ->assertSee('#123456')
        ->assertSee('value="ongoing" selected', false);
});

it('只改 name 更新时其余字段全部原样保留', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '只改名字的赛事',
        'status' => 'ongoing',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    $fresh = $event->fresh();

    expect($fresh->name)->toBe('只改名字的赛事')
        ->and($fresh->status)->toBe('ongoing');

    eventFormG0ExpectPreserved($fresh);
});

it('update 收到空串时:非空列保留原值,可空文本允许清空', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'     => '空值不清空赛事',
        'status'   => 'ongoing',
        'type'     => '',
        'hot'      => '',
        'teams'    => '',
        'icon'     => '',
        'bg_color' => '',
        'sort'     => '',
        'date'     => '',
        'city'     => '',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    $fresh = $event->fresh();

    expect($fresh->name)->toBe('空值不清空赛事')
        ->and($fresh->type)->toBe('竞速赛')
        ->and((int) $fresh->hot)->toBe(88)
        ->and((int) $fresh->teams)->toBe(32)
        ->and($fresh->icon)->toBe('🤖')
        ->and($fresh->bg_color)->toBe('#123456')
        ->and((int) $fresh->sort)->toBe(7)
        ->and($fresh->date)->toBe('')
        ->and($fresh->city)->toBe('')
        ->and($fresh->location)->toBe('浦东新区某体育馆')
        ->and($fresh->prize)->toBe('一等奖：冠军奖杯');
});

it('校验失败后 old() 输入值仍保留在表单中', function () {
    $event = eventFormG0Event();
    $longName = str_repeat('赛', 192);

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'     => $longName,
        'status'   => 'ended',
        'city'     => '临时输入城市',
        'hot'      => '77',
        'icon'     => '🔥',
        'bg_color' => '#ABCDEF',
        'sort'     => '3',
    ])->assertSessionHasErrors('name');

    get(route('admin.catalog.events.edit', $event->id))
        ->assertOk()
        ->assertSee($longName)
        ->assertSee('临时输入城市')
        ->assertSee('value="77"', false)
        ->assertSee('🔥')
        ->assertSee('#ABCDEF')
        ->assertSee('value="ended" selected', false);
});

it('name 长度:191 通过,192 拒绝', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => str_repeat('a', 191),
        'status' => 'ongoing',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    expect($event->fresh()->name)->toHaveLength(191);

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => str_repeat('a', 192),
        'status' => 'ongoing',
    ])->assertSessionHasErrors('name');

    expect($event->fresh()->name)->toHaveLength(191);
});

it('bg_color 长度:20 通过,21 拒绝', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'     => '背景色边界赛事',
        'status'   => 'ongoing',
        'bg_color' => str_repeat('b', 20),
    ])->assertRedirectToRoute('admin.catalog.events.index');

    expect($event->fresh()->bg_color)->toHaveLength(20);

    post(route('admin.catalog.events.update', $event->id), [
        'name'     => '背景色边界赛事',
        'status'   => 'ongoing',
        'bg_color' => str_repeat('b', 21),
    ])->assertSessionHasErrors('bg_color');

    expect($event->fresh()->bg_color)->toHaveLength(20);
});

it('status 只允许四个枚举值', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '非法状态赛事',
        'status' => 'archived',
    ])->assertSessionHasErrors('status');

    expect($event->fresh()->status)->toBe('ongoing');
});

it('hot 超过 100 与 teams 超过 unsigned int 上限均拒绝', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '热度越界赛事',
        'status' => 'ongoing',
        'hot'    => 101,
    ])->assertSessionHasErrors('hot');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '队伍数越界赛事',
        'status' => 'ongoing',
        'teams'  => 4294967296,
    ])->assertSessionHasErrors('teams');

    expect((int) $event->fresh()->hot)->toBe(88)
        ->and((int) $event->fresh()->teams)->toBe(32);
});

it('sort 超出 32 位整数范围被拒绝', function () {
    $event = eventFormG0Event();

    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '排序越界赛事',
        'status' => 'ongoing',
        'sort'   => 2147483648,
    ])->assertSessionHasErrors('sort');

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '排序越界赛事',
        'status' => 'ongoing',
        'sort'   => -2147483649,
    ])->assertSessionHasErrors('sort');

    expect((int) $event->fresh()->sort)->toBe(7);
});

it('create 缺省字段由应用层归一化为默认值', function () {
    $this->actingAs(eventFormG0SuperAdmin(), 'admin');

    post(route('admin.catalog.events.store'), [
        'name'   => '缺省字段赛事',
        'status' => 'registering',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    $event = Event::where('name', '缺省字段赛事')->firstOrFail();

    expect($event->type)->toBe('格斗竞技')
        ->and((int) $event->hot)->toBe(60)
        ->and((int) $event->teams)->toBe(0)
        ->and($event->icon)->toBe('🏆')
        ->and($event->bg_color)->toBe('#E8F0FE')
        ->and((int) $event->sort)->toBe(0);

    post(route('admin.catalog.events.store'), [
        'name'     => '空串缺省赛事',
        'status'   => 'registering',
        'type'     => '',
        'hot'      => '',
        'teams'    => '',
        'icon'     => '',
        'bg_color' => '',
        'sort'     => '',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    $event = Event::where('name', '空串缺省赛事')->firstOrFail();

    expect($event->type)->toBe('格斗竞技')
        ->and((int) $event->hot)->toBe(60)
        ->and((int) $event->teams)->toBe(0)
        ->and($event->icon)->toBe('🏆')
        ->and($event->bg_color)->toBe('#E8F0FE')
        ->and((int) $event->sort)->toBe(0);
});
