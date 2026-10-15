import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	pingCommand, arpCommand, parsePingOutput, parseArpTable, normMac, probeHost, discover,
} from '../src/discovery.mjs';
import { getVendor } from '../src/vendors.mjs';

test('ping 命令按系统区分', () => {
	assert.equal(pingCommand('win32', '1.2.3.4'), 'ping -n 1 -w 1000 1.2.3.4');
	assert.equal(pingCommand('darwin', '1.2.3.4'), 'ping -c 1 -t 2 1.2.3.4');
	assert.equal(pingCommand('linux', '1.2.3.4'), 'ping -c 1 -W 1 1.2.3.4');
});

test('arp 命令按系统区分', () => {
	assert.equal(arpCommand('win32'), 'arp -a');
	assert.equal(arpCommand('darwin'), 'arp -an');
	assert.equal(arpCommand('linux'), 'ip neigh');
});

test('解析 Windows 中文 ping 回复', () => {
	const r = parsePingOutput('来自 192.168.123.164 的回复: 字节=32 时间<1ms TTL=64');
	assert.deepEqual(r, { alive: true, latencyMs: 1 });
});

test('解析 Windows 英文 ping 回复', () => {
	const r = parsePingOutput('Reply from 192.168.123.164: bytes=32 time=2ms TTL=64');
	assert.equal(r.alive, true);
	assert.equal(r.latencyMs, 2);
});

test('解析 macOS/Linux ping 回复', () => {
	const r = parsePingOutput('64 bytes from 192.168.123.164: icmp_seq=1 ttl=64 time=0.412 ms');
	assert.equal(r.alive, true);
	assert.equal(r.latencyMs, 0.412);
});

test('ping 超时 / 乱码 / 空 → 判为不可达（保守）', () => {
	assert.equal(parsePingOutput('请求超时。').alive, false);
	assert.equal(parsePingOutput('Request timed out.').alive, false);
	assert.equal(parsePingOutput('').alive, false);
	assert.equal(parsePingOutput(null).alive, false);
});

test('解析 Linux ip neigh', () => {
	const rows = parseArpTable('192.168.123.164 dev eth0 lladdr 00:11:22:33:44:55 REACHABLE');
	assert.deepEqual(rows, [{ ip: '192.168.123.164', mac: '00:11:22:33:44:55', iface: 'eth0' }]);
});

test('解析 macOS arp -an', () => {
	const rows = parseArpTable('? (192.168.123.164) at 0:11:22:33:44:55 on en0 ifscope [ethernet]');
	assert.equal(rows.length, 1);
	assert.equal(rows[0].ip, '192.168.123.164');
	assert.equal(rows[0].iface, 'en0');
});

test('解析 Windows arp -a（含接口表头行，需被跳过）', () => {
	const out = [
		'接口: 192.168.123.100 --- 0x8',
		'  Internet 地址          物理地址              类型',
		'  192.168.123.164       00-11-22-33-44-55     动态',
		'  192.168.123.255       ff-ff-ff-ff-ff-ff     静态',
	].join('\r\n');
	const rows = parseArpTable(out);
	const hit = rows.find((r) => r.ip === '192.168.123.164');
	assert.ok(hit);
	assert.equal(hit.mac, '00:11:22:33:44:55');
});

test('normMac 归一跨平台 MAC', () => {
	assert.equal(normMac('00-11-22-33-44-55'), '00:11:22:33:44:55');
	assert.equal(normMac('00:11:22:33:44:55'), '00:11:22:33:44:55');
});

test('probeHost 注入假 runner，不改真实网络', () => {
	const run = (cmd) => {
		assert.match(cmd, /^ping /);
		return { stdout: '64 bytes from 10.0.0.5: icmp_seq=1 ttl=64 time=1.0 ms', code: 0 };
	};
	assert.deepEqual(probeHost({ ip: '10.0.0.5', os: 'linux', run }), { ip: '10.0.0.5', alive: true, latencyMs: 1.0 });
});

test('discover：ping 通即 found，并回传 ARP MAC 作旁证', () => {
	const vendor = getVendor('unitree');
	const run = (cmd) => {
		if (cmd.startsWith('ping')) return { stdout: 'Reply from 192.168.123.164: bytes=32 time=1ms TTL=64', code: 0 };
		if (cmd.startsWith('arp')) return { stdout: '  192.168.123.164       00-11-22-33-44-55     动态', code: 0 };
		return { stdout: '', code: 1 };
	};
	const r = discover({ vendor, os: 'win32', run });
	assert.equal(r.found, true);
	assert.equal(r.ip, '192.168.123.164');
	assert.equal(r.mac, '00:11:22:33:44:55');
	assert.equal(r.evidence.length, 2);
});

test('discover：ping 不通即 found:false，不编造 MAC', () => {
	const vendor = getVendor('agibot');
	const run = () => ({ stdout: '请求超时。', code: 1 });
	const r = discover({ vendor, os: 'linux', run });
	assert.equal(r.found, false);
	assert.equal(r.mac, null);
});
