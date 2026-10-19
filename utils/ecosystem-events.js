/**
 * 生态赛事数据合同（G0）—— 只接受具备稳定 id/name 的后端记录。
 *
 * 空列表、请求失败和格式异常必须分别处理，不能把样例数据回填成正式数据。
 * 报名状态只认后端显式的 can_register，不从 status 猜测。
 */

export function normalizeEvent(raw) {
  if (!raw || typeof raw !== 'object') return null

  const id = Number(raw.id)
  const name = typeof raw.name === 'string' ? raw.name.trim() : ''

  if (!Number.isInteger(id) || id <= 0 || !name) return null

  return {
    id,
    name,
    type: typeof raw.type === 'string' ? raw.type : '',
    status: typeof raw.status === 'string' ? raw.status : '',
    date: typeof raw.date === 'string' ? raw.date : '',
    dateShort: typeof raw.dateShort === 'string' && raw.dateShort
      ? raw.dateShort
      : (typeof raw.date === 'string' ? raw.date : ''),
    city: typeof raw.city === 'string' ? raw.city : '',
    location: typeof raw.location === 'string' ? raw.location : '',
    organizer: typeof raw.organizer === 'string' ? raw.organizer : '',
    icon: typeof raw.icon === 'string' && raw.icon ? raw.icon : '🏆',
    bg: typeof raw.bg === 'string' && raw.bg ? raw.bg : '#E8F0FE',
    detail: typeof raw.detail === 'string' ? raw.detail : '',
    prize: typeof raw.prize === 'string' ? raw.prize : '',
    schedule: typeof raw.schedule === 'string' ? raw.schedule : '',
    history: typeof raw.history === 'string' ? raw.history : '',
    canRegister: raw.can_register === true,
    registrationReason: typeof raw.registration_reason === 'string' ? raw.registration_reason : '',
  }
}

export function normalizeEventList(payload) {
  if (!payload || !Array.isArray(payload.data)) {
    throw new Error('赛事列表响应格式异常')
  }

  return payload.data.map((row) => {
    const event = normalizeEvent(row)
    if (!event) throw new Error('赛事数据格式异常')
    return event
  })
}

/**
 * 报名提交结果判定。
 *
 * 只有后端显式返回 success===true 且 message 为非空字符串才算成功;
 * 请求层成功但响应体缺字段/结构不符,一律视为"结果未知",由用户稍后查回状态,
 * 不能拿默认文案伪造报名成功。
 */
export function eventRegistrationFeedback(res) {
  const message = res && typeof res.message === 'string' ? res.message.trim() : ''

  if (res && res.success === true && message) {
    return { success: true, message }
  }

  return { success: false, message: '提交结果未知，请稍后查回报名状态。' }
}

export function eventRegistrationState(event) {
  if (!event || !event.id) {
    return { enabled: false, reason: '赛事不存在。' }
  }

  if (event.status === 'ended') {
    return { enabled: false, reason: '该赛事已结束报名。' }
  }

  // 只认 register 阶段:status 缺失/未知也不能当可报名。
  if (event.status !== 'registering') {
    return { enabled: false, reason: '当前不在报名阶段。' }
  }

  if (event.canRegister !== true) {
    return { enabled: false, reason: event.registrationReason || '站内报名暂未开放。' }
  }

  return { enabled: true, reason: '' }
}
