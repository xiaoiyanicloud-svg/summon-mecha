/**
 * 装机计划编排 —— 把「厂商 + 目标 IP + 平台命令」编成一条**有序、可打印、可执行**的步骤流。
 *
 * 这是「一键连接并安装」的骨架：GUI/CLI 都只是把这份计划渲染出来 / 逐步执行。
 * 计划是**纯数据（纯函数产出）**，所以能单测断言「拼出的命令序列对不对」。
 *
 * ⚠ 计划里的命令**未在真机验证过**（见 B-14 卡点）。默认以 dry-run 呈现。
 */

import { hostIpSetCommands, hostIpRestoreCommands, sshCommand } from './adapters.mjs';
import { pingCommand } from './discovery.mjs';

/**
 * 生成装机计划。
 * @param {object} o
 * @param {object} o.vendor   厂商配置（vendors.mjs）
 * @param {object} o.device   { id?, sn? }（仅用于展示，幂等不依赖它）
 * @param {string} o.os       运行工具的系统（process.platform）
 * @param {string} o.iface    电脑网卡名
 * @param {string} o.installCommand  平台签发的一次性安装命令
 * @param {string} [o.hostIp] 电脑侧静态 IP（默认用厂商建议值）
 * @returns {Array<{id:string,title:string,commands:string[],note:string}>}
 */
export function buildPlan({ vendor, device = {}, os, iface, installCommand, hostIp }) {
	if (!installCommand) throw new Error('buildPlan 缺少 installCommand（平台签发的一次性安装命令）。');
	const ip = hostIp || vendor.hostIp;

	return [
		{
			id: 'host-ip',
			title: `把电脑网卡设到 ${vendor.subnetPrefix}.0/24（${ip}）`,
			commands: hostIpSetCommands({ os, iface, ip }),
			note: '直连机器人调试口需要电脑与机器人同网段。可能需要管理员/sudo 权限。',
		},
		{
			id: 'probe',
			title: `确认能 ping 通机器人（${vendor.robotIp}）`,
			commands: [pingCommand(os, vendor.robotIp)],
			note: 'ping 不通先别装：检查网线、网段、机器人是否开机。',
		},
		{
			id: 'ssh-install',
			title: `SSH 登入 ${vendor.sshUser}@${vendor.robotIp} 并安装 Agent`,
			commands: [sshCommand({ user: vendor.sshUser, host: vendor.robotIp, port: vendor.sshPort, password: vendor.sshPassword, os, command: installCommand })],
			note: [
				vendor.notice,
				vendor.sshPassword ? `默认口令 ${vendor.sshPassword} 太弱：登录后请先 passwd 改密。` : '口令随机器，工具不预置。',
			].join('\n'),
		},
		{
			id: 'restore',
			title: `恢复电脑网卡（自动获取）`,
			commands: hostIpRestoreCommands({ os, iface }),
			note: '配对完成后务必恢复，否则电脑上不了网。',
		},
	];
}

/** 计划转可读文本（CLI 打印 / GUI 展示都可复用）。 */
export function formatPlan(plan, { vendor, device, installCommand } = {}) {
	const lines = [];
	if (vendor || device) {
		lines.push(`设备：${device?.sn || device?.id || '（未指定）'}　品牌：${vendor?.name || ''}`);
	}
	if (installCommand) lines.push(`安装命令：${installCommand}`);
	lines.push('---');
	plan.forEach((step, i) => {
		lines.push(`[${i + 1}/${plan.length}] ${step.title}`);
		step.commands.forEach((c) => lines.push(`    $ ${c}`));
		if (step.note) step.note.split('\n').forEach((n) => lines.push(`    · ${n}`));
	});
	return lines.join('\n');
}
