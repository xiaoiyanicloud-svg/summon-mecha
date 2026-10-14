<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 语音识别(ASR)配置
    |--------------------------------------------------------------------------
    |
    | 用于 POST /api/asr:前端录音上传 → 本服务转交腾讯云「一句话识别」→ 返回文字。
    |
    | 谁在用:
    |   · H5        —— 唯一路径
    |   · 微信小程序 —— 兜底路径(优先走微信「同声传译」插件,不经本服务)
    |
    | ⚠ 密钥未配置时接口**不报错**,而是返回 code=ASR_NOT_CONFIGURED,
    | 前端据此提示「语音功能正在配置中」,其余功能不受影响。
    |
    | ⚠ 控制器里必须用 config('asr.*') 读取,不要直接 env()。
    | 生产跑过 php artisan config:cache 之后,config 文件之外调 env() 一律返回 null。
    |
    */

    // 总开关:false 时直接返回 ASR_NOT_CONFIGURED,不发任何外部请求
    'enabled' => env('ASR_ENABLED', true),

    // 腾讯云访问密钥(https://console.cloud.tencent.com/cam/capi)
    // 留空即视为「未配置」,接口降级返回 ASR_NOT_CONFIGURED
    'secret_id'  => env('TENCENT_SECRET_ID', ''),

    'secret_key' => env('TENCENT_SECRET_KEY', ''),

    // 地域:选离用户近的,如 ap-guangzhou / ap-beijing / ap-shanghai
    'region' => env('TENCENT_ASR_REGION', 'ap-guangzhou'),

    // 接口地址,一般不用改
    'endpoint' => env('TENCENT_ASR_ENDPOINT', 'asr.tencentcloudapi.com'),

    // 引擎模型:16k_zh = 中文通用(16k 采样率)。
    // ⚠ 采样率必须与音频匹配 —— 前端已统一重采样为 16k 单声道,所以这里固定 16k_zh。
    'engine' => env('ASR_ENGINE', '16k_zh'),

    // 上传体积上限(字节)。一句话识别只支持 ≤ 1 分钟音频,3MB 足够
    // ⚠ 这一条会在**转码前后各判一次** —— 转码会放大体积,而腾讯的上限是按送出去的字节算的
    'max_bytes' => env('ASR_MAX_BYTES', 3 * 1024 * 1024),

    // ffmpeg 可执行文件。只有腾讯不收的容器(webm / mp4)才用得到,见 AudioNormalizer
    // 留空或找不到时,这类音频会以 422 UNSUPPORTED_FORMAT 拒绝(其余格式不受影响)
    'ffmpeg_path' => env('ASR_FFMPEG_PATH', 'ffmpeg'),

    // ffmpeg 转码超时(秒)。60s 音频转码通常 < 1s,给 15s 已经很宽松
    'transcode_timeout' => env('ASR_TRANSCODE_TIMEOUT', 15),

    // 是否把上传的音频留档到 storage/app/public/asr/
    // ⚠ 开发期设 true,可用 ffprobe 验证「前端录出来的格式到底对不对」(最大不确定性);
    //   上线前改回 false —— 不留用户录音。
    'keep_audio' => env('ASR_KEEP_AUDIO', false),

    // 调用腾讯云的网络超时(秒)
    'timeout' => env('ASR_TIMEOUT', 20),
];
