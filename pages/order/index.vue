<template>
	<view class="order-page">
		<!-- ===== 顶部标题 + 自定义方案入口 ===== -->
		<view class="page-header">
			<text class="page-title">我的订单</text>
		</view>

		<!-- ===== 统计卡片 ===== -->
		<view class="stats-row">
			<view class="stat-card" @click="filterByStatus('all')">
				<text class="stat-number">{{ orders.length }}</text>
				<text class="stat-label">全部订单</text>
			</view>
			<view class="stat-card" @click="filterByStatus('pending')">
				<text class="stat-number">{{ getCountByStatus('pending') }}</text>
				<text class="stat-label">待付款</text>
			</view>
			<view class="stat-card" @click="filterByStatus('active')">
				<text class="stat-number">{{ getCountByStatus('active') }}</text>
				<text class="stat-label">服务中</text>
			</view>
			<view class="stat-card" @click="filterByStatus('completed')">
				<text class="stat-number">{{ getCountByStatus('completed') }}</text>
				<text class="stat-label">已完成</text>
			</view>
		</view>

		<!-- ===== 状态Tab ===== -->
		<scroll-view class="tab-scroll" scroll-x="true" show-scrollbar="false">
			<view class="tab-list">
				<text class="tab-item" :class="{ active: currentStatus === 'all' }" @click="switchStatus('all')">全部</text>
				<text class="tab-item" :class="{ active: currentStatus === 'scene' }" @click="switchStatus('scene')">场景服务</text>
				<text class="tab-item" :class="{ active: currentStatus === 'pending' }" @click="switchStatus('pending')">待付款</text>
				<text class="tab-item" :class="{ active: currentStatus === 'confirm' }" @click="switchStatus('confirm')">待确认</text>
				<text class="tab-item" :class="{ active: currentStatus === 'active' }" @click="switchStatus('active')">服务中</text>
				<text class="tab-item" :class="{ active: currentStatus === 'completed' }" @click="switchStatus('completed')">已完成</text>
				<text class="tab-item" :class="{ active: currentStatus === 'cancelled' }" @click="switchStatus('cancelled')">已取消</text>
			</view>
		</scroll-view>

		<!-- ===== 订单列表 ===== -->
		<view class="order-list">
			<!-- 三态:加载中 / 故障(带重试) / 真·暂无订单。故障不再伪装成「暂无订单」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="filteredOrders.length === 0"
				empty-icon="🤖"
				empty-text="暂无订单"
				empty-hint="去AI生成机器人方案，快速匹配您的需求"
				empty-action
				empty-action-text="去AI生成方案 →"
				btn-color="#2563eb"
				@empty-action="goAI"
				@retry="loadOrders"
			>
			<view class="order-card" v-for="item in filteredOrders" :key="item.id" @click="viewDetail(item)">
				<!-- 卡片头部 -->
				<view class="card-header">
					<view class="robot-info">
						<view class="robot-avatar" :style="{ backgroundColor: item.bg }">
							<text class="robot-icon">{{ item.icon }}</text>
						</view>
						<view class="robot-meta">
							<text class="robot-name">{{ item.name }}</text>
							<text class="order-id">#{{ item.orderNo }}</text>
						</view>
					</view>
					<view class="status-tag" :class="item.statusClass">
						<text>{{ item.statusText }}</text>
					</view>
				</view>

				<!-- 卡片主体（按订单类型动态渲染不同字段） -->
				<view class="card-body">
					<view class="detail-row">
						<text class="detail-label">订单类型</text>
						<text class="detail-value type-badge" :class="item.typeClass">{{ item.typeText }}</text>
					</view>
					<view class="detail-row" v-for="f in orderFields(item)" :key="f.label" :class="{ highlight: f.highlight }">
						<text class="detail-label">{{ f.label }}</text>
						<text class="detail-value" :class="f.className || ''">{{ f.value }}</text>
					</view>
				</view>

				<!-- 卡片底部 -->
				<view class="card-footer">
					<view class="btn-group">
						<view class="btn-outline" @click.stop="viewDetail(item)">查看详情</view>
						<view class="btn-outline" @click.stop="contactService(item)">联系客服</view>
						<view class="btn-primary" @click.stop="reorder(item)">再次下单</view>
					</view>
				</view>
			</view>

			</state-view>
		</view>

		<!-- ===== 自定义底部导航栏 ===== -->
		<CustomTabBar currentPage="/pages/order/index" />
	</view>
