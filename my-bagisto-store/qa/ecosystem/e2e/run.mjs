/**
 * G2/G3/G4 · 真实浏览器端到端演练（独立测试库 + 真实 Chrome CDP）。
 *
 *   node qa/ecosystem/e2e/run.mjs [--json=D:/main/docs/ecosystem/g3g4-e2e-result.json]
 *
 * 前置：
 *   · 独立测试库 summon_mecha_step10_test；
 *   · Chrome（C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe）；
 *   · H5 已导出到 D:/main/unpackage/dist/build/web（D:/HBuilderX/cli.exe publish web --project D:/main）。
 *
 * 覆盖：
 *   G2  T31 切号隔离 / T47 跨设备一致
 *   G3  作品提交 → 展示授权 → 成绩公开 → 申诉
 *   G4  搜索、收藏、关联降级、SSR/canonical/OG/JSON-LD、sitemap、撤稿 410、停读 503、375px
 *
 * 所有开关只在本进程启动的测试服务里打开；不修改 .env，不写 root。
 */
import { spawn, spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { Browser, sleep } from '../q04/driver.mjs'

const STORE = 'D:/main/my-bagisto-store'
const APP_BASE = 'http://127.0.0.1:8010'
const KILL_BASE = 'http://127.0.0.1:8011'
const H5_BASE = 'http://127.0.0.1:8099'
const H5 = (route, query = '') => `${H5_BASE}/#/pages/ecosystem/${route}${query ? '?' + query : ''}`

const jsonArg = process.argv.find((arg) => arg.startsWith('--json='))
const resultPath = jsonArg ? jsonArg.slice(7) : 'D:/main/docs/ecosystem/g3g4-e2e-result.json'
const shotDir = 'D:/main/docs/ecosystem/e2e-screenshots'

const baseEnv = {
  ...process.env,
  DB_DATABASE: 'summon_mecha_step10_test',
  APP_ENV: 'testing',
  ECOSYSTEM_EVENT_PUBLIC_ENABLED: 'true',
  ECOSYSTEM_CONTENT_PUBLIC_ENABLED: 'true',
  ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED: 'false',
  ECOSYSTEM_EVENT_REGISTRATION_ENABLED: 'true',
  ECOSYSTEM_EVENT_REGISTRATION_WRITE_VERIFIED: 'true',
  ECOSYSTEM_REGISTRATION_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_WRITE_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_SCHEDULE_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_RESULTS_ENABLED: 'true',
  ECOSYSTEM_COMPETITION_PUBLIC_SHOWCASE_ENABLED: 'true',
  ECOSYSTEM_SEARCH_ENABLED: 'true',
  ECOSYSTEM_FAVORITES_ENABLED: 'true',
  ECOSYSTEM_RELATIONS_ENABLED: 'true',
  ECOSYSTEM_SSR_ENABLED: 'true',
  ECOSYSTEM_SITEMAP_ENABLED: 'true',
  ECOSYSTEM_METRICS_ENABLED: 'true',
  ECOSYSTEM_CONTENT_VIEWS_ENABLED: 'true',
  ECOSYSTEM_OPS_WORKBENCH_ENABLED: 'true',
  ECOSYSTEM_PUBLIC_SITE_URL: APP_BASE,
}

const result = {
  q: 'G2/G3/G4 browser e2e',
  generated_at: new Date().toISOString(),
  database: baseEnv.DB_DATABASE,
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

const startServer = (args, envOverrides = {}) => {
  const process = spawn('D:/php.exe', args, {
    cwd: STORE,
    env: { ...baseEnv, ...envOverrides },
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  const logs = { out: '', err: '' }
  process.stdout.on('data', (chunk) => { logs.out += chunk.toString() })
  process.stderr.on('data', (chunk) => { logs.err += chunk.toString() })
  servers.push({ process, logs })
  return process
}

const runPhp = (script, args) => {
  const completed = spawnSync('D:/php.exe', [path.join(STORE, 'qa/ecosystem/e2e', script), ...args], {
    cwd: STORE,
    env: baseEnv,
    encoding: 'utf8',
  })
  const line = (completed.stdout || '').trim().split('\n').filter(Boolean).pop()
  let parsed = null
  try { parsed = line ? JSON.parse(line) : null } catch { parsed = null }
  return { status: completed.status, parsed, stdout: completed.stdout, stderr: completed.stderr }
}

const mutate = (args) => {
  const outcome = runPhp('mutate.php', args)
  if (outcome.status !== 0 || !outcome.parsed) {
    throw new Error('mutate failed: ' + args.join(' ') + '\n' + (outcome.stderr || outcome.stdout))
  }
  return outcome.parsed
}

const loginViaRegister = async (page, email, password, eventId) => {
  await page.goto(H5('event-register', `id=${eventId}`))
  await page.waitForText('登录后继续报名', 30000)
  await page.setInput('请输入邮箱', email)
  await page.setInput('请输入密码', password)
  await page.clickText('登录')
  // 已报名账号登录后显示“你已报名”，新账号显示报名表；两者都代表登录成功。
  await page.waitFor(async () => {
    const body = await page.bodyText()
    return body.includes('填写报名信息') || body.includes('你已报名') || body.includes('查看我的报名')
  }, 30000, '登录后状态超时')
}

const clickInRow = async (page, containerSelector, rowNeedle, buttonText) => {
  const ok = await page.evaluate(`(() => {
    const rows = Array.from(document.querySelectorAll(${JSON.stringify(containerSelector)}))
    const row = rows.find((el) => (el.innerText || '').includes(${JSON.stringify(rowNeedle)}))
    if (!row) return false
    const nodes = Array.from(row.querySelectorAll('button, uni-button, view, text, div, span'))
    const btn = nodes.reverse().find((el) => (el.innerText || el.textContent || '').trim() === ${JSON.stringify(buttonText)})
    if (!btn) return false
    btn.scrollIntoView({ block: 'center' })
    btn.click()
    return true
  })()`)
  if (!ok) throw new Error('找不到行内按钮：' + rowNeedle + ' / ' + buttonText)
  await sleep(400)
}

const run = async () => {
  console.log('[e2e] fixture')
  // ── 夹具 ──────────────────────────────────────────────────────────
  const prepared = runPhp('prepare.php', [])
  if (prepared.status !== 0 || !prepared.parsed) {
    throw new Error('prepare failed: ' + (prepared.stderr || prepared.stdout))
  }
  const fixture = prepared.parsed
  fixturePath = path.join(os.tmpdir(), `ecosystem-e2e-fixture-${Date.now()}.json`)
  fs.writeFileSync(fixturePath, JSON.stringify(fixture, null, 2))
  check('fixture_prepared', fixture.event_main_id > 0 && fixture.track_id > 0 && fixture.content_slug !== '', {
    event: fixture.event_main_id,
    track: fixture.track_id,
    content: fixture.content_slug,
  })

  console.log('[e2e] servers')
  // ── 服务 ──────────────────────────────────────────────────────────
  startServer(['artisan', 'serve', '--host=127.0.0.1', '--port=8010'])
  startServer(['artisan', 'serve', '--host=127.0.0.1', '--port=8011'], {
    ECOSYSTEM_PUBLIC_READ_EMERGENCY_DISABLED: 'true',
  })
  startServer(['-S', '127.0.0.1:8099', '-t', 'D:/main/unpackage/dist/build/web'])

  const appStatus = await waitHttp(`${APP_BASE}/admin/login`, 60000)
  const killStatus = await waitHttp(`${KILL_BASE}/admin/login`, 60000)
  const h5Status = await waitHttp(`${H5_BASE}/`, 30000)
  check('servers_ready', appStatus === 200 && killStatus === 200 && h5Status === 200, {
    app: appStatus, kill: killStatus, h5: h5Status,
  })

  console.log('[e2e] browsers')
  // ── 设备 A / B / C：独立浏览器 profile，storage 不互通 ─────────────
  const browserA = await Browser.launch()
  const browserB = await Browser.launch()
  const browserC = await Browser.launch()
  browsers.push(browserA, browserB, browserC)

  const pageReg = await browserA.newPage({ width: 390, height: 844, mobile: true })
  const pageRegB = await browserB.newPage({ width: 390, height: 844, mobile: true })
  const pageOther = await browserB.newPage({ width: 390, height: 844, mobile: true })
  const pageAnon = await browserC.newPage({ width: 390, height: 844, mobile: true })
  const pageCaptain = await browserC.newPage({ width: 390, height: 844, mobile: true })

  console.log('[e2e] G2 registration')
  // ── 1. G2：客户报名 ───────────────────────────────────────────────
  await pageReg.goto(H5('event-register', `id=${fixture.event_main_id}`))
  await pageReg.waitForText('登录后继续报名', 30000)
  await pageReg.setInput('请输入邮箱', fixture.registrant.email)
  await pageReg.setInput('请输入密码', fixture.registrant.password)
  await pageReg.clickText('登录')
  await pageReg.waitForText('填写报名信息', 30000)

  await pageReg.clickText('提交报名')
  await pageReg.waitForText('请填写姓名', 5000)
  check('g2_form_error_branch', (await pageReg.bodyText()).includes('请填写手机号'))

  await pageReg.setInput('请输入姓名', 'E2E 报名者')
  await pageReg.setInput('请输入手机号', '13500000002')
  await pageReg.setInput('选填', '上海')
  await pageReg.clickContains('我已阅读并同意')
  await pageReg.clickText('提交报名')
  await pageReg.waitForText('报名编号', 30000)

  const regBody = await pageReg.bodyText()
  const publicNo = (regBody.match(/EV[A-Z0-9]{8,}/) || [null])[0]
  check('g2_register_confirmed', Boolean(publicNo) && regBody.includes('已确认'), {
    public_no: publicNo,
    confirmed: regBody.includes('已确认'),
  })
  await pageReg.screenshot(path.join(shotDir, 'e2e-01-register-receipt-390.png'))

  console.log('[e2e] T47 cross-device')
  // T47：第二台“设备”用同一账号看到同一报名。
  // 明确清一次 storage，保证“新设备”而不是共用会话。
  await pageRegB.goto(H5('event-register', `id=${fixture.event_main_id}`))
  await pageRegB.clearStorage()
  await pageRegB.reload()
  await loginViaRegister(pageRegB, fixture.registrant.email, fixture.registrant.password, fixture.event_main_id)
  await pageRegB.goto(H5('my-registrations'))
  await pageRegB.waitForText('我的报名', 30000)
  await pageRegB.waitForText(publicNo, 20000)
  check('g2_t47_cross_device_same_registration', (await pageRegB.bodyText()).includes(publicNo), {
    public_no: publicNo,
  })
  await pageRegB.screenshot(path.join(shotDir, 'e2e-02-cross-device-390.png'))

  console.log('[e2e] T31 account switch')
  // T31：切到另一个账号，私有报名不残留。
  // 先进入 H5 源，才能操作 localStorage（about:blank 会抛 SecurityError）。
  await pageOther.goto(H5('event-register', `id=${fixture.event_main_id}`))
  await pageOther.clearStorage()
  await pageOther.reload()
  await loginViaRegister(pageOther, fixture.other.email, fixture.other.password, fixture.event_main_id)
  await pageOther.goto(H5('my-registrations'))
  await pageOther.waitForText('我的报名', 30000)
  await pageOther.waitFor(async () => {
    const body = await pageOther.bodyText()
    return body.includes('还没有报名记录') || body.includes(publicNo)
  }, 15000, '我的报名空态超时')
  const otherMine = await pageOther.bodyText()
  check('g2_t31_account_switch_isolated', !otherMine.includes(publicNo) && otherMine.includes('还没有报名记录'), {
    leaks_public_no: otherMine.includes(publicNo),
    empty_state: otherMine.includes('还没有报名记录'),
    body: otherMine.slice(0, 400),
  })
  await pageOther.screenshot(path.join(shotDir, 'e2e-03-account-switch-empty-390.png'))

  console.log('[e2e] G3 submission')
  // ── 2. G3：作品提交 → 展示授权 → 成绩 → 申诉 ──────────────────────
  await loginViaRegister(pageCaptain, fixture.captain_a.email, fixture.captain_a.password, fixture.event_main_id)
  await pageCaptain.goto(H5('competition-submission', `teamId=${fixture.team_a_id}`))
  await pageCaptain.waitForText('新建作品版本', 30000)
  await pageCaptain.setInput('作品标题（必填，160 字内）', 'E2E 机甲作品')
  await pageCaptain.setInput('一句话摘要（可选）', '端到端演练作品')
  await pageCaptain.setInput('作品描述（纯文本）', '浏览器提交的作品描述')
  await pageCaptain.clickText('保存草稿')
  await pageCaptain.waitForText('v1', 15000)
  check('g3_submission_draft_saved', (await pageCaptain.bodyText()).includes('草稿'))
  await pageCaptain.clickText('提交作品')
  await pageCaptain.waitFor(async () => {
    const body = await pageCaptain.bodyText()
    return body.includes('E2E 机甲作品') && body.includes('已提交')
  }, 20000, '作品提交后状态超时')
  const submissionBody = await pageCaptain.bodyText()
  check('g3_submission_submitted', submissionBody.includes('E2E 机甲作品') && submissionBody.includes('已提交'), {
    body: submissionBody.slice(0, 500),
  })
  await pageCaptain.screenshot(path.join(shotDir, 'e2e-04-submission-390.png'))

  console.log('[e2e] G3 showcase')
  const showcase = mutate([
    '--action=create-showcase',
    `--track=${fixture.track_id}`,
    `--entry=${fixture.entry_a_id}`,
    `--admin=${fixture.admin_reviewer}`,
    '--title=E2E 作品回顾',
    '--summary=浏览器演练提交的作品与复盘内容。',
  ])
  check('g3_showcase_draft_created', showcase.showcase_id > 0, showcase)

  await pageCaptain.goto(H5('competition-showcase'))
  await pageCaptain.waitForText('E2E 作品回顾', 30000)
  await pageCaptain.clickText('授权公开展示')
  await pageCaptain.waitForText('已授权且内容未变化', 20000)
  check('g3_showcase_consent', (await pageCaptain.bodyText()).includes('已授权且内容未变化'))
  await pageCaptain.screenshot(path.join(shotDir, 'e2e-05-showcase-consent-390.png'))

  const publishedShowcase = mutate(['--action=publish-showcase', `--id=${showcase.showcase_id}`, `--admin=${fixture.admin_reviewer}`])
  check('g3_showcase_published', publishedShowcase.status === 'published', publishedShowcase)

  console.log('[e2e] G3 results')
  await pageCaptain.goto(H5('competition-results', `eventId=${fixture.event_main_id}`))
  await pageCaptain.waitForText(fixture.team_a_name, 30000)
  const resultsBody = await pageCaptain.bodyText()
  check('g3_public_results_visible',
    resultsBody.includes(fixture.team_a_name) && resultsBody.includes('#1') && resultsBody.includes('申诉'),
    { team: fixture.team_a_name },
  )
  check('g3_public_showcase_visible', resultsBody.includes('E2E 作品回顾') && resultsBody.includes('作品展示'))

  console.log('[e2e] G3 appeal')
  await clickInRow(pageCaptain, '.result-row', fixture.team_a_name, '申诉')
  await pageCaptain.waitForText('提交申诉', 10000)
  await pageCaptain.setInput('申诉理由（10~4000 字），请说明具体事实', 'E2E 演练：对第一轮计分的时间记录提出复核申请。')
  await pageCaptain.clickText('提交申诉')
  await pageCaptain.waitForText('我的申诉', 20000)
  const appealBody = await pageCaptain.bodyText()
  const appealNo = (appealBody.match(/AP[A-Z0-9]{8,}/) || [null])[0]
  check('g3_appeal_submitted', Boolean(appealNo), { appeal_no: appealNo })
  await pageCaptain.screenshot(path.join(shotDir, 'e2e-06-appeal-390.png'))

  console.log('[e2e] G4 search')
  // ── 3. G4：搜索 / 关联 / 收藏 ─────────────────────────────────────
  await pageAnon.goto(H5('events'))
  await pageAnon.waitForText('主赛事', 30000)
  await pageAnon.setInput('搜索活动 / 城市 / 地点', 'ONLY-E2')
  await pageAnon.clickText('搜索')
  await pageAnon.waitForText('ONLY-E2 沙龙', 30000)
  await sleep(800)
  const searchBody = await pageAnon.bodyText()
  check('g4_h5_search_filter',
    searchBody.includes('ONLY-E2 沙龙') && !searchBody.includes('主赛事'),
    { filtered_out_main: !searchBody.includes('主赛事') },
  )
  await pageAnon.screenshot(path.join(shotDir, 'e2e-07-search-390.png'))

  console.log('[e2e] G4 relations + favorites')
  // 关联 + 收藏（客户 A，仍是 registrant 会话）。
  await pageReg.goto(H5('event-detail', `id=${fixture.event_main_id}`))
  await pageReg.waitForText('主赛事', 30000)
  const detailBody = await pageReg.bodyText()
  check('g4_relations_all_groups',
    ['相关活动', '相关资讯', '相关机型', '相关技能', '相关场景', 'ONLY-E2 沙龙', '公开资讯', '关联机型', '关联技能', '关联场景']
      .every((text) => detailBody.includes(text)),
    { groups_ok: true },
  )
  check('g4_relations_retracted_target_omitted', !detailBody.includes('撤稿前旧标题'))

  // 收藏前先确认页面会话可用（GET 不写库；失败时把状态码带进结果）。
  const favProbe = await pageReg.evaluate(`(async () => {
    let token = ''
    const raw = localStorage.getItem('token') || ''
    try {
      const parsed = JSON.parse(raw)
      token = parsed && parsed.type ? String(parsed.data || '') : raw
    } catch { token = raw }
    const res = await fetch('http://127.0.0.1:8010/api/customer/favorites?type=event', {
      headers: { Accept: 'application/json', Authorization: 'Bearer ' + token },
    })
    return { token_present: Boolean(token), status: res.status, body: (await res.text()).slice(0, 200) }
  })()`)
  check('g4_favorite_auth_probe', favProbe.status === 200 && favProbe.token_present, favProbe, 'observed')

  const clickedFav = await pageReg.evaluate(`(() => {
    const el = document.querySelector('.nav-fav')
    if (!el) return false
    el.click()
    return true
  })()`)
  check('g4_favorite_click_target', clickedFav, { selector: '.nav-fav' })
  await pageReg.waitForText('已收藏', 10000)
  await pageReg.reload()
  await pageReg.waitForText('主赛事', 30000)
  check('g4_favorite_persisted_after_reload', (await pageReg.bodyText()).includes('已收藏'))

  await pageReg.goto(H5('my-favorites'))
  await pageReg.waitForText('我的收藏', 30000)
  await pageReg.waitForText('主赛事', 20000)
  check('g4_my_favorites_event', (await pageReg.bodyText()).includes('主赛事'))
  await pageReg.screenshot(path.join(shotDir, 'e2e-08-my-favorites-390.png'))

  // 资讯收藏 + tab 切换。
  await pageReg.goto(H5('content-detail', `slug=${encodeURIComponent(fixture.content_slug)}`))
  await pageReg.waitForText('公开资讯', 30000)
  const clickedContentFav = await pageReg.evaluate(`(() => {
    const el = document.querySelector('.nav-fav')
    if (!el) return false
    el.click()
    return true
  })()`)
  check('g4_content_favorite_click_target', clickedContentFav, { selector: '.nav-fav' })
  await pageReg.waitForText('已收藏', 10000)
  await pageReg.goto(H5('my-favorites'))
  await pageReg.clickText('动态资讯')
  await pageReg.waitForText('公开资讯', 20000)
  check('g4_my_favorites_content_tab', (await pageReg.bodyText()).includes('公开资讯'))
  await pageReg.screenshot(path.join(shotDir, 'e2e-09-favorites-content-390.png'))

  // 跨账号：另一个客户看不到上述收藏。
  await pageOther.goto(H5('my-favorites'))
  await pageOther.waitForText('我的收藏', 30000)
  await sleep(800)
  const otherFavorites = await pageOther.bodyText()
  check('g4_t31_favorites_cross_account_isolated',
    !otherFavorites.includes('主赛事') && !otherFavorites.includes('公开资讯'),
    { empty_state: otherFavorites.includes('还没有收藏') },
  )

  // 375px：详情与成绩页不横向溢出。
  await pageReg.setViewport(375, 667, true)
  await pageReg.goto(H5('event-detail', `id=${fixture.event_main_id}`))
  await pageReg.waitForText('主赛事', 30000)
  const detailMetrics = await pageReg.metrics()
  check('g4_375_detail_no_overflow', detailMetrics.overflowX === false, detailMetrics)

  await pageCaptain.setViewport(375, 667, true)
  await pageCaptain.goto(H5('competition-results', `eventId=${fixture.event_main_id}`))
  await pageCaptain.waitForText(fixture.team_a_name, 30000)
  const resultsMetrics = await pageCaptain.metrics()
  check('g3_375_results_no_overflow', resultsMetrics.overflowX === false, resultsMetrics)
  await pageCaptain.screenshot(path.join(shotDir, 'e2e-10-results-375.png'))

  console.log('[e2e] G4 SSR/sitemap')
  // ── 4. G4：SSR / canonical / OG / JSON-LD / sitemap ────────────────
  const listHtml = await (await fetch(`${APP_BASE}/events`)).text()
  check('g4_ssr_list_public_only',
    listHtml.includes('主赛事') && !listHtml.includes('草稿活动') && !listHtml.includes('撤稿前旧标题'),
    { status: 200 },
  )

  const detailResponse = await fetch(`${APP_BASE}/events/${fixture.event_main_id}`)
  const detailHtml = await detailResponse.text()
  check('g4_ssr_event_detail_metadata', detailResponse.status === 200
    && detailHtml.includes(`<link rel="canonical" href="${APP_BASE}/events/${fixture.event_main_id}">`)
    && detailHtml.includes('<meta property="og:title" content="'+fixture.marker+' 主赛事">')
    && detailHtml.includes('"@type":"Event"'), { status: detailResponse.status })

  const contentResponse = await fetch(`${APP_BASE}/content/${encodeURIComponent(fixture.content_slug)}`)
  const contentHtml = await contentResponse.text()
  check('g4_ssr_content_detail_metadata', contentResponse.status === 200
    && contentHtml.includes('"@type":"Article"')
    && contentHtml.includes(`<link rel="canonical" href="${APP_BASE}/content/${fixture.content_slug}">`),
    { status: contentResponse.status })

  const sitemapResponse = await fetch(`${APP_BASE}/sitemap.xml`)
  const sitemapXml = await sitemapResponse.text()
  check('g4_sitemap_public_only', sitemapResponse.status === 200
    && sitemapXml.includes(`${APP_BASE}/events/${fixture.event_main_id}`)
    && sitemapXml.includes(`${APP_BASE}/content/${fixture.content_slug}`)
    && !sitemapXml.includes(`${APP_BASE}/events/${fixture.event_draft_id}`)
    && !sitemapXml.includes(`${APP_BASE}/events/${fixture.event_retracted_id}`)
    && !sitemapXml.includes(`${APP_BASE}/content/${fixture.draft_content_slug}`),
    { status: sitemapResponse.status })

  const retractedBefore = await fetch(`${APP_BASE}/events/${fixture.event_retracted_id}`)
  const retractedBeforeHtml = await retractedBefore.text()
  check('g4_ssr_retracted_410_neutral', retractedBefore.status === 410
    && retractedBeforeHtml.includes('该赛事已撤稿')
    && !retractedBeforeHtml.includes('撤稿前旧标题')
    && !retractedBeforeHtml.includes('撤稿前旧摘要'),
    { status: retractedBefore.status })

  console.log('[e2e] retraction drill')
  // ── 5. 撤稿同步降级 ───────────────────────────────────────────────
  const retracted = mutate([
    '--action=retract-event',
    `--id=${fixture.event_main_id}`,
    `--actor=${fixture.admin_recorder}`,
    '--reason=E2E 撤稿演练',
  ])
  check('g4_event_retracted', retracted.disposition === 'retracted', retracted)

  const goneResponse = await fetch(`${APP_BASE}/events/${fixture.event_main_id}`)
  const goneHtml = await goneResponse.text()
  check('g4_ssr_retracted_410_after_action', goneResponse.status === 410
    && !goneHtml.includes('撤稿前旧标题')
    && !goneHtml.includes('主赛事'),
    { status: goneResponse.status })

  const apiGone = await fetch(`${APP_BASE}/api/events/${fixture.event_main_id}`)
  check('g4_api_retracted_not_leaking', [404, 410].includes(apiGone.status), { status: apiGone.status })

  const sitemapAfter = await (await fetch(`${APP_BASE}/sitemap.xml`)).text()
  check('g4_sitemap_retracted_excluded', !sitemapAfter.includes(`${APP_BASE}/events/${fixture.event_main_id}`)
    && sitemapAfter.includes(`${APP_BASE}/content/${fixture.content_slug}`))

  // 收藏列表：撤稿目标隐藏并显示 stale 计数。
  // 同一路由的 hash 跳转不会重新挂载页面（会保留上次的“动态资讯”tab），必须整页刷新。
  await pageReg.goto(H5('my-favorites'))
  await pageReg.reload()
  await pageReg.waitForText('我的收藏', 30000)
  await pageReg.waitFor(async () => (await pageReg.bodyText()).includes('已撤稿或下架'), 15000, '未显示撤稿提示')
  const favAfter = await pageReg.bodyText()
  check('g4_favorite_stale_hidden', favAfter.includes('已撤稿或下架') && !favAfter.includes('主赛事'), {
    stale_note: true,
  })
  await pageReg.screenshot(path.join(shotDir, 'e2e-11-favorite-stale-390.png'))

  // H5 列表刷新后不再出现撤稿活动。
  await pageAnon.goto(H5('events'))
  await pageAnon.waitForText('ONLY-E2 沙龙', 30000)
  await sleep(800)
  check('g4_h5_list_retracted_gone', !(await pageAnon.bodyText()).includes('主赛事'))

  console.log('[e2e] kill switch')
  // ── 6. 停读开关：API / SSR / sitemap 统一 503 ─────────────────────
  const killApi = await fetch(`${KILL_BASE}/api/events`)
  const killSsr = await fetch(`${KILL_BASE}/events`)
  const killSitemap = await fetch(`${KILL_BASE}/sitemap.xml`)
  check('g4_kill_switch_503',
    killApi.status === 503 && killSsr.status === 503 && killSitemap.status === 503,
    { api: killApi.status, ssr: killSsr.status, sitemap: killSitemap.status })

  result.browser_errors = {
    pageReg: pageReg.pageErrors,
    pageRegB: pageRegB.pageErrors,
    pageCaptain: pageCaptain.pageErrors,
    console_errors: pageReg.consoleErrors.slice(0, 10),
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
    const cleaned = runPhp('cleanup.php', [fixturePath])
    check('fixture_cleanup', Boolean(cleaned.parsed && cleaned.parsed.deleted), {
      result: cleaned.parsed,
      status: cleaned.status,
      stdout: (cleaned.stdout || '').slice(-500),
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
