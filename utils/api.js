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
  // data: { product_id?, sku?, name, price, qty, type(rent|buy|plan|custom), name/phone/city/address, ... }
  create: (data) => http.post('/api/tob-order', data, { loading: false }),
}

// ==================== 订单 ====================
export const orderApi = {
  getOrders: (params = {}) => http.get('/api/customer/orders', { data: params, loading: false }),
  getOrderDetail: (id) => http.get(`/api/customer/orders/${id}`, { loading: false }),
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

// ==================== 收货地址 ====================
export const addressApi = {
  getAddresses: () => http.get('/api/customer/addresses', { loading: false }),
  createAddress: (data) => http.post('/api/customer/addresses', data, { loading: false }),
  updateAddress: (id, data) => http.put(`/api/customer/addresses/edit/${id}`, data, { loading: false }),
}