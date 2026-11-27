<template>
	<view class="order-page">
		<!-- ===== 顶部标题 + 自定义方案入口 ===== -->
		<view class="page-header">
			<text class="page-title">我的订单</text>
		</view>

		<!-- ===== 统计卡片 ===== -->
		<view class="stats-row">
			<view class="stat-card" @click="filterByStatus('all')">
				<text class="stat-number">{{ getTotalCount() }}</text>
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
		<scroll-view class="tab-scroll" scroll-x="true" :enhanced="true" :show-scrollbar="false">
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
							<SmIcon v-if="!item.icon || item.icon === '🤖'" name="empty-robot" size="46rpx" />
							<SmIcon v-else-if="item.icon === '🧩'" name="empty-accessory" size="46rpx" />
							<text v-else class="robot-icon">{{ item.icon }}</text>
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

				<!-- 卡片底部：按真实订单状态分流操作 -->
				<view class="card-footer">
					<view class="btn-group" v-if="item.sceneWorkflow">
						<view class="btn-outline" @click.stop="contactService(item)">联系客服</view>
						<view class="btn-outline" @click.stop="reorder(item)">再次需求</view>
						<view class="btn-primary" @click.stop="viewDetail(item)">查看项目</view>
					</view>
					<view class="btn-group" v-else-if="item.status === 'pending'">
						<view class="btn-outline" @click.stop="cancelOrder(item)">取消订单</view>
						<view class="btn-outline" @click.stop="viewDetail(item)">查看详情</view>
						<view class="btn-primary" @click.stop="payOrder(item)">去付款</view>
					</view>
					<view class="btn-group" v-else-if="item.type === 'skill' && item.status !== 'cancelled'">
						<view class="btn-outline" @click.stop="viewDetail(item)">查看详情</view>
						<view class="btn-outline" @click.stop="contactService(item)">联系客服</view>
						<view class="btn-primary" @click.stop="goSkills()">去使用</view>
					</view>
					<view class="btn-group" v-else>
						<view class="btn-outline" @click.stop="viewDetail(item)">查看详情</view>
						<view class="btn-outline" @click.stop="contactService(item)">联系客服</view>
						<view v-if="item.productId || (item.rawItems && item.rawItems.length)" class="btn-primary" @click.stop="reorder(item)">再次下单</view>
					</view>
				</view>
			</view>

			<view class="load-more-bar" v-if="filteredOrders.length && hasMore" @click="loadMore">
				<text class="load-more-text">{{ loadingMore ? '正在加载更多订单…' : '点击加载更多订单' }}</text>
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
	import SmIcon from '@/components/SmIcon.vue';
	import { orderApi } from '@/utils/api';
	import { sceneStatusLabel } from '@/utils/scene-request';

	export default {
		components: {
			CustomTabBar,
			StateView,
			SmIcon
		},
		data() {
			return {
				currentStatus: 'all',
				loading: true,
				loadingMore: false,
				loadError: '',
				orders: [],
				serverCounts: null,
				guestPage: 1,
				guestHasMore: false,
				minePage: 1,
				mineHasMore: false,
				_loadSeq: 0,
				_pendingMineStatus: '',
			}
		},
		computed: {
			filteredOrders() {
				if (this.currentStatus === 'all') return this.orders
				return this.orders.filter(item => item.status === this.currentStatus)
			},
			hasMore() {
				return !!(this.guestHasMore || this.mineHasMore)
			},
		},
		onShow() {
			// 消费「我的-订单入口」传来的状态定位(读后即清),再刷新
			const status = uni.getStorageSync('orderStatus')
			if (status) {
				uni.removeStorageSync('orderStatus')
				if (status === 'processing') {
					this._pendingMineStatus = 'processing'
					this.currentStatus = 'confirm'
				} else if (['all', 'scene', 'pending', 'confirm', 'active', 'completed', 'cancelled'].includes(status)) {
					this._pendingMineStatus = ''
					this.currentStatus = status
				}
			}
			this.loadOrders(false)
		},
		onPullDownRefresh() {
			Promise.resolve(this.loadOrders(false)).finally(() => {
				if (typeof uni.stopPullDownRefresh === 'function') uni.stopPullDownRefresh()
			})
		},
		onReachBottom() {
			if (this.hasMore && !this.loading && !this.loadingMore) {
				this.loadOrders(true)
			}
		},
		methods: {
			switchStatus(status) { this.currentStatus = status },
			filterByStatus(status) { this.currentStatus = status },

			sumServerCounts(guestRes, mineRes) {
				const keys = ['total', 'scene', 'pending', 'confirm', 'active', 'completed', 'cancelled']
				const gCounts = guestRes && guestRes.counts && typeof guestRes.counts === 'object' ? guestRes.counts : null
				const mCounts = mineRes && mineRes.counts && typeof mineRes.counts === 'object' ? mineRes.counts : null
				if (!gCounts && !mCounts) return null
				const out = {}
				keys.forEach((k) => {
					out[k] = Number((gCounts && gCounts[k]) || 0) + Number((mCounts && mCounts[k]) || 0)
				})
				return out
			},

			mergeAndSortOrders(baseList, incomingList) {
				const byId = new Map()
				;(Array.isArray(baseList) ? baseList : []).forEach((item) => {
					if (item && item.id != null) byId.set(Number(item.id), item)
				})
				;(Array.isArray(incomingList) ? incomingList : []).forEach((item) => {
					if (item && item.id != null) byId.set(Number(item.id), item)
				})
				return [...byId.values()].sort((a, b) => {
					const ta = a.createdAt ? Date.parse(String(a.createdAt).replace(' ', 'T')) : 0
					const tb = b.createdAt ? Date.parse(String(b.createdAt).replace(' ', 'T')) : 0
					if (Number.isFinite(ta) && Number.isFinite(tb) && ta !== tb) return tb - ta
					return Number(b.id || 0) - Number(a.id || 0)
				})
			},

			loadMore() {
				if (!this.hasMore || this.loading || this.loadingMore) return
				return this.loadOrders(true)
			},

			// ================ 订单数据加载（以后端真实单为准） ================
			async loadOrders(append = false) {
				// 清理历史本地意向单(旧假数据);订单以后端 guest-orders 真实单为准
				uni.removeStorageSync('intentOrders')
				if (append && (this.loading || this.loadingMore || !this.hasMore)) return

				const seq = ++this._loadSeq
				if (append) {
					this.loadingMore = true
				} else {
					this.loading = true
					this.loadError = ''
					this.guestPage = 1
					this.minePage = 1
					this.guestHasMore = false
					this.mineHasMore = false
				}

				const hasToken = !!uni.getStorageSync('token')
				const nextGuestPage = append ? (this.guestPage + 1) : 1
				const nextMinePage = append ? (this.minePage + 1) : 1
				let guestRes = null
				let mineRes = null
				let batchOrders = []
				let fetchErr = null

				try {
					if (!append || this.guestHasMore) {
						guestRes = await orderApi.getGuestOrders({ page: nextGuestPage })
						const gList = (guestRes && Array.isArray(guestRes.data)) ? guestRes.data : []
						batchOrders = batchOrders.concat(gList.map(o => this.mapApiOrder(o)))
					}
					if (hasToken && (!append || this.mineHasMore)) {
						mineRes = await orderApi.getOrders({ page: nextMinePage })
						const mList = (mineRes && Array.isArray(mineRes.data)) ? mineRes.data : []
						batchOrders = batchOrders.concat(mList.map(o => this.mapApiOrder(o)))
					}
				} catch (e) {
					fetchErr = e
				}

				if (seq !== this._loadSeq) return

				if (fetchErr) {
					if (append) {
						this.loadingMore = false
						uni.showToast({ title: (fetchErr && fetchErr.message) || '加载更多失败，请重试', icon: 'none' })
						return
					}
					// ⚠ 故障 ≠「暂无订单」:置错误标记交给 StateView 的错误态(带重试)
					this.loadError = (fetchErr && fetchErr.message) || '加载失败'
					this.orders = []
					this.serverCounts = null
					this.loading = false
					return
				}

				if (guestRes) {
					this.guestPage = Number(guestRes.current || nextGuestPage)
					const last = Number(guestRes.last_page || 1)
					this.guestHasMore = typeof guestRes.has_more === 'boolean' ? guestRes.has_more : (this.guestPage < last)
				}
				if (mineRes) {
					this.minePage = Number(mineRes.current || nextMinePage)
					const last = Number(mineRes.last_page || 1)
					this.mineHasMore = typeof mineRes.has_more === 'boolean' ? mineRes.has_more : (this.minePage < last)
				} else if (!hasToken) {
					this.mineHasMore = false
				}

				const merged = append ? this.mergeAndSortOrders(this.orders, batchOrders) : this.mergeAndSortOrders([], batchOrders)
				this.orders = merged
				if (!append) {
					this.serverCounts = this.sumServerCounts(guestRes, mineRes)
				}

				if (this._pendingMineStatus === 'processing') {
					if (this.getCountByStatus('confirm') === 0 && this.getCountByStatus('active') > 0) {
						this.currentStatus = 'active'
					}
					this._pendingMineStatus = ''
				}

				this.loading = false
				this.loadingMore = false
				// 拉取失败时**不**把空数组写进缓存 —— 否则一次网络抖动会清掉订单缓存,
				// 订单详情页(读 orderList 兜底)也跟着查不到单。
				uni.setStorageSync('orderList', merged)
				uni.removeStorageSync('orderNeedRefresh')
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
				const textMap = {
					pending: '待付款',
					confirm: '待确认',
					execute: '待执行',
					active: '服务中',
					completed: '已完成',
					cancelled: '已取消',
				}
				const fulfillmentStatus = o.fulfillment_status || (o.fulfillment && o.fulfillment.status) || ''
				let status = o.scene_workflow ? 'scene' : ((o.order_type === 'custom') ? 'completed' : (statusMap[o.status] || 'active'))
				let statusText = o.scene_workflow
					? sceneStatusLabel(o.scene_workflow.state) + ' · ' + sceneStatusLabel(o.scene_workflow.payment_state)
					: (textMap[status] || '处理中')

				if (!o.scene_workflow && o.order_type !== 'custom' && o.status === 'processing' && fulfillmentStatus) {
					if (fulfillmentStatus === 'shipped') {
						status = 'active'
						statusText = '已发货 · 服务中'
					} else if (fulfillmentStatus === 'in_use') {
						status = 'active'
						statusText = '服务中'
					} else if (['returning', 'returned', 'returned_inspecting'].includes(fulfillmentStatus)) {
						status = 'active'
						statusText = '归还待验机'
					} else if (fulfillmentStatus === 'pending_shipment') {
						status = 'confirm'
						statusText = '待发货'
					} else if (fulfillmentStatus === 'completed') {
						status = 'completed'
						statusText = '已完成'
					} else if (fulfillmentStatus === 'cancelled') {
						status = 'cancelled'
						statusText = '已取消'
					}
				}
				const firstItem = (o.items && o.items[0]) || {}
				const sr = (o.scene_rental && typeof o.scene_rental === 'object')
					? o.scene_rental
					: ((o.rental_info && typeof o.rental_info === 'object') ? o.rental_info : null)
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
				const scene = (sr && sr.scene_name) || (firstRaw.split(' · ').length > 1 ? firstRaw.split(' · ')[0] : (o.scene || ''))
				const rangeMatch = firstRaw.match(/[（(](\d{4}-\d{2}-\d{2})~(\d{4}-\d{2}-\d{2})[）)]/)
				const daysMatch = firstRaw.match(/[（(](?:租期)?(\d+)天[）)]/)
				const rentDays = (sr && Number(sr.rent_days)) || (daysMatch ? Number(daysMatch[1]) : 0)
				const startDate = (sr && sr.start_date) || (rangeMatch ? rangeMatch[1] : '')
				const endDate = (sr && sr.end_date) || (rangeMatch ? rangeMatch[2] : '')
				const duration = startDate && endDate
					? `${startDate} ~ ${endDate}`
					: (rentDays > 0 ? `${rentDays}天` : '')
				return {
					id: o.id,
					isGuest: !!o.is_guest,
					createdAt: o.created_at || '',
					productId: firstItem.product_id || '',
					orderNo: o.increment_id || `SO${o.id}`,
					name: name || '机器人订单',
					icon: o.order_type === 'accessory' ? '🧩' : '🤖',
					bg: '#F0F4FA',
					// 真实类型(后端 order_type):页签/详情分类显示的数据源
					type: o.order_type || 'buy',
					typeText: ({rent:'租赁',buy:'购买',plan:'方案',custom:'定制',skill:'技能',accessory:'配件'})[o.order_type] || '购买',
					typeClass: o.order_type || 'buy',
					scene: scene || '',
					duration,
					rentDays,
					startDate,
					endDate,
					sceneRental: sr,
					rawItems: Array.isArray(o.items) ? o.items : [],
					quantity: o.items_qty || (Array.isArray(o.items) ? o.items.reduce((s, i) => s + (Number(i.qty_ordered) || 0), 0) : 0) || 1,
					amount,
					status,
					statusText,
					statusClass: o.scene_workflow ? 'confirm' : status,
					fulfillmentStatus,
					carrier: o.carrier || (o.fulfillment && o.fulfillment.carrier) || '',
					trackingNo: o.tracking_no || (o.fulfillment && o.fulfillment.tracking_no) || '',
					deliveryMethod: o.delivery_method || '',
					snCode: o.sn_code || '',
					sceneWorkflow: o.scene_workflow || null,
				}
			},

			// 按订单类型动态生成展示字段（租用/购买/方案/技能/定制），代码逻辑区分而非硬写
			orderFields(item) {
				const t = item.type || 'buy'
				const rows = []
				if (t === 'skill') {
					rows.push({ label: '交付方式', value: item.deliveryMethod || '授权交付' })
					rows.push({ label: '设备SN', value: item.snCode || '待指派' })
				} else {
					rows.push({ label: '使用场景', value: item.scene || '—' })
					if (t === 'rent') {
						const duration = item.duration || (item.startDate ? `${item.startDate} ~ ${item.endDate || ''}` : '—')
						rows.push({ label: '租期', value: duration })
						rows.push({ label: '租用数量', value: `${item.quantity} 台` })
					} else if (t === 'plan') {
						if (item.sceneRental || item.rentDays > 0 || item.startDate) {
							const duration = item.startDate && item.endDate
								? `${item.startDate} ~ ${item.endDate}（${item.rentDays || 1}天）`
								: (item.rentDays > 0 ? `${item.rentDays}天` : (item.duration || '—'))
							rows.push({ label: '租期', value: duration })
						}
						rows.push({ label: '方案台数', value: `${item.quantity} 台` })
					} else if (t === 'custom') {
						rows.push({ label: '定制数量', value: `${item.quantity} 台` })
					} else {
						// buy / accessory：购买不需要租期
						rows.push({ label: '数量', value: `${item.quantity} 件` })
					}
					if (item.trackingNo) {
						rows.push({ label: '物流单号', value: [item.carrier, item.trackingNo].filter(Boolean).join(' · ') })
					}
				}
				rows.push({ label: '订单金额', value: `¥${item.amount}`, highlight: true, className: 'price' })
				return rows
			},

			getTotalCount() {
				if (this.serverCounts && Number.isFinite(Number(this.serverCounts.total)) && !this.hasMore) {
					return this.orders.length
				}
				if (this.serverCounts && Number.isFinite(Number(this.serverCounts.total))) {
					return Number(this.serverCounts.total)
				}
				return this.orders.length
			},
			getCountByStatus(status) {
				if (this.serverCounts && this.hasMore && Object.prototype.hasOwnProperty.call(this.serverCounts, status)) {
					return Number(this.serverCounts[status] || 0)
				}
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
			payOrder(item) {
				if (!item || !item.id) return
				uni.setStorageSync('orderDetailData', item)
				uni.navigateTo({
					url: `/pages/order/order-detail?id=${item.id}&pay=1`
				})
			},
			cancelOrder(item) {
				if (!item || !item.id) return
				uni.showModal({
					title: '取消订单',
					content: `确认取消订单「${item.name || item.orderNo}」吗？`,
					success: (res) => {
						if (!res.confirm) return
						const req = (!uni.getStorageSync('token') || item.isGuest)
							? orderApi.cancelGuestOrder(item.id)
							: orderApi.cancelOrder(item.id)
						req
							.then((r) => {
								if (r && r.success === false) {
									uni.showToast({ title: r.message || '取消失败', icon: 'none' })
									return
								}
								uni.showToast({ title: (r && r.message) || '订单已取消', icon: 'success' })
								uni.setStorageSync('orderNeedRefresh', Date.now())
								this.orders = this.orders.map(o => Number(o.id) === Number(item.id)
									? { ...o, status: 'cancelled', statusText: '已取消', statusClass: 'cancelled' }
									: o)
								if (this.serverCounts) {
									this.serverCounts = {
										...this.serverCounts,
										pending: Math.max(0, Number(this.serverCounts.pending || 0) - 1),
										cancelled: Number(this.serverCounts.cancelled || 0) + 1,
									}
								}
								uni.setStorageSync('orderList', this.orders)
							})
							.catch((err) => uni.showToast({ title: (err && err.message) || '取消失败', icon: 'none' }))
					}
				})
			},
			goSkills() {
				uni.navigateTo({ url: '/pages/mine/skills' })
			},
			contactService(item) {
				if (!item) return
				uni.setStorageSync('supportProductContext', {
					type: 'order',
					order_id: item.id,
					increment_id: String(item.orderNo || item.id || ''),
					name: item.name || `订单 #${item.orderNo || item.id}`,
					price: String(item.amount || item.actualAmount || ''),
					status_label: item.statusText || '',
				})
				if (!uni.getStorageSync('token')) {
					uni.showModal({
						title: '联系客服',
						content: '提交订单咨询工单需要先登录，前往「我的」登录？',
						confirmText: '去登录',
						success: (r) => { if (r.confirm) uni.switchTab({ url: '/pages/mine/index' }) },
					})
					return
				}
				uni.navigateTo({
					url: '/pages/mine/tickets?orderContext=1&category=payment'
				})
			},
			reorder(item) {
				if (item.sceneWorkflow) { uni.navigateTo({url:'/pages/scene/request?source=scene'+(item.sceneWorkflow.scene_id?'&sceneId='+item.sceneWorkflow.scene_id:'')}); return }
				if (item.type === 'skill' && item.productId) {
					uni.navigateTo({ url: `/pages/home/jineng?skillId=${item.productId}&productId=${item.productId}` })
					return
				}
				const sr = item.sceneRental
				if ((sr && sr.scene_id && Array.isArray(item.rawItems) && item.rawItems.length) || ((item.type === 'rent' || item.type === 'plan') && Array.isArray(item.rawItems) && item.rawItems.length > 1)) {
					const rentDays = Math.max(1, Number((sr && sr.rent_days) || item.rentDays) || 1)
					const mappedItems = item.rawItems.map((it) => {
						const itemSr = (it && it.additional && (it.additional.scene_rental || it.additional.rental)) || {}
						const unitDaily = Number(itemSr.daily_price || itemSr.unit_daily_rent_yuan) || Math.round((Number(it.price) || 0) / rentDays)
						const cleanName = String((it && it.name) || '机器人').split(' · ').pop().replace(/【[^】]*】|[（(][^）)]*[）)]/g, '').trim()
						return {
							id: it.product_id,
							product_id: it.product_id,
							name: cleanName || '机器人',
							price: unitDaily,
							qty: Math.max(1, Number(it.qty_ordered) || 1),
							scene: item.scene || (sr && sr.scene_name) || '机器租用',
							min_rent_days: Math.max(1, Number(itemSr.min_rent_days) || 1),
							max_rent_days: Math.max(0, Number(itemSr.max_rent_days) || 0),
							deposit_yuan: Math.max(0, Number(itemSr.deposit_yuan) || 0),
						}
					}).filter(it => it.product_id)
					if (mappedItems.length) {
						const params = {
							robotName: item.name,
							productId: mappedItems[0].product_id,
							scene: item.scene || (sr && sr.scene_name) || '',
							from: (sr && sr.scene_id) ? 'scene' : '',
							sceneId: (sr && sr.scene_id) || '',
							sceneRevisionId: (sr && sr.scene_revision_id) || null,
							quantity: item.quantity,
							rentDays,
							rentPeriod: `${rentDays}天`,
							startDate: item.startDate || (sr && sr.start_date) || '',
							endDate: item.endDate || (sr && sr.end_date) || '',
							amount: mappedItems[0].price,
							type: 'rent',
							items: mappedItems,
						}
						uni.navigateTo({ url: `/pages/order/checkout?data=${encodeURIComponent(JSON.stringify(params))}` })
						return
					}
				}
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
					rentPeriod: item.rentDays ? `${item.rentDays}天` : (item.duration || ''),
					startDate: item.startDate || '',
					endDate: item.endDate || '',
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
		background-color: #F4F7FB;
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
		height: 360rpx;
		background: linear-gradient(180deg, #3F89F8 0%, #8FC3FF 42%, #ECF5FF 76%, #FFFFFF 90%, #F4F7FB 100%);
		z-index: 0;
		pointer-events: none;
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
		color: #0F172A;
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
		scrollbar-width: none;
		-ms-overflow-style: none;
	}
	.tab-scroll::-webkit-scrollbar {
		display: none;
		width: 0;
		height: 0;
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
	.type-badge.skill { background: #eef4ff; color: #165dff; }
	.type-badge.accessory { background: #e0f2fe; color: #0369a1; }
	.load-more-bar {
		padding: 20rpx 0 12rpx;
		text-align: center;
	}
	.load-more-text {
		font-size: 23rpx;
		color: #2563eb;
		padding: 10rpx 28rpx;
		background: #ffffff;
		border: 1rpx solid #dbeafe;
		border-radius: 999rpx;
		display: inline-block;
	}
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

<style>
::-webkit-scrollbar,
page::-webkit-scrollbar,
scroll-view::-webkit-scrollbar,
uni-scroll-view::-webkit-scrollbar,
uni-scroll-view .uni-scroll-view::-webkit-scrollbar,
.uni-scroll-view::-webkit-scrollbar {
	width: 0 !important;
	height: 0 !important;
	display: none !important;
}
page,
scroll-view,
uni-scroll-view,
.uni-scroll-view {
	scrollbar-width: none !important;
	-ms-overflow-style: none !important;
}
</style>
