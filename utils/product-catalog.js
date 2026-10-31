/**
 * 商品卡片/详情共用的价格与图片归一化。
 *
 * 存在的理由：
 *  - 价格：Bagisto 的 prices.final/regular 与 min_price 形态不统一（数字 / 带货币符号的字符串），
 *    且不能在缺少真实划线价时编造原价。此前前端用现价 × 1.2 兜底，会展示假折扣。
 *  - 图片：Bagisto 没有图时会给 product-placeholder 占位图，卡片应回退到品类实拍图，
 *    而不是留空白或 emoji；/cache/{size} 地址在本机缺路由时也要回退到 /storage 原图。
 *
 * 纯函数，便于 Node 单测；不依赖 uni-app 运行时。
 */

/** 除用户提供的真实商品图外，所有缺图/加载失败统一使用召唤机甲 Logo。 */
const SUMMON_MECHA_LOGO = '/static/summon-mecha-logo.webp'
const CATALOG_FALLBACK_POOLS = {
  rent: [SUMMON_MECHA_LOGO],
  buy: [SUMMON_MECHA_LOGO],
  skill: [SUMMON_MECHA_LOGO],
  accessory: [SUMMON_MECHA_LOGO],
  scene: [SUMMON_MECHA_LOGO],
}

/**
 * 统一商品媒体 URL：
 *  - /cache/{size}/... 在当前部署没有可用路由，改走真实文件 /storage/...；
 *  - localhost / 127.0.0.1 改写为前端配置的后端地址；
 *  - 相对路径只在前端传入 baseUrl 时补全。
 */
export function normalizeCatalogImageUrl(url, baseUrl = '') {
  let value = String(url || '').trim()
  if (!value) return ''

  if (/^(?:storage|cache)\//i.test(value)) value = '/' + value
  if (value.startsWith('/')) {
    value = value.replace(/^\/cache\/(?:small|medium|large|original)\//i, '/storage/')
    if (!baseUrl) return value
    return String(baseUrl).replace(/\/$/, '') + value
  }

  value = value.replace(
    /^(https?:\/\/[^/]+)\/cache\/(?:small|medium|large|original)\//i,
    '$1/storage/'
  )
  if (baseUrl) {
    value = value.replace(/^https?:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?(?=\/)/i, String(baseUrl).replace(/\/$/, ''))
  }
  return value
}

/** 缺图兜底：统一使用召唤机甲 Logo（保留 seed 参数以兼容既有调用）。 */
export function catalogFallbackImage(category = 'rent', seed = 0) {
  const pool = CATALOG_FALLBACK_POOLS[category] || CATALOG_FALLBACK_POOLS.rent
  const parsed = Number.parseInt(String(seed || 0), 10)
  const index = Number.isFinite(parsed) ? Math.abs(parsed) % pool.length : 0
  return pool[index]
}

function cleanAmount(value) {
  if (value === null || value === undefined || value === '') return NaN
  if (typeof value === 'number') return Number.isFinite(value) ? value : NaN
  const cleaned = String(value).replace(/[^\d.-]/g, '')
  if (cleaned === '') return NaN
  const parsed = Number(cleaned)
  return Number.isFinite(parsed) ? parsed : NaN
}

function round2(value) {
  return Math.round(value * 100) / 100
}

function formatAmount(value) {
  return String(round2(value))
}

/** Bagisto 的空图占位 URL 含 product-placeholder；空串/null 同样视为无图。 */
export function isRealProductImage(url) {
  return typeof url === 'string' && url.trim() !== '' && !url.includes('product-placeholder')
}

/** 取商品主图；优先 /storage 稳定地址，没有真实图时返回空串。 */
export function pickBaseImage(product) {
  const base = product && product.base_image
  if (!base || typeof base !== 'object') return ''
  const candidates = [
    base.medium_image_url,
    base.large_image_url,
    base.small_image_url,
    base.original_image_url,
  ]
  return normalizeCatalogImageUrl(candidates.find(isRealProductImage) || '')
}

/** 取商品图集（详情轮播）；只保留真实图，并统一成可持续访问的地址。 */
export function pickGalleryImages(product) {
  const list = product && Array.isArray(product.images) ? product.images : []
  return list
    .map(image => {
      if (typeof image === 'string') return normalizeCatalogImageUrl(image)
      if (!image || typeof image !== 'object') return ''
      return normalizeCatalogImageUrl(
        image.medium_image_url || image.large_image_url || image.small_image_url || image.original_image_url || image.url || ''
      )
    })
    .filter(isRealProductImage)
}

/**
 * 归一化价格。
 *
 * 规则：
 *  - 实付价优先 final → regular → min_price；
 *  - 只有真实 regular > 实付价时才返回 originalPrice，否则为空串；
 *  - 不接受负数；0 元商品照实返回 0。
 *
 * @returns {{price: string, originalPrice: string, hasDiscount: boolean}}
 */
export function normalizeProductPricing(product) {
  const prices = (product && product.prices) || {}
  const regular = cleanAmount(prices.regular && prices.regular.price)
  const final = cleanAmount(prices.final && prices.final.price)
  const min = cleanAmount(product && product.min_price)

  const effective = [final, regular, min].find(value => Number.isFinite(value) && value >= 0)
  const safeEffective = Number.isFinite(effective) ? effective : 0
  const hasDiscount = Number.isFinite(regular) && regular > safeEffective && safeEffective > 0

  return {
    price: formatAmount(safeEffective),
    originalPrice: hasDiscount ? formatAmount(regular) : '',
    hasDiscount,
  }
}
