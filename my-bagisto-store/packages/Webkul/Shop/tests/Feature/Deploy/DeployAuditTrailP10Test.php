<?php

use Webkul\Shop\Models\GrayRelease;
use Webkul\Shop\Models\OperationLog;
use Webkul\Shop\Models\RollbackRecord;
use Webkul\Shop\Models\SkillBlocklist;
use Webkul\Shop\Models\SkillVersion;
use Webkul\Shop\Services\Deploy\GrayReleaseService;
use Webkul\Shop\Services\Deploy\KillSwitchService;

use function Pest\Laravel\postJson;

/**
 * P10 · 下发侧安全动作(熔断 / 灰度 / 回滚)的操作者与来源留痕。
 *
 * 缺口:
 *   · `rollback_record` 建表时**没有 created_by、没有 IP** —— 回滚是拿别人的设备
 *     执行动作,事后查不出是谁触发的;
 *   · `KillSwitchService::engage()` 的 `$createdBy` 默认**写死 `'admin'`**,而唯一调用方
 *     (`SkillSupplyController::block`)不传 → 每个熔断都记成 admin,真实调用方丢失。
 *     编造的身份比留空更坏:它看起来像有留痕;
 *   · `Services/Deploy` 目录此前**一行日志都没有**。
 *
 * ⚠ 诚实边界:`internal.key` 是共享密钥,服务端**无法验证** X-Internal-Operator 的自称。
 *   故一律存成 `internal[:<自称>]` —— 前缀写明"这是内部通道自报的",不冒充已认证管理员。
 */
function p10InternalHeaders(?string $operator = null): array
{
    $h = ['X-Internal-Key' => 'p10-internal-key'];

    if ($operator !== null) {
        $h['X-Internal-Operator'] = $operator;
    }

    return $h;
}

function p10SkillId(): int
{
    $familyId = DB::table('attribute_families')->orderBy('id')->value('id');

    if (! $familyId) {
        throw new LogicException('The test database requires a seeded attribute family.');
    }

    return DB::table('products')->insertGetId([
        'sku'                 => 'P10-SKILL-'.bin2hex(random_bytes(8)),
        'type'                => 'simple',
        'attribute_family_id' => (int) $familyId,
        'created_at'          => now(),
        'updated_at'          => now(),
    ]);
}

function p10PublishedVersion(int $productId): SkillVersion
{
    return SkillVersion::create([
        'product_id'   => $productId,
        'version'      => '1.0.0',
        'channel'      => 'stable',
        'status'       => SkillVersion::STATUS_PUBLISHED,
        'license_mode' => 'per_device',
    ]);
}

beforeEach(function () {
    config(['skill_delivery.upload_key' => 'p10-internal-key']);
});

// ───────────────────── 熔断:操作者不再是编造的 'admin' ─────────────────────

it('P10 · 熔断记的是调用方自报身份(不再是 admin)', function () {
    $skillId = p10SkillId();

    postJson(route('shop.api.skill-supply.block', $skillId), [
        'action' => 'block_all',
        'reason' => 'pest:P10',
    ], p10InternalHeaders('alice'))->assertOk();

    $block = SkillBlocklist::activeFor($skillId);

    expect($block)->not->toBeNull()
        ->and($block->created_by)->toBe('internal:alice')
        ->and($block->ip)->not->toBeNull();
});

it('P10 · 不自报身份时记 internal(而非 admin)', function () {
    $skillId = p10SkillId();

    postJson(route('shop.api.skill-supply.block', $skillId), [], p10InternalHeaders())->assertOk();

    expect(SkillBlocklist::activeFor($skillId)->created_by)->toBe('internal');
});

it('P10 · 熔断同时进 operation_log,payload 带真实操作者与 IP', function () {
    $skillId = p10SkillId();

    postJson(route('shop.api.skill-supply.block', $skillId), [
        'action'   => 'block_all',
        'severity' => 'high',
        'reason'   => 'pest:P10-log',
    ], p10InternalHeaders('bob'))->assertOk();

    $log = OperationLog::query()
        ->where('action', 'killswitch.engage')
        ->where('target_type', 'skill')
        ->where('target_id', $skillId)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->payload['operator'])->toBe('internal:bob')
        ->and($log->payload['reason'])->toBe('pest:P10-log')
        ->and($log->ip)->not->toBeNull();
});

