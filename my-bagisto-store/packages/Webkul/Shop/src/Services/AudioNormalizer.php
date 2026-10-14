<?php

namespace Webkul\Shop\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 把「客户端传上来的任意音频」归一成腾讯云「一句话识别」能收的东西。
 *
 * ── 为什么需要这个类(2026-09-29 实测出来的坑) ──
 *   微信开发者工具模拟器里,uni.getRecorderManager() 虽然被要求 format:'mp3',
 *   实际产出的却是 **WebM**;而前端 formData 里把 format 写死成 'mp3'。
 *   后端原先直接信任这个声明,于是把 WebM 的字节当 mp3 发给腾讯,腾讯报
 *   `FailedOperation.ErrorRecognize: audio data empty`;前端 handleAsrResponse
 *   又把它统一显示成「网络异常,请重试」—— 排查方向整个被带偏。
 *
 * ── 两条铁律(本类存在的理由) ──
 *   ① **绝不相信客户端声明的 format**,一律按文件魔数嗅探真实容器。
 *   ② 腾讯一句话识别不收 webm/mp4,遇到就用 ffmpeg 转成 16k 单声道 WAV 再送。
 *
 * ⚠ 本类不含任何 Laravel 容器依赖,可直接 new 出来测(与 TencentAsrClient 一致)。
 */
final class AudioNormalizer
{
    /**
     * 嗅探用的文件头长度。
     * 16 字节足够覆盖下面所有魔数里偏移最大的一个(ISO-BMFF 的 ftyp 品牌在偏移 8~12)。
     */
    private const HEAD_LEN = 16;

    /** 腾讯一句话识别单次上限 1 分钟,转码时直接截断,免得超长音频白等 */
    private const MAX_SECONDS = 60;

    public function __construct(
        private readonly string $ffmpegPath = 'ffmpeg',
        private readonly int $timeout = 15,
    ) {}

    /**
     * 归一化入口。
     *
     * @param  string  $bytes  原始音频二进制
     * @param  string  $declaredFormat  客户端声明的格式,仅在嗅探不出时作兜底(见下)
     * @return array{0:string,1:string} [音频字节, 格式]
     *                                   格式保证落在 TencentAsrClient::VOICE_FORMATS 内
     *
     * @throws RuntimeException 嗅探和转码都救不回来时(音视频为空 / 认不出来 / ffmpeg 失败)
     */
    public function normalize(string $bytes, string $declaredFormat = ''): array
    {
        if ($bytes === '') {
            throw new RuntimeException('音频为空');
        }

        $detected = $this->detect($bytes);

        // 嗅探成功且腾讯收 → 一律以**嗅探结果**为准(铁律①)。
        // 注意这里刻意不看 $declaredFormat:声明与实物冲突时,实物永远是对的。
        if ($detected !== '' && in_array($detected, TencentAsrClient::VOICE_FORMATS, true)) {
            return [$bytes, $detected];
        }

        // 嗅探不出来 —— 典型是裸 PCM(没有文件头,魔数无从谈起),此时只能信客户端声明
        if ($detected === '' && in_array($declaredFormat, TencentAsrClient::VOICE_FORMATS, true)) {
            return [$bytes, $declaredFormat];
        }

        // 剩下两种都交给 ffmpeg(铁律②):
        //   · 嗅出了腾讯不收的容器(webm / mp4)
        //   · 什么也没嗅出来,且客户端声明也不可信
        // ffmpeg 自带探测,能处理就处理,不能处理会以非 0 退出,由上层转成 422。
        return [$this->toWav16k($bytes), 'wav'];
    }

    /**
     * 按文件魔数嗅探真实容器。
     *
     * ⚠ 认不出来时返回空串,**不做任何猜测** —— 猜错等于把问题从「格式不符」
     *   变成更难查的「腾讯报奇怪错误」,正是这次踩坑的根源。
     *
     * @return string wav / mp3 / m4a / aac / amr / ogg / silk / webm / mp4,或空串
     *                (webm / mp4 刻意不在腾讯支持列表内,返回它们是为了触发转码)
     */
    public function detect(string $bytes): string
    {
        $head = substr($bytes, 0, self::HEAD_LEN);

        if (strlen($head) < 8) {
            return '';
        }

        // RIFF....WAVE —— 注意 WAVE 在偏移 8,中间 4 字节是文件长度
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            return 'wav';
        }

