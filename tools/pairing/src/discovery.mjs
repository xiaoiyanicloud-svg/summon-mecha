/**
 * 设备发现 —— 「插网线 → 点连接」里「连」的那半截。
 *
 * 现实约束（决定了这里怎么做）：三厂的机器人都是在**固定网段 + 出厂默认 IP**上，
 * 用户把电脑网线插到机器人调试口后，电脑要先进同一网段，再 ping 那个默认 IP。
 * 所以「发现」= ①把电脑网卡设进厂商网段（见 adapters.mjs）→ ②ping 默认 IP 探活。
 *
 * ⚠ 所有**解析函数是纯函数**（喂字符串 → 出结构），因此可被单测完全覆盖；
 *   真正执行 ping / arp 的 `run()` 由调用方注入 —— 单测注入假 runner，
 *   真机由 CLI 注入 child_process。**没有真机就无法验证真实连通性**，
 *   但「解析对不对、命令拼得对不对」在这里是**可验证**的。
 *
 * 跨平台：Windows / macOS / Linux 的 ping、arp 输出格式都不同，逐一解析。
 */

/** ping 命令（-n / -c 一次探活；超时收紧，避免卡住 GUI）。 */
export function pingCommand(os, ip) {
	if (os === 'win32') return `ping -n 1 -w 1000 ${ip}`;
	// macOS / Linux 通用：-c 1 一次，-W 秒级超时（Linux 是秒；macOS 是毫秒，容忍）。
	return os === 'darwin' ? `ping -c 1 -t 2 ${ip}` : `ping -c 1 -W 1 ${ip}`;
}

/** arp 表命令（拿 MAC，用于「是不是这台机器人」的旁证）。 */
export function arpCommand(os) {
	if (os === 'win32') return 'arp -a';
	if (os === 'darwin') return 'arp -an';
	return 'ip neigh'; // Linux（现代发行版）
}

/**
 * 解析 ping 输出 → { alive, latencyMs }。
 * 覆盖中英文 Windows、macOS/Linux 三种。认不出「可达」即为不可达（保守）。
 */
export function parsePingOutput(text) {
	const out = String(text || '');
	// Windows 中文
	if (/来自\s*[\d.]+\s*的回复[:：]/.test(out)) return { alive: true, latencyMs: parseWinLatency(out) };
	// Windows 英文
	if (/Reply from\s+[\d.]+[:：]/i.test(out)) return { alive: true, latencyMs: parseWinLatency(out) };
	// macOS / Linux
	const m = out.match(/bytes from\s+[\d.]+:[^\n]*?time[=<]([\d.]+)\s*ms/i);
	if (m) return { alive: true, latencyMs: Number(m[1]) };
	return { alive: false, latencyMs: null };
}

function parseWinLatency(text) {
	// 中文「时间<1ms」「时间=2ms」；英文「time<1ms」「time=2ms」
	const m = text.match(/(?:时间|time)[=<]([\d.]+)\s*ms/i);
	return m ? Number(m[1]) : null;
}

/**
 * 解析 arp / ip neigh 输出 → [{ ip, mac, iface }]。
 * 三种格式：
 *   Windows : "  192.168.123.164       00-11-22-33-44-55     动态"
 *   macOS   : "? (192.168.123.164) at 0:11:22:33:44:55 on en0 ifscope [ethernet]"
 *   Linux   : "192.168.123.164 dev eth0 lladdr 00:11:22:33:44:55 REACHABLE"
 * 解析不出的行（表头、静态项 <incomplete>）直接跳过 —— 宁少不错。
 */
export function parseArpTable(text) {
	const rows = [];
	for (const raw of String(text || '').split(/\r?\n/)) {
		const line = raw.trim();
		if (!line) continue;

		// Linux: ip neigh
		let m = line.match(/^([\d.]+)\s+dev\s+(\S+)\s+lladdr\s+([0-9a-f:]{11,17})/i);
		if (m) { rows.push({ ip: m[1], mac: normMac(m[3]), iface: m[2] }); continue; }

		// macOS: arp -an
		m = line.match(/\((\d+\.\d+\.\d+\.\d+)\)\s+at\s+([0-9a-f:]{11,17})\s+on\s+(\S+)/i);
		if (m) { rows.push({ ip: m[1], mac: normMac(m[2]), iface: m[3] }); continue; }

		// Windows: arp -a（MAC 是 00-11-22-... 形式）
		m = line.match(/^(\d+\.\d+\.\d+\.\d+)\s+([0-9a-f]{2}(?:-[0-9a-f]{2}){5})/i);
		if (m) { rows.push({ ip: m[1], mac: normMac(m[2]), iface: null }); continue; }
	}
	return rows;
}

/** MAC 归一为小写冒号分隔（跨平台可比）。 */
export function normMac(mac) {
	return String(mac || '').trim().toLowerCase().replace(/-/g, ':');
}

/** 单机探活：ping 一次 → { ip, alive, latencyMs }。run 由调用方注入。 */
export function probeHost({ ip, os, run }) {
	const r = run(pingCommand(os, ip));
	const parsed = parsePingOutput(r && r.stdout);
	return { ip, alive: parsed.alive, latencyMs: parsed.latencyMs };
}

/**
 * 发现机器人：只探该厂**出厂默认 IP**（用户插线直连即此地址）。
 * 返回 { found, ip, evidence[] }。evidence 记录「凭什么认为找到了」，不空口宣称。
 *
 * 若给了 arp 表，会把默认 IP 的 MAC 一并附上做旁证（MAC 是否属于该厂 OUI 属于
 * **待补**：各厂 OUI 未在文档中给出，本工具不臆造 —— 只如实回传 MAC）。
 */
export function discover({ vendor, os, run }) {
	const evidence = [];
	const probe = probeHost({ ip: vendor.robotIp, os, run });
	if (!probe.alive) {
		return { found: false, ip: vendor.robotIp, mac: null, evidence: [`ping ${vendor.robotIp} 无应答`] };
	}
	evidence.push(`ping ${vendor.robotIp} 通${probe.latencyMs != null ? `（${probe.latencyMs}ms）` : ''}`);

	let mac = null;
	try {
		const arp = run(arpCommand(os));
		const row = parseArpTable(arp && arp.stdout).find((r) => r.ip === vendor.robotIp);
		if (row) { mac = row.mac; evidence.push(`ARP 命中 MAC ${row.mac}${row.iface ? `（网卡 ${row.iface}）` : ''}`); }
	} catch {
		// arp 拿不到不影响「ping 通」的主判据
	}

	return { found: true, ip: vendor.robotIp, mac, evidence };
}
