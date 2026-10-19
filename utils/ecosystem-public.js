/**
 * G1 公开活动 / 资讯前端合同（G1-11）。
 *
 * 这个模块是**纯函数**、零依赖：不 import `@/utils/config`、不碰 `uni`，
 * 因此可以直接被 `node --test tools/tests/*.test.mjs` 导入。
 * 需要 BASE_URL 的地方一律由调用方（页面）作为参数传入。
 *
 * 三条不可谈判的约束（对应 docs/ecosystem/G1-08-11-implementation-plan.md §7）：
 *
 * 1. **只消费 G1-10 DTO**：字段名以 `EventPublicResource` /
 *    `ContentPublicResource` 为准。旧 `hot` / `teams` / `date` / `status` /
 *    `detail` 一律不读，缺字段就是缺字段，不拿样例补。
 * 2. **报名状态只认服务端**：只看 `registration.can_register` 与
 *    `registration.allowed_actions`；前端不猜、不造 waitlist、
 *    也不在 `can_register=false` 时提供任何报名入口。
 * 3. **富文本只做受控补全**：正文只接受服务端已净化的 `body_html`，
 *    只把 DTO `media[]` 里的受控相对 URL 补上 BASE_URL；
 *    未知图片整条删除，绝不重新引入远程 URL。
 */

const FORMAT_ERROR_CODE = 'FORMAT_ERROR'

/** 列表/详情响应结构不符合 G1-10 合同。与网络错误、404/410/503 区分。 */
export class EcosystemFormatError extends Error {
  constructor(message) {
    super(message || '数据格式异常')
    this.name = 'EcosystemFormatError'
    this.code = FORMAT_ERROR_CODE
  }
}

function formatError(message) {
  return new EcosystemFormatError(message)
}

function isNonEmptyString(value) {
  return typeof value === 'string' && value.trim() !== ''
}

function positiveInt(value) {
  const number = Number(value)
  return Number.isInteger(number) && number > 0 ? number : null
}

function nullableString(value) {
  return typeof value === 'string' && value.trim() !== '' ? value : null
}

function normalizeMediaItem(raw) {
  if (!raw || typeof raw !== 'object') return null

  const id = positiveInt(raw.id)
  if (!id) return null

  return {
    id,
    role: typeof raw.role === 'string' ? raw.role : '',
    altText: nullableString(raw.alt_text),
    // 只接受 G1-10 生成的受控相对路由（/api/...）。绝对/远程 URL 一律置空。
    url: controlledRelativeUrl(raw.url),
  }
}

function normalizeMediaList(raw) {
  if (!Array.isArray(raw)) return []

  return raw.map(normalizeMediaItem).filter(Boolean)
}

/** 只接受 `/api/` 开头的单斜杠相对路径。 */
function controlledRelativeUrl(value) {
  if (typeof value !== 'string') return ''

  const trimmed = value.trim()

  if (!trimmed.startsWith('/api/') || trimmed.startsWith('//')) return ''
  // 控制字符与反斜杠一律拒绝，避免路径被解释成别的协议/主机。
  if (/[\\\u0000-\u001f]/.test(trimmed)) return ''

  return trimmed
}

/** 外部报名链接（未来的 DTO 字段）。只接受 http(s)，其余置空。 */
function normalizeExternalUrl(value) {
  if (typeof value !== 'string') return ''

  const trimmed = value.trim()

  return /^https?:\/\/[^\s]+$/i.test(trimmed) ? trimmed : ''
}

// ==================== 归一化 ====================

/**
 * G1 活动 DTO → 前端模型。
 *
 * 必需：正整数 `id` + 非空 `name`。缺任一返回 null（列表里出现即视为格式异常）。
 */
