<?php

namespace Webkul\Shop\Http\Controllers\API;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Webkul\Shop\Services\AudioNormalizer;
use Webkul\Shop\Services\TencentAsrClient;

/**
 * 语音识别:前端录音上传 → 腾讯云「一句话识别」→ 文字。
 *
 * 谁在用:
 *   · H5          —— 唯一路径(浏览器录 16k 单声道 WAV 后上传)
 *   · 微信小程序   —— **兜底**路径。小程序优先走微信「同声传译」插件(免费、实时、
 *                     不经本服务);插件未审批 / 不可用时降级到本接口(录 mp3 上传)
 *
 * 因此本接口是「两端共用的兜底链路」,不是 H5 专属 —— 命名刻意不带 h5。
 *
 * ── 格式处理:不信任客户端声明 ──
 *   前端**写死的 format 可能与实物不符**(实测:开发者工具里声明 mp3、实录 WebM),
 *   所以真实容器一律由 AudioNormalizer 按文件魔数嗅探;腾讯不收的容器
 *   (webm / mp4)用 ffmpeg 转 16k 单声道 WAV 后再送识别。详见该类注释。
 *
 * ── 返回约定:一律 HTTP 200,用 success 字段区分成败 ──
 *   语音"失败"是**预期内的常态**(环境吵、没听清、密钥没配到),不是"请求非法",
 *   用 4xx/5xx 表达不准确。且上传不走 utils/request.js(它 Content-Type 写死 JSON,
 *   传不了文件),走 uni.uploadFile 时无论状态码都会进 success 回调,统一 200 前端少一层判断。
 *
 *   ⚠ 尤其不能返回 401 —— utils/request.js:37-44 见到 401 会**清空登录态**,
 *   而语音是游客也能用的入口。只有"请求本身非法"(没文件/超体积/音频无法处理)才用 422。
 *
 * 配置见 config/asr.php。密钥未配置时返回 ASR_NOT_CONFIGURED,绝不 500。
 */
class AsrController extends APIController
{
    public function recognize(Request $request): JsonResponse
    {
        // 音频要过公网,默认 max_execution_time=30s 偶尔不够
        @set_time_limit(60);

        // ① 参数校验 —— 手工判空,与 SummonController / SummonAiController 的写法一致
        $file = $request->file('audio');

        if (! $file || ! $file->isValid()) {
            return $this->reject(ApiErrorCode::NO_AUDIO, '未收到音频');
        }

        if ($file->getSize() > (int) config('asr.max_bytes')) {
            return $this->reject(ApiErrorCode::TOO_LARGE, '音频过大');
        }

        // 客户端声明的格式**仅作兜底**,不再据此拒请求 —— 真实容器由 AudioNormalizer
        // 按魔数嗅探(实测:前端写死 mp3,开发者工具里实录的却是 WebM)。
        // 前端可能传 "audio/webm" 这类带前缀的值,取最后一段。
        $declaredFormat = strtolower((string) $request->input('format', ''));
        $declaredFormat = (string) preg_replace('#^.*/#', '', $declaredFormat);

        // ② 开发期留档 —— ⚠ 刻意放在「未配置」判断**之前**:
        //    这样密钥还没申请下来时,也能先录一段落到磁盘,用 ffprobe 验证前端产出的
        //    格式到底对不对(整个方案最大的不确定性),不必等密钥。
        //    仅 ASR_KEEP_AUDIO=true(开发期)才走到这里;生产为 false 不落盘。
        //    留档失败不影响识别,所以单独 try。
        if (config('asr.keep_audio')) {
            try {
                $file->store('asr');   // → storage/app/public/asr/
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // ③ 未配置 → 明确告知,不发任何外部请求,也不报错
        //    (密钥还没申请下来的阶段,前端就靠这个 code 提示"语音功能正在配置中")
        if (! config('asr.enabled')
            || (string) config('asr.secret_id') === ''
            || (string) config('asr.secret_key') === '') {
            return $this->fail(ApiErrorCode::ASR_NOT_CONFIGURED, '语音识别尚未配置');
        }

        // ④ 归一化 —— 嗅探真实容器;腾讯不收的(webm / mp4)用 ffmpeg 转 16k 单声道 WAV。
        //    直接读内存,不在磁盘留用户录音(留档是 ② 那个可关的开发期开关,与本步无关)。
        try {
            $normalizer = new AudioNormalizer(
                (string) config('asr.ffmpeg_path'),
                (int) config('asr.transcode_timeout'),
            );

            [$bytes, $format] = $normalizer->normalize(
                (string) file_get_contents($file->getRealPath()),
                $declaredFormat,
            );
        } catch (\Throwable $e) {
            // 容器认不出来,或 ffmpeg 缺失 / 超时 / 转码失败。细节进日志,前端只当"请求非法"。
            report($e);

            return $this->reject(ApiErrorCode::UNSUPPORTED_FORMAT, '音频格式无法识别或不支持');
        }

        // ⚠ 体积上限必须在转码**之后**再判一次:转码会放大体积
        //   (模拟器的 webm 才 45KB,转成 60s 的 16k 单声道 WAV 约 1.9MB),
        //   而腾讯的上限是按**送出去的字节**算的,不是按上传的字节。
        if (strlen($bytes) > (int) config('asr.max_bytes')) {
            return $this->reject(ApiErrorCode::TOO_LARGE, '音频过大');
        }

        // ⑤ 识别
        try {
            $client = new TencentAsrClient(
                (string) config('asr.secret_id'),
                (string) config('asr.secret_key'),
                (string) config('asr.endpoint'),
                (string) config('asr.region'),
                (string) config('asr.engine'),
                (int) config('asr.timeout'),
            );

            $text = $client->sentenceRecognition($bytes, $format);
        } catch (\Throwable $e) {
            // ⚠ 细节只进日志,不给前端看 —— 避免泄漏签名/密钥线索,也免得前端
            //    把"签名失败"和"没听清"呈现成两种不同提示(用户看不懂也没法处理)
            report($e);

            return $this->fail(ApiErrorCode::ASR_FAILED, '识别失败,请重试');
        }

        // text 为空串是**正常结果**(听清了但没内容),前端据此提示"没听清"即可
        return response()->json(['success' => true, 'text' => $text]);
    }

}
