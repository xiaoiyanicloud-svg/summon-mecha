import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildPlan, formatPlan } from '../src/plan.mjs';
import { getVendor } from '../src/vendors.mjs';

const CMD = 'curl -fsSL https://x/agent/setup.sh | bash -s -- --token ABC';

test('计划四步且顺序固定：设IP → 探活 → SSH安装 → 恢复', () => {
	const plan = buildPlan({ vendor: getVendor('unitree'), os: 'linux', iface: 'eth0', installCommand: CMD });
	assert.deepEqual(plan.map((s) => s.id), ['host-ip', 'probe', 'ssh-install', 'restore']);
});

test('探活步骤 ping 的是该厂机器人默认 IP', () => {
	const plan = buildPlan({ vendor: getVendor('engineai'), os: 'linux', iface: 'eth0', installCommand: CMD });
	const probe = plan.find((s) => s.id === 'probe');
	assert.ok(probe.commands[0].includes('192.168.0.162'));
});

test('安装步骤把平台命令原样交给 SSH（不篡改）', () => {
	const plan = buildPlan({ vendor: getVendor('unitree'), os: 'linux', iface: 'eth0', installCommand: CMD });
	const ssh = plan.find((s) => s.id === 'ssh-install');
	assert.ok(ssh.commands[0].includes(JSON.stringify(CMD)));
});

test('缺 installCommand 直接抛错（不产出残缺计划）', () => {
	assert.throws(() => buildPlan({ vendor: getVendor('unitree'), os: 'linux', iface: 'eth0' }), /缺少 installCommand/);
});

test('formatPlan 含步骤标题与命令', () => {
	const plan = buildPlan({ vendor: getVendor('agibot'), os: 'darwin', iface: 'en0', installCommand: CMD });
	const text = formatPlan(plan, { vendor: getVendor('agibot'), device: { sn: 'SN-1' }, installCommand: CMD });
	assert.match(text, /SN-1/);
	assert.match(text, /\[1\/4\]/);
	assert.match(text, /\$ sudo ifconfig en0/);
});
