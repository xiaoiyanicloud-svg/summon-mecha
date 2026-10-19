<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/**
 * G0-03 · 后台赛事逐动作授权。
 *
 * 覆盖:
 *   - 仅有列表权限(catalog.events)的 custom 角色:列表可看,其余动作全部拒绝且无副作用;
 *   - 超级管理员(permission_type=all)七个动作均可访问;
 *   - 未登录访问被重定向到后台登录;
 *   - {id} 加 whereNumber 后,2abc 之类的非法 ID 直接 404,不会命中 ID=2;
 *   - 列表 DataGrid 动作与页面按钮按权限条件输出。
 *
 * 运行必须指向独立测试库(APP_ENV=testing,库名以 _test/_testing 结尾);
 * 用例继承 AdminTestCase -> DatabaseTransactions,所有写入在事务内回滚。
 * 本批次不含 PII 脱敏 / 导出(G0-04)与表单回填 / 校验(G0-05)。
 */

beforeEach(function () {
    expect(app()->environment())->toBe('testing');

    expect(DB::connection()->getDatabaseName())
        ->toMatch('/(?:_test|_testing)$/')
        ->not->toBe('root');
});

/**
 * 显式创建 custom 角色 + 管理员,避免依赖默认管理员/角色。
 *
 * 注意:permissions 需带上菜单分组父 key(如 catalog),否则 Admin 菜单
 * 在缺少父节点时会报 "Undefined array key name";动作权限仍以传入项为准。
 */
function eventAclAdmin(array $permissions): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function eventAclSuperAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'all',
        'permissions'     => [],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function eventAclEvent(string $name = '授权测试赛事'): Event
{
    return Event::create([
        'name'   => $name,
        'status' => 'registering',
        'city'   => '上海',
    ]);
}

it('仅有列表权限时:列表可看,create/store/edit/update/delete/registrations 全部拒绝且无副作用', function () {
    $event = eventAclEvent('原始赛事名');

    $this->actingAs(eventAclAdmin(['catalog', 'catalog.events']), 'admin');

    $eventCount = Event::count();

    // 读入口:列表放行。
    get(route('admin.catalog.events.index'))->assertOk();

    // 读入口:ACL 已登记路由映射 -> Bouncer 中间件 401。
    get(route('admin.catalog.events.create'))->assertStatus(401);
    get(route('admin.catalog.events.edit', $event->id))->assertStatus(401);
    get(route('admin.catalog.events.registrations'))->assertStatus(401);

    // 写入口:控制器兜底 -> 403(不能只靠隐藏菜单)。
    post(route('admin.catalog.events.store'), [
        'name'   => '越权新建赛事',
        'status' => 'registering',
    ])->assertStatus(403);

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '越权改名',
        'status' => 'registering',
    ])->assertStatus(403);

    delete(route('admin.catalog.events.delete', $event->id))->assertStatus(403);

    expect(Event::count())->toBe($eventCount)
        ->and($event->fresh()->name)->toBe('原始赛事名');
});

it('仅有列表权限时:列表按钮与 DataGrid 写动作都不输出', function () {
    eventAclEvent();

    $this->actingAs(eventAclAdmin(['catalog', 'catalog.events']), 'admin');

    $html = get(route('admin.catalog.events.index'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('新建赛事')
        ->and($html)->not->toContain('报名列表');

    $response = getJson(route('admin.catalog.events.index'), [
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();

    expect($response->json('actions'))->toBe([])
        ->and($response->json('records.0.actions'))->toBe([]);
});

it('超级管理员可以访问全部赛事动作', function () {
    $this->actingAs(eventAclSuperAdmin(), 'admin');

    get(route('admin.catalog.events.index'))
        ->assertOk()
        ->assertSeeText('新建赛事')
        ->assertSeeText('报名列表');

    get(route('admin.catalog.events.create'))->assertOk();
    get(route('admin.catalog.events.registrations'))->assertOk();

    post(route('admin.catalog.events.store'), [
        'name'   => '超管新建赛事',
        'status' => 'registering',
        'city'   => '上海',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    $event = Event::where('name', '超管新建赛事')->firstOrFail();

    get(route('admin.catalog.events.edit', $event->id))->assertOk();

    post(route('admin.catalog.events.update', $event->id), [
        'name'   => '超管改名后赛事',
        'status' => 'ongoing',
    ])->assertRedirectToRoute('admin.catalog.events.index');

    expect($event->fresh()->name)->toBe('超管改名后赛事')
        ->and($event->fresh()->status)->toBe('ongoing');

    delete(route('admin.catalog.events.delete', $event->id))
        ->assertOk()
        ->assertJson(['message' => '赛事已删除']);

    expect(Event::find($event->id))->toBeNull();

    $response = getJson(route('admin.catalog.events.index'), [
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();

    expect($response->json('actions'))->toHaveCount(2);
});

it('超级管理员不能硬删除已有报名的赛事:返回 422 且赛事与报名都保留', function () {
    $event = eventAclEvent('有报名的赛事');

    $registration = EventRegistration::create([
        'event_id'    => $event->id,
        'customer_id' => null,
        'name'        => '报名用户',
        'phone'       => '13800000000',
        'city'        => '上海',
    ]);

    $this->actingAs(eventAclSuperAdmin(), 'admin');

    delete(route('admin.catalog.events.delete', $event->id))
        ->assertStatus(422)
        ->assertJson([
            'message' => '该赛事已有报名，不能直接删除；请先停用/归档（归档能力待 G1）',
        ]);

    expect(Event::find($event->id))->not->toBeNull()
        ->and(EventRegistration::find($registration->id))->not->toBeNull();
});

it('超级管理员删除无报名的赛事仍然成功', function () {
    $event = eventAclEvent('无报名的赛事');

    $this->actingAs(eventAclSuperAdmin(), 'admin');

    delete(route('admin.catalog.events.delete', $event->id))
        ->assertOk()
        ->assertJson(['message' => '赛事已删除']);

    expect(Event::find($event->id))->toBeNull();
});

it('未登录访问赛事后台:重定向到登录页且不产生副作用', function () {
    eventAclEvent();

    $eventCount = Event::count();

    get(route('admin.catalog.events.index'))
        ->assertRedirect(route('admin.session.create'));

    get(route('admin.catalog.events.registrations'))
        ->assertRedirect(route('admin.session.create'));

    post(route('admin.catalog.events.store'), [
        'name'   => '未登录新建赛事',
        'status' => 'registering',
    ])->assertRedirect(route('admin.session.create'));

    delete(route('admin.catalog.events.delete', 1))
        ->assertRedirect(route('admin.session.create'));

    expect(Event::count())->toBe($eventCount);
});

it('非法 ID 路由不命中:2abc 返回 404', function () {
    $this->actingAs(eventAclSuperAdmin(), 'admin');

    $this->get(route('admin.catalog.events.edit', ['id' => '2abc']))->assertNotFound();
    $this->post(route('admin.catalog.events.update', ['id' => '2abc']))->assertNotFound();
    $this->delete(route('admin.catalog.events.delete', ['id' => '2abc']))->assertNotFound();
});