        // EBML:webm 与 mkv 共用同一个魔数。腾讯两个都不收,所以不必再细分。
        // 开发者工具模拟器录出来的就是这个。
        if (substr($head, 0, 4) === "\x1A\x45\xDF\xA3") {
            return 'webm';
        }

        if (str_starts_with($head, 'OggS')) {
            return 'ogg';
        }

        if (str_starts_with($head, '#!AMR')) {
            return 'amr';
        }

        // SILK:有带 0x02 长度前缀的和不带的,两种都见过
        if (str_starts_with($head, '#!SILK') || str_starts_with($head, "\x02#!SILK")) {
            return 'silk';
        }

        // ISO-BMFF(m4a / mp4):偏移 4 起是 'ftyp',紧跟 4 字节品牌。
        // ⚠ 腾讯收 m4a 不收 mp4,所以品牌必须**精确匹配** —— 判宽了会把 mp4 当 m4a 放过。
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);

            return in_array($brand, ['M4A ', 'M4B '], true) ? 'm4a' : 'mp4';
        }

        // 带 ID3 标签的 mp3
        if (str_starts_with($head, 'ID3')) {
            return 'mp3';
        }

        // 无 ID3 的 mp3 与 AAC(ADTS)都靠帧同步字开头,必须靠掩码区分:
        // ADTS 是 0xFFF(12 位全 1),掩码 0xF6 能与 mp3 的 0xFFE/0xFFF 混合态分开。
        // ⚠ 顺序不能反:先判 aac 再判 mp3,否则 AAC 会被 mp3 的分支吞掉。
        $b1 = ord($head[1]);

        if (ord($head[0]) === 0xFF && ($b1 & 0xF6) === 0xF0) {
            return 'aac';
        }

        if (ord($head[0]) === 0xFF && ($b1 & 0xE0) === 0xE0) {
            return 'mp3';
        }

        return '';
    }

    /**
     * 任意容器 → 16k 单声道 WAV(16bit PCM)。
     *
     * 用 symfony/process 的**数组形式**传参,彻底绕开 shell 转义问题 ——
     * Windows 下 escapeshellarg 的行为与 Linux 不同,拼字符串很容易踩坑。
     *
     * 参数与 ASR_ENGINE=16k_zh 严格对应:16k 采样率 + 单声道,前端 H5 那条路
     * 也是转成同样的东西(见 utils/voice.js 的 toWav16k),两边保持一致。
     *
     * @throws RuntimeException ffmpeg 缺失 / 超时 / 转码失败 / 产出为空
     */
    private function toWav16k(string $bytes): string
    {
        $src = tempnam(sys_get_temp_dir(), 'asr_src_');
        $dst = tempnam(sys_get_temp_dir(), 'asr_dst_');

        if ($src === false || $dst === false) {
            throw new RuntimeException('无法创建临时文件');
        }

        try {
            file_put_contents($src, $bytes);

            $process = new Process([
                $this->ffmpegPath,
                '-hide_banner',
                '-loglevel', 'error',
                '-y',                   // tempnam 已经建出空文件,不加 -y 会卡在覆盖确认
                '-i', $src,
                '-vn',                  // webm 可能带视频轨,只要音频
                '-t', (string) self::MAX_SECONDS,
                '-ac', '1',             // 单声道
                '-ar', '16000',         // 16k
                '-f', 'wav',
                $dst,
            ]);

            $process->setTimeout($this->timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException('ffmpeg 转码失败: '.trim($process->getErrorOutput()));
            }

            $wav = (string) file_get_contents($dst);

            if ($wav === '') {
                throw new RuntimeException('ffmpeg 转码产出为空');
            }

            return $wav;
        } finally {
            @unlink($src);
            @unlink($dst);
        }
    }
}
