<template>
	<view class="page-container">
		<nav-bar title="客服中心" fallback-tab="/pages/mine/index">
			<template #right>
				<view v-if="loggedIn" class="nav-status-pill" :class="{ online: isOnline }">
					<view class="nav-status-dot" :class="{ on: isOnline }"></view>
					<text class="nav-status-text">{{ isOnline ? '在线' : '留言' }}</text>
				</view>
			</template>
		</nav-bar>

		<view v-if="loggedIn" class="work-shell">
			<!-- 一级工作区切换：把「发起新咨询」与「服务记录」解耦，消除单页信息堆积 -->
			<view class="mode-bar">
				<view
					class="mode-tab"
					:class="{ active: activeTab === 'ask' }"
					role="tab"
					:aria-selected="activeTab === 'ask' ? 'true' : 'false'"
					tabindex="0"
					@tap="switchTab('ask')"
				>
					<text class="mode-tab-text">发起咨询</text>
				</view>
				<view
					class="mode-tab"
					:class="{ active: activeTab === 'records' }"
					role="tab"
					:aria-selected="activeTab === 'records' ? 'true' : 'false'"
					tabindex="0"
					@tap="switchTab('records')"
				>
					<text class="mode-tab-text">服务记录</text>
					<view v-if="totalUnread > 0" class="mode-unread-badge sm-mono">
						<text>{{ totalUnread > 99 ? '99+' : totalUnread }}</text>
					</view>
					<text v-else-if="list.length" class="mode-tab-count sm-mono">{{ list.length }}</text>
				</view>
			</view>

			<!-- Tab 1：先排查（AI/常见问题） -> 再结构化填单 -->
			<scroll-view v-if="activeTab === 'ask'" class="scroll-content" scroll-y="true" :enhanced="true" :show-scrollbar="false">
				<view class="page-content">
					<view
						v-if="activeTicket"
						class="resume-strip"
						role="button"
						tabindex="0"
						aria-label="查看进行中的会话"
						@tap="goDetail(activeTicket)"
					>
						<view class="resume-dot"></view>
						<text class="resume-text">你有进行中的会话（{{ categoryLabel(activeTicket.category) }}），点击继续沟通</text>
						<text class="resume-link">查看 ›</text>
					</view>

					<!-- 模块 1：前置分流（AI 助手 + 常见问题一键填入） -->
					<view class="card triage-card">
						<view class="card-head">
							<view class="card-title-wrap">
								<text class="card-title">快速排查与提问</text>
								<text class="card-sub">点击常见场景可快速填入下方表单</text>
							</view>
							<view class="ai-link-btn" role="button" tabindex="0" aria-label="先问 AI 小召" @tap="goAi">
								<SmIcon name="state-spark" size="26rpx" />
								<text class="ai-link-text">问 AI 小召 ›</text>
							</view>
						</view>

						<view class="faq-grid">
							<view
								v-for="(item, index) in faqItems"
								:key="item.question"
								class="faq-chip-card"
								:class="{ active: selectedFaqIndex === index }"
								role="button"
								tabindex="0"
								@tap="useFaq(item, index)"
							>
								<text class="faq-chip-cat">{{ categoryLabel(item.category) }}</text>
								<text class="faq-chip-q">{{ item.question }}</text>
							</view>
						</view>
					</view>

					<!-- 模块 2：联系人工客服结构化表单 -->
					<view class="card compose-card">
						<view class="card-head">
							<text class="card-title">联系人工客服</text>
							<text class="card-hours sm-mono">{{ serviceHours }}</text>
						</view>

						<text class="field-label">问题类型</text>
						<view class="topic-row">
							<text
								v-for="(topic, idx) in topics"
								:key="topic.value"
								class="topic-chip"
								:class="{ active: topicIndex === idx }"
								role="button"
								tabindex="0"
								@tap="selectTopic(idx)"
							>{{ topic.label }}</text>
						</view>

						<text class="field-label" style="margin-top: 20rpx;">接入方式</text>
						<view class="channel-segment">
							<view
								class="segment-item"
								:class="{ active: channel === 'online' }"
								role="button"
								tabindex="0"
								aria-label="选择在线客服"
								@tap="selectChannel('online')"
							>
								<view class="segment-title-row">
									<view class="segment-dot" :class="{ on: isOnline }"></view>
									<text class="segment-title">在线客服</text>
								</view>
								<text class="segment-desc sm-mono">{{ onlineChannelDesc }}</text>
							</view>
							<view
								class="segment-item"
								:class="{ active: channel === 'async' }"
								role="button"
								tabindex="0"
								aria-label="选择工单留言"
								@tap="selectChannel('async')"
							>
								<view class="segment-title-row">
									<text class="segment-title">工单留言</text>
								</view>
								<text class="segment-desc">{{ replyPromise }} · 进度可查</text>
							</view>
						</view>

						<text class="field-label" style="margin-top: 20rpx;">问题描述</text>
						<view class="compose-box">
							<view v-if="productContext" class="context-inline">
								<view class="context-inline-icon">
									<image v-if="productContext.image" :src="productContext.image" mode="aspectFill"></image>
									<SmIcon v-else :name="contextIconName(productContext)" size="32rpx" />
								</view>
								<view class="context-inline-info">
									<text class="context-inline-kicker">{{ contextKicker(productContext) }}</text>
									<text class="context-inline-name">{{ productContext.name }}</text>
									<text v-if="contextMetaLine(productContext)" class="context-inline-meta sm-mono">{{ contextMetaLine(productContext) }}</text>
								</view>
								<view class="context-inline-remove" role="button" tabindex="0" aria-label="移除关联对象" @tap="removeProductContext">×</view>
							</view>

							<textarea
								v-model="content"
								class="compose-input"
								:focus="inputFocus"
								placeholder="请说明遇到的具体报错、订单或设备现象，便于快速排查…"
								placeholder-class="compose-placeholder"
								maxlength="2000"
								auto-height="false"
								cursor-spacing="16"
								@blur="onInputBlur"
							/>

							<view class="compose-toolbar">
								<view class="attach-actions">
									<picker v-if="!productContext && myOrders.length" :range="orderAttachLabels" @change="onPickOrderAttach">
										<view class="attach-trigger">
											<SmIcon name="clipboard" size="22rpx" />
											<text>关联订单</text>
										</view>
									</picker>
									<picker v-if="!productContext && myDevices.length" :range="deviceAttachLabels" @change="onPickDeviceAttach">
										<view class="attach-trigger">
											<SmIcon name="devices" size="22rpx" />
											<text>关联设备</text>
										</view>
									</picker>
									<text v-if="productContext" class="attach-bound-hint">已附带当前关联卡片</text>
									<text v-else-if="!myOrders.length && !myDevices.length" class="attach-bound-hint">请勿填写密码或验证码</text>
								</view>
								<text class="compose-count sm-mono">{{ content.length }}/2000</text>
							</view>
						</view>

						<view class="compose-foot">
							<text class="privacy-note">信息仅用于售后诊断，请勿发送密码、验证码</text>
							<view
								class="send-btn"
								:class="{ disabled: submitting || !content.trim() }"
								role="button"
								tabindex="0"
								aria-label="发送问题"
								@tap="submit"
							>
								<text>{{ submitting ? '提交中…' : (channel === 'online' ? '发送咨询' : '提交留言') }}</text>
							</view>
						</view>
					</view>

					<view class="safe-space"></view>
				</view>
			</scroll-view>

			<!-- Tab 2：专注服务记录与未读追踪 -->
			<scroll-view v-else class="scroll-content" scroll-y="true" :enhanced="true" :show-scrollbar="false">
				<view class="page-content">
					<view class="records-toolbar">
						<view class="filter-pills">
							<text
								v-for="f in recordFilters"
								:key="f.key"
								class="filter-pill"
								:class="{ active: recordFilter === f.key }"
								role="button"
								tabindex="0"
								@tap="recordFilter = f.key"
							>{{ f.label }}<text v-if="f.count > 0" class="filter-count sm-mono">{{ f.count }}</text></text>
						</view>
						<view class="refresh-btn" role="button" tabindex="0" aria-label="刷新会话" @tap="refresh">刷新</view>
					</view>

					<state-view
						:loading="loading"
						:error="loadError"
						:empty="false"
						compact
						btn-color="#165DFF"
						@retry="refresh"
					>
						<view v-if="!filteredList.length" class="empty-panel">
							<SmIcon name="empty-inbox" size="76rpx" />
							<text class="empty-title">{{ list.length ? '当前筛选暂无记录' : '暂无服务记录' }}</text>
							<text class="empty-desc">{{ list.length ? '可切换“全部”查看所有历史工单，或发起新的咨询。' : '遇到安装、设备或订单问题时，可在“发起咨询”随时联系人工客服。' }}</text>
							<view class="empty-cta" role="button" tabindex="0" @tap="switchTab('ask')">去发起咨询</view>
						</view>

						<view v-else class="records-list">
							<view
								v-for="item in filteredList"
								:key="item.id"
								class="conversation"
								role="button"
								tabindex="0"
								:aria-label="`打开会话：${categoryLabel(item.category)}`"
								@tap="goDetail(item)"
							>
								<view class="conv-mark">
									<SmIcon :name="categoryIconName(item.category)" size="38rpx" />
								</view>
								<view class="conv-main">
									<view class="conv-top">
										<view class="conv-title-row">
											<text class="conv-title">{{ categoryLabel(item.category) }}</text>
											<text v-if="item.id" class="conv-id sm-mono">#{{ item.id }}</text>
										</view>
										<text class="conv-time sm-mono">{{ relativeTime(item.updated_at || item.created_at) }}</text>
									</view>
									<text class="conv-preview">{{ preview(item) }}</text>
									<view class="conv-bottom">
										<text class="conv-status" :class="'status-' + (item.held ? 'held' : item.status)">{{ item.status_label || statusLabel(item.status) }}</text>
										<view v-if="unreadCount(item)" class="unread-badge sm-mono">
											<text>{{ unreadCount(item) > 99 ? '99+' : unreadCount(item) }}条未读</text>
										</view>
									</view>
								</view>
								<text class="conv-arrow">›</text>
							</view>
						</view>
					</state-view>

					<view class="service-foot">
						<text>人工服务时间：{{ serviceHours }} · 非服务时间留言会在 {{ replyPromise }}</text>
					</view>
					<view class="safe-space"></view>
				</view>
			</scroll-view>
		</view>

		<view v-else class="login-wrap">
			<view class="login-card">
				<view class="login-mark">
					<SmIcon name="service" size="54rpx" />
				</view>
				<text class="login-title">登录后联系客服</text>
				<text class="login-desc">登录后可以发起咨询、查看处理进度与回复记录。</text>
				<view class="login-btn" role="button" tabindex="0" aria-label="前往登录" @tap="goLogin">前往登录</view>
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

