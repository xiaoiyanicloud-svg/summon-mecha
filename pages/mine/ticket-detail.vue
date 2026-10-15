<template>
	<view class="detail-page">
		<!-- 导航栏 -->
		<nav-bar title="工单详情" />

		<scroll-view class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- 三态:加载中 / 故障(带重试) / 取不到这张工单。故障不伪装成「工单不存在」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!ticket"
				empty-icon="🛠️"
				empty-text="工单不存在"
				btn-color="#ff6b00"
				@retry="refresh"
			>
				<view class="card head-card">
					<view class="head-row">
						<text class="t-no">{{ ticket.ticket_no }}</text>
						<text class="t-status" :class="ticket.status">{{ statusText(ticket.status) }}</text>
					</view>
					<text class="t-cat">类别：{{ categoryText(ticket.category) }}</text>
				</view>

				<!-- B-10:若本工单已被定性为事故,如实告诉用户(此前投诉完就没下文) -->
				<view v-if="ticket.incident" class="card incident-card">
					<view class="ic-head">
						<text class="ic-title">已转为事故处理</text>
						<text class="ic-badge">{{ ticket.incident.severity_label }} · {{ ticket.incident.status_label }}</text>
					</view>
					<text class="ic-no">事故编号：{{ ticket.incident.incident_no }}</text>
					<text class="ic-tip">平台已把本工单定性为事故并进入追溯流程，处理进展见下方记录。</text>
				</view>

				<text class="sec-title">处理记录</text>

				<view v-if="!messages.length" class="empty-state small">
					<text class="empty-text">还没有处理记录</text>
				</view>
				<view v-else>
					<view
						v-for="m in messages"
						:key="m.id"
						class="card msg-card"
						:class="{ mine: m.actor_type === 'customer' }"
					>
						<view class="msg-head">
							<text class="msg-actor">{{ actorText(m.actor_type) }}</text>
							<text class="msg-time">{{ m.created_at }}</text>
						</view>
						<text class="msg-content">{{ m.content }}</text>
						<!-- 附件:后端 attachments 是数组;只做展示(上传能力尚未接入) -->
						<view v-if="m.attachments && m.attachments.length" class="msg-attach">
							<text v-for="(a, i) in m.attachments" :key="i" class="attach-item">{{ a }}</text>
						</view>
					</view>
				</view>

				<view style="height: 60rpx;"></view>
			</state-view>
		</scroll-view>
	</view>
</template>

<script>
import { ticketApi } from '@/utils/api'
import StateView from '@/components/StateView.vue'
import NavBar from '@/components/NavBar.vue'

/**
 * 工单详情 —— 消费既有接口 GET /api/tickets/{id}（shop.api.tickets.show）。
 *
 * ⚠ 此前列表项**没有任何点击入口**,接口虽已存在却无人调用 —— 客服回复了,
 *   用户永远看不到,工单闭环的最后一公里是断的（A8）。
 *
 * 状态/类别/留言者文案与列表页（tickets.vue）保持一致,避免同一单在两页显示成两样。
 */
const STATUS_TEXT = { open: '待处理', pending: '待处理', processing: '处理中', resolved: '已解决', closed: '已关闭' }
const CATEGORY_TEXT = { delivery: '下发/安装', skill: '技能功能', device: '设备问题', payment: '支付/退款', other: '其他' }
const ACTOR_TEXT = { customer: '我', admin: '平台客服', developer: '开发者', system: '系统' }

export default {
	components: { StateView, NavBar },
	data() {
		return {
			id: null,
			loading: true,
			loadError: '',
			ticket: null,
			messages: [],
		}
	},
	onLoad(options) {
		this.id = options && options.id ? Number(options.id) : null
		if (this.id) this.refresh()
		else this.loading = false // 没带 id = 链接坏了,走「工单不存在」空态
	},
	methods: {
		statusText(s) { return STATUS_TEXT[s] || s },
		categoryText(c) { return CATEGORY_TEXT[c] || (c || '其他') },
		actorText(a) { return ACTOR_TEXT[a] || a || '系统' },
		async refresh() {
			this.loading = true
			this.loadError = ''
			try {
				const res = await ticketApi.detail(this.id)
				const d = (res && res.data) || null
				this.ticket = d
				this.messages = (d && Array.isArray(d.messages)) ? d.messages : []
			} catch (e) {
				// ⚠ 故障 ≠「工单不存在」:置错误标记交给错误态(带重试),而不是落进空态
				this.loadError = (e && e.message) || '加载失败'
				this.ticket = null
				this.messages = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.detail-page { min-height: 100vh; background: #f5f6f8; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }

.head-card { margin-top: 24rpx; }
.head-row { display: flex; align-items: center; justify-content: space-between; }
.t-no { font-size: 30rpx; font-weight: 600; color: #222; }
.t-status { font-size: 24rpx; color: #ff6b00; }
.t-status.resolved, .t-status.closed { color: #52c41a; }
.t-cat { display: block; margin-top: 14rpx; font-size: 24rpx; color: #999; }

/* 已转为事故(B-10) */
.incident-card { background: #fff8f0; border: 1rpx solid #ffd9b8; }
.ic-head { display: flex; align-items: center; justify-content: space-between; }
.ic-title { font-size: 28rpx; font-weight: 600; color: #d46b08; }
.ic-badge { font-size: 22rpx; color: #d46b08; background: #fff1e0; border-radius: 20rpx; padding: 4rpx 16rpx; }
.ic-no { display: block; margin-top: 14rpx; font-size: 26rpx; color: #333; }
.ic-tip { display: block; margin-top: 8rpx; font-size: 23rpx; color: #999; line-height: 1.5; }

.sec-title { display: block; margin: 28rpx 24rpx 0; font-size: 28rpx; font-weight: 600; color: #333; }

.msg-card { padding: 22rpx 28rpx; }
.msg-card.mine { background: #fff7f0; }
.msg-head { display: flex; align-items: center; justify-content: space-between; }
.msg-actor { font-size: 25rpx; font-weight: 600; color: #ff6b00; }
.msg-card:not(.mine) .msg-actor { color: #165DFF; }
.msg-time { font-size: 22rpx; color: #aaa; }
.msg-content { display: block; margin-top: 12rpx; font-size: 27rpx; color: #333; line-height: 1.7; white-space: pre-wrap; }
.msg-attach { margin-top: 12rpx; }
.attach-item { display: block; font-size: 24rpx; color: #165DFF; line-height: 1.8; word-break: break-all; }

.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: 120rpx; }
.empty-state.small { padding-top: 40rpx; }
.empty-text { margin-top: 20rpx; font-size: 27rpx; color: #999; }
</style>
