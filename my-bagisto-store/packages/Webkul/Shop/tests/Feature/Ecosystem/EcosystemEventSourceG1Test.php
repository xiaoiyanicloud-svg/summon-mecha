<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\EventSourceService;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\EventSourceValidator;
use Webkul\Shop\Services\Ecosystem\EventWriteService;

use function Pest\Laravel\getJson;

/**
 * G1-03 · 来源三字段校验与写入口（ADR-0001 G1-D01 / G1-D05 / §4.2 / §9）。
 *
 * 保护的是**发布前置与草稿边界**：
 *   · 三字段必须同时为空或同时齐全；
 *   · external ⇒ directory_only；platform ⇒ 不得 directory_only；
 *   · organizer 必须存在且角色一致；rejected/suspended 不得继续挂草稿；
 *   · 发布额外要求 organizer verified、官方来源 URL 合法、且命中 B-01/B-03 放行组合；
 *   · assign 走 EventWriteService，真实变更才递增 row_version；
 *   · 迁移后公开接口行为不变（空列表 / 404）。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-03 event source tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g3SourceValidator(): EventSourceValidator
{
    return app(EventSourceValidator::class);
}

function g3SourceService(): EventSourceService
{
    return app(EventSourceService::class);
}

function g3SourceAdminId(): int
{
    return DB::table('admins')->insertGetId([
        'name'       => 'G3 Source Reviewer',
        'email'      => 'g3-source-'.bin2hex(random_bytes(8)).'@example.test',
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
 */
function g3SourceOrganizer(array $overrides = []): EcosystemOrganizer
{
    return EcosystemOrganizer::create(array_merge([
        'name'          => 'G3 来源主办方 '.bin2hex(random_bytes(3)),
        'platform_role' => 'organizer',
        'source_url'    => 'https://example.com/events',
        'public_materials' => '官网',
        'status'        => 'pending',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g3SourceEvent(array $overrides = []): Event
{
    return Event::create(array_merge(['name' => 'G3 来源活动'], $overrides));
}

function g3SourceErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (EventSourceValidationException $exception) {
        return $exception->errorCode;
    }

    return null;
}

it('G1-03 · 来源三字段全空允许草稿；发布时缺失来源则拒绝', function () {
    $event = g3SourceEvent();

    expect(fn () => g3SourceValidator()->assertDraftAssignment($event))
        ->not->toThrow(EventSourceValidationException::class);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertPublishable($event)))
        ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-03 · 任意一个来源字段单独出现都视为不完整', function () {
    $organizer = g3SourceOrganizer();

    $partials = [
        ['source_type' => 'platform'],
        ['platform_role' => 'organizer'],
        ['organizer_id' => (int) $organizer->id],
    ];

    foreach ($partials as $partial) {
        $event = g3SourceEvent($partial);

        expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertDraftAssignment($event)))
            ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED, '不完整来源应被拒绝：'.json_encode($partial));
    }
});

it('G1-03 · 未知枚举与非法的来源/角色组合被拒绝', function () {
    $organizer = g3SourceOrganizer();

    $invalidSources = [
        ['source_type' => 'internal', 'platform_role' => 'organizer', 'organizer_id' => (int) $organizer->id],
        ['source_type' => 'platform', 'platform_role' => 'host', 'organizer_id' => (int) $organizer->id],
        ['source_type' => 'external', 'platform_role' => 'organizer', 'organizer_id' => (int) $organizer->id],
        ['source_type' => 'platform', 'platform_role' => 'directory_only', 'organizer_id' => (int) $organizer->id],
    ];

    foreach ($invalidSources as $source) {
        $event = g3SourceEvent($source);

        expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertDraftAssignment($event)))
            ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
    }
});

it('G1-03 · organizer 不存在或角色与活动声明不一致时拒绝', function () {
    // FK 会在落库阶段直接拒绝不存在的 organizer_id（1452），
    // 因此这里用未持久化的模型只验证 validator 自身的“主体不存在”分支。
    $missing = new Event([
        'name'          => 'G3 来源活动',
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => 9_999_999,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertDraftAssignment($missing)))
        ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);

    $organizer = g3SourceOrganizer(['platform_role' => 'organizer']);

    $mismatch = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'co_organizer',
        'organizer_id'  => (int) $organizer->id,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertDraftAssignment($mismatch)))
        ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-03 · pending organizer 允许进入草稿', function () {
    $organizer = g3SourceOrganizer(['status' => 'pending']);

    $event = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => (int) $organizer->id,
    ]);

    expect(fn () => g3SourceValidator()->assertDraftAssignment($event))
        ->not->toThrow(EventSourceValidationException::class);
});

it('G1-03 · rejected / suspended organizer 不得继续挂草稿', function () {
    foreach (['rejected', 'suspended'] as $status) {
        $organizer = g3SourceOrganizer(['status' => $status]);

        $event = g3SourceEvent([
            'source_type'   => 'platform',
            'platform_role' => 'organizer',
            'organizer_id'  => (int) $organizer->id,
        ]);

        expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertDraftAssignment($event)))
            ->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED, $status);
    }
});

