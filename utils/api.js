/**
 * API 模块集合 —— 对接 Bagisto（Laravel）后端 REST API
 *
 * 覆盖：认证 / 商品 / 分类 / 购物车 / 结算下单 / 订单 / 地址
 * 全部请求经过 utils/request.js 统一封装（自动带 Bearer token）
 */
import { http } from './request'
// 流式接口(summonStream)不走 request.js 的统一封装,需要自己拼 URL 和 token
import { BASE_URL, TOKEN_KEY } from './config'

// ==================== 认证 ====================
export const authApi = {
  login: (email, password) => http.post('/api/customer/login', { email, password }, { loading: true, loadingText: '登录中...' }),
  register: (payload) => http.post('/api/customer/register', payload, { loading: true, loadingText: '注册中...' }),
  // 登出：通知后端撤销当前 Sanctum token（即使请求失败，前端也会清本地登录态）
  logout: () => http.post('/api/customer/logout', {}, { loading: false }),
}

// ==================== 商品 ====================
export const productApi = {
  // 商品列表：params 支持 { category_id, search=query, limit, page, sort }
  getProducts: (params = {}) => http.get('/api/products', { data: params, loading: false }),
  // 单商品详情
  getProductDetail: (id) => http.get(`/api/products/${id}`, { loading: false }),
  // 相关商品
  getRelatedProducts: (id) => http.get(`/api/products/${id}/related`, { loading: false }),
}

// ==================== 分类 ====================
export const categoryApi = {
  getCategories: (params = {}) => http.get('/api/categories', { data: params, loading: false }),
  getCategoryTree: () => http.get('/api/categories/tree', { loading: false }),
}

// ==================== 购物车 ====================
export const cartApi = {
  getCart: () => http.get('/api/checkout/cart', { loading: false }),
  // data: { product_id, quantity, is_buy_now }
  addToCart: (data) => http.post('/api/checkout/cart', data, { loading: false }),
  updateCart: (data) => http.put('/api/checkout/cart', data, { loading: false }),
  removeCartItem: (cartItemId) => http.delete('/api/checkout/cart', { cart_item_id: cartItemId }, { loading: false }),
}

// ==================== 结算 / 下单 ====================
export const checkoutApi = {
  summary: () => http.get('/api/checkout/onepage/summary', { loading: false }),
  // data: { billing: {...}, shipping: {...} }  （billing.use_for_shipping=1 时合二为一）
  storeAddress: (data) => http.post('/api/checkout/onepage/addresses', data, { loading: false }),
  // shipping_method: 形如 "flatrate_flatrate"
  storeShipping: (shippingMethod) => http.post('/api/checkout/onepage/shipping-methods', { shipping_method: shippingMethod }, { loading: false }),
  // payment: 支付方式编码，形如 "cashondelivery"
  // 注意：后端 Cart::savePaymentMethod(array $params) 需要 { method: code } 数组结构，故用数组包裹
  storePayment: (payment) => http.post('/api/checkout/onepage/payment-methods', { payment: { method: payment } }, { loading: false }),
  // 提交订单
  storeOrder: () => http.post('/api/checkout/onepage/orders', {}, { loading: false }),
}

// ==================== TOB 统一下单（租/买/配件/技能，真实后台订单） ====================
export const tobOrderApi = {
  // loading 统一开启:下单/留资在慢环节时给"提交中"反馈(mask 挡重复点击),避免用户误以为无响应
  create: (data) => http.post('/api/tob-order', data, { loading: true, loadingText: '提交中...' }),
}

// ==================== 目录聚合(机器页性能优化) ====================
// 1 个请求返回 {rent:[],buy:[],accessory:[],skill:[],scenes:[]};后端未部署时前端自动回退旧多请求逻辑
export const catalogApi = {
  getAggregate: () => http.get('/api/tob-catalog', { loading: false }),
}

// ==================== 订单 ====================
export const orderApi = {
  getOrders: (params = {}) => http.get('/api/customer/orders', { data: params, loading: false }),
  getGuestOrders: () => http.get('/api/guest-orders', { loading: false }),
  getOrderDetail: (id) => http.get(`/api/customer/orders/${id}`, { loading: false }),
  // 客户自助取消本人待处理订单
  cancelOrder: (id) => http.post(`/api/customer/orders/${id}/cancel`, {}, { loading: true, loadingText: '取消中...' }),
  cancelGuestOrder: (id) => http.post(`/api/guest-orders/${id}/cancel`, {}, { loading: false }),
}

