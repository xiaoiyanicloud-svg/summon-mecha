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
 * 公开 SSR 站点根（G4-04/G4-05）。
 *
 * 作用只有一个：H5 详情页写 canonical / OG 时，指向 Laravel 服务端渲染的正式地址，
 * 而不是当前 H5 的 hash 地址（`/#/pages/...` 不适合作为 canonical）。
 *
 * ⚠ 生产必须改成与 `ECOSYSTEM_PUBLIC_SITE_URL` **完全一致**的域名（含 scheme，不带尾斜杠）；
 *   两者不一致会出现“H5 声明的 canonical 与 SSR 页面自报的 canonical 不同”。
 */
export const SSR_BASE_URL = 'http://127.0.0.1:8000'

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

/**
 * 对外展示的版本号（「我的 → 设置」与「关于」两处都读它）。
 *
 * 为什么要有这个常量：此前 `v2.1.0` 只硬写在设置页里，而「关于」页也要显示版本 ——
 * 两处各写一遍必然会分叉（同一个事实两个出处，谁也发现不了它们哪天对不上）。
 *
 * ⚠ 与 `manifest.json` 的 `versionName`（打包版本，当前 `1.0.0`）**不是同一个东西**：
 *   那个是构建产物标识，随打包走；这个是给用户看的产品版本。
 *   两者当前数值不一致，属既有状况，本常量**不改动**任何一方的取值。
 */
export const APP_VERSION = 'v2.1.0'
