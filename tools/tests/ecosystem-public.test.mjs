import test from 'node:test'
import assert from 'node:assert/strict'
import {
  normalizeEvent,
  normalizeContent,
  normalizePage,
  normalizeEventPage,
  normalizeContentPage,
  appendUnique,
  eventStatus,
  formatDateTime,
  formatEventWhen,
  errorState,
  renderRichHtml,
  resolveMediaUrl,
  pickCover,
  registrationView,
  buildSharePath,
  buildShareUrl,
  resolveShareBase,
  copyShareLink,
} from '../../utils/ecosystem-public.js'

const BASE = 'http://127.0.0.1:8000'

const eventDto = (overrides = {}) => ({
  id: 12,
  kind: 'competition',
  name: '公开活动名称',
  summary: '公开活动摘要',
  organizer: { name: '召唤机甲', platform_role: 'organizer' },
  source: { name: '召唤机甲官方', url: 'https://example.com/events/1' },
  participation_mode: 'offline',
  city: '上海',
  location_text: '张江科学会堂',
  timezone: 'Asia/Shanghai',
  starts_at: '2026-11-01T09:00:00+08:00',
  ends_at: '2026-11-01T18:00:00+08:00',
  published_at: '2026-10-19T12:00:00+08:00',
  first_published_at: '2026-10-19T12:00:00+08:00',
  revision_no: 1,
  hosting_status: 'scheduled',
  body_html: '<p>公开正文</p>',
  registration: {
    can_register: false,
    registration_reason: 'registration_disabled',
    allowed_actions: ['view_detail'],
  },
  media: [],
  ...overrides,
})

const contentDto = (overrides = {}) => ({
  slug: 'robot-expo-2026',
  type: 'news',
  title: '公开文章标题',
  summary: '公开文章摘要',
  author: { name: '张三', org: '召唤机甲编辑部' },
  sources: [{
    name: '官方原文',
    url: 'https://example.com/news/1',
    verified_at: '2026-10-18T12:00:00+08:00',
    rights_basis: 'platform_original',
    rights_scope: '全文',
  }],
  published_at: '2026-10-19T12:00:00+08:00',
  first_published_at: '2026-10-19T12:00:00+08:00',
  revision_no: 1,
  correction_note: null,
  body_html: '<p>公开文章正文</p>',
  media: [],
  ...overrides,
})

// ==================== 归一化 ====================

test('G1 event DTO normalizes to frontend model without legacy fields', () => {
  const event = normalizeEvent(eventDto())

  assert.equal(event.id, 12)
  assert.equal(event.name, '公开活动名称')
  assert.equal(event.organizer.name, '召唤机甲')
  assert.equal(event.organizer.platformRole, 'organizer')
  assert.equal(event.startsAt, '2026-11-01T09:00:00+08:00')
  assert.equal(event.hostingStatus, 'scheduled')
  assert.deepEqual(event.registration.allowedActions, ['view_detail'])
  assert.equal(event.registration.canRegister, false)

  // 旧合同字段不进入公开页模型
  for (const legacy of ['hot', 'teams', 'date', 'status', 'detail', 'can_register']) {
    assert.equal(Object.prototype.hasOwnProperty.call(event, legacy), false)
  }
})

test('event normalization requires a stable id and non-empty name', () => {
  assert.equal(normalizeEvent(null), null)
  assert.equal(normalizeEvent(eventDto({ id: 0 })), null)
  assert.equal(normalizeEvent(eventDto({ id: '12x' })), null)
  assert.equal(normalizeEvent(eventDto({ name: '   ' })), null)
})

test('content normalization requires slug and title and keeps public sources only', () => {
  const post = normalizeContent(contentDto())

  assert.equal(post.slug, 'robot-expo-2026')
  assert.equal(post.title, '公开文章标题')
  assert.equal(post.sources.length, 1)
  assert.equal(post.sources[0].rightsBasis, 'platform_original')
  assert.equal(Object.prototype.hasOwnProperty.call(post.sources[0], 'evidenceRef'), false)

  assert.equal(normalizeContent(contentDto({ slug: '' })), null)
  assert.equal(normalizeContent(contentDto({ title: ' ' })), null)
})

