/**
 * 语音输入统一门面 —— 把三端差异收在这一个文件里,页面只面对「开始 / 中间结果 / 最终文字 / 出错」四件事。
 *
 * 三条链路:
 *   1. 微信小程序 + 「同声传译」插件 → 边说边出字,免费、实时、不经后端(体验最好)
 *   2. 微信小程序(无插件)/ App   → uni.getRecorderManager 录 mp3 → POST /api/asr
 *   3. H5                          → MediaRecorder → 解码重采样成 16k 单声道 WAV → POST /api/asr
 *
 * ⚠ 平台的差异必须**全部**写在 `// #ifdef` 块内。uni-app 打包时会把不属于当前平台的
 *   条件块整段删除,所以小程序产物里根本不会出现 `navigator` / `MediaRecorder` 这些引用;
 *   但只要在块外碰到一次平台专有 API,构建就会报错。
 *
 * ⚠ 小程序端的 `requirePlugin` 尤其危险:H5 构建里它是 undefined,直接调用会抛错,
 *   所以必须包在 `#ifdef MP-WEIXIN` + try/catch 里(见 getPluginManager)。
 *
 * 错误码与文案分开:页面只管把 ERR_TEXT[code] 显示出来,不用自己拼措辞。
 */

import { BASE_URL, TOKEN_KEY } from './config'

/**
 * 识别完成后是否**直接发送**。
 *
 * 默认 false = 填回输入框,让用户确认后自己点发送。
 * 理由:语音识别必然有错字,而一次召唤要等 8~20 秒,直接发送的代价太高。
 * 想改成"说完即发",把这里改成 true 即可(浮层文案在页面里同步改)。
 */
export const AUTO_SEND_AFTER_ASR = false

/** 短于此长度视为误触,不请求后端(腾讯云对过短音频也识别不出东西) */
const MIN_DURATION_MS = 800

/** 上限:一句话识别只支持 1 分钟以内的音频 */
const MAX_DURATION_MS = 60000

const ASR_URL = BASE_URL + '/api/asr'

/** 腾讯云只认 16k 采样率(与后端 asr.engine=16k_zh 对应) */
const TARGET_SAMPLE_RATE = 16000

export const ERR = {
  NO_SUPPORT: 'NO_SUPPORT',
  INSECURE_CONTEXT: 'INSECURE_CONTEXT',
  PERMISSION_DENIED: 'PERMISSION_DENIED',
  NO_DEVICE: 'NO_DEVICE',
  DEVICE_BUSY: 'DEVICE_BUSY',
  TOO_SHORT: 'TOO_SHORT',
  SILENT: 'SILENT',
  UPLOAD_FAILED: 'UPLOAD_FAILED',
  NOT_CONFIGURED: 'NOT_CONFIGURED',
  UNKNOWN: 'UNKNOWN',
}

export const ERR_TEXT = {
  NO_SUPPORT: '当前环境不支持录音，请用文字输入',
  INSECURE_CONTEXT: '语音需在 https 或 localhost 下使用',
  // ⚠ 这条要写得平台中立：H5 没有"设置"页可去，小程序才有。
  // 具体怎么开，交给页面的 guideRecordPermission() 分端说明。
  PERMISSION_DENIED: '需要麦克风权限',
  NO_DEVICE: '未找到麦克风设备',
  DEVICE_BUSY: '麦克风被其他程序占用',
  TOO_SHORT: '说话时间太短',
  SILENT: '没听清，请再说一次',
  UPLOAD_FAILED: '网络异常，请重试',
  NOT_CONFIGURED: '语音功能正在配置中，请先用文字输入',
  UNKNOWN: '语音出错了，请重试',
}

// ==================== 对外接口 ====================

/** 当前平台是否具备录音能力(不具备时页面直接提示用文字输入) */
export function isVoiceSupported() {
  // #ifdef H5
  return typeof navigator !== 'undefined'
    && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)
  // #endif

  // #ifndef H5
  return true
  // #endif
}

