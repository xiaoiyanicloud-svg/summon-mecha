<template>
	<view class="page-container">
		<nav-bar :title="ticket ? categoryLabel(ticket.category) : '客服会话'" fallback-tab="/pages/mine/tickets">
			<template #right>
				<view
					v-if="showEnd"
					class="nav-end-btn"
					role="button"
					tabindex="0"
					aria-label="结束会话"
					@tap="endConversation"
				>
					<text>结束</text>
				</view>
			</template>
		</nav-bar>

		<state-view
			v-if="loading || loadError || !ticket"
			:loading="loading"
			loading-text="正在同步会话…"
			:error="loadError"
			error-text="暂时无法加载会话"
			:empty="!loading && !loadError && !ticket"
			empty-icon="/static/icons/mine/empty-inbox.png"
			empty-text="会话不存在"
			empty-hint="链接可能已失效，请返回客服中心重新进入。"
			empty-action
			empty-action-text="返回客服中心"
			btn-color="#165DFF"
			@empty-action="goBack"
			@retry="fetchTicket(false)"
		/>

		<view v-else class="chat-shell">
			<view class="service-bar">
				<view class="service-left">
					<text class="status-tag" :class="'st-' + (ticket.held ? 'held' : ticket.status)">{{ ticketStatusText }}</text>
				</view>
				<text class="service-hours">人工服务 {{ serviceHours }}</text>
			</view>

			<view v-if="syncError" class="sync-banner">
				<text>网络不稳定，消息同步可能延迟，正在重试</text>
			</view>

			<view v-if="ticket.incident" class="incident-banner">
				<SmIcon name="state-warn" size="36rpx" />
				<view class="incident-left">
					<text class="incident-title">已进入事故处理流程</text>
					<text class="incident-meta">{{ ticket.incident.incident_no }} · {{ ticket.incident.severity_label }} · {{ ticket.incident.status_label }}</text>
				</view>
			</view>

			<scroll-view
				class="messages-scroll"
				scroll-y="true"
				:enhanced="true"
				:show-scrollbar="false"
				:scroll-into-view="scrollAnchor"
				:scroll-with-animation="true"
			>
				<view class="messages-body">
					<view v-if="!renderedMessages.length" class="welcome-note">
						<text>{{ introDesc }}</text>
					</view>

					<block v-for="message in renderedMessages" :key="message.id">
						<view v-if="message.showDay" class="day-divider">
							<text>{{ message.dayLabel }}</text>
						</view>

						<view v-if="message.actor_type === 'system'" class="system-row" :id="'message-' + message.id">
							<text>{{ message.content }}</text>
						</view>

						<view
							v-else
							class="message-row"
							:class="{ mine: message.actor_type === 'customer' }"
							:id="'message-' + message.id"
						>
							<view class="message-column">
								<view class="message-meta">
									<text class="message-actor">{{ actorText(message.actor_type) }}</text>
									<text class="message-time">{{ clockTime(message.created_at) }}</text>
									<text
										v-if="message.actor_type === 'customer'"
										class="message-receipt"
										:class="{ read: !!message.read_at }"
									>{{ message.read_at ? '已读' : '已送达' }}</text>
								</view>
								<view class="message-bubble">
									<text class="message-content">{{ message.content }}</text>
									<view v-if="message.attachments && message.attachments.length" class="attachment-list">
										<block v-for="(attachment, index) in message.attachments" :key="index">
											<view v-if="isProductAttachment(attachment)" class="message-product-card">
												<view class="message-product-image">
													<image v-if="attachment.image" :src="attachment.image" mode="aspectFill"></image>
													<SmIcon v-else :name="attachmentIconName(attachment)" size="38rpx" />
												</view>
												<view class="message-product-main">
													<text class="message-product-name">{{ attachment.name }}</text>
													<text class="message-product-meta">{{ attachmentMetaText(attachment) }}</text>
												</view>
											</view>
											<view v-else class="attachment" @click="copyAttachment(attachment)">
												<SmIcon name="doc-text" size="22rpx" />
												<text class="attachment-text">附件 {{ index + 1 }}（点击复制）</text>
											</view>
										</block>
									</view>
								</view>
							</view>
						</view>
					</block>

					<view id="message-bottom" class="message-bottom"></view>
				</view>
			</scroll-view>

			<view v-if="ticket.can_rate || ticket.rating" class="rating-card">
				<block v-if="ticket.can_rate">
					<view class="rating-head">
						<text class="rating-title">服务评价</text>
						<text class="rating-sub">评价会进入客服复盘</text>
					</view>
					<view class="rating-stars">
						<text
							v-for="star in 5"
							:key="star"
							class="rating-star"
							:class="{ on: star <= ratingValue }"
							@tap="setRating(star)"
						>★</text>
						<text class="rating-value">{{ ratingText }}</text>
					</view>
					<view class="rating-tags">
						<view
							v-for="tag in ratingTags"
							:key="tag.value"
							class="rating-tag"
							:class="{ active: ratingSelected.includes(tag.value) }"
							@tap="toggleTag(tag.value)"
						>
							<text>{{ tag.label }}</text>
						</view>
					</view>
					<textarea
						v-model="ratingComment"
						class="rating-input"
						maxlength="300"
						placeholder="补充一句评价说明（选填）"
						placeholder-class="rating-placeholder"
					/>
					<view
						class="rating-submit"
						:class="{ disabled: !ratingValue || ratingSubmitting }"
						role="button"
						tabindex="0"
						aria-label="提交评价"
						@tap="submitRating"
					>
						<text>{{ ratingSubmitting ? '提交中…' : '提交评价' }}</text>
					</view>
				</block>
				<view v-else class="rating-done">
					<text class="rating-done-stars">{{ starText(ticket.rating) }}</text>
					<text class="rating-done-text">已评价 · 感谢你的反馈</text>
				</view>
			</view>

			<view v-if="staffTyping" class="typing-indicator">
				<view class="typing-dots"><text></text><text></text><text></text></view>
				<text class="typing-text">客服正在输入…</text>
			</view>

			<view class="composer-panel">
				<scroll-view v-if="canReply && !hasStaffReply" class="quick-scroll" scroll-x="true" :enhanced="true" :show-scrollbar="false">
					<view class="quick-list">
						<view v-for="reply in quickReplies" :key="reply" class="quick-chip" @tap="useQuickReply(reply)">
							<text>{{ reply }}</text>
						</view>
					</view>
				</scroll-view>

				<view v-if="canReply" class="input-area">
					<view class="input-main">
						<textarea
							v-model="draft"
							class="input-box"
							rows="1"
							:placeholder="composerPlaceholder"
							placeholder-class="input-placeholder"
							maxlength="2000"
							auto-height="true"
							confirm-type="send"
							cursor-spacing="18"
							@input="onDraftInput"
							@confirm="sendMessage"
						/>
						<text v-if="draft.length > 1600" class="input-count sm-mono">{{ draft.length }}/2000</text>
					</view>
					<view
						class="send-btn"
						:class="{ disabled: sending || !draft.trim() }"
						role="button"
						tabindex="0"
						aria-label="发送消息"
						@tap="sendMessage"
					>
						<text>{{ sending ? '…' : '发送' }}</text>
					</view>
				</view>

				<view v-else class="closed-panel">
					<view class="closed-copy">
						<text class="closed-title">本次会话已结束</text>
						<text class="closed-desc">如果问题仍需处理，可返回客服中心发起新咨询。</text>
					</view>
					<view class="closed-btn" role="button" tabindex="0" @tap="goBack">返回客服中心</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import SmIcon from '@/components/SmIcon.vue'
