/**
 * Q-04 · Admin / 客户 / 队长 / 裁判真实浏览器演练。
 *
 *   node qa/ecosystem/q04/run.mjs [--json=D:/main/docs/ecosystem/q04-result.json]
 *
 * 前置：独立测试库、Chrome、HBuilderX 已导出 H5 到 unpackage/dist/build/web。
 *
 * 演练使用：
 *   · 独立测试库的 Laravel 服务（127.0.0.1:8010，所有生态写闸按演练需要打开）；
 *   · H5 静态站点（127.0.0.1:8099）；
 *   · 真实 Chrome（headless + CDP），375 / 390 px 视口、键盘 Tab、错误分支。
 *
 * 不改产品配置：H5 把 API base 固化为 8000，驱动在页面脚本前把请求改写到 8010。
 */
import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { Browser, sleep } from './driver.mjs'

const STORE = 'D:/main/my-bagisto-store'
const H5_BASE = 'http://127.0.0.1:8099'
const ADMIN_BASE = 'http://127.0.0.1:8010'
const H5 = (route, query = '') => `${H5_BASE}/#/pages/ecosystem/${route}${query ? '?' + query : ''}`

const jsonArg = process.argv.find((arg) => arg.startsWith('--json='))
const resultPath = jsonArg ? jsonArg.slice(7) : 'D:/main/docs/ecosystem/q04-result.json'
const shotDir = 'D:/main/docs/ecosystem/q04-screenshots'

const env = {
  ...process.env,
  DB_DATABASE: 'summon_mecha_step10_test',
  APP_ENV: 'testing',
  ECOSYSTEM_EVENT_PUBLIC_ENABLED: 'true',
  ECOSYSTEM_EVENT_REGISTRATION_ENABLED: 'true',
  ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED: 'true',
  ECOSYSTEM_REGISTRATION_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_WRITE_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED: 'true',
  ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED: 'false',
}

const result = {
  q: 'Q-04',
  generated_at: new Date().toISOString(),
  database: env.DB_DATABASE,
  checks: [],
  screenshot_dir: shotDir,
  ok: false,
}

const check = (name, passed, detail = {}, status = null) => {
  result.checks.push({ name, passed: Boolean(passed), status: status || (passed ? 'pass' : 'fail'), detail })
}

const waitHttp = async (url, timeout = 30000) => {
  const started = Date.now()
  while (Date.now() - started < timeout) {
    try {
      const response = await fetch(url)
      if (response.status < 500) return response.status
    } catch { /* not ready */ }
    await sleep(250)
  }
  throw new Error('服务未就绪：' + url)
}

const killTree = (pid) => {
  if (!pid) return
  try { spawnSync('taskkill', ['/pid', String(pid), '/T', '/F'], { stdio: 'ignore' }) } catch { /* noop */ }
}

const servers = []
let browser = null
let fixturePath = null

const startServer = (command, args, options = {}) => {
  const process = spawn(command, args, { cwd: STORE, env, stdio: ['ignore', 'pipe', 'pipe'], ...options })
  const logs = { out: '', err: '' }
  process.stdout.on('data', (chunk) => { logs.out += chunk.toString() })
  process.stderr.on('data', (chunk) => { logs.err += chunk.toString() })
  servers.push({ process, logs })
  return process
}

const textAny = async (page, texts, timeout = 15000) => {
  await page.waitFor(async () => {
    const body = await page.bodyText()
    return texts.some((text) => body.includes(text))
  }, timeout, '等待任一文本超时：' + texts.join(' / '))
}

