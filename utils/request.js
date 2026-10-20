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

/**
 * 从响应体里抽出**对用户有用**的错误提示。
 *
 * ⚠ 此前只取 `body.message || body.error`,后端 422 的**字段级错误**(`errors`)
 *   被整块丢掉 —— 用户提交表单失败时只能看到笼统的 `The given data was invalid.`,
 *   根本不知道是哪个字段错了。这里把第一条字段错误并进提示。
 *
 * 兼容两种后端外壳:`{message, errors}` 与统一信封 `{code, message, errors}`。
 */
function extractErrorMessage(body, statusCode) {
  const base = (body && (body.message || body.error)) || `请求失败(${statusCode})`
  const fields = body && body.errors
  if (fields && typeof fields === 'object') {
    // errors 形如 { field: ['消息', ...] },取第一个非空的
    const first = Object.keys(fields)
      .map(k => (Array.isArray(fields[k]) ? fields[k][0] : fields[k]))
      .find(v => typeof v === 'string' && v.trim())
    if (first && first !== base) return `${base}：${first}`
  }
  return base
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
        const message = extractErrorMessage(body, res.statusCode)
        reject({
          ...res,
          statusCode: res.statusCode,
          // 带上后端错误码与字段级错误,便于调用方做精确处理(此前只剩笼统 message)
          code: body.code,
          errors: body.errors,
          message,
        })
      },
      fail: (err) => {
        // ⚠ uni.request 的 fail 把**超时**与**连不上**混在同一个回调里。
        //   errMsg 含 `timeout` 的是超时(服务慢/网络卡),否则是网络层失败(连不上)。
        //   二者对用户的含义完全不同,不能都写成「网络异常」—— 超时被误诊会把人
        //   引向"后端没启动"的错误方向。
        const raw = (err && err.errMsg) || ''
        const isTimeout = /timeout/i.test(raw)
        reject({
          ...err,
          isTimeout,
          message: isTimeout
            ? '请求超时，请稍后重试'
            : '网络异常，请确认后端服务已启动',
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

/**
 * 文件上传（multipart）。与 request 共用 BASE_URL / token / 错误语义。
 *
 * 只用于确实需要上传字节的接口（G3 作品附件）。业务 JSON 仍走 request，
 * 避免页面私接另一套未认证客户端。
 */
function upload({
  url,
  filePath,
  name = 'file',
  formData = {},
  loading = false,
  loadingText = '上传中...',
  timeout = REQUEST_TIMEOUT,
}) {
  const headers = {
    Accept: 'application/json',
  }

  const token = getToken()
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  headers['X-Guest-Key'] = getGuestKey()

  if (loading) {
    uni.showLoading({ title: loadingText, mask: true })
  }

  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: BASE_URL + url,
      filePath,
      name,
      formData,
      header: headers,
      timeout,
      success: (res) => {
        let body = res.data

        if (typeof body === 'string') {
          try {
            body = JSON.parse(body)
          } catch (e) {
            body = { message: body }
          }
        }

        if (res.statusCode === 401) {
          uni.removeStorageSync(TOKEN_KEY)
          uni.removeStorageSync(CUSTOMER_KEY)
          uni.showToast({ title: '登录已失效，请重新登录', icon: 'none' })
          reject({
            statusCode: 401,
            message: (body && body.message) || '登录已失效',
            authExpired: !!token,
          })
          return
        }

        if (res.statusCode >= 200 && res.statusCode < 300) {
          resolve(body)
          return
        }

        reject({
          ...res,
          statusCode: res.statusCode,
          code: body && body.code,
          errors: body && body.errors,
          message: extractErrorMessage(body || {}, res.statusCode),
        })
      },
      fail: (err) => {
        const raw = (err && err.errMsg) || ''
        const isTimeout = /timeout/i.test(raw)
        reject({
          ...err,
          isTimeout,
          message: isTimeout ? '上传超时，请稍后重试' : '上传失败，请检查网络',
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
  upload: (url, filePath, formData = {}, opts = {}) => upload({ ...opts, url, filePath, formData }),
  put: (url, data = {}, opts = {}) => request({ ...opts, url, data, method: 'PUT' }),
  delete: (url, data = {}, opts = {}) => request({ ...opts, url, data, method: 'DELETE' }),
}

export default http