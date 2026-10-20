/**
 * G3 · Admin 录分/发布真实浏览器演练。
 *
 *   node qa/ecosystem/admin-g3/run.mjs [--json=D:/main/docs/ecosystem/admin-g3-result.json]
 *
 * 验证后台真实页面与分权：
 *   录分员 保存草稿 → 提交复核 → 写入选定名次
 *   发布员 暂定发布 → 终审公布
 * 两个账号使用独立 Chrome profile；runner 只启动测试库服务，不写 root。
 */
import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { Browser, sleep } from '../q04/driver.mjs'

const STORE = 'D:/main/my-bagisto-store'
const APP_BASE = 'http://127.0.0.1:8010'
const H5_BASE = 'http://127.0.0.1:8099'

const jsonArg = process.argv.find((arg) => arg.startsWith('--json='))
const resultPath = jsonArg ? jsonArg.slice(7) : 'D:/main/docs/ecosystem/admin-g3-result.json'
const shotDir = 'D:/main/docs/ecosystem/admin-g3-screenshots'

const env = {
  ...process.env,
  DB_DATABASE: 'summon_mecha_step10_test',
  APP_ENV: 'testing',
  ECOSYSTEM_EVENT_PUBLIC_ENABLED: 'true',
  ECOSYSTEM_CONTENT_PUBLIC_ENABLED: 'true',
  ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED: 'false',
  ECOSYSTEM_COMPETITION_WRITE_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED: 'true',
}

