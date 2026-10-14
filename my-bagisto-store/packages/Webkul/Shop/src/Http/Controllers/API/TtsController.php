<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Services\AudioSilenceTrimmer;
use Webkul\Shop\Services\TencentTtsClient;
use Webkul\Shop\Services\TtsApiException;
use Webkul\Shop\Services\TtsProvider;
use Webkul\Shop\Services\VolcanoTtsClient;

/**
 * 语音合成:AI 回复正文 → 云端 TTS → base64 音频数组。
 *
 * 谁在用:
 *   · 小程序 / H5 的 AI 对话页(pages/ai/index.vue)—— 回复自动朗读 + 手动重播
 *
 * 客户端**不直连**云端,一律经本服务代理 —— 密钥绝不能进小程序包。
 *
 * ── provider 可切换(见 config/tts.php 的 TTS_PROVIDER) ──
 *   · tencent —— 腾讯云「基础语音合成」。传统参数合成,人机感明显。
 *   · volcano —— 火山引擎「语音合成大模型」(豆包同源)。韵律与情感好得多,
 *                是当前的选择,因为朗读要"像人"而不是"像机器"。
 *
 *   切换 provider **只改 .env 一行**,本控制器与**前端都不用动** ——
 *   前端只认 /api/tts 的 { success, audios } 契约,与用哪家云无关。
 *
 * ── 返回约定:与 AsrController 完全一致 ──
 *   一律 HTTP 200 + `success` 字段区分成败(合成失败是预期内的常态:资源包用尽、
 *   音色不对、限流),用 4xx/5xx 表达不准确。只有"请求本身非法"(空文本/超长)才用 422。
 *
 *   ⚠ 尤其不能返回 401 —— utils/request.js:37-44 见到 401 会**清空登录态**。
 *   朗读是游客也能用的附加功能,绝不能因为一次朗读把用户踢下线。前端为此还特意
 *   没走 utils/request.js,自带了极薄的请求封装。
 *
 * ── 为什么返回数组而不是单个音频 ──
 *   腾讯单次合成有字数上限,超长必须拆段。裸拼 mp3 不可靠(腾讯返回的 mp3 是否 CBR /
 *   是否带 ID3v2 头未知,裸拼帧在部分播放器会"播到拼接点就停");用 ffmpeg 拼则会把
 *   ASR 那边**可选**的 ffmpeg 变成硬依赖。而前端本来就是顺序播放,多段与一段对用户
 *   完全等价 —— 所以契约固定为数组(单段也是长度 1 的数组),前端只有一条代码路径。
 *
 * 配置见 config/tts.php。密钥未配置时返回 TTS_NOT_CONFIGURED,绝不 500。
 */
class TtsController extends APIController
{
    public function synthesize(Request $request): JsonResponse
    {
        // 可能要多段串行调用腾讯云,默认 max_execution_time=30s 不够
        @set_time_limit(60);

        // ① 参数校验
        $text = trim((string) $request->input('text', ''));

        if ($text === '') {
            return $this->reject('NO_TEXT', '文本为空');
        }

        if (mb_strlen($text) > (int) config('tts.max_total_chars')) {
            return $this->reject('TOO_LONG', '文本过长');
        }

        // ② 组装 provider。配置不完整(缺密钥等)→ 明确告知,不发任何外部请求、不报错
        //    (密钥还没到位时,前端就靠这个 code 静默跳过朗读)
        $provider = $this->resolveProvider();

        if ($provider === null) {
            return $this->fail('TTS_NOT_CONFIGURED', '语音合成尚未配置');
        }

        // ③ 合成 —— 超长拆段,逐段请求
        try {
            // 裁首尾静音:引擎给每段都垫了 0.2 秒左右的留白,相邻两段会叠成约 0.4 秒死寂。
            // 关掉也不影响正确性,只是句间停顿会变长 —— 见 AudioSilenceTrimmer。
            $trimmer = config('tts.trim_silence')
                ? new AudioSilenceTrimmer((string) config('tts.ffmpeg_path'))
                : null;

            $audios = [];

            foreach ($this->splitForTts($text, (int) config('tts.max_chars')) as $segment) {
                // ⚠ 契约差异:provider 返回的是 **base64 字符串**,而裁剪器处理的是
                //   **原始音频字节** —— 必须在这里转换。
                //   把 base64 串直接喂给 ffmpeg 会报 "Invalid data found when processing input",
                //   而裁剪器的容错会**静默回退成原音频**,表现就是"裁剪没生效"(实测踩过,
                //   排查了好几轮 —— 所以那边失败时会写一条告警日志,不再完全无声)。
                $audio = $provider->synthesize($segment);

                if ($trimmer !== null) {
                    $raw = base64_decode($audio, true);
                    $audio = $raw === false ? $audio : base64_encode($trimmer->trim($raw));
                }

                $audios[] = $audio;
            }
        } catch (\Throwable $e) {
            // ⚠ 细节只进日志,不给前端看 —— 避免泄漏签名/密钥线索,也免得前端把
            //    "资源包用尽"和"音色不对"呈现成两种不同提示(用户看不懂也没法处理)
            report($e);

            // 系统性故障(资源包用尽 / 鉴权失败 / 服务未开通 / 音色 ID 错)重试不会变好,
            // 给一个**独立的 code**,让前端立刻放弃并明确提示 ——
            // 否则用户遇到的就是"点了没声音"这种无从诊断的黑盒。
            if ($e instanceof TtsApiException && $e->isSystemic()) {
                return $this->fail('TTS_UNAVAILABLE', '朗读功能暂时不可用');
            }

            return $this->fail('TTS_FAILED', '语音合成失败');
        }

        // ⚠ 只要有一段失败就整体失败(在 catch 里抛出),不在后端悄悄丢内容 ——
        //   否则用户会听到"中间少了一句"却毫无提示,比整段不念更难排查。
        return response()->json([
            'success' => true,
            'audios'  => $audios,
            'count'   => count($audios),
        ]);
    }

