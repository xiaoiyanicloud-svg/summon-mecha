#!/usr/bin/env node
/**
 * 召唤机甲 · 一键配对装机工具（CLI 骨架）
 *
 * 目标（docs/skill-supply-plan.md §4.22 / docs/skill-user-tutorial.md 结尾建议）：
 *   「用户插网线 → 点『连接并安装』 → 工具自动设 IP、SSH、装 Agent，全程图形化」。
 *
 * 本文件是这条链的**可执行骨架**：
 *   取平台一次性命令（platform.mjs） → 发现机器人（discovery.mjs）
 *   → 编排步骤（plan.mjs） → 按系统拼命令（adapters.mjs） → （可选）执行。
 *
 * ⚠ 诚实边界（务必先读）：
 *   1. **默认 dry-run**：不加 `--execute` 只打印「将要执行的命令」，不动网卡、不 SSH。
 *      真机命令能否跑通（网卡名/权限/系统版本/机器人固件差异）**未在真机验证过**
 *      —— B-14 卡点是「真机验证依赖 E7（外部）」，无真机无法给「已跑通」结论。
 *   2. **图形化外壳（Windows/Mac 桌面程序）未做**：它是本骨架的薄壳（直接调这里的
 *      纯函数），等核心在真机验证通过后再套壳，避免先造一个没真机就验不了的假 GUI。
 *   3. 不接入、不篡改任何厂商云（宇树 EULA 红线）。
 *
 * 用法示例：
 *   node tools/pairing/src/pair.mjs --list-vendors
 *   # 用小程序里复制到的安装命令（最贴近现状，无需平台登录态）：
 *   node tools/pairing/src/pair.mjs --vendor unitree --iface "以太网" \
 *        --command 'curl -fsSL https://.../agent/setup.sh | bash -s -- --token XXXX'
 *   # 或直接凭平台登录态取命令：
 *   node tools/pairing/src/pair.mjs --vendor unitree --iface en0 \
 *        --server https://<平台> --device-id 12 --api-token <用户token>
 *   # 确认无误后真正执行：
 *   node tools/pairing/src/pair.mjs ... --execute
 */

import { execSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { getVendor, vendorKeys, VENDORS } from './vendors.mjs';
import { discover } from './discovery.mjs';
import { buildPlan, formatPlan } from './plan.mjs';
import { fetchInstallCommand, PlatformError } from './platform.mjs';

/** 极简参数解析（零依赖）：--k v / --flag / --k=v 都收。 */
export function parseArgs(argv) {
	const args = {};
	for (let i = 0; i < argv.length; i++) {
		const tok = argv[i];
		if (!tok.startsWith('--')) continue;
		const eq = tok.indexOf('=');
		if (eq !== -1) {
			args[tok.slice(2, eq)] = tok.slice(eq + 1);
		} else {
			const key = tok.slice(2);
			const next = argv[i + 1];
			if (next === undefined || next.startsWith('--')) {
				args[key] = true;
			} else {
				args[key] = next;
				i++;
			}
		}
	}
	return args;
}

/** 真实命令执行器（子进程），注入给 discovery / 执行步骤用。 */
function realRun(cmd) {
	try {
		const stdout = execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
		return { stdout, code: 0 };
	} catch (e) {
		return { stdout: String(e.stdout || ''), code: e.status == null ? 1 : e.status };
	}
}

function printVendors() {
	console.log('可用厂商：');
	for (const k of vendorKeys()) {
		const v = VENDORS[k];
		console.log(`  ${k.padEnd(10)} ${v.name}  机器人 ${v.robotIp}  登录 ${v.sshUser}@  电脑设为 ${v.hostIp}`);
	}
}

const HELP = `召唤机甲 · 一键配对装机工具（骨架，默认 dry-run）

用法: node tools/pairing/src/pair.mjs --vendor <key> [选项]

必填:
  --vendor <key>        ${vendorKeys().join(' | ')}
  --iface <网卡名>       电脑网卡（如 Windows「以太网」/ macOS en0）。dry-run 可省略（用占位名）。

取安装命令（二选一）:
  --command "<curl ...>"                直接贴小程序里复制到的命令
  --server <url> --device-id <id> --api-token <token>   凭平台登录态现取

可选:
  --host-ip <ip>        覆盖电脑侧静态 IP（默认用厂商建议值）
  --sn <sn>             设备 SN（仅用于展示）
  --os <win32|darwin|linux>   覆盖系统判定（默认按当前系统）
  --json                以 JSON 输出计划
  --execute             真正执行（默认关闭；会改网卡、SSH）
  --list-vendors        列出厂商
  --help                显示本帮助
`;

async function main(argv) {
	const args = parseArgs(argv);

	if (args.help || args.h) { console.log(HELP); return 0; }
	if (args['list-vendors']) { printVendors(); return 0; }

	if (!args.vendor) {
		console.error('缺少 --vendor。用 --list-vendors 看可选厂商，--help 看用法。');
		return 2;
	}
	const vendor = getVendor(args.vendor);
	const os = args.os || process.platform;
	const execute = Boolean(args.execute);
	// --json 时把「人看的进度」打到 stderr，让 stdout 只剩纯 JSON（可被机器直接解析）。
	const log = (...a) => (args.json ? console.error : console.log)(...a);

	let iface = args.iface;
	if (!iface) {
		if (execute) { console.error('执行模式必须给 --iface（电脑网卡名）。'); return 2; }
		iface = '<你的网卡名>'; // dry-run 占位，让计划可读
	}

	// 1) 取安装命令
	let installCommand = args.command || '';
	if (!installCommand) {
		if (!args.server || !args['device-id']) {
			console.error('要么给 --command（直接贴命令），要么给 --server + --device-id（+ --api-token）现取。');
			return 2;
		}
		try {
			const r = await fetchInstallCommand({
				serverUrl: args.server,
				deviceId: args['device-id'],
				apiToken: args['api-token'] || '',
			});
			installCommand = r.command;
			log(`已从平台取到一次性命令（${r.expiresAt ? `有效期至 ${r.expiresAt}` : '有效期未知'}）。`);
		} catch (e) {
			if (e instanceof PlatformError) { console.error(`取命令失败：${e.code} ${e.message}`); return 3; }
			console.error(`取命令异常：${e.message}`); return 3;
		}
	}

	// 2) 发现机器人（真实 ping/arp；无真机即 found:false，如实呈现）
	log(`正在探测 ${vendor.name} 机器人（${vendor.robotIp}）...`);
	const found = discover({ vendor, os, run: realRun });
	log(found.found ? `✅ 找到机器人：${found.evidence.join('；')}` : `⚠ 未探到：${found.evidence.join('；')}（未插线/未开机/网段不符时正常）`);
	log('   ⚠ 未接真实机器人时，此结果不代表工具故障。');

	// 3) 编排 + 呈现
	const device = { id: args['device-id'], sn: args.sn };
	const plan = buildPlan({ vendor, device, os, iface, installCommand, hostIp: args['host-ip'] });

	if (args.json) {
		console.log(JSON.stringify({ vendor: vendor.key, os, iface, found, steps: plan }, null, 2));
	} else {
		log('');
		log(formatPlan(plan, { vendor, device, installCommand }));
	}

	// 4) 执行（默认关闭）
	if (!execute) {
		log('\n[dry-run] 以上仅为计划，未做任何改动。确认无误后加 --execute 真正执行。');
		return 0;
	}

	log('\n[execute] 开始执行（⚠ 真机命令未经真机验证，失败请按提示排查）...');
	for (const step of plan) {
		log(`\n>>> ${step.title}`);
		for (const cmd of step.commands) {
			log(`    $ ${cmd}`);
			const r = realRun(cmd);
			if (r.code !== 0) {
				console.error(`    命令返回码 ${r.code}。中止后续步骤（避免在错误态继续）。`);
				if (step.id === 'probe') console.error('    ping 不通：先别装，检查网线/网段/机器人开机。');
				return r.code || 1;
			}
		}
	}
	log('\n完成。回到平台「我的设备」，该机器人应显示「已配对」。');
	return 0;
}

// 直接运行时才执行（被 import 时不跑，便于单测引用 parseArgs）
if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
	// ⚠ 用 exitCode 而非 process.exit()：后者会在 fetch/socket 仍关闭中时被 libuv 报
	//   "UV_HANDLE_CLOSING" 断言（Windows 上实测到）。让 Node 自然收尾更干净。
	main(process.argv.slice(2)).then((code) => {
		if (code) process.exitCode = code;
	}).catch((e) => {
		console.error('未捕获异常：', e);
		process.exitCode = 1;
	});
}

export { main, realRun };
