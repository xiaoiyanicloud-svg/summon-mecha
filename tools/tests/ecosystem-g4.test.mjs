import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeEvent, normalizeContent, normalizePage, normalizeEventPage, errorState } from '../../utils/ecosystem-public.js'
import { buildSeo, applySeoToDocument } from '../../utils/ecosystem-seo.js'

const BASE = 'https://example.test'

const eventDto = (overrides = {}) => ({
  id: 12,
  kind: 'competition',
  name: '公开活动名称',
  summary: '公开活动摘要',
  participation_mode: 'offline',
  city: '上海',
  location_text: '张江科学会堂',
  starts_at: '2026-11-01T09:00:00+08:00',
  ends_at: '2026-11-01T18:00:00+08:00',
  hosting_status: 'scheduled',
  organizer: { name: '召唤机甲', platform_role: 'organizer' },
  source: { name: '召唤机甲官方', url: 'https://example.com/events/1' },
  registration: { can_register: false, registration_reason: 'registration_disabled', allowed_actions: [] },
  media: [],
  ...overrides,
})

const contentDto = (overrides = {}) => ({
  slug: 'public-content',
  type: 'news',
  title: '公开文章标题',
  summary: '公开文章摘要',
  author: { name: '张三', org: '召唤机甲编辑部' },
  sources: [],
  published_at: '2026-10-19T12:00:00+08:00',
  first_published_at: '2026-10-19T12:00:00+08:00',
  media: [],
  ...overrides,
})

function fakeDocument() {
  const nodes = []

  const matches = (node, selector) => {
    const meta = /^meta\[(name|property)="([^"]+)"\]$/.exec(selector)
    if (meta) return node.tag === 'meta' && node.attrs[meta[1]] === meta[2]
    const link = /^link\[rel="([^"]+)"\]$/.exec(selector)
    if (link) return node.tag === 'link' && node.attrs.rel === link[1]
    const script = /^script\[data-ecosystem-seo="([^"]+)"\]$/.exec(selector)
    if (script) return node.tag === 'script' && node.attrs['data-ecosystem-seo'] === script[1]
    return false
  }

  return {
    title: '',
    nodes,
    head: {
      appendChild(node) { nodes.push(node) },
    },
    querySelector(selector) {
      return nodes.find((node) => matches(node, selector)) || null
    },
    createElement(tag) {
      return {
        tag,
        attrs: {},
        textContent: '',
        setAttribute(key, value) { this.attrs[key] = value },
        remove() {
          const index = nodes.indexOf(this)
          if (index >= 0) nodes.splice(index, 1)
        },
      }
    },
  }
}

test('G4-05 buildSeo: 活动 canonical/OG/JSON-LD 与 SSR 同源', () => {
  const event = normalizeEvent(eventDto())
  const seo = buildSeo({ type: 'event', entity: event, baseUrl: BASE, siteName: '召唤机甲' })

  assert.equal(seo.canonical, `${BASE}/events/12`)
  assert.equal(seo.robots, 'index,follow')
  assert.equal(seo.og['og:title'], '公开活动名称')
  assert.equal(seo.og['og:url'], seo.canonical)
  assert.equal(seo.jsonLd['@type'], 'Event')
  assert.equal(seo.jsonLd.startDate, '2026-11-01T09:00:00+08:00')
  assert.ok(seo.jsonLd.eventStatus.endsWith('EventScheduled'))
  assert.equal(seo.jsonLd.location.name, '张江科学会堂')
})

test('G4-05 buildSeo: 资讯 canonical 使用 slug，文章元数据完整', () => {
  const post = normalizeContent(contentDto())
  const seo = buildSeo({ type: 'content', entity: post, baseUrl: BASE })

  assert.equal(seo.canonical, `${BASE}/content/public-content`)
  assert.equal(seo.jsonLd['@type'], 'Article')
  assert.equal(seo.jsonLd.headline, '公开文章标题')
  assert.equal(seo.jsonLd.author.name, '张三')
})

