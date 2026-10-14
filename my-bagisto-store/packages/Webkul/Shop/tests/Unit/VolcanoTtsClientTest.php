<?php

use Webkul\Shop\Services\TtsApiException;
use Webkul\Shop\Services\VolcanoTtsClient;

/*
|--------------------------------------------------------------------------
| 火山引擎响应解析
|--------------------------------------------------------------------------
|
| 响应形态是通过**空 key 实测**摸出来的(2026-09-29):
|
|   HTTP 400
|   {"header":{"reqid":"...","code":45000000,"message":"app key not found in header or query"}}
|
| 由此确定三件事,下面每个用例都是在锁住它们:
|   ① 错误码在 **header** 里,不在顶层;
|   ② 错误码是 45000000 这种大数字,与旧版 3000 体系不同 —— **成功码取值未知**,
|      所以解析刻意不靠成功码,而是"能取到音频就算成功";
|   ③ 配置错误返回 HTTP 400(不是 401/403)—— 所以客户端不能只看 HTTP 状态判成败。
|
| 仍未核实的是**成功**响应里音频字段叫什么(默认 data,可配置)、是否流式多段。
|
*/

it('parses a single JSON response', function () {
    $body = '{"header":{"code":0},"data":"QUJD"}';

    expect(VolcanoTtsClient::extractAudio($body))->toBe('QUJD');
});

it('does not depend on knowing the success code', function () {
    // ⚠ 这是刻意的设计:火山的成功码取值还没核实,所以规则是"能取到音频就算成功"。
    //   下面故意用一个**编造的**成功码,依然必须解析成功 —— 否则一旦真实成功码
    //   不是我们猜的那个,整条链路就会哑掉。
    $body = '{"header":{"code":987654321,"message":"whatever"},"data":"QUJD"}';

    expect(VolcanoTtsClient::extractAudio($body))->toBe('QUJD');
});

it('strips a data URI prefix if the service returns one', function () {
    $body = '{"header":{"code":0},"data":"data:audio/mp3;base64,QUJD"}';

    expect(VolcanoTtsClient::extractAudio($body))->toBe('QUJD');
});

it('surfaces the error code from the header envelope (the real observed one)', function () {
    // 这条就是实测到的那条响应,原样固化 —— 它是整个解析逻辑的依据
    $body = '{"header":{"reqid":"7268a3d9-c36d-4a45-80f0-b9d454230d97","code":45000000,"message":"app key not found in header or query"}}';

    try {
        VolcanoTtsClient::extractAudio($body);
        $this->fail('应当抛异常');
    } catch (TtsApiException $e) {
        expect($e->provider())->toBe(TtsApiException::PROVIDER_VOLCANO)
            ->and($e->providerCode())->toBe('45000000')
            ->and($e->getMessage())->toContain('app key not found')
            // 缺 key 属于配置问题,重试无意义 → 系统性,前端立刻放弃并提示
            ->and($e->isSystemic())->toBeTrue();
    }
});

it('also reads a top-level error code as a fallback', function () {
    // 万一某些接口不套 header 封装
    $body = '{"code":45000001,"message":"invalid speaker"}';

    try {
        VolcanoTtsClient::extractAudio($body);
        $this->fail('应当抛异常');
    } catch (TtsApiException $e) {
        expect($e->providerCode())->toBe('45000001')
            ->and($e->getMessage())->toContain('invalid speaker');
    }
});

it('parses a streamed response made of concatenated JSON objects', function () {
    // 流式返回:多段 JSON 拼在一起,每段的 data 是**独立 base64 编码**的一段音频字节。
    //
    // ⚠ 这里刻意选了会产生 `=` 填充的分片:
    //     base64('A')  = 'QQ=='   (1 字节 → 带填充)
    //     base64('BC') = 'QkM='   (2 字节 → 带填充)
    //   直接把两个 base64 串拼起来会得到 'QQ==QkM=',中间的 `=` 让它变成**非法 base64**。
    //   正确做法是逐段解码再拼字节: 'A' + 'BC' = 'ABC' → base64('ABC') = 'QUJD'。
    $body = '{"header":{"code":0},"data":"QQ=="}{"header":{"code":0},"data":"QkM="}';

    expect(VolcanoTtsClient::extractAudio($body))->toBe('QUJD');
});

it('parses a newline-delimited streamed response', function () {
    $body = "{\"header\":{\"code\":0},\"data\":\"QQ==\"}\n{\"header\":{\"code\":0},\"data\":\"QkM=\"}\n";

    expect(VolcanoTtsClient::extractAudio($body))->toBe('QUJD');
});

it('reports what it actually saw when there is neither audio nor an error code', function () {
    // 响应形态与预期不符时,**必须**能一眼看出真实结构,而不是干瞪眼
    $body = '{"header":{"reqid":"x"},"audio_content":"QUJD"}';

    try {
        VolcanoTtsClient::extractAudio($body);
        $this->fail('应当抛异常');
    } catch (TtsApiException $e) {
        expect($e->providerCode())->toBe('UnexpectedResponse')
            ->and($e->isSystemic())->toBeTrue()
            ->and($e->getMessage())->toContain('audio_content');   // 真实键名
    }
});

