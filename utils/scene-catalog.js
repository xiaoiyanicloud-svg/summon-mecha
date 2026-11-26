// 场景列表只接受完整的服务端快照；示例数据不能获得商品和交易身份。
export function sceneId(value) {
  const text = String(value == null ? '' : value)
  if (!/^[1-9]\d{0,9}$/.test(text)) return null
  const id = Number(text)
  return id <= 4294967295 ? id : null
}

export function normalizeSceneImage(url, baseUrl) {
  if (typeof url !== 'string' || !url || url.includes('product-placeholder')) return ''
  const rewriteCache = path => path.replace(/^\/cache\/(?:small|medium|large|original)\//, '/storage/')
  if (url.startsWith('/') && !url.startsWith('//')) return baseUrl + rewriteCache(url)
  // 仅修正开发环境地址与同源 cache 路径；保留真实 CDN 主机，不把第三方地址改成本机。
  const local = url.match(/^https?:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?(\/.*)$/i)
  if (local) return baseUrl + rewriteCache(local[1])
  if (baseUrl && url.startsWith(baseUrl + '/cache/')) return baseUrl + rewriteCache(url.slice(baseUrl.length))
  return /^https?:\/\//i.test(url) ? url : ''
}

export function createSceneCatalog(fetchScenes, { imageUrl = value => value || '', now = Date.now, ttl = 300000 } = {}) {
  const rows = []
  let loadedAt = null
  let pending = null
  let generation = 0
  function invalidate() {
    generation++
    pending = null
    loadedAt = null
    rows.splice(0)
  }
  function load({ force = false } = {}) {
    if (pending) return pending
    if (!force && loadedAt !== null && now() - loadedAt < ttl) return Promise.resolve(rows)
    const version = ++generation
    const request = Promise.resolve().then(fetchScenes).then(response => {
      if (!response || !Array.isArray(response.data)) throw new Error('场景数据格式异常，请重试')
      const seen = new Set()
      const next = response.data.map(item => {
        const id = item && sceneId(item.id)
        if (!id || seen.has(id) || typeof item.name !== 'string' || !item.name.trim()) throw new Error('场景数据格式异常，请重试')
        seen.add(id)
        return {
          id, name: item.name, desc: item.description || '', category: item.category || '',
          banner: imageUrl(item.banner), icon: item.icon || '🤖', bg: item.bg_color || '#E8F0FE',
          tags: Array.isArray(item.tags) ? item.tags : [], sort: Number(item.sort) || 0,
          is_hot: item.is_hot === true || item.is_hot === 1, source: 'server'
        }
      }).sort((a, b) => b.sort - a.sort || a.id - b.id)
      if (version === generation) {
        rows.splice(0, rows.length, ...next)
        loadedAt = now()
      }
      return rows
    }).catch(error => {
      if (version !== generation) return rows
      rows.splice(0)
      loadedAt = null
      throw error
    }).finally(() => { if (version === generation) pending = null })
    pending = request
    return request
  }
  return { rows, load, invalidate }
}

export function configuredSceneProducts(ids, catalog) {
  if (!Array.isArray(ids) || !Array.isArray(catalog)) return []
  const order = new Map()
  for (const raw of ids) {
    const id = sceneId(raw)
    if (id && !order.has(id)) order.set(id, order.size)
  }
  if (!order.size) return []
  return catalog
    .filter(item => item && order.has(sceneId(item.bagistoId || item.id)) && item.type === 'simple')
    .sort((a, b) => order.get(sceneId(a.bagistoId || a.id)) - order.get(sceneId(b.bagistoId || b.id)))
}
