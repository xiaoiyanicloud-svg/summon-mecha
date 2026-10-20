<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Customer\Models\Customer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRegistrationScope;
use Webkul\Shop\Services\Ecosystem\RegistrationExportService;
use Webkul\Shop\Services\Ecosystem\RegistrationException;
use Webkul\Shop\Services\Ecosystem\RegistrationStaffScope;
use Webkul\Shop\Services\Ecosystem\RegistrationSubmissionService;
use Webkul\Shop\Tests\Feature\Ecosystem\G2RegistrationFixtures as Fixtures;
use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/**
 * G2-C · 专用报名导出。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G2 tests require the dedicated summon_mecha_step10_test database.');
    }

    Storage::fake('local');

    config([
        'ecosystem.events.registration_enabled'        => true,
        'ecosystem.events.registration_write_verified' => true,
        'ecosystem_registration.enabled'               => true,
    ]);
});

function g2ExportCustomer(string $suffix = ''): Customer
{
    return Customer::factory()->create([
        'email' => 'g2-export-'.($suffix ?: bin2hex(random_bytes(4))).'@example.test',
        'status' => 1,
    ]);
}

function g2ExportScope(int $eventId): EventRegistrationScope
{
    $id = Fixtures::scope($eventId, [
        'status'              => 'open',
        'policy_confirmed_at' => now('UTC')->format('Y-m-d H:i:s'),
        'capacity'            => 10,
    ]);

    return EventRegistrationScope::findOrFail($id);
}

function g2ExportInput(EventRegistrationScope $scope, array $overrides = []): array
{
    return array_merge([
        'request_key'     => 'exp-'.bin2hex(random_bytes(8)),
        'form_version'    => (int) $scope->form_version,
        'privacy_version' => (string) $scope->privacy_version,
        'consent'         => true,
        'name'            => '张三',
        'phone'           => '13800000001',
        'city'            => '上海',
    ], $overrides);
}

function g2ExportAdmin(string $type = 'all', array $permissions = []): Admin
{
    $role = Role::factory()->create([
        'permission_type' => $type,
        'permissions'     => $permissions,
    ]);

    return Admin::factory()->create(['role_id' => $role->id, 'status' => 1]);
}

it('G2-C · 默认遮罩导出；CSV 含 BOM 且公式被中和', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ExportScope($event['id']);
    $customer = g2ExportCustomer();
    $admin = g2ExportAdmin();

    app(RegistrationSubmissionService::class)->submit(
        Event::find($event['id']),
        $customer,
        g2ExportInput($scope, ['name' => '=1+1', 'phone' => '13800000001']),
    );

    $export = app(RegistrationExportService::class)->create($event['id'], $admin, ['status' => 'confirmed'], false);

    $row = DB::table('ecosystem_export_files')->where('token_hash', hash('sha256', $export['token']))->first();
    $csv = Storage::disk('local')->get($row->file_path);

    expect($csv)->toStartWith("\xEF\xBB\xBF");
    expect($csv)->toContain("'=***");
    expect($csv)->not->toContain('13800000001');
    expect($csv)->toContain('138****0001');
    expect($row->token_hash)->not->toContain($export['token']);
});

it('G2-C · 原始联系人需 pii 能力；权限撤回后不能下载', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ExportScope($event['id']);
    $customer = g2ExportCustomer();
    $super = g2ExportAdmin();
    $operator = g2ExportAdmin('custom', ['ecosystem.registrations.export']);

    app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2ExportInput($scope));

    app(RegistrationStaffScope::class)->grant(
        $event['id'],
        (int) $operator->id,
        ['export'],
        null,
        null,
        (int) $super->id,
    );

    $service = app(RegistrationExportService::class);

    // 有 export 无 pii：遮罩导出可用，原始值拒绝。
    $masked = $service->create($event['id'], $operator, [], false);
    expect($masked['rows'])->toBe(1);

    expect(fn () => $service->create($event['id'], $operator, [], true))
        ->toThrow(RegistrationException::class);

    // 权限撤回后不能新下载。
    app(RegistrationStaffScope::class)->revoke($event['id'], (int) $operator->id, (int) $super->id);

    expect(fn () => $service->download($masked['token'], $operator))
        ->toThrow(RegistrationException::class);
});

it('G2-C · 导出文件过期后下载被拒绝', function () {
    $event = Fixtures::event(['kind' => 'salon']);
    $scope = g2ExportScope($event['id']);
    $customer = g2ExportCustomer();
    $admin = g2ExportAdmin();

    app(RegistrationSubmissionService::class)->submit(Event::find($event['id']), $customer, g2ExportInput($scope));

    $export = app(RegistrationExportService::class)->create($event['id'], $admin, [], false);

    DB::table('ecosystem_export_files')
        ->where('token_hash', hash('sha256', $export['token']))
        ->update(['expires_at' => now('UTC')->subMinute()->format('Y-m-d H:i:s')]);

    expect(fn () => app(RegistrationExportService::class)->download($export['token'], $admin))
        ->toThrow(RegistrationException::class);
});
