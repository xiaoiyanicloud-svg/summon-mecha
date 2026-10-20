import { readFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import vm from 'node:vm'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

// 只读取源码，在独立 VM 中注入假接口复现现有行为；不访问网络、数据库和用户存储。
const root = fileURLToPath(new URL('../../', import.meta.url))
const before = process.argv.includes('--before')
const files = {
  data: 'components/jiqi/changjing-data.js',
  detail: 'pages/robot/fangan.vue',
  home: 'pages/home/index.vue',
  robot: 'pages/robot/index.vue',
  checkout: 'pages/order/checkout.vue',
  sceneApi: 'my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/SceneController.php',
  orderApi: 'my-bagisto-store/packages/Webkul/Shop/src/Http/Controllers/API/TobOrderController.php',
  admin: 'my-bagisto-store/packages/Webkul/Admin/src/Http/Controllers/Catalog/SceneController.php',
  request: 'my-bagisto-store/packages/Webkul/Admin/src/Http/Requests/SceneRequest.php',
  bouncer: 'my-bagisto-store/packages/Webkul/User/src/Http/Middleware/Bouncer.php',
  acl: 'my-bagisto-store/packages/Webkul/Admin/src/Config/acl.php',
  routes: 'my-bagisto-store/packages/Webkul/Admin/src/Routes/catalog-routes.php',
  seed: 'my-bagisto-store/database/seeders/SceneSeeder.php',
  orderRepository: 'my-bagisto-store/packages/Webkul/Sales/src/Repositories/OrderRepository.php'
}
const sources = Object.fromEntries(await Promise.all(Object.entries(files).map(async ([key, file]) => {
  if (before && !['bouncer', 'acl'].includes(key)) {
    return [key, await readFile(path.join(root, '.claude/backup/scene-sc0-20261020', file), 'utf8')]
  }
  return [key, await readFile(path.join(root, file), 'utf8')]
})))
const hashes = Object.fromEntries(Object.entries(sources).map(([key, source]) => [files[key], createHash('sha256').update(source).digest('hex')]))
const locate = (key, term) => {
  const offset = sources[key].indexOf(term)
  if (offset === -1) throw new Error(`找不到审计锚点 ${files[key]}: ${term}`)
  return { file: files[key], line: sources[key].slice(0, offset).split('\n').length, anchor: term }
}

function dataFixture() {
  let response = { data: [] }
  let now = 1_000_000
  let calls = 0
  const context = vm.createContext({
    sceneApi: { getScenes: async () => { calls++; if (response instanceof Error) throw response; return response } },
    BASE_URL: 'https://audit.invalid',
    Date: { now: () => now }
  })
  const source = sources.data.replace(/^import .*$/gm, '').replace(/\bexport\s+(?=(?:const|async function|function)\b)/g, '')
  vm.runInContext(`${source}\nglobalThis.audit = { sceneProducts, loadBackendScenes };`, context, { timeout: 1000 })
  return {
    ...context.audit,
    set: value => { response = value; now += 300_001 },
    calls: () => calls
  }
}

const detailContext = vm.createContext({})
const script = sources.detail.match(/<script>([\s\S]*?)<\/script>/)?.[1]
if (!script) throw new Error('场景详情页缺少 script')
vm.runInContext(script.replace(/^import .*$/gm, '').replace('export default', 'globalThis.component ='), detailContext, { timeout: 1000 })
const component = detailContext.component
const checks = []
function record(id, description, observed, details, refs) {
  checks.push({ id, description, defect_observed: Boolean(observed), details, refs })
}

{
  const f = dataFixture()
  f.set({ data: [{ id: 1, name: '仅此一个启用场景' }] })
  await f.loadBackendScenes()
  record('R01', '成功响应只有一个场景，未返回的本地卡片仍保留', f.sceneProducts.length > 1,
    { backend_count: 1, displayed_count: f.sceneProducts.length }, [locate('data', 'list.forEach')])
}
{
  const f = dataFixture()
  await f.loadBackendScenes()
  record('R02', '后端成功返回空数组，仍展示本地卡片', f.sceneProducts.length > 0,
    { backend_count: 0, displayed_count: f.sceneProducts.length }, [locate('data', '!list.length')])
}
{
  const f = dataFixture()
  f.set({ data: [{ id: 1, consult_count: 37, banner: '/old.png' }] })
  await f.loadBackendScenes()
  f.set({ data: [{ id: 1, consult_count: 0, banner: null }] })
  await f.loadBackendScenes()
  const scene = f.sceneProducts.find(item => item.id === 1)
  record('R03', '咨询数归零和清空图片不能覆盖旧值', scene.consultCount === 37 && scene.banner.endsWith('/old.png'),
    { returned_count: 0, displayed_count: scene.consultCount, cleared_banner_still_present: Boolean(scene.banner) },
    [locate('data', 'if (s.consult_count || s.consultCount)'), locate('data', 'if (s.banner)')])
}
{
  const f = dataFixture()
  f.set({ data: '错误格式' })
  await f.loadBackendScenes()
  await f.loadBackendScenes()
  record('R04', '格式错误响应也进入五分钟成功缓存', f.calls() === 1,
    { consecutive_load_calls: 2, backend_calls: f.calls() }, [locate('data', '_scenesLoadedAt = Date.now()')])
}
for (const [id, sceneRobots, rentCatalog, reason] of [
  ['R05', [], [{ id: 999, type: 'simple' }], '未配置机型'],
  ['R06', [999], [], '目录请求失败'],
  ['R07', [999], [{ id: 999, type: 'booking' }], '配置的机型全被 booking 过滤'],
  ['R08', [888], [{ id: 999, type: 'simple' }], '配置的商品已不在目录']
]) {
  const state = component.data()
  state.sceneRobots = sceneRobots
  state.rentCatalog = rentCatalog
  const result = component.computed.filteredProducts.call(state)
  record(id, `${reason}时，适用机型回退为可操作的模拟商品`, result === state.allProducts && result.length > 0,
    { mock_count: result.length, configured_ids: sceneRobots }, [locate('detail', 'return this.allProducts')])
}
{
  const f = dataFixture()
  f.set(new Error('注入网络失败'))
  await f.loadBackendScenes()
  record('R09', '接口异常被静默捕获，本地卡片继续展示', f.sceneProducts.length > 0,
    { injected_failure: true, displayed_count: f.sceneProducts.length }, [locate('data', '// 接口失败保持本地 mock,不打扰')])
}

const staticFindings = [
  ['S01', '场景详情按主键读取，没有与列表一致的启用条件', 'sceneApi', '$scene = Scene::find($id);'],
  ['S02', '非技能单允许正数客户端价格覆盖目录价', 'orderApi', ': $line[\'price\'];'],
  ['S03', '定制线索依赖 LEAD-CUSTOM 商品', 'orderApi', "findByField('sku', 'LEAD-CUSTOM')"],
  ['S04', '定制需求截断拼入商品名', 'orderApi', "mb_substr((string) $request->input('demand'), 0, 80)"],
  ['S05', '完整 TOB 请求进入 debug 日志', 'orderApi', "Log::debug('TOB_INPUT', $request->all())"],
  ['S06', '前端自定义方案只存本机', 'robot', "uni.setStorageSync('customPlans', list)"],
  ['S07', '客服入口仅 toast', 'detail', "title: '客服功能'"],
  ['S08', '案例入口仅 toast', 'detail', '查看案例：'],
  ['S09', '企微二维码为占位', 'detail', '企微咨询码（占位）'],
  ['S10', '后台可直接改咨询人数', 'admin', "$scene->consult_count = (int) $request->input('consult_count', 0)"],
  ['S11', '后台新建场景固定企业商用分类', 'admin', "'category'      => 'enterprise'"],
  ['S12', '无匹配 ACL 路由时 Bouncer 没有逐动作拒绝分支', 'bouncer', 'if (isset($roles[Route::currentRouteName()]))'],
  ['S13', '订单仓储在回滚及递归重试之外还有 finally commit', 'orderRepository', 'DB::commit();'],
  ['S14', '重复执行 SceneSeeder 重置图片和启用状态', 'seed', '$scene->banner_path = null;']
].map(([id, description, key, anchor]) => ({ id, description, evidence_type: 'source_only', refs: [locate(key, anchor)] }))

const result = {
  schema_version: 1,
  captured_at: new Date().toISOString(),
  scope: before ? '修复前备份的历史缺陷复现，不代表当前代码状态' : '源码及注入假接口的独立 VM 行为复现，不代表数据库、HTTP、浏览器或真机验收',
  side_effects: { database: false, network: false, user_storage: false, source_mutations: false },
  node_version: process.version,
  source_sha256: hashes,
  checks,
  reproduced_count: checks.filter(check => check.defect_observed).length,
  static_findings: staticFindings
}
process.stdout.write(`${JSON.stringify(result, null, 2)}\n`)
if (checks.some(check => !check.defect_observed)) process.exitCode = 1
