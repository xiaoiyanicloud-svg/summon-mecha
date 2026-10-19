<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\EcosystemOrganizer;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRevision;
use Webkul\Shop\Services\Ecosystem\ContentFingerprint;
use Webkul\Shop\Services\Ecosystem\EventRevisionService;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\EventTimeValidationException;
use Webkul\Shop\Services\Ecosystem\RevisionConflictException;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-04 · EventRevisionService 行为守卫（ADR-0001 §4.3 / G1-D04）。
 *
 * 保护的是**版本产生与编辑边界**：
 *   · revision_no 在活动行锁内从 1 递增；
 *   · 指纹服务端计算且稳定：同内容同指纹，任一内容字段变化指纹即变；
 *   · 来源快照必须与活动当前来源一致，主办方快照必须等于主体当前名称；
 *   · 时间（IANA 时区、起止同空或同有、ends >= starts）与 URL/枚举严格校验；
 *   · 本批 media_manifest_hash 由媒体服务维护，sanitizer_version 由服务端写入；
 *   · updateDraft 只改内容字段、draft-only、expectedFingerprint CAS；
 *   · 非 draft 后服务与模型双层拒绝内容修改。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-04 event revision service tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g4SvcService(): EventRevisionService
{
    return app(EventRevisionService::class);
}

function g4SvcFingerprint(): ContentFingerprint
{
    return app(ContentFingerprint::class);
}

function g4SvcAdminId(): int
{
    return DB::table('admins')->insertGetId([
        'name'       => 'G4 Revision Reviewer',
        'email'      => 'g4-revision-'.bin2hex(random_bytes(8)).'@example.test',
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
function g4SvcOrganizer(array $overrides = []): EcosystemOrganizer
{
    return EcosystemOrganizer::create(array_merge([
        'name'             => 'G4 修订主办方 '.bin2hex(random_bytes(3)),
        'platform_role'    => 'organizer',
        'source_url'       => 'https://example.com/events',
        'public_materials' => '官网',
        'status'           => 'pending',
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function g4SvcEvent(EcosystemOrganizer $organizer, array $overrides = []): Event
{
    return Event::create(array_merge([
        'name'          => 'G4 修订活动 '.bin2hex(random_bytes(3)),
        'source_type'   => 'platform',
        'platform_role' => $organizer->platform_role,
        'organizer_id'  => (int) $organizer->id,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g4SvcPayload(EcosystemOrganizer $organizer, array $overrides = []): array
{
    return array_merge([
        'name'                    => 'G4 活动公开名称',
        'summary'                 => '活动摘要',
        'detail_html'             => '<p>活动正文</p>',
        'city'                    => '上海',
        'location_text'           => '张江科学会堂',
        'participation_mode'      => 'offline',
        'timezone'                => 'Asia/Shanghai',
        'starts_at'               => '2026-11-01 10:00:00',
        'ends_at'                 => '2026-11-01 18:00:00',
        'source_type'             => 'platform',
        'platform_role'           => $organizer->platform_role,
        'organizer_id'            => (int) $organizer->id,
        'organizer_name_snapshot' => $organizer->name,
        'source_name'             => '官网活动页',
        'source_url'              => 'https://example.com/events',
        'authorization_basis'     => 'self_organized',
        'external_registration_url' => null,
        'rule_summary'            => '规则摘要',
        'change_summary'          => '初始版本',
    ], $overrides);
}

function g4SvcSourceErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (EventSourceValidationException $exception) {
        return $exception->errorCode;
    }

    return null;
}

it('G1-04 · createDraft 生成 revision_no 1/2，强制 draft 与服务端字段', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $first = $service->createDraft($event, $adminId, g4SvcPayload($organizer));
    $second = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'name'           => 'G4 活动公开名称 v2',
        'change_summary' => '第二版',
    ]));

    expect((int) $first->revision_no)->toBe(1)
        ->and((int) $second->revision_no)->toBe(2)
        ->and((string) $first->review_state)->toBe('draft')
        ->and((string) $second->review_state)->toBe('draft')
        ->and((int) $first->created_by)->toBe($adminId)
        ->and($first->media_manifest_hash)->toBeNull()
        ->and((string) $first->sanitizer_version)->toBe((string) config('ecosystem_rich_text.sanitizer_version'))
        ->and(strlen((string) $first->content_fingerprint))->toBe(64);

    expect(g4SvcFingerprint()->eventRevision($first->fresh()))->toBe((string) $first->content_fingerprint);
});

it('G1-04 · 相同内容指纹稳定，等价时区表示同样稳定；内容变化指纹即变', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $first = $service->createDraft($event, $adminId, g4SvcPayload($organizer));
    $same = $service->createDraft($event, $adminId, g4SvcPayload($organizer));
    $equivalentOffset = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'starts_at' => Carbon::parse('2026-11-01T10:00:00+08:00'),
        'ends_at'   => Carbon::parse('2026-11-01T18:00:00+08:00'),
    ]));
    $changed = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'summary' => '换了摘要',
    ]));

    expect((string) $same->content_fingerprint)->toBe((string) $first->content_fingerprint)
        ->and((string) $equivalentOffset->content_fingerprint)->toBe((string) $first->content_fingerprint)
        ->and((string) $changed->content_fingerprint)->not->toBe((string) $first->content_fingerprint);
});

