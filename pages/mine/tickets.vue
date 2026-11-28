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

		<scroll-view v-if="loggedIn" class="scroll-content" scroll-y="true" :enhanced="true" :show-scrollbar="false">
			<view class="page-content">
				<view class="compose-card">
					<view class="compose-head">
						<text class="compose-title">发起咨询或留言</text>
						<text class="compose-hours sm-mono">{{ serviceHours }}</text>
					</view>

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

					<view class="channel-row">
						<view
							class="channel-option"
							:class="{ active: channel === 'online' }"
							role="button"
							tabindex="0"
							aria-label="选择在线客服"
							@tap="selectChannel('online')"
						>
							<view class="channel-top">
								<text class="channel-title">在线客服</text>
								<view class="channel-dot" :class="{ on: isOnline }"></view>
							</view>
							<text class="channel-desc sm-mono">{{ onlineChannelDesc }}</text>
						</view>
						<view
							class="channel-option"
							:class="{ active: channel === 'async' }"
							role="button"
							tabindex="0"
							aria-label="选择工单留言"
							@tap="selectChannel('async')"
						>
							<view class="channel-top">
								<text class="channel-title">工单留言</text>
							</view>
							<text class="channel-desc">{{ replyPromise }}，进度可追溯</text>
						</view>
					</view>

					<view v-if="activeTicket" class="active-hint" role="button" tabindex="0" aria-label="查看进行中的会话" @tap="goDetail(activeTicket)">
						<text class="active-hint-text">你有进行中的会话，本次发送会续接到该会话</text>
						<text class="active-hint-link">查看 ›</text>
					</view>

					<view v-if="productContext" class="product-context-card">
						<view class="product-context-image">
							<image v-if="productContext.image" :src="productContext.image" mode="aspectFill"></image>
							<SmIcon v-else :name="contextIconName(productContext)" size="42rpx" />
						</view>
						<view class="product-context-main">
							<text class="product-context-kicker">{{ contextKicker(productContext) }}</text>
							<text class="product-context-name">{{ productContext.name }}</text>
							<text class="product-context-meta sm-mono">
								<text v-if="productContext.increment_id">#{{ productContext.increment_id }} · </text>
								<text v-if="productContext.sn">SN {{ productContext.sn }} · </text>
								{{ contextMetaText(productContext) }}
							</text>
						</view>
						<view class="product-context-remove" role="button" tabindex="0" aria-label="移除关联对象" @tap="removeProductContext">×</view>
					</view>

					<view v-if="!productContext && (myOrders.length || myDevices.length)" class="attach-bar">
						<picker v-if="myOrders.length" :range="orderAttachLabels" @change="onPickOrderAttach">
							<view class="attach-pill">
								<SmIcon name="clipboard" size="24rpx" />
								<text>关联订单</text>
							</view>
						</picker>
						<picker v-if="myDevices.length" :range="deviceAttachLabels" @change="onPickDeviceAttach">
							<view class="attach-pill">
								<SmIcon name="devices" size="24rpx" />
								<text>关联设备</text>
							</view>
						</picker>
					</view>

					<view class="compose-box">
						<textarea
							v-model="content"
							class="compose-input"
							:focus="inputFocus"
							placeholder="请描述你遇到的问题，例如：技能安装报错、设备掉线、订单退款进度…"
							placeholder-class="compose-placeholder"
							maxlength="2000"
							auto-height="false"
							cursor-spacing="16"
							@blur="onInputBlur"
						/>
						<text v-if="content.length > 0" class="compose-count sm-mono">{{ content.length }}/2000</text>
					</view>

					<view class="compose-foot">
						<text class="privacy-note">请勿发送密码、验证码等敏感信息</text>
						<view
							class="send-btn"
							:class="{ disabled: submitting || !content.trim() }"
							role="button"
							tabindex="0"
							aria-label="发送问题"
							@tap="submit"
						>
							<text>{{ submitting ? '发送中…' : (channel === 'online' ? '发送咨询' : '提交留言') }}</text>
						</view>
					</view>
				</view>

				<view class="section-head">
					<view class="section-head-copy">
						<text class="section-title">我的会话</text>
						<text class="section-sub sm-mono">{{ listSummary }}</text>
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
					<view v-if="!list.length" class="empty-panel">
						<SmIcon name="empty-inbox" size="72rpx" />
						<text class="empty-title">暂无服务记录</text>
						<text class="empty-desc">在上方描述问题并发送，客服回复后会在这里提醒你。</text>
					</view>

					<block v-else>
						<block v-for="(item, index) in visibleList" :key="item.id">
							<view
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
										<text class="conv-title">{{ categoryLabel(item.category) }}</text>
										<text class="conv-time sm-mono">{{ relativeTime(item.updated_at || item.created_at) }}</text>
									</view>
									<text class="conv-preview">{{ preview(item) }}</text>
									<view class="conv-bottom">
										<text class="conv-status" :class="'status-' + (item.held ? 'held' : item.status)">{{ item.status_label || statusLabel(item.status) }}</text>
										<view v-if="unreadCount(item)" class="unread-badge sm-mono">
											<text>{{ unreadCount(item) > 99 ? '99+' : unreadCount(item) }}</text>
										</view>
									</view>
								</view>
								<text class="conv-arrow">›</text>
							</view>

							<view
								v-if="index === 0 && restItems.length"
								class="conv-toggle"
								role="button"
								tabindex="0"
								:aria-label="expanded ? '收起历史会话' : '展开历史会话'"
								@tap="toggleExpanded"
							>
								<text class="conv-toggle-text">{{ expanded ? '收起历史会话' : `展开其余 ${restItems.length} 条会话` }}</text>
								<view v-if="!expanded && restUnread" class="unread-badge sm-mono">
									<text>{{ restUnread > 99 ? '99+' : restUnread }}</text>
								</view>
								<text class="conv-toggle-caret" :class="{ open: expanded }">▾</text>
							</view>
						</block>
					</block>
				</state-view>

				<view class="ai-entry" role="button" tabindex="0" aria-label="先问问机甲 AI" @tap="goAi">
					<view class="ai-entry-mark">
						<SmIcon name="state-spark" size="36rpx" />
					</view>
					<view class="ai-entry-main">
						<text class="ai-entry-title">先问问机甲 AI</text>
						<text class="ai-entry-desc">选型与常见问题即时解答，复杂故障再转人工</text>
					</view>
					<text class="ai-entry-arrow">›</text>
				</view>

				<view class="section-head">
					<view class="section-head-copy">
						<text class="section-title">常见问题</text>
						<text class="section-sub">点击快速填入上方输入框</text>
					</view>
				</view>

				<view class="faq-card">
					<view
						v-for="(item, index) in faqItems"
						:key="item.question"
						class="faq-row"
						:class="{ last: index === faqItems.length - 1 }"
						role="button"
						tabindex="0"
						@tap="useFaq(item)"
					>
						<text class="faq-text">{{ item.question }}</text>
						<text class="faq-arrow">›</text>
					</view>
				</view>

				<view class="service-foot">
					<text>人工服务时间：{{ serviceHours }}</text>
					<text>非服务时间留言，我们会在 {{ replyPromise }}。</text>
				</view>
				<view class="safe-space"></view>
			</view>
		</scroll-view>

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

