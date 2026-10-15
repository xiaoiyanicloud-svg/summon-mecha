<template>
	<view class="deploy-page">
		<!-- 导航栏 -->
		<nav-bar title="下发进度" />

		<scroll-view class="scroll-content" scroll-y="true" show-scrollbar="false">
			<view class="card">
				<text class="skill-name">{{ skillName || '技能下发' }}</text>

				<!-- 状态机可视化 -->
				<view class="steps">
					<view v-for="(s, i) in steps" :key="s.key" class="step" :class="{ done: stepIndex > i, active: stepIndex === i, fail: isFailed && stepIndex === i }">
						<view class="dot"></view>
						<text class="step-label">{{ s.label }}</text>
						<view class="line" v-if="i < steps.length - 1"></view>
					</view>
				</view>

				<!-- 实时状态 -->
				<view class="status-box">
					<text class="status-text">{{ statusLabel }}</text>
					<text v-if="task.error_msg" class="err">{{ task.error_msg }}</text>
				</view>

				<!-- 操作 -->
				<view class="actions" v-if="!terminal">
					<view class="ghost-btn" @click="cancel" v-if="!canceling">取消下发</view>
				</view>
				<view class="actions" v-else>
					<view class="ghost-btn primary" @click="goSkills">去我的技能</view>
				</view>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { deployApi } from '@/utils/api'
import NavBar from '@/components/NavBar.vue'

// 与后端 deploy_task.status 对齐
const STEPS = [
	{ key: 'dispatching', label: '下发中' },
	{ key: 'downloading', label: '下载中' },
	{ key: 'installing', label: '安装中' },
	{ key: 'success', label: '完成' },
]
const STATUS_TEXT = {
	pending: '排队中', dispatching: '下发中', downloading: '下载中', installing: '安装中',
	success: '已生效', failed: '失败', timeout: '超时', cancelled: '已取消', pending_online: '设备离线，待上线',
}

export default {
	components: { NavBar },
	data() {
		return {
			taskNo: '',
			skillName: '',
			task: {},
			steps: STEPS,
			timer: null,
			canceling: false,
		}
	},
	onLoad(options) {
		this.taskNo = options.taskNo || ''
		this.skillName = decodeURIComponent(options.skillName || '')
		if (this.taskNo) {
			this.poll()
			// 3s 轮询(计划 §4.6)
			this.timer = setInterval(() => this.poll(), 3000)
		}
	},
	onUnload() { if (this.timer) clearInterval(this.timer) },
	computed: {
		stepIndex() {
			const i = STEPS.findIndex(s => s.key === this.task.status)
			return i < 0 ? 0 : i
		},
		terminal() {
			return ['success', 'failed', 'timeout', 'cancelled'].includes(this.task.status)
		},
		isFailed() {
			return ['failed', 'timeout', 'cancelled'].includes(this.task.status)
		},
		statusLabel() {
			return STATUS_TEXT[this.task.status] || '处理中…'
		},
	},
	methods: {
		goSkills() { uni.redirectTo({ url: '/pages/mine/skills' }) },
		async poll() {
			try {
				const res = await deployApi.status(this.taskNo)
				this.task = (res && res.data) || {}
				if (this.terminal && this.timer) { clearInterval(this.timer); this.timer = null }
			} catch (e) {
				// 轮询失败不打断,下次再试
			}
		},
		async cancel() {
			this.canceling = true
			try {
				await deployApi.cancel(this.taskNo)
				uni.showToast({ title: '已取消', icon: 'none' })
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
.deploy-page { min-height: 100vh; background: #f5f6f8; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 32rpx 28rpx; }
.skill-name { font-size: 30rpx; font-weight: 600; color: #222; }
.steps { display: flex; margin: 40rpx 0 24rpx; }
.step { flex: 1; display: flex; flex-direction: column; align-items: center; position: relative; }
.dot { width: 28rpx; height: 28rpx; border-radius: 50%; background: #ddd; }
.step.done .dot { background: #ff6b00; }
.step.active .dot { background: #ff6b00; box-shadow: 0 0 0 8rpx #ffe6d5; }
.step.fail .dot { background: #e33; }
.step-label { margin-top: 12rpx; font-size: 22rpx; color: #888; }
.step.active .step-label { color: #ff6b00; }
.line { position: absolute; top: 13rpx; left: 50%; width: 100%; height: 2rpx; background: #eee; z-index: -1; }
.status-box { text-align: center; padding: 20rpx 0; }
.status-text { font-size: 28rpx; color: #ff6b00; font-weight: 600; }
.err { display: block; margin-top: 12rpx; font-size: 24rpx; color: #e33; }
.actions { margin-top: 20rpx; text-align: center; }
.ghost-btn { display: inline-block; padding: 16rpx 60rpx; border: 1rpx solid #ddd; border-radius: 40rpx; font-size: 28rpx; color: #555; }
.ghost-btn.primary { background: #ff6b00; border-color: #ff6b00; color: #fff; }
</style>
