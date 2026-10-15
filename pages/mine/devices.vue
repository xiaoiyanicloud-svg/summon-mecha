<template>
	<view class="devices-page">
		<!-- 导航栏 -->
		<view class="nav-bar">
			<view class="nav-back" @click="goBack">
				<text class="back-icon">‹</text>
			</view>
			<text class="nav-title">我的设备</text>
			<view class="nav-placeholder"></view>
		</view>

		<!-- 未登录:项目没有独立登录页,登录在「我的」页面内完成 -->
		<view v-if="!loggedIn" class="empty-state">
			<text class="empty-icon">🔒</text>
			<text class="empty-text">请先在「我的」页面登录</text>
			<view class="primary-btn" @click="goBack">返回</view>
		</view>

		<scroll-view v-else class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- v3.4:安装 Agent(一键配对引导) -->
			<view class="card agent-entry" @click="goAgentSetup">
				<view class="agent-entry-main">
					<text class="agent-entry-title">给机器人安装 Agent</text>
					<text class="agent-entry-sub">装一次，之后就能一键下发技能</text>
				</view>
				<text class="agent-entry-arrow">›</text>
			</view>

			<!-- 新增设备 -->
			<view class="card">
				<text class="card-title">登记一台设备</text>
				<input class="input" v-model="form.sn" placeholder="设备 SN（序列号）" placeholder-class="ph" />
				<input class="input" v-model="form.name" placeholder="备注名，如「车间1号臂」" placeholder-class="ph" />
				<view class="model-picker" @click="openModelPicker">
					<text class="model-label">机型</text>
					<text class="model-value">{{ selectedModelName || '未选择' }}</text>
					<text class="model-arrow">›</text>
				</view>
				<view class="primary-btn" :class="{ disabled: saving }" @click="submitRegister">
					{{ saving ? '登记中…' : '登记设备' }}
				</view>
				<view class="notice">
					<text class="notice-title">关于「登记」</text>
					<text class="notice-body">
						平台目前<b>没有办法验证这台机器真实存在</b> —— 机器人是第三方成品，厂家没有给我们查设备的接口。
						所以这里登记的信息只用于你自己管理，会标注为「未核验」，不代表平台已经连上了你的机器人。
					</text>
				</view>
			</view>

			<!-- 设备列表 -->
			<!-- 三态:加载中 / 故障(带重试) / 真·还没有登记设备。故障不再伪装成「还没有设备」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!devices.length"
				empty-icon="🔧"
				empty-text="还没有登记任何设备"
				@retry="refresh"
			>
				<view class="card device-card" v-for="d in devices" :key="d.id">
					<view class="device-head">
						<view class="device-info">
							<view class="device-sn-row">
								<text class="device-sn">{{ d.sn }}</text>
								<!-- 核验状态如实展示:平台没验证过就是「未核验」,不能含糊 -->
								<text class="verify-badge" :class="d.verify_status">{{ d.verify_label || '未核验' }}</text>
								<!-- Agent 配对态:与「核验」是两回事 —— 这是机器真跑起过 Agent -->
								<text class="agent-badge" :class="{ online: d.agent_online }" v-if="d.agent_paired">
									{{ d.agent_online ? 'Agent 在线' : 'Agent 已配对' }}
								</text>
							</view>
							<text class="device-name">{{ d.display_name || '未命名设备' }}</text>
						</view>
						<text class="remove" @click="removeDevice(d)">移除</text>
					</view>

					<view class="device-meta">
						<text class="meta-item">机型：{{ (d.product && d.product.name) || '未选择' }}</text>
						<text class="meta-item">登记于 {{ d.bound_at || '—' }}</text>
					</view>

					<!-- 这台设备已获得的技能 -->
					<view class="skill-list" v-if="d.skills && d.skills.length">
						<text class="skill-title">已获得技能</text>
						<view class="skill-item" v-for="s in d.skills" :key="s.id">
							<text class="skill-name">{{ s.name }}</text>
							<text class="skill-status" :class="s.status">{{ statusText(s.status) }}</text>
						</view>
					</view>
					<text v-else class="no-skill">这台设备还没有技能</text>
				</view>

				<!-- 待指派设备的技能授权 -->
				<view class="card" v-if="pendingSkills.length">
					<text class="card-title">待指派的技能</text>
					<text class="hint">这些技能已开通，选一台设备即可使用。</text>
					<view class="pending-item" v-for="e in pendingSkills" :key="e.id">
						<text class="skill-name">{{ e.name }}</text>
						<view class="pending-actions">
							<text class="bind-one" v-for="d in devices" :key="d.id" @click="assignSkill(e, d)">
								指派给 {{ d.sn }}
							</text>
						</view>
					</view>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- 机型选择 -->
		<view class="mask" v-if="showModelPicker" @click="showModelPicker = false"></view>
		<view class="picker" v-if="showModelPicker">
			<view class="picker-head">
				<text class="picker-cancel" @click="showModelPicker = false">取消</text>
				<text class="picker-title">选择机型</text>
				<text class="picker-ok" @click="showModelPicker = false">确定</text>
			</view>
			<picker-view class="picker-body" :value="modelIndex" @change="onModelChange">
				<picker-view-column>
					<view class="picker-item" v-for="(m, i) in modelOptions" :key="i">{{ m.name }}</view>
				</picker-view-column>
			</picker-view>
		</view>
	</view>