// ==================== 我的设备 ====================
// ⚠ 这里是「登记」不是「绑定」:SN 由用户自填,平台没有任何渠道验证机器真实存在。
//   所以后端返回 verify_status/verify_label(registered = 未核验),界面上必须如实展示。
//   等厂商开放设备接口之后,才有真正意义上的"绑定"。
// ⚠ 这些接口要求登录(后端挂 customer 中间件),未登录会 401。
export const deviceApi = {
  list: () => http.get('/api/devices', { loading: false }),
  // data: { sn, product_id?, display_name? }
  register: (data) => http.post('/api/devices', data, { loading: true, loadingText: '登记中...' }),
  // data: { product_id?, display_name? }
  update: (id, data) => http.put(`/api/devices/${id}`, data, { loading: false }),
  remove: (id) => http.delete(`/api/devices/${id}`, { loading: true, loadingText: '移除中...' }),
  // v3.4 步骤 8:签发一次性 Agent 安装 token(30 分钟、一次性)
  // 返回 { token, expires_at, server_url, command } —— command 是可直接复制的安装命令
  agentToken: (id) => http.post(`/api/devices/${id}/agent-token`, {}, { loading: true, loadingText: '生成中...' }),
}

// ==================== 我的技能 ====================
// 技能授权与技能包下载。下载走鉴权接口(文件在 private 盘,没有公开 URL)。
export const skillApi = {
  mine: () => http.get('/api/skills/mine', { loading: false }),
  assets: (entitlementId) => http.get(`/api/skills/entitlements/${entitlementId}/assets`, { loading: false }),
  // 把一条技能授权指派到本人某台设备 —— 这是平台内部记账(这个技能给哪台机器用),
  // 不涉及任何外部系统,所以用「指派」而不是「绑定」
  assignDevice: (entitlementId, deviceId) => http.put(`/api/skills/entitlements/${entitlementId}/bind`, { device_id: deviceId }, { loading: true, loadingText: '指派中...' }),
  // 兜底通道:用户自报"已装到机器上"。平台无法验证安装结果,只能由用户确认
  confirmInstall: (entitlementId) => http.post(`/api/skills/entitlements/${entitlementId}/confirm-install`, {}, { loading: true, loadingText: '提交中...' }),
  // 下载是二进制流,不能走 request.js 的 JSON 封装 —— 由调用方用 uni.downloadFile 自行处理

  // ===== v3.4 步骤 7 新增 =====
  // 兼容性校验:某技能能否装到某设备(含"未知"降级,后端返回 notes)
  compatibility: (productId, deviceId) => http.get(`/api/skills/${productId}/compatibility`, { data: { device_id: deviceId }, loading: false }),
  // 我的售后单
  afterSales: () => http.get('/api/after-sale', { loading: false }),
  // 发起售后 data: { entitlement_id, reason_category?, detail? }
  applyAfterSale: (data) => http.post('/api/after-sale', data, { loading: true, loadingText: '提交中...' }),
}

// ==================== 通知中心(v3.4 步骤 7) ====================
export const notificationApi = {
  list: () => http.get('/api/notifications', { loading: false }),
  markRead: (id) => http.put(`/api/notifications/${id}/read`, {}, { loading: false }),
}

// ==================== 客服工单(v3.4 步骤 7) ====================
export const ticketApi = {
  list: () => http.get('/api/tickets', { loading: false }),
  // data: { content(必填), category?, skill_id? }
  create: (data) => http.post('/api/tickets', data, { loading: true, loadingText: '提交中...' }),
  detail: (id) => http.get(`/api/tickets/${id}`, { loading: false }),
}

// ==================== 事故上报(v3.4 步骤 10 / 计划 §4.19) ====================
// 技能在真实机器人上物理执行 —— 出事必须有可追溯的上报链。
// 后端在**报案那一刻**就把证据链（技能版本 / 交付物 sha256 / 设备 / 授权）冻结下来，
// 所以报案时能指明 skill_id 与 device_id 的话，追溯链会完整得多。
export const incidentApi = {
  list: () => http.get('/api/incidents', { loading: false }),
  // data: { severity(必填: minor|moderate|severe|critical), description(必填),
  //         skill_id?, device_id?, occurred_at?, evidence?(string[]) }
  // ⚠ evidence 只收文字与链接，**不收文件**（附件上传属未接入能力，见剩余闭环清单）
  create: (data) => http.post('/api/incidents', data, { loading: true, loadingText: '上报中...' }),
  detail: (id) => http.get(`/api/incidents/${id}`, { loading: false }),
}

