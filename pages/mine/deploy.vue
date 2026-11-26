<template>
	<view class="deploy-page">
		<nav-bar :title="taskNo ? '下发任务进度' : '设备下发记录'" @back="onNavBack" />

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可查看技能下发、卸载、回退与执行的实时进度"
			action-text="返回登录"
			@action="goMine"
		/>

		<scroll-view v-else class="scroll-content" scroll-y>
			<!-- 当前焦点任务实时追踪卡 -->
			<view v-if="taskNo" class="card focus-card">
				<view class="focus-head">
					<view class="focus-left">
						<text class="op-badge">{{ task.operation_label || '指令下发' }}</text>
						<text class="skill-name">{{ skillName || task.skill_name || '机器人技能任务' }}</text>
					</view>
					<text class="task-no sm-mono">{{ taskNo }}</text>
				</view>

				<view class="steps">
					<view
						v-for="(s, i) in dynamicSteps"
						:key="s.key"
						class="step"
						:class="{ done: stepIndex > i, active: stepIndex === i && !isFailed, fail: isFailed && stepIndex === i }"
					>
						<view class="dot" />
						<text class="step-label">{{ s.label }}</text>
						<view v-if="i < dynamicSteps.length - 1" class="line" :class="{ done: stepIndex > i }" />
					</view>
				</view>

				<view class="status-box">
					<text class="status-text" :class="{ err: isFailed, ok: task.status === 'success' }">{{ statusLabel }}</text>
					<text v-if="task.device_sn" class="sub-meta sm-mono">目标设备：{{ task.device_sn }}</text>
					<text v-if="task.error_msg" class="err-msg">{{ task.error_msg }}</text>
				</view>

				<view class="actions">
					<view v-if="!terminal && !canceling" class="btn-outline danger" @tap="cancel">取消当前任务</view>
					<view class="btn-solid" @tap="goSkills">前往我的技能</view>
				</view>
			</view>

			<!-- 历史任务台账 -->
			<view class="section-head">
				<text class="section-title">最近下发与运维记录</text>
				<text class="refresh-link" @tap="loadHistory">刷新</text>
			</view>

			<state-view
				:loading="loadingList"
				:error="listError"
				:empty="!history.length"
				icon="bolt"
				title="暂无下发与运维任务记录"
				hint="在「我的技能」中指派设备并点击下发后，所有安装、回退与卸载记录均在此留痕"
				action-text="前往我的技能"
				@action="goSkills"
				@retry="loadHistory"
			>
				<view
					v-for="item in history"
					:key="item.task_no"
					class="card hist-card"
					:class="{ selected: item.task_no === taskNo }"
					@tap="inspectTask(item)"
				>
					<view class="hist-top">
						<view class="hist-tags">
							<text class="op-pill">{{ item.operation_label || '下发' }}</text>
							<text class="hist-skill">{{ item.skill_name || `技能 #${item.skill_id}` }}</text>
						</view>
						<text class="st-pill" :class="'st-' + item.status">{{ statusTextOf(item.status) }}</text>
					</view>
					<view class="hist-meta sm-mono">
						<text>设备：{{ item.device_sn || `#${item.device_id}` }}</text>
						<text>单号：{{ item.task_no }}</text>
					</view>
					<view class="hist-foot sm-mono">
						<text>{{ item.created_at || '—' }}</text>
						<text class="inspect-link">查看实时状态 ›</text>
					</view>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { deployApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

const STATUS_TEXT = {
	pending: '队列等待中',
	pending_online: '设备离线，待上线自动投递',
	dispatching: '指令投递中',
	downloading: '端侧验签拉包中',
	installing: '端侧执行处理中',
	success: '执行成功并已回执',
	failed: '执行失败',
	timeout: '响应超时',
	cancelled: '已取消',
}

export default {
	components: { NavBar, StateView },
	data() {
		return {
			loggedIn: false,
			taskNo: '',
			skillName: '',
			task: {},
			timer: null,
			canceling: false,
			loadingList: false,
			listError: '',
			history: [],
		}
	},
	computed: {
		dynamicSteps() {
			const op = Number(this.task.operation || 1)
			const actionWord = op === 2 ? '卸载中' : (op === 3 ? '回退中' : (op === 4 ? '启用中' : (op === 5 ? '停用中' : '安装中')))
			if (this.task.status === 'pending_online') {
				return [
					{ key: 'pending_online', label: '待设备上线' },
					{ key: 'dispatching', label: '指令投递' },
					{ key: 'installing', label: actionWord },
					{ key: 'success', label: '完成' },
				]
			}
			return [
				{ key: 'dispatching', label: '指令投递' },
				{ key: 'downloading', label: op === 1 ? '拉包验签' : '端侧校验' },
				{ key: 'installing', label: actionWord },
				{ key: 'success', label: '完成' },
			]
		},
		stepIndex() {
			const st = this.task.status
			if (st === 'success') return this.dynamicSteps.length
			const i = this.dynamicSteps.findIndex(s => s.key === st)
			return i < 0 ? 0 : i
		},
		terminal() {
			return ['success', 'failed', 'timeout', 'cancelled'].includes(this.task.status)
		},
		isFailed() {
			return ['failed', 'timeout', 'cancelled'].includes(this.task.status)
		},
		statusLabel() {
			return STATUS_TEXT[this.task.status] || '正在同步端侧回执…'
		},
	},
	onLoad(options) {
		this.taskNo = (options && options.taskNo) || ''
		this.skillName = options && options.skillName ? decodeURIComponent(options.skillName) : ''
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (!this.loggedIn) return
		this.loadHistory()
		if (this.taskNo) this.startPolling()
	},
	onHide() { this.stopPolling() },
	onUnload() { this.stopPolling() },
	onPullDownRefresh() {
		Promise.all([
			this.loadHistory(),
			this.taskNo ? this.poll() : Promise.resolve(),
		]).finally(() => uni.stopPullDownRefresh())
	},
	methods: {
		onNavBack() {
			const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
			if (pages.length <= 1) uni.switchTab({ url: '/pages/mine/index' })
			else uni.navigateBack({ delta: 1 })
		},
		goMine() { uni.switchTab({ url: '/pages/mine/index' }) },
		goSkills() { uni.navigateTo({ url: '/pages/mine/skills' }) },
		statusTextOf(s) { return STATUS_TEXT[s] || s },
		startPolling() {
			this.stopPolling()
			this.poll()
			this.timer = setInterval(() => this.poll(), 3000)
		},
		stopPolling() {
			if (this.timer) { clearInterval(this.timer); this.timer = null }
		},
		async loadHistory() {
			this.loadingList = true
			this.listError = ''
			try {
				const res = await deployApi.tasks({ limit: 30 })
				this.history = (res && Array.isArray(res.data)) ? res.data : []
			} catch (e) {
				this.listError = (e && e.message) || '加载下发记录失败'
			} finally {
				this.loadingList = false
			}
		},
		inspectTask(item) {
			this.taskNo = item.task_no
			this.skillName = item.skill_name || ''
			this.task = item
			this.startPolling()
		},
		async poll() {
			if (!this.taskNo) return
			try {
				const res = await deployApi.status(this.taskNo)
				this.task = (res && res.data) || {}
				if (this.terminal) {
					this.stopPolling()
					this.loadHistory()
				}
			} catch (_) {}
		},
		async cancel() {
			if (!this.taskNo || this.canceling) return
			this.canceling = true
			try {
				await deployApi.cancel(this.taskNo)
				uni.showToast({ title: '已取消该任务', icon: 'success' })
				await this.poll()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '取消失败', icon: 'none' })
			} finally {
				this.canceling = false
			}
		},
	},
}
</script>

