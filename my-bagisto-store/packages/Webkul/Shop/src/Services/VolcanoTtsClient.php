<?php

namespace Webkul\Shop\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 火山引擎「语音合成大模型」客户端(豆包同源)。
 *
 * 为什么加这一家:腾讯「基础语音合成」是传统参数合成,拼接感和"人机感"明显;
 * 火山这套是字节自研的端到端语音大模型,韵律与情感是它的卖点 —— 也就是豆包那种
 * "像活人在说话"的效果。音色 ID 里带 `_emo_` 的是**情感音色**,是"活人感"的关键。
 *
 * ── 请求形态(按控制台真实示例逐字对齐) ──
 *
 *   POST https://openspeech.bytedance.com/api/v3/tts/unidirectional
 *   x-api-key:          {apiKey}        ← ⚠ 鉴权是 x-api-key,**不是** Authorization
 *   X-Api-Resource-Id:  volc.service_type.10029
 *   Connection:         keep-alive
 *   Content-Type:       application/json
 *
 *   { "req_params": {
 *       "text": "豆包语音",
 *       "speaker": "zh_male_beijingxiaoye_emo_v2_mars_bigtts",
 *       "additions": "{\"disable_markdown_filter\":true,...}",   ← ⚠ 是一个 **JSON 字符串**
 *       "audio_params": { "format": "mp3", "sample_rate": 24000 }
 *   } }
 *
 * ── 响应形态(2026-09-29 用空 key 实测出来的) ──
 *
 *   HTTP 400
 *   {"header":{"reqid":"...","code":45000000,"message":"app key not found in header or query"}}
 *
 *   三个由此确定的要点:
 *     ① **错误码在 `header` 里**,不是顶层 —— 找顶层 code 会完全找不到;
 *     ② 错误码是 45000000 这种大数字,与旧版 3000 体系不同,**成功码取值未知**;
 *     ③ 配置错误返回的是 **HTTP 400**,不是 401/403 ——
 *        所以**不能只看 HTTP 状态判成败**,必须先解析响应体,否则会把真实错误码丢掉。
 *
 *   因此下面的解析刻意**不依赖"成功码"判断成败**,而是"能取到音频就算成功",
 *   取不到就用响应里的错误码报错 —— 这样不管成功码是 0 还是别的,都能工作。
 *
 * ⚠ 仍未核实的:成功时音频字段到底叫什么(默认 data,可配置)、是否流式多段。
 *   两种都兼容了,且定位不到时会把**原始响应**带进异常,一发探针就能看清。
 *
 * 与其他客户端同源:只抛 TtsApiException / RuntimeException,report() 由控制器做。
 *
 * ⚠ 措辞精确一点:**响应解析**是纯静态函数,可以脱离 Laravel 容器直接单测
 *   (这也是把它单独抽出来的原因之一);但 **HTTP 调用**走 Http facade,需要容器 ——
 *   所以"可直接 new 出来测"指的是构造与解析,不是整条链路。
 */
final class VolcanoTtsClient implements TtsProvider
{
    public function __construct(
        /** 控制台的 API Key,走 x-api-key 头 */
        private readonly string $apiKey,
        private readonly string $endpoint = 'https://openspeech.bytedance.com/api/v3/tts/unidirectional',
        /** 资源 ID;大模型语音合成必需 */
        private readonly string $resourceId = 'volc.service_type.10029',
        /** 音色 ID。⚠ 必须从控制台音色列表取;带 `_emo_` 的是情感音色 */
        private readonly string $speaker = '',
        private readonly string $format = 'mp3',
        private readonly int $sampleRate = 24000,
        /**
         * 语速。0 = 原速;**每 +10 约快 9%**,下表是同一段文本的实测值:
         *   0 → 9.600s(原速) | 10 → 8.712s(+9%) | 15 → 8.304s(+14%)
         *   20 → 7.992s(+17%) | 30 → 7.368s(+23%) | 50 → 6.384s(+34%)
         *   负数变慢(-20 → 11.928s),双向可控。
         *
         * ⚠⚠ 必须在 **audio_params** 里、字段名 **speech_rate**。以下两种写法**都会被静默忽略**
         *   (不报错、音频毫无变化 —— 比报错更难发现,两种都已实测):
         *     · 旧版 API 的 `speed_ratio`(v3 上无效)
         *     · 放在 req_params 层级
         */
        private readonly int $speechRate = 0,
        /**
         * 附加参数。⚠ 火山要求它是一个**JSON 字符串**(不是嵌套对象) —— 见类注释。
         * 里面可开关注解:disable_markdown_filter / enable_language_detector / cache_config 等。
         */
        private readonly string $additions = '',
        /** 透传到 req_params 的额外字段,给"还没核实的参数"留的口子(不用改代码) */
        private readonly array $extraReqParams = [],
        /** 响应里承载音频的字段名。⚠ 待确认:默认 data,若探针显示是别的名字,改配置即可 */
        private readonly string $audioField = 'data',
        private readonly int $timeout = 20,
    ) {}