</template>

<script>
	import CustomTabBar from '@/components/CustomTabBar.vue';
	import StateView from '@/components/StateView.vue';
	import { orderApi } from '@/utils/api';
	import { sceneStatusLabel } from '@/utils/scene-request';

	export default {
		components: {
			CustomTabBar,
			StateView
		},
		data() {
			return {
				currentStatus: 'all',
				loading: true,
				loadError: '',
				orders: [],
			}
		},
		computed: {
			filteredOrders() {
				if (this.currentStatus === 'all') return this.orders
				return this.orders.filter(item => item.status === this.currentStatus)
			}
		},
		onShow() {
			// 消费「我的-订单入口」传来的状态定位(读后即清),再刷新
			const status = uni.getStorageSync('orderStatus')
			if (status) {
				uni.removeStorageSync('orderStatus')
				if (['pending', 'confirm', 'completed'].includes(status)) this.currentStatus = status
			}
			this.loadOrders()
		},
		methods: {
			switchStatus(status) { this.currentStatus = status },
			filterByStatus(status) { this.currentStatus = status },

			// ================ 订单数据加载（以后端真实单为准） ================
			async loadOrders() {
				// 清理历史本地意向单(旧假数据);订单以后端 guest-orders 真实单为准
				uni.removeStorageSync('intentOrders')
				const intents = []

				this.loading = true
				this.loadError = ''
				let apiOrders = []
				try {
					// 通用:始终拉游客单(场景租用/游客单真实数据);登录用户再叠加本人订单(按 id 去重)
					const gg = await orderApi.getGuestOrders()
					let list = (gg && gg.data) ? gg.data : []
					if (uni.getStorageSync('token')) {
						const mine = await orderApi.getOrders()
						const mList = (mine && mine.data) ? mine.data : []
						const ids = new Set(list.map(o => o.id))
						list = [...list, ...mList.filter(o => !ids.has(o.id))]
					}
					apiOrders = list.map(o => this.mapApiOrder(o))
				} catch (e) {
					// ⚠ 故障 ≠「暂无订单」:置错误标记交给 StateView 的错误态(带重试)
					this.loadError = (e && e.message) || '加载失败'
					apiOrders = []
				} finally {
					this.loading = false
				}

				// 只看真实单
				this.orders = apiOrders
				// 拉取失败时**不**把空数组写进缓存 —— 否则一次网络抖动会清掉订单缓存,
				// 订单详情页(读 orderList 兜底)也跟着查不到单。
				if (!this.loadError) {
					uni.setStorageSync('orderList', apiOrders)
					uni.removeStorageSync('orderNeedRefresh')
				}
			},

			// 后端订单 → 前端卡片字段
			mapApiOrder(o) {
				const statusMap = {
					pending: 'pending',          // 待付款
					pending_payment: 'pending',
					processing: 'confirm',       // 待确认
					completed: 'completed',      // 已完成
					canceled: 'cancelled',       // 已取消
					closed: 'completed',
					fraud: 'cancelled',
				}
				// 方案咨询/定制类线索(custom):提交即视为已完成,无需后续操作
				const status = o.scene_workflow ? 'scene' : ((o.order_type === 'custom') ? 'completed' : (statusMap[o.status] || 'active'))
				const textMap = {
					pending: '待付款',
					confirm: '待确认',
					execute: '待执行',
					active: '服务中',
					completed: '已完成',
					cancelled: '已取消',
				}
				const firstItem = (o.items && o.items[0]) || {}
				const amount = (o.grand_total != null) ? Number(o.grand_total).toLocaleString('en-US') : '0'
				// 场景租用条目名形如「场景名 · 机型【租赁】（租期）」;标题只留机型,使用场景取前缀
				// 多机合单:拼接所有机型名(不只第一台),如「宇树u2、11、众擎T800」;场景取条目名「场景名 · 」前缀
				const itemNames = (Array.isArray(o.items) ? o.items : []).map((it) => {
					const n = (it && it.name) || ''
					const a = n.split(' · ')
					return (a.length > 1 ? a.slice(1).join(' · ') : n).replace(/【[^】]*】|[（(][^）)]*[）)]/g, '').trim()
				}).filter(Boolean)
				const name = itemNames.join('、') || '机器人订单'
				const firstRaw = ((o.items && o.items[0] && o.items[0].name) || '')
				const scene = firstRaw.split(' · ').length > 1 ? firstRaw.split(' · ')[0] : (o.scene || '')
				return {
					id: o.id,
					productId: firstItem.product_id || '',
					orderNo: o.increment_id || `SO${o.id}`,
					name: name || '机器人订单',
					icon: '🤖',
					bg: '#F0F4FA',
					// 真实类型(后端 order_type):页签/详情分类显示的数据源
					type: o.order_type || 'buy',
					typeText: ({rent:'租赁',buy:'购买',plan:'方案',custom:'定制',skill:'技能',accessory:'配件'})[o.order_type] || '购买',
					typeClass: o.order_type || 'buy',
					scene: scene || '',
					duration: o.created_at || '',
					quantity: o.items_qty || (Array.isArray(o.items) ? o.items.reduce((s, i) => s + (Number(i.qty_ordered) || 0), 0) : 0) || 1,
					amount,
					status,
					statusText: o.scene_workflow ? sceneStatusLabel(o.scene_workflow.state) + ' · ' + sceneStatusLabel(o.scene_workflow.payment_state) : textMap[status],
					statusClass: o.scene_workflow ? 'confirm' : status,
					sceneWorkflow: o.scene_workflow || null,
				}
			},

			// 本地意向订单 → 前端卡片字段
			mapIntent(i) {
				const isRent = i.type === 'rent'
				return {
					id: i.id,
					productId: i.productId || '',
					orderNo: `ZT${i.id}`,
					name: i.name,
					icon: i.icon || '🤖',
					bg: i.bg || '#F0F4FA',
					type: isRent ? 'rent' : 'buy',
					typeText: isRent ? '租赁' : '购买',
					typeClass: isRent ? 'rent' : 'buy',
					// 购买不再出现「购买意向」措辞；优先用实际场景
					scene: i.scene || (isRent ? '租用意向' : '产品购买'),
					duration: i.rentPeriod || i.time || '',
					quantity: i.qty || 1,
					amount: i.price ? String(Math.round(parseFloat(String(i.price).replace(/[^\d.]/g, '')) * 100) / 100) : '0',
					status: 'confirm',
					statusText: '待确认',
					statusClass: 'confirm',
				}
			},

			// 按订单类型动态生成展示字段（租用/购买/方案/定制），代码逻辑区分而非硬写
			orderFields(item) {
				const t = item.type || 'buy'
				const rows = [{ label: '使用场景', value: item.scene || '—' }]
				if (t === 'rent') {
					const duration = item.duration || (item.startDate ? `${item.startDate} ~ ${item.endDate || ''}` : '—')
					rows.push({ label: '租期', value: duration })
					rows.push({ label: '租用数量', value: `${item.quantity} 台` })
				} else if (t === 'plan') {
					rows.push({ label: '方案数量', value: `${item.quantity} 台` })
				} else if (t === 'custom') {
					rows.push({ label: '定制数量', value: `${item.quantity} 台` })
				} else {
					// buy 及其它：购买不需要租期
					rows.push({ label: '数量', value: `${item.quantity} 台` })
				}
				rows.push({ label: '订单金额', value: `¥${item.amount}`, highlight: true, className: 'price' })
				return rows
			},

			getCountByStatus(status) {
				return this.orders.filter(item => item.status === status).length
			},
			viewDetail(item) {
				if (item.sceneWorkflow?.project_id) { uni.navigateTo({url:'/pages/scene/project?id='+item.sceneWorkflow.project_id}); return }
				// 同步完整订单数据给详情页
				uni.setStorageSync('orderDetailData', item)
				uni.navigateTo({
					url: `/pages/order/order-detail?id=${item.id}`
				})
			},
			contactService(item) {
				uni.showToast({ title: '客服已接入', icon: 'none' })
			},
			reorder(item) {
				if (item.sceneWorkflow) { uni.navigateTo({url:'/pages/scene/request?source=scene'+(item.sceneWorkflow.scene_id?'&sceneId='+item.sceneWorkflow.scene_id:'')}); return }
				const pid = item.productId || ''
				if (!pid) {
					uni.showToast({ title: '该订单未关联可再次下单的商品', icon: 'none' })
					return
				}
				const params = {
					robotName: item.name,
					productId: pid,
					scene: item.scene,
					quantity: item.quantity,
					duration: item.duration,
					amount: item.amount,
					type: item.type,
					rentPeriod: item.duration || '',
					productType: item.productType || '',
				}
				uni.navigateTo({
					url: `/pages/order/checkout?data=${encodeURIComponent(JSON.stringify(params))}`,
					fail: () => {
						uni.setStorageSync('reorderData', params)
						uni.showModal({
							title: '提示',
							content: `将根据「${item.name}」的订单信息为您生成新订单，请前往AI方案页确认。`,
							confirmText: '去AI方案',
							success: (res) => {
								if (res.confirm) {
									uni.switchTab({ url: '/pages/ai/index' })
								}
							}
						})
					}
				})
			},
			goAI() { uni.switchTab({ url: '/pages/ai/index' }) }
		}
	}
