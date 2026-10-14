/**
 * 全局 API 配置
 *
 * BASE_URL：后端（Laravel/Bagisto）服务地址
 *  - H5 本地调试：指向本机 Laravel 服务（php artisan serve 默认 8000 端口）
 *  - 真机 / 小程序：需改为局域网 IP 或已部署的服务器域名（如 http://192.168.1.100:8000）
 *  - 微信小程序注意：需在后台配置 request 合法域名
 */
export const BASE_URL = 'http://127.0.0.1:8000'

/**
 * 朗读（TTS）专用的后端地址。留空则回退到 BASE_URL。
 *
 * 为什么单独留一个开关 —— 开发期的 `php -S` 是**单进程**服务器（worker 依赖 fork，
 * Windows 上不可用），而 /api/summon-stream 是持续 8~20 秒的 SSE 流，会把唯一的工作
 * 进程占满。此时"边出边读"要发的 /api/tts 请求会排队到流结束 —— 退化成"出完再读"。
 *
 * 解决：再起一个后端进程，把 TTS 指过去即可解锁并发（两份进程同一份代码、同一个库）：
 *
 *   D:/php.exe -S 127.0.0.1:8001 -t D:/main/my-bagisto-store/public
 *
 * 然后取消下面这行的注释。⚠ 这纯粹是 `php -S` 的产物，线上 nginx + php-fpm 下不需要。
 * H5 跨端口不会被 CORS 拦（config/cors.php 里 api/* 的 allowed_origins 是 *）。
 */
export const TTS_BASE_URL = ''
// export const TTS_BASE_URL = 'http://127.0.0.1:8001'

/** 单个请求超时时间（毫秒） */
export const REQUEST_TIMEOUT = 15000

/** token 在本地存储中的 key */
export const TOKEN_KEY = 'token'

/** 用户信息在本地存储中的 key */
export const CUSTOMER_KEY = 'customer'