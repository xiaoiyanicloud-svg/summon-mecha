/**
 * 客服中心用户端共用文案与选项。
 *
 * 列表页与会话页共用同一份分类、状态、快捷话术，避免两边对同一条工单给出
 * 不同说法。这里只放用户看得懂的内容 —— 交付通道、轮询间隔这类内部实现
 * 一律不进入本文件，也就不可能被显示到界面上。
 */

export const SERVICE_NAME = '召唤机甲客服'
export const SERVICE_HOURS = '9:00–21:00'
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
	{ label: '下发/安装', value: 'delivery' },
	{ label: '设备问题', value: 'device' },
	{ label: '技能功能', value: 'skill' },
	{ label: '支付/退款', value: 'payment' },
	{ label: '其他', value: 'other' },
]

/** 常见问题：提供直接可执行的自助排查说明，支持一键转人工带入分类。 */
export const FAQ_ITEMS = [
	{
		category: 'delivery',
		question: '技能下发超时或安装失败怎么办？',
		answer: '请确认设备处于开机联网状态，并前往「我的 - 下发记录」查看失败原因。若提示网络超时，建议切换稳定的 Wi-Fi 后重新下发。',
		prompt: '技能下发或安装失败，请协助排查原因。',
	},
	{
		category: 'device',
		question: '设备显示离线或无法连接平台怎么排查？',
		answer: '请检查机体电源与网络连接是否正常，确认设备端服务已启动且序列号（SN）与「我的设备」中绑定的信息一致。',
		prompt: '设备显示离线或无法连接平台，请协助核查。',
	},
	{
		category: 'skill',
		question: '技能安装后无法启动或运行表现异常？',
		answer: '请核对当前设备固件及机型是否在技能兼容范围内，尝试重启设备端应用后再试；若仍异常，可附上设备型号联系客服排查。',
		prompt: '技能已安装但无法正常启动或运行异常。',
	},
	{
		category: 'payment',
		question: '订单取消、退款规则与到账时间说明',
		answer: '未履约订单可在「订单详情」直接取消；已发起的售后退款审核通过后将原路退回，通常在 1–3 个工作日内到账。',
		prompt: '我想咨询订单取消或退款到账进度。',
	},
	{
		category: 'payment',
		question: '如何申请开具发票？',
		answer: '订单完成后，可前往「我的 - 发票」提交电子普通发票或增值税专用发票申请，开具完成后支持在线查看与下载。',
		prompt: '我想咨询订单开具发票相关问题。',
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