it('G1-04 · createDraft 拒绝不存在的 admin 与未保存活动', function () {
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);

    expect(fn () => g4SvcService()->createDraft($event, 999999999, g4SvcPayload($organizer)))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => g4SvcService()->createDraft(new Event(['name' => '未保存']), g4SvcAdminId(), g4SvcPayload($organizer)))
        ->toThrow(InvalidArgumentException::class);
});

it('G1-04 · 活动缺来源时 createDraft 抛 EVENT_SOURCE_REQUIRED', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = Event::create(['name' => 'G4 无来源活动']);

    expect(g4SvcSourceErrorCode(
        fn () => g4SvcService()->createDraft($event, $adminId, g4SvcPayload($organizer))
    ))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-04 · 修订来源必须与活动当前三字段一致', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer, ['source_type' => 'platform', 'platform_role' => 'organizer']);

    expect(g4SvcSourceErrorCode(fn () => g4SvcService()->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'source_type'   => 'external',
        'platform_role' => 'directory_only',
    ]))))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-04 · organizer_name_snapshot 必须等于主办方当前名称', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);

    expect(g4SvcSourceErrorCode(fn () => g4SvcService()->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'organizer_name_snapshot' => '另一个主办方',
    ]))))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-04 · 活动引用的主体角色不一致时 createDraft 被来源校验拒绝', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer(['platform_role' => 'organizer']);

    // 活动自身的三字段不一致（模拟绕过 EventSourceService 的历史数据），
    // 修订即使原样镜像活动，也必须被 assertDraftValues 的角色一致规则挡住。
    $event = g4SvcEvent($organizer, ['platform_role' => 'co_organizer']);

    expect(g4SvcSourceErrorCode(fn () => g4SvcService()->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'platform_role' => 'co_organizer',
    ]))))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);
});

it('G1-04 · timezone 必须是 IANA 名称，拒绝偏移写法', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);

    foreach (['+08:00', 'Asia/Shanghai+8', 'Not/AZone'] as $timezone) {
        expect(fn () => g4SvcService()->createDraft($event, $adminId, g4SvcPayload($organizer, [
            'timezone' => $timezone,
        ])))->toThrow(EventTimeValidationException::class);
    }

    // 空值属于“缺必填字段”，与非法时区分开。
    expect(fn () => g4SvcService()->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'timezone' => '',
    ])))->toThrow(InvalidArgumentException::class);
});

it('G1-04 · starts_at / ends_at 必须同空或同有，且 ends >= starts', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    expect(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, ['ends_at' => null])))
        ->toThrow(EventTimeValidationException::class)
        ->and(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
            'starts_at' => '2026-11-01 18:00:00',
            'ends_at'   => '2026-11-01 10:00:00',
        ])))->toThrow(EventTimeValidationException::class)
        ->and(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
            'starts_at' => 'not-a-date',
        ])))->toThrow(EventTimeValidationException::class);

    $pending = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'starts_at' => null,
        'ends_at'   => null,
    ]));

    expect($pending->starts_at)->toBeNull()
        ->and($pending->ends_at)->toBeNull();
});

