<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 语音合成(TTS)配置
    |--------------------------------------------------------------------------
    |
    | 用于 POST /api/tts:AI 回复正文 → 本服务转交腾讯云「语音合成(基础版)」→ base64 音频。
    |
    | 谁在用:
    |   · 小程序 / H5 的 AI 对话页 —— 回复自动朗读 + 每条消息可手动重播
    |
    | ⚠ 客户端**不直连**腾讯云,一律经本服务代理 —— AK/SK 绝不能进小程序包。
    |
    | ⚠ 密钥复用 ASR 那一对 TENCENT_SECRET_ID / TENCENT_SECRET_KEY(同一个腾讯云账号)。
    |   ⚠ 但「语音合成」与「一句话识别」是**两个独立服务**,要在控制台**分别开通**,
    |     而且**资源包也是各自独立的** —— 识别有额度不代表合成有额度。
    |     资源包用尽时腾讯返回 UnsupportedOperation.PkgExhausted,接口降级为 TTS_FAILED。
    |
    | ⚠ 密钥未配置时接口**不报错**,而是返回 code=TTS_NOT_CONFIGURED,
    |   前端静默跳过朗读,聊天主流程不受任何影响。
    |
    | ⚠ 控制器里必须用 config('tts.*') 读取,不要直接 env()。
    | 生产跑过 php artisan config:cache 之后,config 文件之外调 env() 一律返回 null。
    |
    | 契约核实记录(2026-09-29,用真实密钥打线上接口实测):
    |   · 字段名 Text / VoiceType / Codec / SampleRate / SessionId / ProjectId 均有效
    |   · Codec 合法值:mp3 / pcm / wav(ogg 被拒)
    |   · SampleRate 合法值:8000 / 16000 / 24000(44100 被拒)
    |   · VoiceType **可以省略**(服务端有默认音色)
    |   · ⚠ 但 VoiceType 只做**范围校验**,0 / 1001 / 601008 之类都能通过 ——
    |     所以**没法靠接口试探出真实音色**,必须从控制台音色列表取值。
    |
    */

    // 总开关:false 时直接返回 TTS_NOT_CONFIGURED,不发任何外部请求
    'enabled' => env('TTS_ENABLED', true),

    // 用哪家云:tencent | volcano
    //
    //   tencent —— 腾讯云「基础语音合成」。传统参数合成,拼接感、人机感明显。
    //   volcano —— 火山引擎「语音合成大模型」(字节自研、豆包同源)。韵律与情感好得多,
    //              **朗读要"像人"就用这个**。
    //
    // ⚠ 换 provider **只改这一行**,控制器与前端都不用动 —— 前端只认 /api/tts 的
    //   { success, audios } 契约,与用哪家云无关。
    'provider' => env('TTS_PROVIDER', 'tencent'),

    // 是否裁掉合成音频**首尾**的静音。
    //
    // ⚠ 为什么需要:引擎给每段音频都垫了约 0.2 秒留白(与句末标点无关,是它自己的 padding),
    //   而朗读是**按句分段**播的 —— 相邻两段的"尾部 0.19s + 头部 0.21s"会叠成
    //   约 **0.4 秒死寂**,听起来就是"念一句、停一下"。裁掉后句间基本无缝。
    //   句中停顿(逗号/顿号)会**保留**,那不是 padding 而是自然语气。
    //
    // 关掉不影响正确性,只是句间停顿会明显变长。裁剪失败(如 ffmpeg 缺失)会自动跳过,
    // 绝不会因此让朗读没声音 —— 见 AudioSilenceTrimmer。
    'trim_silence' => env('TTS_TRIM_SILENCE', true),

    // ffmpeg 可执行文件(裁剪静音用)。⚠ 裁剪必须重编码,所以这个二进制要有;
    // 找不到就自动跳过裁剪,朗读照常
    'ffmpeg_path' => env('TTS_FFMPEG_PATH', 'ffmpeg'),

    // 腾讯云访问密钥(与 ASR 共用同一对,不新增变量)
    'secret_id'  => env('TENCENT_SECRET_ID', ''),

    'secret_key' => env('TENCENT_SECRET_KEY', ''),

    // 地域:选离用户近的
    'region' => env('TENCENT_TTS_REGION', 'ap-guangzhou'),

    // 接口地址,一般不用改
    'endpoint' => env('TENCENT_TTS_ENDPOINT', 'tts.tencentcloudapi.com'),

    // 音色 ID。
    // ⚠⚠ 留空 = **不传 VoiceType 字段**,由服务端用默认音色(已验证可通过校验)。
    //     要指定音色,**必须到控制台「语音合成 → 音色列表」复制真实 ID** 填进 .env 的 TTS_VOICE_TYPE。
    //     不要照抄网上的示例值 —— 上面已实测:接口只做范围校验,填一个不存在的 ID 也能通过校验,
    //     真正的失败要等到合成时才暴露,而且可能只是"音色不对"这种不报错的错。
    'voice_type' => env('TTS_VOICE_TYPE', ''),

    // 音频编码。实测合法值:mp3 / pcm / wav。mp3 体积小且各端播放器都支持,故为默认
    'codec' => env('TTS_CODEC', 'mp3'),

    // 采样率。实测合法值:8000 / 16000 / 24000(44100 会被拒)
    'sample_rate' => env('TTS_SAMPLE_RATE', 16000),

    // 语速,范围 -2 ~ 2(0 = 正常)
    'speed' => env('TTS_SPEED', 0),

    // 音量,范围 -10 ~ 10(0 = 正常)
    'volume' => env('TTS_VOLUME', 0),

    /*
    |----------------------------------------------------------------------
    | 火山引擎「语音合成大模型」(豆包同源)
    |----------------------------------------------------------------------
    |
    | 请求形态已按**控制台的真实示例**逐字对齐(2026-09-29),端点/鉴权头/字段名都已确定:
    |
    |   POST https://openspeech.bytedance.com/api/v3/tts/unidirectional
    |   x-api-key: {apiKey}                 ← ⚠ 鉴权是 x-api-key,**不是** Authorization: Bearer
    |   X-Api-Resource-Id: volc.service_type.10029
    |
    | 仍未核实的只有**响应**形态(端点叫 unidirectional,大概率是流式分片):
    | 客户端已兼容多种形态,且定位不到音频时会把原始响应带进异常,一发探针就能看清。
    |
    */

    'volcano' => [
        // 控制台的 API Key
        'api_key' => env('TTS_VOLCANO_API_KEY', ''),

        // 接口地址(大模型语音合成的 v3 单向流式端点)
        'endpoint' => env('TTS_VOLCANO_ENDPOINT', 'https://openspeech.bytedance.com/api/v3/tts/unidirectional'),

        // 资源 ID —— 大模型语音合成必需(取自控制台示例)
        'resource_id' => env('TTS_VOLCANO_RESOURCE_ID', 'volc.service_type.10029'),

        // 音色 ID。**必须从控制台音色列表取**。
        // 当前选型:zh_female_yingtaowanzi_mars_bigtts(樱桃丸子,女声)。
        //
        // ⚠ 更正一处我早先的过度概括:带 `_emo_` 的确实是情感变体,但**不代表只有那些才自然** ——
        //   `*_mars_bigtts` 后缀的**大模型音色本身就是端到端合成的、都相当自然**,
        //   挑音色请听实际效果,不要只看 ID 里有没有 emo。
        'speaker' => env('TTS_VOLCANO_SPEAKER', 'zh_female_yingtaowanzi_mars_bigtts'),

        // 音频格式与采样率。24k 音质明显好于 16k,而 mp3 体积小、各端播放器都支持
        'format' => env('TTS_VOLCANO_FORMAT', 'mp3'),
        'sample_rate' => env('TTS_VOLCANO_SAMPLE_RATE', 24000),

        // 语速。0 = 原速,**每 +10 约快 9%**。同一段文本的实测值:
        //   0 → 9.600s(原速) | 10 → 8.712s(+9%) | 15 → 8.304s(+14%)
        //   20 → 7.992s(+17%) | 30 → 7.368s(+23%) | 50 → 6.384s(+34%)
        //   负数变慢(-20 → 11.928s),双向可控。
        // 默认 15(快约 14%)——"稍微快一点"的量级;快过头比慢一点更影响听感。
        // ⚠ 必须在 audio_params 里、字段名 speech_rate:旧版的 speed_ratio、
        //   或把它放到 req_params 层级,都会被**静默忽略**(不报错、音频毫无变化)。
        'speech_rate' => env('TTS_VOLCANO_SPEECH_RATE', 15),

        // 附加参数。⚠ 火山要求它是**一个 JSON 字符串**(不是嵌套对象),
        //    所以从 .env 读进来是原样的串,**不要 json_decode**。
        //    当前值取自控制台示例:关 markdown 过滤、开语言检测、开缓存等。
        // ⚠ 实测它会**显著影响产出的音频**:同一段文本,带它 115245 字节 / 不带 76653 字节
        //   (差别主要来自 disable_default_bit_rate)。而合成本身是**确定性**的 ——
        //   同一请求两次调用字节完全相同,所以排查时可以放心按字节比对。
        'additions' => env('TTS_VOLCANO_ADDITIONS', '{"disable_markdown_filter":true,"enable_language_detector":true,"enable_latex_tn":true,"disable_default_bit_rate":true,"max_length_to_filter_parenthesis":0,"cache_config":{"text_type":1,"use_cache":true}}'),

        // 透传到 req_params 的额外字段,**填 JSON**。给"还没核实的参数"留的口子,
        // 且能覆盖任何一项(含 speaker),出问题不用改代码。
        // 例:TTS_VOLCANO_EXTRA_REQ_PARAMS={"speed_ratio":1.1}
        'extra_req_params' => json_decode((string) env('TTS_VOLCANO_EXTRA_REQ_PARAMS', '[]'), true) ?: [],

        // 响应里承载音频的字段名。⚠ 待确认,默认 data;探针显示是别的名字就改这里
        'audio_field' => env('TTS_VOLCANO_AUDIO_FIELD', 'data'),
    ],

    // 单次合成的字数上限。
    // ⚠ 前端已把句子切到 120 字以内,所以这里只在「手动整段重播」时才会触发后端再切分。
    //   超过这个长度不会被拒,而是**拆成多段分别合成**,返回数组由前端顺序播放。
    'max_chars' => env('TTS_MAX_CHARS', 150),

    // 单请求总长上限(防御性,防止有人拿它当长文合成接口)
    'max_total_chars' => env('TTS_MAX_TOTAL_CHARS', 2000),

    // 调用腾讯云的网络超时(秒)。合成比识别慢一些,给宽一点
    'timeout' => env('TTS_TIMEOUT', 20),
];
