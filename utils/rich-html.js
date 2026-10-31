/**
 * 富文本(HTML)渲染准备:把 Bagisto 后台的 description / short_description 整理成
 * `<rich-text :nodes>` 能直接吃的字符串。
 *
 * 为什么需要:Bagisto 的 description 是 tinymce 存下来的 HTML。uni-app 的 `<text>`
 * 不解析 HTML —— 直接绑定就原样吐出标签,页面上出现 `<p>7</p><p>【适用场景】…</p>`
 * 这种"奇奇怪怪的字符"(技能详情页曾经就是这样)。正文必须交给 `<rich-text>`。
 *
 * 顺带解决三件后台数据带来的问题:
 *   1) 图片地址 —— 后台存的是 `http://localhost/storage/...` 或 `/storage/...`,
 *      在 H5 / 真机上都打不开,统一换成本环境的 BASE_URL;
 *   2) 图片尺寸 —— tinymce 写死 width="660",窄屏会裁掉右半,改成 100% 宽自适应;
 *   3) 安全 —— H5 端 rich-text 最终走 innerHTML,后台被塞 <script>/onerror 就是 XSS,
 *      脚本标签与 on* 事件属性一律剥掉。
 *
 * 拿不到图/地址补不全时保持原样,不编内容。
 */
import { BASE_URL } from '@/utils/config'

// 后台写死的本机地址:换成当前环境的 BASE_URL(含端口)
const LOCAL_ORIGIN_RE = /^https?:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?/i

function rewriteUrl(raw) {
  const url = String(raw == null ? '' : raw).trim()
  if (!url) return url
  // 非"取资源"的协议原样放过;javascript: 直接废掉(与 stripDangerous 双保险)
  if (/^javascript:/i.test(url)) return '#'
  if (/^(?:data:|blob:|#|mailto:|tel:)/i.test(url)) return url

  const base = String(BASE_URL || '').replace(/\/+$/, '')
  if (!base) return url

  if (LOCAL_ORIGIN_RE.test(url)) return url.replace(LOCAL_ORIGIN_RE, base)
  if (url.indexOf('//') === 0) return (/^https:/i.test(base) ? 'https:' : 'http:') + url
  if (url.charAt(0) === '/') return base + url
  return url
}

// src / href 取值统一补全
function rewriteAttrUrls(html) {
  return html.replace(/\b(src|href)\s*=\s*("([^"]*)"|'([^']*)'|([^\s>]+))/gi,
    (_m, name, _quoted, dq, sq, bare) => {
      const value = dq !== undefined ? dq : (sq !== undefined ? sq : bare)
      return `${name}="${rewriteUrl(value)}"`
    })
}

// 图片去固定宽高、统一响应式
function responsiveImages(html) {
  return html.replace(/<img([^>]*)>/gi, (_m, attrs) => {
    const rest = String(attrs)
      .replace(/\s+(?:width|height|style)\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
      .replace(/\s*\/\s*$/, '')
    // width="100%" 走属性:uni-app 的 rich-text 对 img 宽度属性一定生效
    return `<img${rest} width="100%" style="height:auto;display:block;max-width:100%;margin:12px 0;">`
  })
}

// 脚本 / on* 事件 / javascript: 链接
function stripDangerous(html) {
  return html
    .replace(/<\s*(script|style|iframe|object|embed|form)\b[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
    .replace(/<\s*(script|style|iframe|object|embed|form)\b[^>]*\/?\s*>/gi, '')
    .replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
    .replace(/\b(href|src)\s*=\s*("|')\s*javascript:[^"']*\2/gi, '')
}

function escapeHtml(text) {
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/**
 * 富文本 → 可渲染 HTML 字符串(交给 `<rich-text :nodes>`)
 * 纯文本(不含标签)会转义并保留换行,避免一段没有 <p> 的描述挤成一行。
 */
export function normalizeRichHtml(html) {
  if (!html) return ''
  let out = String(html)

  if (!/<[a-z!/][^>]*>/i.test(out)) {
    return escapeHtml(out).replace(/\r\n?/g, '\n').replace(/\n/g, '<br>')
  }

  out = stripDangerous(out)
  out = responsiveImages(out)
  out = rewriteAttrUrls(out)
  return out
}

const ENTITY_MAP = { nbsp: ' ', amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", '#39': "'" }

/**
 * 富文本 → 纯文本(卡片摘要这类不该出现标签的位置用)
 */
export function htmlToPlainText(html) {
  if (!html) return ''
  return stripDangerous(String(html))
    .replace(/<\s*(?:br|hr)\s*\/?>/gi, '\n')
    .replace(/<\s*\/\s*(?:p|div|li|ul|ol|h[1-6]|tr|section|article|blockquote)\s*>/gi, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&(nbsp|amp|lt|gt|quot|apos|#39);/gi,
      (m, key) => (ENTITY_MAP[String(key).toLowerCase()] !== undefined ? ENTITY_MAP[String(key).toLowerCase()] : m))
    .replace(/&#(\d+);/g, (_m, code) => String.fromCharCode(Number(code)))
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}
