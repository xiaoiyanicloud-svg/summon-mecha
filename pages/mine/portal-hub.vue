<template>
	<view class="hub-page">
		<nav-bar title="业务工作台" />

		<state-view
			v-if="!isLoggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可查看名下商家店铺与技能开发者工作台实时数据，并支持一键免登进入桌面后台"
			action-text="返回登录"
			@action="goMine"
		/>

		<state-view v-else-if="loading" preset="loading" loading-text="正在同步多租户工作台状态..." />
		<state-view v-else-if="errorMsg" preset="network" :title="errorMsg" @retry="loadData" />

		<scroll-view v-else class="page-scroll" scroll-y>
			<!-- 商家工作台卡片 -->
			<view class="workbench-card">
				<view class="wb-header">
					<view class="wb-title-left">
						<view class="wb-icon"><SmIcon name="hub-store" :size="44" /></view>
						<view>
							<text class="wb-name">商家工作台</text>
							<text class="wb-sub">机器人整机租售 · 配件库存 · 履约与结算</text>
						</view>
					</view>
					<text class="wb-badge" :class="merchantSummary.has_permission ? 'ok' : 'locked'">
						{{ merchantSummary.active_shop ? '已开店' : (merchantSummary.has_permission ? '待创建店铺' : '未开通') }}
					</text>
				</view>

				<template v-if="merchantSummary.active_shop">
					<view class="shop-strip">
						<text class="shop-name">{{ merchantSummary.active_shop.name }}</text>
						<text class="shop-role">角色：{{ merchantSummary.role || 'owner' }} · 共 {{ merchantSummary.shop_count || 1 }} 家店铺</text>
					</view>
					<view class="kpi-grid">
						<view class="kpi-box">
							<text class="kpi-val sm-mono">{{ (merchantSummary.kpis && merchantSummary.kpis.on_shelf_products) || 0 }}</text>
							<text class="kpi-lbl">在架商品</text>
						</view>
						<view class="kpi-box">
							<text class="kpi-val sm-mono warn">{{ (merchantSummary.kpis && merchantSummary.kpis.pending_ship_orders) || 0 }}</text>
							<text class="kpi-lbl">待发货履约</text>
						</view>
						<view class="kpi-box">
							<text class="kpi-val sm-mono">{{ (merchantSummary.kpis && merchantSummary.kpis.active_rentals) || 0 }}</text>
							<text class="kpi-lbl">在租设备单</text>
						</view>
						<view class="kpi-box">
							<text class="kpi-val sm-mono danger">{{ (merchantSummary.kpis && merchantSummary.kpis.open_after_sales) || 0 }}</text>
							<text class="kpi-lbl">待处理售后</text>
						</view>
					</view>
				</template>

				<view v-else class="empty-hint-box">
					<text class="empty-hint-text">
						{{ merchantSummary.has_permission
							? '您的账号已具备供应链准入资格，前往商家工作台即可初始化首家店铺。'
							: (merchantSummary.apply_hint || '需通过供应链准入或本体厂商合作审核后开通多租户商家权限。') }}
					</text>
				</view>

				<view class="wb-actions">
					<template v-if="merchantSummary.has_permission">
						<view class="btn-primary" @tap="enterPortal('merchant_admin')">一键免登进入商家后台</view>
					</template>
					<template v-else>
						<view class="btn-outline" @tap="goPage('/pages/mine/supplier')">前往供应链准入申报</view>
					</template>
				</view>
			</view>

			<!-- 开发者技能工作台卡片 -->
			<view class="workbench-card">
				<view class="wb-header">
					<view class="wb-title-left">
						<view class="wb-icon"><SmIcon name="hub-terminal" :size="44" /></view>
						<view>
							<text class="wb-name">技能开发者工作台</text>
							<text class="wb-sub">技能包上传安检 · 版本提审 · 买断销量统计</text>
						</view>
					</view>
					<text class="wb-badge ok">
						{{ devSummary.onboarded ? '已入驻' : '同账号可入驻' }}
					</text>
				</view>

				<template v-if="devSummary.onboarded">
					<view class="shop-strip">
						<text class="shop-name">{{ devSummary.display_name || '开发者主体' }}</text>
						<text class="shop-role">
							协议状态：{{ devSummary.agreements_signed ? '已签署全部协议' : '待补签协议' }}
						</text>
					</view>
					<view class="kpi-grid two-col">
						<view class="kpi-box">
							<text class="kpi-val sm-mono">{{ devSummary.skill_count !== null && devSummary.skill_count !== undefined ? devSummary.skill_count : '—' }}</text>
							<text class="kpi-lbl">名下技能商品</text>
						</view>
						<view class="kpi-box">
							<text class="kpi-val">{{ devSummary.status === 'active' ? '正常' : (devSummary.status || '待完善') }}</text>
							<text class="kpi-lbl">开发者档案状态</text>
						</view>
					</view>
				</template>

				<view v-else class="empty-hint-box">
					<text class="empty-hint-text">使用当前账户一键免登进入开发者门户后，完成主体登记与协议签署即可提交具身技能包。</text>
				</view>

				<view class="wb-actions">
					<view class="btn-primary" @tap="enterPortal('developer_skill')">
						{{ devSummary.onboarded ? '一键免登进入开发者后台' : '免登前往开发者入驻' }}
					</view>
				</view>
			</view>

			<!-- 扩展工作台列表 -->
			<view v-for="ext in extraBackends" :key="ext.key" class="workbench-card compact">
				<view class="wb-header">
					<view class="wb-title-left">
						<view class="wb-icon"><SmIcon name="hub-cube" :size="40" /></view>
						<view>
							<text class="wb-name">{{ ext.name }}</text>
							<text class="wb-sub">{{ ext.description }}</text>
						</view>
					</view>
					<text class="wb-badge locked">{{ ext.badge || '规划中' }}</text>
				</view>
				<text class="ext-tip">{{ ext.apply_hint || '该业务后台面向签约合作方定向开放。' }}</text>
			</view>

			<view class="pc-tip">
				<SmIcon name="info" :size="26" />
				<text class="pc-tip-text">提示：商品多规格矩阵配置、ZIP 技能包上传与财务结算单导出建议使用电脑端大屏操作。</text>
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { portalApi } from '@/utils/api'
import { TOKEN_KEY, BASE_URL } from '@/utils/config'

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			isLoggedIn: false,
			loading: false,
			errorMsg: '',
			summary: {},
			backends: [],
		}
	},
	computed: {
		merchantSummary() {
			return (this.summary && this.summary.merchant) || {}
		},
		devSummary() {
			return (this.summary && this.summary.developer) || {}
		},
		extraBackends() {
			return (this.backends || []).filter(b => b.key !== 'merchant_admin' && b.key !== 'developer_skill')
		},
	},
	onShow() {
		this.isLoggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.isLoggedIn) this.loadData()
	},
	methods: {
		goMine() {
			uni.switchTab({ url: '/pages/mine/index' })
		},
		goPage(url) {
			uni.navigateTo({ url })
		},
		loadData() {
			this.loading = true
			this.errorMsg = ''
			Promise.all([
				portalApi.summary(),
				portalApi.backends().catch(() => ({ data: { backends: [] } })),
			])
				.then(([sumRes, backRes]) => {
					this.summary = (sumRes && sumRes.data) || {}
					this.backends = (backRes && backRes.data && backRes.data.backends) || []
				})
				.catch((err) => {
					this.errorMsg = (err && err.message) || '加载工作台概览失败'
				})
				.finally(() => {
					this.loading = false
				})
		},
		enterPortal(key) {
			portalApi.track({ key, action: 'enter' }).catch(() => {})
			portalApi.handoffTicket(key)
				.then((res) => {
					const ssoUrl = res && res.data && res.data.sso_url
					const fallback = key === 'merchant_admin' ? `${BASE_URL}/merchant` : `${BASE_URL}/dev`
					const target = ssoUrl || fallback
					// #ifdef H5
					window.location.href = target
					// #endif
					// #ifndef H5
					uni.setClipboardData({
						data: fallback,
						success: () => uni.showToast({ title: '工作台地址已复制，请在电脑浏览器打开', icon: 'none' }),
					})
					// #endif
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '签发免登票据失败', icon: 'none' })
				})
		},
	},
}
</script>

