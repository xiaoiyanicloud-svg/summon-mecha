<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Webkul\Shop\Services\Ecosystem\ContractCoverageChecker;

/**
 * Q-01 · 契约门禁。
 *
 * 前半部分证明当前代码 / 路由 / ACL / OpenAPI **零违规**；
 * 后半部分故意制造漂移，证明门禁不是“永远绿”的装饰：
 * 未登记路由、缺权限、缺鉴权/幂等、未登记错误码、状态常量变化、
 * OpenAPI 缺 operation 都必须被拒绝。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */
beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('Q-01 tests require the dedicated summon_mecha_step10_test database.');
    }
});

function q1Check(): array
{
    return app(ContractCoverageChecker::class)->check();
}

function q1ViolationCodes(array $result): array
{
    return array_column($result['violations'], 'code');
}

it('Q-01 · 当前契约零违规', function () {
    $result = q1Check();

    expect($result['ok'])->toBeTrue()
        ->and($result['violations'])->toBe([])
        ->and($result['stats']['public_routes'])->toBe(45)
        ->and($result['stats']['contracted_routes'])->toBe(45)
        ->and($result['stats']['admin_routes'])->toBeGreaterThan(100);
});

it('Q-01 · 新增未登记公开写路由会被阻断', function () {
    Route::post('api/events/{id}/evil', fn () => response()->json(['ok' => true]))
        ->name('shop.api.events.evil');

    $codes = q1ViolationCodes(q1Check());

    expect($codes)->toContain('public_route_uncontracted');
});

it('Q-01 · 后台路由缺权限映射会被阻断', function () {
    Route::post('admin/catalog/ecosystem-evil', fn () => response()->json(['ok' => true]))
        ->name('admin.catalog.ecosystem.evil');

    $codes = q1ViolationCodes(q1Check());

    expect($codes)->toContain('admin_route_unmapped');
});

it('Q-01 · 写路由缺鉴权或幂等策略会被阻断', function () {
    Route::post('api/events/{id}/evil', fn () => response()->json(['ok' => true]))
        ->name('shop.api.events.evil');

    $routes = config('ecosystem_contract.public_routes');
    $routes['shop.api.events.evil'] = [
        'uri' => '/api/events/{id}/evil',
        'methods' => ['POST'],
        'auth' => 'anonymous',
        'handler' => 'Closure',
        'idempotency' => 'read',
        'throttled' => false,
        'errors' => [],
    ];
    config(['ecosystem_contract.public_routes' => $routes]);

    $codes = q1ViolationCodes(q1Check());

    expect($codes)->toContain('write_route_auth_missing')
        ->and($codes)->toContain('write_route_idempotency_missing');
});

it('Q-01 · 使用未登记错误码会被阻断', function () {
    $codes = config('ecosystem_contract.error_codes');
    config(['ecosystem_contract.error_codes' => array_values(array_diff($codes, ['EVENT_NOT_FOUND']))]);

    $violations = q1Check()['violations'];
    $subjects = array_column(
        array_values(array_filter($violations, fn ($v) => $v['code'] === 'error_code_undeclared')),
        'subject',
    );

    expect($subjects)->toContain('EVENT_NOT_FOUND');
});

it('Q-01 · 模型状态常量漂移会被阻断', function () {
    $inventory = config('ecosystem_contract.state_inventories.competition_showcase');
    $inventory['values'] = array_values(array_diff($inventory['values'], ['withdrawn']));
    config(['ecosystem_contract.state_inventories.competition_showcase' => $inventory]);

    $codes = q1ViolationCodes(q1Check());

    expect($codes)->toContain('state_inventory_drift');
});

it('Q-01 · 发布转移表引用未声明状态会被阻断', function () {
    config([
        'ecosystem_publishing.transitions.event.publish' => [
            'approved' => 'published',
            'ghost' => 'published',
        ],
    ]);

    $codes = q1ViolationCodes(q1Check());

    expect($codes)->toContain('state_source_unknown');
});

it('Q-01 · OpenAPI 缺 operation 会被阻断', function () {
    $source = base_path().'/../docs/ecosystem/openapi.json';
    $document = json_decode((string) file_get_contents($source), true);

    expect($document)->toBeArray();

    unset($document['paths']['/api/events']['get']);

    $temporary = base_path('storage/q1-openapi-missing.json');
    file_put_contents($temporary, json_encode($document, JSON_UNESCAPED_SLASHES));

    config(['ecosystem_contract.openapi_path' => '/storage/q1-openapi-missing.json']);

    try {
        $codes = q1ViolationCodes(q1Check());

        expect($codes)->toContain('openapi_operation_missing');
    } finally {
        @unlink($temporary);
    }
});

it('Q-01 · OpenAPI 错误码与契约不一致会被阻断', function () {
    $source = base_path().'/../docs/ecosystem/openapi.json';
    $document = json_decode((string) file_get_contents($source), true);

    $document['paths']['/api/events']['get']['x-error-codes'] = ['ONLY_ONE'];

    $temporary = base_path('storage/q1-openapi-errors.json');
    file_put_contents($temporary, json_encode($document, JSON_UNESCAPED_SLASHES));

    config(['ecosystem_contract.openapi_path' => '/storage/q1-openapi-errors.json']);

    try {
        $codes = q1ViolationCodes(q1Check());

        expect($codes)->toContain('openapi_error_codes_mismatch');
    } finally {
        @unlink($temporary);
    }
});
