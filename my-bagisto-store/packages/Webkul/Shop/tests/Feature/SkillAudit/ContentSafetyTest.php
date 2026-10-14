<?php

use Webkul\Shop\Services\ContentSafety\ContentSafetyManager;
use Webkul\Shop\Services\ContentSafety\LocalKeywordScanner;
use Webkul\Shop\Services\ContentSafety\ScanResult;
use Webkul\Shop\Services\SkillAudit\AuditService;
use Webkul\Shop\Services\SkillAudit\RejectionReason;

/**
 * 内容安全层（v3.4 步骤 6）。
 *
 * 背景：`AuditService::matchForbidden` 此前遍历的是**空数组** —— 内容筛查什么都没拦。
 * 现在换成真实的 ContentSafety 层（本地词表，可换 msgSecCheck）。
 *
 * 这组测试锁三件事：
 *   1. 词表真能命中（含最朴素的拆写规避）；
 *   2. **「没查」≠「合规」** —— 词表缺失时按配置拦截，理由码 CONTENT_UNSCREENED；
 *   3. **绕过口子已堵** —— 违规文案放进 name/description 以外的字段也拦得住。
 */

/** 造一份能过 ManifestValidator 的清单。 */
function validManifest(array $overrides = []): array
{
    return array_merge([
        'schema'            => 'zhaohuan.skill/v2',
        'kind'              => 'motion',
        'name'              => ['zh_CN' => '抓取技能'],
        'version'           => '1.0.0',
        'delivery_channels' => ['manual'],
        'resource_type'     => 'skill',
        'safety'            => [
            'risk_level'         => 'low',
            'requires_open_area' => true,
            'estop_required'     => true,
            'max_duration_s'     => 30,
        ],
    ], $overrides);
}

/** 写一个临时词表并把它挂到 local 驱动上。 */
function useWords(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'words').'.txt';
    file_put_contents($path, $content);

    config([
        'content_safety.driver' => 'local',
        'content_safety.drivers.local.words_path' => $path,
    ]);

    return $path;
}

it('本地词表：命中违禁词 → violation，并回报命中的词', function () {
    useWords("赌博\n代开发票\n");

    $res = app(ContentSafetyManager::class)->scanner()->scan('本技能用于赌博机、兑换筹码');

    expect($res->passed)->toBeFalse()
        ->and($res->degraded)->toBeFalse()
        ->and($res->isViolation())->toBeTrue()
        ->and($res->hits)->toContain('赌博')
        ->and($res->provider)->toBe('local');
});

it('本地词表：干净文本 → pass', function () {
    useWords("赌博\n");

    $res = app(ContentSafetyManager::class)->scanner()->scan('机械臂抓取与分拣，适用于精密装配');

    expect($res->passed)->toBeTrue()
        ->and($res->degraded)->toBeFalse();
});

it('拆写规避（中插空格/符号）也拦得住', function () {
    useWords("赌博\n");

    foreach (['赌 博', '赌-博', '赌.博', '赌*博'] as $evasion) {
        expect(app(ContentSafetyManager::class)->scanner()->scan("技能：{$evasion}")->isViolation())
            ->toBeTrue("未拦住变体：{$evasion}");
    }
});

it('re: 开头的行按正则匹配；非法正则被跳过而不是让整层崩掉', function () {
    useWords("re:办.{0,3}证\nre:[未闭合\n");

    $scanner = app(ContentSafetyManager::class)->scanner();

    expect($scanner->scan('快速办理各类证件')->isViolation())->toBeTrue();
    // 非法正则没把扫描器搞挂：干净文本仍能正常判定
    expect($scanner->scan('正常的机器人技能文案')->passed)->toBeTrue();
});

it('词表缺失 → degraded（**不是** pass）', function () {
    config([
        'content_safety.driver' => 'local',
        'content_safety.drivers.local.words_path' => 'D:/definitely/not/here.txt',
    ]);

    $res = app(LocalKeywordScanner::class, [['words_path' => 'D:/definitely/not/here.txt']])
        ->scan('任意文本');

    expect($res->passed)->toBeFalse()
        ->and($res->degraded)->toBeTrue()
        ->and($res->message)->toContain('词表');
});

it('L0 机审：manifest 含违禁词 → 拒绝，理由码是 CONTENT_VIOLATION', function () {
    useWords("赌博\n");

    $result = app(AuditService::class)->submitLayer0(900001, 900001, validManifest([
        'name' => ['zh_CN' => '赌博辅助技能'],
    ]));

    expect($result['result'])->toBe(\Webkul\Shop\Models\AuditRecord::RESULT_REJECT);

    $joined = implode(' | ', $result['reasons']);
    expect($joined)->toContain(RejectionReason::CONTENT_VIOLATION)
        ->and($joined)->toContain('赌博');
});

it('L0 机审：违规文案藏在 name/description 以外的字段也拦得住（绕过口子回归）', function () {
    useWords("赌博\n");

    // 放在一个 manifest 里"看起来无害"的嵌套字段中
    $result = app(AuditService::class)->submitLayer0(900002, 900002, validManifest([
        'motion' => ['format' => 'bvh', 'notes' => ['zh_CN' => '配套赌博机使用']],
    ]));

    expect($result['result'])->toBe(\Webkul\Shop\Models\AuditRecord::RESULT_REJECT)
        ->and(implode(' ', $result['reasons']))->toContain(RejectionReason::CONTENT_VIOLATION);
});

it('L0 机审：干净 manifest → 通过', function () {
    useWords("赌博\n");

    $result = app(AuditService::class)->submitLayer0(900003, 900003, validManifest());

    expect($result['result'])->toBe(\Webkul\Shop\Models\AuditRecord::RESULT_PASS)
        ->and($result['reasons'])->toBe([])
        ->and($result['notes'])->toBe([]);
});

it('L0 机审：词表缺失时默认**拦截**（没查 ≠ 合规），理由码 CONTENT_UNSCREENED', function () {
    config([
        'content_safety.driver' => 'local',
        'content_safety.drivers.local.words_path' => 'D:/definitely/not/here.txt',
        'content_safety.on_missing_words' => 'fail',
    ]);

    $result = app(AuditService::class)->submitLayer0(900004, 900004, validManifest());

    expect($result['result'])->toBe(\Webkul\Shop\Models\AuditRecord::RESULT_REJECT)
        ->and(implode(' ', $result['reasons']))->toContain(RejectionReason::CONTENT_UNSCREENED)
        ->and($result['notes'])->not->toBe([]);
});

it('L0 机审：显式配成 pass 时放行，但 notes 里如实记录"扫描未运行"', function () {
    config([
        'content_safety.driver' => 'local',
        'content_safety.drivers.local.words_path' => 'D:/definitely/not/here.txt',
        'content_safety.on_missing_words' => 'pass',
    ]);

    $result = app(AuditService::class)->submitLayer0(900005, 900005, validManifest());

    expect($result['result'])->toBe(\Webkul\Shop\Models\AuditRecord::RESULT_PASS)
        // 放行了，但必须留下"这次没查"的痕迹
        ->and(implode(' ', $result['notes']))->toContain('内容扫描未运行');
});

it('改配置即可换驱动；配了未注册的驱动会显式报错而不是静默退回', function () {
    config(['content_safety.driver' => 'wechat']);

    expect(fn () => app(ContentSafetyManager::class)->scanner())
        ->toThrow(\RuntimeException::class, 'wechat');
});