import StateView from '@/components/StateView.vue'
import { ticketApi } from '@/utils/api'
import {
	RATING_TAGS,
	SERVICE_HOURS,
	categoryLabel,
	statusLabel,
	quickRepliesFor,
} from '@/utils/customer-service'

const ACTOR_TEXT = { customer: '我', admin: '平台客服', agent: '在线客服', staff: '在线客服', developer: '技术团队', system: '系统' }
const SEEN_KEY = 'customer_service_seen_tickets_v1'

export default {
	components: { NavBar, SmIcon, StateView },
	data() {
		return {
			id: null,
			loading: true,
			loadError: '',
			ticket: null,
			messages: [],
			draft: '',
			sending: false,
			refreshing: false,
			syncError: '',
			scrollAnchor: '',
			pollTimer: null,
			ratingValue: 0,
			ratingSelected: [],
			ratingComment: '',
			ratingSubmitting: false,
			ratingTags: RATING_TAGS,
			lastTypingPingAt: 0,
		}
	},
	computed: {
		canReply() {
			return !!this.ticket && this.ticket.status !== 'closed' && this.ticket.can_reply !== false
		},
		showEnd() {
			return !!this.ticket && this.ticket.service_mode === 'online' && this.ticket.status !== 'closed'
		},
		ticketStatusText() {
			if (!this.ticket) return ''
			if (this.ticket.held) return '待补充'
			return this.ticket.status_label || statusLabel(this.ticket.status)
		},
		serviceHours() {
			return SERVICE_HOURS
		},
		hasStaffReply() {
			return this.messages.some((message) => message.actor_type === 'admin' || message.actor_type === 'developer')
		},
		staffTyping() {
			return !!(this.ticket && this.ticket.staff_typing && this.canReply)
		},
		quickReplies() {
			return quickRepliesFor(this.ticket && this.ticket.category)
		},
		composerPlaceholder() {
			if (!this.ticket) return '输入消息…'
			if (this.ticket.service_mode === 'online') return '输入消息，客服会在这里回复…'
			return '继续描述问题或补充信息…'
		},
		ratingText() {
			return ['', '很不满意', '不满意', '一般', '满意', '非常满意'][this.ratingValue] || ''
		},
		introDesc() {
			if (!this.ticket) return ''
			if (this.ticket.status === 'closed') return '本次会话已结束。'
			if (this.ticket.status === 'resolved') return '本次问题已标记为已解决，如需继续处理可直接发送消息。'
			if (this.ticket.held) return '客服正在等待你的补充信息，直接回复即可。'
			if (this.ticket.service_mode === 'online') {
				return this.ticket.status === 'open'
					? '请描述你遇到的问题，客服接入后会在这里回复。'
					: '客服正在处理，你可以继续补充问题。'
			}
			return '留言已收到，你可以继续补充信息。'
		},
		renderedMessages() {
			return this.messages.map((message, index) => {
				const currentDay = this.dayKey(message.created_at)
				const previousDay = index > 0 ? this.dayKey(this.messages[index - 1].created_at) : ''
				return {
					...message,
					showDay: index === 0 || currentDay !== previousDay,
					dayLabel: this.dayLabel(message.created_at),
				}
			})
		},
	},
	onLoad(options) {
		this.id = options && options.id ? Number(options.id) : null
		if (this.id) this.fetchTicket(false)
		else this.loading = false
	},
	onShow() {
		this.startPolling()
	},
	onHide() {
		this.stopPolling()
	},
	onUnload() {
		this.stopPolling()
	},
	methods: {
		categoryLabel,
		goBack() {
			const pages = getCurrentPages()
			if (pages && pages.length > 1) uni.navigateBack({ delta: 1 })
			else uni.redirectTo({ url: '/pages/mine/tickets' })
		},
		isProductAttachment(attachment) {
			return !!(attachment && typeof attachment === 'object' && ['product', 'order', 'device'].includes(attachment.type))
		},
		attachmentIconName(att) {
			if (!att) return 'hub-cube'
			if (att.type === 'order') return 'clipboard'
			if (att.type === 'device') return 'devices'
			return 'hub-cube'
		},
		attachmentMetaText(att) {
			if (!att) return ''
			const parts = []
			if (att.increment_id) parts.push(`订单 #${att.increment_id}`)
			if (att.sn) parts.push(`SN ${att.sn}`)
			if (att.type === 'product' && att.kind) parts.push(this.productKindLabel(att.kind))
			if (att.sku) parts.push(att.sku)
			if (att.status_label) parts.push(att.status_label)
			if (att.price) parts.push(`¥${att.price}`)
			return parts.join(' · ')
		},
		productKindLabel(kind) {
			return ({ rent: '租赁', buy: '购买', accessory: '配件', skill: '技能' })[kind] || '商品'
		},
		actorText(actor) {
			if (actor === 'admin' && this.ticket && this.ticket.agent && this.ticket.agent.name) return this.ticket.agent.name
			return ACTOR_TEXT[actor] || actor || '系统'
		},
		dayKey(value) { return value ? String(value).slice(0, 10) : '' },
		dayLabel(value) {
			if (!value) return ''
			const time = new Date(String(value).replace(' ', 'T'))
			if (!Number.isFinite(time.getTime())) return ''
			const today = new Date()
			if (time.toDateString() === today.toDateString()) return '今天'
			const yesterday = new Date(today.getTime() - 86400000)
			if (time.toDateString() === yesterday.toDateString()) return '昨天'
			return `${time.getMonth() + 1}月${time.getDate()}日`
		},
		clockTime(value) {
			if (!value) return ''
			const text = String(value)
			return text.length >= 16 ? text.slice(11, 16) : text
		},
		markSeen() {
			const last = this.messages.length ? this.messages[this.messages.length - 1] : null
			if (!last) return
			try {
				const map = uni.getStorageSync(SEEN_KEY)
				const next = map && typeof map === 'object' ? map : {}
				next[this.id] = last.id
				uni.setStorageSync(SEEN_KEY, next)
			} catch (e) { /* 本地未读记录失败不影响会话 */ }
		},
		scrollToBottom() {
			this.scrollAnchor = ''
			this.$nextTick(() => { this.scrollAnchor = 'message-bottom' })
		},
		startPolling() {
			this.stopPolling()
			if (!this.id) return
			this.pollTimer = setInterval(() => this.pollUpdates(), this.ticket && this.ticket.service_mode === 'online' ? 2000 : 5000)
		},
		stopPolling() {
			if (this.pollTimer) {
				clearInterval(this.pollTimer)
				this.pollTimer = null
			}
		},
		async fetchTicket(silent) {
			if (this.refreshing) return
			this.refreshing = true
			if (!silent) {
				this.loading = true
				this.loadError = ''
			}
			const previousLast = this.messages.length ? this.messages[this.messages.length - 1].id : null
			try {
				const res = await ticketApi.detail(this.id)
				const data = (res && res.data) || null
				this.ticket = data
				this.messages = data && Array.isArray(data.messages) ? data.messages : []
				this.syncError = ''
				this.afterMessagesChanged(previousLast)
			} catch (e) {
				if (!silent) {
					this.loadError = (e && e.message) || '加载失败'
					this.ticket = null
					this.messages = []
				} else {
					this.syncError = (e && e.message) || '连接已中断'
				}
			} finally {
				this.refreshing = false
				if (!silent) {
					this.loading = false
					this.startPolling()
				}
			}
		},
		async pollUpdates() {
			if (!this.id || this.refreshing || !this.ticket || this.ticket.status === 'closed') return
			this.refreshing = true
			const previousLast = this.messages.length ? this.messages[this.messages.length - 1].id : 0
			try {
				const res = await ticketApi.updates(this.id, previousLast)
				const data = (res && res.data) || {}
				const incoming = Array.isArray(data.messages) ? data.messages : []
				const { messages, receipts, server_time, last_message_id, ...state } = data
				this.ticket = { ...this.ticket, ...state }
				this.mergeMessages(incoming)
				this.applyReceipts(receipts)
				this.syncError = ''
				this.afterMessagesChanged(previousLast)
				if (this.ticket.status === 'closed') this.stopPolling()
			} catch (e) {
				this.syncError = (e && e.message) || '连接已中断'
			} finally {
				this.refreshing = false
			}
		},
		mergeMessages(incoming) {
			if (!Array.isArray(incoming) || !incoming.length) return
			const seen = new Set(this.messages.map(message => message.id))
			const next = incoming.filter(message => !seen.has(message.id))
			if (next.length) this.messages = this.messages.concat(next).sort((a, b) => Number(a.id) - Number(b.id))
		},
		applyReceipts(receipts) {
			if (!Array.isArray(receipts) || !receipts.length) return
			const map = {}
			receipts.forEach((item) => {
				if (item && item.id) map[item.id] = item.read_at || null
			})
			this.messages = this.messages.map((message) => {
				if (message.actor_type !== 'customer' || !(message.id in map)) return message
				const next = map[message.id] || null
				if ((message.read_at || null) === next) return message
				return { ...message, read_at: next }
			})
		},
		afterMessagesChanged(previousLast) {
			const nextLast = this.messages.length ? this.messages[this.messages.length - 1].id : null
			this.markSeen()
			if (nextLast !== previousLast) this.scrollToBottom()
			const hasUnread = this.messages.some(message => message.actor_type !== 'customer' && !message.read_at)
			if (hasUnread) {
				ticketApi.markRead(this.id).then(() => {
					this.messages = this.messages.map((message) => (
						message.actor_type !== 'customer' && !message.read_at
							? { ...message, read_at: message.created_at || 'read' }
							: message
					))
				}).catch(() => {})
			}
		},
		useQuickReply(text) { this.draft = text },
		onDraftInput() {
			if (!this.id || !this.canReply) return
			const now = Date.now()
			if (now - this.lastTypingPingAt < 4000) return
			this.lastTypingPingAt = now
			ticketApi.typing(this.id).catch(() => {})
		},
		setRating(value) {
			this.ratingValue = Number(value) || 0
		},
		toggleTag(value) {
			const index = this.ratingSelected.indexOf(value)
			if (index >= 0) this.ratingSelected.splice(index, 1)
			else this.ratingSelected.push(value)
		},
		starText(rating) {
			const score = Number(rating) || 0
			return '★'.repeat(Math.max(0, score)) + '☆'.repeat(Math.max(0, 5 - score))
		},
		async submitRating() {
			if (!this.ratingValue) {
				uni.showToast({ title: '请先选择星级', icon: 'none' })
				return
			}
			if (this.ratingSubmitting) return
			this.ratingSubmitting = true
			try {
				const res = await ticketApi.rate(this.id, {
					rating: this.ratingValue,
					tags: this.ratingSelected,
					comment: (this.ratingComment || '').trim() || undefined,
				})
				const data = (res && res.data) || {}
				this.ticket = { ...this.ticket, ...data }
				uni.showToast({ title: '感谢你的评价', icon: 'success' })
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '评价失败，请稍后再试', icon: 'none' })
			} finally {
				this.ratingSubmitting = false
			}
		},
		async sendMessage() {
			const text = (this.draft || '').trim()
			if (!text) { uni.showToast({ title: '请输入消息', icon: 'none' }); return }
			if (!this.canReply || this.sending) return
			this.sending = true
			try {
				const res = await ticketApi.reply(this.id, { content: text })
				this.draft = ''
				const redirectId = res && res.data && res.data.redirect_ticket_id
				if (redirectId && Number(redirectId) !== Number(this.id)) {
					uni.showToast({ title: '已发送到你进行中的会话', icon: 'none' })
					setTimeout(() => {
						uni.redirectTo({ url: `/pages/mine/ticket-detail?id=${redirectId}` })
					}, 600)
					return
				}
				this.ticket = { ...this.ticket, held: false }
				const message = res && res.data && res.data.message
				this.mergeMessages(message ? [message] : [])
				await this.pollUpdates()
				this.scrollToBottom()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
			} finally {
				this.sending = false
			}
		},
		endConversation() {
			uni.showModal({
				title: '结束会话',
				content: '确认结束本次客服会话？如问题仍在，可重新发起咨询。',
				confirmText: '结束',
				success: async (result) => {
					if (!result.confirm) return
					try {
						await ticketApi.end(this.id)
						await this.fetchTicket(true)
						uni.showToast({ title: '会话已结束', icon: 'success' })
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '结束失败', icon: 'none' })
					}
				},
			})
		},
		copyAttachment(attachment) {
			uni.setClipboardData({
				data: String(attachment || ''),
				success: () => uni.showToast({ title: '附件链接已复制', icon: 'none' }),
			})
		},
	},
}
</script>