const ORDERED_TOPICS = [...CATEGORY_OPTIONS].sort((a, b) => {
	if (a.value === 'other') return 1
	if (b.value === 'other') return -1
	return 0
})

export default {
	components: { NavBar, SmIcon, StateView },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			submitting: false,
			activeTab: 'ask',
			recordFilter: 'all',
			content: '',
			topicIndex: 0,
			selectedFaqIndex: -1,
			inputFocus: false,
			online: false,
			agentsOnline: 0,
			queueSize: 0,
			channel: 'online',
			channelTouched: false,
			statusTimer: null,
			list: [],
			replyPromise: REPLY_PROMISE,
			serviceHours: SERVICE_HOURS,
			faqItems: FAQ_ITEMS,
			topics: ORDERED_TOPICS,
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
			return this.list.find((item) => item.status === 'open' || item.status === 'processing' || item.status === 'replied') || null
		},
		totalUnread() {
			return this.list.reduce((sum, item) => sum + this.unreadCount(item), 0)
		},
		activeCount() {
			return this.list.filter((item) => item.status === 'open' || item.status === 'processing' || item.status === 'replied').length
		},
		closedCount() {
			return this.list.filter((item) => item.status === 'closed' || item.status === 'resolved').length
		},
		recordFilters() {
			return [
				{ key: 'all', label: '全部', count: this.list.length },
				{ key: 'active', label: '进行中', count: this.activeCount },
				{ key: 'closed', label: '已完结', count: this.closedCount },
			]
		},
		filteredList() {
			if (this.recordFilter === 'active') {
				return this.list.filter((item) => item.status === 'open' || item.status === 'processing' || item.status === 'replied')
			}
			if (this.recordFilter === 'closed') {
				return this.list.filter((item) => item.status === 'closed' || item.status === 'resolved')
			}
			return this.list
		},
		onlineChannelDesc() {
			if (!this.isOnline) return '当前离线 · 可排队接入'
			const queue = this.queueSize > 0 ? `排队 ${this.queueSize} 人` : '无需排队'
			return `${this.agentsOnline} 位在线 · ${queue}`
		},
		currentTopic() {
			return this.topics[this.topicIndex] || this.topics[0]
		},
		orderAttachLabels() {
			return this.myOrders.map(o => `#${o.increment_id || o.id} · ${(o.items && o.items[0] && o.items[0].name) || o.product_name || '订单'}${o.grand_total ? ` · ¥${o.grand_total}` : ''}`)
		},
		deviceAttachLabels() {
			return this.myDevices.map(d => `${d.sn || d.serial_number || ''}${d.display_name || d.name ? `（${d.display_name || d.name}）` : ''}`)
		},
	},
	onLoad(options) {
		const q = options || {}
		if (q.tab === 'records') {
			this.activeTab = 'records'
		}
		if (q.category) {
			const index = this.topics.findIndex((topic) => topic.value === q.category)
			if (index >= 0) this.topicIndex = index
		}
		if (q.content) {
			let content = String(q.content)
			try { content = decodeURIComponent(content) } catch (e) {}
			this.content = content
			this.activeTab = 'ask'
			this.inputFocus = false
		}
		if (q.productContext || q.orderContext || q.deviceContext) {
			const context = uni.getStorageSync('supportProductContext')
			if (context && ['product', 'order', 'device'].includes(context.type) && context.name) {
				this.productContext = context
				this.activeTab = 'ask'
			} else {
				uni.removeStorageSync('supportProductContext')
			}
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) {
			this.refresh()
			this.refreshOnline()
			this.startStatusPolling()
		} else {
			this.loading = false
			this.loadError = ''
			this.stopStatusPolling()
		}
	},
	onHide() { this.stopStatusPolling() },
	onUnload() { this.stopStatusPolling() },
	methods: {
		categoryLabel,
		statusLabel,
		goLogin() { uni.switchTab({ url: '/pages/mine/index' }) },
		goAi() { uni.switchTab({ url: '/pages/ai/index' }) },
		switchTab(tab) {
			this.activeTab = tab === 'records' ? 'records' : 'ask'
		},
		selectTopic(index) {
			this.topicIndex = Number(index) || 0
		},
		onInputBlur() { this.inputFocus = false },
		categoryIconName(category) {
			if (category === 'delivery') return 'bolt'
			if (category === 'skill') return 'skills'
			if (category === 'device') return 'devices'
			if (category === 'payment') return 'after-sales'
			return 'service'
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
		contextKicker(ctx) {
			if (!ctx) return ''
			if (ctx.type === 'order') return '关联订单'
			if (ctx.type === 'device') return '关联设备'
			return '关联商品'
		},
		contextMetaLine(ctx) {
			if (!ctx) return ''
			const parts = []
			if (ctx.increment_id) parts.push(`#${ctx.increment_id}`)
			if (ctx.sn) parts.push(`SN ${ctx.sn}`)
			if (ctx.type === 'order') {
				if (ctx.status_label) parts.push(ctx.status_label)
				if (ctx.price) parts.push(`¥${ctx.price}`)
			} else if (ctx.type === 'device') {
				parts.push('名下登记设备')
			} else {
				parts.push(this.productKindLabel(ctx.kind))
				if (ctx.sku) parts.push(ctx.sku)
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
				name: d.display_name || d.name || `机器设备 ${d.sn || d.serial_number || ''}`,
				sn: d.sn || d.serial_number || '',
			}
		},
		removeProductContext() {
			this.productContext = null
			uni.removeStorageSync('supportProductContext')
		},
		useFaq(item, index) {
			const idx = this.topics.findIndex((topic) => topic.value === item.category)
			if (idx >= 0) this.topicIndex = idx
			this.selectedFaqIndex = typeof index === 'number' ? index : -1
			this.content = item.prompt || item.question || ''
			this.inputFocus = true
			uni.showToast({ title: '已填入问题描述', icon: 'none' })
		},
		startStatusPolling() {
			this.stopStatusPolling()
			this.statusTimer = setInterval(() => this.refreshOnline(true), 30000)
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
				this.agentsOnline = Number(data.agents_online || 0)
				this.queueSize = Number(data.queue_size || 0)
			} catch (e) {
				this.online = false
				this.agentsOnline = 0
				this.queueSize = 0
			}
			if (!this.channelTouched) this.channel = this.online ? 'online' : 'async'
		},
		selectChannel(mode) {
			this.channel = mode
			this.channelTouched = true
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
				return '会话已创建，等待补充问题'
			}
			const prefix = {
				admin: '客服：',
				agent: '客服：',
				staff: '客服：',
				developer: '技术：',
				system: '系统：',
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
			if (diff < 3600000) return `${Math.floor(diff / 60000)} 分钟前`
			if (diff < 86400000) return `${Math.floor(diff / 3600000)} 小时前`
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
				const useOnline = this.channel === 'online'
				const payload = { content: text, category: this.currentTopic.value }
				if (this.productContext) {
					payload.attachments = [this.productContext]
				}
				const res = useOnline
					? await ticketApi.createOnline(payload)
					: await ticketApi.create(payload)
				const id = res && res.data && res.data.id
				const resumed = !!(res && res.data && res.data.resumed)
				this.content = ''
				this.selectedFaqIndex = -1
				this.productContext = null
				uni.removeStorageSync('supportProductContext')
				uni.showToast({
					title: resumed
						? '已续接到当前会话'
						: (useOnline
							? (this.isOnline ? '已发送，客服会尽快回复' : '已进入排队，客服上线后会回复')
							: `留言已提交，${this.replyPromise}内回复`),
					icon: 'none',
				})
				await this.refresh()
				if (id) {
					uni.navigateTo({ url: `/pages/mine/ticket-detail?id=${id}` })
				} else {
					this.activeTab = 'records'
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
	background: #F4F7FB;
}
.work-shell {
	flex: 1;
	min-height: 0;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.nav-status-pill {
	display: inline-flex;
	align-items: center;
	gap: 8rpx;
	padding: 6rpx 16rpx;
	border-radius: 999rpx;
	background: #F1F5F9;
	border: 1rpx solid #E5EAF2;
}
.nav-status-pill.online { background: #ECFDF5; border-color: #A7F3D0; }
.nav-status-dot { width: 10rpx; height: 10rpx; border-radius: 50%; background: #94A3B8; }
.nav-status-dot.on { background: #059669; }
.nav-status-text { font-size: 21rpx; font-weight: 500; color: #475569; }
.nav-status-pill.online .nav-status-text { color: #059669; }

/* 顶部工作区两段式导航 */
.mode-bar {
	flex-shrink: 0;
	display: flex;
	align-items: stretch;
	background: #FFFFFF;
	border-bottom: 1rpx solid #E5EAF2;
	padding: 0 24rpx;
	gap: 12rpx;
}
.mode-tab {
	flex: 1;
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8rpx;
	height: 84rpx;
	color: #64748B;
	font-size: 27rpx;
	font-weight: 500;
}
.mode-tab.active {
	color: #165DFF;
	font-weight: 600;
}
.mode-tab.active::after {
	content: '';
	position: absolute;
	left: 50%;
	transform: translateX(-50%);
	width: 72rpx;
	bottom: 0;
	height: 5rpx;
	border-radius: 5rpx 5rpx 0 0;
	background: #165DFF;
}
.mode-tab-count {
	font-size: 21rpx;
	color: #94A3B8;
}
.mode-tab.active .mode-tab-count {
	color: #165DFF;
}
.mode-unread-badge {
	min-width: 30rpx;
	height: 30rpx;
	padding: 0 8rpx;
	border-radius: 999rpx;
	background: #DC2626;
	color: #FFFFFF;
	font-size: 19rpx;
	font-weight: 600;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.scroll-content {
	flex: 1;
	height: 0;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.scroll-content::-webkit-scrollbar { display: none; width: 0; height: 0; }
.page-content { padding: 20rpx 24rpx 0; }

/* 进行中会话置顶轻提示条 */
.resume-strip {
	display: flex;
	align-items: center;
	gap: 12rpx;
	margin-bottom: 18rpx;
	padding: 16rpx 20rpx;
	border-radius: 14rpx;
	background: #EEF4FF;
	border: 1rpx solid #BFDBFE;
}
.resume-dot {
	width: 12rpx;
	height: 12rpx;
	border-radius: 50%;
	background: #165DFF;
	flex-shrink: 0;
}
.resume-text {
	flex: 1;
	min-width: 0;
	font-size: 23rpx;
	color: #1E3A8A;
	line-height: 1.4;
}
.resume-link {
	flex-shrink: 0;
	font-size: 23rpx;
	font-weight: 600;
	color: #165DFF;
}

.card {
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
	border-radius: 18rpx;
	padding: 24rpx;
	margin-bottom: 18rpx;
}
.card-head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12rpx;
}
.card-title-wrap {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 4rpx;
}
.card-title { font-size: 29rpx; font-weight: 600; color: #0F172A; line-height: 1.3; }
.card-sub { font-size: 21rpx; color: #64748B; }
.card-hours { flex-shrink: 0; font-size: 21rpx; color: #64748B; padding-top: 4rpx; }

/* AI 快速分流入口 */
.ai-link-btn {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	gap: 8rpx;
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	background: #EEF4FF;
	border: 1rpx solid #DCE5F2;
}
.ai-link-text { font-size: 22rpx; font-weight: 600; color: #165DFF; }

/* 2x2 快速场景卡片 */
.faq-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12rpx;
	margin-top: 18rpx;
}
.faq-chip-card {
	display: flex;
	flex-direction: column;
	gap: 6rpx;
	padding: 16rpx 18rpx;
	border-radius: 14rpx;
	background: #F8FAFC;
	border: 1rpx solid #E5EAF2;
}
.faq-chip-card.active {
	background: #EEF4FF;
	border-color: #165DFF;
}
.faq-chip-cat { font-size: 19rpx; font-weight: 600; color: #165DFF; }
.faq-chip-q {
	font-size: 23rpx;
	font-weight: 500;
	color: #0F172A;
	line-height: 1.4;
}

/* 填单内部节律 */
.field-label {
	display: block;
	margin-top: 18rpx;
	margin-bottom: 10rpx;
	font-size: 22rpx;
	font-weight: 500;
	color: #64748B;
}
.topic-row { display: flex; flex-wrap: wrap; gap: 8rpx; }
.topic-chip {
	flex-shrink: 0;
	padding: 10rpx 16rpx;
	border-radius: 999rpx;
	background: #F8FAFC;
	border: 1rpx solid #E5EAF2;
	font-size: 22rpx;
	color: #475569;
}
.topic-chip.active {
	background: #EEF4FF;
	border-color: #165DFF;
	color: #165DFF;
	font-weight: 600;
}

/* 轻量分段接入方式 */
.channel-segment {
	display: flex;
	gap: 8rpx;
	padding: 6rpx;
	border-radius: 14rpx;
	background: #F4F7FB;
	border: 1rpx solid #E5EAF2;
}
.segment-item {
	flex: 1;
	min-width: 0;
	padding: 12rpx 14rpx;
	border-radius: 10rpx;
}
.segment-item.active {
	background: #FFFFFF;
	box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06);
}
.segment-title-row { display: flex; align-items: center; gap: 8rpx; }
.segment-dot { width: 10rpx; height: 10rpx; border-radius: 50%; background: #CBD5E1; flex-shrink: 0; }
.segment-dot.on { background: #059669; }
.segment-title { font-size: 24rpx; font-weight: 600; color: #475569; }
.segment-item.active .segment-title { color: #165DFF; }
.segment-desc { display: block; margin-top: 4rpx; font-size: 20rpx; color: #64748B; }

/* 输入区与附件一体框 */
.compose-box {
	background: #F8FAFC;
	border: 1rpx solid #DCE5F2;
	border-radius: 14rpx;
	overflow: hidden;
}
.context-inline {
	display: flex;
	align-items: center;
	gap: 12rpx;
	margin: 14rpx 14rpx 0;
	padding: 12rpx 14rpx;
	border-radius: 10rpx;
	background: #FFFFFF;
	border: 1rpx solid #DCE5F2;
}
.context-inline-icon {
	width: 60rpx;
	height: 60rpx;
	flex-shrink: 0;
	border-radius: 10rpx;
	background: #EEF4FF;
	overflow: hidden;
	display: flex;
	align-items: center;
	justify-content: center;
}
.context-inline-icon image { width: 100%; height: 100%; }
.context-inline-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2rpx; }
.context-inline-kicker { font-size: 19rpx; font-weight: 600; color: #165DFF; }
.context-inline-name { font-size: 23rpx; font-weight: 600; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.context-inline-meta { font-size: 19rpx; color: #64748B; }
.context-inline-remove {
	width: 38rpx;
	height: 38rpx;
	flex-shrink: 0;
	border-radius: 50%;
	background: #F1F5F9;
	color: #64748B;
	font-size: 24rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}
.compose-input {
	width: 100%;
	height: 156rpx;
	box-sizing: border-box;
	padding: 18rpx 20rpx 12rpx;
	border: 0;
	outline: none;
	resize: none;
	background: transparent;
	font-size: 27rpx;
	line-height: 1.6;
	color: #0F172A;
}
.compose-placeholder { color: #94A3B8; }
.compose-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12rpx;
	padding: 10rpx 16rpx;
	border-top: 1rpx solid #E5EAF2;
	background: rgba(255, 255, 255, 0.7);
}
.attach-actions { display: flex; align-items: center; gap: 10rpx; flex: 1; min-width: 0; }
.attach-trigger {
	display: inline-flex;
	align-items: center;
	gap: 6rpx;
	padding: 6rpx 14rpx;
	border-radius: 999rpx;
	background: #FFFFFF;
	border: 1rpx solid #DCE5F2;
	font-size: 21rpx;
	font-weight: 500;
	color: #334155;
}
.attach-bound-hint { font-size: 20rpx; color: #94A3B8; }
.compose-count { font-size: 20rpx; color: #94A3B8; flex-shrink: 0; }

.compose-foot {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
	margin-top: 18rpx;
}
.privacy-note { flex: 1; font-size: 20rpx; line-height: 1.45; color: #94A3B8; }
.send-btn {
	flex-shrink: 0;
	padding: 16rpx 36rpx;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 25rpx;
	font-weight: 600;
}
.send-btn.disabled { opacity: 0.45; }

/* Tab 2：服务记录工具条与列表 */
.records-toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12rpx;
	margin-bottom: 16rpx;
}
.filter-pills { display: flex; align-items: center; gap: 10rpx; flex-wrap: wrap; }
.filter-pill {
	padding: 8rpx 20rpx;
	border-radius: 999rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
	font-size: 22rpx;
	color: #475569;
}
.filter-pill.active {
	background: #EEF4FF;
	border-color: #165DFF;
	color: #165DFF;
	font-weight: 600;
}
.filter-count {
	margin-left: 6rpx;
	opacity: 0.78;
}
.refresh-btn {
	flex-shrink: 0;
	padding: 8rpx 20rpx;
	border: 1rpx solid #DCE5F2;
	border-radius: 999rpx;
	background: #FFFFFF;
	font-size: 22rpx;
	color: #475569;
}

.records-list { display: flex; flex-direction: column; gap: 12rpx; }
.conversation {
	display: flex;
	align-items: center;
	gap: 18rpx;
	padding: 22rpx;
	border-radius: 16rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.conv-mark {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 72rpx;
	height: 72rpx;
	border-radius: 16rpx;
	background: #EEF4FF;
}
.conv-main { min-width: 0; flex: 1; }
.conv-top { display: flex; align-items: center; justify-content: space-between; gap: 12rpx; }
.conv-title-row { display: flex; align-items: center; gap: 8rpx; min-width: 0; }
.conv-title { font-size: 27rpx; font-weight: 600; color: #0F172A; }
.conv-id { font-size: 20rpx; color: #94A3B8; }
.conv-time { flex-shrink: 0; font-size: 21rpx; color: #94A3B8; }
.conv-preview { display: block; margin-top: 8rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 23rpx; color: #64748B; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 12rpx; }
.conv-status { padding: 3rpx 14rpx; border-radius: 6rpx; font-size: 20rpx; font-weight: 500; background: #EEF4FF; color: #165DFF; }
.status-resolved { background: #ECFDF5; color: #059669; }
.status-held { background: #FFFBEB; color: #B45309; }
.status-closed { background: #F1F5F9; color: #64748B; }
.unread-badge {
	padding: 2rpx 12rpx;
	border-radius: 999rpx;
	background: #FEE2E2;
	color: #DC2626;
	font-size: 20rpx;
	font-weight: 600;
}
.conv-arrow { flex-shrink: 0; font-size: 32rpx; color: #94A3B8; }

.empty-panel {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12rpx;
	padding: 64rpx 32rpx;
	border-radius: 18rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.empty-title { font-size: 28rpx; font-weight: 600; color: #0F172A; }
.empty-desc { text-align: center; font-size: 23rpx; line-height: 1.55; color: #64748B; }
.empty-cta {
	margin-top: 12rpx;
	padding: 14rpx 36rpx;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 24rpx;
	font-weight: 600;
}

.service-foot {
	display: flex;
	justify-content: center;
	padding: 28rpx 0 8rpx;
	font-size: 21rpx;
	color: #94A3B8;
}

.login-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 0 40rpx; }
.login-card {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 52rpx 36rpx;
	border-radius: 20rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.login-mark {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 104rpx;
	height: 104rpx;
	border-radius: 50%;
	background: #EEF4FF;
}
.login-title { margin-top: 24rpx; font-size: 32rpx; font-weight: 600; color: #0F172A; }
.login-desc { margin-top: 12rpx; text-align: center; font-size: 24rpx; line-height: 1.6; color: #64748B; }
.login-btn {
	margin-top: 32rpx;
	padding: 18rpx 56rpx;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 26rpx;
	font-weight: 600;
}
.safe-space { height: calc(40rpx + env(safe-area-inset-bottom)); }
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
