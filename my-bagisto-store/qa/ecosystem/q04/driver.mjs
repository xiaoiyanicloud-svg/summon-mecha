/**
 * Q-04 · 极简 CDP 浏览器驱动（无 Playwright / Puppeteer）。
 *
 * 目的：用真实 Chrome 跑 H5 / Admin 页面，保留截图、控制台异常、
 * 失败请求与视口指标。所有交互都走真实 DOM 事件与 CDP 输入事件，
 * 不直接调用页面内部函数。
 */
import { spawn } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe'

// H5 构建把 API base 固化为 8000；演练用独立测试库服务在 8010。
// 在任何页面脚本之前改写 XHR/fetch 的 URL，避免修改产品配置。
const URL_REWRITE = `
(() => {
  const FROM = 'http://127.0.0.1:8000'
  const TO = 'http://127.0.0.1:8010'
  const fix = (u) => (typeof u === 'string' ? u.split(FROM).join(TO) : u)
  const open = XMLHttpRequest.prototype.open
  XMLHttpRequest.prototype.open = function (method, url, ...rest) {
    return open.call(this, method, fix(url), ...rest)
  }
  if (window.fetch) {
    const original = window.fetch
    window.fetch = function (input, init) {
      if (typeof input === 'string') input = fix(input)
      else if (input && input.url) {
        try { input = new Request(fix(input.url), input) } catch (e) {}
      }
      return original.call(this, input, init)
    }
  }
})()
`

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

class Page {
  constructor(port, wsUrl, targetId) {
    this.port = port
    this.wsUrl = wsUrl
    this.targetId = targetId
    this.pending = new Map()
    this.nextId = 1
    this.documentStatus = null
    this.networkFailures = []
    this.consoleErrors = []
    this.pageErrors = []
  }

  async connect() {
    this.ws = new WebSocket(this.wsUrl)
    await new Promise((resolve, reject) => {
      this.ws.addEventListener('open', resolve, { once: true })
      this.ws.addEventListener('error', reject, { once: true })
    })
    this.ws.addEventListener('message', (event) => {
      const message = JSON.parse(event.data)
      if (message.id && this.pending.has(message.id)) {
        const { resolve, reject } = this.pending.get(message.id)
        this.pending.delete(message.id)
        message.error ? reject(new Error(JSON.stringify(message.error))) : resolve(message.result)
        return
      }
      if (message.method === 'Network.responseReceived' && message.params.type === 'Document') {
        this.documentStatus = message.params.response.status
      }
      if (message.method === 'Network.loadingFailed') {
        this.networkFailures.push(message.params.errorText)
      }
      if (message.method === 'Runtime.exceptionThrown') {
        this.pageErrors.push(message.params.exceptionDetails.text || 'page exception')
      }
      if (message.method === 'Log.entryAdded' && message.params.entry.level === 'error') {
        this.consoleErrors.push(message.params.entry.text)
      }
    })
  }