test('malformed list payloads are format errors, empty list stays empty', () => {
  const empty = normalizeEventPage({ data: [] })
  assert.deepEqual(empty.items, [])
  assert.equal(empty.hasMore, false)

  assert.throws(() => normalizeEventPage({ data: null }), /格式异常/)
  assert.throws(() => normalizeEventPage({ data: [{ name: '没有稳定 ID' }] }), /格式异常/)
  assert.throws(() => normalizeContentPage({ data: [{ title: '没有 slug' }] }), /格式异常/)
})

// ==================== 分页 ====================

test('pagination meta is strict; domain-disabled empty response has no meta', () => {
  const empty = normalizePage({ data: [] }, 1)
  assert.deepEqual(
    { page: empty.page, lastPage: empty.lastPage, hasMore: empty.hasMore, total: empty.total },
    { page: 1, lastPage: 1, hasMore: false, total: 0 },
  )

  const page = normalizePage({
    data: [{ id: 1 }],
    meta: { current_page: 1, last_page: 2, per_page: 10, total: 11 },
  })
  assert.equal(page.page, 1)
  assert.equal(page.lastPage, 2)
  assert.equal(page.hasMore, true)

  assert.throws(() => normalizePage({ data: [], meta: {} }), /分页格式异常/)
  assert.throws(() => normalizePage({ data: [], meta: 'nope' }), /分页格式异常/)
  assert.throws(() => normalizePage({ data: [] , meta: { current_page: 0, last_page: 1, per_page: 10, total: 0 } }), /分页格式异常/)
})

test('appendUnique deduplicates across pages and keeps order', () => {
  const first = [{ id: 1 }, { id: 2 }]
  const merged = appendUnique(first, [{ id: 2 }, { id: 3 }], 'id')

  assert.deepEqual(merged.map((row) => row.id), [1, 2, 3])
  assert.deepEqual(appendUnique([], [{ slug: 'a' }, { slug: 'a' }], 'slug').length, 1)
})

// ==================== 状态 / 日期 ====================

test('date formatting uses the server-returned event timezone offset, not browser timezone', () => {
  assert.equal(formatDateTime('2026-11-01T09:00:00+08:00'), '2026年11月1日 09:00')
  assert.equal(formatDateTime('2026-11-01T09:00:00-04:00'), '2026年11月1日 09:00')
  assert.equal(formatDateTime('not-a-date'), '')
  assert.equal(
    formatEventWhen({
      startsAt: '2026-11-01T09:00:00+08:00',
      endsAt: '2026-11-01T18:00:00+08:00',
    }),
    '2026年11月1日 09:00–18:00',
  )
  assert.equal(formatEventWhen({ startsAt: null, endsAt: null }), '时间待定')
})

test('event status covers canceled / ended / ongoing / upcoming / pending', () => {
  const upcoming = { hostingStatus: 'scheduled', startsAt: '2026-11-01T09:00:00+08:00', endsAt: '2026-11-01T18:00:00+08:00' }
  const now = Date.parse('2026-11-01T12:00:00+08:00')

  assert.deepEqual(eventStatus(upcoming, Date.parse('2026-10-01T00:00:00+08:00')), { code: 'upcoming', label: '即将开始' })
  assert.deepEqual(eventStatus(upcoming, now), { code: 'ongoing', label: '进行中' })
  assert.deepEqual(eventStatus(upcoming, Date.parse('2026-11-02T00:00:00+08:00')), { code: 'ended', label: '已结束' })
  assert.deepEqual(eventStatus({ ...upcoming, hostingStatus: 'canceled' }, now), { code: 'canceled', label: '已取消' })
  assert.deepEqual(eventStatus({ ...upcoming, hostingStatus: 'completed' }, now), { code: 'ended', label: '已结束' })
  assert.deepEqual(eventStatus({ ...upcoming, hostingStatus: 'postponed' }, now), { code: 'pending', label: '时间待定' })
  assert.deepEqual(eventStatus({ hostingStatus: 'scheduled', startsAt: null, endsAt: null }, now), { code: 'pending', label: '时间待定' })
})

