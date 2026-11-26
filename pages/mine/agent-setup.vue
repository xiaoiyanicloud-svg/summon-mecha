<template>
	<view class="setup-page">
		<nav-bar title="端侧 Agent 配对向导" />

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可为已登记的机器人签发一次性 Agent 配对令"
			action-text="返回登录"
			@action="goMine"
		/>

		<scroll-view v-else class="scroll-content" scroll-y>
			<view class="hero-card">
				<text class="hero-title">连接机器人端侧 Agent</text>
				<text class="hero-desc">在机器人计算单元部署一次守护进程，后续买断的技能即可在线下发、状态回执与远程急停。</text>
			</view>

			<!-- 步骤 1：选择设备并签发配对令 -->
			<view class="card">
				<view class="step-head">
					<text class="step-badge sm-mono">01</text>
					<text class="card-title">选择目标设备并生成配对令</text>
				</view>

				<view v-if="devices.length" class="device-chips">
					<view
						v-for="d in devices"
						:key="d.id"
						class="dev-chip"
						:class="{ active: pickedDevice === d.id }"
						@tap="pickDevice(d)"
					>
						<text class="dev-sn sm-mono">{{ d.sn }}</text>
						<text class="dev-tag" :class="{ ok: d.agent_paired }">
							{{ d.agent_online ? '在线' : (d.agent_paired ? '已配对' : '待配对') }}
						</text>
					</view>
				</view>
				<view v-else-if="deviceLoadError" class="outline-btn" @tap="loadDevices">加载失败，点击重试</view>
				<view v-else class="outline-btn" @tap="goDevices">尚未登记设备，先去登记序列号 ›</view>

				<!-- 生成后的安装指令与倒计时 -->
				<view v-if="installCommand" class="token-panel">
					<view class="token-meta-row">
						<text class="token-status">单次配对令已就绪</text>
						<text class="token-ttl sm-mono">剩余有效时间 {{ remainText }}</text>
					</view>
					<view class="cmd-box" @tap="copy(installCommand)">
						<text class="cmd sm-mono">{{ installCommand }}</text>
						<text class="copy-btn">复制命令</text>
					</view>
					<text class="poll-hint">
						{{ pairedJustNow
							? '检测到该设备已完成 Agent 握手，现在可返回下发技能。'
							: '正在自动监听设备配对回执（每 6 秒同步一次）…' }}
					</text>
				</view>
				<text v-else-if="devices.length" class="muted-tip">点击上方任意设备序列号，立即签发 30 分钟内单次有效的配对安装命令。</text>
			</view>

			<!-- 配对成功反馈卡（严守 D1 口径：写 Agent 已配对，不写已绑定） -->
			<view v-if="pairedJustNow" class="success-card">
				<SmIcon name="file-check" :size="44" />
				<view class="sc-info">
					<text class="sc-title">Agent 已配对就绪</text>
					<text class="sc-sub">设备已建立独立 HMAC 凭据通道，可前往「我的技能」执行一键下发。</text>
				</view>
				<view class="sc-btn" @tap="goSkills">去下发</view>
			</view>

			<!-- 步骤 2：渐进式折叠技术终端指引 -->
			<view class="card">
				<view class="collapse-head" @tap="showTechGuide = !showTechGuide">
					<view class="step-head no-mb">
						<text class="step-badge sm-mono">02</text>
						<text class="card-title">机器人终端执行方式（工程师指引）</text>
					</view>
					<text class="toggle-link">{{ showTechGuide ? '收起' : '展开详情 ›' }}</text>
				</view>

				<view v-if="!showTechGuide" class="brief-guide">
					<text class="brief-text">如果您由驻场工程师协助部署，只需将上方复制的配对命令发送给工程师在机器终端执行即可；如需自行通过网线 SSH 操作，请点击右上角展开各品牌步骤。</text>
				</view>

				<view v-else class="tech-body">
					<view class="brand-tabs">
						<text
							v-for="b in brands"
							:key="b.key"
							class="brand-tab"
							:class="{ active: picked === b.key }"
							@tap="picked = b.key"
						>{{ b.name }}</text>
					</view>

					<view v-if="current" class="steps-wrap">
						<view v-for="(s, i) in current.steps" :key="i" class="step-item">
							<text class="st-title">{{ i + 1 }}. {{ s.title }}</text>
							<text class="st-desc">{{ s.desc }}</text>
							<view v-if="stepCmd(s)" class="cmd-box compact" @tap="copy(stepCmd(s))">
								<text class="cmd sm-mono">{{ stepCmd(s) }}</text>
								<text class="copy-btn">复制</text>
							</view>
						</view>

						<view class="warn-box">
							<text class="warn-text">{{ current.notice }}</text>
						</view>
					</view>
				</view>
			</view>

			<!-- 步骤 3：核验完成状态 -->
			<view class="card">
				<view class="step-head">
					<text class="step-badge sm-mono">03</text>
					<text class="card-title">配对完成后的状态说明</text>
				</view>
				<text class="brief-text">终端脚本执行完成后，返回「我的设备」将显示蓝色「Agent 已配对」或绿色「Agent 在线」标识（原厂出厂序列号仍如实保留「未核验」标注）。</text>
				<view class="primary-btn" @tap="goDevices">返回我的设备查看状态</view>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { deviceApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			loggedIn: false,
			picked: 'unitree',
			showTechGuide: false,
			devices: [],
			deviceLoadError: '',
			pickedDevice: 0,
			installCommand: '',
			remainSeconds: 0,
			pairedJustNow: false,
			countdownTimer: null,
			pollTimer: null,
			brands: [
				{ key: 'unitree', name: '宇树 Unitree' },
				{ key: 'agibot', name: '智元 AgiBot' },
				{ key: 'engineai', name: '众擎 EngineAI' },
			],
			setups: {
				unitree: {
					notice: '安全提示：宇树基础版默认关闭 SSH，需 EDU/开发版机型；请务必修改初始弱口令并保持机器周围 2 米净空。',
					steps: [
						{ title: '连接机器人网络', desc: '通过宇树官方 App 将机器人接入局域网 Wi-Fi，或使用网线连接调试口。' },
						{ title: '登录机载开发板', desc: '有线直连时将电脑网卡设为 192.168.123.100，终端执行：', cmd: 'ssh unitree@192.168.123.164' },
						{ title: '修改出厂默认口令', desc: '首次登录建议立即更新系统密码：', cmd: 'passwd' },
						{ title: '执行单次配对安装脚本', desc: '粘贴上方步骤 01 生成的专属命令并回车：', install: true },
					],
				},
				agibot: {
					notice: '安全提示：智元机型需开放应用侧算力单元（Orin），Agent 严格运行在用户态沙箱，不触碰 x86 运控实时核。',
					steps: [
						{ title: '接入有线调试网口', desc: '连接智元调试网口并登录应用算力单元账号：', cmd: 'ssh agi@192.168.2.50' },
						{ title: '执行单次配对安装脚本', desc: '粘贴上方步骤 01 生成的专属命令执行安装：', install: true },
					],
				},
				engineai: {
					notice: '安全提示：众擎机型请登录 Jetson 应用算力单元，切勿改动原厂运控服务进程。',
					steps: [
						{ title: '登录应用算力单元', desc: '在同一内网网段终端执行：', cmd: 'ssh ubuntu@192.168.0.162' },
						{ title: '更新默认口令', desc: '修改默认登录密码以防局域网越权：', cmd: 'passwd' },
						{ title: '执行单次配对安装脚本', desc: '粘贴上方步骤 01 生成的专属命令执行安装：', install: true },
					],
				},
			},
		}
	},
	computed: {
		current() {
			return this.setups[this.picked]
		},
		remainText() {
			const s = Math.max(0, this.remainSeconds)
			const m = String(Math.floor(s / 60)).padStart(2, '0')
			const sec = String(s % 60).padStart(2, '0')
			return `${m}:${sec}`
		},
	},
	onLoad(q) {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) {
			this.loadDevices(q && q.device_id ? Number(q.device_id) : 0)
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
	},
	onUnload() {
		this.clearTimers()
	},
	methods: {
		clearTimers() {
			if (this.countdownTimer) { clearInterval(this.countdownTimer); this.countdownTimer = null }
			if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null }
		},
		goMine() {
			uni.switchTab({ url: '/pages/mine/index' })
		},
		goDevices() {
			uni.redirectTo({ url: '/pages/mine/devices' })
		},
		goSkills() {
			uni.redirectTo({ url: '/pages/mine/skills' })
		},
		async loadDevices(autoPickId = 0) {
			this.deviceLoadError = ''
			try {
				const res = await deviceApi.list()
				this.devices = (res && Array.isArray(res.data)) ? res.data : []
				if (autoPickId) {
					const target = this.devices.find(x => Number(x.id) === autoPickId)
					if (target) this.pickDevice(target)
				}
			} catch (e) {
				this.deviceLoadError = (e && e.message) || '加载失败'
				this.devices = []
			}
		},
		async pickDevice(d) {
			this.pickedDevice = d.id
			this.installCommand = ''
			this.pairedJustNow = false
			this.clearTimers()
			try {
				const res = await deviceApi.agentToken(d.id)
				const data = (res && res.data) || {}
				this.installCommand = data.command || ''
				this.remainSeconds = 30 * 60
				if (this.installCommand) {
					uni.showToast({ title: '配对令已签发', icon: 'success' })
					this.startWatching(d.id)
				}
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '签发配对令失败', icon: 'none' })
			}
		},
		startWatching(deviceId) {
			this.countdownTimer = setInterval(() => {
				if (this.remainSeconds > 0) this.remainSeconds -= 1
				else this.clearTimers()
			}, 1000)

			this.pollTimer = setInterval(async () => {
				try {
					const res = await deviceApi.list()
					const list = (res && Array.isArray(res.data)) ? res.data : []
					this.devices = list
					const found = list.find(x => Number(x.id) === Number(deviceId))
					if (found && found.agent_paired) {
						this.pairedJustNow = true
						this.clearTimers()
					}
				} catch (_) {}
			}, 6000)
		},
		stepCmd(s) {
			if (s.install) return this.installCommand || ''
			return s.cmd || ''
		},
		copy(cmd) {
			if (!cmd) return
			uni.setClipboardData({
				data: cmd,
				success: () => uni.showToast({ title: '命令已复制', icon: 'success' }),
			})
		},
	},
}
</script>

