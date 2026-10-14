/**
 * 语音合成朗读统一门面 —— 把「流式正文 → 按句切分 → 顺序合成 → 顺序播放」这条链路
 * 收在这一个文件里,页面只面对「开始 / 结束 / 出错 / 被浏览器拦」四件事。
 *
 * ── 两条链路 ──
 *   1. 流式朗读:AI 回复边生成边喂(feed),按句边界切出完整句子就合成播放
 *   2. 整段朗读:重播按钮一次喂完整段(speak)
 *   两者是同一套机制,speak 只是「reset + feed + end」的语法糖。
 *
 * ⚠ 平台差异必须**全部**写在 `// #ifdef` 块内。uni-app 打包时会把不属于当前平台的
 *   条件块整段删除,所以小程序产物里根本不会出现 `Blob` / `URL.createObjectURL`;
 *   但只要在块外碰到一次平台专有 API,构建就会报错。
 *
 * ⚠ 本模块支持 MP-WEIXIN 与 H5。`isTtsSupported()` 刻意**只在两个 #ifdef 块里 return**
 *   —— 其它平台(如 APP-PLUS)两个块都被删掉,函数隐式返回 undefined(假值),
 *   于是自动降级为 no-op 会话。这样就不必写语义不确定的 `#ifndef A || B`。
 *
 * ⚠ 朗读是**附加体验**:本模块所有方法都保证不向页面抛异常、永不 reject。
 *   任何失败都只走 onError 或静默跳过,绝不能因为一次朗读失败影响聊天主流程。
 *
 * ⚠ `InnerAudioContext` 是原生对象,绝不能放进 Vue 的 data(进响应式代理会
 *   Illegal invocation)。本模块把它收在闭包里,页面只拿到几个方法 —— 这个坑
 *   项目在 voice.js 的 RecorderManager 上已经踩过一次。
 */

import { BASE_URL, TTS_BASE_URL, TOKEN_KEY } from './config'

/** 朗读后端地址:可指向另一个后端进程(见 config.js 的 TTS_BASE_URL 注释) */
const TTS_URL = (TTS_BASE_URL || BASE_URL) + '/api/tts'

/** 单句硬切上限。留余量给后端 150 字上限,免得前端切出的句子还要后端再拆 */
const SENTENCE_MAX = 120

/** 流中断兜底:这么久没有新增量就把缓冲区里剩下的切出来,免得半句被压住几秒 */
const IDLE_FLUSH_MS = 400

/** 连续合成失败到这么多次就整体放弃(几乎一定是系统性的:密钥/欠费/音色 ID 错) */
const FAIL_STREAK_LIMIT = 3

/**
 * 预取深度:最多允许几句"已合成待播"堆在流水线里。
 *
 * 1 句就足以把段间空档抹掉(播放一句要几秒,而合成只要 1 秒左右);
 * 调大只会让长回答一次性打出更多请求、更可能触发限流,收益却没了。
 */
const PIPELINE_AHEAD = 1

/** 补尾保险:末端剩余超过这个长度说明对齐失败,宁可少念也不要重复念一整段 */
const TAIL_MAX_CHARS = 60

/** 临时文件名序号。放模块级是因为 toPlayable 在模块级(见该函数注释) */
let fileSeq = 0

/**
 * 云端合成是否可用。
 *   null  = 还没试过(首次会试)
 *   true  = 可用
 *   false = 已确认**系统性**不可用(未配置 / 密钥错 / 欠费)→ 本次会话不再尝试
 *
 * ⚠ 放模块级而不是会话级是刻意的:这个结论在一次会话的每一句之间都成立,
 *   放会话级会导致**每条消息的第一句话都白跑一次**注定失败的请求。
 *   代价:改了配置(比如补上密钥)需要重新加载页面才会再次尝试云端 —— 可以接受。
 */
let backendUsable = null

export const ERR = {
  NO_SUPPORT: 'NO_SUPPORT',
  NOT_CONFIGURED: 'NOT_CONFIGURED',
  // 系统性不可用(资源包用尽 / 鉴权失败 / 服务未开通 / 音色 ID 错)。重试不会变好
  UNAVAILABLE: 'UNAVAILABLE',
  SYNTH_FAILED: 'SYNTH_FAILED',
  NETWORK: 'NETWORK',
  PLAY_FAILED: 'PLAY_FAILED',
  BLOCKED: 'BLOCKED',
  UNKNOWN: 'UNKNOWN',
}