<style scoped>
.hub-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.page-scroll { flex: 1; padding: 24rpx 28rpx; box-sizing: border-box; }
.workbench-card { background: #FFFFFF; border-radius: 24rpx; padding: 30rpx; margin-bottom: 22rpx; border: 1rpx solid #E5EAF2; }
.workbench-card.compact { padding: 26rpx 30rpx; }
.wb-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16rpx; margin-bottom: 20rpx; }
.wb-title-left { display: flex; align-items: center; gap: 20rpx; flex: 1; }
.wb-icon { width: 84rpx; height: 84rpx; border-radius: 20rpx; background: #EEF4FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wb-name { display: block; font-size: 31rpx; font-weight: 600; color: #0F172A; }
.wb-sub { display: block; font-size: 23rpx; color: #64748B; margin-top: 4rpx; }
.wb-badge { font-size: 22rpx; font-weight: 600; padding: 6rpx 16rpx; border-radius: 999rpx; flex-shrink: 0; }
.wb-badge.ok { background: #ECFDF5; color: #059669; }
.wb-badge.locked { background: #F1F5F9; color: #64748B; }
.shop-strip { padding: 18rpx 22rpx; background: #F8FAFC; border-radius: 14rpx; margin-bottom: 20rpx; display: flex; flex-direction: column; gap: 4rpx; }
.shop-name { font-size: 28rpx; font-weight: 600; color: #0F172A; }
.shop-role { font-size: 23rpx; color: #64748B; }
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14rpx; margin-bottom: 24rpx; }
.kpi-grid.two-col { grid-template-columns: repeat(2, 1fr); }
.kpi-box { background: #F8FAFC; border: 1rpx solid #EDF2F7; border-radius: 16rpx; padding: 20rpx 12rpx; display: flex; flex-direction: column; align-items: center; gap: 6rpx; }
.kpi-val { font-size: 34rpx; font-weight: 700; color: #0F172A; }
.kpi-val.warn { color: #D97706; }
.kpi-val.danger { color: #DC2626; }
.kpi-lbl { font-size: 22rpx; color: #64748B; }
.empty-hint-box { padding: 20rpx 22rpx; background: #F8FAFC; border-radius: 14rpx; margin-bottom: 22rpx; }
.empty-hint-text { font-size: 25rpx; color: #475569; line-height: 1.55; }
.wb-actions { display: flex; gap: 16rpx; }
.btn-primary { flex: 1; height: 84rpx; border-radius: 16rpx; background: #165DFF; color: #FFFFFF; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-outline { flex: 1; height: 84rpx; border-radius: 16rpx; background: #EEF4FF; border: 1rpx solid #C7DCFF; color: #165DFF; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.ext-tip { display: block; font-size: 24rpx; color: #64748B; line-height: 1.5; }
.pc-tip { display: flex; align-items: flex-start; gap: 12rpx; padding: 20rpx 24rpx; background: #EEF4FF; border-radius: 16rpx; }
.pc-tip-text { flex: 1; font-size: 23rpx; color: #1E40AF; line-height: 1.5; }
</style>
