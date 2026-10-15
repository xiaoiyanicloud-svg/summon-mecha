<template>
	<view class="ticket-page">
		<!-- 导航栏 -->
		<view class="nav-bar">
			<view class="nav-back" @click="goBack">
				<text class="back-icon">‹</text>
			</view>
			<text class="nav-title">我的工单</text>
			<view class="nav-placeholder"></view>
		</view>

		<view v-if="!loggedIn" class="empty-state">
			<text class="empty-icon">🔒</text>
			<text class="empty-text">请先在「我的」页面登录</text>
			<view class="primary-btn" @click="goBack">返回</view>
		</view>

		<scroll-view v-else class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- 开新工单 -->
			<view class="card new-ticket">
				<text class="nt-title">提交新工单</text>
				<textarea class="nt-input" v-model="content" placeholder="描述你的问题(如:技能装不上 / 下发失败 / 退款)" maxlength="2000" />
				<view class="nt-btn" @click="submit">{{ submitting ? '提交中…' : '提交' }}</view>
			</view>

			<!-- 三态:加载中 / 故障(带重试) / 真·暂无工单。故障不再伪装成「暂无工单」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!list.length"
				empty-icon="🛠️"
				empty-text="暂无工单"
				btn-color="#ff6b00"
				@retry="refresh"
			>
				<view v-for="t in list" :key="t.id" class="card ticket-item">
					<view class="ti-head">
						<text class="ti-no">{{ t.ticket_no }}</text>
						<text class="ti-status" :class="t.status">{{ statusText(t.status) }}</text>
					</view>
					<text class="ti-time">{{ t.created_at }}</text>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { ticketApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import StateView from '@/components/StateView.vue'

const STATUS_TEXT = { open: '待处理', pending: '待处理', processing: '处理中', resolved: '已解决', closed: '已关闭' }

export default {
	components: { StateView },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			submitting: false,
			content: '',
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
		statusText(s) { return STATUS_TEXT[s] || s },
		async refresh() {
			this.loading = true
			this.loadError = ''
			try {
				const res = await ticketApi.list()
				this.list = (res && Array.isArray(res.data)) ? res.data : []
			} catch (e) {
				// ⚠ 故障 ≠「暂无工单」:置错误标记交给 StateView 的错误态(带重试),
				//   而不是把空数组塞进列表 —— 那会让 500/断网 看起来像「你没有工单」。
				this.loadError = (e && e.message) || '加载失败'
				this.list = []
			} finally {
				this.loading = false
			}
		},
		async submit() {
			const text = (this.content || '').trim()
			if (!text) { uni.showToast({ title: '请描述问题', icon: 'none' }); return }
			this.submitting = true
			try {
				await ticketApi.create({ content: text, category: 'other' })
				uni.showToast({ title: '已提交', icon: 'success' })
				this.content = ''
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.ticket-page { min-height: 100vh; background: #f5f6f8; }
.nav-bar { display: flex; align-items: center; justify-content: space-between; height: 88rpx; padding: 0 24rpx; background: #fff; }
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #333; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-placeholder { width: 60rpx; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }
.nt-title { font-size: 28rpx; font-weight: 600; color: #222; }
.nt-input { margin-top: 16rpx; width: 100%; min-height: 140rpx; padding: 16rpx; background: #f7f8fa; border-radius: 12rpx; font-size: 26rpx; box-sizing: border-box; }
.nt-btn { margin-top: 16rpx; text-align: center; padding: 18rpx 0; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
.ti-head { display: flex; align-items: center; justify-content: space-between; }
.ti-no { font-size: 28rpx; color: #222; }
.ti-status { font-size: 24rpx; color: #ff6b00; }
.ti-time { display: block; margin-top: 10rpx; font-size: 22rpx; color: #aaa; }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: 120rpx; }
.empty-icon { font-size: 80rpx; }
.empty-text { margin-top: 24rpx; font-size: 28rpx; color: #999; }
.primary-btn { margin-top: 40rpx; padding: 18rpx 60rpx; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
</style>