export const ERR_TEXT = {
  NO_SUPPORT: '当前环境不支持朗读',
  NOT_CONFIGURED: '朗读功能正在配置中',
  UNAVAILABLE: '朗读功能暂时不可用',
  SYNTH_FAILED: '朗读失败',
  NETWORK: '网络异常',
  PLAY_FAILED: '播放失败',
  // ⚠ 这条是**按钮文案**不是 toast 文案:H5 里自动播放会被浏览器拦,让用户点一下就好
  BLOCKED: '点击朗读',
  UNKNOWN: '朗读出错了',
}

/** 后端 code → 前端错误码。没列出的都归为"临时失败",值得跳过继续下一句 */
const BACKEND_CODE_MAP = {
  TTS_NOT_CONFIGURED: ERR.NOT_CONFIGURED,
  TTS_UNAVAILABLE: ERR.UNAVAILABLE,
}

// ==================== 对外接口 ====================

/** 当前平台是否具备朗读能力。见文件头注释:未匹配任何块时隐式返回 undefined */
export function isTtsSupported() {
  // #ifdef MP-WEIXIN
  return true
  // #endif

  // #ifdef H5
  // H5 有两条路:浏览器内置语音合成(优先,零成本)**或**后端 /api/tts
  return (typeof window !== 'undefined')
    && (browserCanSpeak() || (typeof URL !== 'undefined' && !!URL.createObjectURL))
  // #endif
}

/**
 * 创建一个朗读会话。
 *
 * handlers: { onStart(), onEnd(), onError(code, message), onBlocked() }
 *   · onStart   首次真正开始发声时触发一次(用来把按钮切成"朗读中")
 *   · onEnd     **一旦触发过就必定恰好调用一次**(自然读完 / stop / 致命失败),
 *               用来复位 UI。与 voice.js 的 onEnd 契约一致。
 *   · onError   只在**致命**失败时触发(连续失败熔断 / 未配置)。单句失败静默跳过。
 *   · onBlocked H5 自动播放被浏览器拦下,页面据此把按钮文案改成"点击朗读"
 *
 * 返回:
 *   feed(text)        喂流式增量(原文,含 markdown)
 *   end(fullAnswer?)  流结束:冲掉缓冲区 + 补尾 + 读完整队列后触发 onEnd
 *   speak(text)       整段朗读(重播),内部 = reset + feed + end
 *   stop()            立刻静音 + 清队列 + 释放播放器,onEnd 恰好一次
 *   isSpeaking()      正在发声或队列非空
 *   destroy()         stop 的同义词(页面 onUnload 用,语义上更明确)
 */
