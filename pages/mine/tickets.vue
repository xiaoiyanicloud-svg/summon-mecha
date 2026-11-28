<template>
	<view class="page-container">
		<nav-bar title="客服中心" fallback-tab="/pages/mine/index" />

		<scroll-view class="scroll-content" scroll-y="true" :enhanced="true" :show-scrollbar="false">
			<view class="page-content">
				<!-- 进行中的咨询置顶入口（仅存在活跃会话时显示） -->
				<view
					v-if="loggedIn && activeTicket"
					class="surface active-session"
					role="button"
					tabindex="0"
					aria-label="继续进行中的咨询"
					@tap="goDetail(activeTicket)"
				>
					<view class="session-icon">
						<SmIcon name="service" size="38rpx" />
						<view v-if="unreadCount(activeTicket) > 0" class="session-dot"></view>
					</view>
					<view class="session-body">
						<view class="session-head">
							<text class="session-title">进行中的咨询 · {{ categoryLabel(activeTicket.category) }}</text>
						</view>
						<text class="session-preview">{{ preview(activeTicket) }}</text>
					</view>
					<view class="session-action">
						<text class="session-link">继续沟通</text>
						<text class="chevron">›</text>
					</view>
				</view>

				<!-- 自助服务宫格 -->
				<view class="surface quick-panel">
					<view class="quick-grid">
						<view
							v-for="tool in quickTools"
							:key="tool.label"
							class="quick-item"
							role="button"
							tabindex="0"
							:aria-label="tool.label"
							@tap="openTool(tool)"
						>
							<view class="quick-icon">
								<SmIcon :name="tool.icon" size="42rpx" />
							</view>
							<text class="quick-label">{{ tool.label }}</text>
						</view>
					</view>
				</view>

				<!-- 常见问题（极简分割线列表，无框套框） -->
				<view class="surface list-panel">
					<view class="panel-header">
						<text class="panel-title">常见问题</text>
					</view>
					<view class="faq-list">
						<view
							v-for="(item, index) in faqItems"
							:key="item.question"
							class="faq-row"
							:class="{ open: openFaqIndex === index }"
						>
							<view
								class="faq-trigger"
								role="button"
								tabindex="0"
								:aria-expanded="openFaqIndex === index ? 'true' : 'false'"
								@tap="toggleFaq(index)"
							>
								<text class="faq-question">{{ item.question }}</text>
								<text class="faq-arrow" :class="{ open: openFaqIndex === index }">›</text>
							</view>
							<view v-if="openFaqIndex === index" class="faq-detail">
								<text class="faq-answer">{{ item.answer }}</text>
								<view
									class="faq-contact"
									role="button"
									tabindex="0"
									@tap="contactFromFaq(item)"
								>
									<text>仍需协助？联系客服 ›</text>
								</view>
							</view>
						</view>
					</view>
				</view>

				<!-- 服务记录 -->
				<view v-if="loggedIn && (loading || loadError || list.length > 0 || showEmptyRecords)" class="surface list-panel">
					<view class="panel-header">
						<text class="panel-title">服务记录</text>
						<view v-if="list.length > 1" class="filter-tabs">
							<text
								v-for="f in recordFilters"
								:key="f.key"
								class="filter-tab"
								:class="{ active: recordFilter === f.key }"
								role="button"
								tabindex="0"
								@tap="recordFilter = f.key"
							>{{ f.label }}</text>
						</view>
					</view>

					<state-view
						:loading="loading"
						:error="loadError"
						:empty="false"
						compact
						btn-color="#165DFF"
						@retry="refresh"
					>
						<view v-if="!filteredList.length" class="empty-records">
							<text class="empty-records-text">{{ list.length ? '当前筛选下没有记录' : '暂无历史服务记录' }}</text>
						</view>
						<view v-else class="record-list">
							<view
								v-for="item in filteredList"
								:key="item.id"
								class="record-row"
								role="button"
								tabindex="0"
								:aria-label="`查看${categoryLabel(item.category)}记录`"
								@tap="goDetail(item)"
							>
								<view class="record-top">
									<view class="record-title-wrap">
										<text class="record-title">{{ categoryLabel(item.category) }}</text>
										<text class="record-status" :class="{ active: isTicketActive(item) }">{{ item.held ? '待补充' : (item.status_label || statusLabel(item.status)) }}</text>
									</view>
									<text class="record-time">{{ relativeTime(item.updated_at || item.created_at) }}</text>
								</view>
								<view class="record-bottom">
									<text class="record-preview">{{ preview(item) }}</text>
									<view v-if="unreadCount(item) > 0" class="unread-dot">
										<text>{{ unreadCount(item) > 99 ? '99+' : unreadCount(item) }}</text>
									</view>
								</view>
							</view>
						</view>
					</state-view>
				</view>

				<view v-else-if="!loggedIn" class="login-hint-row" role="button" tabindex="0" @tap="goLogin">
					<text class="login-hint-text">登录后可查看历史服务记录</text>
					<text class="login-hint-link">去登录 ›</text>
				</view>
			</view>
		</scroll-view>

		<!-- 底部固定客服栏 -->
		<view class="bottom-bar">
			<view
				class="primary-btn"
				role="button"
				tabindex="0"
				:aria-label="loggedIn ? '联系客服' : '登录后联系客服'"
				@tap="onTapPrimaryContact"
			>
				<text class="primary-btn-text">{{ loggedIn ? '联系客服' : '登录后联系客服' }}</text>
			</view>
			<view class="bottom-meta">
				<view v-if="loggedIn && isOnline" class="online-dot"></view>
				<text class="bottom-meta-text">人工服务时间 {{ serviceHours }}{{ loggedIn ? (isOnline ? ' · 当前在线' : ` · 非服务时段留言 ${replyPromise}`) : '' }}</text>
			</view>
		</view>

		<!-- 联系客服底部面板 -->
		<view v-if="showCompose" class="sheet-mask" @tap="closeCompose">
			<view class="compose-sheet" @tap.stop>
				<view class="sheet-header">
					<view class="sheet-title-group">
						<text class="sheet-title">联系客服</text>
						<text class="sheet-sub">{{ isOnline ? '客服在线，发送后进入会话' : `留言提交后将在 ${replyPromise}` }}</text>
					</view>
					<view class="sheet-close" role="button" tabindex="0" aria-label="关闭" @tap="closeCompose">
						<SmIcon name="close" size="26rpx" />
					</view>
				</view>

				<view class="topic-list">
					<text
						v-for="(topic, idx) in topics"
						:key="topic.value"
						class="topic-item"
						:class="{ active: topicIndex === idx }"
						role="button"
						tabindex="0"
						@tap="selectTopic(idx)"
					>{{ topic.label }}</text>
				</view>

				<view v-if="productContext" class="attached-card">
					<view class="attached-thumb">
						<image v-if="productContext.image" :src="productContext.image" mode="aspectFill"></image>
						<SmIcon v-else :name="contextIconName(productContext)" size="34rpx" />
					</view>
					<view class="attached-info">
						<text class="attached-name">{{ productContext.name }}</text>
						<text v-if="contextMetaLine(productContext)" class="attached-meta">{{ contextMetaLine(productContext) }}</text>
					</view>
					<view class="attached-remove" role="button" tabindex="0" aria-label="移除关联内容" @tap="removeProductContext">
						<SmIcon name="close" size="22rpx" />
					</view>
				</view>

				<view class="input-surface">
					<textarea
						v-model="content"
						class="sheet-textarea"
						:focus="inputFocus"
						placeholder="请简要描述你遇到的问题…"
						placeholder-class="sheet-placeholder"
						maxlength="2000"
						auto-height="false"
						cursor-spacing="20"
						@blur="onInputBlur"
					/>
					<view v-if="(!productContext && (myOrders.length || myDevices.length)) || content.length > 1600" class="input-footer">
						<view class="picker-group">
							<picker v-if="!productContext && myOrders.length" :range="orderAttachLabels" @change="onPickOrderAttach">
								<view class="picker-chip">
									<text>+ 选择订单</text>
								</view>
							</picker>
							<picker v-if="!productContext && myDevices.length" :range="deviceAttachLabels" @change="onPickDeviceAttach">
								<view class="picker-chip">
									<text>+ 选择设备</text>
								</view>
							</picker>
						</view>
						<text v-if="content.length > 1600" class="char-count">{{ content.length }}/2000</text>
					</view>
				</view>

				<view
					class="sheet-submit"
					:class="{ disabled: submitting || !content.trim() }"
					role="button"
					tabindex="0"
					aria-label="提交问题"
					@tap="submit"
				>
					<text>{{ submitting ? '发送中…' : (isOnline ? '发送' : '提交留言') }}</text>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import SmIcon from '@/components/SmIcon.vue'