/**
 * 是否「边说边出字」(只有小程序 + 同声传译插件是这样)。
 * 页面据此决定浮层要不要显示实时文字,以及给用户什么提示。
 */
export function isRealtime() {
  // #ifdef MP-WEIXIN
  return !!getPluginManager()
  // #endif

  // #ifndef MP-WEIXIN
  return false
  // #endif
}

/**
 * 创建一个语音会话。
 *
 * handlers: { onStart, onPartial(text), onResult(text), onError(code, message), onLevel(0~1), onEnd }
 *
 * 返回 { start(), stop(), cancel() }:
 *   start()  —— 开始录音(权限不足/环境不支持时走 onError)
 *   stop()   —— 正常结束,触发识别
 *   cancel() —— 放弃本次录音,不出结果(上滑取消、来电中断等)
 *
 * ⚠ onEnd 的契约(页面靠它关浮层):
 *   · 一旦 onStart 被触发过,onEnd **必定恰好调用一次**(无论成功、失败还是取消)
 *   · onStart 之前的前置失败(不支持 / 非 https / 权限被拒)只给 onError,**不给 onEnd**
 *     —— 此时浮层压根没打开,页面无需关闭它
 */
export function createVoiceSession(handlers = {}) {
  const H = normalize(handlers)

  // #ifdef MP-WEIXIN
  const mgr = getPluginManager()
  if (mgr) return createPluginSession(mgr, H)
  return createNativeSession(H)
  // #endif

  // #ifdef APP-PLUS
  return createNativeSession(H)
  // #endif

  // #ifdef H5
  return createH5Session(H)
  // #endif
}

// ==================== 内部共用 ====================

function normalize(h) {
  return {
    onStart: h.onStart || noop,
    onPartial: h.onPartial || noop,
    onResult: h.onResult || noop,
    onError: h.onError || noop,
    onLevel: h.onLevel || noop,
    onEnd: h.onEnd || noop,
  }
}

function noop() {}

/** 鉴权头。⚠ 上传时不要自己写 Content-Type,要让上传实现生成 multipart boundary */
function authHeader() {
  const token = uni.getStorageSync(TOKEN_KEY) || ''
  return token ? { Authorization: 'Bearer ' + token } : {}
}

/**
 * 统一处理 /api/asr 的响应。
 *
 * 后端一律返回 HTTP 200,用 success 字段区分成败(见 AsrController 类注释),
 * 所以这里只判 body.success。
 */
function handleAsrResponse(raw, H) {
  let d = raw
  if (typeof d === 'string') {
    try { d = JSON.parse(d) } catch (e) { d = null }
  }

  if (!d || d.success !== true) {
    const code = (d && d.code) || ''
    if (code === 'ASR_NOT_CONFIGURED') {
      // 密钥还没配好 —— 这不是用户的问题,给一句"配置中"就够,不要显示技术细节
      H.onError(ERR.NOT_CONFIGURED, ERR_TEXT[ERR.NOT_CONFIGURED])
    } else {
      H.onError(ERR.UPLOAD_FAILED, ERR_TEXT[ERR.UPLOAD_FAILED])
    }
    return
  }

  const text = ((d.text || '') + '').trim()
  if (!text) {
    // 后端 success 但文字为空 = 听清了却没内容(纯噪音/静音),属正常结果
    H.onError(ERR.SILENT, ERR_TEXT[ERR.SILENT])
    return
  }

  H.onResult(text)
}

// ==================== 小程序:同声传译插件(实时) ====================

// #ifdef MP-WEIXIN
/**
 * 取插件实例。任何异常都返回 null,让调用方降级到录音上传 —— 因为插件不可用的原因
 * 太多了(manifest 没声明 / mp 后台没添加 / 版本号不符 / 基础库太老),不该逐个判。
 */