// ==================== 错误映射 ====================

test('404 / 410 / 503 are distinct error states and never become empty state', () => {
  assert.equal(errorState({ statusCode: 404 }).kind, 'not_found')
  assert.equal(errorState({ statusCode: 404 }).retryable, false)
  assert.equal(errorState({ statusCode: 410 }).kind, 'gone')
  assert.equal(errorState({ statusCode: 410 }).retryable, false)
  assert.equal(errorState({ statusCode: 503 }).kind, 'unavailable')
  assert.equal(errorState({ statusCode: 503 }).retryable, true)
  assert.match(errorState({ statusCode: 503 }).message, /暂时不可用/)
})

test('network / timeout / format / server errors map to retryable states', () => {
  assert.equal(errorState({ message: '网络异常，请确认后端服务已启动' }).kind, 'network')
  assert.equal(errorState({ isTimeout: true, message: '请求超时，请稍后重试' }).kind, 'timeout')
  assert.equal(errorState(new Error('赛事数据格式异常')).kind, 'format')
  assert.equal(errorState({ statusCode: 500, message: 'boom' }).kind, 'server')
  assert.equal(errorState({ statusCode: 500 }).retryable, true)
  assert.equal(errorState({ statusCode: 422, message: '筛选参数不合法。' }).retryable, false)
})

// ==================== 媒体 / 富文本 ====================

test('only controlled relative media URLs get BASE_URL; remote URLs are rejected', () => {
  assert.equal(resolveMediaUrl('/api/events/12/media/7', BASE), `${BASE}/api/events/12/media/7`)
  assert.equal(resolveMediaUrl('/api/events/12/media/7', `${BASE}/`), `${BASE}/api/events/12/media/7`)
  assert.equal(resolveMediaUrl('https://evil.example/x.png', BASE), '')
  assert.equal(resolveMediaUrl('//evil.example/x.png', BASE), '')
  assert.equal(resolveMediaUrl('/uploads/x.png', BASE), '')
  assert.equal(pickCover([{ id: 1, role: 'inline', url: '/api/a' }, { id: 2, role: 'cover', url: '/api/b' }]).id, 2)
})

test('rich text only keeps approved media images and never reintroduces remote URLs', () => {
  const html = [
    '<p>正文</p>',
    '<img data-media-id="7" src="https://remote.example/x.png" alt="封面">',
    '<img data-media-id="99" src="https://remote.example/unknown.png">',
    '<img src="https://remote.example/plain.png">',
    '<script>alert(1)</script>',
    '<p onerror="alert(2)">危险属性</p>',
    '<a href="javascript:alert(3)">危险链接</a>',
  ].join('')

  const output = renderRichHtml(html, [
    { id: 7, role: 'cover', altText: '封面', url: '/api/events/12/media/7' },
  ], BASE)

  assert.match(output, /<img src="http:\/\/127\.0\.0\.1:8000\/api\/events\/12\/media\/7"/)
  assert.match(output, /data-media-id="7"/)
  assert.equal(output.includes('remote.example'), false)
  assert.equal(output.includes('unknown.png'), false)
  assert.equal(output.includes('script'), false)
  assert.equal(output.includes('onerror'), false)
  assert.equal(output.includes('javascript:'), false)
})

test('rich text maps src that exactly matches an approved DTO media URL', () => {
  const output = renderRichHtml(
    '<img src="/api/content/posts/robot-expo/media/3">',
    [{ id: 3, role: 'inline', altText: null, url: '/api/content/posts/robot-expo/media/3' }],
    BASE,
  )

  assert.match(output, /src="http:\/\/127\.0\.0\.1:8000\/api\/content\/posts\/robot-expo\/media\/3"/)
})