it('G1-04 · source_url / external_registration_url 只接受合法公开 URL', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);

    foreach ([
        'source_url'                => 'ftp://example.com/events',
        'source_url_private'        => 'http://127.0.0.1/events',
        'external_registration_url' => 'javascript:alert(1)',
        'external_private'          => 'http://192.168.1.10/register',
    ] as $case => $url) {
        $overrides = match ($case) {
            'source_url', 'source_url_private' => ['source_url' => $url],
            default => ['external_registration_url' => $url],
        };

        expect(g4SvcSourceErrorCode(fn () => g4SvcService()->createDraft(
            $event,
            $adminId,
            g4SvcPayload($organizer, $overrides)
        )))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED, 'URL 应被拒绝：'.$url);
    }
});

it('G1-04 · participation_mode 与 authorization_basis 只接受注册表枚举', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    expect(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'participation_mode' => 'remote',
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
            'authorization_basis' => 'self_claimed',
        ])))->toThrow(InvalidArgumentException::class);

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'participation_mode'  => 'hybrid',
        'authorization_basis' => 'written_authorization',
    ]));

    expect((string) $revision->participation_mode)->toBe('hybrid')
        ->and((string) $revision->authorization_basis)->toBe('written_authorization');
});

it('G1-04/07 · sanitizer_version 服务端写入，media_manifest_hash 调用方不可写', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();
    $version = (string) config('ecosystem_rich_text.sanitizer_version');

    expect(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'media_manifest_hash' => str_repeat('a', 64),
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
            'sanitizer_version' => 'v1',
        ])))->toThrow(InvalidArgumentException::class);

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'media_manifest_hash' => null,
        'sanitizer_version'   => null,
    ]));

    expect($revision->media_manifest_hash)->toBeNull()
        ->and((string) $revision->sanitizer_version)->toBe($version);

    expect(fn () => $service->updateDraft($revision, (string) $revision->content_fingerprint, [
        'sanitizer_version' => 'v1',
    ]))->toThrow(InvalidArgumentException::class);
});

it('G1-04/07 · createDraft 净化 detail_html：脚本 / 事件属性 / 远程图片被移除', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
        'detail_html' => '<p onclick="alert(1)">活动正文</p>'
            .'<script>alert(2)</script>'
            .'<a href="javascript:alert(3)">坏链接</a>'
            .'<img src="https://evil.example/x.png" data-media-id="1">',
    ]));

    $html = (string) $revision->fresh()->detail_html;

    expect($html)->toContain('活动正文')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('onclick')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('<img')
        ->and((string) $revision->sanitizer_version)->toBe((string) config('ecosystem_rich_text.sanitizer_version'));
});

it('G1-04/07 · updateDraft 保留既有 media_manifest_hash，不清空', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));

    $manifest = str_repeat('c', 64);
    DB::table('event_revisions')->where('id', $revision->getKey())->update([
        'media_manifest_hash' => $manifest,
    ]);

    $fresh = EventRevision::query()->findOrFail($revision->getKey());
    $updated = $service->updateDraft($fresh, (string) $fresh->content_fingerprint, [
        'summary' => '只改摘要',
    ]);

    expect((string) $updated->fresh()->media_manifest_hash)->toBe($manifest)
        ->and((string) $updated->sanitizer_version)->toBe((string) config('ecosystem_rich_text.sanitizer_version'));
});

it('G1-04 · 调用方不得传 content_fingerprint、审核字段或版本字段', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    foreach ([
        'content_fingerprint' => str_repeat('f', 64),
        'review_state'        => 'in_review',
        'revision_no'         => 9,
        'created_by'          => 1,
        'submitted_by'        => 1,
        'published_at'        => now(),
    ] as $field => $value) {
        expect(fn () => $service->createDraft($event, $adminId, g4SvcPayload($organizer, [
            $field => $value,
        ])))->toThrow(InvalidArgumentException::class);
    }
});

it('G1-04 · 必填字段缺失时 createDraft 拒绝', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    foreach ([
        'name',
        'timezone',
        'source_type',
        'platform_role',
        'organizer_name_snapshot',
        'source_name',
        'change_summary',
    ] as $field) {
        $payload = g4SvcPayload($organizer);
        unset($payload[$field]);

        expect(fn () => $service->createDraft($event, $adminId, $payload))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('G1-04 · updateDraft 修改内容并重算指纹，版本号与 draft 状态不变', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));
    $originalFingerprint = (string) $revision->content_fingerprint;

    $updated = $service->updateDraft($revision, $originalFingerprint, [
        'name'      => 'G4 改后的名称',
        'starts_at' => '2026-11-02 09:00:00',
        'ends_at'   => '2026-11-02 17:00:00',
    ]);

    expect((string) $updated->name)->toBe('G4 改后的名称')
        ->and((int) $updated->revision_no)->toBe(1)
        ->and((string) $updated->review_state)->toBe('draft')
        ->and((string) $updated->content_fingerprint)->not->toBe($originalFingerprint)
        ->and($updated->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-02 01:00:00')
        ->and(g4SvcFingerprint()->eventRevision($updated->fresh()))->toBe((string) $updated->content_fingerprint);
});