function getPluginManager() {
  try {
    const plugin = requirePlugin('WechatSI')
    if (!plugin || typeof plugin.getRecordRecognitionManager !== 'function') return null
    return plugin.getRecordRecognitionManager() || null
  } catch (e) {
    return null
  }
}

function createPluginSession(mgr, H) {
  let cancelled = false
  let finished = false
  let timer = null

  const finish = () => {
    if (finished) return
    finished = true
    if (timer) { clearTimeout(timer); timer = null }
    H.onEnd()
  }

  // ⚠ 这四个是**属性赋值**不是方法调用(onStart = fn),写错不会报错但永远不触发
  mgr.onStart = () => { H.onStart() }

  // ⚠ 插件给的是**整句重写**,不是增量!必须整体替换成 res.result,
  //   写成 this.partial += res.result 会让浮层里的字越堆越长。
  mgr.onRecognize = (res) => {
    if (cancelled || finished) return
    H.onPartial(((res && res.result) || '') + '')
  }

  mgr.onStop = (res) => {
    if (cancelled) { finish(); return }
    const text = (((res && res.result) || '') + '').trim()
    finish()
    if (!text) H.onError(ERR.SILENT, ERR_TEXT[ERR.SILENT])
    else H.onResult(text)
  }

  mgr.onError = (res) => {
    if (cancelled) { finish(); return }
    const msg = ((res && res.msg) || '') + ''
    const code = /auth|permission|授权|deny/i.test(msg) ? ERR.PERMISSION_DENIED : ERR.UNKNOWN
    finish()
    H.onError(code, ERR_TEXT[code])
  }

  return {
    start() {
      cancelled = false
      finished = false
      H.onStart()
      // 插件自己到点会停,这里只加一个兜底,防止回调没来时浮层悬挂
      mgr.start({ lang: 'zh_CN', duration: MAX_DURATION_MS })
      timer = setTimeout(() => { try { mgr.stop() } catch (e) {} }, MAX_DURATION_MS + 500)
    },
    stop() {
      try { mgr.stop() } catch (e) { /* 可能已自行结束 */ }
    },
    cancel() {
      cancelled = true
      try { mgr.stop() } catch (e) { /* 同上 */ }
      finish()
    },
  }
}
// #endif

// ==================== 小程序 / App:录音后上传(兜底) ====================

// #ifdef MP-WEIXIN || APP-PLUS
function createNativeSession(H) {
  // ⚠ 每个会话重新注册回调。这些管理器是单例,onStop/onError 会覆盖上一次的处理器;
  //   若复用同一个 handler,闭包会指向旧会话的 H,第二次录音就没人接结果了。
  const rec = uni.getRecorderManager()

  let cancelled = false
  let finished = false
  let startedAt = 0
  let timer = null

  const finish = () => {
    if (finished) return
    finished = true
    if (timer) { clearTimeout(timer); timer = null }
    H.onEnd()
  }

  rec.onStop((res) => {
    if (cancelled) { finish(); return }

    const duration = (res && res.duration) || (Date.now() - startedAt)
    if (duration < MIN_DURATION_MS) {
      finish()
      H.onError(ERR.TOO_SHORT, ERR_TEXT[ERR.TOO_SHORT])
      return
    }

    const filePath = (res && res.tempFilePath) || ''
    if (!filePath) {
      finish()
      H.onError(ERR.UNKNOWN, ERR_TEXT[ERR.UNKNOWN])
      return
    }

    // ⚠ 小程序端直接录 mp3 —— 腾讯云认 mp3,所以这一路**不需要前端转码**,
    //   这正是它比 H5 简单的地方。
    uni.uploadFile({
      url: ASR_URL,
      filePath,
      name: 'audio',
      formData: { format: 'mp3' },
      header: authHeader(),
      timeout: 30000,
      success: (r) => { finish(); handleAsrResponse(r && r.data, H) },
      fail: () => { finish(); H.onError(ERR.UPLOAD_FAILED, ERR_TEXT[ERR.UPLOAD_FAILED]) },
    })
  })

  rec.onError((e) => {
    if (cancelled) { finish(); return }
    const msg = ((e && e.errMsg) || '') + ''
    const code = /auth|permission|授权|deny/i.test(msg) ? ERR.PERMISSION_DENIED : ERR.UNKNOWN
    finish()
    H.onError(code, ERR_TEXT[code])
  })

  return {
    start() {
      cancelled = false
      finished = false
      startedAt = Date.now()
      H.onStart()
      rec.start({
        format: 'mp3',
        sampleRate: TARGET_SAMPLE_RATE,
        numberOfChannels: 1,
        duration: MAX_DURATION_MS,
      })
      timer = setTimeout(() => { try { rec.stop() } catch (e) {} }, MAX_DURATION_MS + 500)
    },
    stop() {
      try { rec.stop() } catch (e) { /* 可能已自行结束 */ }
    },
    cancel() {
      cancelled = true
      try { rec.stop() } catch (e) { /* 同上 */ }
      finish()
    },
  }
}
// #endif

