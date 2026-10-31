import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import assert from 'node:assert/strict'

const source = await readFile(new URL('../../utils/skill-market.js', import.meta.url), 'utf8')
const { createSkillMarketState, loadSkillMarket, skillDetailUrl, productSkillPrice } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'))
const row = (id, price = 12) => ({ id, price, market_eligible: true })
const response = (data, page = 1, has_more = false) => ({ data, pagination: { page, has_more } })
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }

test('empty supply is successful empty state, request failure remains retryable', async () => {
  const state = createSkillMarketState()
  await loadSkillMarket(state, async () => response([]))
  assert.equal(state.page, 1); assert.equal(state.error, ''); assert.deepEqual(state.rows, [])
  await loadSkillMarket(state, async () => { throw new Error('network unavailable') })
  assert.equal(state.error, 'network unavailable'); assert.equal(state.loading, false); assert.equal(state.page, 0)
  await loadSkillMarket(state, async () => response([row(1)]))
  assert.equal(state.error, ''); assert.equal(state.rows[0].id, 1)
})

test('refresh clears previous cards, trimmed search and filter snapshot reach the server', async () => {
  const state = createSkillMarketState(); state.rows = [row(8)]
  let called
  const pending = deferred()
  const refresh = loadSkillMarket(state, (...args) => { called = args; return pending.promise }, { query: '  导览  ', filters: { model: 'EDU' } })
  assert.deepEqual(state.rows, []); assert.equal(state.loading, true)
  assert.deepEqual(called, ['导览', { model: 'EDU' }, { page: 1, limit: 12 }])
  pending.resolve(response([row(9)])); await refresh
  assert.equal(state.rows[0].id, 9)
})

test('out of order search responses and failures cannot replace the latest results', async () => {
  for (const oldFails of [false, true]) {
    const state = createSkillMarketState(), first = deferred(), second = deferred()
    const a = loadSkillMarket(state, () => first.promise)
    const b = loadSkillMarket(state, () => second.promise)
    second.resolve(response([row(2)])); await b
    oldFails ? first.reject(new Error('stale')) : first.resolve(response([row(1)]))
    await a; assert.equal(state.rows[0].id, 2); assert.equal(state.error, ''); assert.equal(state.loading, false)
  }
})

test('pagination deduplicates rows and stops at the last page', async () => {
  const state = createSkillMarketState(); let calls = 0
  const search = async (q, f, { page }) => { calls++; return page === 1 ? response([row(1)], 1, true) : response([row(1, 15), row(2)], 2, false) }
  await loadSkillMarket(state, search); await loadSkillMarket(state, search, { append: true })
  assert.deepEqual(state.rows, [row(1, 15), row(2)]); assert.equal(state.page, 2)
  await loadSkillMarket(state, search, { append: true }); assert.equal(calls, 2)
})

test('keepRows 静默刷新:旧卡片保留到新结果返回,期间不闪加载态', async () => {
  const state = createSkillMarketState()
  await loadSkillMarket(state, async () => response([row(1)], 1, true))
  const pending = deferred()
  const refresh = loadSkillMarket(state, () => pending.promise, { keepRows: true })
  assert.deepEqual(state.rows, [row(1)]); assert.equal(state.page, 1); assert.equal(state.loading, true)
  pending.resolve(response([row(2)])); await refresh
  assert.deepEqual(state.rows, [row(2)]); assert.equal(state.page, 1); assert.equal(state.hasMore, false)
})

test('only one load-more runs at a time and refreshing invalidates pending pagination', async () => {
  const state = createSkillMarketState(), pending = deferred()
  await loadSkillMarket(state, async () => response([row(1)], 1, true))
  const next = loadSkillMarket(state, () => pending.promise, { append: true })
  await loadSkillMarket(state, () => { throw new Error('duplicate request') }, { append: true })
  await loadSkillMarket(state, async () => response([row(3)]))
  pending.resolve(response([row(2)], 2)); await next
  assert.deepEqual(state.rows, [row(3)]); assert.equal(state.page, 1); assert.equal(state.error, '')
})

test('untrusted supply payloads and malformed pagination never become purchasable cards', async () => {
  for (const payload of [null, { data: [] }, response([row(0)]), response([{ ...row(1), market_eligible: false }]), response([{ id: 1, market_eligible: true }]), response([row(1, '面议')]), response([row(1)], 2), { data: [], pagination: { page: 1, has_more: 'true' } }]) {
    const state = createSkillMarketState()
    await loadSkillMarket(state, async () => payload)
    assert.ok(state.error); assert.deepEqual(state.rows, []); assert.equal(state.loading, false)
  }
})

test('unmount or hidden panel invalidates requests', async () => {
  const state = createSkillMarketState(), pending = deferred()
  const request = loadSkillMarket(state, () => pending.promise)
  state.sequence++; state.loading = false
  pending.resolve(response([row(1)])); await request
  assert.deepEqual(state.rows, []); assert.equal(state.loading, false)
})

test('demo and invalid identities have no purchase navigation; real supply uses canonical detail', () => {
  for (const item of [null, {}, { id: -1 }, { id: 1.5 }, { id: 'example-1' }, { id: 1, is_demo: true }]) assert.equal(skillDetailUrl(item), '')
  assert.equal(skillDetailUrl({ id: 12, bagistoId: 34 }), '/pages/home/jineng?skillId=34&productId=34')
})

test('real zero price stays free, absent/invalid price never becomes free', () => {
  for (const price of [null, undefined, '', '面议', -1, NaN, Infinity]) assert.equal(productSkillPrice({ price }), null)
  assert.equal(productSkillPrice({ price: 0 }), 0)
  assert.equal(productSkillPrice({ prices: { final: { price: 0 } }, price: 100 }), 0)
  assert.equal(productSkillPrice({ price: '¥1,234.50' }), 1234.5)
})