export function createTtsSession(handlers = {}) {
  const H = normalize(handlers)

  // 平台不支持 → 返回空会话,页面代码一个分支都不用改
  if (!isTtsSupported()) {
    let told = false
    const none = () => {}
    return {
      feed: none,
      end: none,
      speak: () => {
        if (!told) { told = true; H.onError(ERR.NO_SUPPORT, ERR_TEXT[ERR.NO_SUPPORT]) }
      },
      stop: none,
      isSpeaking: () => false,
      destroy: none,
    }
  }

  // ── 会话状态(全部在闭包里,不进 Vue 响应式) ──
  let state = 'active' // active | draining | closed
  let gen = 0 // 打断代数:stop/speak 时 ++,在途异步回调据此立即失效
  let buf = '' // 还没切出句子的原始尾巴
  let consumedRaw = '' // 已经从 buf 切出去的原始文本(补尾对齐用)
  let queue = [] // 待合成的文本
  let ready = [] // 已合成、待播放的源(FIFO,保证顺序)
  let synthesizing = false
  let playing = false
  let started = false // onStart 是否已触发
  let failStreak = 0
  let idleTimer = null
  let player = null // InnerAudioContext,本会话独占,收尾时释放
  let ended = false

  const clearIdle = () => {
    if (idleTimer) { clearTimeout(idleTimer); idleTimer = null }
  }

  const releasePlayer = () => {
    if (player) {
      try { player.stop() } catch (e) { /* 可能已结束 */ }
      try { player.destroy() } catch (e) { /* 同上 */ }
      player = null
    }
  }

  /** 收尾。幂等 —— 保证 onEnd 恰好一次 */
  const finishOnce = () => {
    if (ended) return
    ended = true
    state = 'closed'
    clearIdle()
    releasePlayer()
    try { H.onEnd() } catch (e) { /* 页面回调抛异常不能反过来影响我们 */ }
  }

  /** 致命失败:报错 + 收尾(UI 由页面在 onError 里复位) */
  const fatal = (code) => {
    try { H.onError(code, ERR_TEXT[code] || ERR_TEXT.UNKNOWN) } catch (e) { /* 同上 */ }
    finishOnce()
  }

  // ==================== 队列与播放 ====================

  function enqueue(text) {
    if (state === 'closed') return
    queue.push(text)
    pump()
  }

  function maybeFinish() {
    // ⚠ 必须把 ready 与 synthesizing 也算进来:流水线里可能还有"已合成待播"或
    //   "正在合成"的句子,此时收尾会把最后一句吞掉。
    if (state === 'draining' && !queue.length && !ready.length && !synthesizing && !playing) {
      finishOnce()
    }
  }

  /**
   * 推进流水线:**合成与播放并行**。
   *
   * ⚠ 这是"段与段之间接不接得上"的关键。原先串行(念完第 N 句才去请求第 N+1 句)时,
   *   每段之间的空档 = 一次完整的云端往返(约 1~2 秒),听起来就是"念一句、停一下"。
   *   改成预取后,第 N 句还在播时第 N+1 句就已经合成好了,空档只剩音频自身的尾部静音。
   */
  function pump() {
    if (state === 'closed') return

    // ⚠ 顺序要紧:**先播、再预取**。
    //   反过来的话,playNext 还没把 ready 里的那句取走,深度限制就会把预取挡掉 ——
    //   结果"预取"永远只发生在上一句播完之后,空档一点没减少(实测确实如此,空档仍等于一次合成耗时)。
    playNext()
    synthesizeNext()
  }

  /**
   * 预取下一句。
   * ⚠ 限深:最多 1 个在途 + PIPELINE_AHEAD 个已就绪 —— 否则长回答会一次性打出十几个请求,
   *   既可能触发限流,也白占内存。
   */
  function synthesizeNext() {
    if (synthesizing || ready.length >= PIPELINE_AHEAD || !queue.length) return

    synthesizing = true
    const myGen = gen
    const text = queue.shift()

    synthOne(text, myGen, (sources) => {
      if (myGen !== gen) return
      synthesizing = false
      failStreak = 0
      ready.push(sources)
      pump() // 可能已经可以接着播了
      maybeFinish()
    }, (code) => {
      if (myGen !== gen) return
      synthesizing = false

      // 未配置 / 系统性不可用(资源包用尽、鉴权失败…)重试不会变好 ——
      // 立刻停下,并把 code 交给页面给出明确交代,而不是白刷请求。
      if (code === ERR.NOT_CONFIGURED || code === ERR.UNAVAILABLE) { fatal(code); return }

      // ★ 单句失败**跳过继续**:一次回复常有 5~15 句,为一次网络抖动把后面全哑掉,
      //   用户会以为"朗读坏了"。但连续失败基本一定是系统性问题(密钥/欠费/音色 ID),
      //   继续对每句发注定失败的请求既慢又刷日志 —— 所以有熔断。
      failStreak++
      if (failStreak >= FAIL_STREAK_LIMIT) { fatal(ERR.SYNTH_FAILED); return }

      pump()
      maybeFinish()
    })
  }

  /** 播下一句(已有备好的、且当前没在播时) */
  function playNext() {
    if (playing || !ready.length) return

    playing = true
    const myGen = gen
    const sources = ready.shift()

    playAll(sources, myGen, (code) => {
      if (myGen !== gen) return
      playing = false

      if (code === ERR.BLOCKED) {
        // H5 自动播放被拦:不是错误,交给页面改按钮文案
        queue = []
        ready = []
        try { H.onBlocked() } catch (e) { /* 忽略 */ }
        finishOnce()
        return
      }

      if (code) console.warn('[tts] 播放失败', code)
      pump()
      maybeFinish()
    })
  }

  /**
   * 合成一段文本,产出**可播放源数组**。
   *
   * ── 优先级:**云端优先,本机引擎兜底** ──
   *   云端(火山语音合成大模型,豆包同源)的音色和情感明显好于本机引擎,
   *   而这次的诉求就是"像人",所以它是**首选**,哪怕要花点钱和一次往返延迟。
   *
   *   本机引擎(H5 的浏览器内置 / 小程序的同声传译插件)只在**云端系统性不可用**时兜底 ——
   *   它们是免费的,但机械感明显,只配当"总比没声音强"。
   *
   *   ⚠ 这里刻意**没有**沿用 utils/voice.js"能不出后端就不出"的思路:
   *     语音识别只有准确率一个维度,免费的就够;而语音合成**音色就是产品本身**。
   *
   * 可播放源有三种形态(见 playAll / toPlayable):
   *   { speak }  直接交给浏览器朗读(不是音频文件)
   *   { path }   插件给的临时文件路径(直接能播)
   *   { base64 } 云端给的 base64 音频(需要落盘/转 Blob)
   */
  function synthOne(text, myGen, onOk, onFail) {
    // 已经确认云端不可用 → 本次会话不再尝试,直接用本机引擎
    if (backendUsable !== false) {
      backendSynthesize(text, myGen, onOk, (code) => {
        // ⚠ 只有**系统性**不可用(未配置 / 密钥错 / 欠费)才切到本机引擎,
        //   并**永久记住**(本次会话)—— 否则每句话都要白跑一次注定失败的请求。
        //   临时性失败(网络抖动)仍按原逻辑跳过这一句,不动优先级。
        if (code === ERR.NOT_CONFIGURED || code === ERR.UNAVAILABLE) {
          backendUsable = false
          localSynthesize(text, myGen, onOk, onFail)
          return
        }

        onFail(code)
      })

      return
    }

    localSynthesize(text, myGen, onOk, onFail)
  }

  /** 本机引擎兜底:小程序走插件,H5 走浏览器内置合成 */
  function localSynthesize(text, myGen, onOk, onFail) {
    // #ifdef MP-WEIXIN
    const plugin = getTtsPlugin()
    if (plugin) { pluginSynthesize(plugin, text, myGen, onOk, onFail); return }
    // #endif

    // #ifdef H5
    if (browserCanSpeak()) { onOk([{ speak: text }]); return }
    // #endif

    // 两个本机引擎都没有 → 如实报告"不支持",页面会提示用文字输入
    onFail(ERR.NO_SUPPORT)
  }

  // #ifdef MP-WEIXIN
  /**
   * 用同声传译插件合成。
   *
   * ⚠ 必须显式传 `tts: true` —— 这个接口同时用于"翻译",不传 tts 会走翻译而不是语音合成。
   *
   * ⚠ 插件返回的 filename 是**它自己管理的临时文件**,我们只读不删 ——
   *   删了可能影响插件内部缓存。这与我们自己写进 USER_DATA_PATH 的 tts_*.mp3 不同,
   *   那些必须删(见 toPlayable 的清理逻辑)。
   */
  function pluginSynthesize(plugin, text, myGen, onOk, onFail) {
    plugin.textToSpeech({
      lang: 'zh_CN',
      tts: true,
      content: text,
      success: (res) => {
        if (myGen !== gen) return
        const path = (res && res.filename) || ''
        if (!path) { onFail(ERR.SYNTH_FAILED); return }
        onOk([{ path }])
      },
      // ⚠ 插件的失败大多是"免费服务限流/超长",都当临时失败 ——
      //   跳过这句继续下一句,而不是整段放弃(tts.js 的熔断会兜住真·系统性故障)。
      fail: () => { if (myGen === gen) onFail(ERR.SYNTH_FAILED) },
    })
  }
  // #endif

  /**
   * 后端合成。
   * ⚠ 刻意不走 utils/request.js —— 它见 401 会清空登录态,而朗读是游客也能用的
   *   附加功能,绝不能因为一次朗读把用户踢下线。即便这里真收到 401 也只当合成失败。
   */
  function backendSynthesize(text, myGen, onOk, onFail) {
    let token = ''
    try { token = uni.getStorageSync(TOKEN_KEY) || '' } catch (e) { /* 忽略 */ }

    const header = { 'Content-Type': 'application/json' }
    if (token) header.Authorization = 'Bearer ' + token

    uni.request({
      url: TTS_URL,
      method: 'POST',
      data: { text },
      header,
      timeout: 20000,
      success: (res) => {
        if (myGen !== gen) return
        const d = (res && res.data) || {}
        if (!d.success || !(d.audios || []).length) {
          onFail(BACKEND_CODE_MAP[d.code] || ERR.SYNTH_FAILED)
          return
        }
        onOk(d.audios.map((b64) => ({ base64: b64 })))
      },
      fail: () => { if (myGen === gen) onFail(ERR.NETWORK) },
    })
  }

  /**
   * 顺序播放一组音频。done(code) —— code 为空表示正常播完。
   *
   * ⚠ 用 gen 校验而不是本地标志:stop() 之后在途的回调必须立刻失效,否则会出现
   *   "已经停了,上一段的 onEnded 又把下一段放出来"。
   */
  function playAll(sources, myGen, done) {
    let i = 0
    let handle = null
    let ticket = 0 // 每段一个令牌,用来丢掉陈旧事件

    const step = () => {
      if (myGen !== gen) { if (handle) handle.cleanup(); return }
      if (i >= sources.length) { if (handle) handle.cleanup(); done(null); return }

      if (!started) {
        started = true
        try { H.onStart() } catch (e) { /* 忽略 */ }
      }

      const source = sources[i++]

      // ⚠ 本段的令牌。凡是"已经切到下一段"之后才到的回调一律丢弃。
      //   这是防**"说着说着跳到另一段"**的关键:播放器和语音合成都可能补发迟到的事件
      //   (尤其是复用播放器时,上一段会补一个 onEnded),不校验就会让 step() 多推一格,
      //   结果就是跳过或漏掉一整段。
      const mine = ++ticket
      const stale = () => mine !== ticket || myGen !== gen

      // #ifdef H5
      // 浏览器内置语音合成:它不是音频文件,交给它直接念,念完再进下一句。
      // ⚠ 必须在这里分流,不能塞进 toPlayable —— 它没有 src,也不该走播放器。
      if (source && source.speak) {
        if (handle) { handle.cleanup(); handle = null }
        speakViaBrowser(source.speak, (code) => {
          if (stale()) return
          if (code) { done(code); return }
          step()
        })
        return
      }
      // #endif

      const next = toPlayable(source, done)
      if (!next) { if (handle) handle.cleanup(); return }

      // 上一段播完就删,别把临时文件攒着(小程序 USER_DATA_PATH 只有 200MB)
      if (handle) handle.cleanup()
      handle = next

      // ⚠ 每段用一个**全新的** InnerAudioContext,播完立刻销毁。
      //   复用同一个实例反复改 src 会踩微信音频的经典坑:上一段补发迟到的 onEnded,
      //   于是 step() 被多推一次 —— 现象就是"说着说着跳到另一段"。
      const p = uni.createInnerAudioContext()
      player = p // 让 stop() 能停到"当前这一段"
      // ⚠ iOS 默认 true:静音键一开就完全没声,用户会以为功能坏了
      p.obeyMuteSwitch = false

      const release = () => {
        if (player === p) player = null
        try { p.offEnded() } catch (e) { /* 忽略 */ }
        try { p.offError() } catch (e) { /* 忽略 */ }
        try { p.destroy() } catch (e) { /* 忽略 */ }
      }

      p.onEnded(() => {
        if (stale()) return
        release()
        step()
      })

      p.onError(() => {
        if (stale()) return
        release()
        // 播放失败最常见的原因就是 H5 的自动播放策略,统一交给 BLOCKED 处理
        done(ERR.BLOCKED)
      })

      p.src = next.src
      p.play()
    }

    step()
  }

  function reset() {
    gen++
    clearIdle()
    queue = []
    ready = []
    synthesizing = false
    playing = false
    started = false
    failStreak = 0
    buf = ''
    consumedRaw = ''
    state = 'active'
  }

  const api = {
    feed(text) {
      try {
        if (state !== 'active' || !text) return
        buf += String(text)
        drain()
        scheduleIdleFlush()
      } catch (e) { /* 静默:朗读失败绝不影响聊天 */ }
    },

    /**
     * 流结束。fullAnswer 是服务端给的权威正文(done.answer),用于补尾。
     *
     * ⚠ 为什么必须补尾:后端 relayCoze 有 `$safeLen = mb_strlen($full) - 12`
     *   —— **预留 12 字符不发**,且遇到 `【推荐机型】` 就停发。所以流式累计文本是
     *   done.answer 的**真前缀且尾部少 12~20 字**,中文里可能就是最后半句话。
     */
    end(fullAnswer) {
      try {
        if (state === 'closed') return
        if (state !== 'active') { maybeFinish(); return }

        state = 'draining'
        clearIdle()

        const tailRaw = buf
        buf = ''
        consumedRaw += tailRaw

        let tail = cleanForSpeech(tailRaw)

        if (fullAnswer) {
          const extra = remainingAfterCommonPrefix(
            cleanForSpeech(consumedRaw),
            cleanForSpeech(fullAnswer),
          )
          // 剩下的就是流里没发出来的尾巴。太长说明对齐失败(宁可少念也不要重复念)
          if (extra && extra.length <= TAIL_MAX_CHARS) tail = (tail + extra).trim()
        }

        const cleanTail = cleanForSpeech(tail)
        if (usable(cleanTail)) enqueue(cleanTail)

        maybeFinish()
      } catch (e) { finishOnce() }
    },

    /** 整段朗读(重播) */
    speak(text) {
      try {
        reset()
        sweepStaleFiles()
        if (!text) { finishOnce(); return }
        api.feed(text)
        api.end(text)
      } catch (e) { finishOnce() }
    },

    stop() {
      // ⚠ 顺序要紧:先 ++gen 让所有在途回调失效,再停声、清队列
      gen++
      queue = []
      ready = []
      synthesizing = false
      playing = false
      clearIdle()
      if (player) { try { player.stop() } catch (e) { /* 忽略 */ } }
      // #ifdef H5
      // ⚠ 浏览器语音合成**不归播放器管**,必须单独取消 ——
      //   否则 stop() 之后当前这句还会继续念完
      try { cancelBrowserSpeech() } catch (e) { /* 忽略 */ }
      // #endif
      buf = ''
      finishOnce()
    },

    isSpeaking() {
      return !ended && (playing || synthesizing || queue.length > 0 || ready.length > 0)
    },

    destroy() {
      api.stop()
    },
  }

  // ==================== 会话内部:依赖会话状态的部分 ====================

  /** 把缓冲区里所有可切的句子切出来入队 */
  function drain() {
    for (;;) {
      const at = findCut(buf, false)
      if (at < 0) break

      const raw = buf.slice(0, at)
      buf = buf.slice(at)
      consumedRaw += raw

      const clean = cleanForSpeech(raw)
      if (usable(clean)) enqueue(clean)
    }
  }

  function scheduleIdleFlush() {
    clearIdle()
    idleTimer = setTimeout(() => {
      idleTimer = null
      if (state !== 'active') return
      drainEnded()
      pump()
    }, IDLE_FLUSH_MS)
  }

  /** 流中断/结束时允许在缓冲区末尾切(收尾引号不会再来) */
  function drainEnded() {
    const at = findCut(buf, true)
    if (at < 0) return
    const raw = buf.slice(0, at)
    buf = buf.slice(at)
    consumedRaw += raw
    const clean = cleanForSpeech(raw)
    if (usable(clean)) enqueue(clean)
  }

  // 会话开始就把上一次异常退出留下的临时文件清掉
  sweepStaleFiles()

  return api
}

