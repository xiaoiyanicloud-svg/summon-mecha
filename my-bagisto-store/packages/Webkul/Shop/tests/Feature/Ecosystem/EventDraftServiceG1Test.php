<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Services\Ecosystem\EventDraftService;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\EventTimeValidationException;

/**
 * G1 Admin 新建活动草稿服务。
 *
 * 保护的是**唯一的新活动写入口**：
 *   · 只接受 G1 必填字段；发布轴 / 处置轴 / 指针 / 行版本 / 旧展示列不可透传；
 *   · 初始结论固定 draft + normal + row_version=0，且没有任何 revision；
 *   · 来源三字段必须同时齐全，并复用 EventSourceValidator 的组合 / 主体校验；
 *   · 时区必须是 IANA，起止时间按活动时区解释后以 UTC 落库；
 *   · 创建与审计同事务（OperationLogger::logOrFail）。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1 Admin event draft tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g1DraftAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G1 Draft Admin',
        'email'      => 'g1-draft-'.bin2hex(random_bytes(8)).'@example.test',
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
function g1DraftOrganizer(array $overrides = []): EcosystemOrganizer
{
    return EcosystemOrganizer::create(array_merge([
        'name'             => 'G1 新建活动主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'pending',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g1DraftPayload(int $organizerId, array $overrides = []): array
{
    return array_merge([
        'name'               => 'G1 新建活动 '.bin2hex(random_bytes(3)),
        'kind'               => 'salon',
        'participation_mode' => 'offline',
        'hosting_status'     => 'scheduled',
        'timezone'           => 'Asia/Shanghai',
        'starts_at'          => '2026-11-01T09:00',
        'ends_at'            => '2026-11-01T18:00',
        'source_type'        => 'platform',
        'platform_role'      => 'organizer',
        'organizer_id'       => $organizerId,
    ], $overrides);
}

function g1DraftService(): EventDraftService
{
    return app(EventDraftService::class);
}

it('G1 Admin · 新建活动固定为草稿壳：默认值、来源、UTC 时间与审计', function () {
    $adminId = g1DraftAdminId();
    $organizer = g1DraftOrganizer();

    $event = g1DraftService()->createDraft($adminId, g1DraftPayload((int) $organizer->id));

    expect((string) $event->publish_status)->toBe('draft')
        ->and((string) $event->disposition)->toBe('normal')
        ->and((int) $event->row_version)->toBe(0)
        ->and($event->published_revision_id)->toBeNull()
        ->and($event->working_revision_id)->toBeNull()
        ->and($event->approved_revision_id)->toBeNull()
        ->and($event->scheduled_publish_at)->toBeNull()
        ->and($event->first_published_at)->toBeNull()
        ->and($event->published_at)->toBeNull()
        ->and((string) $event->kind)->toBe('salon')
        ->and((string) $event->hosting_status)->toBe('scheduled')
        ->and((string) $event->source_type)->toBe('platform')
        ->and((string) $event->platform_role)->toBe('organizer')
        ->and((int) $event->organizer_id)->toBe((int) $organizer->id);

    // 旧展示列只做兼容默认值，不接受调用方透传。
    expect((string) $event->type)->toBe('生态活动')
        ->and((string) $event->status)->toBe('upcoming')
        ->and((int) $event->hot)->toBe(0)
        ->and((int) $event->teams)->toBe(0)
        ->and((string) $event->organizer)->toBe((string) $organizer->name)
        ->and($event->city)->toBeNull()
        ->and($event->location)->toBeNull()
        ->and($event->date)->toBeNull();

    // 时间按活动时区解释，UTC 落库（09:00 Asia/Shanghai = 01:00 UTC）。
    expect($event->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 01:00:00')
        ->and($event->ends_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 10:00:00');

    // 没有任何 revision，不能直接发布。
    expect(DB::table('event_revisions')->where('event_id', $event->id)->count())->toBe(0);

    $log = DB::table('operation_log')
        ->where('action', 'ecosystem.event.create')
        ->where('actor_type', 'admin')
        ->where('actor_id', $adminId)
        ->where('target_type', 'event')
        ->where('target_id', $event->id)
        ->first();

    expect($log)->not->toBeNull();

    $payload = json_decode((string) $log->payload, true);

    expect($payload)->toMatchArray([
        'to'          => 'draft',
        'reason_code' => 'created',
    ]);
});

it('G1 Admin · 新建活动拒绝越权字段与非法管理员', function () {
    $adminId = g1DraftAdminId();
    $organizer = g1DraftOrganizer();

    $forbidden = [
        'publish_status'        => 'published',
        'disposition'           => 'retracted',
        'row_version'           => 99,
        'published_revision_id' => 1,
        'type'                  => '旧类型',
    ];

    foreach ($forbidden as $field => $value) {
        expect(fn () => g1DraftService()->createDraft(
            $adminId,
            g1DraftPayload((int) $organizer->id, [$field => $value]),
        ))->toThrow(InvalidArgumentException::class, $field);
    }

    expect(fn () => g1DraftService()->createDraft(999999, g1DraftPayload((int) $organizer->id)))
        ->toThrow(InvalidArgumentException::class);

    expect(Event::query()->count())->toBe(0);
});

it('G1 Admin · 新建活动校验必填、枚举、时区与起止时间', function () {
    $adminId = g1DraftAdminId();
    $organizer = g1DraftOrganizer();
    $base = g1DraftPayload((int) $organizer->id);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['name' => '   '])))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['kind' => 'unknown'])))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['participation_mode' => 'unknown'])))
        ->toThrow(InvalidArgumentException::class);

    // B-06 未签署：postponed 等写能力关闭。
    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['hosting_status' => 'postponed'])))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['timezone' => '+08:00'])))
        ->toThrow(EventTimeValidationException::class);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['starts_at' => null])))
        ->toThrow(EventTimeValidationException::class);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, [
        'starts_at' => '2026-11-01T18:00',
        'ends_at'   => '2026-11-01T09:00',
    ])))->toThrow(EventTimeValidationException::class);

    expect(Event::query()->count())->toBe(0);
});

it('G1 Admin · 新建活动要求来源三字段齐全且主办方角色一致', function () {
    $adminId = g1DraftAdminId();
    $organizer = g1DraftOrganizer();

    $base = g1DraftPayload((int) $organizer->id);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['organizer_id' => null])))
        ->toThrow(EventSourceValidationException::class);

    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['source_type' => null])))
        ->toThrow(EventSourceValidationException::class);

    // 角色与主体不一致。
    expect(fn () => g1DraftService()->createDraft($adminId, array_merge($base, ['platform_role' => 'co_organizer'])))
        ->toThrow(EventSourceValidationException::class);

    // rejected / suspended 主体不得继续挂新草稿。
    foreach (['rejected', 'suspended'] as $status) {
        $blocked = g1DraftOrganizer(['status' => $status]);

        expect(fn () => g1DraftService()->createDraft($adminId, g1DraftPayload((int) $blocked->id)))
            ->toThrow(EventSourceValidationException::class, $status);
    }

    // external 只允许 directory_only。
    $directory = g1DraftOrganizer(['platform_role' => 'directory_only']);

    expect(fn () => g1DraftService()->createDraft($adminId, g1DraftPayload((int) $directory->id, [
        'source_type'   => 'external',
        'platform_role' => 'organizer',
    ])))->toThrow(EventSourceValidationException::class);

    expect(Event::query()->count())->toBe(0);
});
