<template>
	<view class="page-container">
		<view class="nav-area">
			<view class="nav-content">
				<view class="nav-back" role="button" tabindex="0" aria-label="返回" @tap="goBack">
					<text>‹</text>
				</view>
				<view class="nav-left">
					<text class="brand">客服中心</text>
					<text class="subtitle">人工服务 · 全程留痕</text>
				</view>
				<view class="nav-right"></view>
			</view>
		</view>

		<scroll-view v-if="loggedIn" class="scroll-content" scroll-y="true" show-scrollbar="false">
			<view class="page-content">
				<view class="compose-card glass">
					<view class="compose-head">
						<text class="compose-title">描述你遇到的问题</text>
						<picker
							class="topic-picker"
							mode="selector"
							:range="topicLabels"
							:value="topicIndex"
							@change="onTopicChange"
						>
							<view class="topic-value">
								<text>{{ currentTopic.label }}</text>
								<text class="topic-caret">⌄</text>
							</view>
						</picker>
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
							<text class="channel-desc">{{ onlineChannelDesc }}</text>
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
							<text class="channel-desc">24 小时内回复，可随时查看</text>
						</view>
					</view>

					<view v-if="activeTicket" class="active-hint" role="button" tabindex="0" aria-label="查看进行中的会话" @tap="goDetail(activeTicket)">
						<text class="active-hint-text">你有进行中的会话，本次发送会续接到该会话</text>
						<text class="active-hint-link">查看 ›</text>
					</view>

					<view v-if="productContext" class="product-context-card">
						<view class="product-context-image">
							<image v-if="productContext.image" :src="productContext.image" mode="aspectFill"></image>
							<text v-else>商</text>
						</view>
						<view class="product-context-main">
							<text class="product-context-kicker">随消息发送商品</text>
							<text class="product-context-name">{{ productContext.name }}</text>
							<text class="product-context-meta">
								{{ productKindLabel(productContext.kind) }}<text v-if="productContext.sku"> · {{ productContext.sku }}</text><text v-if="productContext.price"> · ¥{{ productContext.price }}</text>
							</text>
						</view>
						<view class="product-context-remove" role="button" tabindex="0" aria-label="移除商品" @tap="removeProductContext">×</view>
					</view>

					<textarea
						v-model="content"
						class="compose-input"
						:focus="inputFocus"
						placeholder="例如：技能安装失败、设备无法连接、退款进度……"
						placeholder-class="compose-placeholder"
						maxlength="2000"
						auto-height="false"
						cursor-spacing="16"
						@blur="onInputBlur"
					/>

					<view class="compose-foot">
						<text class="privacy-note">请勿发送密码、验证码等敏感信息</text>
						<view class="send-btn" :class="{ disabled: submitting }" role="button" tabindex="0" aria-label="发送问题" @tap="submit">
							<text>{{ submitting ? '…' : '➤' }}</text>
						</view>
					</view>
				</view>

				<view class="section-head">
					<view>
						<text class="section-title">我的会话</text>
						<text class="section-sub">{{ listSummary }}</text>
					</view>
					<view class="refresh-btn" role="button" tabindex="0" aria-label="刷新会话" @tap="refresh">刷新</view>
				</view>

				<state-view
					:loading="loading"
					:error="loadError"
					:empty="false"
					btn-color="#165DFF"
					@retry="refresh"
				>
					<view v-if="!list.length" class="empty-panel glass">
						<text class="empty-title">还没有会话</text>
						<text class="empty-desc">在上方描述问题并发送，客服回复后会在这里提醒你。</text>
					</view>

					<block v-else>
						<block v-for="(item, index) in visibleList" :key="item.id">
							<view
								class="conversation glass"
								role="button"
								tabindex="0"
								:aria-label="`打开会话：${categoryLabel(item.category)}`"
								@tap="goDetail(item)"
							>
								<view class="conv-mark">
									<text>{{ categoryShort(item.category) }}</text>
								</view>
								<view class="conv-main">
									<view class="conv-top">
										<text class="conv-title">{{ categoryLabel(item.category) }}</text>
										<text class="conv-time">{{ relativeTime(item.updated_at || item.created_at) }}</text>
									</view>
									<text class="conv-preview">{{ preview(item) }}</text>
									<view class="conv-bottom">
										<text class="conv-status" :class="'status-' + (item.held ? 'held' : item.status)">{{ item.status_label || statusLabel(item.status) }}</text>
										<view v-if="unreadCount(item)" class="unread-badge">
											<text>{{ unreadCount(item) > 99 ? '99+' : unreadCount(item) }}</text>
										</view>
									</view>
								</view>
								<text class="conv-arrow">›</text>
							</view>

							<view
								v-if="index === 0 && restItems.length"
								class="conv-toggle glass"
								role="button"
								tabindex="0"
								:aria-label="expanded ? '收起历史会话' : '展开历史会话'"
								@tap="toggleExpanded"
							>
								<text class="conv-toggle-text">{{ expanded ? '收起历史会话' : `展开其余 ${restItems.length} 条会话` }}</text>
								<view v-if="!expanded && restUnread" class="unread-badge">
									<text>{{ restUnread > 99 ? '99+' : restUnread }}</text>
								</view>
								<text class="conv-toggle-caret" :class="{ open: expanded }">⌄</text>
							</view>
						</block>
					</block>
				</state-view>

				<view class="ai-entry glass" role="button" tabindex="0" aria-label="先用机甲 AI 试试" @tap="goAi">
					<view class="ai-entry-mark">AI</view>
					<view class="ai-entry-main">
						<text class="ai-entry-title">先问问机甲 AI</text>
						<text class="ai-entry-desc">常见问题即时答，复杂问题再转人工</text>
					</view>
					<text class="ai-entry-arrow">›</text>
				</view>

				<view class="section-head">
					<view>
						<text class="section-title">常见问题</text>
						<text class="section-sub">点击填入问题，补充细节后发送</text>
					</view>
				</view>

				<view class="faq-card glass">
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
			<view class="login-card glass">
				<view class="login-mark">召</view>
				<text class="login-title">登录后联系客服</text>
				<text class="login-desc">登录后可以发起咨询、查看回复，并随时补充问题。</text>
				<view class="login-btn" role="button" tabindex="0" aria-label="前往登录" @tap="goLogin">前往登录</view>
			</view>
		</view>
	</view>