it('G1-03 · 发布要求 organizer 已核验', function () {
    $organizer = g3SourceOrganizer(['status' => 'pending']);

    $event = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => (int) $organizer->id,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertPublishable($event)))
        ->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED);
});

it('G1-03 · B-01/B-03 未签署前只放行 platform + organizer', function () {
    $coOrganizer = g3SourceOrganizer([
        'platform_role' => 'co_organizer',
        'status'        => 'verified',
    ]);

    $platformCoOrganizer = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'co_organizer',
        'organizer_id'  => (int) $coOrganizer->id,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertPublishable($platformCoOrganizer)))
        ->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED);

    $directory = g3SourceOrganizer([
        'platform_role' => 'directory_only',
        'status'        => 'verified',
    ]);

    $external = g3SourceEvent([
        'source_type'   => 'external',
        'platform_role' => 'directory_only',
        'organizer_id'  => (int) $directory->id,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertPublishable($external)))
        ->toBe(ApiErrorCode::EVENT_ORGANIZER_UNVERIFIED);
});

it('G1-03 · 发布要求官方来源 URL 非空且合法', function () {
    $withoutUrl = g3SourceOrganizer([
        'source_url' => null,
        'status'     => 'verified',
    ]);

    $missingUrl = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => (int) $withoutUrl->id,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertPublishable($missingUrl)))
        ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);

    $badUrl = g3SourceOrganizer([
        'source_url' => 'http://127.0.0.1/events',
        'status'     => 'verified',
    ]);

    $withBadUrl = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => (int) $badUrl->id,
    ]);

    expect(g3SourceErrorCode(fn () => g3SourceValidator()->assertPublishable($withBadUrl)))
        ->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-03 · 合法的 platform + organizer 通过发布前置', function () {
    $organizer = g3SourceOrganizer([
        'source_url' => 'https://example.com/official-events',
        'status'     => 'verified',
    ]);

    $event = g3SourceEvent([
        'source_type'   => 'platform',
        'platform_role' => 'organizer',
        'organizer_id'  => (int) $organizer->id,
    ]);

    expect(fn () => g3SourceValidator()->assertPublishable($event))
        ->not->toThrow(EventSourceValidationException::class);
});

it('G1-03 · EventWriteService 直接写入半个来源会抛错且不落库、不推高版本', function () {
    $event = g3SourceEvent();

    $code = g3SourceErrorCode(fn () => app(EventWriteService::class)->update($event, 0, [
        'source_type' => 'platform',
    ]));

    expect($code)->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);

    $fresh = Event::query()->findOrFail($event->getKey());

    expect($fresh->source_type)->toBeNull()
        ->and($fresh->platform_role)->toBeNull()
        ->and($fresh->organizer_id)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(0);
});

it('G1-03 · assign 走 EventWriteService：真实变更递增 row_version，重复提交 no-op', function () {
    $organizer = g3SourceOrganizer(['status' => 'pending']);
    $event = g3SourceEvent();

    $updated = g3SourceService()->assign(
        $event,
        0,
        'platform',
        'organizer',
        (int) $organizer->id,
    );

    expect((int) $updated->row_version)->toBe(1)
        ->and($updated->source_type)->toBe('platform')
        ->and($updated->platform_role)->toBe('organizer')
        ->and($updated->organizer_id)->toBe((int) $organizer->id);

    $fresh = Event::query()->findOrFail($event->getKey());

    expect((int) $fresh->row_version)->toBe(1)
        ->and($fresh->organizer_id)->toBe((int) $organizer->id);

    // 相同三字段再次提交：不推高版本。
    $noop = g3SourceService()->assign($updated, 1, 'platform', 'organizer', (int) $organizer->id);

    expect((int) $noop->row_version)->toBe(1);
});

it('G1-03 · assign 非法组合在进入写事务前就被拒绝', function () {
    $organizer = g3SourceOrganizer();
    $event = g3SourceEvent();

    $code = g3SourceErrorCode(fn () => g3SourceService()->assign(
        $event,
        0,
        'external',
        'organizer',
        (int) $organizer->id,
    ));

    expect($code)->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);

    $fresh = Event::query()->findOrFail($event->getKey());

    expect($fresh->source_type)->toBeNull()
        ->and((int) $fresh->row_version)->toBe(0);
});

it('G1-03 · 迁移后公开接口仍为空列表 / 404', function () {
    g3SourceEvent(['name' => '迁移后仍应隐藏的活动']);

    getJson('/api/events')
        ->assertOk()
        ->assertExactJson(['data' => []]);

    getJson('/api/events/1')
        ->assertNotFound()
        ->assertJson([
            'success' => false,
            'code'    => ApiErrorCode::EVENT_NOT_FOUND,
        ]);
});