export function normalizeEvent(raw) {
  if (!raw || typeof raw !== 'object') return null

  const id = positiveInt(raw.id)
  const name = isNonEmptyString(raw.name) ? raw.name.trim() : ''

  if (!id || !name) return null

  const organizer = raw.organizer && typeof raw.organizer === 'object' ? raw.organizer : {}
  const source = raw.source && typeof raw.source === 'object' ? raw.source : {}
  const registration = raw.registration && typeof raw.registration === 'object' ? raw.registration : {}

  return {
    id,
    kind: typeof raw.kind === 'string' ? raw.kind : '',
    name,
    summary: nullableString(raw.summary),
    organizer: {
      name: typeof organizer.name === 'string' ? organizer.name : '',
      platformRole: typeof organizer.platform_role === 'string' ? organizer.platform_role : '',
    },
    source: {
      name: typeof source.name === 'string' ? source.name : '',
      url: nullableString(source.url),
    },
    participationMode: typeof raw.participation_mode === 'string' ? raw.participation_mode : '',
    city: nullableString(raw.city),
    locationText: nullableString(raw.location_text),
    timezone: nullableString(raw.timezone),
    startsAt: nullableString(raw.starts_at),
    endsAt: nullableString(raw.ends_at),
    publishedAt: nullableString(raw.published_at),
    firstPublishedAt: nullableString(raw.first_published_at),
    revisionNo: Number.isInteger(Number(raw.revision_no)) ? Number(raw.revision_no) : null,
    hostingStatus: typeof raw.hosting_status === 'string' ? raw.hosting_status : '',
    bodyHtml: typeof raw.body_html === 'string' ? raw.body_html : '',
    registration: {
      // 严格 === true：'true' / 1 都不算。
      canRegister: registration.can_register === true,
      registrationReason: typeof registration.registration_reason === 'string'
        ? registration.registration_reason
        : '',
      allowedActions: Array.isArray(registration.allowed_actions)
        ? registration.allowed_actions.filter((action) => typeof action === 'string')
        : [],
    },
    // G1-10 DTO 目前不返回该字段；保留读取位，未来 DTO 增加后才可能显示外部报名按钮。
    externalRegistrationUrl: normalizeExternalUrl(raw.external_registration_url),
    media: normalizeMediaList(raw.media),
  }
}

/**
 * G1 资讯 DTO → 前端模型。
 *
 * 必需：非空 `slug` + 非空 `title`。
 */
export function normalizeContent(raw) {
  if (!raw || typeof raw !== 'object') return null

  const slug = isNonEmptyString(raw.slug) ? raw.slug.trim() : ''
  const title = isNonEmptyString(raw.title) ? raw.title.trim() : ''

  if (!slug || !title) return null

  const author = raw.author && typeof raw.author === 'object' ? raw.author : {}

  return {
    slug,
    type: typeof raw.type === 'string' ? raw.type : '',
    title,
    summary: nullableString(raw.summary),
    author: {
      name: typeof author.name === 'string' ? author.name : '',
      org: nullableString(author.org),
    },
    sources: Array.isArray(raw.sources)
      ? raw.sources.map((source) => {
        const row = source && typeof source === 'object' ? source : {}
        return {
          name: typeof row.name === 'string' ? row.name : '',
          url: nullableString(row.url),
          verifiedAt: nullableString(row.verified_at),
          rightsBasis: typeof row.rights_basis === 'string' ? row.rights_basis : '',
          rightsScope: nullableString(row.rights_scope),
        }
      }).filter((source) => source.name !== '')
      : [],
    publishedAt: nullableString(raw.published_at),
    firstPublishedAt: nullableString(raw.first_published_at),
    revisionNo: Number.isInteger(Number(raw.revision_no)) ? Number(raw.revision_no) : null,
    correctionNote: nullableString(raw.correction_note),
    bodyHtml: typeof raw.body_html === 'string' ? raw.body_html : '',
    media: normalizeMediaList(raw.media),
  }
}

// ==================== 分页 ====================

/**
 * 解析 G1-10 分页外壳。
 *
 * - 域开关关闭时后端返回 `{data: []}`（无 meta）——这是**成功的空列表**，
 *   按单页空结果处理，而不是格式异常。
 * - meta 存在但字段非法 → 抛格式异常（不能静默当成空页）。
 */
