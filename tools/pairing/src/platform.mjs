/**
 * 平台协议对接 —— 从平台取「一次性安装命令」（B-14 里的「一键取命令的封装」）。
 *
 * 对接的是**已有的** C 端接口 `POST /api/devices/{id}/agent-token`
 * （my-bagisto-store/.../API/DeviceController.php::agentToken）：
 *   登录态（customer token）→ 返回 { message, data:{ token, expires_at, server_url, command } }。
 *   其中 command = `curl -fsSL <server>/agent/setup.sh | bash -s -- --token <一次性码>`。
 *
 * ⚠ 桌面工具怎么拿到「登录态」是本条**未落地**的关键：现接口挂在 `customer` 中间件下，
 *   需要用户 token。本工具先支持「外部传入 api-token」，把已登录态复用（例如从小程序
 *   web-view / 扫码取码）。**完整的桌面登录流（device-code / OAuth）属于产品决策，
 *   未做**，不臆造。
 *
 * ⚠ 服务端是 X1 信封：成功 { success:true, data }；失败 { success:false, code, message }。
 *   本函数对两种都容错，且**不假设 HTTP 2xx 就等于成功**。
 */

export class PlatformError extends Error {
	constructor(code, message) {
		super(message || code);
		this.name = 'PlatformError';
		this.code = code;
	}
}

/**
 * 纯函数：解析 agent-token 的响应体 → { command, token, expiresAt, serverUrl }。
 * 分出来是为了可单测（不依赖网络）。
 */
export function parseInstallCommandBody(body) {
	if (!body || typeof body !== 'object') {
		throw new PlatformError('PLATFORM_BAD_RESPONSE', '平台返回非 JSON 对象。');
	}
	if (body.success === false) {
		throw new PlatformError(body.code || 'PLATFORM_ERROR', body.message || '平台拒绝了请求。');
	}
	// 成功体有的走 ok() 包 data，有的（agentToken）直接返回 { message, data }。统一取 data。
	const data = body.data || body;
	const command = data.command;
	if (!command) {
		throw new PlatformError('PLATFORM_NO_COMMAND', '平台未返回安装命令（data.command 缺失）。');
	}
	return {
		command: String(command),
		token: data.token || null,
		expiresAt: data.expires_at || null,
		serverUrl: data.server_url || null,
	};
}

/**
 * 取一次性安装命令。fetchImpl 可注入（默认为全局 fetch），便于单测打桩。
 * @param {{serverUrl:string, deviceId:number|string, apiToken:string, fetchImpl?:Function}} o
 */
export async function fetchInstallCommand({ serverUrl, deviceId, apiToken, fetchImpl }) {
	if (!serverUrl) throw new PlatformError('MISSING_SERVER', '缺少平台地址（--server）。');
	if (!deviceId) throw new PlatformError('MISSING_DEVICE', '缺少设备 id（--device-id）。');
	const doFetch = fetchImpl || globalThis.fetch;
	if (typeof doFetch !== 'function') {
		throw new PlatformError('NO_FETCH', '当前运行环境无 fetch，请用 Node ≥18 或在调用处注入 fetchImpl。');
	}

	const url = `${String(serverUrl).replace(/\/+$/, '')}/api/devices/${encodeURIComponent(deviceId)}/agent-token`;
	const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
	if (apiToken) headers.Authorization = `Bearer ${apiToken}`;

	const res = await doFetch(url, { method: 'POST', headers, body: '{}' });
	let body = null;
	try {
		body = await res.json();
	} catch {
		throw new PlatformError('PLATFORM_BAD_RESPONSE', `平台返回非 JSON（HTTP ${res.status}）。`);
	}
	// 401/403 时信封里一般也有 code/message；这里优先用信封，兜底用状态码。
	if (res.status === 401 || res.status === 403) {
		throw new PlatformError(body.code || 'UNAUTHENTICATED', body.message || '未登录或无权访问该设备。');
	}
	return parseInstallCommandBody(body);
}