test('empty or non-string body_html renders nothing', () => {
  assert.equal(renderRichHtml('', [], BASE), '')
  assert.equal(renderRichHtml(null, [], BASE), '')
  assert.equal(renderRichHtml(123, [], BASE), '')
})

// ==================== 报名展示 ====================

test('registration state only trusts server fields and never invents waitlist', () => {
  const disabled = registrationView(normalizeEvent(eventDto()))
  assert.equal(disabled.mode, 'none')
  assert.match(disabled.reason, /站内报名暂未开放/)
  assert.equal(disabled.actions.includes('waitlist'), false)

  const enabled = registrationView(normalizeEvent(eventDto({
    registration: { can_register: true, registration_reason: '', allowed_actions: ['view_detail'] },
  })))
  assert.equal(enabled.mode, 'register')

  const stringTrue = registrationView(normalizeEvent(eventDto({
    registration: { can_register: 'true', registration_reason: '', allowed_actions: [] },
  })))
  assert.equal(stringTrue.mode, 'none')
})

test('external registration only appears with both allowed action and real URL', () => {
  const withUrl = registrationView(normalizeEvent(eventDto({
    registration: {
      can_register: false,
      registration_reason: 'external_only',
      allowed_actions: ['view_detail', 'open_external_registration'],
    },
    external_registration_url: 'https://organizer.example/signup',
  })))
  assert.equal(withUrl.mode, 'external')
  assert.equal(withUrl.url, 'https://organizer.example/signup')

  // G1-10 DTO 没有该字段时不凭空造按钮
  const withoutUrl = registrationView(normalizeEvent(eventDto({
    registration: {
      can_register: false,
      registration_reason: 'external_only',
      allowed_actions: ['view_detail', 'open_external_registration'],
    },
  })))
  assert.equal(withoutUrl.mode, 'none')
  assert.match(withoutUrl.reason, /外部主办方/)

  // 非法协议 / 相对路径不产生按钮（normalizer 已置空）。
  const unsafe = registrationView(normalizeEvent(eventDto({
    registration: {
      can_register: false,
      registration_reason: 'external_only',
      allowed_actions: ['view_detail', 'open_external_registration'],
    },
    external_registration_url: 'javascript:alert(1)',
  })))
  assert.equal(unsafe.mode, 'none')
  assert.equal(unsafe.url, '')
})

test('G2 registration modes: requires_login / already_registered', () => {
  const login = registrationView(normalizeEvent(eventDto({
    registration: {
      can_register: false,
      registration_reason: 'requires_login',
      allowed_actions: ['view_detail', 'login'],
    },
  })))
  assert.equal(login.mode, 'login')
  assert.match(login.reason, /登录/)

  const mine = registrationView(normalizeEvent(eventDto({
    registration: {
      can_register: false,
      registration_reason: 'already_registered',
      allowed_actions: ['view_detail', 'view_my_registration'],
    },
  })))
  assert.equal(mine.mode, 'mine')
  assert.match(mine.reason, /我的报名/)

  const full = registrationView(normalizeEvent(eventDto({
    registration: {
      can_register: false,
      registration_reason: 'full',
      allowed_actions: ['view_detail'],
    },
  })))
  assert.equal(full.mode, 'none')
  assert.match(full.reason, /名额已满/)
})

test('G2 form metadata is exposed without inventing defaults', () => {
  const event = normalizeEvent(eventDto({
    registration: {
      can_register: true,
      registration_reason: 'ok',
      allowed_actions: ['view_detail', 'register'],
      form_version: 3,
      privacy_version: '2026-10-19',
      privacy_summary: '摘要',
      rules_version_id: 7,
    },
  }))

  assert.equal(event.registration.formVersion, 3)
  assert.equal(event.registration.privacyVersion, '2026-10-19')
  assert.equal(event.registration.privacySummary, '摘要')
  assert.equal(event.registration.rulesVersionId, 7)

  const missing = normalizeEvent(eventDto())
  assert.equal(missing.registration.formVersion, null)
  assert.equal(missing.registration.privacyVersion, null)
})