</template>

<script>
import { deviceApi, skillApi, catalogApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import StateView from '@/components/StateView.vue'

const STATUS_TEXT = {
	granted: '已开通',
	active: '使用中',
	pending_bind: '待指派',
	expired: '已过期',
	revoked: '已撤销',
}

export default {
	components: { StateView },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			saving: false,
			devices: [],
			skills: [],
			form: { sn: '', name: '' },
			modelOptions: [{ id: null, name: '未选择' }],
			modelIndex: [0],
			showModelPicker: false,
		}
	},
	computed: {
		selectedModel() {
			return this.modelOptions[this.modelIndex[0]] || this.modelOptions[0]
		},
		selectedModelName() {
			const m = this.selectedModel
			return m && m.id ? m.name : ''
		},
		// 还没有挂到任何设备上的技能
		pendingSkills() {
			return (this.skills || []).filter(s => !s.device_id)
		},
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		// 从「我的」页登录后返回本页时,onShow 会再次触发,这里重拉即可
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	methods: {
		goBack() {
			uni.navigateBack({ delta: 1 })
		},
		// v3.4:跳到 Agent 安装引导
		goAgentSetup() { uni.navigateTo({ url: '/pages/mine/agent-setup' }) },
		statusText(s) {
			return STATUS_TEXT[s] || s
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			await Promise.all([this.loadDevices(), this.loadSkills(), this.loadModels()])
			this.loading = false
		},
		async loadDevices() {
			try {
				const res = await deviceApi.list()
				this.devices = (res && Array.isArray(res.data)) ? res.data : []
			} catch (e) {
				// ⚠ 故障 ≠「还没有登记设备」:置错误标记交给 StateView 的错误态(带重试)
				this.loadError = (e && e.message) || '加载失败'
				this.devices = []
			}
		},
		async loadSkills() {
			try {
				const res = await skillApi.mine()
				this.skills = (res && Array.isArray(res.data)) ? res.data : []
			} catch (e) {
				// 技能只影响「待指派」区块,拉不到不阻断设备列表 —— 留 warn 不静默
				console.warn('[devices] 技能授权加载失败', e)
				this.skills = []
			}
		},
		// 机型来自商品目录(rent/buy 两类),失败就只留「未选择」
		async loadModels() {
			try {
				const res = await catalogApi.getAggregate()
				const data = (res && res.data) || {}
				const list = [...(data.buy || []), ...(data.rent || [])]
				const seen = new Set()
				const options = [{ id: null, name: '未选择' }]
				list.forEach(p => {
					if (p && p.id && !seen.has(p.id)) {
						seen.add(p.id)
						options.push({ id: p.id, name: p.name || `商品${p.id}` })
					}
				})
				this.modelOptions = options
			} catch (e) {
				// 目录拉不到不影响登记,机型是可选项 —— 但留 warn,别静默
				console.warn('[devices] 机型目录加载失败', e)
			}
		},
		openModelPicker() {
			this.showModelPicker = true
		},
		onModelChange(e) {
			this.modelIndex = e.detail.value
		},
		async submitRegister() {
			const sn = (this.form.sn || '').trim()
			if (!sn) {
				uni.showToast({ title: '请填写设备 SN', icon: 'none' })
				return
			}
			if (this.saving) return
			this.saving = true
			try {
				await deviceApi.register({
					sn,
					product_id: this.selectedModel.id || null,
					display_name: (this.form.name || '').trim() || null,
				})
				uni.showToast({ title: '已登记', icon: 'success' })
				this.form = { sn: '', name: '' }
				this.modelIndex = [0]
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '登记失败', icon: 'none' })
			} finally {
				this.saving = false
			}
		},
		removeDevice(d) {
			uni.showModal({
				title: '移除设备',
				content: `确定移除 ${d.sn} 吗？已指派给它的技能会回到「待指派」状态。`,
				success: async res => {
					if (!res.confirm) return
					try {
						await deviceApi.remove(d.id)
						uni.showToast({ title: '已移除', icon: 'success' })
						await this.refresh()
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '移除失败', icon: 'none' })
					}
				},
			})
		},
		async assignSkill(entitlement, device) {
			try {
				await skillApi.assignDevice(entitlement.id, device.id)
				uni.showToast({ title: '已指派', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '指派失败', icon: 'none' })
			}
		},
	},
}
</script>

