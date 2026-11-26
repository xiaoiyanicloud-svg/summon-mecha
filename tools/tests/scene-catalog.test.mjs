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
  assert.equal(normalizeSceneImage('http://localhost:8000/cache/medium/product/9/pic.webp', 'https://api.test'), 'https://api.test/storage/product/9/pic.webp')
  assert.equal(normalizeSceneImage('https://api.test/cache/large/product/9/pic.webp', 'https://api.test'), 'https://api.test/storage/product/9/pic.webp')
  assert.equal(normalizeSceneImage('https://api.test/storage/product-placeholder/1.png', 'https://api.test'), '')
  assert.equal(normalizeSceneImage('https://cdn.test/a', 'https://api.test'), 'https://cdn.test/a')
  assert.equal(normalizeSceneImage('javascript:alert(1)', 'https://api.test'), '')
  assert.equal(normalizeSceneImage('//bad.test/a', 'https://api.test'), '')
})

test('无配置、目录失败、孤儿 ID、booking 均不回退到模拟商品，并保留配置排序', () => {
  const product = { id: 99, type: 'simple' }
  const product2 = { id: 18, type: 'simple' }
  for (const [ids, products] of [[[], [product]], [[99], []], [[88], [product]], [[99], [{ id: 99, type: 'booking' }]]]) {
    assert.deepEqual(configuredSceneProducts(ids, products), [])
  }
  assert.deepEqual(configuredSceneProducts(['99'], [product]), [product])
  assert.deepEqual(configuredSceneProducts([99, 18], [product2, product]).map(x => x.id), [99, 18])
})

