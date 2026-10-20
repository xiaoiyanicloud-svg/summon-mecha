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
  // 试用(B-3 · D4):发放 20 分钟在线授权并**直接下发**到该设备。
  // 仅声明了 agent 通道的技能可试用(人工通道 20 分钟后收不回来,后端会拒)。
  trial: (productId, deviceId) => http.post(`/api/skills/${productId}/trial`, { device_id: deviceId }, { loading: true, loadingText: '开通试用中...' }),
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

// ==================== 技能举报(B-7 · 加固计划 §四 A1) ====================
// 技能一旦下发、在真实机器上跑起来,用户能按的最后一个刹车就是这个入口。
// 后端三个刻意口径(见 SkillReportController):不要求持有该技能(举报对象恰恰
// 不该是我买的东西)、不要求写 detail、命中违禁词也照收(引述违规内容是举报的
// 必要部分,拦了等于关掉通道 → 改为留痕 + 人工判)。
export const reportApi = {
  // data: { target_type: 'skill'|'review', target_id, reason_category, detail? }
  // 201 = 受理；200 + duplicate:true = 该对象已有未处置举报,本次未新建
  create: (data) => http.post('/api/skill-reports', data, { loading: true, loadingText: '提交中...' }),
}

// ==================== 技能类目/标签(v3.4 步骤 7) ====================
export const skillCatalogApi = {
  categories: () => http.get('/api/skill-catalog/categories', { loading: false }),
  // type 可选:motion_type / scene / difficulty
  tags: (type) => http.get('/api/skill-catalog/tags', { data: type ? { type } : {}, loading: false }),
  // B-9 · 筛选面板候选项(机型 / 交付通道 / 风险等级)
  filters: () => http.get('/api/skill-catalog/filters', { loading: false }),
  // B-16 · 技能发布历史(版本/发布通道/交付通道/变更日志)—— 公开只读
  versions: (productId) => http.get(`/api/skill-catalog/versions/${productId}`, { loading: false }),
  // B-9 · 技能搜索:名称 q + 四个筛选维度(机型/通道/动作类型/风险等级),空值不发送
  search: (q, filters = {}, pagination = {}) => {
    const data = {}
    if (q) data.q = q
    ;['model', 'channel', 'motion', 'risk'].forEach((k) => {
      if (filters[k]) data[k] = filters[k]
    })
    if (pagination.page) data.page = pagination.page
    if (pagination.limit) data.limit = pagination.limit
    return http.get('/api/skill-catalog/search', { data, loading: false })
  },
}