<style scoped>
.deploy-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.focus-card { border-color: #C7DCFF; }
.focus-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16rpx; }
.focus-left { display: flex; align-items: center; flex-wrap: wrap; gap: 12rpx; flex: 1; }
.op-badge { font-size: 22rpx; font-weight: 600; padding: 4rpx 14rpx; border-radius: 8rpx; background: #EEF4FF; color: #165DFF; }
.skill-name { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.task-no { font-size: 22rpx; color: #64748B; }
.steps { display: flex; margin: 38rpx 0 28rpx; }
.step { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; }
.dot { width: 26rpx; height: 26rpx; border-radius: 50%; background: #CBD5E1; z-index: 2; }
.step.done .dot { background: #059669; }
.step.active .dot { background: #165DFF; box-shadow: 0 0 0 8rpx #DBEAFE; }
.step.fail .dot { background: #DC2626; box-shadow: 0 0 0 8rpx #FEE2E2; }
.step-label { margin-top: 12rpx; font-size: 23rpx; color: #64748B; }
.step.active .step-label { color: #165DFF; font-weight: 600; }
.step.done .step-label { color: #059669; }
.step.fail .step-label { color: #DC2626; font-weight: 600; }
.line { position: absolute; top: 12rpx; left: 50%; width: 100%; height: 3rpx; background: #E2E8F0; z-index: 1; }
.line.done { background: #059669; }
.status-box { text-align: center; padding: 20rpx; background: #F8FAFC; border-radius: 16rpx; }
.status-text { display: block; font-size: 28rpx; font-weight: 600; color: #165DFF; }
.status-text.ok { color: #059669; }
.status-text.err { color: #DC2626; }
.sub-meta { display: block; font-size: 23rpx; color: #64748B; margin-top: 6rpx; }
.err-msg { display: block; margin-top: 10rpx; font-size: 24rpx; color: #DC2626; line-height: 1.5; }
.actions { display: flex; gap: 16rpx; margin-top: 24rpx; }
.btn-solid { flex: 1; height: 82rpx; border-radius: 16rpx; background: #165DFF; color: #FFFFFF; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-outline { flex: 1; height: 82rpx; border-radius: 16rpx; background: #FFFFFF; border: 1rpx solid #CBD5E1; color: #475569; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-outline.danger { border-color: #FECACA; color: #DC2626; background: #FEF2F2; }
.section-head { display: flex; justify-content: space-between; align-items: center; margin: 12rpx 4rpx 16rpx; }
.section-title { font-size: 28rpx; font-weight: 600; color: #334155; }
.refresh-link { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.hist-card { padding: 24rpx 28rpx; }
.hist-card.selected { border-color: #165DFF; background: #F8FBFF; }
.hist-top { display: flex; justify-content: space-between; align-items: center; gap: 12rpx; margin-bottom: 12rpx; }
.hist-tags { display: flex; align-items: center; gap: 12rpx; flex: 1; min-width: 0; }
.op-pill { font-size: 21rpx; font-weight: 600; padding: 3rpx 12rpx; border-radius: 6rpx; background: #EEF4FF; color: #165DFF; flex-shrink: 0; }
.hist-skill { font-size: 28rpx; font-weight: 600; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.st-pill { font-size: 23rpx; font-weight: 600; color: #165DFF; flex-shrink: 0; }
.st-success { color: #059669; }
.st-failed, .st-timeout, .st-cancelled { color: #DC2626; }
.st-pending_online { color: #D97706; }
.hist-meta { display: flex; justify-content: space-between; font-size: 23rpx; color: #64748B; padding-bottom: 14rpx; border-bottom: 1rpx solid #F1F5F9; }
.hist-foot { display: flex; justify-content: space-between; align-items: center; padding-top: 14rpx; font-size: 23rpx; color: #94A3B8; }
.inspect-link { color: #165DFF; font-size: 24rpx; font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif; }
</style>
