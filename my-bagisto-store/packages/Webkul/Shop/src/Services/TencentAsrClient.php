<?php

namespace Webkul\Shop\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 腾讯云「一句话识别」(SentenceRecognition)客户端。
 *
 * 为什么手写签名而不用官方 SDK:项目 composer 里只有通用 guzzle,没引入任何云服务
 * SDK,为一个接口拉一整套 SDK 不划算。TC3 签名是纯函数,单独成类还能脱离 HTTP 直接单测
 * —— 在密钥还没到位的阶段,这是唯一能先把"签名逻辑对不对"验掉一半的办法。
 *
 * ⚠ 签名实现已抽到 TencentCloudSigner(语音合成也要用同一套,两份实现必然漂移)。
 *   本类的构造签名**保持不变**,调用方无需改动。
 *
 * ⚠ 本类不含任何 Laravel 容器依赖,可直接 new 出来测。
 *
 * ⚠ 签名对「精确的 JSON 字符串」求 hash —— 必须自己 json_encode 一次,并用 withBody()
 * 发送**同一份字符串**。若图省事写 Http::post($url, $array),Guzzle 会二次序列化,
 * 键顺序 / 空格 / 斜杠转义任一处不同就会 AuthFailure.SignatureFailure。
 */
final class TencentAsrClient
{
    private const SERVICE = 'asr';

    private const VERSION = '2019-06-14';

    private const ACTION = 'SentenceRecognition';

    private const CONTENT_TYPE = 'application/json; charset=utf-8';

    /** 一句话识别支持的容器格式(AudioNormalizer 依赖它判断哪些容器可直通腾讯) */
    public const VOICE_FORMATS = ['wav', 'mp3', 'm4a', 'aac', 'amr', 'ogg', 'pcm', 'speex', 'silk'];

    private readonly TencentCloudSigner $signer;

    public function __construct(
        private readonly string $secretId,
        private readonly string $secretKey,
        private readonly string $endpoint = 'asr.tencentcloudapi.com',
        private readonly string $region = 'ap-guangzhou',
        private readonly string $engine = '16k_zh',
        private readonly int $timeout = 20,
    ) {
        $this->signer = new TencentCloudSigner(
            $secretId,
            $secretKey,
            $endpoint,
            self::SERVICE,
            self::VERSION,
            $region,
            self::CONTENT_TYPE,
        );
    }

    /**
     * 识别一段音频,返回文字。
     *
     * 返回空串是**正常结果**(听清了但没内容,比如纯音乐/静音),不是错误 —— 调用方
     * 据此提示「没听清」即可,不要当失败处理。
     *
     * @param  string  $audioBytes  原始音频二进制
     * @param  string  $voiceFormat wav / mp3 / m4a / aac / amr / ogg / pcm / speex / silk
     *
     * @throws RuntimeException 音频为空、网络失败、腾讯返回错误
     */
    public function sentenceRecognition(string $audioBytes, string $voiceFormat = 'wav'): string
    {
        if ($audioBytes === '') {
            throw new RuntimeException('音频为空');
        }

        if (! in_array($voiceFormat, self::VOICE_FORMATS, true)) {
            throw new RuntimeException('不支持的音频格式: '.$voiceFormat);
        }

        $body = [
            'ProjectId' => 0,

            // 2 = 一句话识别
            'SubServiceType' => 2,

            'EngSerViceType' => $this->engine,

            // ⚠ SourceType 必须传 **1**。
            //
            // 这个值反直觉，是实测出来的：传 0 会被服务端拒绝并报
            // `InvalidParameter: need param 'Url'`（意思是它认为你要走 URL 方式）。
            // 传 1 配合 Data(base64) + DataLen 才成功。不传则报 MissingParameter。
            // 别照着网上若干"0=base64"的说法改回去 —— 2026-09-29 用真实密钥验证过。
            'SourceType' => 1,

            'VoiceFormat' => $voiceFormat,

            'Data' => base64_encode($audioBytes),

            // ⚠ 是**原始音频字节数**,不是 base64 之后的长度
            'DataLen' => strlen($audioBytes),
        ];

        // ⚠ 只序列化这一次,签名和发送共用这个字符串(见类注释)
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new RuntimeException('请求体序列化失败');
        }

        $timestamp = time();

        $response = Http::withHeaders($this->signer->headers(self::ACTION, $payload, $timestamp))
            ->withBody($payload, $this->signer->contentType())
            ->timeout($this->timeout)
            ->post('https://'.$this->endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('腾讯云 HTTP '.$response->status());
        }

        $result = $response->json('Response') ?? [];

        if (! empty($result['Error'])) {
            throw new RuntimeException(sprintf(
                '腾讯云返回错误 %s: %s',
                $result['Error']['Code'] ?? 'Unknown',
                $result['Error']['Message'] ?? ''
            ));
        }

        return (string) ($result['Result'] ?? '');
    }
}
