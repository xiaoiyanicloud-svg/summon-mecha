<template>
	<view class="detail-page">
		<!-- 导航栏 -->
		<view class="nav-bar">
			<view class="nav-back" @click="goBack">
				<text class="back-icon">‹</text>
			</view>
			<text class="nav-title">事故详情</text>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- 三态:加载中 / 故障(带重试) / 取不到这条记录。故障不伪装成「记录不存在」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!incident"
				empty-icon="📋"
				empty-text="事故记录不存在"
				btn-color="#ff6b00"
				@retry="refresh"
			>
				<view class="card head-card">
					<view class="head-row">
						<text class="i-no">{{ incident.incident_no }}</text>
						<text class="i-sev" :class="incident.severity">{{ incident.severity_label }}</text>
					</view>
					<view class="head-foot">
						<text class="i-status">{{ incident.status_label }}</text>
						<text class="i-time">{{ incident.occurred_at || incident.created_at }}</text>
					</view>
				</view>

				<view class="card">
					<text class="sec-title">情况描述</text>
					<text class="body-text">{{ incident.description }}</text>
				</view>

				<!-- 证据:报案人自己提交的文字/链接,原样回显 -->
				<view class="card" v-if="evidence.length">
					<text class="sec-title">证据说明</text>
					<text v-for="(e, i) in evidence" :key="i" class="body-text evidence">{{ e }}</text>
				</view>

				<!-- 责任认定:未认定时后端给 null —— 如实显示「尚未认定」,不编一个假答案 -->
				<view class="card">
					<text class="sec-title">责任认定</text>
					<text v-if="incident.responsibility" class="resp-text">{{ incident.responsibility }}</text>
					<text v-else class="resp-text pending">尚未认定</text>
					<text v-if="!incident.responsibility" class="resp-hint">认定结论由平台在调查后给出,做出后这里会自动显示。</text>
					<text v-if="incident.resolved_at" class="resp-hint">结案时间：{{ incident.resolved_at }}</text>
				</view>

				<view style="height: 60rpx;"></view>
			</state-view>
		</scroll-view>
	</view>
</template>

<script>
import { incidentApi } from '@/utils/api'
import StateView from '@/components/StateView.vue'

/**
 * 事故详情 —— 消费既有接口 GET /api/incidents/{id}（shop.api.incidents.show）。
 *
 * ⚠ 此前列表项**没有任何点击入口**,接口虽已存在却无人调用 —— 报案人永远看不到
 *   责任认定（responsibility）,事故闭环缺最后一公里（A8）。
 *
 * ⚠ 责任认定在未认定时后端返回 null（不是「尚未认定」字符串）——
 *   这里如实显示「尚未认定」并说明由平台在调查后给出,**不编一个假结论**。
 */
export default {
	components: { StateView },
	data() {
		return {
			id: null,
			loading: true,
			loadError: '',
			incident: null,
		}
	},
	computed: {
		evidence() {
			const e = this.incident && this.incident.evidence
			return Array.isArray(e) ? e : []
		},
	},
	onLoad(options) {
		this.id = options && options.id ? Number(options.id) : null
		if (this.id) this.refresh()
		else this.loading = false // 没带 id = 链接坏了,走「记录不存在」空态
	},
	methods: {
		goBack() { uni.navigateBack({ delta: 1 }) },
		async refresh() {
			this.loading = true
			this.loadError = ''
			try {
				const res = await incidentApi.detail(this.id)
				this.incident = (res && res.data) || null
			} catch (e) {
				// ⚠ 故障 ≠「记录不存在」:置错误标记交给错误态(带重试),而不是落进空态
				this.loadError = (e && e.message) || '加载失败'
				this.incident = null
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.detail-page { min-height: 100vh; background: #f5f6f8; }
.nav-bar { display: flex; align-items: center; justify-content: space-between; height: 88rpx; padding: 0 24rpx; background: #fff; }
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #333; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-placeholder { width: 60rpx; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }

.head-card { margin-top: 24rpx; }
.head-row { display: flex; align-items: center; justify-content: space-between; }
.i-no { font-size: 30rpx; font-weight: 600; color: #222; }
.i-sev { font-size: 24rpx; color: #888; }
.i-sev.severe, .i-sev.critical { color: #cf1322; font-weight: 600; }
.head-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 14rpx; }
.i-status { font-size: 24rpx; color: #ff6b00; }
.i-time { font-size: 22rpx; color: #aaa; }

.sec-title { display: block; font-size: 28rpx; font-weight: 600; color: #333; margin-bottom: 14rpx; }
.body-text { display: block; font-size: 27rpx; color: #333; line-height: 1.7; white-space: pre-wrap; }
.body-text.evidence { color: #165DFF; word-break: break-all; margin-bottom: 6rpx; }
.resp-text { display: block; font-size: 28rpx; color: #222; font-weight: 600; }
.resp-text.pending { color: #999; font-weight: 400; }
.resp-hint { display: block; margin-top: 10rpx; font-size: 23rpx; color: #aaa; line-height: 1.6; }

.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: 120rpx; }
.empty-text { margin-top: 20rpx; font-size: 27rpx; color: #999; }
</style>