// ==================== H5:录音 → 解码 → 16k WAV → 上传 ====================

// #ifdef H5
function createH5Session(H) {
  let stream = null
  let recorder = null
  let chunks = []
  let startedAt = 0
  let cancelled = false
  let finished = false
  let timer = null
  // 用户在 getUserMedia 返回之前就松手了(见 stop() 里的说明)
  let stopRequested = false

  const finish = () => {
    if (finished) return
    finished = true
    if (timer) { clearTimeout(timer); timer = null }
    releaseStream()
    stopLevel()
    H.onEnd()
  }

  // ⚠ 一定要停掉音轨,否则浏览器标签页会一直挂着"正在录音"的红点
  function releaseStream() {
    if (stream) {
      stream.getTracks().forEach((t) => t.stop())
      stream = null
    }
  }

  let audioCtx = null
  let analyser = null
  let levelTimer = null

  function startLevel(s) {
    // 音量条只是装饰,失败绝不能影响录音 —— 所以整段包 try
    try {
      const AC = window.AudioContext || window.webkitAudioContext
      if (!AC) return

      audioCtx = new AC()
      analyser = audioCtx.createAnalyser()
      analyser.fftSize = 256
      audioCtx.createMediaStreamSource(s).connect(analyser)

      const buf = new Uint8Array(analyser.frequencyBinCount)
      levelTimer = setInterval(() => {
        if (!analyser) return
        analyser.getByteFrequencyData(buf)
        let sum = 0
        for (let i = 0; i < buf.length; i++) sum += buf[i]
        H.onLevel(Math.min(1, sum / buf.length / 128))
      }, 100)
    } catch (e) { /* 无音量指示也能正常录音 */ }
  }

  function stopLevel() {
    if (levelTimer) { clearInterval(levelTimer); levelTimer = null }
    if (audioCtx) { try { audioCtx.close() } catch (e) {} audioCtx = null }
    analyser = null
  }

  function onRecorderStop() {
    const duration = Date.now() - startedAt
    const type = (recorder && recorder.mimeType) || 'audio/webm'
    releaseStream()
    stopLevel()

    if (cancelled) { finish(); return }

    if (duration < MIN_DURATION_MS) {
      finish()
      H.onError(ERR.TOO_SHORT, ERR_TEXT[ERR.TOO_SHORT])
      return
    }

    toWav16k(new Blob(chunks, { type }))
      .then((wav) => {
        if (cancelled) { finish(); return }
        return uploadWav(wav)
      })
      .then((body) => { finish(); handleAsrResponse(body, H) })
      .catch((err) => {
        finish()
        const code = (err && err.__voiceCode) || ERR.UPLOAD_FAILED
        H.onError(code, ERR_TEXT[code])
      })
  }

  return {
    start() {
      cancelled = false
      finished = false
      stopRequested = false
      chunks = []

      if (!isVoiceSupported()) {
        finished = true
        H.onError(ERR.NO_SUPPORT, ERR_TEXT[ERR.NO_SUPPORT])
        return
      }

      // ⚠ getUserMedia 只在 https 或 localhost 下可用。提前拦掉,否则拿到的是一个
      //   对用户毫无意义的 DOMException。
      if (!isSecureContext()) {
        finished = true
        H.onError(ERR.INSECURE_CONTEXT, ERR_TEXT[ERR.INSECURE_CONTEXT])
        return
      }

      navigator.mediaDevices
        .getUserMedia({
          audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true },
        })
        .then((s) => {
          if (cancelled) { s.getTracks().forEach((t) => t.stop()); return }

          // ⚠ 竞态收尾：用户赶在权限返回之前就松了手。此时**不能**照常开始录音 ——
          //   那会变成"松手了还在录、浮层一直挂着、麦克风一直开"。
          //   按"按太短"处理：释放麦克风并报 TOO_SHORT。
          //   注意这里语义上属"前置失败"(onStart 尚未触发)，所以只给 onError，不给 onEnd。
          if (stopRequested) {
            s.getTracks().forEach((t) => t.stop())
            finished = true
            H.onError(ERR.TOO_SHORT, ERR_TEXT[ERR.TOO_SHORT])
            return
          }

          stream = s
          const mime = pickMime()
          try {
            recorder = mime ? new MediaRecorder(s, { mimeType: mime }) : new MediaRecorder(s)
          } catch (e) {
            recorder = new MediaRecorder(s)
          }

          recorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data) }
          recorder.onstop = onRecorderStop
          recorder.onerror = () => {
            finish()
            H.onError(ERR.UNKNOWN, ERR_TEXT[ERR.UNKNOWN])
          }

          startedAt = Date.now()
          recorder.start()
          startLevel(s)
          H.onStart()

          timer = setTimeout(() => {
            try { recorder.stop() } catch (e) {}
          }, MAX_DURATION_MS)
        })
        .catch((err) => {
          finished = true
          const code = mapGetUserMediaError(err)
          H.onError(code, ERR_TEXT[code])
        })
    },

    stop() {
      // ⚠ 权限是异步的：用户可能赶在 getUserMedia 返回之前就松手。此时录音器还不存在，
      //   若直接 return，start() 的 .then 之后会照常开始录音 —— 松手了反而在录。
      //   用标志位记下来，交给 .then 按"太短"收尾。
      if (!recorder) { stopRequested = true; return }
      try { recorder.stop() } catch (e) { /* 可能已自行结束 */ }
    },

    cancel() {
      cancelled = true
      try { recorder && recorder.stop() } catch (e) { /* 同上 */ }
      finish()
    },
  }
}