// ==================== 模块级纯函数 ====================

// 装饰性标点:念不出来,直接删掉(句末的会先被切句逻辑吃掉,这里处理句中的)
const DECORATION = /["'“”‘’「」『』（）()【】《》]/g
// emoji:念不出来,且部分引擎会因此报错
const EMOJI = /[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}\u{2190}-\u{21FF}]/gu
// 洗完之后还有没有可念的内容(纯标点/纯空白就没有)
const MEANINGFUL = /[一-龥a-zA-Z0-9]/

const STRONG = '。！？…；!?;'
const SOFT = '，,、：:'
const CLOSERS = '”’」』"\'）)】》'

/** 洗完之后还有没有可念的内容 */
function usable(t) {
  return MEANINGFUL.test(t)
}

/**
 * 把「给人看的 markdown 文本」洗成「给机器念的纯文本」。
 *
 * ⚠ 这一步是**必须的,不是锦上添花**:已核实后端的 stripMarkdown 只在 done 事件里
 *   调用(SummonAiController:179),流式 delta 是 Coze 原始输出,`**` `#` `-` 全在。
 *   不清洗的话会听见"星号星号"。
 *
 * ⚠ 中文标点(。！？，、：；)一律**保留** —— 腾讯 TTS 靠它们决定停顿,删了会变成一口气念完。
 *
 * 导出成纯函数是为了能在 console 里单独喂文本核对正则有没有误伤正常正文。
 */
