<template>
	<view class="page-container">
		<view class="nav-area">
			<view class="nav-content">
				<view class="nav-back" role="button" tabindex="0" aria-label="返回" @tap="goBack">
					<text>‹</text>
				</view>
				<view class="nav-left">
					<text class="brand">客服会话</text>
					<text class="subtitle" v-if="ticket">{{ categoryLabel(ticket.category) }}</text>
					<text class="subtitle" v-else>人工服务</text>
				</view>
				<view class="nav-right"></view>
			</view>
		</view>

		<view v-if="loading" class="state-wrap">
			<view class="state-card glass">
				<view class="loading-orbit"><view></view></view>
				<text class="state-title">正在进入会话</text>
				<text class="state-desc">正在同步客服回复与服务记录</text>
			</view>
		</view>

		<view v-else-if="loadError" class="state-wrap">
			<view class="state-card glass">
				<view class="error-symbol">!</view>
				<text class="state-title">暂时无法加载会话</text>
				<text class="state-desc">{{ loadError }}</text>
				<view class="state-btn" @tap="fetchTicket(false)">重新加载</view>
			</view>
		</view>

		<view v-else-if="!ticket" class="state-wrap">
			<view class="state-card glass">
				<view class="empty-symbol">—</view>
				<text class="state-title">会话不存在</text>
				<text class="state-desc">链接可能已失效，请返回客服中心重新进入。</text>
				<view class="state-btn" @tap="goBack">返回客服中心</view>
			</view>
		</view>

		<view v-else class="chat-shell">
			<view class="service-bar glass">
				<view class="service-meta">
					<text class="service-title">人工服务</text>
					<text class="service-hours">{{ serviceHours }}</text>
				</view>
				<view v-if="showEnd" class="end-link" role="button" tabindex="0" aria-label="结束会话" @tap="endConversation">结束</view>
			</view>

			<view v-if="syncError" class="sync-banner">
				<text>网络不稳定，消息同步可能延迟，正在重试</text>
			</view>

			<view v-if="ticket.incident" class="incident-banner">
				<view class="incident-left">
					<text class="incident-kicker">事故追溯</text>
					<text class="incident-title">已进入事故处理流程</text>
					<text class="incident-meta">{{ ticket.incident.incident_no }} · {{ ticket.incident.severity_label }} · {{ ticket.incident.status_label }}</text>
				</view>
				<text class="incident-mark">!</text>
			</view>

			<scroll-view
				class="messages-scroll"
				scroll-y="true"
				show-scrollbar="false"
				:scroll-into-view="scrollAnchor"
				:scroll-with-animation="true"
			>
				<view class="messages-body">
					<view class="welcome-note">
						<text>{{ introDesc }}</text>
					</view>

					<block v-for="message in renderedMessages" :key="message.id">
						<view v-if="message.showDay" class="day-divider">
							<view></view>
							<text>{{ message.dayLabel }}</text>
							<view></view>
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
													<text v-else>商</text>
												</view>
												<view class="message-product-main">
													<text class="message-product-kicker">商品咨询 · {{ productKindLabel(attachment.kind) }}</text>
													<text class="message-product-name">{{ attachment.name }}</text>
													<text class="message-product-meta">
														<text v-if="attachment.sku">{{ attachment.sku }}</text>
														<text v-if="attachment.price">¥{{ attachment.price }}</text>
													</text>
												</view>
											</view>
											<view v-else class="attachment" @click="copyAttachment(attachment)">
												<text class="attachment-icon">↗</text>
												<text class="attachment-text">附件 {{ index + 1 }}</text>
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

			<view v-if="ticket.can_rate || ticket.rating" class="rating-card glass">
				<block v-if="ticket.can_rate">
					<view class="rating-head">
						<text class="rating-title">这次服务怎么样？</text>
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
						placeholder="补充一句（选填）"
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
				<scroll-view v-if="canReply && !hasStaffReply" class="quick-scroll" scroll-x="true" show-scrollbar="false">
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
							:placeholder="composerPlaceholder"
							placeholder-class="input-placeholder"
							maxlength="2000"
							auto-height="true"
							confirm-type="send"
							cursor-spacing="18"
							@input="onDraftInput"
							@confirm="sendMessage"
						/>
						<text v-if="draft.length > 1600" class="input-count">{{ draft.length }}/2000</text>
					</view>
					<view
						class="send-icon"
						:class="{ disabled: sending || !draft.trim() }"
						role="button"
						tabindex="0"
						aria-label="发送消息"
						@tap="sendMessage"
					>
						<text>{{ sending ? '…' : '➤' }}</text>
					</view>
				</view>

				<view v-else class="closed-panel glass">
					<view class="closed-copy">
						<text class="closed-title">本次会话已结束</text>
						<text class="closed-desc">如果问题仍需处理，请返回客服中心重新发起咨询。</text>
					</view>
					<view class="closed-btn" @tap="goBack">返回客服中心</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import { ticketApi } from '@/utils/api'
