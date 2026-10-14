<?php

use Webkul\Shop\Services\AudioNormalizer;
use Webkul\Shop\Services\TencentAsrClient;

/*
|--------------------------------------------------------------------------
| 音频容器嗅探
|--------------------------------------------------------------------------
|
| AudioNormalizer 是按文件魔数判断真实容器的,它修掉的是这个坑:
| 微信开发者工具里 uni.getRecorderManager() 声明 mp3 却实录 WebM,后端原先信任声明,
| 把 WebM 当 mp3 发给腾讯 → `audio data empty` → 前端显示成"网络异常,请重试"。
|
| 嗅探是这条链路的第一道闸门,判错的代价很隐蔽,所以每种容器都固定一个用例。
| 这些都是纯函数测试:无容器、无数据库、无密钥。
|
*/

it('detects wav', function () {
    expect((new AudioNormalizer())->detect("RIFF\x24\x08\x00\x00WAVEfmt "))->toBe('wav');
});

it('detects webm (the one that started all this)', function () {
    // 真实的开发者工具录音就是这个头:\x1A\x45\xDF\xA3 是 EBML 魔数
    expect((new AudioNormalizer())->detect("\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00\x1F\x42\x86\x81\x01"))->toBe('webm');
});

it('detects the containers Tencent accepts', function () {
    $n = new AudioNormalizer();

    expect($n->detect("OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('ogg')
        ->and($n->detect("#!AMR\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('amr')
        ->and($n->detect("#!SILK\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('silk')
        ->and($n->detect("\x02#!SILK\x0A\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('silk')
        ->and($n->detect("\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00"))->toBe('m4a')
        ->and($n->detect("ID3\x04\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('mp3');
});

it('separates aac from mp3 by the frame sync mask', function () {
    // ⚠ 两者都以 0xFF 开头,靠掩码区分,且**必须先判 aac** —— 顺序反了 AAC 会被 mp3 吞掉
    $n = new AudioNormalizer();

    expect($n->detect("\xFF\xF1\x50\x80\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('aac')
        ->and($n->detect("\xFF\xFB\x90\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('mp3');
});

it('returns mp4 for a non-m4a ISO-BMFF brand', function () {
    // 腾讯收 m4a 不收 mp4 —— 品牌判宽了会把 mp4 当 m4a 放过去
    expect((new AudioNormalizer())->detect("\x00\x00\x00\x20ftypisom\x00\x00\x00\x00"))->toBe('mp4');
});

it('returns empty string for unrecognised data instead of guessing', function () {
    // ⚠ 认不出就返回空串(交给 ffmpeg),**不做任何猜测** ——
    //   猜错等于把"格式不符"变成更难查的"腾讯报奇怪错误"
    expect((new AudioNormalizer())->detect("\xDE\xAD\xBE\xEF\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"))->toBe('')
        ->and((new AudioNormalizer())->detect(''))->toBe('');
});

it('passes Tencent-supported containers straight through without transcoding', function () {
    // 直通 = 原样返回,不调 ffmpeg(所以这里给一个不存在的 ffmpeg 路径也不会失败)
    $n = new AudioNormalizer('/definitely/not/ffmpeg');

    $wav = "RIFF\x24\x08\x00\x00WAVEfmt ";

    expect($n->normalize($wav, 'wav'))->toBe([$wav, 'wav']);

    // 客户端声明与实物冲突时,以**实物**为准(这正是那个 bug 的修法)
    expect($n->normalize($wav, 'mp3'))->toBe([$wav, 'wav']);
});

it('trusts the declared format only when the container cannot be sniffed', function () {
    // 裸 PCM 没有文件头,魔数无从谈起 —— 此时只能信声明
    $pcm = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10";

    expect((new AudioNormalizer('/definitely/not/ffmpeg'))->normalize($pcm, 'pcm'))->toBe([$pcm, 'pcm']);
});

it('throws when nothing can handle the audio', function () {
    // 腾讯不收、声明也不可信、ffmpeg 又不存在 → 抛异常,由控制器转成 422
    $webm = "\x1A\x45\xDF\xA3\x01\x00\x00\x00\x00\x00\x00\x1F\x42\x86\x81\x01";

    expect(fn () => (new AudioNormalizer('/definitely/not/ffmpeg'))->normalize($webm, 'mp3'))
        ->toThrow(RuntimeException::class);
});

it('rejects empty audio', function () {
    expect(fn () => (new AudioNormalizer())->normalize('', 'wav'))
        ->toThrow(RuntimeException::class);
});

it('keeps the voice-format list in sync with what the sniffer returns', function () {
    // 嗅探结果里除了 webm/mp4(刻意用来触发转码),其余都必须是腾讯支持的格式,
    // 否则会出现"嗅探成功却仍被拒"的诡异情况
    $n = new AudioNormalizer();

    $detectable = ['wav', 'mp3', 'm4a', 'aac', 'amr', 'ogg', 'silk'];

    foreach ($detectable as $format) {
        expect(TencentAsrClient::VOICE_FORMATS)->toContain($format);
    }
});
