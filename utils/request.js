/**
 * 统一请求封装
 *
 * 统一处理：baseURL 拼接、Authorization Bearer token 注入、401 失效处理、
 * 错误信息提取、可选 loading/弹层，并 Promise 化 uni.request。
 */
import { BASE_URL, REQUEST_TIMEOUT, TOKEN_KEY, CUSTOMER_KEY } from './config'

function getToken() {
  return uni.getStorageSync(TOKEN_KEY) || ''
}

// 本机游客标识:首次访问生成后持久化,随每个请求带 X-Guest-Key。
// 后端用它把「游客订单」限定到产生它的那台设备 —— 否则游客订单列表只能靠
// customer_id IS NULL 去捞,会把所有人的游客单都返回给任何一个访客。
const GUEST_KEY_STORAGE = 'guest_key'

function generateGuestKey() {
  // 优先用真随机源(H5 有 crypto);小程序端没有同步的,退回 Math.random 混合时间戳。
  // 这个值只用来圈定游客订单、不是登录凭证,强度够用。
  const g = typeof globalThis !== 'undefined' ? globalThis : {}
  if (g.crypto && typeof g.crypto.getRandomValues === 'function') {
    const buf = new Uint8Array(16)
    g.crypto.getRandomValues(buf)
    return Array.from(buf, b => b.toString(16).padStart(2, '0')).join('')
  }
  let s = Date.now().toString(16)
  while (s.length < 32) {
    s += Math.floor(Math.random() * 0xffffffff).toString(16).padStart(8, '0')
  }
  return s.slice(0, 32)
}

function getGuestKey() {
  let key = uni.getStorageSync(GUEST_KEY_STORAGE)
  // 长度不足 16 的旧值一律作废重生成:后端也按这个门槛拒绝,不重生成会一直拿到空列表
  if (!key || String(key).length < 16) {
    key = generateGuestKey()
    uni.setStorageSync(GUEST_KEY_STORAGE, key)
  }
  return key
}

function request({ url, method = 'GET', data = {}, header = {}, loading = false, loadingText = '加载中...', timeout = REQUEST_TIMEOUT }) {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...header,
  }

  const token = getToken()
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  // 登录与否都带上:登录单按 customer_id 归属,游客单按这个标识归属
  headers['X-Guest-Key'] = getGuestKey()

  if (loading) {
    uni.showLoading({ title: loadingText, mask: true })
  }

  return new Promise((resolve, reject) => {
    uni.request({
      url: `${BASE_URL}${url}`,
      method,
      data,
      header: headers,
      timeout,
      success: (res) => {
        if (res.statusCode === 401) {
          const body = res.data || {}
          // ⚠ 原来 reject(res):res 是 uni 的原始响应对象,没有 message 字段
          //   (真正的消息在 res.data.message),调用方拿到的 e.message 是 undefined,
          //   于是只能退回一句笼统的兜底文案,真实原因(如"购买技能需要先登录")被吞掉。
          const message = body.message || '请先登录'

          // 只有"本来有 token 却失效"才算过期。从没登录过就报"登录已过期"是误报,
          // 会把用户引到错误的方向(他其实需要的是"去登录",不是"重新登录")。
          if (token) {
            uni.removeStorageSync(TOKEN_KEY)
            uni.removeStorageSync(CUSTOMER_KEY)
            uni.showToast({ title: '登录已过期，请重新登录', icon: 'none' })
          }

          reject({
            ...res,
            statusCode: 401,
            message,
            authExpired: !!token,
          })
          return
        }

        if (res.statusCode >= 200 && res.statusCode < 300) {
          resolve(res.data)
          return
        }

        const body = res.data || {}
        const message = body.message || body.error || `请求失败(${res.statusCode})`
        reject({
          ...res,
          statusCode: res.statusCode,
          message,
        })
      },
      fail: (err) => {
        reject({
          ...err,
          message: '网络异常，请确认后端服务已启动',
        })
      },
      complete: () => {
        if (loading) {
          uni.hideLoading()
        }
      },
    })
  })
}

export const http = {
  get: (url, opts = {}) => request({ ...opts, url, method: 'GET' }),
  post: (url, data = {}, opts = {}) => request({ ...opts, url, data, method: 'POST' }),
  put: (url, data = {}, opts = {}) => request({ ...opts, url, data, method: 'PUT' }),
  delete: (url, data = {}, opts = {}) => request({ ...opts, url, data, method: 'DELETE' }),
}

export default http