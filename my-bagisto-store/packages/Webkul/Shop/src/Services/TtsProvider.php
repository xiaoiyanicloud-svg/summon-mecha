<?php

namespace Webkul\Shop\Services;

use RuntimeException;

/**
 * 语音合成 provider 的统一契约。
 *
 * 有它,TtsController 就完全不必知道"用的是哪家云" —— 换 provider 只改
 * config/tts.php 里的 TTS_PROVIDER 一行,控制器和**前端都不用动**
 * (前端只认 /api/tts 的 { success, audios } 契约,与 provider 无关)。
 *
 * ⚠ 各家的差异**全部收在各自实现里**,不往上层泄漏:
 *   · 签名方式(腾讯 TC3-HMAC-SHA256 / 火山 openspeech 的 Bearer;token)
 *   · 单次字数上限
 *   · 错误码规则(点号前缀 vs 数字码)—— 由 TtsApiException::isSystemic() 按 provider 判
 */
interface TtsProvider
{
    /**
     * 合成一段文本。
     *
     * @param  string  $text  待合成文字;长度由调用方按 config('tts.max_chars') 保证
     * @return string base64 编码的音频(**不含 data URI 前缀**)
     *
     * @throws TtsApiException 云端返回业务错误(带原始错误码,可判系统性)
     * @throws RuntimeException 网络失败 / 响应无法解析
     */
    public function synthesize(string $text): string;
}
