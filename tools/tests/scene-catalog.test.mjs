import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'

const source = await readFile(new URL('../../utils/scene-catalog.js', import.meta.url), 'utf8')
const { createSceneCatalog, sceneId, configuredSceneProducts, normalizeSceneImage } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'))
const row = (id, extra = {}) => ({ id, name: '场景' + id, ...extra })
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b }); return { promise, resolve, reject } }

test('服务端快照替换：删除、停用和真实空列表不能保留本地卡片', async () => {
  let data = [row(1), row(2)]
  const catalog = createSceneCatalog(async () => ({ data }))
  assert.deepEqual(catalog.rows, [])
  await catalog.load(); data = [row(2)]; await catalog.load({ force: true })
  assert.deepEqual(catalog.rows.map(s => s.id), [2])
  data = []; await catalog.load({ force: true }); assert.deepEqual(catalog.rows, [])
})

test('0、null 和空文本覆盖旧内容，未经核验咨询数不会转成公开指标', async () => {
  let data = [row(1, { description: 'old', banner: '/old', consult_count: 37, sort: 9, is_hot: true })]
  const catalog = createSceneCatalog(async () => ({ data }))
  await catalog.load()
  data = [row(1, { description: '', banner: null, consult_count: 0, sort: 0, is_hot: false })]
  await catalog.load({ force: true })
  assert.equal(catalog.rows[0].desc, ''); assert.equal(catalog.rows[0].banner, '')
  assert.equal(catalog.rows[0].sort, 0); assert.equal(catalog.rows[0].is_hot, false)
  assert.equal(catalog.rows[0].consultCount, undefined)
})

test('非法响应、重复 ID 和网络失败清空可操作卡片，立即重试不命中成功缓存', async () => {
  for (const bad of [null, {}, { data: 'wrong' }, { data: [row(1), row(1)] }, { data: [row('undefined')] }, { data: [row(1, { name: '' })] }]) {
    let result = { data: [row(9)] }
    const catalog = createSceneCatalog(async () => result)
    await catalog.load(); result = bad
    await assert.rejects(catalog.load({ force: true })); assert.deepEqual(catalog.rows, [])
    result = { data: [row(2)] }; await catalog.load(); assert.equal(catalog.rows[0].id, 2)
  }
  let fail = false
  const catalog = createSceneCatalog(async () => { if (fail) throw new Error('network'); return { data: [row(1)] } })
  await catalog.load(); fail = true
  await assert.rejects(catalog.load({ force: true }), /network/); assert.deepEqual(catalog.rows, [])
})

test('TTL 只缓存有效响应，onShow 可强制重新验证，并发请求共享结果', async () => {
  let now = 0, calls = 0
  const wait = deferred()
  const catalog = createSceneCatalog(async () => { calls++; return wait.promise }, { now: () => now })
  const a = catalog.load(), b = catalog.load({ force: true }); assert.equal(a, b)
  wait.resolve({ data: [row(1)] }); await a
  await catalog.load(); assert.equal(calls, 1)
  now = 300001; await catalog.load(); assert.equal(calls, 2)
  await catalog.load({ force: true }); assert.equal(calls, 3)
})

test('失效后的旧成功或旧失败均不能覆盖最新快照', async () => {
  for (const fails of [false, true]) {
    const first = deferred(), second = deferred(); let count = 0
    const catalog = createSceneCatalog(() => (++count === 1 ? first.promise : second.promise))
    const a = catalog.load(); await Promise.resolve(); catalog.invalidate()
    const b = catalog.load(); await Promise.resolve()
    second.resolve({ data: [row(2)] }); await b
    fails ? first.reject(new Error('stale')) : first.resolve({ data: [row(1)] })
    await a; assert.deepEqual(catalog.rows.map(x => x.id), [2])
  }
})

test('合法 ID、分类顺序及图片地址处理', async () => {
  for (const id of [undefined, '', 0, -1, '1x', '1e2', '01', '1.0', 4294967296]) assert.equal(sceneId(id), null)
  assert.equal(sceneId('4294967295'), 4294967295)
  const catalog = createSceneCatalog(async () => ({ data: [row(2), row(1), row(3, { sort: 10 })] }))
  await catalog.load(); assert.deepEqual(catalog.rows.map(x => x.id), [3, 1, 2])
  assert.equal(normalizeSceneImage('http://localhost:8000/storage/a', 'https://api.test'), 'https://api.test/storage/a')
  assert.equal(normalizeSceneImage('https://cdn.test/a', 'https://api.test'), 'https://cdn.test/a')
  assert.equal(normalizeSceneImage('javascript:alert(1)', 'https://api.test'), '')
  assert.equal(normalizeSceneImage('//bad.test/a', 'https://api.test'), '')
})

