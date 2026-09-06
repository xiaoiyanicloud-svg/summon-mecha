/**
 * 商品图片桥接:把 Bagisto(GET /api/products)返回的真实商品补到前端 mock 商品上。
 *
 * 作用:
 *  1) 图片 —— 卡片图 item.image / 详情轮播 item.images(真实图,过滤占位图)
 *  2) 闭环  —— 命中后备注 item.bagistoId(真实商品 id)/ item.bagistoPrice(真实价),
 *              供 addCart / 结算使用,从而打通「加购 → Bagisto 下单」的真闭环。
 *
 * 用法:前端保持写死数据不变。找不到对应商品、或该商品无真实图、或接口失败时一律静默——
 * 页面照常走原来的 emoji+底色兜底,商品 id 也回退到 mock id(仍走意向单),不会坏。
 *
 * 匹配规则:优先 item.sku(精确),其次 item.name(精确)。
 * 运营在 Bagisto 后台建一个同名(或同 sku,如 RENT-1/BUY-101)且已上架的商品并上传图,
 * 前端自动出图并把真 id 传导到结算,购买即闭环。
 */
import { productApi } from '@/utils/api'

// 简单内存缓存,避免每次 onShow 都请求
let _index = null
let _indexAt = 0
const TTL = 5 * 60 * 1000 // 5 分钟

// 过滤 Bagisto 的「空图占位图」:真实图走 cache/{size}/...,占位图 URL 含 product-placeholder
function isRealImageUrl(url) {
  return !!url && url.indexOf('product-placeholder') === -1
}

function pickImageUrl(image, key = 'medium_image_url') {
  if (!image || typeof image !== 'object') return ''
  const url = image[key]
    || image.medium_image_url
    || image.large_image_url
    || image.small_image_url
    || ''
  return isRealImageUrl(url) ? url : ''
}

// 为 mock 商品生成稳定 sku(供与 Bagisto 商品对齐;已有 sku 则沿用)
export function deriveSku(item, prefix = 'RBT') {
  return (item && item.sku) || `${prefix}-${item.id}`
}

/**
 * 拉取商品并建立 name/sku -> product 的索引(带缓存)。
 * 出错/空都返回空 Map,保证调用方安全。
 */
export async function getProductImageIndex() {
  const now = Date.now()
  if (_index && now - _indexAt < TTL) return _index

  let raw = []
  try {
    const res = await productApi.getProducts({ limit: 200, perPage: 200, page: 1 })
    raw = (res && res.data) ? res.data : []
    // 兼容分页包装 { data: [...] }
    if (raw && raw.data && Array.isArray(raw.data)) raw = raw.data
  } catch (e) {
    raw = []
  }

  const idx = new Map()
  ;(Array.isArray(raw) ? raw : []).forEach(p => {
    if (!p) return
    if (p.sku && !idx.has(`sku:${p.sku}`)) idx.set(`sku:${p.sku}`, p)
    if (p.name && !idx.has(`name:${p.name}`)) idx.set(`name:${p.name}`, p)
  })

  // 只在确实拿到数据时才缓存;空结果(后端未起/无商品)不缓存,下次进入页面自动重试
  if (idx.size > 0) {
    _index = idx
    _indexAt = now
  }
  return idx
}

/**
 * 给一批 mock 商品补数据:命中且确有真实值时,原地补齐 图片/真id/真价/sku。
 * @param {Array} items           前端商品数组(原地补充并返回)
 * @param {Object} opts          { prefix, cardKey, detailKey }
 *           prefix    生成的 sku 前缀,默认 RBT
 *           cardKey   卡片图用哪个字段,默认 medium_image_url
 *           detailKey 详情轮播用哪个字段,默认 medium_image_url
 * @returns {Array} 原数组
 */
export async function enrichWithProductImages(items, opts = {}) {
  if (!Array.isArray(items) || !items.length) return items
  const { prefix = 'RBT', cardKey = 'medium_image_url', detailKey = 'medium_image_url' } = opts || {}
  const idx = await getProductImageIndex()
  if (!idx || !idx.size) return items

  items.forEach(item => {
    if (!item || !item.name) return
    const p = idx.get(item.sku ? `sku:${item.sku}` : '') || idx.get(`name:${item.name}`)
    if (!p) return

    // 稳定 sku(已有则沿用),便于运营按它建品、也令重跑幂等
    item.sku = deriveSku(item, prefix)

    // 真实商品 id:购买闭环的关键 —— addCart/结算据此走 Bagisto 真下单
    if (p.id != null) item.bagistoId = p.id
    // 真实价(尽力读取;后端 min_price 是含货币符号的格式化串,需解析成纯数字)
    let realPrice = ''
    if (typeof p.price === 'number') realPrice = p.price
    else if (p.price != null && String(p.price).trim() !== '') realPrice = String(p.price)
    else if (p.min_price != null && String(p.min_price).trim() !== '') realPrice = String(p.min_price).replace(/[^\d.]/g, '')
    if (realPrice !== '' && String(realPrice).trim() !== '') item.bagistoPrice = realPrice
    // 后端产品介绍(富文本 HTML),供详情页 产品介绍 区展示
    if (p.description) item.bagistoDescription = p.description
    // 后端短描述(如有),供场景/副标题展示
    if (p.short_description) item.bagistoShort = p.short_description
    // 后端自定义属性 { code: {code,name,value} },供详情页「产品参数」区展示
    if (p.custom_attributes && typeof p.custom_attributes === 'object') item.bagistoAttributes = p.custom_attributes

    // 图片
    const card = pickImageUrl(p.base_image, cardKey)
    if (card) item.image = card

    const gallery = (Array.isArray(p.images) ? p.images : [])
      .map(img => pickImageUrl(img, detailKey))
      .filter(Boolean)
    if (gallery.length) item.images = gallery
  })

  return items
}