function isSecureContext() {
  if (typeof location === 'undefined') return false
  const host = location.hostname
  return location.protocol === 'https:'
    || host === 'localhost'
    || host === '127.0.0.1'
    || host === '[::1]'
}

/**
 * 挑一个浏览器支持的录音容器。
 *
 * ⚠ 这里选的容器腾讯云**并不接受**(webm / mp4 都不在其支持列表里),
 *   所以它只决定"录出来先用什么装",后面 toWav16k 会统一解成 WAV 再上传。
 *   选一个可预测的容器,是为了让 decodeAudioData 的输入稳定些。
 */
function pickMime() {
  if (typeof MediaRecorder === 'undefined' || typeof MediaRecorder.isTypeSupported !== 'function') {
    return ''
  }
  const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
  for (let i = 0; i < candidates.length; i++) {
    if (MediaRecorder.isTypeSupported(candidates[i])) return candidates[i]
  }
  return ''
}

function mapGetUserMediaError(err) {
  const name = (err && err.name) || ''
  if (name === 'NotAllowedError' || name === 'SecurityError') return ERR.PERMISSION_DENIED
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError') return ERR.NO_DEVICE
  if (name === 'NotReadableError' || name === 'TrackStartError') return ERR.DEVICE_BUSY
  return ERR.UNKNOWN
}