</script>

<style scoped>
	.order-page {
		position: relative;
		background-color: #f5f7fa;
		min-height: 100vh;
		padding: 88rpx 24rpx 160rpx;  /* 底部内边距从120rpx改为160rpx，为自定义导航栏留空间 */
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
	}
	.order-page::before {
		content: '';
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		height: 240rpx;
		background: linear-gradient(135deg, #7AB8F7, #D4EAFF);
		border-radius: 0 0 48rpx 48rpx;
		z-index: 0;
	}
	.page-header {
		position: relative;
		z-index: 1;
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 8rpx 0 16rpx;
	}
	.page-title {
		font-size: 36rpx;
		font-weight: 700;
		color: #1A2A3A;
	}
	.custom-plan-btn {
		display: flex;
		align-items: center;
		gap: 8rpx;
		background: rgba(255,255,255,0.85);
		padding: 10rpx 20rpx;
		border-radius: 30rpx;
		border: 1.5rpx solid rgba(255,255,255,0.6);
		cursor: pointer;
		backdrop-filter: blur(8rpx);
	}
	.custom-plan-btn:active { opacity: 0.7; }
	.plan-icon { font-size: 24rpx; }
	.plan-text { font-size: 22rpx; color: #1A2A3A; font-weight: 500; }

	.stats-row {
		position: relative;
		z-index: 1;
		display: flex;
		gap: 12rpx;
		margin-bottom: 24rpx;
		margin-top: 4rpx;
	}
	.stat-card {
		flex: 1;
		background: #ffffff;
		border-radius: 16rpx;
		padding: 16rpx 0;
		text-align: center;
		box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.04);
		cursor: pointer;
	}
	.stat-card:active { opacity: 0.7; }
	.stat-number {
		font-size: 32rpx;
		font-weight: 700;
		color: #1e293b;
		display: block;
	}
	.stat-label {
		font-size: 20rpx;
		color: #94a3b8;
		display: block;
		margin-top: 4rpx;
	}

	.tab-scroll {
		position: relative;
		z-index: 1;
		margin-bottom: 20rpx;
	}
	.tab-list {
		display: flex;
		gap: 8rpx;
		padding: 4rpx 0;
	}
	.tab-item {
		flex-shrink: 0;
		padding: 10rpx 24rpx;
		font-size: 24rpx;
		color: #64748b;
		background: #ffffff;
		border-radius: 40rpx;
		border: 1px solid #e8f0fe;
		cursor: pointer;
	}
	.tab-item.active {
		background: #ffffff;
		color: #2563eb;
		border-color: #2563eb;
		font-weight: 600;
	}

	.order-list {
		position: relative;
		z-index: 1;
		display: flex;
		flex-direction: column;
		gap: 16rpx;
	}
	.order-card {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 20rpx 24rpx 18rpx;
		box-shadow: 0 2rpx 16rpx rgba(0,0,0,0.04);
		cursor: pointer;
	}
	.order-card:active { opacity: 0.95; }

	.card-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16rpx;
	}
	.robot-info {
		display: flex;
		align-items: center;
		gap: 14rpx;
	}
	.robot-avatar {
		width: 72rpx;
		height: 72rpx;
		border-radius: 16rpx;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.robot-icon { font-size: 36rpx; }
	.robot-meta {
		display: flex;
		flex-direction: column;
		gap: 4rpx;
	}
	.robot-name {
		font-size: 26rpx;
		font-weight: 600;
		color: #1e293b;
	}
	.order-id {
		font-size: 20rpx;
		color: #94a3b8;
	}

	.status-tag {
		padding: 6rpx 18rpx;
		border-radius: 30rpx;
		font-size: 20rpx;
		font-weight: 500;
	}
	.status-tag.pending { background: #fef3c7; color: #d97706; }
	.status-tag.confirm { background: #dbeafe; color: #2563eb; }
	.status-tag.execute { background: #dbeafe; color: #2563eb; }
	.status-tag.active { background: #d1fae5; color: #059669; }
	.status-tag.completed { background: #e8f0fe; color: #64748b; }
	.status-tag.cancelled { background: #f1f5f9; color: #94a3b8; }

	.card-body {
		background: #f8faff;
		border-radius: 16rpx;
		padding: 16rpx 18rpx;
		margin-bottom: 16rpx;
	}
	.detail-row {
		display: flex;
		justify-content: space-between;
		padding: 6rpx 0;
		border-bottom: 1px solid #f1f5f9;
	}
	.detail-row:last-child { border-bottom: none; }
	.detail-row.highlight { padding: 10rpx 0 6rpx; }
	.detail-label {
		font-size: 22rpx;
		color: #94a3b8;
	}
	.detail-value {
		font-size: 22rpx;
		color: #1e293b;
		font-weight: 500;
	}
	.detail-value.type-badge {
		padding: 2rpx 16rpx;
		border-radius: 20rpx;
		font-size: 20rpx;
		font-weight: 500;
	}
	.type-badge.rent { background: #dbeafe; color: #2563eb; }
	.type-badge.buy { background: #d1fae5; color: #059669; }
	.type-badge.plan { background: #fef3c7; color: #d97706; }
	.type-badge.custom { background: #ede9fe; color: #7c3aed; }
	.detail-value.price {
		font-size: 26rpx;
		font-weight: 700;
		color: #2563eb;
	}

	.card-footer { padding-top: 4rpx; }
	.btn-group {
		display: flex;
		gap: 12rpx;
	}
	.btn-outline {
		flex: 1;
		padding: 14rpx 0;
		border-radius: 30rpx;
		text-align: center;
		font-size: 22rpx;
		font-weight: 500;
		color: #1e293b;
		background: #f8faff;
		border: 1px solid #e8f0fe;
		cursor: pointer;
	}
	.btn-outline:active { opacity: 0.7; }
	.btn-primary {
		flex: 1;
		padding: 14rpx 0;
		border-radius: 30rpx;
		text-align: center;
		font-size: 22rpx;
		font-weight: 500;
		color: #2563eb;
		background: #ffffff;
		border: 1.5rpx solid #2563eb;
		cursor: pointer;
	}
	.btn-primary:active { opacity: 0.7; }

	.empty-state {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 80rpx 40rpx 60rpx;
	}
	.empty-icon { font-size: 120rpx; margin-bottom: 24rpx; }
	.empty-title {
		font-size: 32rpx;
		font-weight: 600;
		color: #1e293b;
	}
	.empty-desc {
		font-size: 24rpx;
		color: #94a3b8;
		margin-top: 8rpx;
	}
	.empty-btn {
		margin-top: 32rpx;
		background: #ffffff;
		color: #2563eb;
		padding: 16rpx 48rpx;
		border-radius: 40rpx;
		font-size: 26rpx;
		font-weight: 500;
		border: 1.5rpx solid #2563eb;
		cursor: pointer;
	}
	.empty-btn:active { opacity: 0.7; }

	/* 隐藏原生的 bottom-safe，由自定义导航栏替代 */
	.bottom-safe {
		display: none;
	}
</style>
