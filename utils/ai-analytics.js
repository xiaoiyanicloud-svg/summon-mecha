import { aiAnalyticsApi } from './api'
import { APP_VERSION } from './config'

const QUEUE_KEY = 'ai_analytics_queue'
const MAX_QUEUE = 100
const BATCH_SIZE = 20

let flushTimer = null
let flushing = false

function randomId(prefix) {
  const random = Math.random().toString(36).slice(2, 12)
  return `${prefix}_${Date.now().toString(36)}_${random}`
}

function readQueue() {
  try {
    const queue = uni.getStorageSync(QUEUE_KEY)
    return Array.isArray(queue) ? queue : []
  } catch (e) {
    return []
  }
}

function writeQueue(queue) {
  try {
    uni.setStorageSync(QUEUE_KEY, queue.slice(-MAX_QUEUE))
  } catch (e) { /* 埋点失败不能影响主流程 */ }
}

function platform() {
  try {
    const info = uni.getSystemInfoSync()
    return String(info.uniPlatform || info.platform || 'unknown')
  } catch (e) {
    return 'unknown'
  }
}

function cleanProperties(properties) {
  const safe = {}
  Object.keys(properties || {}).slice(0, 20).forEach((key) => {
    const value = properties[key]
    if (typeof value === 'string') safe[key] = value.slice(0, 200)
    else if (typeof value === 'number' || typeof value === 'boolean' || value === null) safe[key] = value
  })
  return safe
}

function scheduleFlush(delay = 2000) {
  if (flushTimer) clearTimeout(flushTimer)
  flushTimer = setTimeout(() => {
    flushTimer = null
    flushAiEvents()
  }, delay)
}

export function trackAiEvent(eventName, properties = {}) {
  const event = {
    event_id: randomId('evt'),
    event_name: eventName,
    occurred_at: new Date().toISOString(),
    properties: {
      ...cleanProperties(properties),
      app_version: APP_VERSION,
      platform: platform(),
    },
  }
  const queue = readQueue()
  queue.push(event)
  writeQueue(queue)

  if (queue.length >= BATCH_SIZE) flushAiEvents()
  else scheduleFlush()
}

export async function flushAiEvents() {
  if (flushing) return
  flushing = true

  try {
    while (true) {
      const queue = readQueue()
      if (!queue.length) break
      const batch = queue.slice(0, BATCH_SIZE)

      try {
        await aiAnalyticsApi.ingest(batch)
      } catch (e) {
        // 网络失败时保留队列，下一次进入页面或达到批量阈值再试。
        scheduleFlush(8000)
        break
      }

      const sent = new Set(batch.map(event => event.event_id))
      writeQueue(readQueue().filter(event => !sent.has(event.event_id)))
    }
  } finally {
    flushing = false
  }
}
