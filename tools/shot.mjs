/**
 * _tools/shot.mjs — 基于 Chrome DevTools Protocol 的截图工具
 *
 * 为什么不用 `chrome --screenshot`：
 *   1. headless Chrome 的窗口有最小宽度（Windows 上约 494px），
 *      请求 390 宽会得到一个 494 宽的视口再裁切，移动端断点根本没被触发；
 *   2. 无法在截图前执行交互脚本（打开弹窗、切换语言、点击场景等）。
 * CDP 的 Emulation.setDeviceMetricsOverride 可以精确设定 CSS 视口宽度。
 *
 * 用法：
 *   node _tools/shot.mjs <url> <out.png> [width] [height] [dsf] [waitMs] [evalJs]
 * 例：
 *   node _tools/shot.mjs "file:///D:/.../index.html" out.png 390 844 2 3500
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9333 + Math.floor(Math.random() * 400);

const [url, out, w = 1920, h = 1080, dsf = 1, waitMs = 3500, evalJs = ''] = process.argv.slice(2);
if (!url || !out) {
  console.error('usage: node shot.mjs <url> <out.png> [w] [h] [dsf] [waitMs] [evalJs]');
  process.exit(1);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'mecha-shot-'));
const chrome = spawn(CHROME, [
  '--headless=new',
  '--disable-gpu',
  '--hide-scrollbars',
  '--no-first-run',
  '--no-default-browser-check',
  '--disable-extensions',
  '--disable-background-networking',
  '--mute-audio',
  `--remote-debugging-port=${PORT}`,
  `--user-data-dir=${profile}`,
  'about:blank'
], { stdio: 'ignore' });

function cleanup(code) {
  try { chrome.kill(); } catch { /* noop */ }
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch { /* noop */ }
  process.exit(code);
}

async function findPageTarget() {
  for (let i = 0; i < 100; i++) {
    try {
      const r = await fetch(`http://127.0.0.1:${PORT}/json/list`);
      if (r.ok) {
        const list = await r.json();
        const page = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
        if (page) return page.webSocketDebuggerUrl;
      }
    } catch { /* 还没起来 */ }
    await sleep(150);
  }
  throw new Error('DevTools 未就绪');
}

function connect(wsUrl) {
  return new Promise((resolve, reject) => {
    const ws = new WebSocket(wsUrl);
    let id = 0;
    const pending = new Map();

    ws.addEventListener('open', () => resolve(send));
    ws.addEventListener('error', reject);
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && pending.has(msg.id)) {
        pending.get(msg.id)(msg);
        pending.delete(msg.id);
      }
    });

    function send(method, params = {}) {
      return new Promise((res) => {
        const i = ++id;
        pending.set(i, res);
        ws.send(JSON.stringify({ id: i, method, params }));
      });
    }
  });
}

try {
  const wsUrl = await findPageTarget();
  const send = await connect(wsUrl);

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: Number(w),
    height: Number(h),
    deviceScaleFactor: Number(dsf),
    mobile: Number(dsf) > 1
  });

  await send('Page.navigate', { url });
  await sleep(Number(waitMs));

  if (evalJs) {
    const r = await send('Runtime.evaluate', { expression: evalJs, awaitPromise: true });
    if (r.result && r.result.exceptionDetails) {
      console.error('eval 出错:', JSON.stringify(r.result.exceptionDetails));
    }
    await sleep(900);
  }

  const shot = await send('Page.captureScreenshot', { format: 'png' });
  if (!shot.result || !shot.result.data) throw new Error('截图失败: ' + JSON.stringify(shot).slice(0, 200));

  fs.writeFileSync(out, Buffer.from(shot.result.data, 'base64'));
  console.log('OK ' + out);
  cleanup(0);
} catch (e) {
  console.error('FAIL ' + (e && e.message));
  cleanup(1);
}
