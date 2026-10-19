<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Http\Controllers\API\ApiErrorCode;
use Webkul\Shop\Models\Event;
use Webkul\Shop\Models\EventRuleVersion;
use Webkul\Shop\Services\Ecosystem\ContentFingerprint;
use Webkul\Shop\Services\Ecosystem\EventRuleVersionService;
use Webkul\Shop\Services\Ecosystem\EventSourceValidationException;
use Webkul\Shop\Services\Ecosystem\EventTimeValidationException;
use Webkul\Shop\Services\Ecosystem\RevisionConflictException;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-04 · EventRuleVersionService 行为守卫（ADR-0001 §4.5 / G1-D04）。
 *
 * 保护的是**规则版本的权威语义**：
 *   · version_no 在活动行锁内从 1 递增；
 *   · body_html 与 external_url 至少一项，外链必须通过公开 URL 校验；
 *   · authority_language 必须是 BCP 47 形态；
 *   · file_media_id 本批必须 NULL（G1-06 才有媒体表）；
 *   · 指纹服务端计算，同内容同指纹、内容变化指纹即变；
 *   · updateDraft 只改内容字段、draft-only、expectedFingerprint CAS；
 *   · 非 draft 后服务与模型双层拒绝内容修改。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-04 rule version service tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g4RuleService(): EventRuleVersionService
{
    return app(EventRuleVersionService::class);
}

function g4RuleFingerprint(): ContentFingerprint
{
    return app(ContentFingerprint::class);
}

function g4RuleAdminId(): int
{
    return DB::table('admins')->insertGetId([
        'name'       => 'G4 Rule Reviewer',
        'email'      => 'g4-rule-'.bin2hex(random_bytes(8)).'@example.test',
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
function g4RuleEvent(array $overrides = []): Event
{
    return Event::create(array_merge([
        'name' => 'G4 规则活动 '.bin2hex(random_bytes(3)),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function g4RulePayload(array $overrides = []): array
{
    return array_merge([
        'title'              => 'G4 活动规则',
        'authority_language' => 'zh-CN',
        'body_html'          => '<p>规则正文</p>',
        'external_url'       => 'https://example.com/rules',
        'file_media_id'      => null,
        'effective_from'     => '2026-11-01 00:00:00',
        'change_summary'     => '初始规则',
    ], $overrides);
}

function g4RuleSourceErrorCode(callable $callback): ?string
{
    try {
        $callback();
    } catch (EventSourceValidationException $exception) {
        return $exception->errorCode;
    }

    return null;
}

it('G1-04 · createDraft 生成 version_no 1/2，强制 draft 与服务端字段', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    $first = $service->createDraft($event, $adminId, g4RulePayload());
    $second = $service->createDraft($event, $adminId, g4RulePayload([
        'title'          => 'G4 活动规则 v2',
        'change_summary' => '第二版',
    ]));

    expect((int) $first->version_no)->toBe(1)
        ->and((int) $second->version_no)->toBe(2)
        ->and((string) $first->status)->toBe('draft')
        ->and((string) $second->status)->toBe('draft')
        ->and((int) $first->created_by)->toBe($adminId)
        ->and($first->file_media_id)->toBeNull()
        ->and(strlen((string) $first->content_fingerprint))->toBe(64);

    expect(g4RuleFingerprint()->ruleVersion($first->fresh()))->toBe((string) $first->content_fingerprint);
});

it('G1-04 · 规则版本指纹稳定；正文变化指纹即变', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    $first = $service->createDraft($event, $adminId, g4RulePayload());
    $same = $service->createDraft($event, $adminId, g4RulePayload());
    $changed = $service->createDraft($event, $adminId, g4RulePayload([
        'body_html' => '<p>换了一段完全不同的规则正文</p>',
    ]));

    expect((string) $same->content_fingerprint)->toBe((string) $first->content_fingerprint)
        ->and((string) $changed->content_fingerprint)->not->toBe((string) $first->content_fingerprint);
});

it('G1-04 · createDraft 拒绝不存在的 admin 与未保存活动', function () {
    $event = g4RuleEvent();

    expect(fn () => g4RuleService()->createDraft($event, 999999999, g4RulePayload()))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => g4RuleService()->createDraft(new Event(['name' => '未保存']), g4RuleAdminId(), g4RulePayload()))
        ->toThrow(InvalidArgumentException::class);
});

it('G1-04 · 必填字段缺失时 createDraft 拒绝', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    foreach (['title', 'authority_language', 'change_summary'] as $field) {
        $payload = g4RulePayload();
        unset($payload[$field]);

        expect(fn () => $service->createDraft($event, $adminId, $payload))
            ->toThrow(InvalidArgumentException::class);
    }
});

it('G1-04 · body_html 与 external_url 至少一项', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    expect(fn () => $service->createDraft($event, $adminId, g4RulePayload([
        'body_html'    => null,
        'external_url' => null,
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->createDraft($event, $adminId, g4RulePayload([
            'body_html'    => '   ',
            'external_url' => '',
        ])))->toThrow(InvalidArgumentException::class);

    $linkOnly = $service->createDraft($event, $adminId, g4RulePayload([
        'body_html'    => null,
        'external_url' => 'https://example.com/official-rules',
    ]));

    expect($linkOnly->body_html)->toBeNull()
        ->and((string) $linkOnly->external_url)->toBe('https://example.com/official-rules');
});

it('G1-04 · external_url 只接受合法公开 URL', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();

    foreach ([
        'ftp://example.com/rules',
        'http://localhost/rules',
        'http://10.0.0.5/rules',
        'http://[::1]/rules',
        'javascript:alert(1)',
        'https://user:pass@example.com/rules',
    ] as $url) {
        expect(g4RuleSourceErrorCode(fn () => g4RuleService()->createDraft(
            $event,
            $adminId,
            g4RulePayload(['external_url' => $url])
        )))->toBe(ApiErrorCode::EVENT_SOURCE_REQUIRED, 'URL 应被拒绝：'.$url);
    }
});

it('G1-04 · authority_language 必须是 BCP 47 形态且不超过列宽', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    foreach (['zh_CN', 'zh--CN', 'x', 'zh-CN-toolongsubtag', '123'] as $language) {
        expect(fn () => $service->createDraft($event, $adminId, g4RulePayload([
            'authority_language' => $language,
        ])))->toThrow(InvalidArgumentException::class);
    }

    foreach (['zh-CN', 'en', 'zh-Hant-TW'] as $language) {
        $rule = $service->createDraft($event, $adminId, g4RulePayload([
            'authority_language' => $language,
        ]));

        expect((string) $rule->authority_language)->toBe($language);
    }
});

it('G1-04 · 本批 file_media_id 必须 NULL', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    expect(fn () => $service->createDraft($event, $adminId, g4RulePayload([
        'file_media_id' => 123,
    ])))->toThrow(InvalidArgumentException::class);

    $rule = $service->createDraft($event, $adminId, g4RulePayload([
        'file_media_id' => null,
    ]));

    expect($rule->file_media_id)->toBeNull();
});

it('G1-04 · 调用方不得传 content_fingerprint、status 或版本字段', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    foreach ([
        'content_fingerprint' => str_repeat('f', 64),
        'status'              => 'approved',
        'version_no'          => 9,
        'created_by'          => 1,
        'reviewed_by'         => 1,
        'reviewed_at'         => now(),
    ] as $field => $value) {
        expect(fn () => $service->createDraft($event, $adminId, g4RulePayload([
            $field => $value,
        ])))->toThrow(InvalidArgumentException::class);
    }
});

it('G1-04 · effective_from 非法时间被拒绝，合法时间按 UTC 落库', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    expect(fn () => $service->createDraft($event, $adminId, g4RulePayload([
        'effective_from' => 'not-a-date',
    ])))->toThrow(EventTimeValidationException::class);

    $rule = $service->createDraft($event, $adminId, g4RulePayload([
        'effective_from' => '2026-11-01 08:00:00',
    ]));

    expect($rule->effective_from->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-01 00:00:00');
});

it('G1-04 · updateDraft 修改内容并重算指纹，版本号与 draft 状态不变', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    $rule = $service->createDraft($event, $adminId, g4RulePayload());
    $originalFingerprint = (string) $rule->content_fingerprint;

    $updated = $service->updateDraft($rule, $originalFingerprint, [
        'title'          => 'G4 改后的规则标题',
        'body_html'      => '<p>改后的规则正文</p>',
        'change_summary' => '第二稿',
    ]);

    expect((string) $updated->title)->toBe('G4 改后的规则标题')
        ->and((int) $updated->version_no)->toBe(1)
        ->and((string) $updated->status)->toBe('draft')
        ->and((string) $updated->content_fingerprint)->not->toBe($originalFingerprint)
        ->and(g4RuleFingerprint()->ruleVersion($updated->fresh()))->toBe((string) $updated->content_fingerprint);
});