import {
	RATING_TAGS,
	SERVICE_HOURS,
	categoryLabel,
	quickRepliesFor,
} from '@/utils/customer-service'

const ACTOR_TEXT = { customer: '我', admin: '平台客服', developer: '技术团队', system: '系统' }
const SEEN_KEY = 'customer_service_seen_tickets_v1'

export default {
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
			return !!(attachment && typeof attachment === 'object' && attachment.type === 'product')
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
			// 已读状态不会产生新消息，服务端单独下发；这里只更新已有气泡，不改消息顺序。
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
					// 服务端已标记已读；本地同步收掉未读，否则每一轮轮询都会重复请求已读接口。
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
			// 输入中只是协作信号，失败不能打断用户打字
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
					// 这张旧单已解决，客户当前另有进行中的会话：消息已转投过去，直接切过去。
					uni.showToast({ title: '已发送到你进行中的会话', icon: 'none' })
					setTimeout(() => {
						uni.redirectTo({ url: `/pages/mine/ticket-detail?id=${redirectId}` })
					}, 600)
					return
				}
				// 用户发出消息即解除挂起，不必等下一轮轮询才收掉「等待补充」提示。
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
page {
	background: linear-gradient(180deg, #4291f6 0%, #b3d5ff 100%);
	min-height: 100vh;
	height: 100%;
	overflow: hidden;
}
.page-container { position: relative; z-index: 0; display: flex; flex-direction: column; height: 100vh; overflow: hidden; background: radial-gradient(circle at 88% 2%, rgba(255, 255, 255, 0.38) 0%, rgba(255, 255, 255, 0) 32%), radial-gradient(circle at 6% 16%, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 26%), linear-gradient(180deg, #4291f6 0%, #b3d5ff 46%, #eef6ff 100%); }
.glass { background: rgba(255, 255, 255, 0.58); border: 1rpx solid rgba(255, 255, 255, 0.66); backdrop-filter: blur(20rpx); }

.nav-area { flex-shrink: 0; padding: calc(80rpx + env(safe-area-inset-top)) 30rpx 12rpx 30rpx; padding: calc(80rpx + constant(safe-area-inset-top)) 30rpx 12rpx 30rpx; }
.nav-content { display: flex; align-items: flex-start; }
.nav-back { flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 64rpx; height: 64rpx; margin-right: 16rpx; border: 1rpx solid rgba(255, 255, 255, 0.28); border-radius: 50%; background: rgba(255, 255, 255, 0.16); backdrop-filter: blur(8rpx); }
.nav-back text { margin-top: -4rpx; font-size: 44rpx; line-height: 1; color: #ffffff; }
.nav-left { min-width: 0; flex: 1; }
.brand { display: block; font-size: 44rpx; font-weight: 700; line-height: 1.2; letter-spacing: 2rpx; color: #ffffff; text-shadow: 0 4rpx 12rpx rgba(0, 0, 0, 0.2); }
.subtitle { display: inline-block; margin-top: 8rpx; padding: 4rpx 16rpx; border-radius: 30rpx; background: rgba(0, 0, 0, 0.2); font-size: 22rpx; color: rgba(255, 255, 255, 0.95); backdrop-filter: blur(4rpx); }
.nav-right { flex-shrink: 0; width: 64rpx; }

.chat-shell { flex: 1; min-height: 0; display: flex; flex-direction: column; }
.service-bar { flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 16rpx; margin: 0 24rpx 10rpx; padding: 12rpx 22rpx; border-radius: 999rpx; box-shadow: 0 8rpx 22rpx rgba(22, 93, 255, 0.06); }
.service-meta { min-width: 0; display: flex; align-items: center; gap: 14rpx; }
.service-title { font-size: 22rpx; font-weight: 600; color: #1a2a3a; }
.service-hours { font-size: 20rpx; color: #5f7080; }
.end-link { flex-shrink: 0; padding-left: 18rpx; border-left: 1rpx solid rgba(22, 93, 255, 0.14); font-size: 20rpx; color: #5f7080; }

.sync-banner { flex-shrink: 0; margin: 0 24rpx 10rpx; padding: 12rpx 18rpx; border: 1rpx solid #fde3ba; border-radius: 16rpx; background: rgba(255, 249, 238, 0.92); font-size: 19rpx; color: #9a6a36; }
.incident-banner { flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; margin: 0 24rpx 10rpx; padding: 18rpx 20rpx; border: 1rpx solid #f3d3b6; border-radius: 20rpx; background: rgba(255, 248, 241, 0.94); }
.incident-left { display: flex; flex-direction: column; }
.incident-kicker { font-size: 16rpx; letter-spacing: 2rpx; color: #c27b45; }
.incident-title { margin-top: 5rpx; font-size: 24rpx; font-weight: 600; color: #7b4c2a; }
.incident-meta { margin-top: 5rpx; font-size: 18rpx; color: #ac8465; }
.incident-mark { display: flex; align-items: center; justify-content: center; width: 38rpx; height: 38rpx; border-radius: 50%; background: #c9814d; color: #fff; font-size: 24rpx; font-weight: 600; }

.messages-scroll { flex: 1; height: 0; }
.messages-body { padding: 14rpx 28rpx 18rpx; }
.welcome-note { display: flex; justify-content: center; padding: 4rpx 24rpx 24rpx; }
.welcome-note text { padding: 8rpx 20rpx; border-radius: 999rpx; background: rgba(255, 255, 255, 0.45); text-align: center; font-size: 19rpx; line-height: 1.55; color: #4f6275; }

.day-divider { display: flex; align-items: center; gap: 14rpx; margin: 20rpx 0; }
.day-divider view { flex: 1; height: 1rpx; background: rgba(22, 93, 255, 0.14); }
.day-divider text { font-size: 17rpx; color: #4f6275; }
.system-row { display: flex; justify-content: center; margin: 16rpx 30rpx; }
.system-row text { padding: 10rpx 18rpx; border-radius: 999rpx; background: rgba(255, 255, 255, 0.62); text-align: center; font-size: 18rpx; line-height: 1.5; color: #5f7080; }

.message-row { display: flex; margin-bottom: 24rpx; }
.message-row.mine { justify-content: flex-end; }
.message-column { display: flex; flex-direction: column; align-items: flex-start; max-width: 84%; }
.message-row.mine .message-column { align-items: flex-end; }
.message-meta { display: flex; align-items: center; gap: 10rpx; margin: 0 6rpx 7rpx; }
.message-actor { font-size: 18rpx; color: #4f6275; }
.message-time { font-size: 16rpx; color: #6b7a88; }
.message-receipt { font-size: 16rpx; color: #94a3b8; }
.message-receipt.read { color: #165dff; }
.typing-indicator { flex-shrink: 0; align-self: flex-start; display: flex; align-items: center; gap: 10rpx; margin: 0 28rpx 8rpx; padding: 8rpx 18rpx; border: 1rpx solid rgba(255, 255, 255, 0.66); border-radius: 999rpx; background: rgba(255, 255, 255, 0.62); }
.typing-dots { display: flex; align-items: center; gap: 5rpx; }
.typing-dots text { width: 8rpx; height: 8rpx; border-radius: 50%; background: #165dff; opacity: .35; }
.typing-dots text:nth-child(2) { animation: typing-pulse 1.2s infinite .15s; }
.typing-dots text:nth-child(3) { animation: typing-pulse 1.2s infinite .3s; }
.typing-text { font-size: 18rpx; color: #5f7080; }
@keyframes typing-pulse { 0%, 100% { opacity: .35; } 50% { opacity: 1; } }
.message-bubble { padding: 20rpx 24rpx; border: 1rpx solid #e2e8f0; border-radius: 20rpx; background: rgba(255, 255, 255, 0.97); box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.08); }
.message-row.mine .message-bubble { border-color: #165dff; background: #165dff; box-shadow: 0 8rpx 22rpx rgba(22, 93, 255, 0.22); }
.message-content { font-size: 27rpx; line-height: 1.7; color: #1f2937; white-space: pre-wrap; word-break: break-word; }
.message-row.mine .message-content { color: #ffffff; }
.attachment-list { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 12rpx; }
.attachment { display: flex; align-items: center; gap: 6rpx; padding: 8rpx 12rpx; border-radius: 12rpx; background: rgba(22, 93, 255, 0.08); }
.message-row.mine .attachment { background: rgba(255, 255, 255, 0.16); }
.attachment-icon { font-size: 18rpx; color: #165dff; }
.message-product-card { width: 100%; min-width: 410rpx; display: flex; gap: 16rpx; padding: 16rpx; border-radius: 18rpx; background: rgba(255, 255, 255, 0.98); border: 1rpx solid rgba(22, 93, 255, 0.16); box-sizing: border-box; }
.message-product-image { width: 92rpx; height: 92rpx; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; border-radius: 14rpx; background: #eef4ff; color: #165dff; font-size: 28rpx; font-weight: 700; }
.message-product-image image { width: 100%; height: 100%; }
.message-product-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; text-align: left; }
.message-product-kicker { color: #165dff; font-size: 18rpx; font-weight: 600; }
.message-product-name { overflow: hidden; color: #1a2a3a; font-size: 25rpx; font-weight: 600; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.message-product-meta { display: flex; flex-wrap: wrap; gap: 8rpx 14rpx; color: #6b7a88; font-size: 19rpx; }
.message-row.mine .attachment-icon { color: #dbeaff; }
.attachment-text { font-size: 18rpx; color: #165dff; }
.message-row.mine .attachment-text { color: #eaf2ff; }
.message-bottom { height: 4rpx; }

.rating-card { flex-shrink: 0; margin: 0 20rpx 12rpx; padding: 22rpx 24rpx; border-radius: 26rpx; box-shadow: 0 10rpx 26rpx rgba(22, 93, 255, 0.08); }
.rating-head { display: flex; align-items: baseline; justify-content: space-between; }
.rating-title { font-size: 26rpx; font-weight: 600; color: #1a2a3a; }
.rating-sub { font-size: 19rpx; color: #6b7a88; }
.rating-stars { display: flex; align-items: center; gap: 8rpx; margin-top: 14rpx; }
.rating-star { font-size: 44rpx; line-height: 1; color: rgba(22, 93, 255, 0.18); }
.rating-star.on { color: #f5a623; }
.rating-value { margin-left: 10rpx; font-size: 21rpx; color: #5f7080; }
.rating-tags { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 14rpx; }
.rating-tag { padding: 8rpx 18rpx; border: 1rpx solid rgba(22, 93, 255, 0.18); border-radius: 999rpx; background: rgba(255, 255, 255, 0.62); font-size: 20rpx; color: #3c5573; }
.rating-tag.active { border-color: #165dff; background: rgba(22, 93, 255, 0.12); color: #165dff; }
.rating-input { width: 100%; height: 88rpx; margin-top: 12rpx; padding: 16rpx 20rpx; box-sizing: border-box; border: 1rpx solid rgba(255, 255, 255, 0.7); border-radius: 18rpx; background: rgba(255, 255, 255, 0.62); font-size: 22rpx; color: #1a2a3a; }
.rating-placeholder { color: #7b8fa5; }
.rating-submit { display: flex; align-items: center; justify-content: center; height: 72rpx; margin-top: 14rpx; border-radius: 999rpx; background: linear-gradient(135deg, #4291f6, #6eb5ff); color: #ffffff; font-size: 24rpx; box-shadow: 0 8rpx 20rpx rgba(66, 145, 246, 0.28); }
.rating-submit.disabled { opacity: .45; box-shadow: none; }
.rating-done { display: flex; align-items: center; justify-content: center; gap: 14rpx; }
.rating-done-stars { font-size: 30rpx; letter-spacing: 4rpx; color: #f5a623; }
.rating-done-text { font-size: 21rpx; color: #5f7080; }

.composer-panel { flex-shrink: 0; padding: 8rpx 20rpx calc(56rpx + env(safe-area-inset-bottom)); padding: 8rpx 20rpx calc(56rpx + constant(safe-area-inset-bottom)); }
.quick-scroll { width: 100%; margin-bottom: 10rpx; white-space: nowrap; }
.quick-list { display: inline-flex; gap: 10rpx; padding-right: 10rpx; }
.quick-chip { flex-shrink: 0; padding: 12rpx 20rpx; border: 1rpx solid #d6e4ff; border-radius: 999rpx; background: rgba(255, 255, 255, 0.92); font-size: 21rpx; color: #1a2a3a; }
.input-area { display: flex; align-items: flex-end; gap: 12rpx; padding: 12rpx 16rpx; border: 1rpx solid rgba(255, 255, 255, 0.3); border-radius: 60rpx; background: rgba(255, 255, 255, 0.5); backdrop-filter: blur(30rpx); }
.input-main { flex: 1; min-width: 0; position: relative; display: flex; }
.input-box { flex: 1; width: 100%; min-height: 72rpx; max-height: 220rpx; padding: 16rpx 24rpx; box-sizing: border-box; border: 1rpx solid rgba(255, 255, 255, 0.7); border-radius: 40rpx; background: rgba(255, 255, 255, 0.72); font-size: 27rpx; line-height: 1.5; color: #1a2a3a; }
.input-box .uni-textarea-textarea { height: auto !important; min-height: 40rpx; max-height: 188rpx; }
.input-placeholder { color: #6b7a88; }
.input-count { position: absolute; right: 20rpx; bottom: 8rpx; font-size: 18rpx; color: #94a3b8; pointer-events: none; }
.send-icon { flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 72rpx; height: 72rpx; border-radius: 50%; background: linear-gradient(135deg, #4291f6, #6eb5ff); color: #ffffff; font-size: 30rpx; box-shadow: 0 6rpx 18rpx rgba(66, 145, 246, 0.3); }
.send-icon.disabled { opacity: .45; box-shadow: none; }

.closed-panel { display: flex; align-items: center; justify-content: space-between; gap: 24rpx; padding: 22rpx; border-radius: 26rpx; }
.closed-copy { min-width: 0; flex: 1; }
.closed-title { display: block; font-size: 24rpx; font-weight: 600; color: #1a2a3a; }
.closed-desc { display: block; margin-top: 6rpx; font-size: 18rpx; line-height: 1.5; color: #5f7080; }
.closed-btn { flex-shrink: 0; padding: 12rpx 20rpx; border-radius: 999rpx; background: linear-gradient(135deg, #4291f6, #6eb5ff); color: #ffffff; font-size: 20rpx; }

.state-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 0 48rpx; }
.state-card { width: 100%; display: flex; flex-direction: column; align-items: center; padding: 48rpx 36rpx; border-radius: 36rpx; box-shadow: 0 16rpx 40rpx rgba(22, 93, 255, 0.12); }
.loading-orbit { position: relative; width: 70rpx; height: 70rpx; border: 2rpx solid rgba(22, 93, 255, 0.2); border-radius: 50%; animation: orbit 1.2s linear infinite; }
.loading-orbit view { position: absolute; top: -5rpx; left: 29rpx; width: 10rpx; height: 10rpx; border-radius: 50%; background: #165dff; }
@keyframes orbit { to { transform: rotate(360deg); } }
.error-symbol, .empty-symbol { display: flex; align-items: center; justify-content: center; width: 66rpx; height: 66rpx; border: 1rpx solid rgba(22, 93, 255, 0.24); border-radius: 50%; color: #165dff; font-size: 30rpx; }
.state-title { margin-top: 26rpx; font-size: 29rpx; font-weight: 600; color: #1a2a3a; }
.state-desc { margin-top: 12rpx; text-align: center; font-size: 22rpx; line-height: 1.6; color: #5f7080; }
.state-btn { margin-top: 28rpx; padding: 14rpx 34rpx; border-radius: 999rpx; background: linear-gradient(135deg, #4291f6, #6eb5ff); color: #fff; font-size: 22rpx; box-shadow: 0 8rpx 20rpx rgba(66, 145, 246, 0.28); }
</style>
