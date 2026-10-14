<template>
	<view class="setup-page">
		<view class="nav-bar">
			<view class="nav-back" @click="goBack"><text class="back-icon">‹</text></view>
			<text class="nav-title">安装 Agent</text>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view class="scroll-content" scroll-y="true" show-scrollbar="false">
			<view class="intro">
				<text class="intro-text">给机器人装上 Agent，之后就能「一键下发技能」。只需装一次。</text>
			</view>

			<!-- 选设备 → 生成一次性安装命令(30 分钟有效) -->
			<view class="card">
				<text class="card-title">① 选择要安装的设备</text>
				<view class="devices" v-if="devices.length">
					<text
						v-for="d in devices"
						:key="d.id"
						class="device"
						:class="{ on: pickedDevice === d.id }"
						@click="pickDevice(d)"
					>{{ d.sn }}{{ d.agent_paired ? '（已配对）' : '' }}</text>
				</view>
				<view class="bind-btn add" v-else @click="goDevices">还没有设备，先去登记</view>

				<view class="cmd-box" v-if="installCommand" @click="copy(installCommand)">
					<text class="cmd">{{ installCommand }}</text>
					<text class="copy">复制</text>
				</view>
				<text class="step-desc" v-else-if="devices.length">选一台设备，生成专属安装命令（30 分钟内有效，只能用一次）。</text>
			</view>

			<!-- 选品牌 -->
			<view class="card">
				<text class="card-title">② 选择你的机器人品牌（看 SSH 登录方式）</text>
				<view class="brands">
					<text
						v-for="b in brands"
						:key="b.key"
						class="brand"
						:class="{ on: picked === b.key }"
						@click="picked = b.key"
					>{{ b.name }}</text>
				</view>
			</view>

			<!-- 步骤 -->
			<view class="card" v-if="current">
				<text class="card-title">③ 按步骤操作</text>

				<view class="step" v-for="(s, i) in current.steps" :key="i">
					<text class="step-title">{{ i + 1 }}. {{ s.title }}</text>
					<text class="step-desc">{{ s.desc }}</text>
					<view v-if="stepCmd(s)" class="cmd-box" @click="copy(stepCmd(s))">
						<text class="cmd">{{ stepCmd(s) }}</text>
						<text class="copy">复制</text>
					</view>
				</view>

				<view class="notice">
					<text class="notice-text">{{ current.notice }}</text>
				</view>
			</view>

			<!-- 完成后 -->
			<view class="card" v-if="current">
				<text class="card-title">④ 完成后</text>
				<text class="step-desc">回到「我的设备」，这台机器人会变成绿色「已绑定」。之后在技能市场点「下发」即可。</text>
				<view class="ghost-btn primary" @click="goDevices">去我的设备</view>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { deviceApi } from '@/utils/api'