// ==================== 分享 ====================

test('share paths use real detail routes and reject invalid identity', () => {
  assert.equal(buildSharePath('event', 12), '/pages/ecosystem/event-detail?id=12')
  assert.equal(buildSharePath('event', 0), '')
  assert.equal(buildSharePath('event', 'abc'), '')
  assert.equal(
    buildSharePath('content', 'robot expo/2026'),
    '/pages/ecosystem/content-detail?slug=robot%20expo%2F2026',
  )
  assert.equal(buildSharePath('content', ' '), '')
  assert.equal(buildShareUrl('/pages/ecosystem/event-detail?id=12', BASE), `${BASE}/#/pages/ecosystem/event-detail?id=12`)
  assert.equal(buildShareUrl('/pages/x', `${BASE}/`), `${BASE}/#/pages/x`)
  assert.equal(buildShareUrl('', BASE), '')
  // 基址为空（非 H5 / 站点地址不可判定）时绝不能退化成 `/#/...` 伪链接。
  assert.equal(buildShareUrl('/pages/x', ''), '')
  assert.equal(buildShareUrl('/pages/x', null), '')
})

test('H5 share base is the current site, never the API BASE_URL', () => {
  // 常规 H5 根部署
  assert.equal(
    resolveShareBase({ origin: 'https://mecha.example.com', pathname: '/', protocol: 'https:' }),
    'https://mecha.example.com',
  )
  // 子目录部署：去掉 index.html 与尾斜杠
  assert.equal(
    resolveShareBase({ origin: 'https://mecha.example.com', pathname: '/h5/index.html', protocol: 'https:' }),
    'https://mecha.example.com/h5',
  )
  assert.equal(
    resolveShareBase({ origin: 'http://127.0.0.1:5173', pathname: '/h5/', protocol: 'http:' }),
    'http://127.0.0.1:5173/h5',
  )
  // 非 http(s) / 缺少 origin / 非对象一律返回空串（非 H5 由页面传空）
  assert.equal(resolveShareBase(null), '')
  assert.equal(resolveShareBase('https://evil.example/'), '')
  assert.equal(resolveShareBase({ origin: 'file:///tmp', pathname: '/', protocol: 'file:' }), '')
  assert.equal(resolveShareBase({ origin: 'https://api.example.com', pathname: '//evil.example', protocol: 'https:' }), '')

  // 生成的分享 URL 必须落在当前站点，而不是后端 8000 端口。
  const path = buildSharePath('event', 12)
  const siteBase = resolveShareBase({ origin: 'https://mecha.example.com', pathname: '/', protocol: 'https:' })
  assert.equal(buildShareUrl(path, siteBase), 'https://mecha.example.com/#/pages/ecosystem/event-detail?id=12')
  assert.notEqual(buildShareUrl(path, siteBase), `${BASE}/#${path}`)
})

test('copy reports success only when the clipboard write resolves', async () => {
  const ok = await copyShareLink('/pages/ecosystem/event-detail?id=12', BASE, () => Promise.resolve())
  assert.deepEqual(ok, { ok: true, message: '链接已复制' })

  const failed = await copyShareLink('/pages/ecosystem/event-detail?id=12', BASE, () => Promise.reject(new Error('denied')))
  assert.equal(failed.ok, false)
  assert.match(failed.message, /复制失败/)

  const noWriter = await copyShareLink('/pages/x', BASE, null)
  assert.equal(noWriter.ok, false)

  const noPath = await copyShareLink('', BASE, () => Promise.resolve())
  assert.equal(noPath.ok, false)
})
