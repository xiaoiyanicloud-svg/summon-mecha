import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'

// 独立组件浏览器验证，不冒充 HBuilderX 的完整 H5/小程序编译。
// 依赖放忽略目录：npm install --prefix <QA目录> --no-save --no-package-lock @vue/compiler-sfc@3.5.13 vue@3.5.13 playwright-core@1.48.2
const qaDir = path.resolve(process.env.SCENE_QA_DIR || '.claude/backup/scene-sc0-20261020/qa')
const require = createRequire(path.join(qaDir, 'package.json'))
const { parse, compileTemplate } = require('@vue/compiler-sfc')
const { parse: parseJavaScript } = require('@babel/parser')
const { chromium } = require('playwright-core')
const out = path.resolve('docs/scenes/sc1-sc5-evidence/catalog')
fs.mkdirSync(out, { recursive: true })
const files = ['pages/home/index.vue', 'pages/robot/index.vue', 'pages/robot/fangan.vue', 'pages/home/rmfangan.vue', 'pages/order/checkout.vue', 'pages/ai/index.vue', 'components/StateView.vue']
const sources = new Map()
const customTags = ['view','text','image','scroll-view','swiper','swiper-item','input','textarea','button','picker','video']
function withoutImports(source) {
  const ast = parseJavaScript(source, { sourceType: 'module' })
  for (const node of [...ast.program.body].reverse()) {
    if (node.type === 'ImportDeclaration') source = source.slice(0, node.start) + source.slice(node.end)
  }
  return source
}
for (const filename of files) {
  const source = fs.readFileSync(filename, 'utf8')
  const { descriptor, errors } = parse(source, { filename })
  if (errors.length) throw new Error(filename + ': ' + errors.join('; '))
  const template = compileTemplate({ filename, source: descriptor.template.content, id: 'scene-qa', compilerOptions: { isCustomElement: tag => customTags.includes(tag), mode: 'function' } })
  if (template.errors.length) throw new Error(filename + ': ' + template.errors.join('; '))
  // 只语法编译脚本，不执行个人页面里的网络/存储逻辑。
  new Function(withoutImports(descriptor.script.content).replace('export default', 'return'))
  sources.set(filename, { descriptor, render: template.code })
}
const helperSource = fs.readFileSync('utils/scene-catalog.js', 'utf8')
const { sceneId, configuredSceneProducts, normalizeSceneImage } = await import('data:text/javascript;base64,' + Buffer.from(helperSource).toString('base64'))
const browser = await chromium.launch({ executablePath: process.env.SCENE_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true })
const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 })
const errors = []
page.on('pageerror', error => errors.push(error.message))
await page.route('**/*', route => route.abort())
try {
  const detail = sources.get('pages/robot/fangan.vue'), state = sources.get('components/StateView.vue')
  const styles = [detail, state].flatMap(item => item.descriptor.styles.map(style => style.content)).join('\n').replace(/([\d.]+)rpx/g, (_, number) => Number(number) / 2 + 'px')
  await page.setContent('<style>body{margin:0}view{display:block}text{display:inline}scroll-view{display:block;overflow:auto}image{display:block}' + styles + '</style><div id="app"></div>')
  await page.addScriptTag({ path: require.resolve('vue/dist/vue.global.prod.js') })
  await page.evaluate(({ detail, state, helpers }) => {
    const get = (source, args, values) => new Function(...args, source.replace('export default', 'return'))(...values)
    const StateView = get(state.script, [], [])
    StateView.render = new Function('Vue', state.render)(Vue)
    window.sceneResult = { matched: true, name: '展会品牌活动', description: '提供迎宾与互动服务，现场条件和档期需确认。', banner: null,
      scheme: { robots: [99], pains: [], benefits: [], scenes: [] }, products: [{ id: 99, name: '目录机型 A', type: 'simple', prices: { regular: { price: 1200 } } }] }
    window.sceneFail = false
    window.navigation = []
    window.sceneDraftPayload = value => ({ ...value, scene_id: value.sceneId })
    window.openSceneRequest = value => window.navigation.push({ url: '/pages/scene/request?sceneId=' + value.scene_id })
    const helperFunctions = helpers.map(source => new Function('return (' + source + ')')())
    window.sceneId = helperFunctions[0]
    const component = get(detail.script, ['StateView','sceneApi','tobOrderApi','BASE_URL','uni','sceneId','configuredSceneProducts','normalizeSceneImage'],
      [StateView, { getSceneDetail: async () => { if (window.sceneFail) throw new Error('服务暂不可用，请重试'); return window.sceneResult } }, { create: async () => ({ success: true }) }, 'https://qa.invalid',
        { navigateTo: data => window.navigation.push(data), showToast: () => {}, navigateBack: () => {} },
        ...helperFunctions])
    component.render = new Function('Vue', detail.render)(Vue)
    component.created = function () { component.onLoad.call(this, { sceneId: 1 }); component.onShow.call(this) }
    window.sceneVm = Vue.createApp(component).mount('#app')
  }, {
    detail: { script: withoutImports(detail.descriptor.script.content), render: detail.render },
    state: { script: withoutImports(state.descriptor.script.content), render: state.render },
    helpers: [sceneId, configuredSceneProducts, normalizeSceneImage].map(fn => fn.toString())
  })
  await page.getByText('目录机型 A', { exact: true }).waitFor()
  await page.screenshot({ path: path.join(out, 'detail-ready.png'), fullPage: true })
  await page.locator('.product-select-btn').dispatchEvent('tap')
  await page.getByText('提交选型意向 · 获取正式报价', { exact: true }).dispatchEvent('tap')
  if (!(await page.evaluate(() => window.navigation[0]?.url.includes('sceneId=1')))) throw new Error('场景 ID 未进入需求表单')
  await page.getByText('方案定制', { exact: true }).dispatchEvent('tap')
  await page.getByText('具体服务范围、交付条件及报价需根据您的需求确认。', { exact: true }).waitFor()
  await page.evaluate(async () => { window.sceneFail = true; await window.sceneVm.reload() })
  await page.getByText('服务暂不可用，请重试', { exact: true }).waitFor()
  if (await page.locator('.product-card').count()) throw new Error('错误态仍显示商品')
  await page.screenshot({ path: path.join(out, 'detail-error.png'), fullPage: true })
  await page.evaluate(async () => { window.sceneFail = false; window.sceneResult.products = []; window.sceneResult.scheme.robots = []; window.sceneVm.activeMode = 'rent'; await window.sceneVm.reload() })
  await page.getByText('当前未配置可租机型', { exact: true }).waitFor()
  await page.screenshot({ path: path.join(out, 'detail-empty.png'), fullPage: true })
  if (errors.length) throw new Error(errors.join('; '))
  const report = { compiled_sfc: files, browser: 'Chromium headless / Vue 3.5.13', scope: '真实 Vue 组件 + 隔离假接口；不是 uni-app 完整构建或实际后端联调', checks: ['正常目录','场景 ID 进入需求表单','定制空内容说明','错误态无商品','无配置空态'], errors }
  fs.writeFileSync(path.join(out, 'ui-verification.json'), JSON.stringify(report, null, 2) + '\n')
  process.stdout.write(JSON.stringify(report, null, 2) + '\n')
} finally { await browser.close() }
