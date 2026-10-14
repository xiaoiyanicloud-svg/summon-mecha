<?php

namespace Webkul\Shop\Services;

use Symfony\Component\Process\Process;

/**
 * 裁掉合成音频**首尾**的静音,让相邻两句之间不再有死寂。
 *
 * ── 为什么需要它(2026-09-29 实测) ──
 *   火山(以及腾讯)产出的每段音频都自带留白:
 *     头部静音 ≈ 0.21s + 尾部静音 ≈ 0.19s,且**与句末标点无关** —— 是引擎自己的 padding。
 *   朗读是按句分段播的,于是相邻两段之间会叠成:
 *     上一段尾部 0.19s + 下一段头部 0.21s ≈ **0.4 秒死寂**,
 *   5 句话就是 2 秒多的空白。用户感受到的就是"念一句、停一下"。
 *
 * ⚠ 只裁**首尾**,句中停顿必须保留 —— 那是逗号/顿号的自然语气,裁掉会变成一口气念完。
 *   靠 `silenceremove` 的 start_periods 限定在开头,尾部则用"反转→裁头→再反转"实现。
 *
 * ⚠ 入参与返回值都是**原始音频字节**,不是 base64。
 *   provider 的契约是 base64(要进 JSON),所以**控制器负责转换** ——
 *   把 base64 串直接喂给 ffmpeg 会报 "Invalid data found when processing input",
 *   而下面的容错会静默回退成原音频,表现就是"裁剪没生效"。这个坑实测踩过,
 *   所以失败时会留一条告警日志,不再完全无声。
 *
 * ⚠ 失败时**原样返回** —— 宁可不裁,也绝不能让朗读没声音。
 *   这是本类最重要的行为,单独有用例锁住。
 */
final class AudioSilenceTrimmer
{
    /** 本次进程内是否已告警过 —— 避免一个长回复里每句都写一条(配置错了会刷屏) */
    private static bool $warned = false;

    /**
     * ⚠ 这个滤镜串是实测调出来的,别凭感觉改:
     *   · start_periods=1  —— 只处理开头那一段静音(不碰句中停顿)
     *   · start_silence=0.05 —— 留 0.05s,避免切得太"秃"显得突兀
     *   · start_threshold=-45dB —— 阈值取得比 -40 更保守,防止把字首的气音/轻辅音也切掉
     *   · detection=peak —— 用峰值而非 RMS 判定,对语音更稳
     *   · 尾部靠 areverse 反转后同样处理再转回来
     */
    private const FILTER = 'silenceremove=start_periods=1:start_silence=0.05:start_threshold=-45dB:detection=peak,'
        .'areverse,'
        .'silenceremove=start_periods=1:start_silence=0.05:start_threshold=-45dB:detection=peak,'
        .'areverse';

    /**
     * @param  string  $bitrate  重编码码率。裁剪必须重编码,所以显式给足码率:
     *                           源是 96kbps,这里给 128k —— 高于源,重编码基本无损
     *                           (用 ffmpeg 默认码率会明显掉到 60k 以下,音质会变差)
     */
    public function __construct(
        private readonly string $ffmpegPath = 'ffmpeg',
        private readonly string $bitrate = '128k',
        private readonly int $timeout = 10,
    ) {}

    /**
     * @return string 裁剪后的音频;**任何失败都返回原始音频**
     */
    public function trim(string $audio): string
    {
        if ($audio === '') {
            return $audio;
        }

        $src = tempnam(sys_get_temp_dir(), 'tts_trim_in_');
        $dst = tempnam(sys_get_temp_dir(), 'tts_trim_out_');

        if ($src === false || $dst === false) {
            return $audio;
        }

        try {
            file_put_contents($src, $audio);

            $process = new Process([
                $this->ffmpegPath,
                '-hide_banner',
                '-loglevel', 'error',
                '-y',
                '-i', $src,
                '-af', self::FILTER,
                '-c:a', 'libmp3lame',
                '-b:a', $this->bitrate,
                // ⚠ 必须显式给输出格式:临时文件没有扩展名,ffmpeg 无法从后缀推断,
                //   不给会直接报 "Unable to find a suitable output format" 而失败。
                //   (AudioNormalizer 那边写的是 -f wav,同理)
                '-f', 'mp3',
                $dst,
            ]);

            $process->setTimeout($this->timeout);
            $process->run();

            if (! $process->isSuccessful()) {
                $this->warnOnce('ffmpeg 退出码 '.$process->getExitCode().': '.mb_substr($process->getErrorOutput(), 0, 200));

                return $audio;
            }

            $trimmed = (string) file_get_contents($dst);

            // ⚠ 只判空,**不要**拿字节数跟原音频比长短:
            //   我们是按 128k 重编码的(源约 96k),裁掉静音后**字节数反而会变大** ——
            //   按字节比长短会把正确结果当成异常丢掉,裁剪等于白做(实测踩过)。
            if ($trimmed === '') {
                return $audio;
            }

            return $trimmed;
        } catch (\Throwable $e) {
            $this->warnOnce('异常 '.get_class($e).': '.$e->getMessage());

            // ⚠ 这里刻意**不抛异常**:ffmpeg 缺失/超时都不该让朗读失败,
            //   最多就是白留了 0.4 秒停顿而已 —— 两害相权取其轻。
            return $audio;
        } finally {
            @unlink($src);
            @unlink($dst);
        }
    }

    /**
     * 告警,但**每个进程只报一次**。
     *
     * ⚠ 这条日志的存在意义:裁剪失败会**静默回退成原音频**,现象只是"停顿没变短",
     *   完全看不出是故障 —— 我之前就是这么被它骗了好几轮(实际是 base64/字节用错了)。
     *   留一条告警,下次就能一眼看到。
     *   用 once 是因为一个长回复有十几句,配置错了会刷满日志。
     */
    private function warnOnce(string $reason): void
    {
        if (self::$warned) {
            return;
        }

        self::$warned = true;

        \Log::warning('[tts] 首尾静音裁剪失败,已回退为原始音频(朗读照常,只是停顿会偏长): '.$reason);
    }
}