import StateView from '@/components/StateView.vue'
import { ticketApi, orderApi, deviceApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import {
	CATEGORY_OPTIONS,
	FAQ_ITEMS,
	REPLY_PROMISE,
	SERVICE_HOURS,
	categoryLabel,
	statusLabel,
} from '@/utils/customer-service'

const SEEN_KEY = 'customer_service_seen_tickets_v1'

const QUICK_TOOLS = [
	{ label: '订单查询', icon: 'order-all', url: '/pages/order/index', auth: true },
	{ label: '下发记录', icon: 'bolt', url: '/pages/mine/deploy', auth: true },
	{ label: '退换售后', icon: 'after-sales', url: '/pages/mine/after-sales', auth: true },
	{ label: '我的设备', icon: 'devices', url: '/pages/mine/devices', auth: true },
]

export default {
	components: { NavBar, SmIcon, StateView },
	data() {
		return {
			loggedIn: false,
			loading: false,
			loadError: '',
			submitting: false,
			showCompose: false,
			pendingOpenCompose: false,
			showEmptyRecords: false,
			recordFilter: 'all',
			openFaqIndex: -1,
			content: '',
			topicIndex: 0,
			inputFocus: false,
			online: false,
			statusTimer: null,
			list: [],
			replyPromise: REPLY_PROMISE,
			serviceHours: SERVICE_HOURS,
			faqItems: FAQ_ITEMS,
			topics: CATEGORY_OPTIONS,
			quickTools: QUICK_TOOLS,
			productContext: null,
			myOrders: [],
			myDevices: [],
		}
	},
	computed: {
		isOnline() {
			return !!this.online
		},
		activeTicket() {
			return this.list.find((item) => this.isTicketActive(item)) || null
		},
		recordFilters() {
			return [
				{ key: 'all', label: '全部' },
				{ key: 'active', label: '进行中' },
				{ key: 'closed', label: '已完结' },
			]
		},
		filteredList() {
			if (this.recordFilter === 'active') {
				return this.list.filter((item) => this.isTicketActive(item))
			}
			if (this.recordFilter === 'closed') {
				return this.list.filter((item) => !this.isTicketActive(item))
			}
			return this.list
		},
		currentTopic() {
			return this.topics[this.topicIndex] || this.topics[0]
		},
		orderAttachLabels() {
			return this.myOrders.map((o) => `#${o.increment_id || o.id} · ${(o.items && o.items[0] && o.items[0].name) || o.product_name || '订单'}${o.grand_total ? ` · ¥${o.grand_total}` : ''}`)
		},
		deviceAttachLabels() {
			return this.myDevices.map((d) => `${d.sn || d.serial_number || ''}${d.display_name || d.name ? `（${d.display_name || d.name}）` : ''}`)
		},
	},
	onLoad(options) {
		const q = options || {}
		if (q.tab === 'records') {
			this.showEmptyRecords = true
		}
		if (q.category) {
			const index = this.topics.findIndex((topic) => topic.value === q.category)
			if (index >= 0) this.topicIndex = index
		}
		if (q.content) {
			let text = String(q.content)
			try { text = decodeURIComponent(text) } catch (e) {}
			this.content = text
			this.pendingOpenCompose = true
		}
		if (q.productContext || q.orderContext || q.deviceContext) {
			const context = uni.getStorageSync('supportProductContext')
			if (context && ['product', 'order', 'device'].includes(context.type) && context.name) {
				this.productContext = context
				this.pendingOpenCompose = true
			} else {
				uni.removeStorageSync('supportProductContext')
			}
		}
		if (q.compose === '1' || q.category) {
			if (q.compose === '1') this.pendingOpenCompose = true
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) {
			if (this.pendingOpenCompose) {
				this.showCompose = true
				this.pendingOpenCompose = false
			}
			this.refresh()
			this.refreshOnline()
			this.startStatusPolling()
		} else {
			this.loading = false
			this.loadError = ''
			this.showCompose = false
			this.stopStatusPolling()
		}
	},
	onHide() { this.stopStatusPolling() },
	onUnload() { this.stopStatusPolling() },
	methods: {
		categoryLabel,
		statusLabel,
		isTicketActive(item) {
			return !!(item && (item.status === 'open' || item.status === 'processing' || item.status === 'replied'))
		},
		goLogin() {
			uni.switchTab({ url: '/pages/mine/index' })
		},
		openTool(tool) {
			if (!tool || !tool.url) return
			if (tool.auth && !this.loggedIn) {
				this.goLogin()
				return
			}
			uni.navigateTo({ url: tool.url })
		},
		toggleFaq(index) {
			this.openFaqIndex = this.openFaqIndex === index ? -1 : index
		},
		contactFromFaq(item) {
			if (!this.loggedIn) {
				this.goLogin()
				return
			}
			const idx = this.topics.findIndex((topic) => topic.value === item.category)
			if (idx >= 0) this.topicIndex = idx
			this.showCompose = true
			this.inputFocus = true
		},
		onTapPrimaryContact() {
			if (!this.loggedIn) {
				this.goLogin()
				return
			}
			if (this.activeTicket && !this.productContext && !this.content.trim()) {
				this.goDetail(this.activeTicket)
				return
			}
			this.showCompose = true
			this.inputFocus = true
		},
		closeCompose() {
			this.showCompose = false
			this.inputFocus = false
		},
		selectTopic(index) {
			this.topicIndex = Number(index) || 0
		},
		onInputBlur() {
			this.inputFocus = false
		},
		contextIconName(ctx) {
			if (!ctx) return 'hub-cube'
			if (ctx.type === 'order') return 'clipboard'
			if (ctx.type === 'device') return 'devices'
			return 'hub-cube'
		},
		productKindLabel(kind) {
			return ({ rent: '租赁', buy: '购买', accessory: '配件', skill: '技能' })[kind] || '商品'
		},
		contextMetaLine(ctx) {
			if (!ctx) return ''
			const parts = []
			if (ctx.increment_id) parts.push(`订单 #${ctx.increment_id}`)
			if (ctx.sn) parts.push(`SN ${ctx.sn}`)
			if (ctx.type === 'order') {
				if (ctx.status_label) parts.push(ctx.status_label)
				if (ctx.price) parts.push(`¥${ctx.price}`)
			} else if (ctx.type === 'product') {
				parts.push(this.productKindLabel(ctx.kind))
				if (ctx.price) parts.push(`¥${ctx.price}`)
			}
			return parts.join(' · ')
		},
		onPickOrderAttach(e) {
			const o = this.myOrders[Number(e.detail.value)]
			if (!o) return
			this.productContext = {
				type: 'order',
				order_id: o.id,
				increment_id: String(o.increment_id || o.id),
				name: (o.items && o.items[0] && o.items[0].name) || o.product_name || `订单 #${o.increment_id || o.id}`,
				price: String(o.grand_total || ''),
				status_label: o.status_label || o.status || '',
			}
		},
		onPickDeviceAttach(e) {
			const d = this.myDevices[Number(e.detail.value)]
			if (!d) return
			this.productContext = {
				type: 'device',
				device_id: d.id,
				name: d.display_name || d.name || `设备 ${d.sn || d.serial_number || ''}`,
				sn: d.sn || d.serial_number || '',
			}
		},
		removeProductContext() {
			this.productContext = null
			uni.removeStorageSync('supportProductContext')
		},
		startStatusPolling() {
			this.stopStatusPolling()
			this.statusTimer = setInterval(() => this.refreshOnline(), 30000)
		},
		stopStatusPolling() {
			if (this.statusTimer) {
				clearInterval(this.statusTimer)
				this.statusTimer = null
			}
		},
		async refreshOnline() {
			try {
				const res = await ticketApi.onlineStatus()
				const data = (res && res.data) || {}
				this.online = !!data.online
			} catch (e) {
				this.online = false
			}
		},
		goDetail(item) {
			if (!item || item.id == null) return
			this.markSeen(item)
			uni.navigateTo({ url: `/pages/mine/ticket-detail?id=${item.id}` })
		},
		preview(item) {
			const last = item && item.last_message
			if (typeof last === 'string' && last.trim()) return last.trim().slice(0, 48)
			if (!last || !last.content) {
				if (item && item.content) return String(item.content).replace(/\s+/g, ' ').slice(0, 48)
				return '等待客服回复'
			}
			const prefix = {
				admin: '客服：',
				agent: '客服：',
				staff: '客服：',
				developer: '技术：',
				system: '',
				customer: '我：',
			}[last.actor_type] || ''
			return prefix + String(last.content).replace(/\s+/g, ' ').slice(0, 46)
		},
		seenMap() {
			try {
				const raw = uni.getStorageSync(SEEN_KEY)
				return raw && typeof raw === 'object' ? raw : {}
			} catch (e) { return {} }
		},
		unreadCount(item) {
			const server = Number(item && item.unread_count || 0)
			if (server > 0) return server
			const last = item && item.last_message
			if (!last || typeof last !== 'object' || last.actor_type === 'customer') return 0
			return Number(last.id || 0) > Number(this.seenMap()[item.id] || 0) ? 1 : 0
		},
		markSeen(item) {
			if (!item || !item.last_message || typeof item.last_message !== 'object') return
			const map = this.seenMap()
			map[item.id] = item.last_message.id
			uni.setStorageSync(SEEN_KEY, map)
		},
		relativeTime(value) {
			if (!value) return ''
			const time = new Date(String(value).replace(' ', 'T')).getTime()
			if (!Number.isFinite(time)) return value
			const diff = Date.now() - time
			if (diff < 60000) return '刚刚'
			if (diff < 3600000) return `${Math.floor(diff / 60000)}分钟前`
			if (diff < 86400000) return `${Math.floor(diff / 3600000)}小时前`
			const date = new Date(time)
			if (date.getFullYear() === new Date().getFullYear()) return `${date.getMonth() + 1}月${date.getDate()}日`
			return `${date.getFullYear()}/${date.getMonth() + 1}/${date.getDate()}`
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			orderApi.getOrders({ limit: 20 }).then((r) => {
				const raw = r && r.data
				this.myOrders = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.items) ? raw.items : [])
			}).catch(() => {})
			deviceApi.list().then((r) => {
				const raw = r && r.data
				this.myDevices = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.items) ? raw.items : [])
			}).catch(() => {})
			try {
				const res = await ticketApi.list()
				const raw = res && res.data
				this.list = Array.isArray(raw) ? raw : (raw && Array.isArray(raw.items) ? raw.items : [])
			} catch (e) {
				this.loadError = (e && e.message) || '加载失败'
				this.list = []
			} finally {
				this.loading = false
			}
		},
		async submit() {
			const text = (this.content || '').trim()
			if (!text) { uni.showToast({ title: '请先描述问题', icon: 'none' }); return }
			if (this.submitting) return
			this.submitting = true
			try {
				const payload = { content: text, category: this.currentTopic.value }
				if (this.productContext) {
					payload.attachments = [this.productContext]
				}
				const res = this.isOnline
					? await ticketApi.createOnline(payload)
					: await ticketApi.create(payload)
				const id = res && res.data && res.data.id
				this.content = ''
				this.productContext = null
				this.showCompose = false
				uni.removeStorageSync('supportProductContext')
				await this.refresh()
				if (id) {
					uni.navigateTo({ url: `/pages/mine/ticket-detail?id=${id}` })
				}
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '发送失败', icon: 'none' })
			} finally {
				this.submitting = false
			}
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

