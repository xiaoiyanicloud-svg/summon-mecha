<?php

use Webkul\Shop\Http\Controllers\API\SummonAiController;

/**
 * X5 · SummonAiController 改走配置注册表。
 *
 * 改前:控制器直接 `env('COZE_BASE_URL')` / `env('COZE_PAT')` / `env('COZE_BOT_ID')`,
 * 是 Shop src 里**唯一**的直接 env() 调用,违背 CLAUDE.md「扩展优先走配置注册表」。
 *
 * 实伤:生产执行 `php artisan config:cache` 后,config 文件之外的 `env()` 一律返回 null
 * → AI 召唤整条链路静默失效(base_url 退回 localhost、pat/bot_id 变空 → 直接降级),**不报错**。
 * 修法:三键登记进 `config/summon_ai.php`,控制器改读 config()。
 *
 * (真·config:cache 后的运行验证见交付说明的实测证据:缓存后 fresh 进程里
 *  config('summon_ai.pat') 仍有值,而 env('COZE_PAT') 已是 NULL。)
 */

it('X5 · Coze 三键登记进配置注册表', function () {
    expect(config('summon_ai'))->toBeArray()
        ->and(config('summon_ai'))->toHaveKeys(['base_url', 'pat', 'bot_id'])
        // 三键都是字符串(读不到时也是空串,而不是 null —— 与控制器原 env() 默认一致)
        ->and(config('summon_ai.base_url'))->toBeString()
        ->and(config('summon_ai.pat'))->toBeString()
        ->and(config('summon_ai.bot_id'))->toBeString();
});

it('X5 · 控制器源码里不再有直接 env(\'COZE_*\') 调用', function () {
    $src = file_get_contents((new ReflectionClass(SummonAiController::class))->getFileName());

    // 逐行剔除注释(// 与 * 开头的说明行),避免把叙述里的 "env()" 误判成调用
    $code = implode("\n", array_filter(
        explode("\n", $src),
        fn ($line) => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $line)
    ));

    expect($code)->not->toContain("env('COZE_")
        ->and($code)->not->toContain('env("COZE_')
        // 正面断言:确实改读了注册表
        ->and($code)->toContain("config('summon_ai.");
});
