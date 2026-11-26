<template>
	<view class="detail-page">
		<nav-bar title="物理事故报案详情" />

		<scroll-view class="scroll-content" scroll-y>
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!incident"
				icon="clipboard"
				title="未找到该条事故记录"
				@retry="refresh"
			>
				<view class="card">
					<view class="head-row">
						<text class="i-no sm-mono">{{ incident.incident_no }}</text>
						<text class="i-sev" :class="incident.severity">{{ incident.severity_label }}</text>
					</view>
					<view class="kv-list">
						<view class="kv-row"><text class="kk">当前处置阶段</text><text class="kv highlight">{{ incident.status_label }}</text></view>
						<view v-if="incident.device_sn" class="kv-row"><text class="kk">涉及机器设备</text><text class="kv sm-mono">{{ incident.device_sn }}</text></view>
						<view v-if="incident.skill_name" class="kv-row">
							<text class="kk">涉及技能版本</text>
							<text class="kv">{{ incident.skill_name }}{{ incident.skill_version ? ` (v${incident.skill_version})` : '' }}</text>
						</view>
						<view v-if="incident.occurred_at" class="kv-row"><text class="kk">填报事发时间</text><text class="kv sm-mono">{{ incident.occurred_at }}</text></view>
						<view v-if="incident.created_at" class="kv-row"><text class="kk">存证冻结时间</text><text class="kv sm-mono">{{ incident.created_at }}</text></view>
					</view>
				</view>

				<view class="card">
					<text class="sec-title">事发情况描述</text>
					<text class="body-text">{{ incident.description }}</text>
				</view>

				<view v-if="evidence.length" class="card">
					<text class="sec-title">报案人提交的证据索引</text>
					<text v-for="(e, i) in evidence" :key="i" class="body-text evidence">{{ e }}</text>
				</view>

				<view class="card">
					<text class="sec-title">调查结论与责任认定</text>
					<text v-if="incident.responsibility" class="resp-text">{{ incident.responsibility }}</text>
					<text v-else class="resp-text pending">调查进行中 · 尚未出具最终认定</text>
					<text v-if="!incident.responsibility" class="resp-hint">平台安全专员完成日志与包签名核验后，认定结论将自动同步至此。</text>
					<text v-if="incident.resolved_at" class="resp-hint sm-mono">结案时间：{{ incident.resolved_at }}</text>

					<view v-if="incident.ticket_id" class="ticket-jump" @tap="goTicket(incident.ticket_id)">
						<text class="tj-text">前往关联客服工单补充沟通 ›</text>
					</view>
				</view>

				<view style="height: 60rpx;"></view>
			</state-view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { incidentApi } from '@/utils/api'

export default {
	components: { NavBar, StateView },
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
		else this.loading = false
	},
	methods: {
		goTicket(ticketId) {
			uni.navigateTo({ url: `/pages/mine/ticket-detail?id=${ticketId}` })
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			try {
				const res = await incidentApi.detail(this.id)
				this.incident = (res && res.data) || null
			} catch (e) {
				this.loadError = (e && e.message) || '加载事故详情失败'
				this.incident = null
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.detail-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.head-row { display: flex; justify-content: space-between; align-items: center; padding-bottom: 18rpx; border-bottom: 1rpx solid #F1F5F9; margin-bottom: 12rpx; }
.i-no { font-size: 31rpx; font-weight: 700; color: #0F172A; }
.i-sev { font-size: 23rpx; font-weight: 600; padding: 4rpx 14rpx; border-radius: 8rpx; background: #F1F5F9; color: #475569; }
.i-sev.severe, .i-sev.critical { background: #FEF2F2; color: #DC2626; }
.kv-row { display: flex; justify-content: space-between; gap: 20rpx; padding: 10rpx 0; font-size: 25rpx; }
.kk { color: #64748B; }
.kv { color: #0F172A; font-weight: 500; text-align: right; }
.kv.highlight { color: #165DFF; font-weight: 600; }
.sec-title { display: block; font-size: 28rpx; font-weight: 600; color: #0F172A; margin-bottom: 14rpx; }
.body-text { display: block; font-size: 27rpx; color: #334155; line-height: 1.65; white-space: pre-wrap; }
.body-text.evidence { color: #165DFF; word-break: break-all; margin-bottom: 8rpx; }
.resp-text { display: block; font-size: 28rpx; font-weight: 600; color: #0F172A; }
.resp-text.pending { color: #64748B; font-weight: 500; }
.resp-hint { display: block; margin-top: 10rpx; font-size: 23rpx; color: #64748B; line-height: 1.55; }
.ticket-jump { margin-top: 22rpx; padding: 18rpx 22rpx; border-radius: 14rpx; background: #EEF4FF; display: flex; justify-content: center; }
.tj-text { font-size: 26rpx; font-weight: 600; color: #165DFF; }
</style>