// ==================== 技能类目/标签(v3.4 步骤 7) ====================
export const skillCatalogApi = {
  categories: () => http.get('/api/skill-catalog/categories', { loading: false }),
  // type 可选:motion_type / scene / difficulty
  tags: (type) => http.get('/api/skill-catalog/tags', { data: type ? { type } : {}, loading: false }),
}

// ==================== 商品评价(v3.4 步骤 7) ====================
// 复用 Bagisto 原生 ProductReview 接口(服务端已有,表/模型/API 齐)
export const reviewApi = {
  list: (productId) => http.get(`/api/product/${productId}/reviews`, { loading: false }),
  // data: { title?, comment, rating(1-5), name? }
  create: (productId, data) => http.post(`/api/product/${productId}/review`, data, { loading: true, loadingText: '提交中...' }),
}

// ==================== 支付下单(v3.4 步骤 4/7) ====================
// ⚠ 资质未就绪时后端走 mock 网关,返回 { out_trade_no, params:{ mock:true, callback_hint } }
export const paymentApi = {
  create: (orderId) => http.post(`/api/payment/orders/${orderId}`, {}, { loading: true, loadingText: '创建支付中...' }),
}

// ==================== 下发任务(v3.4 步骤 8) ====================
export const deployApi = {
  // data: { device_id, skill_id, skill_version_id?, entitlement_id?, operation?, channel? }
  create: (data) => http.post('/api/deploy/tasks', data, { loading: true, loadingText: '下发中...' }),
  status: (taskNo) => http.get(`/api/deploy/tasks/${taskNo}`, { loading: false }),
  cancel: (taskNo) => http.post(`/api/deploy/tasks/${taskNo}/cancel`, {}, { loading: false }),
  // 某设备已安装的技能
  installs: (deviceId) => http.get(`/api/deploy/devices/${deviceId}/installs`, { loading: false }),
}

// ==================== 场景(TOB) ====================
// 机器页场景:列表(后台维护的卡片)+ 详情(TOB 场景真实图/描述)
export const sceneApi = {
  getScenes: (params = {}) => http.get('/api/scenes', { data: params, loading: false }),
  getSceneDetail: (id) => {
    // 避免 sceneId 为 undefined/'undefined'（部分卡片无 id）时请求 /api/scenes/undefined → 500
    if (!id || id === 'undefined') {
      return Promise.resolve(null)
    }
    return http.get(`/api/scenes/${id}`, { loading: false })
  },
}

// ==================== 赛事(生态中心赛事活动 tab) ====================
export const eventApi = {
  getEvents: () => http.get('/api/events', { loading: false }),
  // 真实报名(落 event_registrations 表,后台报名列表可查)
  register: (id, data) => http.post(`/api/events/${id}/register`, data, { loading: true, loadingText: '报名中...' }),
}

// ==================== 小召·召唤引擎(意图→场景→机型) ====================
export const summonApi = {
  // ⚠ 单独放宽超时:后端开了 AI 版(SUMMON_AI_ENABLED=true)时,一次召唤要等
  // Coze Agent 推理 8~20 秒,远超全局默认的 15 秒,否则会静默超时(表现为"发不出、没反应")。
  // 关掉 AI 开关时只影响这一个接口的超时上限,其他接口仍用全局 15 秒。
  //
  // loading: false —— 本页在对话流里自己显示「小召正在思考…」气泡,
  // 再叠一个全屏转圈浮层是重复的(实测两个同时出现)。
  summon: (q, history) => http.post('/api/summon', { q, history }, { loading: false, timeout: 60000 }),
}

/**
 * 小召·召唤「流式版」——边生成边回调。
 *
 * 为什么要流式:非流式要等 5~8 秒才拿到整段;流式实测 **0.6 秒**就有响应、
 * **1.4 秒**答案就开始出来,过程感完全不同。
 *
 * ⚠ 平台差异(uni-app 没有统一的流式 API,必须分端):
 *   · H5      → fetch + ReadableStream
 *   · 小程序/App → uni.request 的 enableChunked + onChunkReceived
 *
 * handlers:
 *   onMeta({q,intent}) / onThinking(text) / onDelta(text) / onDone(obj) / onError(msg)
 *
 * history:[{ role:'user'|'ai', text }] —— 已有对话,必须带上。
 *
 * ⚠ 为什么必须带:后端实测**每轮都会开一个新的 Coze 会话**(而且它忽略请求里的
 *   conversation_id,所以"复用会话"那条路走不通)。不带历史的后果是机器人
 *   **完全记不住上文**:每轮都从开场白重来、反复问同一个问题(用户看到的就是"重复回答")。
 *   把历史随请求带上后,第二轮能正确复述第一轮说过的内容 —— 已实测。
 *   后端会限长(最近 10 条 / 3000 字),前端传全量即可。
 */