.scroll-content {
	flex: 1;
	height: 0;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.scroll-content::-webkit-scrollbar {
	display: none;
	width: 0;
	height: 0;
}

.page-content {
	padding: 20rpx 24rpx 220rpx;
}

.surface {
	background: #FFFFFF;
	border-radius: 20rpx;
	margin-bottom: 20rpx;
}

/* 进行中的咨询卡片 */
.active-session {
	display: flex;
	align-items: center;
	gap: 20rpx;
	padding: 26rpx 28rpx;
}
.session-icon {
	position: relative;
	width: 76rpx;
	height: 76rpx;
	border-radius: 50%;
	background: #EEF4FF;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.session-dot {
	position: absolute;
	top: 2rpx;
	right: 2rpx;
	width: 16rpx;
	height: 16rpx;
	border-radius: 50%;
	background: #EF4444;
	border: 3rpx solid #FFFFFF;
}
.session-body {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 6rpx;
}
.session-head {
	display: flex;
	align-items: center;
}
.session-title {
	font-size: 28rpx;
	font-weight: 600;
	color: #111827;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.session-preview {
	font-size: 24rpx;
	color: #6B7280;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.session-action {
	display: flex;
	align-items: center;
	gap: 4rpx;
	flex-shrink: 0;
}
.session-link {
	font-size: 25rpx;
	font-weight: 500;
	color: #165DFF;
}
.chevron {
	font-size: 30rpx;
	line-height: 1;
	color: #165DFF;
}

/* 自助服务宫格 */
.quick-panel {
	padding: 28rpx 16rpx;
}
.quick-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 8rpx;
}
.quick-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 14rpx;
}
.quick-icon {
	width: 80rpx;
	height: 80rpx;
	border-radius: 22rpx;
	background: #F5F7FA;
	display: flex;
	align-items: center;
	justify-content: center;
}
.quick-label {
	font-size: 24rpx;
	color: #1F2937;
	font-weight: 400;
}