export function cleanForSpeech(input) {
  let t = String(input == null ? '' : input)

  t = t.replace(/```[a-zA-Z]*\n?/g, '') // 代码块围栏(内容保留)
  t = t.replace(/`([^`]*)`/g, '$1') // 行内代码
  t = t.replace(/!\[[^\]]*\]\([^)]*\)/g, '') // 图片:整个删
  t = t.replace(/\[([^\]]*)\]\([^)]*\)/g, '$1') // 链接:只留文字
  t = t.replace(/https?:\/\/\S+/g, '') // 裸网址:念出来毫无意义
  t = t.replace(/^\s{0,3}#{1,6}\s*/gm, '') // 标题号
  t = t.replace(/^\s{0,3}[-*+]\s+/gm, '') // 无序列表符
  t = t.replace(/^\s{0,3}\d+[.)]\s+/gm, '') // 有序列表号
  t = t.replace(/^\s{0,3}>\s?/gm, '') // 引用符
  t = t.replace(/^\s{0,3}\|?[\s:|-]+\|[\s:|-]*$/gm, '') // 表格分隔行
  t = t.replace(/\|/g, '，') // 表格竖线 → 停顿
  t = t.replace(/<[^>]+>/g, '') // HTML 残留
  t = t.replace(/(\*\*|__|~~)/g, '') // 成对强调记号
  t = t.replace(EMOJI, '')
  t = t.replace(DECORATION, '')
  t = t.replace(/[ \t]+/g, ' ')
  // 删掉行首行尾的空格(emoji/链接被删后常留下多余空格,念出来会有怪停顿)
  t = t.replace(/[ \t]*\n[ \t]*/g, '\n')
  // 标点前后不留空格,否则 TTS 的停顿会怪
  t = t.replace(/\s*([。！？…；，、：])\s*/g, '$1')

  return t.trim()
}