<style scoped>
.devices-page {
	min-height: 100vh;
	background: #F7F8FA;
	display: flex;
	flex-direction: column;
}
.nav-bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: calc(80rpx + env(safe-area-inset-top)) 30rpx 20rpx;
	background: #FFFFFF;
}
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #1A2A3A; line-height: 1; }
.nav-title { font-size: 34rpx; font-weight: 600; color: #1A2A3A; }
.nav-placeholder { width: 60rpx; }

.scroll-content { flex: 1; height: 0; padding: 20rpx 30rpx; box-sizing: border-box; }

.card {
	background: #FFFFFF;
	border-radius: 20rpx;
	padding: 28rpx;
	margin-bottom: 20rpx;
	box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.card-title { display: block; font-size: 30rpx; font-weight: 600; color: #1A2A3A; margin-bottom: 20rpx; }

/* v3.4:安装 Agent 入口 */
.agent-entry { display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #fff5ee, #ffffff); border: 1rpx solid #ffe0c8; }
.agent-entry-main { flex: 1; }
.agent-entry-title { display: block; font-size: 30rpx; font-weight: 600; color: #d2691e; }
.agent-entry-sub { display: block; margin-top: 8rpx; font-size: 24rpx; color: #8A9AA8; }
.agent-entry-arrow { font-size: 44rpx; color: #d2691e; }

.input {
	height: 84rpx;
	background: #F8FAFC;
	border: 1rpx solid #E5E7EB;
	border-radius: 14rpx;
	padding: 0 22rpx;
	font-size: 28rpx;
	color: #1A2A3A;
	margin-bottom: 16rpx;
}
.ph { color: #9CA3AF; }

.model-picker {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 84rpx;
	background: #F8FAFC;
	border: 1rpx solid #E5E7EB;
	border-radius: 14rpx;
	padding: 0 22rpx;
	margin-bottom: 20rpx;
}
.model-label { font-size: 28rpx; color: #6B7280; }
.model-value { flex: 1; text-align: right; font-size: 28rpx; color: #1A2A3A; margin-right: 10rpx; }
.model-arrow { font-size: 32rpx; color: #C0C8D2; }

.primary-btn {
	height: 84rpx;
	line-height: 84rpx;
	text-align: center;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 30rpx;
	font-weight: 600;
	border-radius: 42rpx;
}
.primary-btn.disabled { opacity: 0.6; }
.hint { display: block; font-size: 22rpx; color: #9CA3AF; margin-top: 16rpx; line-height: 1.5; }

.empty-state { display: flex; flex-direction: column; align-items: center; padding: 120rpx 40rpx; }
.empty-icon { font-size: 76rpx; margin-bottom: 20rpx; }
.empty-text { font-size: 28rpx; color: #8A9AA8; margin-bottom: 30rpx; }
.empty-state .primary-btn { width: 300rpx; }

.device-head { display: flex; align-items: flex-start; justify-content: space-between; }
.device-info { flex: 1; min-width: 0; }
.device-sn-row { display: flex; align-items: center; }
.device-sn { font-size: 32rpx; font-weight: 600; color: #1A2A3A; }
.verify-badge {
	font-size: 20rpx;
	padding: 4rpx 12rpx;
	border-radius: 16rpx;
	margin-left: 12rpx;
	background: #FFF4E5;
	color: #B26A00;
}
.verify-badge.verified { background: #E6F4EA; color: #1E8E3E; }
/* Agent 已配对(灰)→ 在线(绿)。真机跑起 Agent 才会出现 */
.agent-badge {
	font-size: 20rpx;
	padding: 4rpx 12rpx;
	border-radius: 16rpx;
	margin-left: 12rpx;
	background: #EEF1F5;
	color: #5A6B7B;
}
.agent-badge.online { background: #E6F4EA; color: #1E8E3E; }
.device-name { display: block; font-size: 24rpx; color: #8A9AA8; margin-top: 6rpx; }
.remove { font-size: 24rpx; color: #FF4D4F; padding: 8rpx 0 8rpx 20rpx; }

/* 关于「登记」的说明 —— 平台无法核验,必须让用户知道,不能含糊成"已绑定" */
.notice {
	margin-top: 20rpx;
	background: #FFFBF0;
	border: 1rpx solid #FFE7B3;
	border-radius: 14rpx;
	padding: 18rpx;
}
.notice-title { display: block; font-size: 24rpx; font-weight: 600; color: #B26A00; margin-bottom: 8rpx; }
.notice-body { display: block; font-size: 22rpx; color: #6B5A38; line-height: 1.7; }

.device-meta { margin-top: 16rpx; }
.meta-item { display: block; font-size: 24rpx; color: #6B7280; line-height: 1.8; }

.skill-list { margin-top: 20rpx; padding-top: 16rpx; border-top: 1rpx solid #EFF3F8; }
.skill-title { display: block; font-size: 24rpx; color: #8A9AA8; margin-bottom: 12rpx; }
.skill-item { display: flex; align-items: center; justify-content: space-between; padding: 10rpx 0; }
.skill-name { font-size: 27rpx; color: #1A2A3A; }
.skill-status { font-size: 22rpx; padding: 4rpx 14rpx; border-radius: 20rpx; background: #EEF2F6; color: #6B7280; }
.skill-status.granted { background: #E6F4EA; color: #1E8E3E; }
.skill-status.pending_bind { background: #FFF4E5; color: #B26A00; }
.no-skill { display: block; font-size: 24rpx; color: #B0BAC5; margin-top: 18rpx; }

.pending-item { padding: 16rpx 0; border-bottom: 1rpx solid #EFF3F8; }
.pending-item:last-child { border-bottom: none; }
.pending-actions { display: flex; flex-wrap: wrap; margin-top: 12rpx; }
.bind-one {
	font-size: 24rpx;
	color: #165DFF;
	background: #EEF4FF;
	border-radius: 24rpx;
	padding: 8rpx 22rpx;
	margin: 0 12rpx 12rpx 0;
}

.mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.45); z-index: 900; }
.picker {
	position: fixed;
	left: 0; right: 0; bottom: 0;
	background: #FFFFFF;
	border-radius: 24rpx 24rpx 0 0;
	z-index: 901;
	padding-bottom: env(safe-area-inset-bottom);
}
.picker-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	height: 96rpx;
	padding: 0 30rpx;
	border-bottom: 1rpx solid #EFF3F8;
}
.picker-cancel { font-size: 28rpx; color: #8A9AA8; }
.picker-title { font-size: 30rpx; font-weight: 600; color: #1A2A3A; }
.picker-ok { font-size: 28rpx; color: #165DFF; }
.picker-body { height: 420rpx; }
.picker-item { line-height: 80rpx; text-align: center; font-size: 30rpx; color: #1A2A3A; }
</style>
