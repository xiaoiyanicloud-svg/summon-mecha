import { test } from 'node:test';
import assert from 'node:assert/strict';
import { VENDORS, vendorKeys, getVendor, DEFAULT_MASK } from '../src/vendors.mjs';

test('三厂齐备，key 与页面 brand 一致', () => {
	assert.deepEqual(vendorKeys(), ['unitree', 'agibot', 'engineai']);
});

test('宇树参数与 pages/mine/agent-setup.vue 的图文步骤一致', () => {
	const v = getVendor('unitree');
	assert.equal(v.robotIp, '192.168.123.164');
	assert.equal(v.sshUser, 'unitree');
	assert.equal(v.sshPassword, '123');
});

test('智元 / 众擎 的默认机器人 IP 与账号', () => {
	assert.equal(VENDORS.agibot.robotIp, '192.168.2.50');
	assert.equal(VENDORS.agibot.sshUser, 'agi');
	assert.equal(VENDORS.engineai.robotIp, '192.168.0.162');
	assert.equal(VENDORS.engineai.sshUser, 'ubuntu');
});

test('每台厂商电脑侧 IP 都要落在自己的网段里（否则同网段直连就不成立）', () => {
	for (const v of Object.values(VENDORS)) {
		assert.ok(v.hostIp.startsWith(v.subnetPrefix + '.'), `${v.key} 的 hostIp 不在 ${v.subnetPrefix} 网段`);
		assert.ok(v.robotIp.startsWith(v.subnetPrefix + '.'), `${v.key} 的 robotIp 不在 ${v.subnetPrefix} 网段`);
		assert.notEqual(v.hostIp, v.robotIp, `${v.key} 的 hostIp 撞了机器人 IP`);
	}
});

test('未知厂商抛错，不静默兜底成宇树', () => {
	assert.throws(() => getVendor('boston-dynamics'), /未知厂商/);
});

test('默认掩码是 /24', () => {
	assert.equal(DEFAULT_MASK, '255.255.255.0');
});