/**
 * 找出「可以切到哪里」(返回不含该下标),-1 表示还不能切。
 *
 * @param {boolean} ended 流是否已结束。未结束时,**紧贴缓冲区末尾的终结符不切** ——
 *                        后面可能还跟着收尾引号(`他说:"你好。"` 不能切在 `。` 之后)。
 */
function findCut(s, ended) {
  let lastSoft = -1

  for (let i = 0; i < s.length; i++) {
    const c = s[i]

    if (STRONG.indexOf(c) >= 0 || c === '\n') {
      let j = i + 1
      // 连续终结符与收尾符一起吞掉:`……`、`？！`、`。"`
      while (j < s.length && (STRONG.indexOf(s[j]) >= 0 || s[j] === '\n' || CLOSERS.indexOf(s[j]) >= 0)) j++
      if (!ended && j >= s.length) return -1
      return j
    }

    if (SOFT.indexOf(c) >= 0 || c === ' ') lastSoft = i + 1
  }

  // 超长又没标点 → 退到最后一个弱断点;连弱断点都没有就硬切
  if (s.length >= SENTENCE_MAX) {
    return lastSoft > SENTENCE_MAX * 0.5 ? lastSoft : SENTENCE_MAX
  }

  return -1
}

/** 取 s2 中「超出 s1 公共前缀」的剩余部分;没有公共前缀时返回 s2 本身 */
function remainingAfterCommonPrefix(s1, s2) {
  if (!s2) return ''
  if (!s1) return s2
  let i = 0
  const max = Math.min(s1.length, s2.length)
  while (i < max && s1[i] === s2[i]) i++
  return s2.slice(i)
}