export function normalizePage(payload, fallbackPage = 1) {
  if (!payload || typeof payload !== 'object' || !Array.isArray(payload.data)) {
    throw formatError('列表响应格式异常')
  }

  const rows = payload.data
  const meta = payload.meta

  if (meta === undefined || meta === null) {
    return {
      page: positiveInt(fallbackPage) || 1,
      lastPage: positiveInt(fallbackPage) || 1,
      perPage: rows.length,
      total: rows.length,
      hasMore: false,
      rows,
    }
  }

  if (typeof meta !== 'object') {
    throw formatError('列表分页格式异常')
  }

  const page = positiveInt(meta.current_page)
  const lastPage = positiveInt(meta.last_page)
  const perPage = positiveInt(meta.per_page)
  const total = Number(meta.total)

  if (!page || !lastPage || !perPage || !Number.isInteger(total) || total < 0) {
    throw formatError('列表分页格式异常')
  }

  return {
    page,
    lastPage,
    perPage,
    total,
    hasMore: page < lastPage,
    rows,
  }
}

export function normalizeEventPage(payload, fallbackPage = 1) {
  const page = normalizePage(payload, fallbackPage)

  return {
    ...page,
    items: page.rows.map((row) => {
      const event = normalizeEvent(row)
      if (!event) throw formatError('赛事数据格式异常')
      return event
    }),
  }
}

export function normalizeContentPage(payload, fallbackPage = 1) {
  const page = normalizePage(payload, fallbackPage)

  return {
    ...page,
    items: page.rows.map((row) => {
      const content = normalizeContent(row)
      if (!content) throw formatError('资讯数据格式异常')
      return content
    }),
  }
}

/**
 * 追加分页结果并按稳定键去重。
 * 服务端排序键含 id/slug，正常不会重复；这里兜住"翻页期间数据变动"造成的重复。
 */
export function appendUnique(list, items, key = 'id') {
  const current = Array.isArray(list) ? list.slice() : []
  const seen = new Set(current.map((row) => String(row && row[key])))
  const next = Array.isArray(items) ? items : []

  for (const item of next) {
    const value = item && item[key]
    const marker = String(value)
    if (!value || seen.has(marker)) continue
    seen.add(marker)
    current.push(item)
  }

  return current
}

// ==================== 状态 / 日期 ====================

const EVENT_KIND_LABELS = {
  competition: '竞赛',
  salon: '沙龙',
  workshop: '工作坊',
  expo: '展会',
  open_day: '开放日',
}

const HOSTING_STATUS_LABELS = {
  scheduled: '已排期',
  postponed: '已延期',
  rescheduled: '已改期',
  completed: '已结束',
  canceled: '已取消',
}

const CONTENT_TYPE_LABELS = {
  news: '资讯',
  announcement: '公告',
  case: '案例',
  competition_report: '赛事报道',
}

const RIGHTS_BASIS_LABELS = {
  platform_original: '平台原创',
  written_repost_permission: '已获书面转载授权',
  official_link_only: '官方链接',
  public_document_summary: '公开文件摘要',
}

const REGISTRATION_REASON_LABELS = {
  external_only: '该活动由外部主办方举办，请在来源页面查看参与方式。',
  canceled: '活动已取消。',
  ended: '活动已结束。',
  registration_disabled: '站内报名暂未开放。',
}

export function eventKindLabel(kind) {
  return EVENT_KIND_LABELS[kind] || (isNonEmptyString(kind) ? kind : '活动')
}

export function hostingStatusLabel(status) {
  return HOSTING_STATUS_LABELS[status] || ''
}

export function contentTypeLabel(type) {
  return CONTENT_TYPE_LABELS[type] || (isNonEmptyString(type) ? type : '资讯')
}

export function rightsBasisLabel(basis) {
  return RIGHTS_BASIS_LABELS[basis] || (isNonEmptyString(basis) ? basis : '')
}

