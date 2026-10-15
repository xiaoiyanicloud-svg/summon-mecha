import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseArgs } from '../src/pair.mjs';

test('--k v 形式', () => {
	assert.deepEqual(parseArgs(['--vendor', 'unitree', '--iface', 'en0']), { vendor: 'unitree', iface: 'en0' });
});

test('--k=v 形式', () => {
	assert.deepEqual(parseArgs(['--vendor=agibot', '--device-id=5']), { vendor: 'agibot', 'device-id': '5' });
});

test('布尔开关（后跟另一个 -- 或结尾）', () => {
	assert.deepEqual(parseArgs(['--execute', '--vendor', 'unitree']), { execute: true, vendor: 'unitree' });
	assert.deepEqual(parseArgs(['--json']), { json: true });
});

test('非 -- 开头的裸参数被忽略（不误吞）', () => {
	assert.deepEqual(parseArgs(['somefile', '--vendor', 'unitree']), { vendor: 'unitree' });
});

test('--command 里含空格与管道也能整段取到', () => {
	const cmd = 'curl -fsSL https://x/agent/setup.sh | bash -s -- --token ABC';
	assert.equal(parseArgs(['--command', cmd]).command, cmd);
});