/**
 * 任意容器 → 16k 单声道 WAV。
 *
 * 为什么要走这一趟(而不是把浏览器录的原始文件直接上传):
 *   · Chrome 的 MediaRecorder 产出 webm/opus,iOS Safari 产出 mp4/aac —— 腾讯云**两个都不收**
 *   · 腾讯 16k_zh 引擎要求音频就是 16k 采样率,重采样这步无论如何省不掉
 *   · decodeAudioData 对 webm 和 mp4 都能解,跨浏览器差异在这里一次性抹平
 *   · 纯 Web API,零依赖(本项目是 HBuilderX 工程,装不了 npm 包)
 */
async function toWav16k(blob) {
  const arrayBuffer = await blob.arrayBuffer()

  const AC = window.AudioContext || window.webkitAudioContext
  const decodeCtx = new AC()
  let decoded
  try {
    decoded = await decodeCtx.decodeAudioData(arrayBuffer)
  } finally {
    try { decodeCtx.close() } catch (e) { /* 忽略 */ }
  }

  // ⚠ OfflineAudioContext(声道数, 长度, 采样率) 会**一步完成降混 + 重采样**,
  //   比手写线性插值质量好得多,代码也短得多。
  const length = Math.max(1, Math.ceil(decoded.duration * TARGET_SAMPLE_RATE))
  const offline = new OfflineAudioContext(1, length, TARGET_SAMPLE_RATE)
  const source = offline.createBufferSource()
  source.buffer = decoded
  source.connect(offline.destination)
  source.start()

  const rendered = await offline.startRendering()
  return encodeWav(rendered.getChannelData(0), TARGET_SAMPLE_RATE)
}

/** 把 [-1,1] 的浮点采样编成 16bit PCM 的 WAV(44 字节头 + 小端样本) */
function encodeWav(samples, sampleRate) {
  const buffer = new ArrayBuffer(44 + samples.length * 2)
  const view = new DataView(buffer)

  const writeAscii = (offset, str) => {
    for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i))
  }

  writeAscii(0, 'RIFF')
  view.setUint32(4, 36 + samples.length * 2, true)
  writeAscii(8, 'WAVE')
  writeAscii(12, 'fmt ')
  view.setUint32(16, 16, true)                              // fmt 块长度
  view.setUint16(20, 1, true)                               // 1 = PCM
  view.setUint16(22, 1, true)                               // 单声道
  view.setUint32(24, sampleRate, true)
  view.setUint32(28, sampleRate * 2, true)                  // 字节率 = 采样率 × 声道 × 位深/8
  view.setUint16(32, 2, true)                               // 块对齐
  view.setUint16(34, 16, true)                              // 位深
  writeAscii(36, 'data')
  view.setUint32(40, samples.length * 2, true)

  let offset = 44
  for (let i = 0; i < samples.length; i++) {
    const s = Math.max(-1, Math.min(1, samples[i]))
    view.setInt16(offset, s < 0 ? s * 0x8000 : s * 0x7FFF, true)
    offset += 2
  }

  return new Blob([view], { type: 'audio/wav' })
}

/**
 * H5 上传。用 fetch + FormData,不用 uni.uploadFile —— 后者的 filePath 在 H5 端
 * 语义是 blob: URL 或 File 对象,跨端行为不一致,容易埋坑;而我们已经拿到 Blob 了。
 */
function uploadWav(blob) {
  const form = new FormData()
  form.append('audio', blob, 'voice.wav')
  form.append('format', 'wav')

  // ⚠ 绝不设置 Content-Type —— 必须让浏览器自己生成带 boundary 的值
  return fetch(ASR_URL, {
    method: 'POST',
    headers: authHeader(),
    body: form,
  })
    .then((res) => res.json().catch(() => null))
    .catch(() => {
      const e = new Error('upload failed')
      e.__voiceCode = ERR.UPLOAD_FAILED
      throw e
    })
}
// #endif