// ==================== 风险告知与留痕(B-5 · 计划 §4.14 / D6) ====================
// 分档文案按 manifest.safety.risk_level(low/medium/high)。high 风险必须逐条勾选才可提交，
// 落 risk_acknowledgment 留痕(含勾选明细 + 当时文案指纹 + IP)。
export const riskApi = {
  // 取该技能的风险告知档案(游客可读 —— 买之前就该看到)
  profile: (productId) => http.get(`/api/risk/${productId}`, { loading: false }),
  // data: { product_id(必填), skill_version_id?, entitlement_id?, channel?, checked?:string[] }
  acknowledge: (data) => http.post('/api/risk/acknowledge', data, { loading: true, loadingText: '提交中...' }),
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

// ==================== 赛事 / 资讯(G1 公开只读合同) ====================
// G1-10 公开 DTO：列表只返回 published + normal，详情 404/410/503 有独立语义。
// 报名写入口不在 G1 前端开放 —— 报名状态只读 DTO 的 registration.* 展示。
export const eventApi = {
  // params: { page, per_page, kind, participation_mode, city, hosting_status, starts_from, starts_to }
  getEvents: (params = {}) => http.get('/api/events', { data: params, loading: false }),
  // 独立详情必须按 ID 重新取，不能只依赖列表内存对象
  getEvent: (id) => http.get(`/api/events/${id}`, { loading: false }),
  // G2 报名：必须登录；request_key 由调用方生成并在重试时沿用。
  register: (id, data) => http.post(`/api/events/${id}/register`, data, { loading: true, loadingText: '提交中...' }),
}

// ==================== G3 人工赛务（队伍 / 作品 / 赛程） ====================
export const competitionApi = {
  // 活动公开赛务信息：active 赛项 + 我的队伍 + 已发布赛程
  getByEvent: (eventId) => http.get(`/api/events/${eventId}/competition`, { loading: false }),
  getSchedule: (eventId) => http.get(`/api/events/${eventId}/competition/schedule`, { loading: false }),
  // G3-08 / G3-11：公开成绩/奖项与公开展示（独立开关，关闭时 404）
  getResults: (eventId) => http.get(`/api/events/${eventId}/competition/results`, { loading: false }),
  getShowcases: (eventId) => http.get(`/api/events/${eventId}/competition/showcases`, { loading: false }),
}

export const competitionTeamApi = {
  mine: () => http.get('/api/customer/competition/teams', { loading: false }),
  get: (id) => http.get(`/api/customer/competition/teams/${id}`, { loading: false }),
  create: (data) => http.post('/api/customer/competition/teams', data, { loading: true, loadingText: '创建中...' }),
  invite: (id, data) => http.post(`/api/customer/competition/teams/${id}/invites`, data, { loading: true, loadingText: '生成邀请码...' }),
  join: (data) => http.post('/api/customer/competition/teams/join', data, { loading: true, loadingText: '加入中...' }),
  decline: (data) => http.post('/api/customer/competition/teams/decline', data, { loading: true, loadingText: '处理中...' }),
  leave: (id, data) => http.post(`/api/customer/competition/teams/${id}/leave`, data, { loading: true, loadingText: '退出中...' }),
  transfer: (id, data) => http.post(`/api/customer/competition/teams/${id}/transfer`, data, { loading: true, loadingText: '移交中...' }),
  register: (id, data) => http.post(`/api/customer/competition/teams/${id}/register`, data, { loading: true, loadingText: '提交报名...' }),
}

export const competitionSubmissionApi = {
  workspace: (teamId) => http.get(`/api/customer/competition/teams/${teamId}/submission`, { loading: false }),
  saveDraft: (teamId, data) => http.post(`/api/customer/competition/teams/${teamId}/submission/draft`, data, { loading: true, loadingText: '保存中...' }),
  submit: (teamId, data = {}) => http.post(`/api/customer/competition/teams/${teamId}/submission/submit`, data, { loading: true, loadingText: '提交中...' }),
  uploadAttachment: (teamId, filePath, versionId) => http.upload(
    `/api/customer/competition/teams/${teamId}/submission/attachments`,
    filePath,
    { version_id: versionId },
    { loading: true, loadingText: '上传中...' },
  ),
}

// G3-09 申诉：本人；规则限定队长。
export const competitionAppealApi = {
  mine: () => http.get('/api/customer/competition/appeals', { loading: false }),
  get: (id) => http.get(`/api/customer/competition/appeals/${id}`, { loading: false }),
  create: (data) => http.post('/api/customer/competition/appeals', data, { loading: true, loadingText: '提交申诉...' }),
  withdraw: (id, data) => http.post(`/api/customer/competition/appeals/${id}/withdraw`, data, { loading: true, loadingText: '撤回中...' }),
  uploadEvidence: (id, filePath) => http.upload(
    `/api/customer/competition/appeals/${id}/attachments`,
    filePath,
    {},
    { loading: true, loadingText: '上传证据...' },
  ),
}

// G3-11 作品展示：队长授权与技能提审交接。
export const competitionShowcaseApi = {
  mine: () => http.get('/api/customer/competition/showcases', { loading: false }),
  consent: (id, data) => http.post(`/api/customer/competition/showcases/${id}/consent`, data, { loading: true, loadingText: '提交授权...' }),
  requestSkillReview: (id, data) => http.post(`/api/customer/competition/showcases/${id}/skill-review`, data, { loading: true, loadingText: '提交提审...' }),
}

// ==================== 本人报名(G2) ====================
export const eventRegistrationApi = {
  // params: { page, per_page }
  mine: (params = {}) => http.get('/api/customer/event-registrations', { data: params, loading: false }),
  get: (publicNo) => http.get(`/api/customer/event-registrations/${encodeURIComponent(publicNo)}`, { loading: false }),
  cancel: (publicNo, data) => http.post(`/api/customer/event-registrations/${encodeURIComponent(publicNo)}/cancel`, data, { loading: true, loadingText: '取消中...' }),
  reapply: (publicNo, data) => http.post(`/api/customer/event-registrations/${encodeURIComponent(publicNo)}/reapply`, data, { loading: true, loadingText: '重报中...' }),
  credential: (publicNo) => http.post(`/api/customer/event-registrations/${encodeURIComponent(publicNo)}/credential`, {}, { loading: true, loadingText: '签发中...' }),
  changes: (publicNo) => http.get(`/api/customer/event-registrations/${encodeURIComponent(publicNo)}/changes`, { loading: false }),
  ackChange: (publicNo, changeId, data) => http.post(`/api/customer/event-registrations/${encodeURIComponent(publicNo)}/changes/${changeId}/ack`, data, { loading: true, loadingText: '确认中...' }),
  // 超时查回：action=submit|reapply|cancel
  requestStatus: (requestKey, action = 'submit') => http.get(`/api/customer/registration-requests/${encodeURIComponent(requestKey)}`, { data: { action }, loading: false }),
}

export const contentApi = {
  // params: { mode, cursor, per_page, q, type, category, published_from, published_to }
  getPosts: (params = {}) => http.get('/api/content/posts', { data: params, loading: false }),
  getPost: (slug) => http.get(`/api/content/posts/${encodeURIComponent(slug)}`, { loading: false }),
}

// ==================== G4-02 我的收藏 ====================
export const favoriteApi = {
  // type: 'event' | 'content'；返回只含当前仍公开的目标。
  list: (type, { cursor = '', perPage = 20 } = {}) => http.get('/api/customer/favorites', {
    data: { type, per_page: perPage, ...(cursor ? { cursor } : {}) },
    loading: false,
  }),
  // 后端 key 校验为字符串（活动 ID 是数字，必须显式转换，否则 H5 收藏会 422）。
  add: (type, key) => http.post('/api/customer/favorites', { type, key: String(key) }, {
    loading: true,
    loadingText: '收藏中...',
  }),
  remove: (type, key) => http.delete(
    `/api/customer/favorites/${encodeURIComponent(type)}/${encodeURIComponent(key)}`,
    {},
    { loading: true, loadingText: '取消中...' },
  ),
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

// 小召跨设备会话：登录后把本机 active + tombstone 一起同步，服务端按客户端更新时间合并。
export const aiConversationApi = {
  sync: (conversations) => http.post(
    '/api/ai/conversations/sync',
    { conversations },
    { loading: false, timeout: 30000 },
  ),
}

// 小召正式埋点：只提交事件元数据与受限属性，不提交提示词或回答正文。
export const aiAnalyticsApi = {
  ingest: (events) => http.post(
    '/api/ai/events',
    { events },
    { loading: false, timeout: 10000 },
  ),
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
  const { onMeta, onThinking, onDelta, onDone, onError, onRequestReady, onClose } = handlers

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
  const controller = typeof AbortController !== 'undefined' ? new AbortController() : null
  if (onRequestReady) {
    onRequestReady(() => {
      if (controller) controller.abort()
    })
  }

  return fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: JSON.stringify({ q, history }),
    ...(controller ? { signal: controller.signal } : {}),
  })
    .then((res) => {
      if (!res.ok || !res.body) throw new Error('HTTP ' + res.status)
      const reader = res.body.getReader()
      const decoder = new TextDecoder('utf-8')
      const pump = () =>
        reader.read().then(({ done, value }) => {
          if (done) {
            if (onClose) onClose()
            return
          }
          feed(decoder.decode(value, { stream: true }))
          return pump()
        })
      return pump()
    })
    .catch((e) => {
      // 用户主动停止时不把 AbortError 当成业务失败弹给用户。
      if (e && e.name === 'AbortError') return
      if (onError) onError((e && e.message) || '网络异常')
    })
  // #endif

  // #ifndef H5
  return new Promise((resolve) => {
    let cancelled = false
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
      success: () => {
        if (!cancelled && onClose) onClose()
        resolve()
      },
      fail: (e) => {
        if (!cancelled && onError) onError((e && e.errMsg) || '网络异常')
        resolve()
      },
    })

    if (onRequestReady) {
      onRequestReady(() => {
        cancelled = true
        try { if (task && typeof task.abort === 'function') task.abort() } catch (e) { /* 静默 */ }
      })
    }

    if (task && typeof task.onChunkReceived === 'function') {
      task.onChunkReceived((res) => {
        if (cancelled) return
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

// ==================== 发票(开票登记,B-15 · 计划 §4.18) ====================
// 用户侧唯一的开票入口。Admin 侧只有只读台账,没有用户填单的地方。
// ⚠ 当前只到"登记":支付成功时后端已自动落一条(抬头=订单姓名、税号空),
//   这里做的是补全抬头类型/抬头/税号。真实开具(税控/PDF)未接入 —— 别在页面上假装能下载。
export const invoiceApi = {
  list: () => http.get('/api/invoices', { loading: false }),
  // data: { order_id(必填), type: 'personal'|'company', title, tax_no?(企业必填) }
  apply: (data) => http.post('/api/invoices', data, { loading: true, loadingText: '提交中...' }),
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
