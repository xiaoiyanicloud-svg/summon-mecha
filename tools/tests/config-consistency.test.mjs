import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import {
  BASE_URL,
  DEFAULT_API_BASE_URL,
  SSR_BASE_URL,
  resolveBaseUrl,
} from '../../utils/config.js'

test('前端 API 默认端口与本地服务统一为 8000', () => {
  assert.equal(DEFAULT_API_BASE_URL, 'http://127.0.0.1:8000')
  assert.equal(BASE_URL, DEFAULT_API_BASE_URL)
  assert.equal(SSR_BASE_URL, BASE_URL)
})

test('API 地址允许通过环境覆盖，并统一去除尾斜杠', () => {
  assert.equal(resolveBaseUrl('http://192.168.1.20:9000///'), 'http://192.168.1.20:9000')
  assert.equal(resolveBaseUrl('', 'http://127.0.0.1:8000'), 'http://127.0.0.1:8000')
})

test('非法 API 地址在启动时立即报错，而不是静默请求失败', () => {
  assert.throws(() => resolveBaseUrl('127.0.0.1:8000'), /无效的 API 地址/)
  assert.throws(() => resolveBaseUrl('ftp://127.0.0.1'), /无效的 API 地址/)
})

test('源码不再硬编码已废弃的 8008 默认端口', async () => {
  const source = await readFile(new URL('../../utils/config.js', import.meta.url), 'utf8')
  assert.doesNotMatch(source, /127\.0\.0\.1:8008/)
})