export function registrationReasonLabel(code) {
  return REGISTRATION_REASON_LABELS[code] || '暂不可报名。'
}

/**
 * 解析服务端返回的 ISO 本地时间（已按 event timezone 带偏移输出）。
 *
 * 前端**不做二次时区换算**：`2026-11-01T09:00:00+08:00` 显示 09:00，
 * 即使活动时区是 America/New_York（服务端会输出 -04:00/-05:00）。
 */
function isoParts(value) {
  if (typeof value !== 'string') return null

  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value.trim())
  if (!match) return null

  const parts = {
    year: Number(match[1]),
    month: Number(match[2]),
    day: Number(match[3]),
    hour: Number(match[4]),
    minute: Number(match[5]),
  }

  if (parts.month < 1 || parts.month > 12) return null
  if (parts.day < 1 || parts.day > 31) return null
  if (parts.hour > 23 || parts.minute > 59) return null

  return parts
}

function pad2(value) {
  return String(value).padStart(2, '0')
}

/** `2026年11月1日 09:00`；无法解析返回 ''。 */
export function formatDateTime(value) {
  const parts = isoParts(value)
  if (!parts) return ''

  return `${parts.year}年${parts.month}月${parts.day}日 ${pad2(parts.hour)}:${pad2(parts.minute)}`
}

/** `11月1日 09:00`（列表用）；无法解析返回 ''。 */
export function formatDateShort(value) {
  const parts = isoParts(value)
  if (!parts) return ''

  return `${parts.month}月${parts.day}日 ${pad2(parts.hour)}:${pad2(parts.minute)}`
}

/** `2026-11-01`；无法解析返回 ''。 */
export function formatDateISO(value) {
  const parts = isoParts(value)
  if (!parts) return ''

  return `${parts.year}-${pad2(parts.month)}-${pad2(parts.day)}`
}

export function timezoneLabel(timezone) {
  if (!isNonEmptyString(timezone)) return ''
  if (timezone === 'Asia/Shanghai') return '北京时间'
  if (timezone === 'Asia/Tokyo') return '日本时间'
  if (timezone === 'Europe/London') return '伦敦时间'
  if (timezone === 'America/New_York') return '纽约时间'
  if (timezone === 'UTC') return 'UTC'
  return timezone
}

/** 活动时间行：同一天合并上下午，否则显示起止。 */
export function formatEventWhen(event) {
  if (!event) return ''

  const start = formatDateTime(event.startsAt)
  if (!start) return '时间待定'

  const end = formatDateTime(event.endsAt)
  if (!end) return start

  const startParts = isoParts(event.startsAt)
  const endParts = isoParts(event.endsAt)
  const sameDay = startParts && endParts
    && startParts.year === endParts.year
    && startParts.month === endParts.month
    && startParts.day === endParts.day

  return sameDay ? `${start}–${pad2(endParts.hour)}:${pad2(endParts.minute)}` : `${start} – ${end}`
}

function parseTimestamp(value) {
  if (typeof value !== 'string' || value.trim() === '') return null
  const time = Date.parse(value)
  return Number.isFinite(time) ? time : null
}

/**
 * 活动状态：取消 / 结束 / 进行中 / 即将开始 / 待定。
 *
 * 优先级：canceled > completed > postponed/rescheduled（时间待定）> 起止时间。
 * 状态只用于展示，**不参与报名判断**。
 */
export function eventStatus(event, now = Date.now()) {
  if (!event) return { code: 'pending', label: '时间待定' }

  const hosting = event.hostingStatus

  if (hosting === 'canceled') return { code: 'canceled', label: '已取消' }
  if (hosting === 'completed') return { code: 'ended', label: '已结束' }
  if (hosting === 'postponed' || hosting === 'rescheduled') {
    return { code: 'pending', label: '时间待定' }
  }

  const start = parseTimestamp(event.startsAt)
  const end = parseTimestamp(event.endsAt)
  const current = typeof now === 'number' ? now : Date.parse(now)

  if (end !== null && Number.isFinite(current) && current > end) {
    return { code: 'ended', label: '已结束' }
  }

  if (start !== null && Number.isFinite(current) && current >= start) {
    return { code: 'ongoing', label: '进行中' }
  }

  if (start !== null) return { code: 'upcoming', label: '即将开始' }

  return { code: 'pending', label: '时间待定' }
}