test('无配置、目录失败、孤儿 ID、booking 均不回退到模拟商品', () => {
  const product = { id: 99, type: 'simple' }
  for (const [ids, products] of [[[], [product]], [[99], []], [[88], [product]], [[99], [{ id: 99, type: 'booking' }]]]) {
    assert.deepEqual(configuredSceneProducts(ids, products), [])
  }
  assert.deepEqual(configuredSceneProducts(['99'], [product]), [product])
})

const detailScript = (await readFile(new URL('../../pages/robot/fangan.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
function mountDetail(getSceneDetail, submitError = null) {
  const calls = []
  const context = vm.createContext({ sceneId, configuredSceneProducts, normalizeSceneImage, BASE_URL: 'https://api.test', StateView: {},
    sceneApi: { getSceneDetail }, sceneDraftPayload: value => ({ ...value, scene_id: value.sceneId }),
    openSceneRequest: payload => calls.push({ url: '/pages/scene/request?sceneId=' + payload.scene_id, payload }),
    uni: { navigateTo: payload => calls.push(payload), showToast: payload => calls.push(payload) } })
  vm.runInContext(detailScript.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), context)
  const component = context.component, state = component.data()
  for (const [name, fn] of Object.entries(component.methods)) state[name] = fn.bind(state)
  Object.defineProperty(state, 'filteredProducts', { get: () => component.computed.filteredProducts.call(state) })
  component.onLoad.call(state, { sceneId: '1' })
  return { state, calls }
}
const detail = products => ({ matched: true, name: '真实场景', description: '', banner: null, scheme: { robots: [99] }, products })
const product = { id: 99, name: '真实机型', type: 'simple', prices: { regular: { price: 10 } } }

test('详情仅在服务端成功后开放选择，导航保留 sceneId；失败清空选择并禁止交易', async () => {
  let result = detail([product])
  const { state, calls } = mountDetail(async () => { if (result instanceof Error) throw result; return result })
  state.goProductDetail(product); assert.equal(calls.length, 0)
  await state.reload(); state.toggleMachine(state.filteredProducts[0]); assert.equal(state.selectedMachines.length, 1)
  state.submitSceneOrder(); assert.match(calls[0].url, /sceneId=1/)
  result = new Error('场景已停用'); await state.reload()
  assert.equal(state.sceneLoaded, false); assert.equal(state.selectedMachines.length, 0)
  const count = calls.length; state.goProductDetail(product); state.submitSceneOrder(); assert.equal(calls.length, count)
  assert.match(state.loadError, /停用/)
})

test('详情乱序响应只保留最新，重新进入时刷新已选机型价格', async () => {
  const first = deferred(), second = deferred(); let count = 0
  const { state } = mountDetail(() => ++count === 1 ? first.promise : second.promise)
  const a = state.reload(), b = state.reload()
  second.resolve(detail([product])); await b
  first.resolve(detail([])); await a
  assert.equal(state.filteredProducts.length, 1)
  state.toggleMachine(state.filteredProducts[0]); product.prices.regular.price = 12
  await state.reload(); assert.equal(state.selectedMachines[0].price, '12')
})

test('详情缺产品协议与非法价格不进入交易；未配置方案保持空态', async () => {
  const { state } = mountDetail(async () => detail([{ ...product, prices: {} }, { ...product, id: 2, type: 'booking' }]))
  await state.reload(); assert.equal(state.filteredProducts.length, 0); assert.equal(state.scheme.pains.length, 0)
  const missing = mountDetail(async () => ({ matched: true, scheme: {} }))
  await missing.state.reload(); assert.match(missing.state.loadError, /数据已更新/)
  for (const invalid of ['', ' ', 'NaN', '-1']) assert.equal(state.mapCatalogProduct({ ...product, prices: { regular: { price: invalid } } }), null)
  assert.equal(state.mapCatalogProduct({ ...product, prices: { regular: { price: 0 } } }).price, '0')
})

test('场景咨询只进入统一需求表单，不创建零元订单或伪造已受理', async () => {
  const { state, calls } = mountDetail(async () => detail([product]))
  await state.reload(); state.onConsult(); state.leadForm = { name: '测试', phone: '13800000000', demand: '需求' }
  const a = state.submitLead(), b = state.submitLead(); await Promise.all([a, b])
  assert.equal(calls.filter(c => c.type === 'custom').length, 0)
  assert.equal(calls[0].payload.scene_id, 1); assert.equal(state.showLeadSuccess, false)
  assert.match(calls[0].url, /\/pages\/scene\/request/)
})

test('首页从真实快照更新，接口失败不保留旧卡片或假咨询人数', async () => {
  const file = await readFile(new URL('../../pages/home/index.vue', import.meta.url), 'utf8')
  const script = file.match(/<script>([\s\S]*?)<\/script>/)[1]
  let fail = false
  const context = vm.createContext({ StateView: {}, CustomTabBar: {}, EcoCenter: {}, sceneProducts: [row(1, { is_hot: false })],
    loadBackendScenes: async () => { if (fail) throw new Error('offline') } })
  vm.runInContext(script.replace(/^[ \t]*import .*$/gm, '').replace('export default', 'globalThis.component ='), context)
  const state = context.component.data()
  await context.component.methods.refreshScenes.call(state); assert.equal(state.schemes.length, 1)
  fail = true; await context.component.methods.refreshScenes.call(state)
  assert.equal(state.schemes.length, 0); assert.equal(state.scenesLoading, false); assert.equal(state.scenesError, 'offline')
  assert.doesNotMatch(file.split('<script>')[0], /100咨询|consultCount|已生成/)
})

test('结算失败或没有真实订单确认时，不写本地订单、不跳成功页，保留表单并透传场景身份', async () => {
  const source = (await readFile(new URL('../../pages/order/checkout.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  for (const response of [new Error('资源已失效'), { success: false }, { success: true }]) {
    const writes = [], navigation = []; let payload
    const context = vm.createContext({ TOKEN_KEY: 'token',
      tobOrderApi: { create: async input => { payload = input; if (response instanceof Error) throw response; return response } },
      uni: { showLoading() {}, hideLoading() {}, showToast() {}, getStorageSync: () => '', setStorageSync: (...args) => writes.push(args), switchTab: input => navigation.push(input) } })
    vm.runInContext(source.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), context)
    const state = context.component.data()
    state.form = { name: '测试', phone: '13800000000', city: '上海', address: '测试地址', email: '' }
    state.product = { id: 99, type: 'rent', from: 'scene', sceneId: 1, price: 10 }
    await context.component.methods.submitOrder.call(state)
    assert.equal(payload.scene_id, 1); assert.equal(state.submitting, false)
    assert.equal(writes.length, 0); assert.equal(navigation.length, 0); assert.equal(state.form.name, '测试')
  }
})

test('自定义方案旧草稿不能把模拟 ID 冒充目录 ID，提交到确认订单页时使用当前商品名称和价格', async () => {
  const source = (await readFile(new URL('../../pages/robot/index.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  const navigation = [], notices = []
  const context = vm.createContext({ Jineng: {}, Peijian: {}, StateView: {}, BASE_URL: 'https://qa.invalid',
    uni: { navigateTo: input => navigation.push(input), showToast: input => notices.push(input) } })
  vm.runInContext(source.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), context)
  const state = context.component.data()
  // 提交路径会用到 planPerDay 等同组件方法,按 mountDetail 的做法全部绑到 state 上
  for (const [name, fn] of Object.entries(context.component.methods)) state[name] = fn.bind(state)
  state.reloadCatalog = async () => { state.rentCatalog = [{ id: 11, bagistoId: 11, name: '当前商品', price: 20 }] }
  state.selectedPlanItems = [{ id: 11, name: '旧模拟商品', price: 1 }]
  await context.component.methods.submitCustomPlanOrder.call(state)
  assert.equal(navigation.length, 0); assert.equal(state.planSubmitting, false); assert.match(notices[0].title, /失效/)
  state.selectedPlanItems = [{ id: 11, bagistoId: 11, name: '旧名称', price: 1 }]
  await context.component.methods.submitCustomPlanOrder.call(state)
  // 多机合单:提交订单 = 进确认订单页,items[] 用目录里的当前名称/价格,不能用草稿旧值
  const url = navigation[0].url
  assert.match(url, /^\/pages\/order\/checkout\?/)
  const params = new URLSearchParams(url.split('?')[1])
  const items = JSON.parse(params.get('items'))
  assert.equal(items.length, 1)
  assert.equal(items[0].product_id, 11)
  assert.equal(items[0].name, '当前商品')
  assert.equal(items[0].price, 20)
  assert.equal(params.get('rent_days'), '1')
  assert.equal(state.selectedPlanItems[0].price, 20)
})
