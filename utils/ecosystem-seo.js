/**
 * G4-05 · H5 端 SEO / 分享元数据。
 *
 * 纯函数、零依赖、不直接触摸全局 `document`：
 *   · `buildSeo()` 由归一化后的 G1 DTO 生成标题、描述、canonical、OG、JSON-LD；
 *   · `applySeoToDocument()` 接收 document 对象，便于 Node 测试用假对象验证；
 *   · 页面只在 `#ifdef H5` 条件编译块内调用，小程序端仍走原生分享。
 *
 * 三条一致性约束：
 *   1. canonical 必须指向 Laravel SSR 地址（`SSR_BASE_URL`），不是 H5 hash 地址；
 *   2. 分享路径只含公开标识（活动 id / 资讯 slug），不含 token、收藏或账号状态；
 *   3. 撤稿/404 页面输出 noindex，不输出旧标题与摘要。
 */

const SITE_NAME = '召唤机甲'

function trimString(value) {
  return typeof value === 'string' ? value.trim() : ''
}

function joinUrl(baseUrl, path) {
  const base = trimString(baseUrl).replace(/\/+$/, '')
  if (!base) return ''
  return `${base}/${String(path || '').replace(/^\/+/, '')}`
}

function truncate(value, limit) {
  const text = trimString(value).replace(/\s+/g, ' ')
  if (text.length <= limit) return text
  return `${text.slice(0, limit)}…`
}

function eventAttendanceMode(mode) {
  if (mode === 'online') return 'OnlineEventAttendanceMode'
  if (mode === 'hybrid') return 'MixedEventAttendanceMode'
  return 'OfflineEventAttendanceMode'
}

function eventStatus(status) {
  if (status === 'canceled') return 'EventCancelled'
  if (status === 'postponed') return 'EventPostponed'
  return 'EventScheduled'
}

/**
 * @param {object} input
 * @param {'event'|'content'} input.type
 * @param {object} input.entity  normalizeEvent / normalizeContent 的结果
 * @param {string} input.baseUrl Laravel SSR 站点根，例如 https://www.example.com
 * @param {string} [input.siteName]
 * @param {string} [input.image]  绝对图片 URL（可选）
 */
export function buildSeo(input = {}) {
  const type = input.type
  const entity = input.entity || {}
  const baseUrl = input.baseUrl
  const siteName = trimString(input.siteName) || SITE_NAME

  if (type === 'event' && entity.id) {
    const canonical = joinUrl(baseUrl, `/events/${entity.id}`)
    const description = truncate(entity.summary || `${siteName} 赛事活动`, 200)

    return {
      title: `${entity.name} · ${siteName}`,
      description,
      canonical,
      robots: 'index,follow',
      og: {
        'og:type': 'article',
        'og:title': entity.name,
        'og:description': description,
        'og:url': canonical,
        'og:site_name': siteName,
        ...(input.image ? { 'og:image': input.image } : {}),
      },
      jsonLd: {
        '@context': 'https://schema.org',
        '@type': 'Event',
        name: entity.name,
        description: entity.summary || '',
        url: canonical,
        startDate: entity.startsAt || undefined,
        endDate: entity.endsAt || undefined,
        eventAttendanceMode: `https://schema.org/${eventAttendanceMode(entity.participationMode)}`,
        eventStatus: `https://schema.org/${eventStatus(entity.hostingStatus)}`,
        location: entity.participationMode === 'online'
          ? { '@type': 'VirtualLocation', url: canonical }
          : {
            '@type': 'Place',
            name: entity.locationText || entity.city || '',
            address: { '@type': 'PostalAddress', addressLocality: entity.city || '' },
          },
        organizer: {
          '@type': 'Organization',
          name: (entity.organizer && entity.organizer.name) || '',
        },
      },
    }
  }

  if (type === 'content' && entity.slug) {
    const canonical = joinUrl(baseUrl, `/content/${encodeURIComponent(entity.slug)}`)
    const description = truncate(entity.summary || `${siteName} 动态资讯`, 200)

    return {
      title: `${entity.title} · ${siteName}`,
      description,
      canonical,
      robots: 'index,follow',
      og: {
        'og:type': 'article',
        'og:title': entity.title,
        'og:description': description,
        'og:url': canonical,
        'og:site_name': siteName,
        ...(input.image ? { 'og:image': input.image } : {}),
      },
      jsonLd: {
        '@context': 'https://schema.org',
        '@type': 'Article',
        headline: entity.title,
        description: entity.summary || '',
        url: canonical,
        datePublished: entity.firstPublishedAt || undefined,
        dateModified: entity.publishedAt || undefined,
        author: {
          '@type': 'Person',
          name: (entity.author && entity.author.name) || '',
        },
        publisher: { '@type': 'Organization', name: siteName },
      },
    }
  }

  return {
    title: `${siteName} · 内容不可用`,
    description: '该内容不存在或暂不可访问。',
    canonical: joinUrl(baseUrl, '/'),
    robots: 'noindex,nofollow',
    og: { 'og:site_name': siteName },
    jsonLd: null,
  }
}

/**
 * 把 SEO 元数据写入 document。
 *
 * `doc` 由调用方传入（浏览器传 window.document，测试传假对象）。
 * 不引入任何框架；重复调用会先移除上一次由本模块写入的标签。
 */
export function applySeoToDocument(seo, doc) {
  if (!seo || !doc) return

  if (typeof seo.title === 'string' && seo.title) {
    doc.title = seo.title
  }

  setMeta(doc, 'name', 'description', seo.description)
  setMeta(doc, 'name', 'robots', seo.robots)

  setLink(doc, 'canonical', seo.canonical)

  const og = seo.og || {}
  for (const property of Object.keys(og)) {
    setMeta(doc, 'property', property, og[property])
  }

  setJsonLd(doc, seo.jsonLd)
}

function setMeta(doc, attr, key, content) {
  if (!content) return

  let node = doc.querySelector(`meta[${attr}="${key}"]`)
  if (!node) {
    node = doc.createElement('meta')
    node.setAttribute(attr, key)
    doc.head.appendChild(node)
  }
  node.setAttribute('content', String(content))
}

function setLink(doc, rel, href) {
  if (!href) return

  let node = doc.querySelector(`link[rel="${rel}"]`)
  if (!node) {
    node = doc.createElement('link')
    node.setAttribute('rel', rel)
    doc.head.appendChild(node)
  }
  node.setAttribute('href', href)
}

function setJsonLd(doc, jsonLd) {
  let node = doc.querySelector('script[data-ecosystem-seo="jsonld"]')

  if (!jsonLd) {
    if (node) node.remove()
    return
  }

  if (!node) {
    node = doc.createElement('script')
    node.setAttribute('type', 'application/ld+json')
    node.setAttribute('data-ecosystem-seo', 'jsonld')
    doc.head.appendChild(node)
  }

  // 避免 `</script>` 闭合注入：把 < > & 转义成 JSON 安全形式。
  node.textContent = JSON.stringify(jsonLd)
    .replace(/</g, '\\u003c')
    .replace(/>/g, '\\u003e')
    .replace(/&/g, '\\u0026')
}

export { SITE_NAME }
