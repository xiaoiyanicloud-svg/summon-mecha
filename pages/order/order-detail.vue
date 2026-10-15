<template>
	<view class="order-detail-page">
		<!-- 导航栏 -->
		<nav-bar title="订单详情" variant="pill" />

		<!-- 加载 / 故障:加载中转圈;拉取失败走「取不到」,而不是伪造成「订单不存在」 -->
		<state-view
			v-if="loading || loadError"
			:loading="loading"
			:error="loadError"
			compact
			btn-color="#2563eb"
			@retry="retryLoad"
		/>

		<!-- 订单内容 -->
		<view v-else-if="order" class="scroll-content">
			<!-- 头部：机器人信息 + 状态 -->
			<view class="card header-card">
				<view class="robot-row">
					<view class="robot-avatar" :style="{ backgroundColor: order.bg }">
						<text class="robot-icon">{{ order.icon }}</text>
					</view>
					<view class="robot-info">
						<text class="robot-name">{{ order.name }}</text>
						<text class="order-id">订单号：{{ order.orderNo }}</text>
					</view>
				</view>
				<view class="status-tag" :class="order.statusClass">
					{{ order.statusText }}
				</view>
			</view>

			<!-- 订单信息 -->
			<view class="card info-card">
				<view class="info-row">
					<text class="label">租赁类型</text>
					<text class="value type-badge" :class="order.typeClass">{{ order.typeText }}</text>
				</view>
				<view class="info-row" v-if="order.scene">
					<text class="label">使用场景</text>
					<text class="value">{{ order.scene }}</text>
				</view>
				<view class="info-row" v-if="order.type === 'rent'">
					<text class="label">租期</text>
					<text class="value">{{ order.startDate || order.duration || '—' }} {{ order.startDate && order.endDate ? '至 ' + order.endDate : '' }}</text>
				</view>
				<view class="info-row">
					<text class="label">{{ order.type === 'rent' ? '租赁台数' : '数量' }}</text>
					<text class="value">{{ order.quantity }} 台</text>
				</view>
			</view>

			<!-- 费用明细 -->
			<view class="card fee-card">
				<view class="fee-row" v-if="order.rentPrice">
					<text class="label">租金</text>
					<text class="value">¥{{ order.rentPrice }}</text>
				</view>
				<view class="fee-row" v-if="order.deposit">
					<text class="label">押金</text>
					<text class="value">¥{{ order.deposit }}</text>
				</view>
				<view class="fee-row" v-if="order.freight">
					<text class="label">运费</text>
					<text class="value">¥{{ order.freight || '0' }}</text>
				</view>
				<view class="fee-row discount" v-if="order.discount">
					<text class="label">优惠</text>
					<text class="value">-¥{{ order.discount || '0' }}</text>
				</view>
				<view class="fee-row total">
					<text class="label">实付</text>
					<text class="value price">¥{{ order.actualAmount || '0' }}</text>
				</view>
			</view>

			<!-- 履约时间轴（真实订单暂无履约数据时隐藏,不伪造进度） -->
			<view class="card timeline-card" v-if="order.timeline && order.timeline.length">
				<text class="card-title">履约进度</text>
				<view class="timeline">
					<view class="timeline-item" v-for="(item, idx) in order.timeline" :key="idx">
						<view class="timeline-dot" :class="{ done: item.done }"></view>
						<view class="timeline-content">
							<text class="timeline-title">{{ item.title }}</text>
							<text class="timeline-time">{{ item.time }}</text>
						</view>
						<view class="timeline-line" v-if="!item.isLast"></view>
					</view>
				</view>
			</view>

			<!-- 设备信息：只有技能单才有交付设备的概念。此前这块对所有订单都显示、且值恒为「—」 -->
			<view class="card device-card" v-if="order.deviceInfoVisible">
				<view class="device-row">
					<text class="label">设备SN码</text>
					<text class="value sn-code">{{ order.snCode || '待指派' }}</text>
				</view>
				<view class="device-row">
					<text class="label">交付方式</text>
					<text class="value">{{ order.deliveryMethod || '—' }}</text>
				</view>
				<!-- 下单只是开通授权,真正启用要去「我的技能」绑设备、下技能包 -->
				<view class="device-row">
					<text class="label">技能状态</text>
					<text class="value">{{ skillStatusText }}</text>
				</view>
				<view class="go-skills" @click="goSkills">去使用这个技能 ›</view>
			</view>

			<view style="height: 180rpx;"></view>
		</view>

		<!-- 空状态 -->
		<view v-else class="empty-state">
			<text class="empty-icon">📭</text>
			<text class="empty-title">订单不存在</text>
			<view class="empty-btn" @click="goBack">返回</view>
		</view>

		<!-- 底部操作按钮（根据状态动态渲染） -->
		<view class="bottom-actions" v-if="order">
			<template v-if="order.status === 'pending'">
				<view class="btn-outline" @click="cancelOrder">取消订单</view>
				<view class="btn-primary" @click="payOrder">立即付款</view>
			</template>
			<template v-else-if="order.status === 'confirm'">
				<view class="btn-outline" @click="cancelOrder">取消订单</view>
			</template>
			<template v-else-if="order.status === 'active'">
				<view class="btn-outline" @click="extendRent">续租</view>
				<view class="btn-outline" @click="applyReturn">申请归还</view>
				<view class="btn-outline" @click="reportRepair">一键报修</view>
				<view class="btn-outline" @click="viewContract">查看电子合同</view>
				<view class="btn-primary" @click="contactService">联系服务商</view>
			</template>
			<template v-else-if="order.status === 'completed'">
				<view class="btn-outline" @click="applyInvoice">开发票</view>
				<view class="btn-outline" @click="evaluate">评价</view>
				<view class="btn-primary" @click="reorder">再次下单</view>
			</template>
		</view>
	</view>