/* 列表通用容器 */
.list-panel {
	padding: 8rpx 28rpx;
}
.panel-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 24rpx 0 12rpx;
}
.panel-title {
	font-size: 30rpx;
	font-weight: 600;
	color: #111827;
}

/* 常见问题 */
.faq-row {
	border-top: 1rpx solid #F2F4F7;
}
.faq-row:first-child {
	border-top: none;
}
.faq-trigger {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 20rpx;
	padding: 28rpx 0;
}
.faq-question {
	flex: 1;
	font-size: 28rpx;
	color: #1F2937;
	line-height: 1.45;
}
.faq-arrow {
	font-size: 32rpx;
	line-height: 1;
	color: #9CA3AF;
	transition: transform 0.18s ease;
}
.faq-arrow.open {
	transform: rotate(90deg);
}
.faq-detail {
	padding: 0 0 26rpx;
}
.faq-answer {
	display: block;
	font-size: 25rpx;
	line-height: 1.68;
	color: #6B7280;
}
.faq-contact {
	display: inline-flex;
	align-items: center;
	margin-top: 14rpx;
	font-size: 24rpx;
	font-weight: 500;
	color: #165DFF;
}

/* 服务记录筛选与行 */
.filter-tabs {
	display: flex;
	align-items: center;
	gap: 24rpx;
}
.filter-tab {
	font-size: 24rpx;
	color: #9CA3AF;
}
.filter-tab.active {
	color: #111827;
	font-weight: 600;
}
.empty-records {
	padding: 36rpx 0 42rpx;
	text-align: center;
}
.empty-records-text {
	font-size: 25rpx;
	color: #9CA3AF;
}
.record-row {
	padding: 26rpx 0;
	border-top: 1rpx solid #F2F4F7;
}
.record-row:first-child {
	border-top: none;
}
.record-top {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
}
.record-title-wrap {
	display: flex;
	align-items: center;
	gap: 12rpx;
	min-width: 0;
}
.record-title {
	font-size: 28rpx;
	font-weight: 500;
	color: #111827;
}
.record-status {
	font-size: 22rpx;
	color: #9CA3AF;
}
.record-status.active {
	color: #165DFF;
}
.record-time {
	font-size: 23rpx;
	color: #9CA3AF;
	font-variant-numeric: tabular-nums;
	flex-shrink: 0;
}
.record-bottom {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
	margin-top: 10rpx;
}
.record-preview {
	flex: 1;
	min-width: 0;
	font-size: 25rpx;
	color: #6B7280;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.unread-dot {
	min-width: 32rpx;
	height: 32rpx;
	padding: 0 10rpx;
	border-radius: 999rpx;
	background: #EF4444;
	color: #FFFFFF;
	font-size: 20rpx;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.login-hint-row {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10rpx;
	padding: 16rpx 0;
}
.login-hint-text {
	font-size: 24rpx;
	color: #9CA3AF;
}
.login-hint-link {
	font-size: 24rpx;
	color: #165DFF;
	font-weight: 500;
}

/* 底部固定栏 */
.bottom-bar {
	position: fixed;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 50;
	background: #FFFFFF;
	border-top: 1rpx solid rgba(15, 23, 42, 0.06);
	padding: 18rpx 32rpx calc(18rpx + env(safe-area-inset-bottom));
}
.primary-btn {
	height: 88rpx;
	border-radius: 44rpx;
	background: #165DFF;
	display: flex;
	align-items: center;
	justify-content: center;
}
.primary-btn-text {
	font-size: 30rpx;
	font-weight: 600;
	color: #FFFFFF;
}
.bottom-meta {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8rpx;
	margin-top: 12rpx;
}
.online-dot {
	width: 10rpx;
	height: 10rpx;
	border-radius: 50%;
	background: #10B981;
}
.bottom-meta-text {
	font-size: 22rpx;
	color: #9CA3AF;
	font-variant-numeric: tabular-nums;
}

/* 联系客服底部面板 */
.sheet-mask {
	position: fixed;
	inset: 0;
	z-index: 120;
	background: rgba(15, 23, 42, 0.45);
	display: flex;
	align-items: flex-end;
}
.compose-sheet {
	width: 100%;
	background: #FFFFFF;
	border-radius: 28rpx 28rpx 0 0;
	padding: 32rpx 32rpx calc(32rpx + env(safe-area-inset-bottom));
	box-sizing: border-box;
}
.sheet-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	margin-bottom: 26rpx;
}
.sheet-title-group {
	display: flex;
	flex-direction: column;
	gap: 6rpx;
}
.sheet-title {
	font-size: 32rpx;
	font-weight: 600;
	color: #111827;
}
.sheet-sub {
	font-size: 23rpx;
	color: #6B7280;
}
.sheet-close {
	padding: 8rpx;
}

