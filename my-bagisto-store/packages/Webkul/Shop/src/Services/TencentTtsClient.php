<?php

namespace Webkul\Shop\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 腾讯云「语音合成(基础版)」(TextToVoice)客户端。
 *
 * 与同目录的 TencentAsrClient 同源:手写签名、不引官方 SDK、无 Laravel 容器依赖
 * (可直接 new 出来测)。签名逻辑已抽到 TencentCloudSigner 供两者共用 ——
 * 签名算法的服务差异只有 endpoint / service / version 三处。
 *
 * ⚠ 铁律与 ASR 完全相同:签名对「精确的 JSON 字符串」求 hash,必须自己 json_encode
 *   一次,并用 withBody() 发送**同一份字符串**。写 Http::post($url, $array) 会让
 *   Guzzle 二次序列化,键顺序/空格/斜杠转义任一处不同就 AuthFailure.SignatureFailure。
 *
 * ⚠ 本类只抛 RuntimeException(音频为空/格式不支持/网络失败/腾讯报错),
 *   report() 由控制器做 —— 服务层不自己 try,与现有约定一致。
 */
final class TencentTtsClient implements TtsProvider
{
    private const SERVICE = 'tts';

    /** 官方《语音合成》接口版本,注意与 ASR 的 2019-06-14 不同 */
    private const VERSION = '2019-08-23';

    private const ACTION = 'TextToVoice';

    private const CONTENT_TYPE = 'application/json; charset=utf-8';

    /**
     * @param  string  $voiceType  音色 ID;留空则不传该字段,由服务端用默认音色。
     *                             ⚠ 真实音色 ID 必须从控制台音色列表取 —— 接口只做范围校验,
     *                             填一个不存在的 ID 也能通过校验(2026-09-29 实测)。
     */
    public function __construct(
        private readonly string $secretId,
        private readonly string $secretKey,
        private readonly string $endpoint = 'tts.tencentcloudapi.com',
        private readonly string $region = 'ap-guangzhou',
        private readonly string $voiceType = '',
        private readonly string $codec = 'mp3',
        private readonly int $sampleRate = 16000,
        private readonly float $speed = 0,
        private readonly float $volume = 0,
        private readonly int $timeout = 20,
    ) {}

    /**
     * 合成一段文字,返回 **base64 编码的音频**(不含 data URI 前缀)。
     *
     * @param  string  $text  待合成文字,长度需由调用方保证在上限内
     *
     * @throws TtsApiException 腾讯返回业务错误(带原始错误码,可判系统性)
     * @throws RuntimeException 文字为空、网络失败、响应里找不到音频
     */
    public function synthesize(string $text): string
    {
        if ($text === '') {
            throw new RuntimeException('待合成文字为空');
        }

        $body = [
            'Text'      => $text,

            // 自定义串,仅用于在腾讯侧排查问题时定位是哪一次调用
            'SessionId' => bin2hex(random_bytes(8)),

            'ProjectId'  => 0,
            'Codec'      => $this->codec,
            'SampleRate' => $this->sampleRate,
            'Speed'      => $this->speed,
            'Volume'     => $this->volume,
        ];

        // 留空则**整个字段不传**,走服务端默认音色。
        // ⚠ 见构造函数注释:不要为了"看起来完整"随便填一个数字。
        if ($this->voiceType !== '') {
            $body['VoiceType'] = $this->voiceType;
        }

        // ⚠ 只序列化这一次,签名和发送共用这个字符串(见类注释)
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('请求体序列化失败');
        }

        $timestamp = time();

        $signer = new TencentCloudSigner(
            $this->secretId,
            $this->secretKey,
            $this->endpoint,
            self::SERVICE,
            self::VERSION,
            $this->region,
            self::CONTENT_TYPE,
        );

        $response = Http::withHeaders($signer->headers(self::ACTION, $payload, $timestamp))
            ->withBody($payload, $signer->contentType())
            ->timeout($this->timeout)
            ->post('https://'.$this->endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('腾讯云 HTTP '.$response->status());
        }

        $result = $response->json('Response') ?? [];

        if (! empty($result['Error'])) {
            throw new TtsApiException(
                TtsApiException::PROVIDER_TENCENT,
                (string) ($result['Error']['Code'] ?? 'Unknown'),
                sprintf(
                    '腾讯云返回错误 %s: %s',
                    $result['Error']['Code'] ?? 'Unknown',
                    $result['Error']['Message'] ?? ''
                )
            );
        }

        // ⚠ 音频字段名 Audio 是照官方文档写的,但**本机环境无法核实**
        //   (外网文档被网络策略拦截;而线上账号资源包已用尽,拿不到一次成功响应)。
        //   所以这里做**自诊断**:取不到时把 Response 里真实存在的键名打进异常信息,
        //   第一次真跑就能立刻看出正确字段名 —— 而不是抛一句没用的"合成失败"。
        $audio = $result['Audio'] ?? null;

        if (! is_string($audio) || $audio === '') {
            throw new TtsApiException(
                TtsApiException::PROVIDER_TENCENT,
                'UnexpectedResponse',
                sprintf(
                    '腾讯云响应里没有 Audio 字段。Response 实际含键: [%s]。%s',
                    implode(', ', array_keys($result)),
                    '若键名不同,请据此修正 TencentTtsClient 中的取值。'
                )
            );
        }

        // 防御:万一返回的是 data URI 形式,去掉前缀只留 base64
        return (string) preg_replace('#^data:audio/\w+;base64,#', '', $audio);
    }
}