const result = {
  q: 'G3 Admin scoring/publishing browser drill',
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
const browsers = []
let fixturePath = null

const startServer = () => {
  const process = spawn('D:/php.exe', ['artisan', 'serve', '--host=127.0.0.1', '--port=8010'], {
    cwd: STORE,
    env,
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  const logs = { out: '', err: '' }
  process.stdout.on('data', (chunk) => { logs.out += chunk.toString() })
  process.stderr.on('data', (chunk) => { logs.err += chunk.toString() })
  servers.push({ process, logs })
}

const runPhp = (scriptPath, args) => {
  const completed = spawnSync('D:/php.exe', [scriptPath, ...args], { cwd: STORE, env, encoding: 'utf8' })
  const line = (completed.stdout || '').trim().split('\n').filter(Boolean).pop()
  let parsed = null
  try { parsed = line ? JSON.parse(line) : null } catch { parsed = null }
  return { status: completed.status, parsed, stdout: completed.stdout, stderr: completed.stderr }
}

const adminLogin = async (page, email, password) => {
  await page.goto(`${APP_BASE}/admin/login`)
  await page.setInputSelector('input[name="email"]', email)
  await page.setInputSelector('input[name="password"]', password)
  await page.clickText('登录')
  await page.waitFor(async () => !(await page.evaluate('location.href')).includes('/admin/login'), 30000, '后台登录超时')
}

const selectValue = async (page, selector, value) => {
  const ok = await page.evaluate(`(() => {
    const el = document.querySelector(${JSON.stringify(selector)})
    if (!el) return false
    el.value = ${JSON.stringify(String(value))}
    el.dispatchEvent(new Event('change', { bubbles: true }))
    return true
  })()`)
  if (!ok) throw new Error('找不到下拉框：' + selector)
  await sleep(120)
}

const submitAndSettle = async (page, buttonText) => {
  await page.clickText(buttonText)
  await sleep(1200)
  await page.waitFor(() => page.evaluate('document.readyState === "complete"'), 20000, '表单提交后页面未就绪')
}

const saveDraft = async (page, entryId, speed, damage) => {
  await selectValue(page, 'form[action*="/results/draft"] select[name="entry_id"]', entryId)
  await page.setInputSelector('form[action*="/results/draft"] input[name="values[speed]"]', speed)
  await page.setInputSelector('form[action*="/results/draft"] input[name="values[damage]"]', damage)
  await page.setInputSelector('form[action*="/results/draft"] input[name="expected_row_version"]', '0')
  await page.setInputSelector('form[action*="/results/draft"] input[name="reason"]', '后台录分演练')
  await submitAndSettle(page, '保存草稿')
}

const run = async () => {
  console.log('[admin-g3] fixture')
  const prepared = runPhp(path.join(STORE, 'qa/ecosystem/admin-g3/prepare.php'), [])
  if (prepared.status !== 0 || !prepared.parsed) {
    throw new Error('prepare failed: ' + (prepared.stderr || prepared.stdout))
  }
  const fixture = prepared.parsed
  fixturePath = path.join(os.tmpdir(), `admin-g3-fixture-${Date.now()}.json`)
  fs.writeFileSync(fixturePath, JSON.stringify(fixture, null, 2))
  check('fixture_prepared', fixture.event_main_id > 0 && fixture.track_id > 0, {
    event: fixture.event_main_id, track: fixture.track_id,
  })

  console.log('[admin-g3] server')
  startServer()
  const status = await waitHttp(`${APP_BASE}/admin/login`, 60000)
  check('server_ready', status === 200, { status })

  const browserRecorder = await Browser.launch()
  const browserReviewer = await Browser.launch()
  browsers.push(browserRecorder, browserReviewer)
  const recorder = await browserRecorder.newPage({ width: 1440, height: 900, mobile: false })
  const reviewer = await browserReviewer.newPage({ width: 1440, height: 900, mobile: false })

  const resultsUrl = `${APP_BASE}/admin/catalog/ecosystem-competition/tracks/${fixture.track_id}/results`

  console.log('[admin-g3] recorder drafts')
  await adminLogin(recorder, fixture.admin_recorder.email, fixture.admin_recorder.password)
  await recorder.goto(resultsUrl)
  await recorder.waitForText('保存草稿', 30000)
  check('recorder_can_open_results', recorder.documentStatus === 200 && (await recorder.bodyText()).includes(fixture.team_a_name), {
    status: recorder.documentStatus,
  })

  await saveDraft(recorder, fixture.entry_a_id, '80', '20')
  await recorder.waitForText('v1', 20000)
  check('recorder_draft_a_saved', (await recorder.bodyText()).includes(fixture.team_a_name))

  await saveDraft(recorder, fixture.entry_b_id, '70', '19')
  await recorder.waitFor(async () => {
    const body = await recorder.bodyText()
    return body.includes(fixture.team_b_name) && (body.match(/v1/g) || []).length >= 2
  }, 20000, '第二条草稿未出现')
  check('recorder_draft_b_saved', (await recorder.bodyText()).includes(fixture.team_b_name))
  await recorder.screenshot(path.join(shotDir, 'admin-g3-01-drafts-1440.png'))

  console.log('[admin-g3] recorder submit reviews')
  const submitCount = () => recorder.evaluate(`(() => Array.from(document.querySelectorAll('button')).filter((b) => (b.innerText || '').trim() === '提交复核').length)()`)
  let remaining = await submitCount()

  for (let i = 0; i < 4 && remaining > 0; i++) {
    const before = remaining
    await submitAndSettle(recorder, '提交复核')
    try {
      await recorder.waitFor(async () => (await submitCount()) < before, 15000, '提交复核后按钮未减少')
    } catch { /* 记录在下面的 detail 里 */ }
    remaining = await submitCount()
  }

  const afterReviewSubmit = await recorder.bodyText()
  check('recorder_submitted_reviews', remaining === 0, {
    remaining_submit_buttons: remaining,
    body: afterReviewSubmit.slice(0, 600),
  })

  console.log('[admin-g3] recorder ranking')
  const rankingOk = await recorder.evaluate(`(() => {
    const form = document.querySelector('form[action*="/ranking"]')
    if (!form) return false
    const wanted = { ${JSON.stringify(String(fixture.entry_a_id))}: '1', ${JSON.stringify(String(fixture.entry_b_id))}: '2' }
    let hit = 0
    form.querySelectorAll('tbody tr').forEach((tr) => {
      const idInput = tr.querySelector('input[name$="[entry_id]"]')
      const rankInput = tr.querySelector('input[name$="[rank_no]"]')
      if (!idInput || !rankInput) return
      const value = wanted[idInput.value]
      if (!value) return
      rankInput.value = value
      rankInput.dispatchEvent(new Event('input', { bubbles: true }))
      hit++
    })
    return hit === 2
  })()`)
  check('recorder_ranking_form_filled', rankingOk)
  await submitAndSettle(recorder, '写入选定名次')
  await recorder.waitForText('名次 1', 20000)
  check('recorder_ranking_saved', (await recorder.bodyText()).includes('名次 1'))
  await recorder.screenshot(path.join(shotDir, 'admin-g3-02-reviewed-ranking-1440.png'))

  console.log('[admin-g3] reviewer publish')
  await adminLogin(reviewer, fixture.admin_reviewer.email, fixture.admin_reviewer.password)
  await reviewer.goto(resultsUrl)
  await reviewer.waitForText('暂定发布', 30000)
  const reviewerBody = await reviewer.bodyText()
  check('reviewer_can_open_results', reviewer.documentStatus === 200 && reviewerBody.includes(fixture.team_a_name))

  await submitAndSettle(reviewer, '暂定发布')
  await reviewer.waitFor(async () => {
    const response = await fetch(`${APP_BASE}/api/events/${fixture.event_main_id}/competition/results`)
    if (response.status !== 200) return false
    const payload = await response.json()
    const rows = (payload.data && payload.data.results && payload.data.results[0] && payload.data.results[0].results) || []
    return rows.length === 2 && rows.some((row) => row.status === 'provisional')
  }, 20000, '公开成绩未进入 provisional')
  check('reviewer_published_provisional', true)
  await reviewer.screenshot(path.join(shotDir, 'admin-g3-03-provisional-1440.png'))

  await submitAndSettle(reviewer, '终审公布')
  await reviewer.waitFor(async () => {
    const response = await fetch(`${APP_BASE}/api/events/${fixture.event_main_id}/competition/results`)
    if (response.status !== 200) return false
    const payload = await response.json()
    const rows = (payload.data && payload.data.results && payload.data.results[0] && payload.data.results[0].results) || []
    return rows.length === 2 && rows.every((row) => row.status === 'final')
  }, 20000, '公开成绩未进入 final')
  check('reviewer_finalized', true)
  await reviewer.screenshot(path.join(shotDir, 'admin-g3-04-final-1440.png'))

  result.browser_errors = {
    recorder: recorder.pageErrors,
    reviewer: reviewer.pageErrors,
  }
}

try {
  await run()
} catch (error) {
  check('harness_exception', false, { message: error.message, stack: String(error.stack).split('\n').slice(0, 8) })
} finally {
  for (const browser of browsers) {
    try { await browser.close() } catch { /* noop */ }
  }
  for (const server of servers) killTree(server.process.pid)

  fs.mkdirSync(path.dirname(resultPath), { recursive: true })
  result.server_logs = servers.map((server) => ({ out: server.logs.out.slice(-2000), err: server.logs.err.slice(-2000) }))

  if (fixturePath && fs.existsSync(fixturePath)) {
    const cleaned = runPhp(path.join(STORE, 'qa/ecosystem/e2e/cleanup.php'), [fixturePath])
    check('fixture_cleanup', Boolean(cleaned.parsed && cleaned.parsed.deleted), {
      result: cleaned.parsed,
      stderr: (cleaned.stderr || '').slice(-500),
    })
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
