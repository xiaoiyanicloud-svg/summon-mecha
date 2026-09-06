/**
 * 地址转换工具:后台接口行 ↔ 前端展示行(mine 地址簿 / checkout 结算共用)
 *
 * 后台地址行结构(实测):{id, first_name, last_name, address: [行1,行2], city, state, country,
 *                      postcode, phone, email, default_address}
 * 前端展示行结构:    {id, name, phone, province, city, district, detail, isDefault}
 */

/** 后台地址行 → 前端展示行 */
export function serverToLocal(a) {
  return {
    id: a.id,
    name: [a.first_name, a.last_name].filter(Boolean).join(' '),
    phone: a.phone || '',
    province: a.state || '',
    city: a.city || '',
    district: '',
    detail: Array.isArray(a.address) ? a.address.join(' ') : (a.address || ''),
    isDefault: !!a.default_address,
    server: true,
  }
}

/** 「张三」→ [张, 三]；「John Smith」→ [John, Smith]（Bagisto 要求 first/last 均非空） */
export function splitName(name) {
  const n = String(name || '').trim()
  const parts = n.split(/\s+/)
  if (parts.length > 1) return [parts[0], parts.slice(1).join(' ')]
  if (n.length > 1) return [n.charAt(0), n.slice(1)]
  return [n || '收', '件人']
}

/** 前端展示行 → 后台接口载荷(创建/更新用) */
export function localToServerPayload(d, email) {
  const [first_name, last_name] = splitName(d.name)
  return {
    first_name,
    last_name,
    phone: d.phone || '',
    address: [d.district ? `${d.district} ${d.detail || ''}`.trim() : (d.detail || '')].filter(Boolean),
    city: d.city || '',
    state: d.province || '',
    country: 'CN',
    postcode: '518000',
    email: email || '',
    default_address: d.isDefault ? 1 : 0,
  }
}
