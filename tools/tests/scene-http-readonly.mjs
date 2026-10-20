// 在有业务数据库的环境执行；仅公开 GET，不创建场景、客户或订单。
// node tools/tests/scene-http-readonly.mjs http://127.0.0.1:8000
const base = new URL(process.argv[2] || 'http://127.0.0.1:8000')
if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password) throw new Error('请传入不带凭证的后端 HTTP 地址')
async function get(route) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), 8000)
  try {
    const response = await fetch(new URL(route, base), { headers: { Accept: 'application/json' }, signal: controller.signal, redirect: 'error' })
    return { status: response.status, body: await response.json() }
  } finally { clearTimeout(timer) }
}
const results = []
function check(name, condition) { results.push({ name, pass: Boolean(condition) }) }
const list = await get('/api/scenes')
check('列表返回合法快照', list.status === 200 && Array.isArray(list.body.data))
if (Array.isArray(list.body.data)) {
  check('列表无虚构咨询人数', list.body.data.every(s => s.consult_count === null && s.consult_count_source === 'legacy_unverified'))
  check('列表场景身份无重复', new Set(list.body.data.map(s => s.id)).size === list.body.data.length)
  if (list.body.data.length) {
    const detail = await get('/api/scenes/' + list.body.data[0].id)
    check('真实详情返回配置机型协议', detail.status === 200 && detail.body.matched === true && Array.isArray(detail.body.products) && Array.isArray(detail.body.scheme?.robots))
    check('详情不公开未核验收益', detail.body.scheme?.stats === null && detail.body.scheme?.consultCount === null)
    check('详情机型均来自配置并显式限定商品类型', Array.isArray(detail.body.products) && detail.body.products.every(p => p.type === 'simple' && detail.body.scheme.robots.includes(p.id)))
  } else { results.push({ name: '真实详情', pass: null, note: '当前无启用场景，未验证详情，不创建测试数据' }) }
}
const invalid = await Promise.all(['undefined','-1','1x','4294967296'].map(async id => [id, await get('/api/scenes/' + id)]))
for (const [id, response] of invalid) check('非法 ID ' + id + ' 稳定拒绝', response.status === 404)
const report = { target: base.origin, checked_at: new Date().toISOString(), method: 'GET only', results, failed: results.filter(r => r.pass === false).length }
process.stdout.write(JSON.stringify(report, null, 2) + '\n')
if (report.failed) process.exitCode = 1