it('honours a custom audio field name', function () {
    // 音频字段名还没核实 —— 所以做成可配置的:探针显示它叫别的名字时,
    // 改一个 .env 变量即可,不用改代码。
    $body = '{"header":{"code":0},"audio_content":"QUJD"}';

    expect(VolcanoTtsClient::extractAudio($body, 'audio_content'))->toBe('QUJD')
        // 用默认字段名去找同一个响应,应当失败并提示它找的是哪个字段
        ->and(fn () => VolcanoTtsClient::extractAudio($body))
        ->toThrow(TtsApiException::class);
});

it('reports a non-JSON body instead of failing silently', function () {
    try {
        VolcanoTtsClient::extractAudio('<html>502 Bad Gateway</html>');
        $this->fail('应当抛异常');
    } catch (TtsApiException $e) {
        expect($e->providerCode())->toBe('UnexpectedResponse')
            ->and($e->getMessage())->toContain('502 Bad Gateway');
    }
});

it('rejects empty text before making any request', function () {
    $client = new VolcanoTtsClient('api-key');

    expect(fn () => $client->synthesize(''))->toThrow(RuntimeException::class);
});

it('satisfies the shared provider contract', function () {
    // 两个 provider 必须都能被控制器一视同仁地调用 —— 这是 provider 可切换的前提
    expect(new VolcanoTtsClient('api-key'))
        ->toBeInstanceOf(Webkul\Shop\Services\TtsProvider::class);

    expect(new Webkul\Shop\Services\TencentTtsClient('id', 'key'))
        ->toBeInstanceOf(Webkul\Shop\Services\TtsProvider::class);
});

/*
|--------------------------------------------------------------------------
| 请求体形状
|--------------------------------------------------------------------------
|
| 这一组是针对**已经踩过的坑**写的:这个接口的参数位置我猜错过三次
| (端点、鉴权头、音色字段名、语速参数位置),而**错的全都不报错** ——
| 它们只会静默失效,靠听感或联调去发现代价极高。
|
| 所以把请求体抽成纯函数,把"参数落在哪一层、叫什么"钉死在这里。
| 这些用例的价值不在于"测了构造函数",而在于**接口契约变更时能第一时间报警**。
|
*/

it('puts the voice in req_params.speaker (not voice_type)', function () {
    // 旧版 API 叫 voice_type,v3 叫 speaker。写错不会报错,只会用错音色。
    $body = (new VolcanoTtsClient('k', speaker: 'zh_female_yingtaowanzi_mars_bigtts'))
        ->buildRequestBody('你好');

    expect($body['req_params']['speaker'])->toBe('zh_female_yingtaowanzi_mars_bigtts')
        ->and($body['req_params'])->not->toHaveKey('voice_type');
});

it('puts speech_rate inside audio_params (the only place that works)', function () {
    // ⚠⚠ 这是本组最重要的一条。实测:
    //   · audio_params.speech_rate  → 生效(0→9.600s, 15→8.304s, 20→7.992s)
    //   · speed_ratio(旧版写法)     → **静默忽略**,音频毫无变化
    //   · req_params.speech_rate    → **静默忽略**(层级错了)
    // 所以两个"错误位置"都必须断言为不存在 —— 否则哪天有人"顺手"挪一下,测试要能拦住。
    $body = (new VolcanoTtsClient('k', speechRate: 15))->buildRequestBody('你好');

    expect($body['req_params']['audio_params']['speech_rate'])->toBe(15)
        ->and($body['req_params']['audio_params'])->not->toHaveKey('speed_ratio')
        ->and($body['req_params'])->not->toHaveKey('speech_rate');
});

it('always sends speech_rate, and 0 matches the original speed', function () {
    // 实测 speech_rate=0 与完全不传等价,所以无条件发送是安全的
    // (也就意味着"设为 0"是一个明确的"原速",而不是"不设置"的模糊状态)
    $body = (new VolcanoTtsClient('k'))->buildRequestBody('你好');

    expect($body['req_params']['audio_params']['speech_rate'])->toBe(0);
});

it('keeps additions a JSON string, not a nested object', function () {
    // ⚠ 火山要求 additions 是**字符串**。传成数组不会报错,但配置会失效。
    $additions = '{"disable_markdown_filter":true}';
    $body = (new VolcanoTtsClient('k', additions: $additions))->buildRequestBody('你好');

    expect($body['req_params']['additions'])->toBeString()->toBe($additions);
});

it('omits additions entirely when empty', function () {
    // 空串会让火山认为你显式给了一个空配置,不如整个不传
    $body = (new VolcanoTtsClient('k', additions: ''))->buildRequestBody('你好');

    expect($body['req_params'])->not->toHaveKey('additions');
});

it('puts audio format and sample rate in audio_params', function () {
    $body = (new VolcanoTtsClient('k', format: 'mp3', sampleRate: 24000))->buildRequestBody('你好');

    expect($body['req_params']['audio_params']['format'])->toBe('mp3')
        ->and($body['req_params']['audio_params']['sample_rate'])->toBe(24000);
});

it('lets extra_req_params override anything', function () {
    // 给"还没核实的参数"留的逃生口 —— 出问题时不改代码就能试
    $body = (new VolcanoTtsClient(
        'k',
        speaker: 'original',
        extraReqParams: ['speaker' => 'overridden', 'speed_ratio' => 1.5],
    ))->buildRequestBody('你好');

    expect($body['req_params']['speaker'])->toBe('overridden')
        ->and($body['req_params']['speed_ratio'])->toBe(1.5);
});
