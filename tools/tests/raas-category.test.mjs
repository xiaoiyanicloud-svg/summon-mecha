import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'
import {
  normalizeProductPricing,
  pickBaseImage,
  pickGalleryImages,
  catalogFallbackImage,
} from '../../utils/product-catalog.js'

async function loadRobotComponent(extraGlobals = {}) {
  const vue = await readFile(new URL('../../pages/robot/index.vue', import.meta.url), 'utf8')
  const scriptMatch = vue.match(/<script>([\s\S]*?)<\/script>/)
  assert.ok(scriptMatch, 'pages/robot/index.vue 必须包含 <script> 块')
  const storage = new Map()
  const context = vm.createContext({
    Jineng: {},
    Peijian: {},
    StateView: {},
    SmIcon: {},
    BASE_URL: 'https://qa.invalid',
    TOKEN_KEY: 'token',
    normalizeProductPricing,
    pickBaseImage,
    pickGalleryImages,
    catalogFallbackImage,
    sceneProducts: [],
    categoryMap: {},
    loadBackendScenes: async () => [],
    uni: {
      navigateTo() {},
      switchTab() {},
      showToast() {},
      getStorageSync: k => storage.get(k),
      setStorageSync: (k, v) => storage.set(k, v),
      removeStorageSync: k => storage.delete(k),
      getImageInfo() {},
    },
    ...extraGlobals,
  })
  const cleanScript = scriptMatch[1].replace(/^import .*$/gm, '').replace('export default', 'globalThis.component =')
  vm.runInContext(cleanScript, context)
  return { vue, component: context.component, context, storage }
}

function bindComponentState(component, overrides = {}) {
  const state = Object.assign(component.data(), overrides)
  for (const [k, fn] of Object.entries(component.methods || {})) {
    state[k] = fn.bind(state)
  }
  const computedValues = {}
  for (const [k, fn] of Object.entries(component.computed || {})) {
    Object.defineProperty(state, k, {
      configurable: true,
      enumerable: true,
      get: () => fn.call(state),
    })
    computedValues[k] = () => fn.call(state)
  }
  return state
}

test('分类页模板已完成 4 主 Tab（租售 | 场景 | 技能 | 配件）重构、去 TOB 与布局统一', async () => {
  const vue = await readFile(new URL('../../pages/robot/index.vue', import.meta.url), 'utf8')
  assert.match(vue, /@tap="switchTab\('tob'\)">租售<\/text>/)
  assert.doesNotMatch(vue, /@tap="switchTab\('tob'\)">租售\s*TOB<\/text>/)
  assert.doesNotMatch(vue, /租售\s*TOB/)
  assert.doesNotMatch(vue, /@tap="switchTab\('rent'\)">租用<\/text>/)
  assert.doesNotMatch(vue, /@tap="switchTab\('buy'\)">购买<\/text>/)
  assert.match(vue, /<!-- ===== 搜索框\(租售 \/ 场景\) ===== -->\s*<view class="search-bar" v-show="currentTab === 'tob'">/)
  assert.match(vue, /<view class="tob-toolbar-head"[\s\S]*?<view class="tob-subbar">/)
  assert.match(vue, /v-model="sceneSearchQ"/)
  assert.match(vue, /title:\s*'租售・平台保障'/)
  // 禁止虚假划线价、失效 coupons 和虚构免邮承诺
  assert.doesNotMatch(vue, /selectedProduct\.price[^\n]*\* 1\.2/)
  assert.doesNotMatch(vue, /\bcoupons\b/)
  assert.doesNotMatch(vue, /全国包邮/)
  assert.doesNotMatch(vue, /48小时内发货/)
})