const detailScript = (await readFile(new URL('../../pages/robot/fangan.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
function mountDetail(getSceneDetail, submitError = null, options = { sceneId: '1' }) {
  const calls = []
  const context = vm.createContext({ sceneId, configuredSceneProducts, normalizeSceneImage, BASE_URL: 'https://api.test', StateView: {}, SmIcon: {},
    sceneApi: { getSceneDetail }, sceneDraftPayload: value => ({ ...value, scene_id: value.sceneId }),
    openSceneRequest: payload => calls.push({ url: '/pages/scene/request?sceneId=' + payload.scene_id, payload }),
    uni: { navigateTo: payload => calls.push(payload), showToast: payload => calls.push(payload), showModal: payload => calls.push(payload) } })
  vm.runInContext(detailScript.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), context)
  const component = context.component, state = component.data()
  for (const [name, fn] of Object.entries(component.methods)) state[name] = fn.bind(state)
  for (const [name, fn] of Object.entries(component.computed || {})) {
    Object.defineProperty(state, name, { configurable: true, enumerable: true, get: () => fn.call(state) })
  }
  component.onLoad.call(state, options)
  return { state, calls }
}
const detail = products => ({ matched: true, name: '真实场景', description: '', banner: null, scene_revision_id: 7, scheme: { robots: [99] }, products })
const product = { id: 99, name: '真实机型', type: 'simple', prices: { regular: { price: 10 } } }

test('详情仅在服务端成功后开放选择，选中后进入确认订单页并透传 sceneId；失败清空选择并禁止交易', async () => {
  let result = detail([product])
  const { state, calls } = mountDetail(async () => { if (result instanceof Error) throw result; return result })
  state.goProductDetail(product); assert.equal(calls.length, 0); assert.equal(state.showMachineModal, false)
  await state.reload(); state.toggleMachine(state.filteredProducts[0]); assert.equal(state.selectedMachines.length, 1)
  await state.submitSceneOrder()
  const checkoutCall = calls.find(c => c.url && c.url.startsWith('/pages/order/checkout'))
  assert.ok(checkoutCall, '场景选型提交应进入确认订单页而不是需求线索表单')
  assert.match(checkoutCall.url, /sceneId=1/)
  assert.match(checkoutCall.url, /from=scene/)
  assert.match(checkoutCall.url, /type=rent/)
  assert.match(checkoutCall.url, /sceneRevisionId=7/)
  result = new Error('场景已停用'); await state.reload()
  assert.equal(state.sceneLoaded, false); assert.equal(state.selectedMachines.length, 0)
  const count = calls.length; state.goProductDetail(product); await state.submitSceneOrder()
  assert.equal(calls.length, count); assert.equal(state.showMachineModal, false)
  assert.match(state.loadError, /停用/)
})

test('场景选型支持按台数步进、最低起租下限约束与单机直下（重验最新价格）', async () => {
  const datedProduct = {
    id: 99, name: 'G1 人形', type: 'simple',
    prices: { final: { price: 2800 }, regular: { price: 3200 } },
    brand: { code: 'yushu', name: '宇树' },
    robot_type: { code: 'humanoid', label: '人形' },
    raas_meta: { min_rent_days: 5, deposit_yuan: '6000.00', comm_channels: ['DDS'] },
  }
  let serverPrice = 2800
  const { state, calls } = mountDetail(async () => ({
    matched: true, name: '商演展厅', description: '', banner: null, scene_revision_id: 9,
    scheme: { robots: [99] },
    products: [{ ...datedProduct, prices: { final: { price: serverPrice }, regular: { price: 3200 } } }],
  }))
  await state.reload()
  assert.equal(state.filteredProducts[0].minRentDays, 5)
  state.toggleMachine(state.filteredProducts[0])
  assert.equal(state.rentDays, 5) // 选入时自动抬升至最低起租天数
  state.selectRentDays(2)
  assert.equal(state.rentDays, 5) // 禁止低于最低起租
  state.changeMachineQty(state.filteredProducts[0], 2)
  assert.equal(state.selectedMachines[0].qty, 3)

  // 点卡片打开机型规格弹窗，服务端涨价后点击单机直下，必须重验并按最新价格送入 checkout
  state.goProductDetail(state.filteredProducts[0])
  assert.equal(state.showMachineModal, true)
  serverPrice = 3100
  await state.orderSingleMachine()
  const checkoutCall = calls.find(c => c.url && c.url.startsWith('/pages/order/checkout'))
  assert.ok(checkoutCall)
  const qs = new URLSearchParams(checkoutCall.url.split('?')[1])
  const sentItems = JSON.parse(qs.get('items'))
  assert.equal(sentItems[0].price, '3100')
  assert.equal(sentItems[0].qty, 3)
  assert.equal(qs.get('rent_days'), '5')
  assert.equal(qs.get('from'), 'scene')
})

test('确认订单页对场景机器选型放行不跳转需求页，且空参数场景跳回需求页、报价跳 confirm', async () => {
  const checkoutSource = (await readFile(new URL('../../pages/order/checkout.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  const createPayloads = [], redirects = []
  const mkCtx = () => {
    const ctx = vm.createContext({ TOKEN_KEY: 'token', SmIcon: {},
      tobOrderApi: { create: async p => { createPayloads.push(p); return { success: true, order_id: 88 } } },
      uni: { showLoading() {}, hideLoading() {}, showToast() {}, getStorageSync: () => '', setStorageSync() {}, switchTab() {}, redirectTo: r => redirects.push(r) } })
    vm.runInContext(checkoutSource.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), ctx)
    const s = ctx.component.data()
    for (const [n, fn] of Object.entries(ctx.component.methods)) s[n] = fn.bind(s)
    for (const [n, fn] of Object.entries(ctx.component.computed || {})) Object.defineProperty(s, n, { configurable: true, enumerable: true, get: () => fn.call(s) })
    s.tryPrefillFromLocal = () => {}
    return { ctx, s }
  }

  // 1) 空参数场景仍回退需求提交
  const emptyScene = mkCtx()
  emptyScene.ctx.component.onLoad.call(emptyScene.s, { from: 'scene', sceneId: '1' })
  assert.match(redirects[0].url, /^\/pages\/scene\/request/)

  // 2) quoteId 仍进 SC3 confirm 页
  redirects.length = 0
  mkCtx().ctx.component.onLoad.call(mkCtx().s, { quoteId: '15' })
  assert.match(redirects[0].url, /^\/pages\/scene\/confirm\?quoteId=15/)

  // 3) 带选型 items 进入 checkout 不重定向，算总价支持 qty 并提交真实订单
  redirects.length = 0
  const { ctx, s } = mkCtx()
  ctx.component.onLoad.call(s, {
    items: encodeURIComponent(JSON.stringify([{ product_id: 99, name: 'G1 人形', price: '2800', qty: 2, min_rent_days: 5 }])),
    rent_days: '5', type: 'rent', from: 'scene', sceneId: '1', sceneRevisionId: '9', scene: encodeURIComponent('商演展厅'), startDate: '2026-12-01',
  })
  assert.equal(redirects.length, 0)
  assert.equal(s.product.endDate, '2026-12-06')
  assert.equal(s.productTotal, 28000)
  s.changeMultiItemQty(0, 1)
  assert.equal(s.multiItems[0].qty, 3)
  assert.equal(s.productTotal, 42000)
  s.form = { name: '李四', phone: '13900000000', city: '北京', address: '朝阳区', email: '' }
  await s.submitOrder()
  assert.equal(createPayloads[0].from, 'scene')
  assert.equal(createPayloads[0].scene_id, '1')
  assert.equal(createPayloads[0].scene_revision_id, 9)
  assert.equal(createPayloads[0].items[0].qty, 3)
  assert.equal(createPayloads[0].items[0].rent_days, 5)
  assert.equal(createPayloads[0].startDate, '2026-12-01')
  assert.equal(createPayloads[0].endDate, '2026-12-06')
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

test('详情缺产品协议与非法价格不进入交易；日租兜底/max_rent_days/库存上限与 checkout 交互租期', async () => {
  const { state } = mountDetail(async () => detail([{ ...product, prices: {} }, { ...product, id: 2, type: 'booking' }]))
  await state.reload(); assert.equal(state.filteredProducts.length, 0); assert.equal(state.scheme.pains.length, 0)
  const missing = mountDetail(async () => ({ matched: true, scheme: {} }))
  await missing.state.reload(); assert.match(missing.state.loadError, /数据已更新/)
  for (const invalid of ['', ' ', 'NaN', '-1']) assert.equal(state.mapCatalogProduct({ ...product, prices: { regular: { price: invalid } } }), null)
  assert.equal(state.mapCatalogProduct({ ...product, prices: { regular: { price: 0 } } }).price, '0')
  const merchantFallback = state.mapCatalogProduct({
    ...product,
    prices: { final: { price: 0 } },
    raas_meta: { daily_rent_yuan: '1680.00', min_rent_days: 3, max_rent_days: 10, fulfillment_type: 'rent', rentable_stock: 2 },
  })
  assert.equal(merchantFallback.price, '1680')
  assert.equal(merchantFallback.maxRentDays, 10)
  assert.equal(merchantFallback.rentableStock, 2)

  // maxRentDays 与 rentableStock 上限拦截
  const capped = mountDetail(async () => ({
    matched: true, name: '受限场景', description: '', banner: null, scene_revision_id: 2,
    scheme: { robots: [99] },
    products: [{
      ...product,
      prices: { final: { price: 1680 } },
      raas_meta: { daily_rent_yuan: '1680.00', min_rent_days: 3, max_rent_days: 10, fulfillment_type: 'rent', rentable_stock: 2 },
    }],
  }))
  await capped.state.reload()
  capped.state.toggleMachine(capped.state.filteredProducts[0])
  capped.state.selectRentDays(15)
  assert.equal(capped.state.rentDays, 3) // 超过 max_rent_days=10 时拒绝切换
  capped.state.changeMachineQty(capped.state.filteredProducts[0], 1)
  capped.state.changeMachineQty(capped.state.filteredProducts[0], 1) // 第 3 台超出 rentable_stock=2
  assert.equal(capped.state.selectedMachines[0].qty, 2)
})

test('场景咨询只进入统一需求表单，不创建零元订单或伪造已受理，支持 mode 参数并清理废弃留资弹窗', async () => {
  const { state, calls } = mountDetail(async () => detail([product]))
  await state.reload()
  assert.equal(state.showLeadForm, undefined); assert.equal(state.showLeadSuccess, undefined)
  state.onConsult(); state.openService()
  assert.equal(calls.length, 2)
  assert.equal(calls.filter(c => c.type === 'custom').length, 0)
  assert.equal(calls[0].payload.scene_id, 1); assert.equal(calls[1].payload.scene_id, 1)
  assert.match(calls[0].url, /\/pages\/scene\/request/)
  const customMode = mountDetail(async () => detail([product]), null, { sceneId: '1', mode: 'custom' })
  assert.equal(customMode.state.sceneId, 1); assert.equal(customMode.state.activeMode, 'custom')
})

test('首页从真实快照更新，接口失败不保留旧卡片或假咨询人数', async () => {
  const file = await readFile(new URL('../../pages/home/index.vue', import.meta.url), 'utf8')
  const script = file.match(/<script>([\s\S]*?)<\/script>/)[1]
  let fail = false
  const context = vm.createContext({ StateView: {}, CustomTabBar: {}, EcoCenter: {}, SmIcon: {}, sceneProducts: [row(1, { is_hot: false })],
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
    const context = vm.createContext({ TOKEN_KEY: 'token', SmIcon: {},
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
  const context = vm.createContext({ Jineng: {}, Peijian: {}, SearchInput: {}, StateView: {}, SmIcon: {}, BASE_URL: 'https://qa.invalid',
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
  assert.equal(params.get('scene'), null); assert.equal(params.get('from'), null); assert.equal(params.get('sceneId'), null)
  assert.equal(params.get('type'), 'rent')
  assert.equal(state.selectedPlanItems[0].price, 20)

  // 将 +方案 生成的 query 参数直接送入确认订单页，验证不会被重定向且提交标准多机租用订单
  const checkoutSource = (await readFile(new URL('../../pages/order/checkout.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  const redirects = [], createdPayloads = []
  const checkoutCtx = vm.createContext({ TOKEN_KEY: 'token', SmIcon: {},
    tobOrderApi: { create: async input => { createdPayloads.push(input); return { success: true, order_id: 77 } } },
    uni: { showLoading() {}, hideLoading() {}, showToast() {}, getStorageSync: () => '', setStorageSync() {}, switchTab() {}, redirectTo: input => redirects.push(input) } })
  vm.runInContext(checkoutSource.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), checkoutCtx)
  const checkoutState = checkoutCtx.component.data()
  for (const [name, fn] of Object.entries(checkoutCtx.component.methods)) checkoutState[name] = fn.bind(checkoutState)
  for (const [name, fn] of Object.entries(checkoutCtx.component.computed || {})) Object.defineProperty(checkoutState, name, { configurable: true, enumerable: true, get: () => fn.call(checkoutState) })
  checkoutState.tryPrefillFromLocal = () => {}
  const queryObj = Object.fromEntries(Array.from(params.entries()).map(([k, v]) => [k, encodeURIComponent(v)]))
  checkoutCtx.component.onLoad.call(checkoutState, queryObj)
  assert.equal(redirects.length, 0)
  assert.equal(checkoutState.multiItems.length, 1); assert.equal(checkoutState.rentDays, 1)
  checkoutState.selectCheckoutRentDays(7)
  assert.equal(checkoutState.rentDays, 7)
  assert.equal(checkoutState.product.rentPeriod, '7天')
  checkoutState.selectCheckoutRentDays(1)
  checkoutState.form = { name: '张三', phone: '13800000000', city: '上海', address: '浦东', email: '' }
  await checkoutState.submitOrder()
  assert.equal(createdPayloads.length, 1)
  assert.equal(createdPayloads[0].type, 'rent')
  assert.equal(createdPayloads[0].scene, ''); assert.equal(createdPayloads[0].from, ''); assert.equal(createdPayloads[0].scene_id, null)
  assert.equal(createdPayloads[0].items.length, 1); assert.equal(createdPayloads[0].items[0].product_id, 11)
  assert.equal(createdPayloads[0].items[0].rent_days, 1)
})

test('AI 页交给方案专家直接进入统一需求页，不再无 sceneId 跳转详情报错', async () => {
  const aiSource = (await readFile(new URL('../../pages/ai/index.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  const requests = [], events = []
  const aiCtx = vm.createContext({ CustomTabBar: {}, summonApi: {}, summonStream: {}, aiConversationApi: {}, TOKEN_KEY: 't', CUSTOMER_KEY: 'c',
    trackAiEvent: (name, payload) => events.push({ name, payload }), flushAiEvents: () => {},
    sceneDraftPayload: value => ({ ...value, scene_id: value.sceneId || null, original_text: value.text || '' }),
    openSceneRequest: payload => requests.push(payload),
    createVoiceSession() {}, isVoiceSupported: () => false, isRealtime: () => false, AUTO_SEND_AFTER_ASR: false, ERR: {}, ERR_TEXT: {},
    createTtsSession() {}, TTS_ERR: {}, TTS_ERR_TEXT: {} })
  vm.runInContext(aiSource.replace(/^import\b[\s\S]*?from\s+['"][^'"]+['"];?/gm, '').replace('export default', 'globalThis.component ='), aiCtx)
  const aiState = aiCtx.component.data()
  aiCtx.component.methods.openCustomPlan.call(aiState, '需要2台展厅迎宾机器人')
  assert.equal(requests.length, 1)
  assert.equal(requests[0].source, 'ai')
  assert.equal(requests[0].original_text, '需要2台展厅迎宾机器人')
  assert.equal(events[0].name, 'ai_custom_plan_click')
})

test('订单列表与详情页联调：rental_info 租期不误回退 created_at，详情页刷新完整收货/履约信息与登录后游客单取消', async () => {
  const orderListSrc = (await readFile(new URL('../../pages/order/index.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  const orderCtx = vm.createContext({ CustomTabBar: {}, StateView: {}, SmIcon: {}, orderApi: {}, sceneStatusLabel: s => s, uni: { navigateTo() {} } })
  vm.runInContext(orderListSrc.replace(/^[ \t]*import\b[\s\S]*?from\s+['"][^'"]+['"];?/gm, '').replace('export default', 'globalThis.component ='), orderCtx)
  const listState = orderCtx.component.data()
  const mappedStandardRent = orderCtx.component.methods.mapApiOrder.call(listState, {
    id: 501,
    increment_id: 'SO501',
    is_guest: true,
    status: 'pending',
    order_type: 'rent',
    created_at: '2026-11-26 10:00:00',
    grand_total: 4200,
    items_qty: 2,
    rental_info: { rent_days: 3, start_date: '2026-12-01', end_date: '2026-12-04' },
    items: [{ product_id: 11, name: 'G1【租赁】（租期3天）', qty_ordered: 2 }],
  })
  assert.equal(mappedStandardRent.duration, '2026-12-01 ~ 2026-12-04')
  assert.equal(mappedStandardRent.createdAt, '2026-11-26 10:00:00')
  assert.equal(mappedStandardRent.isGuest, true)

  const mappedBuy = orderCtx.component.methods.mapApiOrder.call(listState, {
    id: 502,
    increment_id: 'SO502',
    status: 'pending',
    order_type: 'buy',
    created_at: '2026-11-26 10:00:00',
    grand_total: 9000,
    items: [{ product_id: 12, name: 'Go2 购买版', qty_ordered: 1 }],
  })
  assert.equal(mappedBuy.duration, '')

  // 详情页：有缓存首屏后仍拉最新服务端详情（通过 getOrderDetail 失败时回退 getGuestOrders({ id })），并识别 fulfillment + timeline + shippingAddress
  const detailSrc = (await readFile(new URL('../../pages/order/order-detail.vue', import.meta.url), 'utf8')).match(/<script>([\s\S]*?)<\/script>/)[1]
  const storage = new Map([['token', 'user-jwt'], ['orderDetailData', mappedStandardRent]])
  const cancelCalls = []
  const detailCtx = vm.createContext({
    TOKEN_KEY: 'token', StateView: {}, NavBar: {}, SmIcon: {}, ticketApi: {}, invoiceApi: {}, reviewApi: {},
    orderApi: {
      getOrderDetail: async () => { throw new Error('404 not owned') },
      getGuestOrders: async params => ({
        detail: {
          id: params.id,
          increment_id: 'SO501',
          is_guest: true,
          status: 'processing',
          order_type: 'rent',
          created_at: '2026-11-26 10:00:00',
          grand_total: 4200,
          items_qty: 2,
          rental_info: { rent_days: 3, start_date: '2026-12-01', end_date: '2026-12-04' },
          shipping_address: { first_name: '王', last_name: '五', phone: '13800001111', state: '上海', city: '浦东', address: '张江' },
          fulfillment: { status: 'shipped', carrier: '顺丰', tracking_no: 'SF999', device_sn: 'SN-1' },
          timeline: [{ title: '商家已出库发货', time: '2026-11-26 11:00:00', done: true, isLast: true }],
          items: [{ id: 1, product_id: 11, name: 'G1【租赁】', qty_ordered: 2, total: 4200 }],
        },
      }),
      cancelOrder: async id => { cancelCalls.push({ kind: 'auth', id }); return { message: 'ok' } },
      cancelGuestOrder: async id => { cancelCalls.push({ kind: 'guest', id }); return { message: 'ok' } },
    },
    uni: {
      getStorageSync: k => storage.get(k),
      setStorageSync: (k, v) => storage.set(k, v),
      removeStorageSync: k => storage.delete(k),
      showModal: ({ success }) => success && success({ confirm: true }),
      showToast() {},
      navigateBack() {},
      redirectTo() {},
      navigateTo() {},
    },
  })
  vm.runInContext(detailSrc.replace(/^[ \t]*import\b[\s\S]*?from\s+['"][^'"]+['"];?/gm, '').replace('export default', 'globalThis.component ='), detailCtx)
  const detailState = detailCtx.component.data()
  for (const [k, fn] of Object.entries(detailCtx.component.methods)) detailState[k] = fn.bind(detailState)
  await detailState.loadOrder(501)
  assert.equal(detailState.order.status, 'confirm')
  assert.equal(detailState.order.shippingAddress.fullAddress, '上海 浦东 张江')
  assert.equal(detailState.order.fulfillment.trackingNo, 'SF999')
  assert.equal(detailState.order.timeline.length, 1)
  detailState.cancelOrder()
  await new Promise(r => setTimeout(r, 10))
  assert.deepEqual(cancelCalls, [{ kind: 'guest', id: 501 }])
  assert.ok(storage.get('orderNeedRefresh'))
})
