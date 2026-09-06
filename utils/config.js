/**
 * 全局 API 配置
 *
 * BASE_URL：后端（Laravel/Bagisto）服务地址
 *  - H5 本地调试：指向本机 Laravel 服务（php artisan serve 默认 8000 端口）
 *  - 真机 / 小程序：需改为局域网 IP 或已部署的服务器域名（如 http://192.168.1.100:8000）
 *  - 微信小程序注意：需在后台配置 request 合法域名
 */
export const BASE_URL = 'http://127.0.0.1:8000'

/** 单个请求超时时间（毫秒） */
export const REQUEST_TIMEOUT = 15000

/** token 在本地存储中的 key */
export const TOKEN_KEY = 'token'

/** 用户信息在本地存储中的 key */
export const CUSTOMER_KEY = 'customer'