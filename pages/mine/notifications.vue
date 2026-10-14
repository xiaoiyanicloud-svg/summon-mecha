<template>
	<view class="notify-page">
		<!-- 导航栏 -->
		<view class="nav-bar">
			<view class="nav-back" @click="goBack">
				<text class="back-icon">‹</text>
			</view>
			<text class="nav-title">消息通知</text>
			<view class="nav-placeholder"></view>
		</view>

		<view v-if="!loggedIn" class="empty-state">
			<text class="empty-icon">🔒</text>
			<text class="empty-text">请先在「我的」页面登录</text>
			<view class="primary-btn" @click="goBack">返回</view>
		</view>

		<scroll-view v-else class="scroll-content" scroll-y="true" show-scrollbar="false">
			<view v-if="loading" class="empty-state"><text class="empty-text">加载中…</text></view>

			<view v-else-if="!list.length" class="empty-state">
				<text class="empty-icon">🔔</text>
				<text class="empty-text">暂无消息</text>
			</view>

			<view v-else>
				<view
					v-for="n in list"
					:key="n.id"
					class="card notify-card"
					:class="{ unread: !n.read }"
					@click="open(n)"
				>
					<view class="notify-head">
						<text class="notify-title">{{ n.title }}</text>
						<text class="notify-dot" v-if="!n.read"></text>
					</view>
					<text class="notify-content" v-if="n.content">{{ n.content }}</text>
					<text class="notify-time">{{ n.created_at }}</text>
				</view>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { notificationApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

export default {
	data() {
		return {
			loggedIn: false,
			loading: true,
			list: [],
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	methods: {
		goBack() { uni.navigateBack({ delta: 1 }) },
		async refresh() {
			this.loading = true
			try {
				const res = await notificationApi.list().catch(() => ({ data: [] }))
				this.list = (res && Array.isArray(res.data)) ? res.data : []
			} finally {
				this.loading = false
			}
		},
		// 点开即标记已读(乐观更新本地,失败不打断)
		async open(n) {
			if (n.read) return
			n.read = true
			try {
				await notificationApi.markRead(n.id)
			} catch (e) {
				// 标记已读失败不回滚 —— 下次刷新会纠正
			}
		},
	},
}
</script>

<style scoped>
.notify-page { min-height: 100vh; background: #f5f6f8; }
.nav-bar { display: flex; align-items: center; justify-content: space-between; height: 88rpx; padding: 0 24rpx; background: #fff; }
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #333; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-placeholder { width: 60rpx; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }
.notify-card.unread { border-left: 6rpx solid #ff6b00; }
.notify-head { display: flex; align-items: center; justify-content: space-between; }
.notify-title { font-size: 30rpx; font-weight: 600; color: #222; }
.notify-dot { width: 14rpx; height: 14rpx; border-radius: 50%; background: #ff6b00; }
.notify-content { display: block; margin-top: 12rpx; font-size: 26rpx; color: #666; line-height: 1.5; }
.notify-time { display: block; margin-top: 16rpx; font-size: 22rpx; color: #aaa; }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: 200rpx; }
.empty-icon { font-size: 80rpx; }
.empty-text { margin-top: 24rpx; font-size: 28rpx; color: #999; }
.primary-btn { margin-top: 40rpx; padding: 18rpx 60rpx; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
</style>
