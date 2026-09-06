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

function request({ url, method = 'GET', data = {}, header = {}, loading = false, loadingText = '加载中...' }) {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...header,
  }

  const token = getToken()
  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  if (loading) {
    uni.showLoading({ title: loadingText, mask: true })
  }

  return new Promise((resolve, reject) => {
    uni.request({
      url: `${BASE_URL}${url}`,
      method,
      data,
      header: headers,
      timeout: REQUEST_TIMEOUT,
      success: (res) => {
        if (res.statusCode === 401) {
          // token 失效：清除本地登录态
          uni.removeStorageSync(TOKEN_KEY)
          uni.removeStorageSync(CUSTOMER_KEY)
          uni.showToast({ title: '登录已过期，请重新登录', icon: 'none' })
          reject(res)
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