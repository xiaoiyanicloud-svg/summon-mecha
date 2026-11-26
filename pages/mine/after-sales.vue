<template>
	<view class="as-page">
		<nav-bar title="售后与维保中心">
			<template #right>
				<text v-if="loggedIn" class="nav-action" @tap="openOrderApply">订单售后</text>
			</template>
		</nav-bar>

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可查看技能退款、整机报修与配件退换进度"
			action-text="返回登录"
			@action="goMine"
		/>

		<template v-else>
			<view class="domain-tabs">
				<view class="d-tab" :class="{ active: domainTab === 'all' }" @tap="domainTab = 'all'">全部记录</view>
				<view class="d-tab" :class="{ active: domainTab === 'skill' }" @tap="domainTab = 'skill'">买断技能售后</view>
				<view class="d-tab" :class="{ active: domainTab === 'order' }" @tap="domainTab = 'order'">租售与配件维保</view>
			</view>

			<scroll-view class="scroll-content" scroll-y>
				<view class="incident-entry" @tap="goIncidentReport">
					<view class="ie-icon"><SmIcon name="shield-lock" :size="40" /></view>
					<view class="ie-main">
						<text class="ie-title">物理安全事故专门报案通道</text>
						<text class="ie-desc">若机器执行中发生碰撞或现场损坏，请前往事故上报固定证据链</text>
					</view>
					<SmIcon name="chevron-right" :size="24" />
				</view>

				<state-view
					:loading="loading"
					:error="loadError"
					:empty="!filteredList.length"
					icon="after-sales"
					title="暂无售后或维保记录"
					hint="未下发技能支持极速退；租售整机与配件可点击右上角「订单售后」发起报修或退换"
					action-text="发起订单售后"
					@action="openOrderApply"
					@retry="refresh"
				>
					<view v-for="a in filteredList" :key="(a.source || 'as') + '_' + a.id" class="card as-card">
						<view class="as-head">
							<view class="as-left">
								<text class="domain-badge" :class="a.target_domain === 'order' ? 'ord' : 'sk'">
									{{ a.target_domain === 'order' ? '租售/配件' : '买断技能' }}
								</text>
								<text class="as-name">{{ itemTitle(a) }}</text>
							</view>
							<text class="as-status" :class="a.status">{{ statusText(a.status) }}</text>
						</view>

						<view class="rows">
							<view class="row">
								<text class="rk">服务类型</text>
								<text class="rv">{{ typeText(a.type) }}</text>
							</view>
							<view v-if="a.reason_category" class="row">
								<text class="rk">申请原因</text>
								<text class="rv">{{ reasonText(a.reason_category) }}</text>
							</view>
							<view v-if="policyText(a.policy_basis)" class="row">
								<text class="rk">适用规则</text>
								<text class="rv">{{ policyText(a.policy_basis) }}</text>
							</view>
							<view v-if="a.refund_amount !== null && a.refund_amount !== undefined" class="row">
								<text class="rk">核定退款额</text>
								<text class="rv sm-mono">¥{{ a.refund_amount }}</text>
							</view>
							<view v-if="a.detail" class="row block">
								<text class="rk">问题描述</text>
								<text class="rv-text">{{ a.detail }}</text>
							</view>
							<view v-if="a.review_note" class="row block note">
								<text class="rk">平台/商家处理回复</text>
								<text class="rv-text">{{ a.review_note }}</text>
							</view>
							<view v-if="a.appeal_detail" class="row block">
								<text class="rk">补充申诉材料</text>
								<text class="rv-text">{{ a.appeal_detail }}</text>
							</view>
						</view>

						<view class="as-foot">
							<text class="as-time sm-mono">{{ a.created_at || '—' }}</text>
							<view class="as-actions">
								<text v-if="a.can_cancel" class="act-link" @tap="cancelItem(a)">撤回申请</text>
								<text v-if="a.can_appeal" class="act-link primary" @tap="openAppealModal(a)">提交复核申诉</text>
							</view>
						</view>
					</view>
				</state-view>

				<view style="height: 60rpx;"></view>
			</scroll-view>
		</template>

		<!-- 发起订单售后弹窗 -->
		<view v-if="orderModal.visible" class="modal-mask" @tap="orderModal.visible = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">申请整机租售 / 配件售后</text>
					<view class="sheet-close" @tap="orderModal.visible = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<text class="field-lbl">选择关联订单</text>
					<picker v-if="eligibleOrders.length" :range="orderPickerLabels" @change="onPickOrder">
						<view class="picker-box">
							<text>{{ pickedOrderLabel || '点击选择订单' }}</text>
							<text class="arrow">›</text>
						</view>
					</picker>
					<view v-else class="empty-tip">当前暂无可申请售后的租售或配件订单（技能售后请前往「我的技能」发起）</view>

					<text class="field-lbl">服务诉求类型</text>
					<view class="chip-row">
						<text
							v-for="t in orderTypes"
							:key="t.key"
							class="chip"
							:class="{ active: orderModal.type === t.key }"
							@tap="orderModal.type = t.key"
						>{{ t.label }}</text>
					</view>

					<text class="field-lbl">原因分类</text>
					<view class="chip-row">
						<text
							v-for="r in orderReasons"
							:key="r.key"
							class="chip"
							:class="{ active: orderModal.reason_category === r.key }"
							@tap="orderModal.reason_category = r.key"
						>{{ r.label }}</text>
					</view>

					<text class="field-lbl">故障或退换说明</text>
					<textarea class="textarea" v-model="orderModal.detail" placeholder="请说明设备故障现象、配件型号不符或租赁档期变更诉求" maxlength="500" />
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="orderModal.visible = false">取消</view>
					<view class="btn-solid" @tap="submitOrderAfterSale">提交售后单</view>
				</view>
			</view>
		</view>

		<!-- D10 申诉弹窗 -->
		<view v-if="appealModal.visible" class="modal-mask" @tap="appealModal.visible = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">提交售后二次复核申诉</text>
					<view class="sheet-close" @tap="appealModal.visible = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<text class="field-lbl">补充事实依据与凭证说明（至少 5 个字）</text>
					<textarea class="textarea" v-model="appealModal.detail" placeholder="请补充沟通记录、报错日志或现场情况，提交后转入人工复核队列" maxlength="1000" />
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="appealModal.visible = false">取消</view>
					<view class="btn-solid" @tap="submitAppeal">确认提交申诉</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { skillApi, orderApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

const STATUS_TEXT = {
	applied: '待受理',
	reviewing: '人工审核中',
	approved: '审核通过 · 处理中',
	rejected: '未通过',
	refunded: '已完成退款/结单',
	cancelled: '申请人已撤回',
}

const TYPE_TEXT = {
	refund: '仅退款',
	return: '退货退款',
	exchange: '更换配件/备机',
	repair: '故障报修',
	complaint: '履约投诉',
}

const REASON_TEXT = {
	unable_to_install: '无法下发安装',
	incompatible_model: '机型固件不兼容',
	execution_error: '执行报错异常',
	description_mismatch: '与商品描述不符',
	hardware_fault: '硬件/传感器故障',
	shipping_damage: '物流外包装破损',
	schedule_change: '租赁档期变更',
	other: '其他原因',
}

const POLICY_TEXT = {
	not_installed: '未下发技能 · 可直接退款',
	activated: '已激活技能 · 人工核实处理',
	order_fulfillment: '订单履约维保协同',
}

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			loggedIn: false,
			domainTab: 'all',
			loading: true,
			loadError: '',
			list: [],
			skillNames: {},
			eligibleOrders: [],
			orderModal: {
				visible: false,
				order_id: '',
				type: 'repair',
				reason_category: 'hardware_fault',
				detail: '',
			},
			orderTypes: [
				{ key: 'repair', label: '故障报修' },
				{ key: 'exchange', label: '更换配件/备机' },
				{ key: 'return', label: '退货退款' },
				{ key: 'refund', label: '仅退款' },
			],
			orderReasons: [
				{ key: 'hardware_fault', label: '硬件/传感器异常' },
				{ key: 'shipping_damage', label: '运输破损缺件' },
				{ key: 'schedule_change', label: '租赁档期调整' },
				{ key: 'description_mismatch', label: '规格与下单不符' },
			],
			appealModal: { visible: false, id: null, detail: '' },
		}
	},
	computed: {
		filteredList() {
			if (this.domainTab === 'all') return this.list
			return this.list.filter(x => (x.target_domain || 'skill') === this.domainTab)
		},
		orderPickerLabels() {
			return this.eligibleOrders.map(o => `#${o.increment_id || o.id} · ${(o.items && o.items[0] && o.items[0].name) || '订单'} · ¥${o.grand_total}`)
		},
		pickedOrderLabel() {
			const hit = this.eligibleOrders.find(o => o.id === this.orderModal.order_id)
			return hit ? `#${hit.increment_id || hit.id} · ¥${hit.grand_total}` : ''
		},
	},
	onLoad(q) {
		if (q && q.orderId) {
			setTimeout(() => {
				if (this.loggedIn) this.openOrderApply(Number(q.orderId))
			}, 250)
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
		goIncidentReport() { uni.navigateTo({ url: '/pages/mine/incidents' }) },
		itemTitle(a) {
			if (a.target_domain === 'order') {
				return a.ticket_no ? `维保单 ${a.ticket_no}` : `订单 #${a.order_id || '—'}`
			}
			return this.skillNames[a.entitlement_id] || `技能授权 #${a.entitlement_id || '—'}`
		},
		statusText(s) { return STATUS_TEXT[s] || s || '处理中' },
		typeText(t) { return TYPE_TEXT[t] || t || '售后处理' },
		reasonText(r) { return REASON_TEXT[r] || r },
		policyText(p) { return POLICY_TEXT[p] || '' },
		async refresh() {
			this.loading = true
			this.loadError = ''
			const [as, sk] = await Promise.all([
				skillApi.afterSales().catch((e) => { this.loadError = (e && e.message) || '加载失败'; return null }),
				skillApi.mine().catch(() => null),
			])
			this.list = (as && Array.isArray(as.data)) ? as.data : []
			const names = {}
			;((sk && Array.isArray(sk.data)) ? sk.data : []).forEach((s) => {
				names[s.id] = s.name || `技能 #${s.id}`
			})
			this.skillNames = names
			this.loading = false
		},
		async openOrderApply(preselectOrderId = 0) {
			this.orderModal = {
				visible: true,
				order_id: preselectOrderId || '',
				type: 'repair',
				reason_category: 'hardware_fault',
				detail: '',
			}
			try {
				const res = await orderApi.getOrders({ limit: 50 })
				const rows = (res && Array.isArray(res.data)) ? res.data : []
				this.eligibleOrders = rows.filter(o => !['canceled', 'closed'].includes(o.status))
				if (!this.orderModal.order_id && this.eligibleOrders.length) {
					this.orderModal.order_id = this.eligibleOrders[0].id
				}
			} catch (_) {}
		},
		onPickOrder(e) {
			const hit = this.eligibleOrders[Number(e.detail.value)]
			if (hit) this.orderModal.order_id = hit.id
		},
		async submitOrderAfterSale() {
			const f = this.orderModal
			if (!f.order_id) { uni.showToast({ title: '请选择关联订单', icon: 'none' }); return }
			if (!String(f.detail || '').trim()) { uni.showToast({ title: '请填写故障或退换说明', icon: 'none' }); return }
			try {
				const res = await skillApi.applyAfterSale({
					order_id: f.order_id,
					type: f.type,
					reason_category: f.reason_category,
					detail: f.detail.trim(),
				})
				this.orderModal.visible = false
				uni.showToast({ title: (res && res.message) || '售后单已提交', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
			}
		},
		cancelItem(a) {
			uni.showModal({
				title: '撤回售后申请',
				content: '确认撤回该条待处理的售后申请吗？',
				confirmColor: '#DC2626',
				success: async (r) => {
					if (!r.confirm) return
					try {
						await skillApi.cancelAfterSale(a.id)
						uni.showToast({ title: '已撤回', icon: 'success' })
						await this.refresh()
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '撤回失败', icon: 'none' })
					}
				},
			})
		},
		openAppealModal(a) {
			this.appealModal = { visible: true, id: a.id, detail: '' }
		},
		async submitAppeal() {
			const { id, detail } = this.appealModal
			if (!id || String(detail || '').trim().length < 5) {
				uni.showToast({ title: '请填写至少 5 个字的复核申诉说明', icon: 'none' })
				return
			}
			try {
				await skillApi.appealAfterSale(id, { appeal_detail: detail.trim() })
				this.appealModal.visible = false
				uni.showToast({ title: '复核申诉已受理', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
			}
		},
	},
}
</script>

<style scoped>
.as-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.nav-action { font-size: 27rpx; font-weight: 600; color: #165DFF; }
.domain-tabs { display: flex; margin: 18rpx 28rpx 0; background: #E9EEF6; border-radius: 16rpx; padding: 6rpx; }
.d-tab { flex: 1; height: 66rpx; border-radius: 12rpx; display: flex; align-items: center; justify-content: center; font-size: 25rpx; font-weight: 600; color: #475569; }
.d-tab.active { background: #FFFFFF; color: #165DFF; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06); }
.scroll-content { flex: 1; padding: 20rpx 28rpx; box-sizing: border-box; }
.incident-entry { background: #FFFFFF; border: 1rpx solid #FECACA; border-radius: 20rpx; padding: 24rpx; margin-bottom: 20rpx; display: flex; align-items: center; gap: 18rpx; }
.ie-icon { width: 72rpx; height: 72rpx; border-radius: 16rpx; background: #FEF2F2; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ie-main { flex: 1; min-width: 0; }
.ie-title { display: block; font-size: 28rpx; font-weight: 600; color: #0F172A; }
.ie-desc { display: block; font-size: 23rpx; color: #64748B; margin-top: 4rpx; line-height: 1.45; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.as-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14rpx; margin-bottom: 16rpx; }
.as-left { display: flex; align-items: center; flex-wrap: wrap; gap: 10rpx; flex: 1; }
.domain-badge { font-size: 21rpx; font-weight: 600; padding: 3rpx 12rpx; border-radius: 6rpx; }
.domain-badge.sk { background: #EEF4FF; color: #165DFF; }
.domain-badge.ord { background: #ECFDF5; color: #059669; }
.as-name { font-size: 30rpx; font-weight: 600; color: #0F172A; }
.as-status { font-size: 22rpx; font-weight: 600; padding: 4rpx 14rpx; border-radius: 999rpx; background: #F1F5F9; color: #475569; flex-shrink: 0; }
.as-status.applied { background: #EEF4FF; color: #165DFF; }
.as-status.reviewing { background: #FFFBEB; color: #D97706; }
.as-status.approved, .as-status.refunded { background: #ECFDF5; color: #059669; }
.as-status.rejected { background: #FEF2F2; color: #DC2626; }
.rows { padding: 12rpx 0; border-top: 1rpx solid #F1F5F9; border-bottom: 1rpx solid #F1F5F9; }
.row { display: flex; justify-content: space-between; padding: 8rpx 0; font-size: 25rpx; }
.row.block { flex-direction: column; gap: 6rpx; }
.row.note { background: #F8FAFC; padding: 14rpx 16rpx; border-radius: 12rpx; margin-top: 8rpx; }
.rk { color: #64748B; }
.rv { color: #0F172A; font-weight: 500; }
.rv-text { font-size: 25rpx; color: #334155; line-height: 1.55; }
.as-foot { display: flex; justify-content: space-between; align-items: center; padding-top: 16rpx; }
.as-time { font-size: 23rpx; color: #94A3B8; }
.as-actions { display: flex; gap: 24rpx; }
.act-link { font-size: 25rpx; color: #64748B; }
.act-link.primary { color: #165DFF; font-weight: 600; }
.modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 100; display: flex; align-items: flex-end; }
.modal-sheet { width: 100%; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; padding-bottom: env(safe-area-inset-bottom); }
.sheet-head { display: flex; justify-content: space-between; align-items: center; padding: 30rpx 32rpx 22rpx; border-bottom: 1rpx solid #EEF2F7; }
.sheet-title { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.sheet-close { padding: 8rpx; }
.sheet-body { padding: 24rpx 32rpx; max-height: 64vh; overflow-y: auto; }
.sheet-foot { display: flex; gap: 20rpx; padding: 16rpx 32rpx 28rpx; border-top: 1rpx solid #EEF2F7; }
.field-lbl { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin: 16rpx 0 10rpx; }
.picker-box { height: 82rpx; padding: 0 22rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; display: flex; align-items: center; justify-content: space-between; font-size: 26rpx; color: #0F172A; }
.arrow { font-size: 32rpx; color: #94A3B8; }
.empty-tip { padding: 18rpx 20rpx; background: #F8FAFC; border-radius: 14rpx; font-size: 24rpx; color: #64748B; line-height: 1.5; }
.chip-row { display: flex; flex-wrap: wrap; gap: 12rpx; }
.chip { padding: 10rpx 20rpx; border-radius: 999rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; font-size: 24rpx; color: #475569; }
.chip.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.textarea { width: 100%; min-height: 150rpx; padding: 18rpx 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; line-height: 1.5; }
.btn-cancel { flex: 1; height: 84rpx; border-radius: 16rpx; background: #F1F5F9; color: #475569; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-solid { flex: 2; height: 84rpx; border-radius: 16rpx; background: #165DFF; color: #FFFFFF; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
</style>