export default {
	components: { NavBar, SmIcon, StateView },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			submitting: false,
			content: '',
			topicIndex: 0,
			inputFocus: false,
			online: false,
			agentsOnline: 0,
			queueSize: 0,
			channel: 'online',
			channelTouched: false,
			statusTimer: null,
			list: [],
			expanded: false,
			replyPromise: REPLY_PROMISE,
			serviceHours: SERVICE_HOURS,
			faqItems: FAQ_ITEMS,
			topics: CATEGORY_OPTIONS,
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
			return this.list.find((item) => item.status === 'open' || item.status === 'processing') || null
		},
		listSummary() {
			if (!this.list.length) return '你的服务记录会出现在这里'
			const active = this.activeTicket ? 1 : 0
			const history = this.list.length - active
			return history ? `进行中 ${active} · 历史 ${history}` : `进行中 ${active}`
		},
		onlineChannelDesc() {
			if (!this.isOnline) return '暂无客服在线，可排队'
			const queue = this.queueSize > 0 ? ` · 排队 ${this.queueSize} 人` : ' · 无需排队'
			return `客服在线 ${this.agentsOnline} 位${queue}`
		},
		topicLabels() {
			return this.topics.map((topic) => topic.label)
		},
		currentTopic() {
			return this.topics[this.topicIndex] || this.topics[0]
		},
		restItems() {
			return this.list.slice(1)
		},
		visibleList() {
			return this.expanded ? this.list : this.list.slice(0, 1)
		},
		restUnread() {
			return this.restItems.reduce((sum, item) => sum + this.unreadCount(item), 0)
		},
		orderAttachLabels() {
			return this.myOrders.map(o => `#${o.increment_id || o.id} · ${(o.items && o.items[0] && o.items[0].name) || '订单'} · ¥${o.grand_total}`)
		},
		deviceAttachLabels() {
			return this.myDevices.map(d => `${d.sn}${d.display_name ? `（${d.display_name}）` : ''}`)
		},
	},
	onLoad(options) {
		const q = options || {}
		if (q.category) {
			const index = this.topics.findIndex((topic) => topic.value === q.category)
			if (index >= 0) this.topicIndex = index
		}
		if (q.content) {
			let content = String(q.content)
			try { content = decodeURIComponent(content) } catch (e) {}
			this.content = content
			this.inputFocus = false
		}
		if (q.productContext || q.orderContext || q.deviceContext) {
			const context = uni.getStorageSync('supportProductContext')
			if (context && ['product', 'order', 'device'].includes(context.type) && context.name) {
				this.productContext = context
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
		selectTopic(index) {
			this.topicIndex = Number(index) || 0
		},
		onTopicChange(event) {
			this.topicIndex = Number(event.detail.value) || 0
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
			if (ctx.type === 'order') return '随消息关联订单'
			if (ctx.type === 'device') return '随消息关联设备'
			return '随消息发送商品'
		},
		contextMetaText(ctx) {
			if (!ctx) return ''
			if (ctx.type === 'order') return [ctx.status_label, ctx.price ? `¥${ctx.price}` : ''].filter(Boolean).join(' · ')
			if (ctx.type === 'device') return '名下登记机器设备'
			return [this.productKindLabel(ctx.kind), ctx.sku, ctx.price ? `¥${ctx.price}` : ''].filter(Boolean).join(' · ')
		},
		onPickOrderAttach(e) {
			const o = this.myOrders[Number(e.detail.value)]
			if (!o) return
			this.productContext = {
				type: 'order',
				order_id: o.id,
				increment_id: String(o.increment_id || o.id),
				name: (o.items && o.items[0] && o.items[0].name) || `订单 #${o.increment_id || o.id}`,
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
				name: d.display_name || `机器设备 ${d.sn}`,
				sn: d.sn || '',
			}
		},
		removeProductContext() {
			this.productContext = null
			uni.removeStorageSync('supportProductContext')
		},
		useFaq(item) {
			const index = this.topics.findIndex((topic) => topic.value === item.category)
			if (index >= 0) this.topicIndex = index
			this.content = item.prompt
			this.inputFocus = true
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
		toggleExpanded() {
			this.expanded = !this.expanded
		},
		preview(item) {
			const last = item && item.last_message
			if (!last || !last.content) return '会话已创建，等待补充问题'
			const prefix = {
				admin: '客服：',
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
			if (!last || last.actor_type === 'customer') return 0
			return Number(last.id || 0) > Number(this.seenMap()[item.id] || 0) ? 1 : 0
		},
		markSeen(item) {
			if (!item || !item.last_message) return
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
			orderApi.getOrders({ limit: 20 }).then((r) => { this.myOrders = (r && Array.isArray(r.data)) ? r.data : [] }).catch(() => {})
			deviceApi.list().then((r) => { this.myDevices = (r && Array.isArray(r.data)) ? r.data : [] }).catch(() => {})
			try {
				const res = await ticketApi.list()
				this.list = (res && Array.isArray(res.data)) ? res.data : []
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
.nav-status-pill {
	display: inline-flex;
	align-items: center;
	gap: 8rpx;
	padding: 6rpx 16rpx;
	border-radius: 999rpx;
	background: #F1F5F9;
	border: 1rpx solid #E5EAF2;
}
.nav-status-pill.online {
	background: #ECFDF5;
	border-color: #A7F3D0;
}
.nav-status-dot {
	width: 10rpx;
	height: 10rpx;
	border-radius: 50%;
	background: #94A3B8;
}
.nav-status-dot.on {
	background: #059669;
}
.nav-status-text {
	font-size: 21rpx;
	font-weight: 500;
	color: #475569;
}
.nav-status-pill.online .nav-status-text {
	color: #059669;
}

.scroll-content {
	flex: 1;
	height: 0;
	scrollbar-width: none;
	-ms-overflow-style: none;
}
.scroll-content::-webkit-scrollbar { display: none; width: 0; height: 0; }
.page-content { padding: 20rpx 24rpx 0; }

.compose-card {
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
	border-radius: 18rpx;
	padding: 24rpx;
}
.compose-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12rpx;
}
.compose-title { font-size: 30rpx; font-weight: 600; color: #0F172A; }
.compose-hours { font-size: 21rpx; color: #64748B; }

.topic-row {
	display: flex;
	flex-wrap: wrap;
	gap: 10rpx;
	margin-top: 18rpx;
}
.topic-chip {
	padding: 8rpx 18rpx;
	border-radius: 999rpx;
	background: #F8FAFC;
	border: 1rpx solid #E5EAF2;
	font-size: 23rpx;
	color: #475569;
}
.topic-chip.active {
	background: #EEF4FF;
	border-color: #165DFF;
	color: #165DFF;
	font-weight: 600;
}

.channel-row { display: flex; gap: 14rpx; margin-top: 18rpx; }
.channel-option {
	flex: 1;
	min-width: 0;
	padding: 16rpx 18rpx;
	border: 1rpx solid #E5EAF2;
	border-radius: 14rpx;
	background: #F8FAFC;
}
.channel-option.active {
	border-color: #165DFF;
	background: #EEF4FF;
}
.channel-top { display: flex; align-items: center; gap: 8rpx; }
.channel-title { font-size: 25rpx; font-weight: 600; color: #0F172A; }
.channel-option.active .channel-title { color: #165DFF; }
.channel-dot { flex-shrink: 0; width: 12rpx; height: 12rpx; border-radius: 50%; background: #CBD5E1; }
.channel-dot.on { background: #059669; box-shadow: 0 0 0 4rpx rgba(5, 150, 105, 0.14); }
.channel-desc { display: block; margin-top: 6rpx; font-size: 20rpx; line-height: 1.4; color: #64748B; }

.active-hint {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 14rpx;
	margin-top: 16rpx;
	padding: 14rpx 18rpx;
	border: 1rpx solid #BFDBFE;
	border-radius: 12rpx;
	background: #EEF4FF;
}
.active-hint-text { flex: 1; font-size: 22rpx; line-height: 1.45; color: #165DFF; }
.active-hint-link { flex-shrink: 0; font-size: 22rpx; font-weight: 600; color: #165DFF; }

.product-context-card {
	display: flex;
	align-items: center;
	gap: 16rpx;
	margin-top: 16rpx;
	padding: 16rpx;
	border-radius: 14rpx;
	background: #F8FAFC;
	border: 1rpx solid #DCE5F2;
}
.product-context-image {
	width: 84rpx;
	height: 84rpx;
	flex-shrink: 0;
	overflow: hidden;
	border-radius: 12rpx;
	background: #EEF4FF;
	display: flex;
	align-items: center;
	justify-content: center;
}
.product-context-image image { width: 100%; height: 100%; }
.product-context-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.product-context-kicker { color: #165DFF; font-size: 20rpx; font-weight: 600; }
.product-context-name { overflow: hidden; color: #0F172A; font-size: 25rpx; font-weight: 600; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.product-context-meta { color: #64748B; font-size: 20rpx; }
.product-context-remove {
	width: 44rpx;
	height: 44rpx;
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: #E2E8F0;
	color: #475569;
	font-size: 28rpx;
}

.attach-bar { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 16rpx; }
.attach-pill {
	display: inline-flex;
	align-items: center;
	gap: 8rpx;
	font-size: 22rpx;
	font-weight: 500;
	color: #334155;
	background: #F8FAFC;
	border: 1rpx solid #DCE5F2;
	padding: 8rpx 18rpx;
	border-radius: 999rpx;
}

.compose-box {
	position: relative;
	margin-top: 16rpx;
	background: #F8FAFC;
	border: 1rpx solid #DCE5F2;
	border-radius: 14rpx;
	padding: 18rpx 20rpx 38rpx;
}
.compose-input {
	width: 100%;
	height: 148rpx;
	box-sizing: border-box;
	border: 0;
	outline: none;
	resize: none;
	background: transparent;
	font-size: 27rpx;
	line-height: 1.6;
	color: #0F172A;
}
.compose-placeholder { color: #94A3B8; }
.compose-count {
	position: absolute;
	right: 18rpx;
	bottom: 10rpx;
	font-size: 20rpx;
	color: #94A3B8;
}

.compose-foot {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
	margin-top: 16rpx;
}
.privacy-note { flex: 1; font-size: 21rpx; line-height: 1.45; color: #64748B; }
.send-btn {
	flex-shrink: 0;
	padding: 16rpx 34rpx;
	border-radius: 999rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 25rpx;
	font-weight: 600;
}
.send-btn.disabled { opacity: 0.45; }

.section-head {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	margin: 28rpx 4rpx 14rpx;
}
.section-head-copy { display: flex; flex-direction: column; gap: 4rpx; }
.section-title { display: block; font-size: 29rpx; font-weight: 600; color: #0F172A; }
.section-sub { display: block; font-size: 22rpx; color: #64748B; }
.refresh-btn {
	padding: 8rpx 20rpx;
	border: 1rpx solid #DCE5F2;
	border-radius: 999rpx;
	background: #FFFFFF;
	font-size: 22rpx;
	color: #475569;
}

.conversation {
	display: flex;
	align-items: center;
	gap: 18rpx;
	margin-bottom: 12rpx;
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
.conv-top { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.conv-title { font-size: 28rpx; font-weight: 600; color: #0F172A; }
.conv-time { flex-shrink: 0; font-size: 21rpx; color: #94A3B8; }
.conv-preview { display: block; margin-top: 8rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 23rpx; color: #64748B; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 10rpx; }
.conv-status { padding: 3rpx 14rpx; border-radius: 6rpx; font-size: 20rpx; font-weight: 500; background: #EEF4FF; color: #165DFF; }
.status-resolved { background: #ECFDF5; color: #059669; }
.status-held { background: #FFFBEB; color: #B45309; }
.status-closed { background: #F1F5F9; color: #64748B; }
.unread-badge {
	min-width: 32rpx;
	height: 32rpx;
	padding: 0 8rpx;
	box-sizing: border-box;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 999rpx;
	background: #DC2626;
	color: #FFFFFF;
	font-size: 19rpx;
	font-weight: 600;
}
.conv-arrow { flex-shrink: 0; font-size: 32rpx; color: #94A3B8; }
.conv-toggle {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10rpx;
	margin-bottom: 12rpx;
	padding: 18rpx 22rpx;
	border-radius: 14rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.conv-toggle-text { font-size: 23rpx; color: #475569; font-weight: 500; }
.conv-toggle-caret { font-size: 24rpx; line-height: 1; color: #94A3B8; transition: transform 0.2s ease; }
.conv-toggle-caret.open { transform: rotate(180deg); }

.ai-entry {
	display: flex;
	align-items: center;
	gap: 16rpx;
	margin-top: 24rpx;
	padding: 20rpx 22rpx;
	border-radius: 16rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.ai-entry-mark {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 68rpx;
	height: 68rpx;
	border-radius: 16rpx;
	background: #EEF4FF;
}
.ai-entry-main { min-width: 0; flex: 1; }
.ai-entry-title { display: block; font-size: 27rpx; font-weight: 600; color: #0F172A; }
.ai-entry-desc { display: block; margin-top: 4rpx; font-size: 21rpx; color: #64748B; }
.ai-entry-arrow { flex-shrink: 0; font-size: 32rpx; color: #94A3B8; }

.faq-card {
	overflow: hidden;
	border-radius: 16rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.faq-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 18rpx;
	padding: 24rpx 22rpx;
	border-bottom: 1rpx solid #F1F5F9;
}
.faq-row.last { border-bottom: none; }
.faq-text { flex: 1; font-size: 26rpx; color: #0F172A; line-height: 1.45; }
.faq-arrow { flex-shrink: 0; font-size: 30rpx; color: #94A3B8; }

.empty-panel {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12rpx;
	padding: 44rpx 28rpx;
	border-radius: 16rpx;
	background: #FFFFFF;
	border: 1rpx solid #E5EAF2;
}
.empty-title { font-size: 27rpx; font-weight: 600; color: #0F172A; }
.empty-desc { text-align: center; font-size: 22rpx; line-height: 1.55; color: #64748B; }

.service-foot {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6rpx;
	padding: 28rpx 0 8rpx;
	font-size: 21rpx;
	line-height: 1.5;
	color: #64748B;
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
