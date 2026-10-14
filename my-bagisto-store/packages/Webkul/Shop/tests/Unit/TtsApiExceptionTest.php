<?php

use Webkul\Shop\Services\TtsApiException;

/*
|--------------------------------------------------------------------------
| 云端 TTS 错误的"系统性"判定
|--------------------------------------------------------------------------
|
| 这个判定决定了"朗读失败要不要继续重试"以及"要不要告诉用户"。判错的代价:
|   · 把系统性故障当临时故障 → 前端对每一句都发注定失败的请求(刷额度、刷日志)
|   · 把临时故障当系统性故障 → 一次网络抖动就让整段朗读放弃
|
| 真实的触发案例:腾讯资源包用尽(UnsupportedOperation.PkgExhausted)曾让前端
| 连发 5 次请求却完全静默,用户只看到"点了没声音",无从诊断。
|
| ⚠ 现在有两个 provider,错误码规则完全不同(腾讯是点号前缀码,火山是数字码),
|   所以判定必须按 provider 分开 —— 用同一套规则去套另一家必然误判。
|
*/

it('treats Tencent quota, auth and config problems as systemic', function () {
    $cases = [
        'UnsupportedOperation.PkgExhausted',    // 资源包用尽(实际踩到过)
        'AuthFailure.SignatureFailure',         // 密钥/签名错
        'AuthFailure.SignatureExpire',          // 服务器时间偏差过大
        'UnauthorizedOperation',                // 服务未开通
        'InvalidParameterValue.VoiceType',      // 音色 ID 不对
    ];

    foreach ($cases as $code) {
        $e = new TtsApiException(TtsApiException::PROVIDER_TENCENT, $code, 'x');

        expect($e->isSystemic())->toBeTrue("腾讯 {$code} 应判为系统性");
    }
});

it('treats Tencent transient problems as retryable', function () {
    $cases = [
        'RequestLimitExceeded',   // 限流:过一会儿可能就好了
        'InternalError',          // 腾讯侧偶发
        'FailedOperation',        // 业务处理失败,多为偶发
    ];

    foreach ($cases as $code) {
        $e = new TtsApiException(TtsApiException::PROVIDER_TENCENT, $code, 'x');

        expect($e->isSystemic())->toBeFalse("腾讯 {$code} 应判为可重试");
    }
});

it('treats Volcano auth, quota and param problems as systemic', function () {
    // ⚠ 火山的码表是按公开资料整理的,本机无法核实官方文档 —— 详见类注释
    foreach (['401', '403', '3001', '3003', '3006'] as $code) {
        $e = new TtsApiException(TtsApiException::PROVIDER_VOLCANO, $code, 'x');

        expect($e->isSystemic())->toBeTrue("火山 {$code} 应判为系统性");
    }
});

it('treats every Volcano error as systemic until its code space is known', function () {
    // ⚠ 这是有意的保守策略,不是遗漏:火山的码表还没摸清(实测缺 key 是 45000000,
    //   与旧版 3000 体系不同,而**成功码取值也未知**)。既然分不清哪个码是"临时性",
    //   就一律当作系统性 —— 代价是偶发抖动会让这次朗读立刻放弃,收益是配置类错误
    //   不会白刷 3 次注定失败的请求。两者最终都是"没念 + 弹一次提示"。
    foreach (['45000000', '45000001', '3005', '3007'] as $code) {
        $e = new TtsApiException(TtsApiException::PROVIDER_VOLCANO, $code, 'x');

        expect($e->isSystemic())->toBeTrue("火山 {$code} 当前应判为系统性");
    }
});

it('never applies one provider code convention to the other', function () {
    // 这是本类拆成 provider 感知的**根本理由**:腾讯是点号前缀码、火山是数字码。
    // 拿腾讯的前缀规则去认火山的数字码,结果必然是"认不出来" ——
    // 反过来说,一旦哪天有人把两家的判定合并成一套,这个用例就会失败。
    $volcanoCodeJudgedAsTencent = new TtsApiException(TtsApiException::PROVIDER_TENCENT, '45000000', 'x');

    expect($volcanoCodeJudgedAsTencent->isSystemic())
        ->toBeFalse('腾讯的前缀规则不该认得出火山的数字码');

    // 而腾讯自己的前缀码在腾讯规则下是命中的
    $tencentCode = new TtsApiException(TtsApiException::PROVIDER_TENCENT, 'AuthFailure.SignatureFailure', 'x');

    expect($tencentCode->isSystemic())->toBeTrue();
});

it('always treats an unexpected response shape as systemic', function () {
    // 契约不符(响应里没有音频字段)重试不可能变好,只能改代码/配置
    foreach ([TtsApiException::PROVIDER_TENCENT, TtsApiException::PROVIDER_VOLCANO] as $provider) {
        $e = new TtsApiException($provider, 'UnexpectedResponse', 'x');

        expect($e->isSystemic())->toBeTrue();
    }
});

it('keeps the provider and original code', function () {
    $e = new TtsApiException(TtsApiException::PROVIDER_VOLCANO, '3001', '音色不存在');

    expect($e->provider())->toBe('volcano')
        ->and($e->providerCode())->toBe('3001')
        ->and($e->getMessage())->toBe('音色不存在')
        // 必须是 RuntimeException 的子类,否则控制器现有的 catch 逻辑会漏掉
        ->and($e)->toBeInstanceOf(RuntimeException::class);
});

it('embeds the raw response in the unexpected-response error for self-diagnosis', function () {
    // 火山的响应字段名还没核实,所以"定位不到音频"时**必须**把原始响应带出来,
    // 这样一发探针就能看出真实结构,而不是干瞪眼。
    $raw = '{"code":3000,"message":"Success","something_else":"QUJD"}';
    $e = TtsApiException::unexpectedVolcanoResponse($raw, json_decode($raw, true));

    expect($e->providerCode())->toBe('UnexpectedResponse')
        ->and($e->getMessage())->toContain('something_else')   // 解析出的键名
        ->and($e->getMessage())->toContain('something_else')   // 原始响应
        ->and($e->getMessage())->toContain('QUJD');
});

it('handles the case where the body is not JSON at all', function () {
    $e = TtsApiException::unexpectedVolcanoResponse('<html>502 Bad Gateway</html>', null);

    expect($e->getMessage())->toContain('整段无法解析为 JSON')
        ->and($e->getMessage())->toContain('502 Bad Gateway');
});