<style scoped>
.setup-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.hero-card { padding: 30rpx 32rpx; border-radius: 24rpx; background: linear-gradient(135deg, #102F6E 0%, #174299 100%); color: #FFFFFF; margin-bottom: 20rpx; }
.hero-title { display: block; font-size: 32rpx; font-weight: 600; margin-bottom: 8rpx; }
.hero-desc { display: block; font-size: 24rpx; color: rgba(255, 255, 255, 0.82); line-height: 1.55; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.step-head { display: flex; align-items: center; gap: 14rpx; margin-bottom: 20rpx; }
.step-head.no-mb { margin-bottom: 0; }
.step-badge { width: 44rpx; height: 44rpx; border-radius: 12rpx; background: #EEF4FF; color: #165DFF; font-size: 23rpx; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.card-title { font-size: 29rpx; font-weight: 600; color: #0F172A; }
.device-chips { display: flex; flex-wrap: wrap; gap: 14rpx; }
.dev-chip { padding: 14rpx 22rpx; border-radius: 16rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; gap: 12rpx; }
.dev-chip.active { background: #EEF4FF; border-color: #165DFF; }
.dev-sn { font-size: 26rpx; font-weight: 600; color: #0F172A; }
.dev-tag { font-size: 21rpx; padding: 2rpx 10rpx; border-radius: 6rpx; background: #E2E8F0; color: #475569; }
.dev-tag.ok { background: #ECFDF5; color: #059669; }
.outline-btn { height: 80rpx; border-radius: 16rpx; border: 1rpx solid #165DFF; color: #165DFF; font-size: 26rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.token-panel { margin-top: 22rpx; padding: 22rpx; background: #F8FAFC; border: 1rpx solid #D6E4FF; border-radius: 18rpx; }
.token-meta-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14rpx; }
.token-status { font-size: 24rpx; font-weight: 600; color: #165DFF; }
.token-ttl { font-size: 23rpx; color: #D97706; font-weight: 600; }
.cmd-box { display: flex; align-items: center; gap: 16rpx; padding: 20rpx; background: #0F172A; border-radius: 14rpx; }
.cmd-box.compact { margin-top: 12rpx; padding: 16rpx 20rpx; }
.cmd { flex: 1; font-size: 24rpx; color: #93C5FD; word-break: break-all; line-height: 1.5; }
.copy-btn { font-size: 24rpx; font-weight: 600; color: #FFFFFF; background: #165DFF; padding: 8rpx 18rpx; border-radius: 10rpx; flex-shrink: 0; }
.poll-hint { display: block; font-size: 23rpx; color: #64748B; margin-top: 14rpx; }
.muted-tip { display: block; font-size: 24rpx; color: #64748B; margin-top: 16rpx; line-height: 1.5; }
.success-card { background: #ECFDF5; border: 1rpx solid #A7F3D0; border-radius: 22rpx; padding: 26rpx; margin-bottom: 20rpx; display: flex; align-items: center; gap: 18rpx; }
.sc-info { flex: 1; }
.sc-title { display: block; font-size: 29rpx; font-weight: 600; color: #065F46; }
.sc-sub { display: block; font-size: 23rpx; color: #047857; margin-top: 4rpx; }
.sc-btn { padding: 12rpx 24rpx; border-radius: 12rpx; background: #059669; color: #FFFFFF; font-size: 25rpx; font-weight: 600; }
.collapse-head { display: flex; justify-content: space-between; align-items: center; }
.toggle-link { font-size: 25rpx; color: #165DFF; font-weight: 600; }
.brief-guide { margin-top: 16rpx; }
.brief-text { display: block; font-size: 25rpx; color: #475569; line-height: 1.6; }
.tech-body { margin-top: 22rpx; padding-top: 20rpx; border-top: 1rpx solid #F1F5F9; }
.brand-tabs { display: flex; gap: 14rpx; margin-bottom: 20rpx; }
.brand-tab { flex: 1; height: 68rpx; border-radius: 14rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-size: 24rpx; color: #475569; font-weight: 500; }
.brand-tab.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.step-item { margin-bottom: 22rpx; }
.st-title { display: block; font-size: 27rpx; font-weight: 600; color: #0F172A; }
.st-desc { display: block; font-size: 24rpx; color: #475569; margin-top: 6rpx; line-height: 1.55; }
.warn-box { padding: 18rpx 20rpx; border-radius: 14rpx; background: #FFFBEB; border: 1rpx solid #FDE68A; }
.warn-text { font-size: 23rpx; color: #92400E; line-height: 1.55; }
.primary-btn { height: 84rpx; border-radius: 18rpx; background: #165DFF; color: #FFFFFF; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; margin-top: 22rpx; }
</style>