it('G1-04 · updateDraft 指纹过期抛 EVENT_REVISION_CONFLICT', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    $rule = $service->createDraft($event, $adminId, g4RulePayload());

    $error = null;

    try {
        $service->updateDraft($rule, str_repeat('0', 64), ['title' => '不应落库']);
    } catch (RevisionConflictException $exception) {
        $error = $exception;
    }

    expect($error)->not->toBeNull()
        ->and($error->errorCode)->toBe(ApiErrorCode::EVENT_REVISION_CONFLICT)
        ->and($error->expectedFingerprint)->toBe(str_repeat('0', 64))
        ->and($error->actualFingerprint)->toBe((string) $rule->content_fingerprint)
        ->and((string) EventRuleVersion::query()->findOrFail($rule->id)->title)->not->toBe('不应落库');
});

it('G1-04 · 非 draft 规则版本：服务与模型都拒绝内容修改', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    $rule = $service->createDraft($event, $adminId, g4RulePayload());

    DB::table('event_rule_versions')
        ->where('id', $rule->id)
        ->update(['status' => 'approved']);

    $fresh = EventRuleVersion::query()->findOrFail($rule->id);

    expect(fn () => $service->updateDraft($fresh, (string) $fresh->content_fingerprint, [
        'title' => '批准后改名',
    ]))->toThrow(RevisionImmutableException::class);

    $fresh->title = '批准后改名';
    expect(fn () => $fresh->save())->toThrow(RevisionImmutableException::class);
});

it('G1-04 · updateDraft 不能把正文与外链同时清空，也不接受非内容字段', function () {
    $adminId = g4RuleAdminId();
    $event = g4RuleEvent();
    $service = g4RuleService();

    $rule = $service->createDraft($event, $adminId, g4RulePayload());

    expect(fn () => $service->updateDraft($rule, (string) $rule->content_fingerprint, [
        'body_html'    => null,
        'external_url' => null,
    ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->updateDraft($rule, (string) $rule->content_fingerprint, [
            'content_fingerprint' => str_repeat('f', 64),
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $service->updateDraft($rule, (string) $rule->content_fingerprint, [
            'status' => 'approved',
        ]))->toThrow(InvalidArgumentException::class);
});
