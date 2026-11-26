<template>
	<view class="notify-page">
		<nav-bar title="消息通知中心">
			<template #right>
				<text v-if="loggedIn && unreadCount > 0" class="nav-action" @tap="readAll">全部已读</text>
			</template>
		</nav-bar>

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可接收订单履约、设备下发、合作审核与赛事动态通知"
			action-text="返回登录"
			@action="goMine"
		/>

		<template v-else>
			<scroll-view class="cat-scroll" scroll-x show-scrollbar="false">
				<view class="cat-row">
					<text
						v-for="c in categories"
						:key="c.key"
						class="cat-pill"
						:class="{ active: activeCategory === c.key }"
						@tap="switchCategory(c.key)"
					>{{ c.label }}</text>
				</view>
			</scroll-view>

			<scroll-view class="scroll-content" scroll-y @scrolltolower="loadMore">
				<state-view
					:loading="loading"
					:error="loadError"
					:empty="!list.length"
					icon="notify"
					title="暂无相关站内通知"
					hint="当订单发货、技能下发完成或合作申请更新时，系统将第一时间推送至此"
					@retry="refresh"
				>
					<view
						v-for="n in list"
						:key="n.id"
						class="card notify-card"
						:class="{ unread: !n.read }"
						@tap="openItem(n)"
					>
						<view class="notify-head">
							<view class="head-left">
								<text v-if="!n.read" class="unread-dot" />
								<text class="notify-title">{{ n.title }}</text>
							</view>
							<text class="notify-time sm-mono">{{ n.created_at }}</text>
						</view>
						<text v-if="n.content" class="notify-content">{{ n.content }}</text>
						<view v-if="n.target_url" class="jump-foot">
							<text class="jump-link">前往处理详情 ›</text>
						</view>
					</view>

					<view v-if="hasMore" class="more-btn" @tap="loadMore">
						{{ loadingMore ? '加载中...' : '加载更早消息' }}
					</view>
				</state-view>

				<view style="height: 60rpx;"></view>
			</scroll-view>
		</template>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { notificationApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

const TAB_PAGES = ['/pages/home/index', '/pages/robot/index', '/pages/ai/index', '/pages/order/index', '/pages/mine/index']

export default {
	components: { NavBar, StateView },
	data() {
		return {
			loggedIn: false,
			activeCategory: 'all',
			categories: [
				{ key: 'all', label: '全部消息' },
				{ key: 'order', label: '交易与售后' },
				{ key: 'deploy', label: '设备与技能' },
				{ key: 'cooperation', label: '合作与赛事' },
				{ key: 'system', label: '系统与客服' },
			],
			loading: true,
			loadingMore: false,
			loadError: '',
			list: [],
			unreadCount: 0,
			hasMore: false,
			nextCursor: null,
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	onPullDownRefresh() {
		this.refresh().finally(() => uni.stopPullDownRefresh())
	},
	methods: {
		goMine() { uni.switchTab({ url: '/pages/mine/index' }) },
		switchCategory(cat) {
			if (this.activeCategory === cat) return
			this.activeCategory = cat
			this.refresh()
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			try {
				const res = await notificationApi.list({ category: this.activeCategory, limit: 30 })
				this.list = (res && Array.isArray(res.data)) ? res.data : []
				const meta = (res && res.meta) || {}
				this.unreadCount = Number(meta.unread_count ?? this.list.filter(x => !x.read).length)
				this.hasMore = !!meta.has_more
				this.nextCursor = meta.next_cursor || null
			} catch (e) {
				this.loadError = (e && e.message) || '加载通知失败'
				this.list = []
			} finally {
				this.loading = false
			}
		},
		async loadMore() {
			if (!this.hasMore || !this.nextCursor || this.loadingMore) return
			this.loadingMore = true
			try {
				const res = await notificationApi.list({
					category: this.activeCategory,
					before_id: this.nextCursor,
					limit: 30,
				})
				const rows = (res && Array.isArray(res.data)) ? res.data : []
				this.list = [...this.list, ...rows]
				const meta = (res && res.meta) || {}
				this.hasMore = !!meta.has_more
				this.nextCursor = meta.next_cursor || null
			} catch (_) {} finally {
				this.loadingMore = false
			}
		},
		async readAll() {
			try {
				await notificationApi.markAllRead()
				this.list = this.list.map(x => ({ ...x, read: true }))
				this.unreadCount = 0
				uni.showToast({ title: '已全部标为已读', icon: 'success' })
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
			}
		},
		async openItem(n) {
			if (!n.read) {
				n.read = true
				if (this.unreadCount > 0) this.unreadCount -= 1
				notificationApi.markRead(n.id).catch(() => {})
			}
			if (n.target_url) {
				const base = n.target_url.split('?')[0]
				if (TAB_PAGES.includes(base)) {
					uni.switchTab({ url: base })
				} else {
					uni.navigateTo({ url: n.target_url })
				}
			}
		},
	},
}
</script>

<style scoped>
.notify-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.nav-action { font-size: 26rpx; font-weight: 600; color: #165DFF; }
.cat-scroll { background: #FFFFFF; border-bottom: 1rpx solid #EEF2F7; white-space: nowrap; }
.cat-row { display: inline-flex; gap: 16rpx; padding: 18rpx 28rpx; }
.cat-pill { font-size: 25rpx; font-weight: 500; color: #475569; background: #F1F5F9; padding: 10rpx 24rpx; border-radius: 999rpx; }
.cat-pill.active { background: #EEF4FF; color: #165DFF; font-weight: 600; }
.scroll-content { flex: 1; padding: 20rpx 28rpx; box-sizing: border-box; }
.card { background: #FFFFFF; border-radius: 22rpx; padding: 26rpx 28rpx; margin-bottom: 18rpx; border: 1rpx solid #E5EAF2; }
.notify-card.unread { border-left: 6rpx solid #165DFF; }
.notify-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16rpx; }
.head-left { display: flex; align-items: center; gap: 12rpx; flex: 1; min-width: 0; }
.unread-dot { width: 14rpx; height: 14rpx; border-radius: 50%; background: #165DFF; flex-shrink: 0; }
.notify-title { font-size: 29rpx; font-weight: 600; color: #0F172A; }
.notify-time { font-size: 22rpx; color: #94A3B8; flex-shrink: 0; }
.notify-content { display: block; margin-top: 12rpx; font-size: 25rpx; color: #475569; line-height: 1.55; }
.jump-foot { margin-top: 16rpx; padding-top: 14rpx; border-top: 1rpx solid #F1F5F9; display: flex; justify-content: flex-end; }
.jump-link { font-size: 24rpx; font-weight: 600; color: #165DFF; }
.more-btn { text-align: center; padding: 24rpx; font-size: 25rpx; color: #165DFF; font-weight: 500; }
</style>
