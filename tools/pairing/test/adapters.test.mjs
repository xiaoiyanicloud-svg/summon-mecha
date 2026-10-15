import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	milToCidr, hostIpSetCommands, hostIpRestoreCommands, sshCommand,
} from '../src/adapters.mjs';

test('milToCidr 换算', () => {
	assert.equal(milToCidr('255.255.255.0'), 24);
	assert.equal(milToCidr('255.255.0.0'), 16);
	assert.equal(milToCidr('255.255.255.240'), 28);
	assert.throws(() => milToCidr('255.255.255'), /非法掩码/);
	assert.throws(() => milToCidr('255.255.999.0'), /非法掩码/);
});

test('设置电脑 IP：Windows 用 netsh，name= 带引号', () => {
	assert.deepEqual(
		hostIpSetCommands({ os: 'win32', iface: '以太网', ip: '192.168.123.100' }),
		['netsh interface ip set address name="以太网" static 192.168.123.100 255.255.255.0'],
	);
});

test('设置电脑 IP：macOS 用 ifconfig inet', () => {
	assert.deepEqual(
		hostIpSetCommands({ os: 'darwin', iface: 'en0', ip: '192.168.123.100' }),
		['sudo ifconfig en0 inet 192.168.123.100 netmask 255.255.255.0'],
	);
});

test('设置电脑 IP：Linux 用 ip addr add（掩码转 CIDR）并拉起网卡', () => {
	assert.deepEqual(
		hostIpSetCommands({ os: 'linux', iface: 'eth0', ip: '192.168.123.100' }),
		['sudo ip addr add 192.168.123.100/24 dev eth0', 'sudo ip link set eth0 up'],
	);
});

test('设置电脑 IP：缺网卡名 / 未知系统 → 抛错（不静默）', () => {
	assert.throws(() => hostIpSetCommands({ os: 'linux', ip: '1.2.3.4' }), /缺少网卡名/);
	assert.throws(() => hostIpSetCommands({ os: 'plan9', iface: 'x', ip: '1.2.3.4' }), /不支持的系统/);
});

test('恢复网卡：三平台各自的自动获取命令', () => {
	assert.deepEqual(hostIpRestoreCommands({ os: 'win32', iface: '以太网' }), ['netsh interface ip set address name="以太网" dhcp']);
	assert.deepEqual(hostIpRestoreCommands({ os: 'darwin', iface: 'en0' }), ['sudo ipconfig set en0 DHCP']);
	assert.deepEqual(hostIpRestoreCommands({ os: 'linux', iface: 'eth0' }), ['sudo nmcli device reapply eth0']);
});

test('ssh 命令：无口令 → 纯 ssh，命令用 JSON 引号包裹', () => {
	const cmd = sshCommand({ user: 'unitree', host: '192.168.123.164', command: 'curl x | bash', os: 'win32' });
	assert.match(cmd, /^ssh -o StrictHostKeyChecking=no/);
	assert.match(cmd, /unitree@192\.168\.123\.164/);
	assert.match(cmd, /"curl x \| bash"$/);
	assert.ok(!cmd.includes('sshpass'));
});

test('ssh 命令：非 Windows 给口令 → 前缀 sshpass，口令单引号转义', () => {
	const cmd = sshCommand({ user: 'ubuntu', host: '192.168.0.162', command: 'echo hi', password: "a'b", os: 'linux' });
	assert.match(cmd, /^sshpass -p 'a'\\''b' ssh /);
});

test('ssh 命令：Windows 即便给了口令也不用 sshpass（系统无此物）', () => {
	const cmd = sshCommand({ user: 'unitree', host: 'h', command: 'x', password: '123', os: 'win32' });
	assert.ok(!cmd.includes('sshpass'));
});

test('ssh 命令：自定义端口', () => {
	const cmd = sshCommand({ user: 'u', host: 'h', command: 'x', port: 2222, os: 'linux' });
	assert.match(cmd, /-p 2222 /);
});

test('ssh 命令：缺 user/host 抛错', () => {
	assert.throws(() => sshCommand({ host: 'h', command: 'x' }), /需要 user 与 host/);
});