.topic-list {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: 12rpx;
	margin-bottom: 22rpx;
}
.topic-item {
	padding: 14rpx 0;
	border-radius: 12rpx;
	background: #F5F6F8;
	font-size: 24rpx;
	color: #374151;
	text-align: center;
	white-space: nowrap;
}
.topic-item.active {
	background: #EEF4FF;
	color: #165DFF;
	font-weight: 600;
}

.attached-card {
	display: flex;
	align-items: center;
	gap: 16rpx;
	padding: 16rpx 20rpx;
	margin-bottom: 18rpx;
	border-radius: 14rpx;
	background: #F7F8FA;
}
.attached-thumb {
	width: 68rpx;
	height: 68rpx;
	border-radius: 12rpx;
	background: #FFFFFF;
	overflow: hidden;
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}
.attached-thumb image {
	width: 100%;
	height: 100%;
}
.attached-info {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4rpx;
}
.attached-name {
	font-size: 26rpx;
	font-weight: 500;
	color: #111827;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
.attached-meta {
	font-size: 22rpx;
	color: #6B7280;
	font-variant-numeric: tabular-nums;
}
.attached-remove {
	padding: 8rpx;
	flex-shrink: 0;
}

.input-surface {
	border-radius: 16rpx;
	background: #F7F8FA;
	padding: 22rpx 24rpx;
	margin-bottom: 26rpx;
}
.sheet-textarea {
	display: block;
	width: 100%;
	height: 180rpx;
	padding: 0;
	border: none;
	outline: none;
	resize: none;
	background: transparent;
	font-family: inherit;
	font-size: 28rpx;
	line-height: 1.55;
	color: #111827;
}
.sheet-placeholder {
	color: #9CA3AF;
}
.input-footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-top: 14rpx;
	padding-top: 14rpx;
	border-top: 1rpx solid #ECEEF2;
}
.picker-group {
	display: flex;
	align-items: center;
	gap: 16rpx;
}
.picker-chip {
	padding: 8rpx 18rpx;
	border-radius: 10rpx;
	background: #FFFFFF;
	font-size: 23rpx;
	color: #4B5563;
}
.char-count {
	font-size: 22rpx;
	color: #9CA3AF;
	font-variant-numeric: tabular-nums;
}

.sheet-submit {
	height: 88rpx;
	border-radius: 44rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 30rpx;
	font-weight: 600;
	display: flex;
	align-items: center;
	justify-content: center;
}
.sheet-submit.disabled {
	opacity: 0.45;
}
</style>