// #ifdef MP-WEIXIN
/**
 * 同声传译插件实例缓存。
 * ⚠ undefined = 还没探过;null = 探过了但没有 —— 必须区分,
 *   否则每次合成都得重新 requirePlugin,而它抛异常是常态。
 */
let ttsPlugin

/**
 * 取同声传译插件。任何异常都返回 null,让调用方降级到后端合成。
 *
 * ⚠ 插件不可用的原因太多了(manifest 没声明 / mp 后台没添加 / 版本号不符 /
 *   基础库太老),不该逐个判 —— 与 utils/voice.js 的 getPluginManager 同一思路。
 *
 * ⚠ requirePlugin 在非小程序构建里是 undefined,直接调用会抛错,所以整段必须
 *   包在 #ifdef MP-WEIXIN 内(项目纪律:块外碰一次平台 API,构建就报错)。
 */
function getTtsPlugin() {
  if (ttsPlugin !== undefined) return ttsPlugin
  try {
    const plugin = requirePlugin('WechatSI')
    ttsPlugin = (plugin && typeof plugin.textToSpeech === 'function') ? plugin : null
  } catch (e) {
    ttsPlugin = null
  }
  return ttsPlugin
}
// #endif

// #ifdef H5
/**
 * 浏览器是否具备内置语音合成(Web Speech API)。
 * ⚠ 两个都要判:某些 WebView 有 speechSynthesis 却没有 SpeechSynthesisUtterance 构造函数。
 */
function browserCanSpeak() {
  return typeof window !== 'undefined'
    && typeof window.speechSynthesis !== 'undefined'
    && typeof window.SpeechSynthesisUtterance !== 'undefined'
}

