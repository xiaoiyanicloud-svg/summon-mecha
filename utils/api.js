/**
 * API 模块集合 —— 对接 Bagisto（Laravel）后端 REST API
 *
 * 覆盖：认证 / 商品 / 分类 / 购物车 / 结算下单 / 订单 / 地址
 * 全部请求经过 utils/request.js 统一封装（自动带 Bearer token）
 */
import { http } from './request'

// ==================== 认证 ====================
export const authApi = {
  login: (email, password) => http.post('/api/customer/login', { email, password }, { loading: true, loadingText: '登录中...' }),
  register: (payload) => http.post('/api/customer/register', payload, { loading: true, loadingText: '注册中...' }),
  // 登出：通知后端撤销当前 Sanctum token（即使请求失败，前端也会清本地登录态）
  logout: () => http.post('/api/customer/logout', {}, { loading: false }),
}

// ==================== 商品 ====================
export const productApi = {
  // 商品列表：params 支持 { category_id, search=query, limit, page, sort }
  getProducts: (params = {}) => http.get('/api/products', { data: params, loading: false }),
  // 单商品详情
  getProductDetail: (id) => http.get(`/api/products/${id}`, { loading: false }),
  // 相关商品
  getRelatedProducts: (id) => http.get(`/api/products/${id}/related`, { loading: false }),
}

// ==================== 分类 ====================
export const categoryApi = {
  getCategories: (params = {}) => http.get('/api/categories', { data: params, loading: false }),
  getCategoryTree: () => http.get('/api/categories/tree', { loading: false }),
}

// ==================== 购物车 ====================
export const cartApi = {
  getCart: () => http.get('/api/checkout/cart', { loading: false }),
  // data: { product_id, quantity, is_buy_now }
  addToCart: (data) => http.post('/api/checkout/cart', data, { loading: false }),
  updateCart: (data) => http.put('/api/checkout/cart', data, { loading: false }),
  removeCartItem: (cartItemId) => http.delete('/api/checkout/cart', { cart_item_id: cartItemId }, { loading: false }),
}

// ==================== 结算 / 下单 ====================
export const checkoutApi = {
  summary: () => http.get('/api/checkout/onepage/summary', { loading: false }),
  // data: { billing: {...}, shipping: {...} }  （billing.use_for_shipping=1 时合二为一）
  storeAddress: (data) => http.post('/api/checkout/onepage/addresses', data, { loading: false }),
  // shipping_method: 形如 "flatrate_flatrate"
  storeShipping: (shippingMethod) => http.post('/api/checkout/onepage/shipping-methods', { shipping_method: shippingMethod }, { loading: false }),
  // payment: 支付方式编码，形如 "cashondelivery"
  // 注意：后端 Cart::savePaymentMethod(array $params) 需要 { method: code } 数组结构，故用数组包裹
  storePayment: (payment) => http.post('/api/checkout/onepage/payment-methods', { payment: { method: payment } }, { loading: false }),
  // 提交订单
  storeOrder: () => http.post('/api/checkout/onepage/orders', {}, { loading: false }),
}

// ==================== TOB 统一下单（租/买/配件/技能，真实后台订单） ====================
export const tobOrderApi = {
  // loading 统一开启:下单/留资在慢环节时给"提交中"反馈(mask 挡重复点击),避免用户误以为无响应
  create: (data) => http.post('/api/tob-order', data, { loading: true, loadingText: '提交中...' }),
}

// ==================== 目录聚合(机器页性能优化) ====================
// 1 个请求返回 {rent:[],buy:[],accessory:[],skill:[],scenes:[]};后端未部署时前端自动回退旧多请求逻辑
export const catalogApi = {
  getAggregate: () => http.get('/api/tob-catalog', { loading: false }),
}

// ==================== 订单 ====================
export const orderApi = {
  getOrders: (params = {}) => http.get('/api/customer/orders', { data: params, loading: false }),
  getOrderDetail: (id) => http.get(`/api/customer/orders/${id}`, { loading: false }),
  // 客户自助取消本人待处理订单
  cancelOrder: (id) => http.post(`/api/customer/orders/${id}/cancel`, {}, { loading: true, loadingText: '取消中...' }),
}

// ==================== 场景(TOB) ====================
// 机器页场景:列表(后台维护的卡片)+ 详情(TOB 场景真实图/描述)
export const sceneApi = {
  getScenes: (params = {}) => http.get('/api/scenes', { data: params, loading: false }),
  getSceneDetail: (id) => {
    // 避免 sceneId 为 undefined/'undefined'（部分卡片无 id）时请求 /api/scenes/undefined → 500
    if (!id || id === 'undefined') {
      return Promise.resolve(null)
    }
    return http.get(`/api/scenes/${id}`, { loading: false })
  },
}

// ==================== 赛事(生态中心赛事活动 tab) ====================
export const eventApi = {
  getEvents: () => http.get('/api/events', { loading: false }),
  // 真实报名(落 event_registrations 表,后台报名列表可查)
  register: (id, data) => http.post(`/api/events/${id}/register`, data, { loading: true, loadingText: '报名中...' }),
}

// ==================== 小召·召唤引擎(意图→场景→机型) ====================
export const summonApi = {
  summon: (q) => http.post('/api/summon', { q }, { loading: true, loadingText: '召唤中...' }),
}

// ==================== 收货地址 ====================
export const addressApi = {
  getAddresses: () => http.get('/api/customer/addresses', { loading: false }),
  createAddress: (data) => http.post('/api/customer/addresses', data, { loading: false }),
  updateAddress: (id, data) => http.put(`/api/customer/addresses/edit/${id}`, data, { loading: false }),
  // 删除地址(后端 9/7 新增 destroy 接口,仅允许删除本人地址)
  deleteAddress: (id) => http.delete(`/api/customer/addresses/${id}`, { loading: false }),
}