test('G4-05 buildSeo: 缺实体输出 noindex，不拼旧标题', () => {
  const seo = buildSeo({ type: 'event', entity: null, baseUrl: BASE })

  assert.equal(seo.robots, 'noindex,nofollow')
  assert.equal(seo.jsonLd, null)
  assert.ok(seo.title.includes('内容不可用'))
})

test('G4-05 applySeoToDocument: 写入/更新 head 且 JSON-LD 转义闭合标签', () => {
  const doc = fakeDocument()
  const seo = buildSeo({
    type: 'event',
    entity: normalizeEvent(eventDto({ name: '标题 </script><script>alert(1)</script>' })),
    baseUrl: BASE,
  })

  applySeoToDocument(seo, doc)

  assert.equal(doc.title, seo.title)
  const description = doc.querySelector('meta[name="description"]')
  const robots = doc.querySelector('meta[name="robots"]')
  const canonical = doc.querySelector('link[rel="canonical"]')
  const jsonLd = doc.querySelector('script[data-ecosystem-seo="jsonld"]')

  assert.ok(description)
  assert.equal(robots.attrs.content, 'index,follow')
  assert.equal(canonical.attrs.href, seo.canonical)
  assert.ok(jsonLd)
  assert.ok(!jsonLd.textContent.includes('</script>'))
  assert.ok(jsonLd.textContent.includes('\\u003c'))

  // 再写一次应更新同一组节点，而不是无限追加。
  applySeoToDocument(buildSeo({ type: 'event', entity: normalizeEvent(eventDto()), baseUrl: BASE }), doc)
  assert.equal(doc.nodes.filter((node) => node.tag === 'link').length, 1)
  assert.equal(doc.nodes.filter((node) => node.tag === 'script').length, 1)
})

test('G4-01 normalizePage: 游标模式解析 has_more / next_cursor', () => {
  const payload = {
    data: [eventDto()],
    meta: {
      mode: 'cursor',
      per_page: 10,
      has_more: true,
      next_cursor: 'abc123',
      total: null,
    },
  }

  const page = normalizeEventPage(payload, 1)
  assert.equal(page.mode, 'cursor')
  assert.equal(page.hasMore, true)
  assert.equal(page.nextCursor, 'abc123')
  assert.equal(page.total, null)
  assert.equal(page.items.length, 1)
})

test('G4-01 normalizePage: 游标声明 has_more 却没有 next_cursor 视为格式异常', () => {
  assert.throws(() => normalizePage({
    data: [],
    meta: { mode: 'cursor', per_page: 10, has_more: true, next_cursor: null },
  }), /分页格式异常/)
})

test('G4-02 401 是“请先登录”而不是可重试的服务故障', () => {
  const state = errorState({ statusCode: 401, message: '请先登录' })

  assert.equal(state.kind, 'unauthorized')
  assert.equal(state.retryable, false)
  assert.match(state.title, /登录/)
})

test('G4-02/G4-03 normalize: favorited 与 related 只接受真实字段', () => {
  const event = normalizeEvent(eventDto({
    favorited: true,
    related: {
      events: [{ type: 'event', id: 5, name: '关联活动', bogus: 'nope' }],
      contents: [{ type: 'content', slug: 'post-1', title: '关联资讯' }],
      device_models: [{ type: 'device_model', id: 3, brand: '召唤机甲', name: '机型', availability: 'active' }],
    },
  }))

  assert.equal(event.favorited, true)
  assert.equal(event.related.events[0].name, '关联活动')
  assert.equal(event.related.events[0].bogus, undefined)
  assert.equal(event.related.contents[0].slug, 'post-1')
  assert.equal(event.related.device_models[0].brand, '召唤机甲')
  assert.equal(event.related.skills.length, 0)

  const post = normalizeContent(contentDto({ favorited: 'true', related: null }))
  assert.equal(post.favorited, false)
  assert.deepEqual(post.related.events, [])
})