// ==================== 错误映射 ====================

/**
 * 把请求/解析错误映射成页面四态里的 error 态。
 *
 * 404 / 410 / 503 有各自独立文案，**不会被伪装成空列表**。
 */
export function errorState(error) {
  if (!error) {
    return {
      kind: 'unknown',
      icon: "📡",
      title: '加载失败',
      message: '数据没能加载，请稍后重试。',
      retryable: true,
      status: 0,
    }
  }

  const status = positiveInt(error.statusCode || error.status) || 0
  const message = typeof error.message === 'string' ? error.message : ''

  if (error.code === FORMAT_ERROR_CODE || /格式异常/.test(message)) {
    return {
      kind: 'format',
      icon: "🧩",
      title: '内容格式异常',
      message: '服务返回的数据无法识别，请稍后重试。',
      retryable: true,
      status,
    }
  }

  if (status === 404) {
    return {
      kind: 'not_found',
      icon: "🔍",
      title: '内容不存在或未公开',
      message: '该内容不存在，或尚未公开发布。',
      retryable: false,
      status,
    }
  }

  if (status === 410) {
    return {
      kind: 'gone',
      icon: "🗑️",
      title: '内容已撤稿',
      message: '该内容已撤稿，不再提供。',
      retryable: false,
      status,
    }
  }

  if (status === 503) {
    return {
      kind: 'unavailable',
      icon: "🛠️",
      title: '服务暂不可用',
      message: '公开内容暂时不可用，请稍后重试。',
      retryable: true,
      status,
    }
  }

  if (status >= 500) {
    return {
      kind: 'server',
      icon: "⚠️",
      title: '服务异常',
      message: message || '服务暂时异常，请稍后重试。',
      retryable: true,
      status,
    }
  }

  if (status >= 400) {
    return {
      kind: 'request',
      icon: "⛔",
      title: '请求未能完成',
      message: message || '请求未能完成，请稍后重试。',
      // 404/410/503 已在上面单独处理；其余 4xx（如 422 参数非法）重试通常无意义。
      retryable: false,
      status,
    }
  }

  if (error.isTimeout === true || /超时/.test(message)) {
    return {
      kind: 'timeout',
      icon: "⏱️",
      title: '请求超时',
      message: '请求超时，请稍后重试。',
      retryable: true,
      status,
    }
  }

  return {
    kind: 'network',
      icon: "📡",
    title: '网络异常',
    message: '网络连接失败，请检查网络后重试。',
    retryable: true,
    status,
  }
}

// ==================== 媒体 / 富文本 ====================

/**
 * 媒体/正文里的受控相对 URL 补 BASE_URL。
 *
 * 只接受 `/api/` 开头的单斜杠相对路径；绝对 URL、协议相对 URL、远程 URL 一律返回 ''。
 */
export function resolveMediaUrl(url, baseUrl) {
  const relative = controlledRelativeUrl(url)
  if (!relative) return ''

  const base = typeof baseUrl === 'string' ? baseUrl.trim().replace(/\/+$/, '') : ''

  return base + relative
}

/** 列表/详情封面：优先 role=cover，其次第一张可用媒体。 */
export function pickCover(media) {
  if (!Array.isArray(media) || media.length === 0) return null

  const cover = media.find((item) => item && item.role === 'cover' && item.url)
  if (cover) return cover

  return media.find((item) => item && item.url) || null
}

