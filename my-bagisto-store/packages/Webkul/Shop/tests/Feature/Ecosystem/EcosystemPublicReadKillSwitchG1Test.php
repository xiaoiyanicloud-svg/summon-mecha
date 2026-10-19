<?php

use Illuminate\Support\Facades\DB;
use Webkul\Shop\Services\Ecosystem\MediaAccessException;
use Webkul\Shop\Services\Ecosystem\MediaAccessService;
use Webkul\Shop\Services\Ecosystem\PublicReadGate;
use Webkul\Shop\Services\Ecosystem\PublicReadUnavailableException;

/**
 * G1-09 · 公开读取 kill switch（ADR-0001 G1-D08 / 规划 §5.4）。
 *
 * 覆盖：
 *   · 默认可用；
 *   · 命中时抛 503 语义异常，且**不产生任何数据库查询**；
 *   · 媒体门禁在域开关之前先检查 kill switch（503 而不是 404）；
 *   · kill switch 恢复后回到域开关语义（404）。
 *
 * 运行必须指向独立库 summon_mecha_step10_test。
 */

beforeEach(function () {
    if (DB::connection()->getDatabaseName() !== 'summon_mecha_step10_test') {
        throw new LogicException('G1-09 public read kill switch tests require summon_mecha_step10_test.');
    }
});

it('kill switch 默认关闭（公开读取可用）', function () {
    expect(config('ecosystem_public_read.emergency_disabled'))->toBeFalse();

    $gate = app(PublicReadGate::class);
    expect($gate->available())->toBeTrue();

    $gate->assertAvailable();
    expect(true)->toBeTrue();
});

it('kill switch 命中时不查业务库且返回 503 语义', function () {
    config(['ecosystem_public_read.emergency_disabled' => true]);

    $gate = app(PublicReadGate::class);

    DB::enableQueryLog();

    try {
        $gate->assertAvailable();
        $this->fail('kill switch 开启时不应通过。');
    } catch (PublicReadUnavailableException $exception) {
        expect($exception->httpStatus())->toBe(503)
            ->and($exception->errorCode())->toBe('SERVICE_UNAVAILABLE')
            ->and($exception->getMessage())->not->toBe('');
    } finally {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
    }

    expect($queries)->toBeEmpty();
});

it('媒体门禁先检查 kill switch：503 优先于域开关 404', function () {
    config([
        'ecosystem_public_read.emergency_disabled' => true,
        // 域开关关闭时本来是 404；kill switch 必须优先。
        'ecosystem.events.public_enabled' => false,
    ]);

    $mediaAccess = app(MediaAccessService::class);

    DB::enableQueryLog();

    try {
        $mediaAccess->resolvePublic('event_revision', 1, 1);
        $this->fail('kill switch 开启时媒体不应返回 404。');
    } catch (PublicReadUnavailableException $exception) {
        expect($exception->httpStatus())->toBe(503);
    } finally {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
    }

    expect($queries)->toBeEmpty();
});

it('kill switch 恢复后媒体回到域开关的 404 语义', function () {
    config([
        'ecosystem_public_read.emergency_disabled' => false,
        'ecosystem.events.public_enabled' => false,
    ]);

    expect(fn () => app(MediaAccessService::class)->resolvePublic('event_revision', 1, 1))
        ->toThrow(MediaAccessException::class);

    expect(app(PublicReadGate::class)->available())->toBeTrue();
});