    public function synthesize(string $text): string
    {
        if ($text === '') {
            throw new RuntimeException('待合成文字为空');
        }

        $body = $this->buildRequestBody($text);

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'X-Api-Resource-Id' => $this->resourceId,
            'Connection'        => 'keep-alive',
            'Content-Type'      => 'application/json',
        ])
            ->timeout($this->timeout)
            ->post($this->endpoint, $body);

        $raw = $response->body();

        // ⚠ 业务错误就在**响应体**里,而 HTTP 状态未必是 2xx(实测缺 key 是 400)。
        //   所以只要响应体非空就先解析它 —— 只看 HTTP 状态会把真实错误码丢掉,
        //   也就无法区分"配置错了"和"临时故障"。
        if ($raw !== '') {
            return self::extractAudio($raw, $this->audioField);
        }

        if (! $response->successful()) {
            throw new RuntimeException('火山引擎 HTTP '.$response->status().'(响应体为空)');
        }

        throw new TtsApiException(
            TtsApiException::PROVIDER_VOLCANO,
            'UnexpectedResponse',
            '火山返回了空响应体'
        );
    }

    /**
     * 组装请求体。
     *
     * 抽成公开的纯函数是有原因的:这个接口的**参数位置我已经猜错过三次**
     * (端点、鉴权头、音色字段名,以及语速参数的位置)——
     * 而这些错误全都不会报错,只会**静默失效**。把它独立出来就能直接单测,
     * 不必等到联调时靠听感发现"参数没生效"。
     *
     * @return array{req_params: array<string,mixed>}
     */
    public function buildRequestBody(string $text): array
    {
        $reqParams = [
            'text'    => $text,
            'speaker' => $this->speaker,
            'audio_params' => [
                'format'      => $this->format,
                'sample_rate' => $this->sampleRate,
                // ⚠ 语速必须在 audio_params 里叫 speech_rate —— 见构造函数注释,
                //   写错地方(如 req_params 层级)或写错名字(speed_ratio)都会被**静默忽略**。
                //   0 与不传等价(实测),所以无条件发送即可。
                'speech_rate' => $this->speechRate,
            ],
        ];

        // additions 只有非空时才带 —— 空串会让火山认为你显式给了个空配置
        if ($this->additions !== '') {
            $reqParams['additions'] = $this->additions;
        }

        // ⚠ 先铺基础字段、再让 extraReqParams 覆盖 —— 这样它能改掉任何一项(含 speaker),
        //   出问题时不用改代码就能试
        return [
            'req_params' => array_merge($reqParams, $this->extraReqParams),
        ];
    }

    /**
     * 从响应体里取出音频(base64,不含 data URI 前缀)。
     *
     * 纯静态函数 —— 响应形态还有一部分没核实,把它独立出来是为了能直接单测各种形态,
     * 也让"形态不对"变成一个可测的、有明确报错的分支,而不是玄学失败。
     *
     * ⚠ 刻意**不靠"成功码"判断成败**:火山的成功码取值还没核实(实测报错是 45000000),
     *   所以规则改成"**能取到音频就算成功**",取不到就用响应里的错误码报错 ——
     *   这样无论成功码是 0 还是别的值,都能工作。
     *
     * 兼容:
     *   · 单段响应:            {"header":{...},"data":"<base64>"}
     *   · 流式多段 JSON 拼接:   {...}{...}{...} 或每行一个(NDJSON)
     *     —— 端点叫 unidirectional,这是更可能的形态
     *
     * ⚠ 流式多段**必须逐段 base64 解码后再拼字节**,不能直接拼 base64 字符串 ——
     *   每段是独立编码的、可能带 `=` 填充,直接拼接会得到非法 base64。
     *
     * @param  string  $audioField  音频字段名(默认 data;探针显示是别的名字就改配置)
     *
     * @throws TtsApiException 云端返回错误码,或响应里根本定位不到音频
     */
    public static function extractAudio(string $body, string $audioField = 'data'): string
    {
        $quoted = preg_quote($audioField, '/');

        // 音频分片:流式响应里会有多段
        preg_match_all('/"'.$quoted.'"\s*:\s*"([^"]*)"/', $body, $dataMatches);
        $chunks = $dataMatches[1] ?? [];

        if ($chunks !== []) {
            $binary = '';

            foreach ($chunks as $chunk) {
                // 防御:万一返回的是 data URI 形式,先剥掉前缀再解码
                // ⚠ 不剥的话 `:` `/` `;` `,` 都不是合法 base64 字符,
                //   strict 模式会直接判 false,整段音频就丢了
                $piece = base64_decode(self::stripDataUri($chunk), true);

                if ($piece !== false) {
                    $binary .= $piece;
                }
            }

            if ($binary !== '') {
                // 契约要求返回 base64,而我们已经拿到了原始字节 —— 统一重新编码
                return base64_encode($binary);
            }
        }

        // 没取到音频 → 报响应里的错误码;连错误码都挖不到,说明响应形态与预期不符,
        // 此时把**原始响应**带出来自诊断 —— 一发探针就能看出真实结构该怎么解析
        throw self::extractError($body)
            ?? TtsApiException::unexpectedVolcanoResponse($body, json_decode($body, true) ?: null, $audioField);
    }

    /**
     * 防御:万一返回的是 data URI 形式,去掉前缀只留 base64。
     *
     * ⚠ 必须在 base64_decode **之前**做 —— `:` `/` `;` `,` 都不是合法 base64 字符,
     *   strict 模式下会直接判 false,整段音频就丢了。
     */
    private static function stripDataUri(string $data): string
    {
        return (string) preg_replace('#^data:audio/\w+;base64,#', '', $data);
    }

    /**
     * 从响应体里挖出错误(找不到错误码时返回 null,由调用方兜成"响应形态不符")。
     *
     * ⚠ 实测错误码在 **`header.code`** 里,不在顶层 —— 所以两条路都要走:
     *   先按已知的 header 封装解析,不行再退回顶层,都不行就交给调用方。
     */
    private static function extractError(string $body): ?TtsApiException
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            // 已知封装:{"header":{"code":...,"message":...}, ...}
            $header = $decoded['header'] ?? null;

            if (is_array($header) && isset($header['code'])) {
                return new TtsApiException(
                    TtsApiException::PROVIDER_VOLCANO,
                    (string) $header['code'],
                    sprintf('火山引擎返回错误 %s: %s', $header['code'], $header['message'] ?? '')
                );
            }

            // 兼容顶层形态(万一某些接口不套 header)
            if (isset($decoded['code'])) {
                return new TtsApiException(
                    TtsApiException::PROVIDER_VOLCANO,
                    (string) $decoded['code'],
                    sprintf('火山引擎返回错误 %s: %s', $decoded['code'], $decoded['message'] ?? '')
                );
            }

            return null;
        }

        // 多段 JSON / 非 JSON:用正则挖出第一个错误码
        if (preg_match('/"code"\s*:\s*"?(-?\d+)"?/', $body, $codeMatch)) {
            $message = '';

            if (preg_match('/"message"\s*:\s*"([^"]*)"/', $body, $msgMatch)) {
                $message = $msgMatch[1];
            }

            return new TtsApiException(
                TtsApiException::PROVIDER_VOLCANO,
                $codeMatch[1],
                sprintf('火山引擎返回错误 %s: %s', $codeMatch[1], $message)
            );
        }

        return null;
    }
}