test('utils/api.js 已导出 brandApi.list 且指向 /api/brands', async () => {
  const api = await readFile(new URL('../../utils/api.js', import.meta.url), 'utf8')
  assert.match(api, /export const brandApi = \{\s*list: \(\) => http\.get\('\/api\/brands'/)
})

test('租售 TOB 合并后禁止跨品牌/跨形态筛出（all 漏斗修复），且切子模式保留筛选条件', async () => {
  const { component } = await loadRobotComponent()
  const state = bindComponentState(component)

  state.applyCatalog({
    brands: [
      { code: 'yushu', name: '宇树', name_en: 'Unitree', aliases: ['宇树', 'G1'], is_hot: true, status: true },
      { code: 'zhiyuan', name: '智元', name_en: 'Agibot', aliases: ['智元'], is_hot: true, status: true },
      { code: 'disabled_brand', name: '已停用', status: false },
    ],
    robot_types: [
      { code: 'humanoid', label: '人形' },
      { code: 'quadruped', label: '四足' },
    ],
    rent: [
      {
        id: 101,
        name: '宇树 G1 教育版',
        sku: 'UNI-G1-EDU',
        prices: { final: { price: 2800 }, regular: { price: 3200 } },
        brand: { code: 'yushu', name: '宇树', name_en: 'Unitree', aliases: ['宇树', 'G1'] },
        robot_type: { code: 'humanoid', label: '人形' },
        raas_meta: { models_count: 2, active_models: 1, comm_channels: ['DDS'], min_rent_days: 3, deposit_yuan: '10000.00' },
        sales: { total: 12 },
      },
      {
        id: 102,
        name: '未归类测试设备',
        sku: 'RAW-999',
        prices: { final: { price: 500 } },
        sales: { total: 2 },
      },
    ],
    buy: [
      {
        id: 201,
        name: '宇树 Go2 四足整机',
        sku: 'UNI-GO2-BUY',
        prices: { final: { price: 16000 } },
        brand: { code: 'yushu', name: '宇树', name_en: 'Unitree', aliases: ['Go2'] },
        robot_type: { code: 'quadruped', label: '四足' },
        sales: { total: 20 },
      },
    ],
    accessory: [],
    skill: [],
  })

  // 停用品牌不应出现在前台品牌列表
  assert.deepEqual(Array.from(state.brandList, b => b.code), ['yushu', 'zhiyuan'])
  // 默认 all 子模式返回 租赁+购买 全部 3 台
  assert.equal(state.filteredTobList.length, 3)

  // 选择「宇树」品牌：未归类设备 (brand='') 绝对不能串台进来
  state.selectBrand('yushu')
  assert.deepEqual(Array.from(state.filteredTobList, x => x.sku), ['UNI-G1-EDU', 'UNI-GO2-BUY'])

  // 切换租赁/购买子模式时必须保留当前选中的品牌与形态过滤条件
  state.setTobSubTab('rent')
  assert.equal(state.currentBrand, 'yushu')
  assert.deepEqual(Array.from(state.filteredTobList, x => x.sku), ['UNI-G1-EDU'])

  state.setTobSubTab('buy')
  assert.equal(state.currentBrand, 'yushu')
  assert.deepEqual(Array.from(state.filteredTobList, x => x.sku), ['UNI-GO2-BUY'])

  // 切回 all + 按人形形态过滤：未归类与四足不应出现
  state.setTobSubTab('all')
  state.selectType('humanoid')
  assert.equal(state.currentTypeLabel, '人形形态')
  assert.deepEqual(Array.from(state.filteredTobList, x => x.sku), ['UNI-G1-EDU'])

  // 多字段搜索 SKU / 别名（清掉类型限制，测 SKU/英文品牌匹配）
  state.selectBrand('all')
  state.selectType('all')
  assert.equal(state.currentTypeLabel, '全部形态')
  state.searchQ = 'UNI-GO2'
  assert.deepEqual(Array.from(state.filteredTobList, x => x.sku), ['UNI-GO2-BUY'])
  state.searchQ = 'Unitree'
  assert.equal(state.filteredTobList.length, 2)

  // 销量优先排序
  state.searchQ = ''
  state.pickSort('sales_desc')
  assert.deepEqual(Array.from(state.filteredTobList, x => x.id), [201, 101, 102])
})

test('goDetail、goEnterprise、enterCustomPlanMode 与历史 storage(robotTab=buy/rent) 完全兼容', async () => {
  const { component, storage } = await loadRobotComponent()
  const state = bindComponentState(component)
  state.applyCatalog({
    brands: [],
    rent: [{ id: 11, name: '宇树 H1', prices: { final: { price: 3000 } } }],
    buy: [{ id: 22, name: '智元 A2 售卖版', prices: { final: { price: 99000 } } }],
    accessory: [],
    skill: [],
  })

  // 在 tob/all 下点开购买商品，actionType 必须是 'buy'，不能错成 'tob'
  state.currentTab = 'tob'
  state.tobSubTab = 'all'
  state.loadRelatedProducts = () => {}
  state.goDetail(state.buyCatalog[0])
  assert.equal(state.actionType, 'buy')

  // 点击企业部署在 buy 子模式下进入批量采购模式
  state.tobSubTab = 'buy'
  state.goEnterprise()
  assert.equal(state.enterpriseForm.mode, 'buy')

  // 从 buy/all 点击 +方案 自动进入 tob + rent 子模式
  state.loadCustomPlans = () => {}
  state.enterCustomPlanMode()
  assert.equal(state.currentTab, 'tob')
  assert.equal(state.tobSubTab, 'rent')

  // 历史页面写入 robotTab='buy' 仍能精准路由到 租售 TOB -> 购买
  storage.set('robotTab', 'buy')
  state.refreshWishlist = () => {}
  state.loadCatalog = async () => {}
  state.refreshScenes = () => {}
  state.hintShow = () => {}
  state.$nextTick = fn => fn()
  component.onShow.call(state)
  assert.equal(state.currentTab, 'tob')
  assert.equal(state.tobSubTab, 'buy')
  assert.equal(storage.get('robotTab'), undefined)
})

test('detailSpecs 注入真实品牌/形态/RaaS 元信息，sceneSearchQ 过滤场景，openPendingAiProduct 与 openRentModal 兼容', async () => {
  const { component, storage } = await loadRobotComponent({
    sceneProducts: [
      { id: 1, category: 'enterprise', sort: 10, name: '智慧园区导览', desc: '双足迎宾与导览服务', tags: ['室内', '语音'] },
      { id: 2, category: 'industry', sort: 5, name: '变电站自主巡检', desc: '全天候四足狗巡检', tags: ['红外', '防爆'] },
    ],
  })
  const state = bindComponentState(component)
  state.applyCatalog({
    brands: [],
    rent: [
      {
        id: 301,
        name: '宇树 G1 PRO',
        prices: { final: { price: 3500 } },
        brand: { code: 'yushu', name: '宇树' },
        robot_type: { code: 'humanoid', label: '人形' },
        raas_meta: { models_count: 3, active_models: 2, comm_channels: ['DDS', 'ROS2'], min_rent_days: 5, deposit_yuan: '8000.00' },
        custom_attributes: { sku: { name: 'SKU', value: 'U-301' }, compute: { name: '算力', value: '100 TOPS' } },
      },
      {
        id: 302,
        name: '未知型号装置',
        prices: { final: { price: 1000 } },
      },
    ],
    buy: [],
    accessory: [],
    skill: [],
  })

  // 验证 detailSpecs 注入真实结构化 RaaS 参数而不编造未知商品参数
  state.loadRelatedProducts = () => {}
  state.goDetail(state.rentCatalog[0])
  const specMap = Object.fromEntries(Array.from(state.detailSpecs, s => [s.name, s.value]))
  assert.equal(specMap['品牌'], '宇树')
  assert.equal(specMap['机器人形态'], '人形')
  assert.equal(specMap['平台机型库兼容'], '2 款机型')
  assert.equal(specMap['支持指令通道'], 'DDS / ROS2')
  assert.equal(specMap['最低起租天数'], '5 天')
  assert.equal(specMap['参考押金'], '¥8000.00')
  assert.equal(specMap['算力'], '100 TOPS')

  // openRentModal 对未分类机器人不报错且安全处理 productTypeIndex
  state.goDetail(state.rentCatalog[1])
  state.openRentModal()
  assert.equal(state.rentForm.productTypeIndex, 0)

  // 场景 Tab 搜索匹配名称、描述与标签
  state.switchTab('scene')
  assert.equal(state.filteredSceneList.length, 2)
  state.sceneSearchQ = '四足'
  assert.deepEqual(Array.from(state.filteredSceneList, s => s.id), [2])
  state.sceneSearchQ = '语音'
  assert.deepEqual(Array.from(state.filteredSceneList, s => s.id), [1])

  // openPendingAiProduct 按 tob + subTab 精确激活商品详情
  state.loadCatalog = async () => {}
  storage.set('aiOpenProduct', { id: 301 })
  await state.openPendingAiProduct()
  assert.equal(state.currentTab, 'tob')
  assert.equal(state.tobSubTab, 'rent')
  assert.equal(state.fromAi, true)
  assert.equal(state.selectedProduct.id, 301)
})

test('首页我要租用/热门品牌入口修正，与 checkout 未分类机器显示标准文案', async () => {
  const home = await readFile(new URL('../../pages/home/index.vue', import.meta.url), 'utf8')
  assert.match(home, /onRent\(\)\s*\{\s*uni\.setStorageSync\('robotTab', 'rent'\)/)
  assert.match(home, /goBrandBuy\(\)\s*\{\s*uni\.setStorageSync\('robotTab', 'tob'\)/)

  const checkout = await readFile(new URL('../../pages/order/checkout.vue', import.meta.url), 'utf8')
  assert.match(checkout, /productTypeLabel\(\)[\s\S]*?\|\| '标准'/)
  assert.doesNotMatch(checkout, /productTypeLabel\(\)[\s\S]*?\|\| '人形'/)
})

test('租购同款切换、min_rent_days 前置拦截、+方案起租天数自动抬升、筛选胶囊与 robotBrand 深链', async () => {
  const toasts = []
  const { component, storage } = await loadRobotComponent({
    uni: {
      navigateTo() {},
      switchTab() {},
      showToast: v => toasts.push(v && v.title ? v.title : ''),
      getStorageSync: k => storage.get(k),
      setStorageSync: (k, v) => storage.set(k, v),
      removeStorageSync: k => storage.delete(k),
      getImageInfo() {},
    },
  })
  const state = bindComponentState(component)
  state.applyCatalog({
    brands: [
      { code: 'yushu', name: '宇树', is_hot: true, status: true },
    ],
    rent: [
      {
        id: 401,
        sku: 'UNI-G1-RENT',
        name: '宇树 G1 双足机器（租赁版）',
        prices: { final: { price: 2800 } },
        brand: { code: 'yushu', name: '宇树' },
        robot_type: { code: 'humanoid', label: '人形' },
        raas_meta: {
          min_rent_days: 5,
          compatible_model_names: ['Unitree G1'],
          rental_plans: [{ name: '五日起租特惠' }],
          param_specs: [{ name: '续航时间', value: '4小时' }],
        },
      },
    ],
    buy: [
      {
        id: 402,
        sku: 'UNI-G1-BUY',
        name: '宇树 G1 双足机器（购买版）',
        prices: { final: { price: 99000 } },
        brand: { code: 'yushu', name: '宇树' },
        robot_type: { code: 'humanoid', label: '人形' },
      },
    ],
    accessory: [],
    skill: [],
  })

  // 1. robotBrand + robotType 深链直达
  storage.set('robotTab', 'rent')
  storage.set('robotBrand', 'yushu')
  storage.set('robotType', 'humanoid')
  state.refreshWishlist = () => {}
  state.loadCatalog = async () => {}
  state.refreshScenes = () => {}
  state.hintShow = () => {}
  state.$nextTick = fn => fn()
  component.onShow.call(state)
  assert.equal(state.currentTab, 'tob')
  assert.equal(state.tobSubTab, 'rent')
  assert.equal(state.currentBrand, 'yushu')
  assert.equal(state.currentType, 'humanoid')
  assert.equal(state.hasActiveTobFilters, true)
  assert.equal(state.currentBrandLabel, '宇树')

  // resetTobFilters 一键恢复全部
  state.resetTobFilters()
  assert.equal(state.hasActiveTobFilters, false)
  assert.equal(state.currentBrand, 'all')

  // 2. 同款租购快速切换 & 商户 param_specs/compatible_model_names 注入 detailSpecs
  state.loadRelatedProducts = () => {}
  state.goDetail(state.rentCatalog[0])
  assert.equal(state.siblingTobProduct.id, 402)
  const specMap = Object.fromEntries(Array.from(state.detailSpecs, s => [s.name, s.value]))
  assert.equal(specMap['适配机型'], 'Unitree G1')
  assert.equal(specMap['租期方案'], '五日起租特惠')
  assert.equal(specMap['续航时间'], '4小时')

  state.switchToSiblingTobProduct()
  assert.equal(state.selectedProduct.id, 402)
  assert.equal(state.actionType, 'buy')
  assert.equal(state.tobSubTab, 'buy')
  assert.equal(state.siblingTobProduct.id, 401)
  state.switchToSiblingTobProduct()
  assert.equal(state.actionType, 'rent')

  // 3. openRentModal 自动匹配 >= min_rent_days(5) 的首个选项 7天，且阻止选 1天/3天
  state.openRentModal()
  assert.equal(state.selectedProductMinDays, 5)
  assert.equal(state.rentPeriodOptions[state.rentForm.daysIndex], '7天')
  state.selectRentPeriod(0, '1天')
  assert.equal(state.rentPeriodOptions[state.rentForm.daysIndex], '7天')
  assert.match(toasts[toasts.length - 1], /最低起租 5 天/)

  // 4. +方案 选择最低起租 5 天的设备时自动抬升 planDays，若强行调低提交则拦下
  state.loadCustomPlans = () => {}
  state.enterCustomPlanMode()
  state.togglePlanItem(state.rentCatalog[0])
  assert.equal(state.planMinDaysRequired, 5)
  assert.equal(state.planDays, 5)
  state.planDays = 2
  state.reloadCatalog = async () => {}
  await state.submitCustomPlanOrder()
  assert.equal(state.planDays, 5)
  assert.match(toasts[toasts.length - 1], /最低起租 5 天/)
})