it('P10 · 不同操作者按不同技能熔断 → operation_log 各记名的真实身份', function () {
    $a = p10SkillId();
    $b = p10SkillId();

    postJson(route('shop.api.skill-supply.block', $a), [], p10InternalHeaders('alice'))->assertOk();
    postJson(route('shop.api.skill-supply.block', $b), [], p10InternalHeaders('carol'))->assertOk();

    $operatorOf = fn (int $skillId) => OperationLog::query()
        ->where('action', 'killswitch.engage')
        ->where('target_id', $skillId)
        ->value('payload')['operator'];

    expect($operatorOf($a))->toBe('internal:alice')
        ->and($operatorOf($b))->toBe('internal:carol');
});

it('P10 · 解除熔断也留痕(否则复盘会看到一条永远生效的熔断)', function () {
    $skillId = p10SkillId();

    postJson(route('shop.api.skill-supply.block', $skillId), [], p10InternalHeaders('alice'))->assertOk();
    postJson(route('shop.api.skill-supply.lift', $skillId), [], p10InternalHeaders('dave'))->assertOk();

    expect(OperationLog::query()->where('action', 'killswitch.lift')->where('target_id', $skillId)->exists())
        ->toBeTrue()
        ->and(OperationLog::query()->where('action', 'killswitch.lift')->where('target_id', $skillId)->value('payload')['operator'])
        ->toBe('internal:dave');
});

it('P10 · engage() 直接调用时默认记 internal,绝不退回 admin', function () {
    $skillId = p10SkillId();

    app(KillSwitchService::class)->engage($skillId, 'block_all', 'pest:P10-direct', 'high');

    expect(SkillBlocklist::activeFor($skillId)->created_by)->toBe(KillSwitchService::ACTOR_INTERNAL);
});

// ───────────────────── 灰度 / 回滚:发起者 + IP ─────────────────────

it('P10 · 人工回滚:rollback_record 落操作者与 IP,并进 operation_log', function () {
    $skillId = p10SkillId();
    $version = p10PublishedVersion($skillId);

    $release = app(GrayReleaseService::class)->start($version->id, ['percent' => 5], 'P10-'.uniqid());

    postJson(route('shop.api.gray-releases.abort', $release->id), [
        'reason' => 'pest:P10-手动回滚',
    ], p10InternalHeaders('erin'))->assertOk();

    $record = RollbackRecord::query()->where('gray_release_id', $release->id)->first();

    expect($record)->not->toBeNull()
        ->and($record->trigger)->toBe('manual')
        ->and($record->created_by)->toBe('internal:erin')
        ->and($record->ip)->not->toBeNull();

    $log = OperationLog::query()
        ->where('action', 'gray_release.abort')
        ->where('target_id', $release->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->payload['operator'])->toBe('internal:erin')
        ->and($log->payload['trigger'])->toBe('manual');
});

it('P10 · 自动回滚:发起者记 system + trigger=auto(与"人按的"区分开)', function () {
    $skillId = p10SkillId();
    $version = p10PublishedVersion($skillId);

    $release = app(GrayReleaseService::class)->start($version->id, ['percent' => 5], 'P10-'.uniqid());

    app(GrayReleaseService::class)->abort($release->fresh(), 'pest:auto', 'auto');

    $record = RollbackRecord::query()->where('gray_release_id', $release->id)->first();

    expect($record->trigger)->toBe('auto')
        ->and($record->created_by)->toBe(GrayReleaseService::ACTOR_AUTO);
});

it('P10 · 开启灰度也留痕(发起者 + 策略)', function () {
    $skillId = p10SkillId();
    $version = p10PublishedVersion($skillId);

    postJson(route('shop.api.gray-releases.store'), [
        'skill_version_id' => $version->id,
        'batch_no'         => 'P10-'.uniqid(),
    ], p10InternalHeaders('frank'))->assertStatus(201);

    $release = GrayRelease::query()->where('skill_version_id', $version->id)->first();

    $log = OperationLog::query()
        ->where('action', 'gray_release.start')
        ->where('target_id', $version->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->payload['operator'])->toBe('internal:frank')
        ->and($log->payload['release_id'])->toBe($release->id);
});
