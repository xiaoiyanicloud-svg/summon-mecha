import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { parseInstallCommandBody, fetchInstallCommand, PlatformError } from '../src/platform.mjs';

test('解析成功信封（ok() 包 data 的形态）', () => {
	const r = parseInstallCommandBody({
		success: true,
		data: { command: 'curl A', token: 'T', expires_at: '2026-01-01 00:00:00', server_url: 'https://s' },
	});
	assert.equal(r.command, 'curl A');
	assert.equal(r.token, 'T');
	assert.equal(r.expiresAt, '2026-01-01 00:00:00');
	assert.equal(r.serverUrl, 'https://s');
});

test('解析成功信封（agentToken 直接 {message,data} 的形态，无 success 字段）', () => {
	const r = parseInstallCommandBody({ message: '已签发', data: { command: 'curl B' } });
	assert.equal(r.command, 'curl B');
});

test('失败信封 → 抛 PlatformError 带 code', () => {
	assert.throws(
		() => parseInstallCommandBody({ success: false, code: 'DEVICE_NOT_FOUND', message: '设备不存在。' }),
		(e) => e instanceof PlatformError && e.code === 'DEVICE_NOT_FOUND',
	);
});

test('缺 data.command → PLATFORM_NO_COMMAND', () => {
	assert.throws(() => parseInstallCommandBody({ success: true, data: {} }), (e) => e instanceof PlatformError && e.code === 'PLATFORM_NO_COMMAND');
});

test('非对象 → PLATFORM_BAD_RESPONSE', () => {
	assert.throws(() => parseInstallCommandBody(null), (e) => e instanceof PlatformError && e.code === 'PLATFORM_BAD_RESPONSE');
});

test('fetchInstallCommand：缺 server / device 提前抛错（不发请求）', async () => {
	await assert.rejects(() => fetchInstallCommand({ deviceId: 1, apiToken: 't' }), (e) => e.code === 'MISSING_SERVER');
	await assert.rejects(() => fetchInstallCommand({ serverUrl: 'https://s', apiToken: 't' }), (e) => e.code === 'MISSING_DEVICE');
});

test('fetchInstallCommand：打桩 fetch，校验 URL / 方法 / 鉴权头', async () => {
	let seen = null;
	const fetchImpl = async (url, opts) => {
		seen = { url, opts };
		return { status: 200, json: async () => ({ success: true, data: { command: 'curl C' } }) };
	};
	const r = await fetchInstallCommand({ serverUrl: 'https://s/', deviceId: 12, apiToken: 'tok', fetchImpl });
	assert.equal(r.command, 'curl C');
	assert.equal(seen.url, 'https://s/api/devices/12/agent-token');
	assert.equal(seen.opts.method, 'POST');
	assert.equal(seen.opts.headers.Authorization, 'Bearer tok');
});

test('fetchInstallCommand：401 → UNAUTHENTICATED', async () => {
	const fetchImpl = async () => ({ status: 401, json: async () => ({ success: false, code: 'UNAUTHENTICATED', message: '未登录。' }) });
	await assert.rejects(
		() => fetchInstallCommand({ serverUrl: 'https://s', deviceId: 1, apiToken: '', fetchImpl }),
		(e) => e instanceof PlatformError && e.code === 'UNAUTHENTICATED',
	);
});

test('fetchInstallCommand：非 JSON 响应 → PLATFORM_BAD_RESPONSE', async () => {
	const fetchImpl = async () => ({ status: 502, json: async () => { throw new Error('not json'); } });
	await assert.rejects(
		() => fetchInstallCommand({ serverUrl: 'https://s', deviceId: 1, apiToken: 't', fetchImpl }),
		(e) => e instanceof PlatformError && e.code === 'PLATFORM_BAD_RESPONSE',
	);
});

test('fetchInstallCommand：对真实本地 HTTP 服务端到端（Node 原生 fetch）', async () => {
	const server = http.createServer((req, res) => {
		// 断言服务端收到了正确的路径与鉴权
		assert.equal(req.url, '/api/devices/7/agent-token');
		assert.equal(req.method, 'POST');
		assert.equal(req.headers.authorization, 'Bearer real');
		res.writeHead(200, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ success: true, data: { command: 'curl REAL', token: 'X', expires_at: 'soon' } }));
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	const port = server.address().port;
	try {
		const r = await fetchInstallCommand({ serverUrl: `http://127.0.0.1:${port}`, deviceId: 7, apiToken: 'real' });
		assert.equal(r.command, 'curl REAL');
	} finally {
		await new Promise((resolve) => server.close(resolve));
	}
});