function escapeAttr(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function parseAttributes(tag) {
  const attributes = {}
  const pattern = /([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+))/g
  let match

  while ((match = pattern.exec(tag)) !== null) {
    attributes[match[1].toLowerCase()] = match[2] !== undefined
      ? match[2]
      : (match[3] !== undefined ? match[3] : match[4])
  }

  return attributes
}

function safeDimension(value) {
  return /^\d{1,5}(px|%)?$/.test(String(value || '')) ? String(value) : ''
}

function removeTagBlocks(html, tag) {
  const paired = new RegExp(`<${tag}\\b[^>]*>[\\s\\S]*?<\\/${tag}>`, 'gi')
  const selfClosing = new RegExp(`<${tag}\\b[^>]*\\/?>`, 'gi')

  return html.replace(paired, '').replace(selfClosing, '')
}

/**
 * 富文本渲染（返回可交给 `<rich-text :nodes>` 的 HTML 字符串）。
 *
 * - 输入只来自服务端已净化的 `body_html`；本函数不接收任何其他来源的 HTML。
 * - `<img>` 只保留 DTO `media[]` 中已批准媒体：按 `data-media-id` 精确匹配，
 *   或 `src` 与 DTO 受控相对 URL 完全一致；其余 `<img>` 整条删除。
 * - 输出的 `src` 只能是 `BASE_URL + /api/...`，不会把原 HTML 里的远程地址带出来。
 * - 额外兜底移除 script/style/iframe/object/embed/form 与事件属性、危险协议链接。
 */
export function renderRichHtml(html, media, baseUrl) {
  if (typeof html !== 'string' || html.trim() === '') return ''

  const byId = new Map()
  const byUrl = new Map()

  for (const item of Array.isArray(media) ? media : []) {
    const relative = controlledRelativeUrl(item && item.url)
    if (!relative) continue

    const absolute = resolveMediaUrl(relative, baseUrl)
    if (!absolute) continue

    const id = positiveInt(item.id)
    if (id) byId.set(String(id), absolute)
    byUrl.set(relative, absolute)
  }

  let output = html

  for (const tag of ['script', 'style', 'iframe', 'object', 'embed', 'form', 'video', 'audio', 'source']) {
    output = removeTagBlocks(output, tag)
  }

  output = output.replace(/<img\b[^>]*\/?>/gi, (tag) => {
    const attributes = parseAttributes(tag)
    const mediaId = String(attributes['data-media-id'] || '').trim()
    const source = String(attributes.src || '').trim()

    const absolute = (mediaId && byId.get(mediaId)) || byUrl.get(source) || ''
    if (!absolute) return ''

    let image = `<img src="${escapeAttr(absolute)}" style="max-width:100%;height:auto"`

    if (/^[1-9]\d*$/.test(mediaId)) image += ` data-media-id="${mediaId}"`
    if (attributes.alt) image += ` alt="${escapeAttr(attributes.alt)}"`
    if (attributes.title) image += ` title="${escapeAttr(attributes.title)}"`

    for (const dimension of ['width', 'height']) {
      const value = safeDimension(attributes[dimension])
      if (value) image += ` ${dimension}="${value}"`
    }

    return `${image}>`
  })

  // 兜底：事件属性与危险协议（净化器已处理，这里防旧数据/异常输入）。
  output = output
    .replace(/\son[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi, '')
    .replace(/\s(href|src)\s*=\s*(?:"\s*(?:javascript|vbscript|data):[^"]*"|'\s*(?:javascript|vbscript|data):[^']*')/gi, '')

  return output.trim()
}

// ==================== 报名展示 ====================

/**
 * 报名区只读展示。
 *
 * - `can_register=true` → register（G1 后端恒 false；G2 接入后才有真实动作）。
 * - 否则只有同时满足「allowed_actions 含 open_external_registration」且 DTO
 *   带有可用外部链接时，才显示外部报名按钮。
 * - 其余一律展示稳定原因文案。**不存在 waitlist / 假报名入口**。
 */
export function registrationView(event) {
  const registration = event && event.registration ? event.registration : {}
  const actions = Array.isArray(registration.allowedActions) ? registration.allowedActions : []

  if (registration.canRegister === true) {
    return { mode: 'register', url: '', reason: '', actions }
  }

  const externalUrl = normalizeExternalUrl(event && event.externalRegistrationUrl)

  if (actions.includes('open_external_registration') && externalUrl) {
    return { mode: 'external', url: externalUrl, reason: '', actions }
  }

  return {
    mode: 'none',
    url: '',
    reason: registrationReasonLabel(registration.registrationReason),
    actions,
  }
}

// ==================== 分享 ====================

/** 详情页真实路径（小程序分享 path / H5 hash 路由共用）。 */
export function buildSharePath(kind, key) {
  if (kind === 'event') {
    const id = positiveInt(key)
    return id ? `/pages/ecosystem/event-detail?id=${id}` : ''
  }

  if (kind === 'content') {
    const slug = isNonEmptyString(key) ? key.trim() : ''
    return slug ? `/pages/ecosystem/content-detail?slug=${encodeURIComponent(slug)}` : ''
  }

  return ''
}

/**
 * H5 分享基址：**当前站点**的 origin + pathname，而不是后端 API 的 BASE_URL。
 *
 * 详情页过去把 API 基址传给 `buildShareUrl`，生成的是
 * `http://127.0.0.1:8000/#/pages/...` —— 后端地址不是用户能打开的页面地址。
 * 这里只接受 H5 的 `window.location` 形状：
 *   · 协议必须是 http/https；
 *   · 去掉 `index.html`（部署在子目录时常见 `/h5/index.html`）；
 *   · 去掉尾斜杠；根路径返回纯 origin。
 *
 * 非 H5（小程序/App）没有可复制的网页地址，调用方应传入 `null` / `''`，
 * 本函数返回空串；小程序分享仍走 `buildSharePath`。
 */
export function resolveShareBase(location) {
  if (!location || typeof location !== 'object') return ''

  const origin = typeof location.origin === 'string' ? location.origin.trim() : ''

  if (!/^https?:\/\/[^/?#\s]+$/i.test(origin)) return ''

  let pathname = typeof location.pathname === 'string' ? location.pathname : '/'

  // 去掉部署入口文件；大小写与 `.htm` 都兼容。
  pathname = pathname.replace(/\/index\.html?$/i, '').replace(/\/+$/, '')

  if (pathname === '' || pathname === '/') return origin

  // pathname 必须以单个 `/` 开头；拒绝 `//host` 这类协议相对形态。
  if (!pathname.startsWith('/') || pathname.startsWith('//')) return ''

  return origin + pathname
}

/**
 * H5 可直达 URL：`<站点基址>/#/pages/...`。
 *
 * 基址为空（非 H5、或站点地址不可判定）时返回空串 —— 绝不退化成 `/#/...`
 * 这种无法定位站点的伪链接。
 */
export function buildShareUrl(path, baseUrl) {
  if (!isNonEmptyString(path)) return ''

  const base = typeof baseUrl === 'string' ? baseUrl.trim().replace(/\/+$/, '') : ''

  if (!base) return ''

  return `${base}/#${path}`
}

/**
 * 复制分享链接。
 *
 * `write` 由页面注入（H5/小程序都用 `uni.setClipboardData` 包一层）。
 * **只有写入成功才返回 ok:true** —— 失败不能提示成功。
 */
export function copyShareLink(path, baseUrl, write) {
  const url = buildShareUrl(path, baseUrl)

  if (!url) {
    return Promise.resolve({ ok: false, message: '分享链接不可用' })
  }

  if (typeof write !== 'function') {
    return Promise.resolve({ ok: false, message: '当前环境不支持复制' })
  }

  return Promise.resolve()
    .then(() => write(url))
    .then(() => ({ ok: true, message: '链接已复制' }))
    .catch(() => ({ ok: false, message: '复制失败，请手动复制链接' }))
}