export function summonStream(q, handlers = {}, history = []) {
  const { onMeta, onThinking, onDelta, onDone, onError } = handlers

  let buffer = ''
  // 按 SSE 协议切块分发:块之间以空行分隔
  const feed = (text) => {
    buffer += text
    let idx
    while ((idx = buffer.indexOf('\n\n')) >= 0) {
      const block = buffer.slice(0, idx)
      buffer = buffer.slice(idx + 2)

      let ev = ''
      let data = ''
      block.split('\n').forEach((l) => {
        if (l.indexOf('event:') === 0) ev = l.slice(6).trim()
        else if (l.indexOf('data:') === 0) data += l.slice(5).trim()
      })
      if (!ev || !data) continue

      let d = {}
      try { d = JSON.parse(data) } catch (e) { continue }

      if (ev === 'meta' && onMeta) onMeta(d)
      else if (ev === 'thinking' && onThinking) onThinking(d.text || '')
      else if (ev === 'delta' && onDelta) onDelta(d.text || '')
      else if (ev === 'done' && onDone) onDone(d)
      else if (ev === 'error' && onError) onError(d.message || '出错了')
    }
  }

  const url = BASE_URL + '/api/summon-stream'
  const token = uni.getStorageSync(TOKEN_KEY) || ''

  // #ifdef H5
  return fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ q, history }),
  })
    .then((res) => {
      if (!res.ok || !res.body) throw new Error('HTTP ' + res.status)
      const reader = res.body.getReader()
      const decoder = new TextDecoder('utf-8')
      const pump = () =>
        reader.read().then(({ done, value }) => {
          if (done) return
          feed(decoder.decode(value, { stream: true }))
          return pump()
        })
      return pump()
    })
    .catch((e) => { if (onError) onError((e && e.message) || '网络异常') })
  // #endif

  // #ifndef H5
  return new Promise((resolve) => {
    const task = uni.request({
      url,
      method: 'POST',
      data: { q, history },
      header: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      enableChunked: true,   // 微信小程序(基础库 2.20.2+)/App 支持
      timeout: 120000,
      success: () => resolve(),
      fail: (e) => { if (onError) onError((e && e.errMsg) || '网络异常'); resolve() },
    })

    if (task && typeof task.onChunkReceived === 'function') {
      task.onChunkReceived((res) => {
        try {
          const bytes = new Uint8Array(res.data)
          let s = ''
          for (let i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i])
          // 分块可能在多字节字符中间切断,用这层兜住,失败就跳过这一块
          feed(decodeURIComponent(escape(s)))
        } catch (e) { /* 忽略单块解码失败,下一块会接上 */ }
      })
    } else if (onError) {
      onError('当前平台不支持流式')
    }
  })
  // #endif
}

// ==================== 收货地址 ====================
export const addressApi = {
  getAddresses: () => http.get('/api/customer/addresses', { loading: false }),
  createAddress: (data) => http.post('/api/customer/addresses', data, { loading: false }),
  updateAddress: (id, data) => http.put(`/api/customer/addresses/edit/${id}`, data, { loading: false }),
  // 删除地址(后端 9/7 新增 destroy 接口,仅允许删除本人地址)
  deleteAddress: (id) => http.delete(`/api/customer/addresses/${id}`, { loading: false }),
}

// ==================== 静态内容页(隐私政策 / 用户协议 / 关于) ====================
/**
 * ⚠ 正文**不在前端写死** —— 读的是与网页端 `/page/{slug}` 同一份 CMS 内容。
 *   写死会立刻产生第二个"协议正文出处":律师定稿后改了 CMS,网页端更新了、
 *   App 还显示旧条款,而没有任何东西能发现这件事。
 *
 * 可用 slug 见后端迁移 2026_10_14_000008 的 PAGES 常量。
 * 当前 privacy-policy / terms-of-use 的正文是**诚实的「尚未定稿」说明**,
 * 不是条款(律师未定稿)。前端**不要**为它们补任何"看起来像条款"的文案。
 */
export const cmsApi = {
  page: (slug) => http.get(`/api/cms/pages/${slug}`, { loading: false }),
}