    /**
     * 按 config('tts.provider') 组装当前 provider;配置不完整时返回 null。
     *
     * ⚠ 两家的"配置完整"判据**不同**(腾讯要一对 AK/SK,火山要 AppID + Access Token),
     *   所以判据写在各自分支里,不往上层泄漏 —— 上层只关心"有没有 provider"。
     */
    private function resolveProvider(): ?TtsProvider
    {
        if (! config('tts.enabled')) {
            return null;
        }

        if (config('tts.provider') === 'volcano') {
            $apiKey = (string) config('tts.volcano.api_key');

            // 火山的凭据就一个 API Key(走 x-api-key 头),不像腾讯要一对 AK/SK
            if ($apiKey === '') {
                return null;
            }

            return new VolcanoTtsClient(
                $apiKey,
                (string) config('tts.volcano.endpoint'),
                (string) config('tts.volcano.resource_id'),
                (string) config('tts.volcano.speaker'),
                (string) config('tts.volcano.format'),
                (int) config('tts.volcano.sample_rate'),
                (int) config('tts.volcano.speech_rate'),
                (string) config('tts.volcano.additions'),
                (array) config('tts.volcano.extra_req_params', []),
                (string) config('tts.volcano.audio_field'),
                (int) config('tts.timeout'),
            );
        }

        // 默认腾讯
        $secretId = (string) config('tts.secret_id');
        $secretKey = (string) config('tts.secret_key');

        if ($secretId === '' || $secretKey === '') {
            return null;
        }

        return new TencentTtsClient(
            $secretId,
            $secretKey,
            (string) config('tts.endpoint'),
            (string) config('tts.region'),
            (string) config('tts.voice_type'),
            (string) config('tts.codec'),
            (int) config('tts.sample_rate'),
            (float) config('tts.speed'),
            (float) config('tts.volume'),
            (int) config('tts.timeout'),
        );
    }

    /**
     * 把长文本切成每段不超过 $maxChars 的片段。
     *
     * 三级退让:句末标点 → 逗号类标点 → 按长度硬切。
     * 前端已把句子切到 120 字以内,所以这里通常只有一段,只在「手动整段重播」时才会用到。
     *
     * @return string[]
     */
    private function splitForTts(string $text, int $maxChars): array
    {
        $maxChars = max(1, $maxChars);

        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        // 先按句末标点切,再逐句累积到不超过上限
        $sentences = preg_split('/(?<=[。！？；…!?;])/u', $text) ?: [$text];

        $segments = [];
        $buffer = '';

        foreach ($sentences as $sentence) {
            foreach ($this->hardSplit($sentence, $maxChars) as $piece) {
                if (mb_strlen($buffer.$piece) > $maxChars) {
                    if ($buffer !== '') {
                        $segments[] = $buffer;
                    }
                    $buffer = $piece;
                } else {
                    $buffer .= $piece;
                }
            }
        }

        if ($buffer !== '') {
            $segments[] = $buffer;
        }

        return $segments;
    }

    /**
     * 单句仍然超长时的兜底:退让到逗号类标点,实在没有就按长度硬切。
     *
     * @return string[]
     */
    private function hardSplit(string $text, int $maxChars): array
    {
        if (mb_strlen($text) <= $maxChars) {
            return [$text];
        }

        $out = [];

        foreach (preg_split('/(?<=[，,、：:])/u', $text) ?: [$text] as $part) {
            // ⚠ 必须用 mb_substr 按字符切,substr 会把中文切成半个字(变成乱码)
            while (mb_strlen($part) > $maxChars) {
                $out[] = mb_substr($part, 0, $maxChars);
                $part = mb_substr($part, $maxChars);
            }

            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /**
     * 业务性失败:HTTP 200 + success=false。
     * 前端只判 success,与"没配好"走同一套静默跳过。
     */
    private function fail(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ]);
    }

    /**
     * 请求本身非法(空文本/超长):才用 422。
     */
    private function reject(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code'    => $code,
            'message' => $message,
        ], 422);
    }
}