/**
 * 用浏览器内置语音合成念一段文字。done(code) —— code 为空表示正常念完。
 *
 * ⚠ 它不产生音频文件,所以**不经过播放器**,也就没有 src / cleanup 那一套。
 *
 * ⚠ 音色由操作系统与浏览器决定,我们只能约束语言:设 lang='zh-CN' 让浏览器挑一个
 *   中文音色。系统里没装中文音色时会念得很怪,但不会报错。
 *
 * ⚠ Chrome 在标签页切到后台时会暂停合成 —— 方向与我们 onHide 主动 stop 一致,
 *   不用担心它偷偷念。
 */
function speakViaBrowser(text, done) {
  try {
    const synth = window.speechSynthesis
    // 先清干净:上一句可能还残留在合成队列里,不清会与这一句叠着念
    synth.cancel()

    const utter = new SpeechSynthesisUtterance(text)
    utter.lang = 'zh-CN'
    utter.rate = 1
    utter.onend = () => done(null)
    // 出错多是被自动播放策略拦下 → 映射到 BLOCKED,让页面把按钮改成"点击朗读"
    utter.onerror = () => done(ERR.BLOCKED)

    synth.speak(utter)
  } catch (e) {
    done(ERR.BLOCKED)
  }
}

/** 取消浏览器语音合成(必须单独调 —— 它不归 innerAudioContext 管) */
function cancelBrowserSpeech() {
  const synth = window.speechSynthesis
  if (synth) synth.cancel()
}
// #endif

/** 清掉历史残留(上一次会话异常退出可能留下临时文件) */
function sweepStaleFiles() {
  // #ifdef MP-WEIXIN
  try {
    const fs = uni.getFileSystemManager()
    const files = fs.readdirSync(wx.env.USER_DATA_PATH) || []
    files.filter((f) => f.indexOf('tts_') === 0).forEach((f) => {
      try { fs.unlinkSync(wx.env.USER_DATA_PATH + '/' + f) } catch (e) { /* 忽略 */ }
    })
  } catch (e) { /* 扫不动就算了,不影响朗读 */ }
  // #endif
}

/**
 * 可播放源 → 播放器能吃的 src。
 *
 * 源有两种形态:
 *   { path }   同声传译插件给的临时文件路径 —— 直接就是可播的,**且不要删**
 *              (那是插件自己管理的文件,删了可能影响它的内部缓存)
 *   { base64 } 后端给的 base64 音频 —— 需要落盘(小程序)或转 Blob(H5)。
 *              这些是**我们自己**的文件,必须清理,否则会撑爆 USER_DATA_PATH。
 *
 * 返回 { src, cleanup() };失败时返回 null 并回调错误码。
 *
 * ⚠ base64 分支才有平台差异(MP-WEIXIN / H5);其它平台在 createTtsSession 开头
 *   就被 isTtsSupported() 挡掉,返回的是 no-op 会话,走不到这里。
 */
function toPlayable(source, onFail) {
  // 插件给的路径:与平台无关,直接可用;**不清理**(不是我们的文件)
  if (source && source.path) {
    return { src: source.path, cleanup() {} }
  }

  const base64 = (source && source.base64) || ''
  if (!base64) { onFail(ERR.PLAY_FAILED); return null }

  // #ifdef MP-WEIXIN
  try {
    const fs = uni.getFileSystemManager()
    // ⚠ 必须写文件而不是用 data: URL —— 小程序端对 data: 音频的支持按基础库/机型而异
    const path = wx.env.USER_DATA_PATH + '/tts_' + Date.now() + '_' + (fileSeq++) + '.mp3'
    fs.writeFileSync(path, base64, 'base64')
    return {
      src: path,
      cleanup() { try { fs.unlinkSync(path) } catch (e) { /* 已删/不存在 */ } },
    }
  } catch (e) {
    onFail(ERR.PLAY_FAILED)
    return null
  }
  // #endif

  // #ifdef H5
  try {
    const bin = atob(base64)
    const bytes = new Uint8Array(bin.length)
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i)
    const url = URL.createObjectURL(new Blob([bytes], { type: 'audio/mpeg' }))
    return {
      src: url,
      cleanup() { try { URL.revokeObjectURL(url) } catch (e) { /* 忽略 */ } },
    }
  } catch (e) {
    onFail(ERR.PLAY_FAILED)
    return null
  }
  // #endif
}

function normalize(h) {
  return {
    onStart: h.onStart || noop,
    onEnd: h.onEnd || noop,
    onError: h.onError || noop,
    onBlocked: h.onBlocked || noop,
  }
}

function noop() {}
