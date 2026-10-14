<?php

namespace Webkul\Shop\Services;

/**
 * 腾讯云 TC3-HMAC-SHA256 签名（《签名方法 v3》），供各云服务客户端复用。
 *
 * ── 为什么要单独成类 ──
 *   签名算法与具体业务**完全无关**，只由 endpoint / service / version 和请求体决定。
 *   语音识别（asr）与语音合成（tts）用的是同一套算法，抽出来避免两份实现各自漂移
 *   —— 签名错的表现是 AuthFailure.SignatureFailure，极难排查，绝不能有两份。
 *
 * ⚠ 本类不含任何 Laravel 容器依赖，可直接 new 出来测（与 AudioNormalizer /
 *   TencentAsrClient 一致的约定）。timestamp 是入参，所以固定输入能得到确定输出，
 *   黄金向量单测不需要密钥、不需要 DB。
 *
 * ⚠ 服务器系统时间必须准，与腾讯侧偏差 > 5 分钟会 AuthFailure.SignatureExpire。
 */
final class TencentCloudSigner
{
    private const ALGORITHM = 'TC3-HMAC-SHA256';

    private const REQUEST_TYPE = 'tc3_request';

    public function __construct(
        private readonly string $secretId,
        private readonly string $secretKey,
        private readonly string $endpoint,
        private readonly string $service,
        private readonly string $version,
        private readonly string $region,
        private readonly string $contentType = 'application/json; charset=utf-8',
    ) {}

    /**
     * 生成可直接交给 Http::withHeaders() 的请求头。
     *
     * ⚠ $payload 必须是**将要发送的那一份精确字符串**：调用方自己 json_encode 一次，
     *   签名与 withBody() 共用同一个值。若图省事写 Http::post($url, $array)，Guzzle 会
     *   二次序列化，键顺序 / 空格 / 斜杠转义任一处不同就会 SignatureFailure。
     *
     * @param  int|null  $timestamp  留空取当前时间；显式传入是为了单测能得到确定输出
     */
    public function headers(string $action, string $payload, ?int $timestamp = null): array
    {
        // ⚠ 只算一次：下面的 date 与 X-TC-Timestamp 必须来自同一个值，
        //   否则跨零点的一瞬间会签出一个日期对不上的签名。
        $ts = $timestamp ?? time();

        $date = gmdate('Y-m-d', $ts);

        // ① 拼接规范请求串
        // ⚠ CanonicalHeaders 必须小写、按字典序、每行以 \n 结尾；**末尾也有 \n**。
        //   与下面 implode 的 \n 叠加后，headers 块与 SignedHeaders 之间会有一个空行 —— 这是对的。
        $canonicalHeaders = 'content-type:'.$this->contentType."\n"
            .'host:'.$this->endpoint."\n";
        $signedHeaders = 'content-type;host';

        $canonicalRequest = implode("\n", [
            'POST',
            '/',
            '',                     // query string 为空（参数走 body）
            $canonicalHeaders,
            $signedHeaders,
            hash('sha256', $payload),
        ]);

        // ② 拼接待签名字符串
        $credentialScope = $date.'/'.$this->service.'/'.self::REQUEST_TYPE;

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            (string) $ts,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        // ③ 逐层派生签名密钥 —— 前两步要原始二进制，所以传 true
        $secretDate    = hash_hmac('sha256', $date, 'TC3'.$this->secretKey, true);
        $secretService = hash_hmac('sha256', $this->service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', self::REQUEST_TYPE, $secretService, true);

        // ④ 计算签名
        // ⚠ 最后一步**输出 hex，不加 true** —— 整个签名里最容易写错的一行
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);

        return [
            'Authorization' => sprintf(
                '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                self::ALGORITHM,
                $this->secretId,
                $credentialScope,
                $signedHeaders,
                $signature
            ),
            'Content-Type' => $this->contentType,
            'Host' => $this->endpoint,
            'X-TC-Action' => $action,
            'X-TC-Version' => $this->version,
            'X-TC-Timestamp' => (string) $ts,
            'X-TC-Region' => $this->region,
        ];
    }

    /** 调用方必须用它作为 withBody() 的 Content-Type —— 与签名里的 content-type 同源 */
    public function contentType(): string
    {
        return $this->contentType;
    }
}