it('G1-04 · updateDraft 指纹过期抛 EVENT_REVISION_CONFLICT', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));

    $error = null;

    try {
        $service->updateDraft($revision, str_repeat('0', 64), ['name' => '不应落库']);
    } catch (RevisionConflictException $exception) {
        $error = $exception;
    }

    expect($error)->not->toBeNull()
        ->and($error->errorCode)->toBe(ApiErrorCode::EVENT_REVISION_CONFLICT)
        ->and($error->expectedFingerprint)->toBe(str_repeat('0', 64))
        ->and($error->actualFingerprint)->toBe((string) $revision->content_fingerprint)
        ->and((string) EventRevision::query()->findOrFail($revision->id)->name)->not->toBe('不应落库');
});

it('G1-04 · 非 draft 修订：服务与模型都拒绝内容修改', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));

    DB::table('event_revisions')
        ->where('id', $revision->id)
        ->update(['review_state' => 'in_review']);

    $fresh = EventRevision::query()->findOrFail($revision->id);

    expect(fn () => $service->updateDraft($fresh, (string) $fresh->content_fingerprint, [
        'name' => '审核后改名',
    ]))->toThrow(RevisionImmutableException::class);

    // 直接绕过服务改模型也必须被持久化守卫挡住。
    $fresh->name = '审核后改名';
    expect(fn () => $fresh->save())->toThrow(RevisionImmutableException::class);
});

it('G1-04 · updateDraft 只接受内容字段', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));

    foreach ([
        'content_fingerprint' => str_repeat('f', 64),
        'review_state'        => 'approved',
        'revision_no'         => 99,
        'event_id'            => (int) $event->id,
        'created_by'          => 1,
    ] as $field => $value) {
        expect(fn () => $service->updateDraft($revision, (string) $revision->content_fingerprint, [
            $field => $value,
        ]))->toThrow(InvalidArgumentException::class);
    }
});

it('G1-04 · 主办方改名后，updateDraft 只改无关内容成功且保留历史快照', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));
    $historicalSnapshot = (string) $revision->organizer_name_snapshot;

    $organizer->name = 'G4 改名后的主办方';
    $organizer->save();

    // 未显式触碰来源/主办方字段：不得因主体改名而阻塞无关内容编辑。
    $updated = $service->updateDraft($revision, (string) $revision->content_fingerprint, [
        'summary' => '改名后只改摘要',
    ]);

    expect((string) $updated->summary)->toBe('改名后只改摘要')
        ->and((string) $updated->organizer_name_snapshot)->toBe($historicalSnapshot)
        ->and((string) EventRevision::query()->findOrFail($revision->id)->organizer_name_snapshot)
        ->toBe($historicalSnapshot);
});

it('G1-04 · 主办方改名后，显式把快照更新为新名成功', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));

    $organizer->name = 'G4 改名后的主办方';
    $organizer->save();

    $updated = $service->updateDraft($revision, (string) $revision->content_fingerprint, [
        'organizer_name_snapshot' => (string) $organizer->name,
    ]);

    expect((string) $updated->organizer_name_snapshot)->toBe('G4 改名后的主办方');
});

it('G1-04 · 显式写入错误的主办方快照仍抛 EVENT_SOURCE_REQUIRED 且不落库', function () {
    $adminId = g4SvcAdminId();
    $organizer = g4SvcOrganizer();
    $event = g4SvcEvent($organizer);
    $service = g4SvcService();

    $revision = $service->createDraft($event, $adminId, g4SvcPayload($organizer));
    $originalSnapshot = (string) $revision->organizer_name_snapshot;

    expect(g4SvcSourceErrorCode(fn () => $service->updateDraft(
        $revision,
        (string) $revision->content_fingerprint,
        ['organizer_name_snapshot' => '冒名主办方'],
    )))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED);

    expect((string) EventRevision::query()->findOrFail($revision->id)->organizer_name_snapshot)
        ->toBe($originalSnapshot);
});
