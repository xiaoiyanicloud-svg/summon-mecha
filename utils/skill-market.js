// Shared by both market entry points; examples never enter this state or a checkout URL.
export function createSkillMarketState() {
  return { rows: [], loading: false, error: '', page: 0, hasMore: false, sequence: 0 }
}

export async function loadSkillMarket(state, search, { query = '', filters = {}, append = false, keepRows = false } = {}) {
  if (append && (state.loading || !state.hasMore)) return
  const sequence = ++state.sequence
  const page = append ? state.page + 1 : 1
  state.loading = true
  state.error = ''
  // keepRows: 静默刷新时保留上一份已上架列表,避免先清空再加载导致「正在加载技能…」闪烁
  if (!append && !keepRows) { state.rows = []; state.page = 0; state.hasMore = false }
  try {
    const res = await search(query.trim(), { ...filters }, { page, limit: 12 })
    if (sequence !== state.sequence) return
    if (!res || !Array.isArray(res.data) || !res.pagination || res.pagination.page !== page || typeof res.pagination.has_more !== 'boolean') {
      throw new Error('技能市场数据暂未就绪，请稍后重试')
    }
    const rows = res.data
    if (rows.some(row => !row || row.market_eligible !== true || !Number.isSafeInteger(Number(row.id)) || Number(row.id) <= 0 || productSkillPrice(row) === null)) {
      throw new Error('技能市场数据暂未就绪，请稍后重试')
    }
    const unique = new Map((append ? state.rows : []).map(row => [Number(row.id), row]))
    rows.forEach(row => unique.set(Number(row.id), row))
    state.rows = [...unique.values()]
    state.page = page
    state.hasMore = res.pagination.has_more === true
  } catch (error) {
    if (sequence === state.sequence) state.error = error.message || '技能加载失败，请重试'
  } finally {
    if (sequence === state.sequence) state.loading = false
  }
}

export function skillDetailUrl(item) {
  const id = Number(item && (item.bagistoId || item.id))
  return item && !item.is_demo && Number.isSafeInteger(id) && id > 0
    ? `/pages/home/jineng?skillId=${id}&productId=${id}` : ''
}

export function productSkillPrice(product) {
  const raw = product && (product.prices?.final?.price ?? product.price)
  if (raw === null || raw === undefined || raw === '') return null
  const normalized = typeof raw === 'number' ? raw : String(raw).replace(/[^\d.\-]/g, '')
  if (normalized === '') return null
  const value = Number(normalized)
  return Number.isFinite(value) && value >= 0 ? value : null
}
