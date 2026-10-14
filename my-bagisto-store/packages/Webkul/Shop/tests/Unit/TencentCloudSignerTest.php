<?php

use Webkul\Shop\Services\TencentCloudSigner;

/*
|--------------------------------------------------------------------------
| TC3 签名的黄金向量
|--------------------------------------------------------------------------
|
| 这个签名实现原本长在 TencentAsrClient 里(private sign()),2026-09-29 抽到
| TencentCloudSigner 供语音识别与语音合成共用。
|
| 抽取时最容易出的错是"看起来没坏、其实签出来的串变了"—— 而签名错的表现是
| AuthFailure.SignatureFailure,极难排查。所以下面的期望值是**抽取之前**从原实现
| dump 出来的字节,必须逐字符相同。
|
| ⚠ 改这里的期望值之前先想清楚:签名算法没有任何可"优化"的余地。
|
*/

/** 固定输入 —— 全部是假密钥,不涉及真实凭据 */
function goldenInputs(): array
{
    return [
        'secretId'  => 'AKIDEXAMPLE',
        'secretKey' => 'SecretExample123',
        'endpoint'  => 'asr.tencentcloudapi.com',
        'region'    => 'ap-guangzhou',
        'payload'   => '{"ProjectId":0,"SubServiceType":2}',
        'timestamp' => 1759100000,
    ];
}

function goldenSigner(): TencentCloudSigner
{
    $in = goldenInputs();

    return new TencentCloudSigner(
        $in['secretId'],
        $in['secretKey'],
        $in['endpoint'],
        'asr',
        '2019-06-14',
        $in['region'],
    );
}

it('produces the exact same Authorization as before the extraction', function () {
    $in = goldenInputs();

    $headers = goldenSigner()->headers('SentenceRecognition', $in['payload'], $in['timestamp']);

    // 期望值来自抽取前的原实现(dump 得到),不是"跑一遍看看是什么"
    expect($headers['Authorization'])->toBe(
        'TC3-HMAC-SHA256 Credential=AKIDEXAMPLE/2025-09-28/asr/tc3_request, '
        .'SignedHeaders=content-type;host, '
        .'Signature=1ea210fa715e9a13b989d727343d1c8e17fe47f3e0fe595a840648958604a66f'
    );
});

it('fills every X-TC-* header the gateway expects', function () {
    $in = goldenInputs();

    $headers = goldenSigner()->headers('SentenceRecognition', $in['payload'], $in['timestamp']);

    expect($headers['X-TC-Action'])->toBe('SentenceRecognition')
        ->and($headers['X-TC-Version'])->toBe('2019-06-14')
        ->and($headers['X-TC-Timestamp'])->toBe('1759100000')
        ->and($headers['X-TC-Region'])->toBe('ap-guangzhou')
        ->and($headers['Host'])->toBe($in['endpoint'])
        ->and($headers['Content-Type'])->toBe('application/json; charset=utf-8');
});

it('is deterministic for the same inputs', function () {
    $in = goldenInputs();

    $first = goldenSigner()->headers('SentenceRecognition', $in['payload'], $in['timestamp']);
    $second = goldenSigner()->headers('SentenceRecognition', $in['payload'], $in['timestamp']);

    expect($first)->toBe($second);
});

it('derives the credential date from the passed timestamp, not from now', function () {
    // ⚠ 专打一个坑:日期与 X-TC-Timestamp 必须来自**同一个** timestamp。
    //   原实现里若写成 gmdate('Y-m-d') 就会在跨零点的一瞬间签出日期对不上的签名。
    $headers = goldenSigner()->headers('SentenceRecognition', '{}', 1759100000);

    expect($headers['X-TC-Timestamp'])->toBe('1759100000')
        ->and($headers['Authorization'])->toContain('/2025-09-28/asr/tc3_request');
});

it('falls back to the current time when no timestamp is given', function () {
    $before = time();
    $headers = goldenSigner()->headers('SentenceRecognition', '{}');
    $after = time();

    $ts = (int) $headers['X-TC-Timestamp'];

    expect($ts)->toBeGreaterThanOrEqual($before)
        ->and($ts)->toBeLessThanOrEqual($after)
        // 日期与 timestamp 必须是同一时刻推出来的
        ->and($headers['Authorization'])->toContain('/'.gmdate('Y-m-d', $ts).'/asr/tc3_request');
});

it('changes only the scope when the service differs', function () {
    // 语音合成用的是同一套算法,差异只该出现在 credential scope 的 service 段
    $in = goldenInputs();

    $asr = goldenSigner()->headers('SentenceRecognition', $in['payload'], $in['timestamp']);

    $signer = new TencentCloudSigner(
        $in['secretId'], $in['secretKey'],
        'tts.tencentcloudapi.com', 'tts', '2019-08-23', $in['region'],
    );
    $tts = $signer->headers('TextToVoice', $in['payload'], $in['timestamp']);

    expect($tts['Authorization'])->toContain('/tts/tc3_request')
        ->and($tts['X-TC-Version'])->toBe('2019-08-23')
        ->and($tts['Host'])->toBe('tts.tencentcloudapi.com')
        ->and($tts['Authorization'])->not->toBe($asr['Authorization']);
});
