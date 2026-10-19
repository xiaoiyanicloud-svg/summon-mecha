<?php

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistration;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

use function Pest\Laravel\getJson;

/**
 * G0-04 · 赛事报名 PII 遮罩 + 赛事 DataGrid 导出旁路封堵。
 *
 * 覆盖:
 *   - 超级管理员(permission_type=all)可看原始 name/phone,name/phone 列可搜索;
 *   - 仅有报名权限的 custom 角色只看遮罩值,name/phone 列不可搜索,
 *     显式 filters[name]/filters[phone] 被忽略,JSON 中不含原始值;
 *   - 空值遮罩后仍为空字符串;
 *   - EventDataGrid / EventRegistrationDataGrid 对任意 export/format 参数
 *     在 parent::process() 之前返回 403 JSON,不产生下载文件。
 *
 * 运行必须指向独立测试库(APP_ENV=testing,库名以 _test/_testing 结尾);
 * 用例继承 AdminTestCase -> DatabaseTransactions,所有写入在事务内回滚。
 * 本批次不含表单回填 / 校验(G0-05),不新增 PII ACL key。
 */

beforeEach(function () {
    expect(app()->environment())->toBe('testing');

    expect(DB::connection()->getDatabaseName())
        ->toMatch('/(?:_test|_testing)$/')
        ->not->toBe('root');
});

function piiG0Admin(array $permissions): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'custom',
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function piiG0SuperAdmin(): Admin
{
    $role = Role::factory()->create([
        'permission_type' => 'all',
        'permissions'     => [],
    ]);

    return Admin::factory()->create(['role_id' => $role->id]);
}

function piiG0Event(string $name = 'PII 测试赛事'): Event
{
    return Event::create([
        'name'   => $name,
        'status' => 'registering',
        'city'   => '上海',
    ]);
}

function piiG0Registration(Event $event, string $name, string $phone): EventRegistration
{
    return EventRegistration::create([
        'event_id'    => $event->id,
        'customer_id' => null,
        'name'        => $name,
        'phone'       => $phone,
        'city'        => '上海',
    ]);
}

function piiG0GridColumns(array $columns): array
{
    return collect($columns)->keyBy('index')->all();
}

it('超级管理员能看到原始 name/phone,name/phone 列可搜索', function () {
    $event = piiG0Event();
    piiG0Registration($event, '张三', '13900000001');

    $this->actingAs(piiG0SuperAdmin(), 'admin');

    $response = getJson(route('admin.catalog.events.registrations'), [
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();

    expect($response->json('records.0.name'))->toBe('张三')
        ->and($response->json('records.0.phone'))->toBe('13900000001')
        ->and($response->getContent())->toContain('13900000001');

    $columns = piiG0GridColumns($response->json('columns'));

    expect($columns['name']['searchable'])->toBeTrue()
        ->and($columns['phone']['searchable'])->toBeTrue();
});

it('custom 角色只看到遮罩值,PII 列不可搜索,显式搜索参数不生效且 JSON 无明文', function () {
    $event = piiG0Event();
    piiG0Registration($event, '张三', '13900000001');
    piiG0Registration($event, '李四', '13900000002');

    $this->actingAs(piiG0Admin([
        'catalog',
        'catalog.events',
        'catalog.events.registrations',
    ]), 'admin');

    $query = http_build_query([
        'filters' => [
            'name'  => '张三',
            'phone' => '13900000001',
        ],
    ]);

    $response = getJson(route('admin.catalog.events.registrations').'?'.$query, [
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();

    $records = collect($response->json('records'));

    // 两条都返回 = filters[name]/filters[phone] 被忽略(若生效只会剩 1 条)。
    expect($records)->toHaveCount(2)
        ->and($records->pluck('name')->all())->toBe(['李*', '张*'])
        ->and($records->pluck('phone')->all())->toBe(['139****0002', '139****0001']);

    $content = $response->getContent();

    expect($content)->not->toContain('张三')
        ->not->toContain('李四')
        ->not->toContain('13900000001')
        ->not->toContain('13900000002');

    $columns = piiG0GridColumns($response->json('columns'));

    expect($columns['name']['searchable'])->toBeFalse()
        ->and($columns['phone']['searchable'])->toBeFalse();
});

it('custom 角色按 phone 排序被忽略,仍按默认 id desc 返回且无明文', function () {
    $event = piiG0Event();
    piiG0Registration($event, '张三', '13900000001');
    piiG0Registration($event, '李四', '13900000002');

    $this->actingAs(piiG0Admin([
        'catalog',
        'catalog.events',
        'catalog.events.registrations',
    ]), 'admin');

    // 若排序生效,phone asc 会让 139****0001 排在 139****0002 之前。
    $query = http_build_query([
        'sort' => [
            'column' => 'phone',
            'order'  => 'asc',
        ],
    ]);

    $response = getJson(route('admin.catalog.events.registrations').'?'.$query, [
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();

    $records = collect($response->json('records'));

    expect($records)->toHaveCount(2)
        ->and($records->pluck('phone')->all())->toBe(['139****0002', '139****0001'])
        ->and($records->pluck('name')->all())->toBe(['李*', '张*']);

    $content = $response->getContent();

    expect($content)->not->toContain('13900000001')
        ->not->toContain('13900000002');
});

it('custom 角色下空 name/phone 遮罩后保持空字符串', function () {
    $event = piiG0Event();
    piiG0Registration($event, '', '');

    $this->actingAs(piiG0Admin([
        'catalog',
        'catalog.events',
        'catalog.events.registrations',
    ]), 'admin');

    $response = getJson(route('admin.catalog.events.registrations'), [
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();

    expect($response->json('records.0.name'))->toBe('')
        ->and($response->json('records.0.phone'))->toBe('');
});

it('两个赛事 DataGrid 对任意 export/format 参数返回 403 JSON 且不生成下载文件', function () {
    piiG0Event();

    $this->actingAs(piiG0SuperAdmin(), 'admin');

    $bypassQueries = [
        'export=1',
        'export=true',
        'export=0',
        'export=false',
        'export[]=1',
        'format=csv',
        'format=xls',
        'format=xlsx',
        'export=1&format=csv',
        'export[]=1&format=xlsx',
    ];

    foreach ([
        'admin.catalog.events.index',
        'admin.catalog.events.registrations',
    ] as $routeName) {
        foreach ($bypassQueries as $query) {
            $response = getJson(route($routeName).'?'.$query, [
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

            $response->assertStatus(403);

            expect($response->baseResponse)
                ->not->toBeInstanceOf(BinaryFileResponse::class)
                ->and($response->baseResponse->headers->get('content-disposition'))->toBeNull()
                ->and($response->baseResponse->headers->get('content-type'))->toContain('application/json');
        }
    }
});