// 训练手册:每家机器人的安装步骤(来自用户教程)。
// ⚠ 「安装 Agent」那一步的命令**由平台签发**(内含一次性 token),不在这里硬编码 ——
//   所以该步用 `install: true` 标记,渲染时取①里生成的真实命令。
export default {
	data() {
		return {
			picked: 'unitree',
			devices: [],
			pickedDevice: 0,
			installCommand: '',
			brands: [
				{ key: 'unitree', name: '宇树' },
				{ key: 'agibot', name: '智元' },
				{ key: 'engineai', name: '众擎' },
			],
			setups: {
				unitree: {
					notice: '⚠ 宇树基础版出厂禁用 SSH，需 EDU/开发版。开发者模式导致的损坏不在保修范围。',
					steps: [
						{ title: '让机器人联网', desc: '用宇树 App 让机器人连上 WiFi。' },
						{ title: '用电脑 SSH 登录', desc: '网线连电脑与机器人（电脑设 192.168.123.100），打开终端输入：', cmd: 'ssh unitree@192.168.123.164' },
						{ title: '改掉默认密码', desc: '登录后先改密码（默认 123 太弱）。', cmd: 'passwd' },
						{ title: '安装 Agent', desc: '复制上面①里生成的安装命令，粘贴执行：', install: true },
					],
				},
				agibot: {
					notice: '⚠ 智元需开放二开机型；Agent 会装在 Orin 侧，遵守其硬约束（不碰 x86 运控板、不用宿主机 apt）。',
					steps: [
						{ title: '用电脑 SSH 登录', desc: '接入智元有线调试口，登录开发账号：', cmd: 'ssh agi@192.168.2.50' },
						{ title: '安装 Agent', desc: '复制上面①里生成的安装命令，粘贴执行（脚本会自动按智元约束装在 Orin 侧）：', install: true },
					],
				},
				engineai: {
					notice: '⚠ 众擎请连应用算力单元（Jetson），默认口令 ubuntu 请先改。',
					steps: [
						{ title: '用电脑 SSH 登录', desc: '接入众擎内网 192.168.0.x，登录应用算力单元：', cmd: 'ssh ubuntu@192.168.0.162' },
						{ title: '改弱口令', desc: '先改密码。', cmd: 'passwd' },
						{ title: '安装 Agent', desc: '复制上面①里生成的安装命令，粘贴执行（不改动原厂 robotics.service）：', install: true },
					],
				},
			},
		}
	},
	onLoad() {
		this.loadDevices()
	},
	computed: {
		current() { return this.setups[this.picked] },
	},
	methods: {
		goBack() { uni.navigateBack({ delta: 1 }) },
		goDevices() { uni.redirectTo({ url: '/pages/mine/devices' }) },
		async loadDevices() {
			try {
				const res = await deviceApi.list()
				this.devices = (res && Array.isArray(res.data)) ? res.data : []
			} catch (e) {
				this.devices = []
			}
		},
		// 选设备 → 向平台要一次性安装 token(30 分钟、一次性)
		async pickDevice(d) {
			this.pickedDevice = d.id
			this.installCommand = ''
			try {
				const res = await deviceApi.agentToken(d.id)
				this.installCommand = (res && res.data && res.data.command) || ''
				if (this.installCommand) {
					uni.showToast({ title: '命令已生成', icon: 'none' })
				}
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '生成失败', icon: 'none' })
			}
		},
		// 安装命令必须由平台签发(内含一次性 token);没生成就不显示假命令
		stepCmd(s) {
			if (s.install) return this.installCommand || ''
			return s.cmd || ''
		},
		copy(cmd) {
			if (!cmd) return
			uni.setClipboardData({ data: cmd, success: () => uni.showToast({ title: '已复制', icon: 'none' }) })
		},
	},
}
</script>

<style scoped>
.setup-page { min-height: 100vh; background: #f5f6f8; }
.nav-bar { display: flex; align-items: center; justify-content: space-between; height: 88rpx; padding: 0 24rpx; background: #fff; }
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #333; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-placeholder { width: 60rpx; }
.scroll-content { height: calc(100vh - 88rpx); }
.intro { padding: 24rpx; }
.intro-text { font-size: 26rpx; color: #666; line-height: 1.6; }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }
.card-title { font-size: 30rpx; font-weight: 600; color: #222; }
.brands { margin-top: 16rpx; display: flex; }
.brand { margin-right: 20rpx; padding: 12rpx 36rpx; border: 1rpx solid #ddd; border-radius: 40rpx; font-size: 26rpx; color: #555; }
.brand.on { border-color: #ff6b00; color: #ff6b00; background: #fff5ee; }
.devices { margin-top: 16rpx; display: flex; flex-wrap: wrap; }
.device { margin: 0 20rpx 16rpx 0; padding: 12rpx 28rpx; border: 1rpx solid #ddd; border-radius: 40rpx; font-size: 26rpx; color: #555; }
.device.on { border-color: #ff6b00; color: #ff6b00; background: #fff5ee; }
.bind-btn.add { display: inline-block; margin-top: 16rpx; padding: 14rpx 36rpx; border: 1rpx solid #ff6b00; border-radius: 40rpx; font-size: 26rpx; color: #ff6b00; }
.step { margin-top: 24rpx; }
.step-title { font-size: 28rpx; font-weight: 600; color: #333; }
.step-desc { display: block; margin-top: 8rpx; font-size: 24rpx; color: #666; line-height: 1.6; }
.cmd-box { display: flex; align-items: center; margin-top: 12rpx; padding: 16rpx 20rpx; background: #1f2937; border-radius: 10rpx; }
.cmd { flex: 1; font-size: 24rpx; color: #7dd3fc; word-break: break-all; }
.copy { margin-left: 16rpx; font-size: 24rpx; color: #ffb86c; }
.notice { margin-top: 24rpx; padding: 16rpx; background: #fff7e6; border-radius: 10rpx; }
.notice-text { font-size: 22rpx; color: #b45309; line-height: 1.6; }
.ghost-btn { display: inline-block; margin-top: 20rpx; padding: 16rpx 48rpx; border-radius: 40rpx; font-size: 28rpx; }
.ghost-btn.primary { background: #ff6b00; color: #fff; }
</style>