const run = async () => {
  // ── 夹具 ──────────────────────────────────────────────────────────
  const prepared = spawnSync('D:/php.exe', [path.join(STORE, 'qa/ecosystem/q04/prepare.php')], { cwd: STORE, env, encoding: 'utf8' })
  const fixtureLine = (prepared.stdout || '').trim().split('\n').filter(Boolean).pop()
  const fixture = JSON.parse(fixtureLine)
  fixturePath = path.join(os.tmpdir(), `q04-fixture-${Date.now()}.json`)
  fs.writeFileSync(fixturePath, JSON.stringify(fixture, null, 2))
  check('fixture_prepared', fixture.event_id > 0 && fixture.team_id > 0, { event_id: fixture.event_id, track_id: fixture.track_id })

  // ── 服务 ──────────────────────────────────────────────────────────
  startServer('D:/php.exe', ['artisan', 'serve', '--host=127.0.0.1', '--port=8010'])
  startServer('D:/php.exe', ['-S', '127.0.0.1:8099', '-t', 'D:/main/unpackage/dist/build/web'])

  const adminStatus = await waitHttp(`${ADMIN_BASE}/admin/login`, 60000)
  const h5Status = await waitHttp(`${H5_BASE}/`, 30000)
  check('servers_ready', adminStatus === 200 && h5Status === 200, { admin: adminStatus, h5: h5Status })

  browser = await Browser.launch()

  // ── 1. 客户：登录错误分支 → 表单错误分支 → 提交 → 我的报名 ────────
  const customer = await browser.newPage({ width: 390, height: 844, mobile: true })
  await customer.goto(H5('event-register', `id=${fixture.event_id}`))
  await customer.waitForText('登录后继续报名', 30000)

  await customer.setInput('请输入邮箱', fixture.registrant.email)
  await customer.setInput('请输入密码', 'wrong-password')
  await customer.clickText('登录')
  // toast 可能只显示 1.5s；更稳定的判据是：仍停在登录区，且没有进入报名表。
  await sleep(2500)
  const afterWrongLogin = await customer.bodyText()
  check('customer_login_error_branch',
    afterWrongLogin.includes('登录后继续报名') && !afterWrongLogin.includes('填写报名信息'),
    { observed: afterWrongLogin.slice(0, 300) },
  )

  await customer.setInput('请输入密码', fixture.registrant.password)
  await customer.clickText('登录')
  await customer.waitForText('填写报名信息', 30000)

  // 键盘：从 body 开始连续 Tab，记录焦点顺序。
  await customer.evaluate('document.body.focus()')
  const tabOrder = []
  for (let i = 0; i < 6; i++) {
    await customer.pressTab()
    tabOrder.push(await customer.activeElement())
  }
  const distinctFocus = new Set(tabOrder.map((item) => item ? `${item.tag}:${item.placeholder || item.text}` : 'null'))
  check('customer_keyboard_tab_order', distinctFocus.size >= 4 && tabOrder.some((item) => item && item.tag === 'INPUT'), {
    tab_order: tabOrder,
  })

  // 空表单提交 → 字段级错误，不发请求。
  await customer.clickText('提交报名')
  await customer.waitForText('请填写姓名', 5000)
  const fieldErrors = (await customer.bodyText()).includes('请填写手机号')
  check('customer_form_error_branch', fieldErrors)

  await customer.setInput('请输入姓名', 'Q04 报名者')
  await customer.setInput('请输入手机号', '13500000001')
  await customer.setInput('选填', '上海')
  await customer.clickContains('我已阅读并同意')
  await customer.clickText('提交报名')
  await customer.waitForText('报名编号', 30000)

  const bodyAfterSubmit = await customer.bodyText()
  const publicNo = (bodyAfterSubmit.match(/EV[A-Z0-9]{8,}/) || [null])[0]
  check('customer_register_confirmed', Boolean(publicNo) && bodyAfterSubmit.includes('已确认'), {
    public_no: publicNo,
    status_confirmed: bodyAfterSubmit.includes('已确认'),
  })
  await customer.screenshot(path.join(shotDir, 'q04-01-customer-register-390.png'))

  await customer.goto(H5('my-registrations'))
  await customer.waitForText('我的报名', 30000)
  let mineLoaded = true
  try {
    await customer.waitForText(publicNo || 'NEVER', 15000)
  } catch { mineLoaded = false }
  const mineBody = await customer.bodyText()
  check('customer_my_registrations',
    mineLoaded && mineBody.includes(publicNo || 'NEVER') && mineBody.includes(fixture.marker),
    { public_no: publicNo, body: mineBody.slice(0, 500) },
  )
  await customer.screenshot(path.join(shotDir, 'q04-02-my-registrations-390.png'))

  // ── 2. 375px 关键状态 + 横向溢出 ─────────────────────────────────
  await customer.setViewport(375, 667, true)
  await customer.goto(H5('event-register', `id=${fixture.event_id}`))
  await customer.waitForText(fixture.marker, 30000)
  const customerMetrics = await customer.metrics()
  check('customer_375_no_horizontal_overflow', customerMetrics.overflowX === false, customerMetrics)
  await customer.screenshot(path.join(shotDir, 'q04-03-customer-registered-375.png'))

  // ── 3. 队长：登录 → 队伍 → 邀请码 → 无效邀请码错误分支 ────────────
  await customer.clearStorage()
  // 只改 hash 不会重新挂载页面；必须整页刷新，让 onLoad 重新读 token。
  await customer.reload()
  await customer.waitForText('登录后继续报名', 30000)
  await customer.setInput('请输入邮箱', fixture.captain.email)
  await customer.setInput('请输入密码', fixture.captain.password)
  await customer.clickText('登录')
  await customer.waitForText('填写报名信息', 30000)

  await customer.goto(H5('competition-team', `eventId=${fixture.event_id}&teamId=${fixture.team_id}`))
  await customer.waitForText('Q04 机甲队', 30000)
  const captainBody = await customer.bodyText()
  check('captain_team_view', captainBody.includes('我是队长') && captainBody.includes('邀请队员'), {
    is_captain: captainBody.includes('我是队长'),
  })

  await customer.clickText('生成邀请码')
  await customer.waitFor(async () => {
    const body = await customer.bodyText()
    return /[A-Za-z0-9_-]{32,}/.test(body)
  }, 15000, '邀请码未生成')
  const inviteToken = ((await customer.bodyText()).match(/[A-Za-z0-9_-]{32,}/) || [null])[0]
  check('captain_invite_token', Boolean(inviteToken), { token_length: inviteToken ? inviteToken.length : 0 })
  await customer.screenshot(path.join(shotDir, 'q04-04-captain-team-375.png'))

  await customer.goto(H5('competition-team', `eventId=${fixture.event_id}`))
  // 同页不同 query 可能复用组件；整页刷新确保 onLoad 重新执行、teamId 为空。
  await customer.reload()
  await customer.waitForText('加入队伍', 30000)
  await customer.setInput('粘贴队长发给你的邀请码', 'INVALID-QA-CODE')
  await customer.clickText('加入')
  let joinErrorObserved = false
  try {
    await textAny(customer, ['无效', '过期', '失败'], 8000)
    joinErrorObserved = true
  } catch { /* 与登录 toast 同因 */ }
  check('team_invalid_invite_error_branch', joinErrorObserved)

  const captainMetrics = await customer.metrics()
  check('captain_375_no_horizontal_overflow', captainMetrics.overflowX === false, captainMetrics)
  await customer.screenshot(path.join(shotDir, 'q04-05-team-join-error-375.png'))

  // ── 4. 裁判：只读赛务可以，报名 PII/列表被拒 ─────────────────────
  const referee = await browser.newPage({ width: 1440, height: 900, mobile: false })
  await referee.goto(`${ADMIN_BASE}/admin/login`)

  await referee.evaluate('document.body.focus()')
  const adminTabOrder = []
  for (let i = 0; i < 4; i++) {
    await referee.pressTab()
    adminTabOrder.push(await referee.activeElement())
  }
  check('admin_keyboard_tab_order', adminTabOrder.filter((item) => item && item.tag === 'INPUT').length >= 2, {
    tab_order: adminTabOrder,
  })

  await referee.setInputSelector('input[name="email"]', fixture.admin_referee.email)
  await referee.setInputSelector('input[name="password"]', fixture.admin_referee.password)
  await referee.clickText('登录')
  await referee.waitFor(async () => !(await referee.evaluate('location.href')).includes('/admin/login'), 30000, '裁判登录超时')

  await referee.goto(`${ADMIN_BASE}/admin/catalog/ecosystem-competition/tracks/${fixture.track_id}/results`)
  const resultsStatus = referee.documentStatus
  const resultsBody = await referee.bodyText()
  check('referee_can_view_results', resultsStatus === 200 && (resultsBody.includes('成绩') || resultsBody.includes('排行榜')), {
    status: resultsStatus,
  })
  await referee.screenshot(path.join(shotDir, 'q04-06-referee-results-1440.png'))

  await referee.goto(`${ADMIN_BASE}/admin/catalog/ecosystem-publishing/events/${fixture.event_id}/registrations`)
  const deniedStatus = referee.documentStatus
  const deniedBody = await referee.bodyText()
  check('referee_registration_list_denied', deniedStatus === 403, {
    status: deniedStatus,
    leaks_email: deniedBody.includes(fixture.registrant.email),
  })
  await referee.screenshot(path.join(shotDir, 'q04-07-referee-403-1440.png'))

  await referee.setViewport(390, 844, true)
  await referee.goto(`${ADMIN_BASE}/admin/catalog/ecosystem-competition/tracks/${fixture.track_id}/results`)
  const adminMetrics = await referee.metrics()
  check('admin_390_layout_observed', true, adminMetrics, 'observed')
  await referee.screenshot(path.join(shotDir, 'q04-08-referee-results-390.png'))

  // ── 5. 超管：报名列表可读 ─────────────────────────────────────────
  const admin = await browser.newPage({ width: 1440, height: 900, mobile: false })
  await admin.clearCookies()
  await admin.goto(`${ADMIN_BASE}/admin/login`)
  await admin.setInputSelector('input[name="email"]', fixture.admin_super.email)
  await admin.setInputSelector('input[name="password"]', fixture.admin_super.password)
  await admin.clickText('登录')
  await admin.waitFor(async () => !(await admin.evaluate('location.href')).includes('/admin/login'), 30000, '超管登录超时')

  await admin.goto(`${ADMIN_BASE}/admin/catalog/ecosystem-publishing/events/${fixture.event_id}/registrations`)
  const superStatus = admin.documentStatus
  const superBody = await admin.bodyText()
  check('super_admin_registration_list', superStatus === 200 && superBody.includes(fixture.marker), {
    status: superStatus,
    event_listed: superBody.includes(fixture.marker),
  })
  await admin.screenshot(path.join(shotDir, 'q04-09-super-admin-registrations-1440.png'))

  // 汇总浏览器侧异常（只作证据，不把第三方资源失败当业务失败）。
  result.browser_errors = {
    referee_page_errors: referee.pageErrors,
    referee_console_errors: referee.consoleErrors.slice(0, 10),
    customer_page_errors: customer.pageErrors,
  }
}

