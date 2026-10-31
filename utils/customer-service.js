/**
 * 客服中心用户端共用文案与选项。
 *
 * 列表页与会话页共用同一份分类、状态、快捷话术，避免两边对同一条工单给出
 * 不同说法。这里只放用户看得懂的内容 —— 交付通道、轮询间隔这类内部实现
 * 一律不进入本文件，也就不可能被显示到界面上。
 */

export const SERVICE_NAME = '召唤机甲客服'
export const SERVICE_HOURS = '每日 9:00–21:00'
export const REPLY_PROMISE = '24 小时内回复'

export const STATUS_TEXT = {
	open: '待受理',
	pending: '待受理',
	processing: '处理中',
	resolved: '已解决',
	closed: '已关闭',
}

/**
 * 满意度评价标签 —— 必须与服务端 `SupportTicket::RATING_TAG_LABELS` 保持一致。
 * 评分是数字，标签才说明「为什么是这个分」。
 */
export const RATING_TAGS = [
	{ value: 'solved', label: '问题已解决' },
	{ value: 'fast', label: '回复及时' },
	{ value: 'polite', label: '态度好' },
	{ value: 'clear', label: '说明清楚' },
	{ value: 'unresolved', label: '仍未解决' },
	{ value: 'slow', label: '等待太久' },
]

export const CATEGORY_TEXT = {
	delivery: '下发/安装',
	skill: '技能功能',
	device: '设备问题',
	payment: '支付/退款',
	other: '其他',
}

export const CATEGORY_OPTIONS = [
	{ label: '其他问题', value: 'other' },
	{ label: '安装/下发', value: 'delivery' },
	{ label: '技能功能', value: 'skill' },
	{ label: '设备问题', value: 'device' },
	{ label: '支付/退款', value: 'payment' },
]

/** 常见问题：纯前端预填，不新增数据模型，也不伪造智能客服。 */
export const FAQ_ITEMS = [
	{
		category: 'delivery',
		question: '技能安装失败 / 下发一直超时',
		prompt: '技能安装失败，提示连接超时。',
	},
	{
		category: 'device',
		question: '设备无法连接或频繁掉线',
		prompt: '设备无法连接平台，或者连接后频繁掉线。',
	},
	{
		category: 'payment',
		question: '退款进度与到账时间',
		prompt: '我想查询退款进度和预计到账时间。',
	},
	{
		category: 'skill',
		question: '技能无法启动 / 功能异常',
		prompt: '技能可以安装，但启动失败或功能与描述不符。',
	},
]

/** 会话页快捷话术：按问题类型给出不同选项，避免所有工单都说同一句。 */
export const QUICK_REPLIES = {
	delivery: ['安装步骤看不懂', '提示连接超时', '需要远程协助'],
	skill: ['技能无法启动', '功能与描述不符', '需要卸载重装'],
	device: ['设备无法连接', '连接后频繁掉线', '需要检修'],
	payment: ['退款还没到账', '想取消订单', '需要开发票'],
	other: ['问题仍未解决', '补充订单号', '请人工继续跟进'],
}

export function categoryLabel(value) {
	return CATEGORY_TEXT[value] || value || '其他'
}

export function statusLabel(value) {
	return STATUS_TEXT[value] || value || '待受理'
}

export function quickRepliesFor(category) {
	return QUICK_REPLIES[category] || QUICK_REPLIES.other
}