</template>

<script>
import { orderApi, ticketApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import StateView from '@/components/StateView.vue'
import NavBar from '@/components/NavBar.vue'
	export default {
		components: { StateView, NavBar },
		data() {
			return {
				order: null,
				orderId: null,
				loading: true,
				loadError: '',
			}
		},
		onLoad(options) {
			const id = parseInt(options.id)
			this.orderId = id || null
			if (id) {
				this.loadOrder(id)
			} else {
				this.loading = false
				this.order = null
			}
		},
		computed: {
			// 技能单的状态文案。后端 formatOrder 在 items[].skill_status 上给了授权状态,
			// 这里转成人话 —— 「已开通」是「已授权」,不等于「已能使用」(还要绑设备)。
			skillStatusText() {
				const map = {
					pending_bind: '已开通 · 待指派设备',
					granted: '已开通 · 可用',
					active: '已安装',
					expired: '已过期',
					revoked: '已撤销',
				}
				const s = this.order && this.order.skillStatus
				return map[s] || (s || '—')
			},
		},
		methods: {
			async loadOrder(id) {
				this.loading = true
				this.loadError = ''
				// 方式一：优先使用列表页同步过来的完整订单（viewDetail 里 setStorageSync）
				const synced = uni.getStorageSync('orderDetailData')
				if (synced && (id == null || Number(synced.id) === Number(id))) {
					uni.removeStorageSync('orderDetailData')
					this.order = this.normalizeOrder(synced)
					this.loading = false
					return
				}

				// 方式二：从缓存订单列表查找（宽松匹配，避免 id 类型不一至）
				const orderList = uni.getStorageSync('orderList') || []
				const found = orderList.find(item => Number(item.id) === Number(id))
				if (found) {
					this.order = this.normalizeOrder(found)
					this.loading = false
					return
				}

					// 方式三：真实接口(已登录且有数字 id)——后台订单以服务端为准,不再回退假数据
				const token = uni.getStorageSync(TOKEN_KEY)
				if (token && id != null && !isNaN(Number(id))) {
					try {
						const res = await orderApi.getOrderDetail(id)
						const d = (res && res.data) || {}
						if (d && d.id) {
							this.order = this.normalizeOrder(this.mapApiDetail(d))
							this.loading = false
							return
						}
					} catch (e) {
						// ⚠ 接口失败是**故障**,不是「订单不存在」—— 置错误标记,
						//   由上方 StateView 的错误态承载(带重试),不再落进「订单不存在」空态。
						this.loadError = (e && e.message) || '加载失败'
					}
				}

				this.order = null
				this.loading = false
			},

			// 错误态「重试」:清掉错误标记,按记住的 id 重拉
			retryLoad() {
				if (!this.orderId) { this.loadError = ''; return }
				this.loadOrder(this.orderId)
			},

			// 后台订单详情 → 展示字段(只映射接口真实存在的数据,mock 专属字段不伪造)
			mapApiDetail(d) {
				const item = (d.items && d.items[0]) || {}
				const statusMap = {
					pending: ['待处理', 'pending'],
					processing: ['待确认', 'confirm'],
					completed: ['已完成', 'completed'],
					closed: ['已完成', 'completed'],
					canceled: ['已取消', 'cancelled'],
				}
				const pair = statusMap[d.status] || ['服务中', 'active']
				const typeLabels = { rent: '租赁', buy: '购买', plan: '方案', custom: '定制', skill: '技能', accessory: '配件' }
				return {
					id: d.id,
					orderNo: d.increment_id,
					name: item.name || '订单',
					icon: '🤖',
					bg: '#F0F4FA',
					type: d.order_type || 'buy',
					typeText: typeLabels[d.order_type] || '购买',
					typeClass: d.order_type || 'buy',
					scene: '',
					quantity: d.total_qty_ordered != null ? d.total_qty_ordered : (item.qty_ordered || 1),
					actualAmount: d.grand_total != null ? String(d.grand_total) : '0',
					status: pair[1],
					statusText: pair[0],
					statusClass: pair[1],
					timeline: [],
					// 技能单才有交付设备的概念。后端 formatOrder 在技能单上返回 sn_code / delivery_method，
					// 非技能单为 null —— 这时整块设备卡片不该出现（此前它对所有订单都显示、值恒为「—」）。
					deviceInfoVisible: d.order_type === 'skill',
					snCode: d.sn_code || '',
					deliveryMethod: d.delivery_method || '',
					skillStatus: item.skill_status || '',
				}
			},

			// 补齐详情页字段：缺失时回退到订单已有字段，避免显示为空
			normalizeOrder(order) {
				if (!order.actualAmount) order.actualAmount = order.amount || '0'
				return { ...order }
			},

			goBack() { uni.navigateBack() },

			// 技能单的「开通」落点在「我的技能」页(绑设备 + 下技能包)
			goSkills() { uni.navigateTo({ url: '/pages/mine/skills' }); },

			// ===== 按钮交互 =====
			payOrder() { uni.showToast({ title: '跳转支付', icon: 'none' }) },

			// 真实自助取消:仅本人待处理订单(后端校验),成功后刷新状态
			cancelOrder() {
				const id = this.order && this.order.id
				if (!id) return
				uni.showModal({
					title: '取消订单',
					content: '确认取消该订单吗？',
					success: (res) => {
						if (!res.confirm) return
						// 游客单走游客取消接口(免登录),登录单走本人取消;后者需后端已绑定 token
						const req = uni.getStorageSync('token')
							? orderApi.cancelOrder(id)
							: orderApi.cancelGuestOrder(id)
						req
							.then((r) => {
								uni.showToast({ title: (r && r.message) || '订单已取消', icon: 'success' })
								// 本地同步状态,不等缓存刷新
								if (this.order) {
									this.order.status = 'cancelled'
									this.order.statusText = '已取消'
									this.order.statusClass = 'cancelled'
								}
							})
							.catch((err) => uni.showToast({ title: (err && err.message) || '取消失败', icon: 'none' }))
					}
				})
			},
			extendRent() { uni.showToast({ title: '续租申请已提交', icon: 'success' }) },
			applyReturn() { uni.showToast({ title: '归还申请已提交', icon: 'success' }) },
			reportRepair() {
				// B-20:此前这里直接 toast「报修提交成功」——**不调任何后端**,是假动作。
				// 现在落到真实客服工单(category=device),用户能在「我的工单」里追进度。
				if (!uni.getStorageSync(TOKEN_KEY)) {
					uni.showModal({
						title: '一键报修',
						content: '报修会生成客服工单，需要先登录。前往「我的」登录？',
						confirmText: '去登录',
						success: (r) => { if (r.confirm) uni.switchTab({ url: '/pages/mine/index' }) },
					})
					return
				}

				uni.showModal({
					title: '一键报修',
					editable: true,
					placeholderText: '请描述故障现象（必填）',
					success: async (r) => {
						if (!r.confirm) return
						const desc = (r.content || '').trim()
						if (!desc) { uni.showToast({ title: '请描述故障现象', icon: 'none' }); return }

						const orderNo = (this.order && this.order.orderNo) || ''
						const orderName = (this.order && this.order.name) || ''
						try {
							await ticketApi.create({
								category: 'device',
								content: `【一键报修】订单 ${orderNo}${orderName ? ' · ' + orderName : ''}\n故障描述：${desc}`,
							})
							uni.showModal({
								title: '报修已提交',
								content: '客服会在「我的工单」里回复你。',
								confirmText: '查看工单',
								cancelText: '知道了',
								success: (m) => { if (m.confirm) uni.navigateTo({ url: '/pages/mine/tickets' }) },
							})
						} catch (e) {
							uni.showToast({ title: (e && e.message) || '报修提交失败', icon: 'none' })
						}
					},
				})
			},
			viewContract() { uni.showToast({ title: '查看电子合同', icon: 'none' }) },
			contactService() { uni.showToast({ title: '客服已接入', icon: 'none' }) },
			applyInvoice() { uni.showToast({ title: '申请开票', icon: 'none' }) },
			evaluate() { uni.showToast({ title: '评价页面', icon: 'none' }) },
			reorder() {
				const params = {
					robotName: this.order.name,
					scene: this.order.scene,
					quantity: this.order.quantity,
					startDate: this.order.startDate,
					endDate: this.order.endDate,
					rentPrice: this.order.rentPrice,
					deposit: this.order.deposit,
					amount: this.order.actualAmount,
					type: this.order.type, rentPeriod: this.order.duration || '', productType: this.order.productType || ''
				}
				uni.navigateTo({
					url: `/pages/order/checkout?data=${encodeURIComponent(JSON.stringify(params))}`,
					fail: () => {
						uni.setStorageSync('reorderData', params)
						uni.showModal({
							title: '再次下单',
							content: `将根据「${this.order.name}」的订单信息为您生成新订单，请前往AI方案页确认。`,
							confirmText: '去AI方案',
							success: (res) => {
								if (res.confirm) {
									uni.switchTab({ url: '/pages/ai/index' })
								}
							}
						})
					}
				})
			}
		}
	}
</script>

<style scoped>
	.order-detail-page {
		background-color: #f5f7fa;
		min-height: 100vh;
		display: flex;
		flex-direction: column;
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
	}
	.loading-state {
		flex: 1;
		display: flex;
		justify-content: center;
		align-items: center;
	}
	.loading-text {
		font-size: 28rpx;
		color: #94a3b8;
	}

	.scroll-content {
		flex: 1;
		padding: 20rpx 24rpx 0;
		box-sizing: border-box;
		overflow-y: auto;
	}

	.card {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 24rpx 20rpx;
		margin-bottom: 20rpx;
		box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.04);
	}
	.header-card {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}
	.robot-row {
		display: flex;
		align-items: center;
		gap: 16rpx;
	}
	.robot-avatar {
		width: 80rpx;
		height: 80rpx;
		border-radius: 20rpx;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.robot-icon { font-size: 40rpx; }
	.robot-info {
		display: flex;
		flex-direction: column;
		gap: 4rpx;
	}
	.robot-name {
		font-size: 28rpx;
		font-weight: 600;
		color: #1e293b;
	}
	.order-id {
		font-size: 20rpx;
		color: #94a3b8;
	}
	.status-tag {
		padding: 6rpx 20rpx;
		border-radius: 30rpx;
		font-size: 22rpx;
		font-weight: 500;
	}
	.status-tag.pending { background: #fef3c7; color: #d97706; }
	.status-tag.confirm { background: #dbeafe; color: #2563eb; }
	.status-tag.active { background: #d1fae5; color: #059669; }
	.status-tag.completed { background: #e8f0fe; color: #64748b; }

	.info-row {
		display: flex;
		justify-content: space-between;
		padding: 12rpx 0;
		border-bottom: 1rpx solid #f1f5f9;
	}
	.info-row:last-child { border-bottom: none; }
	.info-row .label {
		font-size: 24rpx;
		color: #94a3b8;
	}
	.info-row .value {
		font-size: 24rpx;
		color: #1e293b;
		font-weight: 500;
	}
	.type-badge {
		padding: 2rpx 16rpx;
		border-radius: 20rpx;
		font-size: 20rpx;
		font-weight: 500;
	}
	.type-badge.rent { background: #dbeafe; color: #2563eb; }
	.type-badge.buy { background: #d1fae5; color: #059669; }
	.type-badge.plan { background: #fef3c7; color: #d97706; }
	.type-badge.custom { background: #ede9fe; color: #7c3aed; }

	.fee-row {
		display: flex;
		justify-content: space-between;
		padding: 10rpx 0;
		border-bottom: 1rpx solid #f1f5f9;
	}
	.fee-row:last-child { border-bottom: none; }
	.fee-row .label {
		font-size: 24rpx;
		color: #94a3b8;
	}
	.fee-row .value {
		font-size: 24rpx;
		color: #1e293b;
	}
	.fee-row.discount .value { color: #ef4444; }
	.fee-row.total {
		padding-top: 16rpx;
		border-top: 2rpx solid #e8edf3;
	}
	.fee-row.total .label {
		font-size: 28rpx;
		font-weight: 600;
		color: #1e293b;
	}
	.fee-row.total .value.price {
		font-size: 32rpx;
		font-weight: 700;
		color: #4291F6;
	}

	.timeline-card .card-title {
		font-size: 26rpx;
		font-weight: 600;
		color: #1e293b;
		margin-bottom: 16rpx;
		display: block;
	}
	.timeline { padding-left: 4rpx; }
	.timeline-item {
		position: relative;
		padding-left: 40rpx;
		padding-bottom: 28rpx;
	}
	.timeline-item:last-child { padding-bottom: 0; }
	.timeline-dot {
		position: absolute;
		left: 0;
		top: 6rpx;
		width: 20rpx;
		height: 20rpx;
		border-radius: 50%;
		background: #d1d5db;
		border: 3rpx solid #e8edf3;
	}
	.timeline-dot.done {
		background: #4291F6;
		border-color: #4291F6;
	}
	.timeline-line {
		position: absolute;
		left: 9rpx;
		top: 32rpx;
		width: 2rpx;
		height: calc(100% - 28rpx);
		background: #e8edf3;
	}
	.timeline-item:last-child .timeline-line { display: none; }
	.timeline-content {
		display: flex;
		flex-direction: column;
		gap: 4rpx;
	}
	.timeline-title {
		font-size: 24rpx;
		color: #1e293b;
		font-weight: 500;
	}
	.timeline-time {
		font-size: 20rpx;
		color: #94a3b8;
	}

	.device-row {
		display: flex;
		justify-content: space-between;
		padding: 12rpx 0;
		border-bottom: 1rpx solid #f1f5f9;
	}
	.device-row:last-child { border-bottom: none; }
	.device-row .label {
		font-size: 24rpx;
		color: #94a3b8;
	}
	.device-row .value {
		font-size: 24rpx;
		color: #1e293b;
		font-weight: 500;
	}
	/* 「去使用这个技能」入口接在最后一个 device-row 之后,那条 :last-child 去边框的规则
	   就失效了(最后一个子元素变成了 .go-skills)。用 nth-last-child(2) 把上边框补回来,
	   避免和 .go-skills 的 border-top 叠成双线 */
	.device-card .device-row:nth-last-child(2) { border-bottom: none; }
	.go-skills {
		margin-top: 18rpx;
		padding-top: 18rpx;
		border-top: 1rpx solid #f1f5f9;
		font-size: 26rpx;
		color: #165DFF;
		text-align: right;
	}
	.go-skills:active { opacity: 0.6; }
	.sn-code {
		font-family: 'Courier New', monospace;
		letter-spacing: 2rpx;
	}

	.bottom-actions {
		position: fixed;
		bottom: 0;
		left: 0;
		right: 0;
		background: #ffffff;
		padding: 20rpx 24rpx;
		display: flex;
		gap: 16rpx;
		box-shadow: 0 -4rpx 16rpx rgba(0,0,0,0.04);
		z-index: 10;
		flex-wrap: wrap;
		justify-content: flex-end;
	}
	.bottom-actions .btn-outline,
	.bottom-actions .btn-primary {
		padding: 16rpx 28rpx;
		border-radius: 40rpx;
		font-size: 24rpx;
		font-weight: 500;
		text-align: center;
		cursor: pointer;
		flex-shrink: 0;
		min-width: 120rpx;
	}
	.bottom-actions .btn-outline:active,
	.bottom-actions .btn-primary:active { opacity: 0.7; }
	.bottom-actions .btn-outline {
		background: #f8faff;
		color: #1e293b;
		border: 1rpx solid #e8f0fe;
	}
	.bottom-actions .btn-primary {
		background: #4291F6;
		color: #ffffff;
		border: 1rpx solid #4291F6;
	}

	.empty-state {
		flex: 1;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
	}
	.empty-icon { font-size: 80rpx; }
	.empty-title {
		font-size: 32rpx;
		color: #1e293b;
		margin-top: 16rpx;
	}
	.empty-btn {
		margin-top: 24rpx;
		padding: 12rpx 40rpx;
		border-radius: 40rpx;
		background: #4291F6;
		color: #fff;
		font-size: 26rpx;
	}
</style>