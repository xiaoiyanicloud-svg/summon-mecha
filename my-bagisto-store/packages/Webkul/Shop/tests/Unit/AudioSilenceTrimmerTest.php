<?php

use Webkul\Shop\Services\AudioSilenceTrimmer;

/*
|--------------------------------------------------------------------------
| 首尾静音裁剪
|--------------------------------------------------------------------------
|
| 引擎给每段音频都垫了约 0.2 秒留白(头部 0.21s + 尾部 0.19s,与句末标点无关),
| 而朗读是**按句分段**播的 —— 相邻两段的尾部+头部静音会叠成约 0.4 秒死寂,
| 5 句话就是 2 秒多空白。这个类就是来消掉它的(实测每段省 0.27~0.30 秒)。
|
| ⚠ 这里测的重点不是"裁得干不干净",而是 **失败时绝不能丢音频** ——
|   裁不掉最多多 0.4 秒停顿,而丢音频就是"朗读没声音",后者严重得多。
|
*/

it('returns the audio untouched when ffmpeg is unavailable', function () {
    // 这是本类最重要的行为:ffmpeg 缺失/路径错时,必须**原样返回**
    $trimmer = new AudioSilenceTrimmer('/definitely/not/ffmpeg');
    $audio = 'fake-mp3-bytes-'.str_repeat('x', 200);

    expect($trimmer->trim($audio))->toBe($audio);
});

it('returns the audio untouched when ffmpeg cannot parse the input', function () {
    // 输入不是合法音频时 ffmpeg 会失败 —— 同样必须原样返回,而不是返回空
    $trimmer = new AudioSilenceTrimmer('ffmpeg');
    $junk = random_bytes(500);

    expect($trimmer->trim($junk))->toBe($junk);
});

it('returns empty input as-is without invoking anything', function () {
    expect((new AudioSilenceTrimmer('/definitely/not/ffmpeg'))->trim(''))->toBe('');
});

it('never returns something shorter than nothing when it fails', function () {
    // 断言"失败即原样返回"这条不变量在多种失败下都成立 ——
    // 因为一旦返回空串,前端拿到 audios: [''] 会当成合成失败,整句没声音。
    foreach (['/nope/ffmpeg', '', 'true'] as $binary) {
        $audio = str_repeat('A', 128);
        $result = (new AudioSilenceTrimmer($binary))->trim($audio);

        expect($result)->toBe($audio);
    }
});