try {
  await run()
} catch (error) {
  check('harness_exception', false, { message: error.message, stack: String(error.stack).split('\n').slice(0, 6) })
} finally {
  if (browser) await browser.close()
  for (const server of servers) killTree(server.process.pid)
  fs.mkdirSync(path.dirname(resultPath), { recursive: true })
  result.server_logs = servers.map((server) => ({ out: server.logs.out.slice(-2000), err: server.logs.err.slice(-2000) }))

  if (fixturePath && fs.existsSync(fixturePath)) {
    const cleaned = spawnSync('D:/php.exe', [path.join(STORE, 'qa/ecosystem/q04/cleanup.php'), fixturePath], { cwd: STORE, env, encoding: 'utf8' })
    let cleanup = null
    try { cleanup = JSON.parse((cleaned.stdout || '').trim().split('\n').filter(Boolean).pop()) } catch { cleanup = null }
    check('fixture_cleanup', Boolean(cleanup && cleanup.deleted), { result: cleanup, stderr: cleaned.stderr })
    fs.unlinkSync(fixturePath)
  }

  result.ok = result.checks.filter((item) => item.status !== 'observed').every((item) => item.passed)
  fs.writeFileSync(resultPath, JSON.stringify(result, null, 2) + '\n')
  console.log(JSON.stringify({
    ok: result.ok,
    result: resultPath,
    checks: result.checks.map((item) => `${item.passed ? 'PASS' : 'FAIL'} ${item.status} ${item.name}`),
  }, null, 2))
  process.exit(result.ok ? 0 : 1)
}
