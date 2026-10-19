<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webkul\Shop\Models\EcosystemMedia;
use Webkul\Shop\Models\EventRuleVersion;
use Webkul\Shop\Services\Ecosystem\ContentFingerprint;
use Webkul\Shop\Services\Ecosystem\MediaService;
use Webkul\Shop\Services\Ecosystem\MediaValidationException;
use Webkul\Shop\Services\Ecosystem\RevisionImmutableException;

/**
 * G1-06 · 规则文件绑定（ADR-0001 §4.5 / G1-D06）。
 *
 * 保护的是规则版本的文件引用：
 *   · 只有 draft 规则版本可以绑定 / 更换规则文件；
 *   · 媒体必须 approved；
 *   · 绑定只改 `file_media_id` 并重算规则版本指纹，不触碰 `body_html`
 *     （富文本净化属于 G1-07）；
 *   · `file_media_id` 外键 RESTRICT：被规则版本引用的媒体不可物理删除。
 *
 * 所有用例继承 ShopTestCase → DatabaseTransactions，运行必须指向 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-06 rule media tests require the dedicated summon_mecha_step10_test database.');
    }
});

function g6RuleAdminId(): int
{
    return (int) DB::table('admins')->insertGetId([
        'name'       => 'G6 Rule Admin',
        'email'      => 'g6-rule-'.bin2hex(random_bytes(8)).'@example.test',
        'password'   => bcrypt('secret'),
        'api_token'  => null,
        'status'     => 1,
        'role_id'    => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g6RuleMedia(string $status = 'approved'): EcosystemMedia
{
    $sha256 = hash('sha256', random_bytes(32));
    $storageKey = 'ecosystem/media/'.substr($sha256, 0, 2).'/'.$sha256.'.pdf';

    $id = (int) DB::table('ecosystem_media')->insertGetId([
        'disk'          => 'local',
        'storage_key'   => $storageKey,
        'original_name' => 'rules.pdf',
        'mime'          => 'application/pdf',
        'byte_size'     => 2048,
        'width'         => null,
        'height'        => null,
        'sha256'        => $sha256,
        'status'        => $status,
        'uploaded_by'   => 1,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    return EcosystemMedia::query()->findOrFail($id);
}

function g6RuleEventId(): int
{
    return (int) DB::table('events')->insertGetId([
        'name'       => 'G6 规则文件活动',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function g6RuleVersion(int $eventId, string $status = 'draft'): EventRuleVersion
{
    $id = (int) DB::table('event_rule_versions')->insertGetId([
        'event_id'            => $eventId,
        'version_no'          => 1,
        'title'               => 'G6 规则版本',
        'authority_language'  => 'zh-CN',
        'body_html'           => '<p>规则正文</p>',
        'external_url'        => null,
        'file_media_id'       => null,
        'content_fingerprint' => str_repeat('d', 64),
        'change_summary'      => '规则文件夹具',
        'effective_from'      => null,
        'status'              => $status,
        'created_by'          => 1,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);

    return EventRuleVersion::query()->findOrFail($id);
}

function g6RuleService(): MediaService
{
    return app(MediaService::class);
}

it('G1-06 · attachRuleFile 写入 FK 并重算规则版本指纹，不触碰 body_html', function () {
    $service = g6RuleService();
    $rule = g6RuleVersion(g6RuleEventId());
    $media = g6RuleMedia();
    $adminId = g6RuleAdminId();

    $bodyBefore = (string) $rule->body_html;
    $fingerprintBefore = (string) $rule->content_fingerprint;

    $attached = $service->attachRuleFile($rule, (int) $media->getKey(), $adminId);

    expect((int) $attached->file_media_id)->toBe((int) $media->getKey())
        ->and($attached->body_html)->toBe($bodyBefore)
        ->and($attached->content_fingerprint)->not->toBe($fingerprintBefore)
        ->and($attached->content_fingerprint)
        ->toBe(app(ContentFingerprint::class)->ruleVersion($attached));

    $row = DB::table('event_rule_versions')->where('id', $rule->getKey())->first();

    expect((int) $row->file_media_id)->toBe((int) $media->getKey())
        ->and($row->body_html)->toBe($bodyBefore)
        ->and($row->content_fingerprint)->toBe($attached->content_fingerprint);
});

it('G1-06 · 更换为另一份 approved 媒体会再次改变规则指纹', function () {
    $service = g6RuleService();
    $rule = g6RuleVersion(g6RuleEventId());
    $adminId = g6RuleAdminId();
    $first = g6RuleMedia();
    $second = g6RuleMedia();

    $firstAttach = $service->attachRuleFile($rule, (int) $first->getKey(), $adminId);
    $firstFingerprint = (string) $firstAttach->content_fingerprint;

    $secondAttach = $service->attachRuleFile($firstAttach, (int) $second->getKey(), $adminId);

    expect((int) $secondAttach->file_media_id)->toBe((int) $second->getKey())
        ->and($secondAttach->content_fingerprint)->not->toBe($firstFingerprint)
        ->and($secondAttach->content_fingerprint)
        ->toBe(app(ContentFingerprint::class)->ruleVersion($secondAttach));
});

it('G1-06 · 非 approved / 不存在的媒体不得绑定为规则文件', function () {
    $service = g6RuleService();
    $rule = g6RuleVersion(g6RuleEventId());
    $adminId = g6RuleAdminId();
    $fingerprintBefore = (string) $rule->content_fingerprint;

    foreach (['pending', 'rejected', 'quarantined'] as $status) {
        $media = g6RuleMedia($status);

        expect(fn () => $service->attachRuleFile($rule->fresh(), (int) $media->getKey(), $adminId))
            ->toThrow(MediaValidationException::class);
    }

    expect(fn () => $service->attachRuleFile($rule->fresh(), 999999999, $adminId))
        ->toThrow(MediaValidationException::class);

    expect(fn () => $service->attachRuleFile($rule->fresh(), 0, $adminId))
        ->toThrow(MediaValidationException::class);

    $fresh = $rule->fresh();

    expect($fresh->file_media_id)->toBeNull()
        ->and($fresh->content_fingerprint)->toBe($fingerprintBefore);
});

it('G1-06 · 规则版本非 draft 时拒绝绑定规则文件', function () {
    $service = g6RuleService();
    $media = g6RuleMedia();
    $adminId = g6RuleAdminId();

    foreach (['approved', 'superseded', 'withdrawn'] as $status) {
        $rule = g6RuleVersion(g6RuleEventId(), $status);

        expect(fn () => $service->attachRuleFile($rule, (int) $media->getKey(), $adminId))
            ->toThrow(RevisionImmutableException::class);

        expect($rule->fresh()->file_media_id)->toBeNull();
    }
});

it('G1-06 · 绑定规则文件需要存在的后台账号', function () {
    $service = g6RuleService();
    $rule = g6RuleVersion(g6RuleEventId());
    $media = g6RuleMedia();

    expect(fn () => $service->attachRuleFile($rule, (int) $media->getKey(), 0))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $service->attachRuleFile($rule, (int) $media->getKey(), 999999999))
        ->toThrow(InvalidArgumentException::class);

    expect($rule->fresh()->file_media_id)->toBeNull();
});

it('G1-06 · 被规则版本引用的媒体受 FK 保护，不可物理删除', function () {
    $service = g6RuleService();
    $rule = g6RuleVersion(g6RuleEventId());
    $media = g6RuleMedia();

    $service->attachRuleFile($rule, (int) $media->getKey(), g6RuleAdminId());

    expect(fn () => DB::table('ecosystem_media')->where('id', $media->getKey())->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('ecosystem_media')->where('id', $media->getKey())->exists())->toBeTrue();
});
