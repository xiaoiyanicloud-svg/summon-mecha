<template>
	<view class="as-page">
		<!-- 导航栏 -->
		<nav-bar title="售后" />

		<view v-if="!loggedIn" class="empty-state">
			<text class="empty-icon">🔒</text>
			<text class="empty-text">请先在「我的」页面登录</text>
			<view class="primary-btn" @click="goBack">返回</view>
		</view>

		<scroll-view v-else class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- 说明:售后单走到哪一步由平台侧推进,这里只做**如实展示**,不伪造进度 -->
			<view class="hint-card">
				<text class="hint-title">售后进度</text>
				<text class="hint-body">未下发的技能可直接退;已安装的技能需人工审核。审核通过后进入退款流程,到账时间以支付渠道为准。</text>
			</view>

			<view class="incident-entry" role="button" tabindex="0" @click="goIncidentReport">
				<view class="incident-entry-icon">🚨</view>
				<view class="incident-entry-main">
					<text class="incident-entry-title">事故上报</text>
					<text class="incident-entry-desc">设备或技能发生安全事件时，从这里提交事故信息并跟踪处理。</text>
				</view>
				<text class="incident-entry-arrow">›</text>
			</view>

			<!-- 三态:加载中 / 故障(带重试) / 真·还没有售后单。故障不再伪装成「还没有」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!list.length"
				empty-icon="🧾"
				empty-text="还没有售后申请"
				empty-action
				empty-action-text="去我的技能申请"
				@empty-action="goSkills"
				@retry="refresh"
			>
				<view class="card as-item" v-for="a in list" :key="a.id">
					<view class="as-head">
						<text class="as-name">{{ skillName(a) }}</text>
						<text class="as-status" :class="a.status">{{ statusText(a.status) }}</text>
					</view>

					<view class="rows">
						<view class="row">
							<text class="row-label">类型</text>
							<text class="row-value">{{ typeText(a.type) }}</text>
						</view>
						<view class="row" v-if="a.reason_category">
							<text class="row-label">申请理由</text>
							<text class="row-value">{{ reasonText(a.reason_category) }}</text>
						</view>
						<view class="row" v-if="policyText(a.policy_basis)">
							<text class="row-label">政策依据</text>
							<text class="row-value">{{ policyText(a.policy_basis) }}</text>
						</view>
						<view class="row">
							<text class="row-label">申请时间</text>
							<text class="row-value">{{ a.created_at || '—' }}</text>
						</view>
					</view>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { skillApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import StateView from '@/components/StateView.vue'
import NavBar from '@/components/NavBar.vue'

// 与后端 AfterSale::STATUS_* 对齐。
// ⚠ approved 是「审核通过、退款处理中」——不是「钱已到账」,别写成「已退款」
//   (那要等网关回执把状态推成 refunded)。写错会让用户在没收到钱时以为到账了。
const STATUS_TEXT = {
	applied: '已受理',
	reviewing: '审核中',
	approved: '已通过 · 退款处理中',
	rejected: '已驳回',
	refunded: '已退款',
}

// 与后端 AfterSale::TYPE_* 对齐
const TYPE_TEXT = {
	refund: '退款',
	complaint: '投诉',
}

// 与后端 AfterSaleService 的判定口径对齐:未下发可退 / 已激活需人工审
const POLICY_TEXT = {
	not_installed: '未下发 · 可直接退',
	activated: '已安装 · 需人工审核',
}

export default {
	components: { StateView, NavBar },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			list: [],
			// entitlementId -> 技能名。售后接口只回 entitlement_id,
			// 名字要靠「我的技能」侧对齐 —— 这一层 join 放在前端做,不另开后端接口。
			skillNames: {},
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	methods: {
		goBack() { uni.navigateBack({ delta: 1 }) },
		goSkills() { uni.navigateTo({ url: '/pages/mine/skills' }) },
		goIncidentReport() { uni.navigateTo({ url: '/pages/mine/incidents' }) },

		// 找不到对应授权时**如实显示授权号**,不编一个技能名
		skillName(a) {
			return this.skillNames[a.entitlement_id] || ('授权 #' + (a.entitlement_id != null ? a.entitlement_id : '—'))
		},
		statusText(s) { return STATUS_TEXT[s] || s || '—' },
		typeText(t) { return TYPE_TEXT[t] || t || '—' },
		policyText(p) { return POLICY_TEXT[p] || '' },
		reasonText(r) {
			// 后端 reason_category 目前是前端传来的自由短串(skills 页固定 'other')
			return r === 'other' ? '其他' : r
		},

		async refresh() {
			this.loading = true
			this.loadError = ''
			// 售后单(主数据)失败 → 错误态(带重试);技能名只是展示层 join,
			// 拉不到不影响售后单本身,留 warn 不静默。
			const [as, sk] = await Promise.all([
				skillApi.afterSales().catch(e => {
					this.loadError = (e && e.message) || '加载失败'
					return null
				}),
				skillApi.mine().catch(e => { console.warn('[after-sales] 技能名映射加载失败', e); return null }),
			])
			this.list = (as && Array.isArray(as.data)) ? as.data : []

			const names = {}
			const skills = (sk && Array.isArray(sk.data)) ? sk.data : []
			skills.forEach((s) => { names[s.id] = s.name || ('技能 #' + s.id) })
			this.skillNames = names

			this.loading = false
		},
	},
}
</script>

<style scoped>
.as-page { min-height: 100vh; background: #F7F8FA; display: flex; flex-direction: column; }
.scroll-content { flex: 1; height: 0; padding: 20rpx 30rpx; box-sizing: border-box; }

.hint-card {
	background: #EEF4FF; border: 1rpx solid #D6E4FF; border-radius: 16rpx;
	padding: 22rpx 24rpx; margin-bottom: 20rpx;
}
.hint-title { display: block; font-size: 26rpx; font-weight: 600; color: #102F6E; }
.hint-body { display: block; margin-top: 8rpx; font-size: 24rpx; color: #5A6B87; line-height: 1.6; }

.incident-entry {
	display: flex; align-items: center; gap: 20rpx;
	background: #FFFFFF; border-radius: 20rpx; padding: 24rpx;
	margin-bottom: 20rpx; box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.incident-entry:active { opacity: 0.72; }
.incident-entry-icon {
	width: 72rpx; height: 72rpx; border-radius: 18rpx;
	display: flex; align-items: center; justify-content: center;
	background: #FFF1F0; font-size: 38rpx; flex-shrink: 0;
}
.incident-entry-main { flex: 1; min-width: 0; }
.incident-entry-title { display: block; font-size: 28rpx; font-weight: 600; color: #1A2A3A; }
.incident-entry-desc { display: block; margin-top: 6rpx; font-size: 23rpx; color: #8A9AA8; line-height: 1.5; }
.incident-entry-arrow { font-size: 38rpx; color: #C0C8D4; }

.card {
	background: #FFFFFF; border-radius: 20rpx; padding: 28rpx;
	margin-bottom: 20rpx; box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.as-head { display: flex; align-items: flex-start; justify-content: space-between; }
.as-name { flex: 1; min-width: 0; font-size: 32rpx; font-weight: 600; color: #1A2A3A; }
.as-status { font-size: 22rpx; padding: 6rpx 16rpx; border-radius: 20rpx; background: #EEF2F6; color: #6B7280; flex-shrink: 0; }
.as-status.applied { background: #EAF1FF; color: #165DFF; }
.as-status.reviewing { background: #FFF4E5; color: #B26A00; }
.as-status.approved { background: #E6F4EA; color: #1E8E3E; }
.as-status.refunded { background: #E6F4EA; color: #1E8E3E; }
.as-status.rejected { background: #FDECEC; color: #D93025; }

.rows { margin-top: 20rpx; }
.row { display: flex; justify-content: space-between; padding: 10rpx 0; }
.row-label { font-size: 26rpx; color: #8A9AA8; }
.row-value { font-size: 26rpx; color: #1A2A3A; }

.empty-state { display: flex; flex-direction: column; align-items: center; padding: 120rpx 40rpx; }
.empty-icon { font-size: 76rpx; margin-bottom: 20rpx; }
.empty-text { font-size: 28rpx; color: #8A9AA8; margin-bottom: 30rpx; }
.primary-btn {
	width: 300rpx; height: 84rpx; line-height: 84rpx; text-align: center;
	background: #165DFF; color: #FFFFFF; font-size: 30rpx; font-weight: 600; border-radius: 42rpx;
}
</style>