<style scoped>
.page-container {
	display: flex;
	flex-direction: column;
	height: 100vh;
	overflow: hidden;
	background: #F5F6F8;
}
.nav-end-btn {
	padding: 8rpx 20rpx;
	border-radius: 999rpx;
	background: #F5F6F8;
	font-size: 23rpx;
	font-weight: 500;
	color: #4B5563;
	white-space: nowrap;
}

.chat-shell { flex: 1; min-height: 0; display: flex; flex-direction: column; }
.service-bar {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
	padding: 16rpx 28rpx;
	background: #FFFFFF;
	border-bottom: 1rpx solid rgba(15, 23, 42, 0.05);
}
.service-left { display: flex; align-items: center; gap: 12rpx; }
.status-tag {
	padding: 4rpx 14rpx;
	border-radius: 8rpx;
	font-size: 22rpx;
	font-weight: 500;
	background: #EEF4FF;
	color: #165DFF;
}
.st-resolved { background: #ECFDF5; color: #059669; }
.st-held { background: #FFFBEB; color: #B45309; }
.st-closed { background: #F3F4F6; color: #6B7280; }
.service-hours { font-size: 22rpx; color: #9CA3AF; font-variant-numeric: tabular-nums; }

.sync-banner {
	flex-shrink: 0;
	margin: 12rpx 24rpx 0;
	padding: 12rpx 18rpx;
	border-radius: 12rpx;
	background: #FFFBEB;
	font-size: 22rpx;
	color: #92400E;
}
.incident-banner {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 14rpx;
	margin: 12rpx 24rpx 0;
	padding: 18rpx 20rpx;
	border-radius: 16rpx;
	background: #FFFBEB;
}
.incident-left { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.incident-title { font-size: 25rpx; font-weight: 600; color: #78350F; }
.incident-meta { margin-top: 4rpx; font-size: 21rpx; color: #92400E; font-variant-numeric: tabular-nums; }

.messages-scroll {
	flex: 1;
	height: 0;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.messages-scroll::-webkit-scrollbar { display: none; width: 0; height: 0; }
.messages-body { padding: 20rpx 24rpx; }
.welcome-note { display: flex; justify-content: center; padding: 12rpx 16rpx 24rpx; }
.welcome-note text {
	text-align: center;
	font-size: 23rpx;
	line-height: 1.5;
	color: #9CA3AF;
}

.day-divider { display: flex; justify-content: center; margin: 18rpx 0; }
.day-divider text { font-size: 22rpx; color: #9CA3AF; font-variant-numeric: tabular-nums; }
.system-row { display: flex; justify-content: center; margin: 16rpx 24rpx; }
.system-row text {
	padding: 6rpx 18rpx;
	border-radius: 10rpx;
	background: rgba(15, 23, 42, 0.04);
	text-align: center;
	font-size: 22rpx;
	line-height: 1.5;
	color: #6B7280;
}

.message-row { display: flex; margin-bottom: 24rpx; }
.message-row.mine { justify-content: flex-end; }
.message-column { display: flex; flex-direction: column; align-items: flex-start; max-width: 82%; }
.message-row.mine .message-column { align-items: flex-end; }
.message-meta { display: flex; align-items: center; gap: 10rpx; margin: 0 4rpx 8rpx; }
.message-actor { font-size: 21rpx; color: #6B7280; }
.message-time { font-size: 20rpx; color: #9CA3AF; font-variant-numeric: tabular-nums; }
.message-receipt { font-size: 20rpx; color: #9CA3AF; }
.message-receipt.read { color: #165DFF; }

.typing-indicator {
	flex-shrink: 0;
	align-self: flex-start;
	display: flex;
	align-items: center;
	gap: 10rpx;
	margin: 0 24rpx 12rpx;
	padding: 10rpx 20rpx;
	border-radius: 999rpx;
	background: #FFFFFF;
}
.typing-dots { display: flex; align-items: center; gap: 5rpx; }
.typing-dots text { width: 8rpx; height: 8rpx; border-radius: 50%; background: #165DFF; opacity: 0.35; }
.typing-dots text:nth-child(2) { animation: typing-pulse 1.2s infinite 0.15s; }
.typing-dots text:nth-child(3) { animation: typing-pulse 1.2s infinite 0.3s; }
.typing-text { font-size: 21rpx; color: #6B7280; }
@keyframes typing-pulse { 0%, 100% { opacity: 0.35; } 50% { opacity: 1; } }

.message-bubble {
	padding: 20rpx 24rpx;
	border-radius: 8rpx 20rpx 20rpx 20rpx;
	background: #FFFFFF;
	box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.02);
}
.message-row.mine .message-bubble {
	background: #165DFF;
	border-radius: 20rpx 8rpx 20rpx 20rpx;
	box-shadow: none;
}
.message-content { font-size: 28rpx; line-height: 1.65; color: #111827; white-space: pre-wrap; word-break: break-word; }
.message-row.mine .message-content { color: #FFFFFF; }

.attachment-list { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 14rpx; }
.attachment {
	display: inline-flex;
	align-items: center;
	gap: 8rpx;
	padding: 10rpx 16rpx;
	border-radius: 10rpx;
	background: #F5F6F8;
}
.message-row.mine .attachment {
	background: rgba(255, 255, 255, 0.18);
}
.attachment-text { font-size: 22rpx; color: #165DFF; }
.message-row.mine .attachment-text { color: #FFFFFF; }

.message-product-card {
	width: 100%;
	min-width: 390rpx;
	display: flex;
	align-items: center;
	gap: 16rpx;
	padding: 16rpx;
	border-radius: 14rpx;
	background: #F7F8FA;
	box-sizing: border-box;
}
.message-row.mine .message-product-card {
	background: #FFFFFF;
}
.message-product-image {
	width: 76rpx;
	height: 76rpx;
	flex-shrink: 0;
	overflow: hidden;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 12rpx;
	background: #EEF4FF;
}
.message-product-image image { width: 100%; height: 100%; }
.message-product-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6rpx; text-align: left; }
.message-product-name { overflow: hidden; color: #111827; font-size: 25rpx; font-weight: 600; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.message-product-meta { color: #6B7280; font-size: 21rpx; font-variant-numeric: tabular-nums; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.message-bottom { height: 6rpx; }

.rating-card {
	flex-shrink: 0;
	margin: 0 24rpx 14rpx;
	padding: 24rpx;
	border-radius: 20rpx;
	background: #FFFFFF;
}
.rating-head { display: flex; align-items: baseline; justify-content: space-between; }
.rating-title { font-size: 28rpx; font-weight: 600; color: #111827; }
.rating-sub { font-size: 22rpx; color: #9CA3AF; }
.rating-stars { display: flex; align-items: center; gap: 10rpx; margin-top: 14rpx; }
.rating-star { font-size: 44rpx; line-height: 1; color: #E5E7EB; }
.rating-star.on { color: #F59E0B; }
.rating-value { margin-left: 10rpx; font-size: 23rpx; color: #4B5563; }
.rating-tags { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 14rpx; }
.rating-tag {
	padding: 10rpx 20rpx;
	border-radius: 12rpx;
	background: #F5F6F8;
	font-size: 23rpx;
	color: #374151;
}
.rating-tag.active { background: #EEF4FF; color: #165DFF; font-weight: 500; }
.rating-input {
	width: 100%;
	height: 84rpx;
	margin-top: 14rpx;
	padding: 14rpx 18rpx;
	box-sizing: border-box;
	border-radius: 14rpx;
	background: #F7F8FA;
	font-size: 25rpx;
	color: #111827;
}
.rating-placeholder { color: #9CA3AF; }
.rating-submit {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 78rpx;
	margin-top: 16rpx;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 26rpx;
	font-weight: 600;
}
.rating-submit.disabled { opacity: 0.45; }
.rating-done { display: flex; align-items: center; justify-content: center; gap: 14rpx; }
.rating-done-stars { font-size: 28rpx; letter-spacing: 4rpx; color: #F59E0B; }
.rating-done-text { font-size: 23rpx; color: #6B7280; }

.composer-panel {
	flex-shrink: 0;
	background: #FFFFFF;
	border-top: 1rpx solid rgba(15, 23, 42, 0.06);
	padding: 14rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
	padding: 14rpx 24rpx calc(20rpx + constant(safe-area-inset-bottom));
}
.quick-scroll {
	width: 100%;
	margin-bottom: 12rpx;
	white-space: nowrap;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.quick-scroll::-webkit-scrollbar { display: none; width: 0; height: 0; }
.quick-list { display: inline-flex; gap: 12rpx; padding-right: 10rpx; }
.quick-chip {
	flex-shrink: 0;
	padding: 10rpx 20rpx;
	border-radius: 999rpx;
	background: #F5F6F8;
	font-size: 23rpx;
	color: #374151;
}
.input-area {
	display: flex;
	align-items: flex-end;
	gap: 14rpx;
}
.input-main { flex: 1; min-width: 0; position: relative; display: flex; }
.input-box {
	flex: 1;
	width: 100%;
	height: 76rpx;
	min-height: 76rpx;
	max-height: 200rpx;
	padding: 18rpx 26rpx;
	box-sizing: border-box;
	border: none;
	outline: none;
	resize: none;
	border-radius: 38rpx;
	background: #F5F6F8;
	font-family: inherit;
	font-size: 28rpx;
	line-height: 40rpx;
	color: #111827;
}
.input-box .uni-textarea-textarea { height: auto !important; min-height: 40rpx; max-height: 170rpx; }
.input-placeholder { color: #9CA3AF; }
.input-count { position: absolute; right: 20rpx; bottom: 10rpx; font-size: 20rpx; color: #9CA3AF; pointer-events: none; }
.send-btn {
	flex-shrink: 0;
	height: 76rpx;
	padding: 0 32rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 27rpx;
	font-weight: 600;
}
.send-btn.disabled { opacity: 0.45; }

.closed-panel {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20rpx;
	padding: 12rpx 4rpx;
}
.closed-copy { min-width: 0; flex: 1; }
.closed-title { display: block; font-size: 26rpx; font-weight: 600; color: #111827; }
.closed-desc { display: block; margin-top: 4rpx; font-size: 22rpx; line-height: 1.45; color: #6B7280; }
.closed-btn {
	flex-shrink: 0;
	padding: 14rpx 26rpx;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 24rpx;
	font-weight: 500;
}
</style>

<style>
::-webkit-scrollbar,
scroll-view::-webkit-scrollbar,
uni-scroll-view::-webkit-scrollbar,
.uni-scroll-view::-webkit-scrollbar {
	width: 0 !important;
	height: 0 !important;
	display: none !important;
}
scroll-view,
uni-scroll-view,
.uni-scroll-view {
	scrollbar-width: none !important;
	-ms-overflow-style: none !important;
}
</style>
