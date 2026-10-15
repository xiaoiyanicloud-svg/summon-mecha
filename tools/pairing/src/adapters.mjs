/**
 * 平台适配层 —— 把「设网卡 IP」「SSH 执行」翻译成各操作系统的命令。
 *
 * ⚠ 这里全是**命令拼装（纯函数）**，可被单测逐字符断言。真正 **执行**这些命令
 *   由 CLI 注入 runner；命令能不能在真机上跑通，**没有真机就无法验证**
 *   （软件/系统版本差异、网卡名不同、权限不足都可能失败）—— 见 B-14 卡点。
 *   因此工具默认 `--dry-run`：先打印**将要执行的命令**，用户确认后再 `--execute`。
 */

import { DEFAULT_MASK } from './vendors.mjs';

/**
 * 点分掩码 → CIDR 位数（'255.255.255.0' → 24）。用于 Linux `ip addr add x/24`。
 */
export function milToCidr(mask) {
	const parts = String(mask || '').split('.').map((n) => Number(n));
	if (parts.length !== 4 || parts.some((n) => !Number.isInteger(n) || n < 0 || n > 255)) {
		throw new Error(`非法掩码：${mask}`);
	}
	return parts.reduce((bits, n) => bits + n.toString(2).replace(/0/g, '').length, 0);
}

/**
 * 把电脑网卡设成厂商静态 IP（同网段直连机器人）。
 * 返回**有序命令数组**（每台系统可能不止一条）。iface 为系统网卡名。
 */
export function hostIpSetCommands({ os, iface, ip, mask = DEFAULT_MASK }) {
	if (!iface) throw new Error('缺少网卡名（--iface），无法设置电脑 IP。');
	switch (os) {
		case 'win32':
			// netsh 用 name= 指定连接名（如「以太网」「Ethernet」）
			return [
				`netsh interface ip set address name="${iface}" static ${ip} ${mask}`,
			];
		case 'darwin':
			return [
				`sudo ifconfig ${iface} inet ${ip} netmask ${mask}`,
			];
		case 'linux':
			return [
				`sudo ip addr add ${ip}/${milToCidr(mask)} dev ${iface}`,
				`sudo ip link set ${iface} up`,
			];
		default:
			throw new Error(`不支持的系统：${os}（本工具覆盖 Windows / macOS / Linux）。`);
	}
}

/**
 * 配对结束后把网卡恢复成自动获取（否则用户上不了网）。
 */
export function hostIpRestoreCommands({ os, iface }) {
	if (!iface) throw new Error('缺少网卡名（--iface），无法恢复电脑 IP。');
	switch (os) {
		case 'win32':
			return [`netsh interface ip set address name="${iface}" dhcp`];
		case 'darwin':
			return [`sudo ipconfig set ${iface} DHCP`];
		case 'linux':
			// Linux 无「一键 DHCP」通用法，交给 NetworkManager（装了 nmcli 才有）
			return [`sudo nmcli device reapply ${iface}`];
		default:
			throw new Error(`不支持的系统：${os}`);
	}
}

/**
 * 拼 SSH 执行命令：在机器人上跑一条命令（就是把平台签发的一次性安装命令送进去）。
 *
 * ⚠ 口令处理是**现实限制**：
 *   - macOS/Linux：给了 password 就前缀 `sshpass -p`（需自装 sshpass；明文进进程列表，
 *     仅作便利，不适合生产）；
 *   - Windows 自带 OpenSSH **不支持非交互传口令** → 只能让用户在弹出的 ssh 里手敲。
 *   这是「不碰终端」目标尚未完全消除的最后一道门槛，**待真机验证后**再决定是否引入
 *   plink / 自定义 SSH 客户端。
 */
export function sshCommand({ user, host, command, port = 22, password = null, os = 'linux' }) {
	if (!user || !host) throw new Error('sshCommand 需要 user 与 host。');
	const base = [
		'ssh',
		'-o', 'StrictHostKeyChecking=no', // 首次连接不卡在 known_hosts 交互（直连调试口，非生产信任）
		'-o', 'UserKnownHostsFile=/dev/null',
		'-o', 'ConnectTimeout=5',
		'-p', String(port),
		`${user}@${host}`,
		JSON.stringify(String(command || '')),
	].join(' ');
	if (password && os !== 'win32') {
		// 单引号包裹并转义内部单引号：' -> '\''
		const pw = `'${String(password).replace(/'/g, `'\\''`)}'`;
		return `sshpass -p ${pw} ${base}`;
	}
	return base;
}
