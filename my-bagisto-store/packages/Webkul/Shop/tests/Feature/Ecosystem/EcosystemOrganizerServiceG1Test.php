<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\OrganizerService;

/**
 * G1-03 · OrganizerService 行为守卫（ADR-0001 §4.4 / G1-D05）。
 *
 * 保护的是**状态机与核验事实**，不是“类存在”：
 *   · create 一律 pending，调用方不能自带 status / verified_by / verified_at；
 *   · verify 必须在行锁内、admin 存在、至少一项依据，verified_at 按 UTC 落库；
 *   · 已核验主体修改敏感字段 → 回 pending 并清空核验人/时间，但保留 note；
 *   · reject 只允许 pending，suspend 只允许 verified，两者都必须写理由；
 *   · 不提供 delete。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-03 organizer service tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g3OrganizerService(): OrganizerService
{
    return app(OrganizerService::class);
}

function g3OrganizerAdminId(): int
{
    return DB::table('admins')->insertGetId([
        'name'       => 'G3 Reviewer',
        'email'      => 'g3-organizer-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g3OrganizerPayload(array $overrides = []): array
{
    return array_merge([
        'name'                 => 'G3 主办方',
        'platform_role'        => 'organizer',
        'source_url'           => 'https://example.com/events',
        'public_materials'     => '官网与活动页',
        'private_evidence_ref' => 'evidence/2026/g3.pdf',
        'verification_note'    => '初始资料',
    ], $overrides);
}

function g3OrganizerErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (EventSourceValidationException $exception) {
        return $exception->errorCode;
    }

    return null;
}

it('G1-03 · create 强制 pending，且拒绝调用方自带核验字段', function () {
    $service = g3OrganizerService();

    expect(fn () => $service->create(g3OrganizerPayload(['status' => 'verified'])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->create(g3OrganizerPayload(['verified_by' => 1])))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->create(g3OrganizerPayload(['verified_at' => now()])))
        ->toThrow(InvalidArgumentException::class);

    $organizer = $service->create(g3OrganizerPayload([
        'name' => 'G3 新主体',
    ]));

    expect($organizer->status)->toBe('pending')
        ->and($organizer->verified_by)->toBeNull()
        ->and($organizer->verified_at)->toBeNull()
        ->and($organizer->verification_note)->toBe('初始资料');
});

it('G1-03 · create 拒绝非法平台角色，错误码 EVENT_SOURCE_REQUIRED', function () {
    expect(g3OrganizerErrorCode(
        fn () => g3OrganizerService()->create(g3OrganizerPayload(['platform_role' => 'host']))
    ))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-03 · 来源 URL 只允许合法 http/https，拒绝私网/回环/userinfo/控制字符/超长', function () {
    $service = g3OrganizerService();

    $invalidUrls = [
        'ftp://example.com/events',
        'javascript:alert(1)',
        'data:text/plain,hello',
        'http://localhost/events',
        'http://foo.localhost/events',
        'http://127.0.0.1/events',
        'http://127.1/events',
        'http://10.1.2.3/events',
        'http://192.168.1.10/events',
        'http://169.254.1.1/events',
        'http://[::1]/events',
        'http://[fe80::1]/events',
        'http://user:pass@example.com/events',
        'https://exa mple.com/events',
        "https://example.com/events\n",
        'https://example.com/'.str_repeat('a', 2048),
    ];

    foreach ($invalidUrls as $url) {
        expect(g3OrganizerErrorCode(
            fn () => $service->create(g3OrganizerPayload(['source_url' => $url]))
        ))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED, 'URL 应被拒绝：'.$url);
    }

    $validUrls = [
        'https://example.com/events',
        'http://example.com/activities/42?lang=zh-CN',
        'https://sub.example.com:8443/path',
        null,
        '',
    ];

    foreach ($validUrls as $url) {
        $organizer = $service->create(g3OrganizerPayload(['source_url' => $url]));

        expect($organizer->exists)->toBeTrue();
    }
});

it('G1-03 · verify 缺证据 / 缺 admin 时拒绝', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();

    $noEvidence = $service->create(g3OrganizerPayload([
        'public_materials'     => null,
        'private_evidence_ref' => null,
    ]));

    expect(g3OrganizerErrorCode(fn () => $service->verify((int) $noEvidence->id, $adminId)))
        ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);

    $withEvidence = $service->create(g3OrganizerPayload());

    expect(fn () => $service->verify((int) $withEvidence->id, 9_999_999))
        ->toThrow(InvalidArgumentException::class);

    expect(EcosystemOrganizer::query()->findOrFail($withEvidence->id)->status)->toBe('pending');
});

it('G1-03 · verify 成功：verified + 核验人 + UTC verified_at', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();
    $organizer = $service->create(g3OrganizerPayload());

    $verified = $service->verify((int) $organizer->id, $adminId, '资料齐备，通过');

    expect($verified->status)->toBe('verified')
        ->and($verified->verified_by)->toBe($adminId)
        ->and($verified->verification_note)->toBe('资料齐备，通过')
        ->and($verified->verified_at)->not->toBeNull();

    $raw = DB::table('ecosystem_organizers')->where('id', $organizer->id)->value('verified_at');

    // UtcDateTime 读取按 UTC 解析；若被当成 Asia/Shanghai 会整体偏 8 小时。
    expect($verified->verified_at->format('Y-m-d H:i:s'))->toBe($raw)
        ->and(abs(strtotime($raw.' UTC') - Carbon::now('UTC')->timestamp))->toBeLessThan(10);
});

it('G1-03 · rejected / suspended 可以重新核验为 verified', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();

    $rejected = $service->create(g3OrganizerPayload());
    $service->reject((int) $rejected->id, $adminId, '材料不完整');

    expect($service->verify((int) $rejected->id, $adminId, '补充后通过')->status)->toBe('verified');

    $suspended = $service->create(g3OrganizerPayload());
    $service->verify((int) $suspended->id, $adminId, '首次通过');
    $service->suspend((int) $suspended->id, $adminId, '复核期间暂停');

    expect($service->verify((int) $suspended->id, $adminId, '恢复')->status)->toBe('verified');
});

it('G1-03 · 已核验主体改敏感字段回 pending、清空核验人与时间、保留 note', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();

    $changes = [
        'name'                 => '更名后的主办方',
        'platform_role'        => 'co_organizer',
        'source_url'           => 'https://example.org/new-events',
        'public_materials'     => '更新后的公开资料',
        'private_evidence_ref' => 'evidence/2026/new.pdf',
    ];

    foreach ($changes as $field => $newValue) {
        $organizer = $service->create(g3OrganizerPayload());
        $service->verify((int) $organizer->id, $adminId, '核验通过');

        $service->update($organizer, [$field => $newValue]);

        $fresh = EcosystemOrganizer::query()->findOrFail($organizer->id);

        expect($fresh->status)->toBe('pending')
            ->and($fresh->verified_by)->toBeNull()
            ->and($fresh->verified_at)->toBeNull()
            ->and($fresh->verification_note)->toBe('核验通过')
            ->and($fresh->getAttribute($field))->toBe($newValue);
    }
});

it('G1-03 · 已核验主体只改 verification_note 不触发重新核验', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();
    $organizer = $service->create(g3OrganizerPayload());

    $verified = $service->verify((int) $organizer->id, $adminId, '首次通过');
    $verifiedAt = $verified->verified_at->format('Y-m-d H:i:s');

    $service->update($organizer, ['verification_note' => '补充一条内部备注']);

    $fresh = EcosystemOrganizer::query()->findOrFail($organizer->id);

    expect($fresh->status)->toBe('verified')
        ->and($fresh->verified_by)->toBe($adminId)
        ->and($fresh->verified_at->format('Y-m-d H:i:s'))->toBe($verifiedAt)
        ->and($fresh->verification_note)->toBe('补充一条内部备注');
});

it('G1-03 · reject 只允许 pending 且必须写理由', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();

    $organizer = $service->create(g3OrganizerPayload());

    expect(fn () => $service->reject((int) $organizer->id, $adminId, '   '))
        ->toThrow(InvalidArgumentException::class);

    $rejected = $service->reject((int) $organizer->id, $adminId, '授权书缺失');

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->verified_by)->toBe($adminId)
        ->and($rejected->verified_at)->not->toBeNull()
        ->and($rejected->verification_note)->toBe('授权书缺失');

    // 已 rejected 不能再驳回。
    expect(fn () => $service->reject((int) $organizer->id, $adminId, '再次驳回'))
        ->toThrow(InvalidArgumentException::class);
});

it('G1-03 · suspend 只允许 verified 且必须写理由', function () {
    $service = g3OrganizerService();
    $adminId = g3OrganizerAdminId();

    $pending = $service->create(g3OrganizerPayload());

    expect(fn () => $service->suspend((int) $pending->id, $adminId, '暂停'))
        ->toThrow(InvalidArgumentException::class);

    $organizer = $service->create(g3OrganizerPayload());
    $service->verify((int) $organizer->id, $adminId, '首次通过');

    expect(fn () => $service->suspend((int) $organizer->id, $adminId, ''))
        ->toThrow(InvalidArgumentException::class);

    expect(EcosystemOrganizer::query()->findOrFail($organizer->id)->status)->toBe('verified');

    $suspended = $service->suspend((int) $organizer->id, $adminId, '授权到期');

    expect($suspended->status)->toBe('suspended')
        ->and($suspended->verified_by)->toBe($adminId)
        ->and($suspended->verified_at)->not->toBeNull()
        ->and($suspended->verification_note)->toBe('授权到期');
});

it('G1-03 · update 不接受核验字段，且不提供 delete', function () {
    $service = g3OrganizerService();
    $organizer = $service->create(g3OrganizerPayload());

    foreach (['status', 'verified_by', 'verified_at'] as $field) {
        expect(fn () => $service->update($organizer, [$field => 'x']))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(method_exists(OrganizerService::class, 'delete'))->toBeFalse();
});