</template>

<script>
import { ticketApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import {
	CATEGORY_OPTIONS,
	FAQ_ITEMS,
	REPLY_PROMISE,
	SERVICE_HOURS,
	categoryLabel,
	statusLabel,
} from '@/utils/customer-service'
import StateView from '@/components/StateView.vue'

const SEEN_KEY = 'customer_service_seen_tickets_v1'

export default {
	components: { StateView },
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
		// 排队透明度：头部平台都会告诉用户「现在几个人在等」，而不是让用户盲等。
		onlineChannelDesc() {
			if (!this.isOnline) return '暂无客服在线，先排队'
			const queue = this.queueSize > 0 ? ` · 排队 ${this.queueSize} 人` : ' · 无需排队'
			return `客服在线 ${this.agentsOnline} 位${queue}`
		},
		topicLabels() {
			return this.topics.map((topic) => topic.label)
		},
		currentTopic() {
			return this.topics[this.topicIndex] || this.topics[0]
		},
		// 会话列表默认只露出最新一条（后端按 updated_at 倒序），其余折叠
		restItems() {
			return this.list.slice(1)
		},
		visibleList() {
			return this.expanded ? this.list : this.list.slice(0, 1)
		},
		restUnread() {
			return this.restItems.reduce((sum, item) => sum + this.unreadCount(item), 0)
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
		if (q.productContext) {
			const context = uni.getStorageSync('supportProductContext')
			if (context && context.type === 'product' && context.name) {
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
		goBack() {
			const pages = getCurrentPages()
			if (pages && pages.length > 1) uni.navigateBack({ delta: 1 })
			else uni.switchTab({ url: '/pages/mine/index' })
		},
		goLogin() { uni.switchTab({ url: '/pages/mine/index' }) },
		goAi() { uni.switchTab({ url: '/pages/ai/index' }) },
		onTopicChange(event) {
			this.topicIndex = Number(event.detail.value) || 0
		},
		onInputBlur() { this.inputFocus = false },
		productKindLabel(kind) {
			return ({ rent: '租赁', buy: '购买', accessory: '配件', skill: '技能' })[kind] || '商品'
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
			// 用户还没手动选过时，按当前在线情况给一个更实际的默认项：
			// 没有客服在线就先选「工单留言」，避免用户以为发出去马上有人回。
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
		categoryShort(category) {
			return (categoryLabel(category) || '其').slice(0, 1)
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
.brand { display: block; font-size: 48rpx; font-weight: 700; line-height: 1.2; letter-spacing: 2rpx; color: #ffffff; text-shadow: 0 4rpx 12rpx rgba(0, 0, 0, 0.2); }
.subtitle { display: inline-block; margin-top: 8rpx; padding: 4rpx 16rpx; border-radius: 30rpx; background: rgba(0, 0, 0, 0.2); font-size: 22rpx; color: rgba(255, 255, 255, 0.95); backdrop-filter: blur(4rpx); }
.nav-right { flex-shrink: 0; width: 64rpx; }

.scroll-content { flex: 1; height: 0; }
.page-content { padding: 18rpx 30rpx 0; }

.compose-card { margin-top: 0; padding: 28rpx; border-radius: 32rpx; box-shadow: 0 16rpx 40rpx rgba(22, 93, 255, 0.1); }
.compose-head { display: flex; align-items: center; justify-content: space-between; }
.product-context-card { display: flex; align-items: center; gap: 18rpx; margin-top: 18rpx; padding: 16rpx; border-radius: 22rpx; background: rgba(255, 255, 255, 0.78); border: 1rpx solid rgba(22, 93, 255, 0.16); box-shadow: 0 8rpx 24rpx rgba(22, 93, 255, 0.08); }
.product-context-image { width: 92rpx; height: 92rpx; flex-shrink: 0; overflow: hidden; border-radius: 16rpx; background: #eef4ff; display: flex; align-items: center; justify-content: center; color: #165dff; font-size: 28rpx; font-weight: 700; }
.product-context-image image { width: 100%; height: 100%; }
.product-context-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.product-context-kicker { color: #165dff; font-size: 18rpx; font-weight: 600; }
.product-context-name { overflow: hidden; color: #1a2a3a; font-size: 25rpx; font-weight: 600; line-height: 1.35; text-overflow: ellipsis; white-space: nowrap; }
.product-context-meta { color: #6b7a88; font-size: 19rpx; }
.product-context-remove { width: 44rpx; height: 44rpx; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: rgba(107, 122, 136, 0.1); color: #6b7a88; font-size: 30rpx; }
.compose-title { font-size: 30rpx; font-weight: 600; color: #1a2a3a; }
.topic-value { display: flex; align-items: center; gap: 8rpx; padding: 10rpx 18rpx; border-radius: 999rpx; background: rgba(255, 255, 255, 0.72); border: 1rpx solid rgba(255, 255, 255, 0.8); font-size: 22rpx; color: #1a2a3a; }
.topic-caret { font-size: 20rpx; color: #6b7a88; }
.channel-row { display: flex; gap: 14rpx; margin-top: 20rpx; }
.channel-option { flex: 1; min-width: 0; padding: 16rpx 18rpx; border: 1rpx solid rgba(255, 255, 255, 0.75); border-radius: 22rpx; background: rgba(255, 255, 255, 0.5); }
.channel-option.active { border-color: #165dff; background: rgba(22, 93, 255, 0.1); box-shadow: inset 0 0 0 1rpx rgba(22, 93, 255, 0.26); }
.channel-top { display: flex; align-items: center; gap: 8rpx; }
.channel-title { font-size: 25rpx; font-weight: 600; color: #1a2a3a; }
.channel-option.active .channel-title { color: #165dff; }
.channel-dot { flex-shrink: 0; width: 12rpx; height: 12rpx; border-radius: 50%; background: #b6c2cf; }
.channel-dot.on { background: #23b26e; box-shadow: 0 0 0 4rpx rgba(35, 178, 110, 0.14); }
.channel-desc { display: block; margin-top: 8rpx; font-size: 19rpx; line-height: 1.4; color: #5f7080; }
.compose-input { width: 100%; height: 168rpx; margin-top: 20rpx; padding: 22rpx; box-sizing: border-box; border: 1rpx solid rgba(255, 255, 255, 0.7); border-radius: 24rpx; background: rgba(255, 255, 255, 0.62); font-size: 27rpx; line-height: 1.65; color: #1a2a3a; }
.active-hint { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; margin-top: 16rpx; padding: 14rpx 20rpx; border: 1rpx solid rgba(22, 93, 255, 0.18); border-radius: 16rpx; background: rgba(22, 93, 255, 0.07); }
.active-hint-text { flex: 1; font-size: 20rpx; line-height: 1.5; color: #165dff; }
.active-hint-link { flex-shrink: 0; font-size: 20rpx; font-weight: 600; color: #165dff; }
.compose-placeholder { color: #7b8fa5; }
.compose-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 18rpx; }
.privacy-note { max-width: 480rpx; font-size: 19rpx; line-height: 1.45; color: #6b7a88; }
.send-btn { display: flex; align-items: center; justify-content: center; width: 72rpx; height: 72rpx; border-radius: 50%; background: linear-gradient(135deg, #4291f6, #6eb5ff); color: #ffffff; font-size: 30rpx; box-shadow: 0 6rpx 18rpx rgba(66, 145, 246, 0.3); }
.send-btn.disabled { opacity: .5; box-shadow: none; }

.section-head { display: flex; align-items: flex-end; justify-content: space-between; margin: 32rpx 4rpx 16rpx; }
.section-title { display: block; font-size: 30rpx; font-weight: 600; color: #13243b; text-shadow: 0 2rpx 8rpx rgba(255, 255, 255, 0.4); }
.section-sub { display: block; margin-top: 7rpx; font-size: 21rpx; color: #4f6275; }
.refresh-btn { padding: 9rpx 18rpx; border: 1rpx solid rgba(255, 255, 255, 0.75); border-radius: 999rpx; background: rgba(255, 255, 255, 0.55); font-size: 20rpx; color: #3c5573; }

.conversation { display: flex; align-items: center; gap: 20rpx; margin-bottom: 14rpx; padding: 24rpx 22rpx; border-radius: 26rpx; box-shadow: 0 10rpx 28rpx rgba(22, 93, 255, 0.07); }
.conv-mark { flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 74rpx; height: 74rpx; border-radius: 22rpx; background: rgba(22, 93, 255, 0.1); color: #165dff; font-size: 26rpx; font-weight: 600; }
.conv-main { min-width: 0; flex: 1; }
.conv-top { display: flex; align-items: center; justify-content: space-between; gap: 18rpx; }
.conv-title { font-size: 27rpx; font-weight: 600; color: #1a2a3a; }
.conv-time { flex-shrink: 0; font-size: 20rpx; color: #7b8fa5; }
.conv-preview { display: block; margin-top: 9rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 22rpx; color: #5f7080; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: 12rpx; }
.conv-status { padding: 4rpx 12rpx; border-radius: 999rpx; font-size: 18rpx; background: rgba(22, 93, 255, 0.1); color: #165dff; }
.status-resolved { background: rgba(35, 178, 110, 0.12); color: #2f8a68; }
.status-held { background: rgba(245, 166, 35, 0.14); color: #b45309; }
.status-closed { background: rgba(107, 122, 136, 0.12); color: #6b7a88; }
.unread-badge { min-width: 32rpx; height: 32rpx; padding: 0 8rpx; box-sizing: border-box; display: flex; align-items: center; justify-content: center; border-radius: 999rpx; background: #e5484d; color: #ffffff; font-size: 18rpx; font-weight: 600; }
.conv-arrow { flex-shrink: 0; font-size: 34rpx; font-weight: 300; color: #7b8fa5; }
.conv-toggle { display: flex; align-items: center; justify-content: center; gap: 10rpx; margin-bottom: 14rpx; padding: 18rpx 22rpx; border-radius: 999rpx; box-shadow: 0 10rpx 28rpx rgba(22, 93, 255, 0.07); }
.conv-toggle-text { font-size: 22rpx; color: #3c5573; }
.conv-toggle-caret { font-size: 24rpx; line-height: 1; color: #7b8fa5; transition: transform 0.2s ease; }
.conv-toggle-caret.open { transform: rotate(180deg); }

.ai-entry { display: flex; align-items: center; gap: 18rpx; margin-top: 28rpx; padding: 20rpx 24rpx; border-radius: 26rpx; box-shadow: 0 10rpx 28rpx rgba(22, 93, 255, 0.07); }
.ai-entry-mark { flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 64rpx; height: 64rpx; border-radius: 20rpx; background: linear-gradient(135deg, #165dff, #6eb5ff); color: #ffffff; font-size: 22rpx; font-weight: 600; box-shadow: 0 6rpx 16rpx rgba(22, 93, 255, 0.24); }
.ai-entry-main { min-width: 0; flex: 1; }
.ai-entry-title { display: block; font-size: 25rpx; font-weight: 600; color: #1a2a3a; }
.ai-entry-desc { display: block; margin-top: 5rpx; font-size: 19rpx; color: #5f7080; }
.ai-entry-arrow { flex-shrink: 0; font-size: 30rpx; color: #7b8fa5; }

.faq-card { overflow: hidden; border-radius: 26rpx; box-shadow: 0 10rpx 28rpx rgba(22, 93, 255, 0.07); }.faq-row { display: flex; align-items: center; justify-content: space-between; gap: 20rpx; padding: 24rpx 26rpx; border-bottom: 1rpx solid rgba(22, 93, 255, 0.08); }
.faq-row.last { border-bottom: none; }
.faq-text { flex: 1; font-size: 25rpx; color: #1a2a3a; }
.faq-arrow { flex-shrink: 0; font-size: 30rpx; color: #7b8fa5; }

.empty-panel { display: flex; flex-direction: column; align-items: center; padding: 54rpx 30rpx 44rpx; border-radius: 26rpx; }
.empty-title { font-size: 25rpx; font-weight: 600; color: #1a2a3a; }
.empty-desc { margin-top: 9rpx; text-align: center; font-size: 21rpx; line-height: 1.55; color: #5f7080; }

.service-foot { display: flex; flex-direction: column; align-items: center; gap: 6rpx; padding: 30rpx 0 6rpx; font-size: 19rpx; line-height: 1.5; color: #4f6275; }

.login-wrap { flex: 1; display: flex; align-items: center; justify-content: center; padding: 0 48rpx; }
.login-card { width: 100%; display: flex; flex-direction: column; align-items: center; padding: 56rpx 40rpx; border-radius: 36rpx; box-shadow: 0 16rpx 40rpx rgba(22, 93, 255, 0.12); }
.login-mark { display: flex; align-items: center; justify-content: center; width: 108rpx; height: 108rpx; border-radius: 50%; background: linear-gradient(135deg, #165dff, #4291f6); color: #ffffff; font-size: 40rpx; font-weight: 600; box-shadow: 0 10rpx 24rpx rgba(22, 93, 255, 0.24); }
.login-title { margin-top: 30rpx; font-size: 32rpx; font-weight: 600; color: #1a2a3a; }
.login-desc { margin-top: 14rpx; text-align: center; font-size: 23rpx; line-height: 1.65; color: #5f7080; }
.login-btn { margin-top: 34rpx; padding: 16rpx 54rpx; border-radius: 999rpx; background: linear-gradient(135deg, #4291f6, #6eb5ff); color: #ffffff; font-size: 25rpx; box-shadow: 0 8rpx 22rpx rgba(66, 145, 246, 0.3); }
.safe-space { height: calc(40rpx + env(safe-area-inset-bottom)); }
</style>