  send(method, params = {}) {
    const id = this.nextId++
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject })
      this.ws.send(JSON.stringify({ id, method, params }))
    })
  }

  async init({ width = 390, height = 844, mobile = true } = {}) {
    await this.send('Page.enable')
    await this.send('Runtime.enable')
    await this.send('Network.enable')
    await this.send('Log.enable')
    await this.send('Page.addScriptToEvaluateOnNewDocument', { source: URL_REWRITE })
    await this.setViewport(width, height, mobile)
  }

  async setViewport(width, height, mobile = true) {
    await this.send('Emulation.setDeviceMetricsOverride', {
      width,
      height,
      deviceScaleFactor: mobile ? 2 : 1,
      mobile,
    })
  }

  async goto(url) {
    this.documentStatus = null
    await this.send('Page.navigate', { url })
    await this.waitFor(() => this.evaluate('document.readyState === "complete"'), 20000, '页面加载超时')
  }

  async reload() {
    this.documentStatus = null
    await this.send('Page.reload', { ignoreCache: true })
    await this.waitFor(() => this.evaluate('document.readyState === "complete"'), 20000, '页面刷新超时')
  }

  async evaluate(expression) {
    const result = await this.send('Runtime.evaluate', {
      expression,
      returnByValue: true,
      awaitPromise: true,
      userGesture: true,
    })
    if (result.exceptionDetails) {
      throw new Error(result.exceptionDetails.text + ': ' + (result.exceptionDetails.exception?.description || ''))
    }
    return result.result.value
  }

  async bodyText() {
    return String(await this.evaluate('document.body ? document.body.innerText : ""'))
  }

  async waitFor(fn, timeout = 20000, message = '等待超时') {
    const started = Date.now()
    while (Date.now() - started < timeout) {
      if (await fn()) return
      await sleep(200)
    }
    throw new Error(message + '\n' + (await this.bodyText()).slice(0, 800))
  }

  async waitForText(text, timeout = 25000) {
    await this.waitFor(async () => (await this.bodyText()).includes(text), timeout, '等待文本超时：' + text)
  }

  async setInput(placeholder, value) {
    const ok = await this.evaluate(`(() => {
      const wanted = ${JSON.stringify(placeholder)}
      const nodes = Array.from(document.querySelectorAll('input, textarea'))
      // 标准 input：placeholder 属性；uni-app H5：placeholder 是兄弟节点文本。
      let hit = nodes.find((el) => (el.getAttribute('placeholder') || '').includes(wanted))
      if (!hit) {
        const holders = Array.from(document.querySelectorAll('.uni-input-placeholder, .uni-textarea-placeholder'))
        const holder = holders.find((el) => (el.innerText || el.textContent || '').trim().includes(wanted))
        if (holder) {
          const wrapper = holder.closest('.uni-input-wrapper, .uni-textarea-wrapper') || holder.parentElement
          hit = wrapper ? wrapper.querySelector('input, textarea') : null
        }
      }
      if (!hit) return false
      const proto = hit.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(hit, ${JSON.stringify(value)})
      hit.dispatchEvent(new Event('input', { bubbles: true }))
      hit.dispatchEvent(new Event('change', { bubbles: true }))
      hit.focus()
      return true
    })()`)
    if (!ok) throw new Error('找不到输入框：' + placeholder)
    await sleep(120)
  }

  async setInputSelector(selector, value) {
    const ok = await this.evaluate(`(() => {
      const hit = document.querySelector(${JSON.stringify(selector)})
      if (!hit) return false
      const proto = hit.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement.prototype : window.HTMLInputElement.prototype
      Object.getOwnPropertyDescriptor(proto, 'value').set.call(hit, ${JSON.stringify(value)})
      hit.dispatchEvent(new Event('input', { bubbles: true }))
      hit.dispatchEvent(new Event('change', { bubbles: true }))
      hit.focus()
      return true
    })()`)
    if (!ok) throw new Error('找不到元素：' + selector)
    await sleep(120)
  }

  async elementExists(selector) {
    return Boolean(await this.evaluate(`document.querySelector(${JSON.stringify(selector)}) !== null`))
  }

  async clearCookies() {
    await this.send('Network.clearBrowserCookies')
  }

  async clickText(text, { exact = true } = {}) {
    const ok = await this.evaluate(`(() => {
      const wanted = ${JSON.stringify(text)}
      const nodes = Array.from(document.querySelectorAll('button, uni-button, uni-view, view, div, span, a, text'))
      const hit = nodes.reverse().find((el) => {
        const value = (el.innerText || el.textContent || '').trim()
        return ${exact ? 'value === wanted' : 'value.includes(wanted)'}
      })
      if (!hit) return false
      hit.scrollIntoView({ block: 'center' })
      hit.click()
      return true
    })()`)
    if (!ok) throw new Error('找不到可点击元素：' + text)
    await sleep(300)
  }

  async clickContains(text) {
    return this.clickText(text, { exact: false })
  }

  async pressTab(times = 1) {
    for (let i = 0; i < times; i++) {
      await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, nativeVirtualKeyCode: 9 })
      await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, nativeVirtualKeyCode: 9 })
      await sleep(120)
    }
  }

  async pressEnter() {
    await this.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 })
    await this.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13 })
    await sleep(300)
  }

  async activeElement() {
    return await this.evaluate(`(() => {
      const el = document.activeElement
      if (!el) return null
      return {
        tag: el.tagName,
        placeholder: el.getAttribute('placeholder') || '',
        text: (el.innerText || el.textContent || '').trim().slice(0, 40),
        type: el.getAttribute('type') || '',
      }
    })()`)
  }

  async metrics() {
    return await this.evaluate(`(() => ({
      innerWidth: window.innerWidth,
      scrollWidth: document.documentElement.scrollWidth,
      innerHeight: window.innerHeight,
      scrollHeight: document.documentElement.scrollHeight,
      overflowX: document.documentElement.scrollWidth > window.innerWidth + 1,
    }))()`)
  }

  async screenshot(file) {
    fs.mkdirSync(path.dirname(file), { recursive: true })
    const shot = await this.send('Page.captureScreenshot', { format: 'png' })
    fs.writeFileSync(file, Buffer.from(shot.data, 'base64'))
  }

  async clearStorage() {
    await this.evaluate('localStorage.clear(); sessionStorage.clear();')
  }

  async close() {
    try { this.ws.close() } catch { /* noop */ }
  }
}

export class Browser {
  static async launch() {
    const port = 9400 + Math.floor(Math.random() * 400)
    const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'q04-chrome-'))
    const chrome = spawn(CHROME, [
      '--headless=new',
      '--disable-gpu',
      '--hide-scrollbars',
      '--no-first-run',
      '--no-default-browser-check',
      '--disable-extensions',
      '--disable-background-networking',
      '--mute-audio',
      `--remote-debugging-port=${port}`,
      `--user-data-dir=${profile}`,
      'about:blank',
    ], { stdio: 'ignore' })

    const started = Date.now()
    while (Date.now() - started < 15000) {
      try {
        const response = await fetch(`http://127.0.0.1:${port}/json/version`)
        if (response.ok) break
      } catch { /* 还没起来 */ }
      await sleep(150)
    }

    return new Browser(port, chrome, profile)
  }

  constructor(port, chrome, profile) {
    this.port = port
    this.chrome = chrome
    this.profile = profile
    this.pages = []
  }

  async newPage(options = {}) {
    const target = await (await fetch(`http://127.0.0.1:${this.port}/json/new?${encodeURIComponent('about:blank')}`, { method: 'PUT' })).json()
    const page = new Page(this.port, target.webSocketDebuggerUrl, target.id)
    await page.connect()
    await page.init(options)
    this.pages.push(page)
    return page
  }

  async close() {
    for (const page of this.pages) await page.close()
    try { this.chrome.kill() } catch { /* noop */ }
    await sleep(300)
    try { fs.rmSync(this.profile, { recursive: true, force: true }) } catch { /* noop */ }
  }
}

export { sleep }
