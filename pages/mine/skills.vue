<template>
	<view class="skills-page">
		<!-- 导航栏 -->
		<nav-bar title="我的技能" variant="safe" />

		<view v-if="!loggedIn" class="empty-state">
			<text class="empty-icon">🔒</text>
			<text class="empty-text">请先在「我的」页面登录</text>
			<view class="primary-btn" @click="goBack">返回</view>
		</view>

		<scroll-view v-else class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- 三态:加载中 / 故障(带重试) / 真·还没有开通技能。故障不再伪装成「还没有技能」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!skills.length"
				empty-icon="✨"
				empty-text="还没有开通任何技能"
				empty-action
				empty-action-text="去看看技能"
				@empty-action="goRobot"
				@retry="refresh"
			>
				<view class="card skill-card" v-for="s in skills" :key="s.id">
					<view class="skill-head">
						<view class="skill-info">
							<text class="skill-name">{{ s.name }}</text>
							<text class="skill-meta" v-if="s.version">版本 v{{ s.version }}</text>
						</view>
						<text class="status" :class="s.status">{{ statusLabel(s) }}</text>
					</view>

					<!-- 交付信息 -->
					<view class="rows">
						<view class="row">
							<text class="row-label">指派设备</text>
							<text class="row-value">{{ s.device_sn || '尚未指派' }}</text>
						</view>
						<view class="row" v-if="s.trial">
							<text class="row-label">试用剩余</text>
							<text class="row-value" style="color:#ff6b00;font-weight:600;">{{ countdownText(s) }}</text>
						</view>
						<view class="row">
							<text class="row-label">开通时间</text>
							<text class="row-value">{{ s.granted_at || '—' }}</text>
						</view>
						<view class="row">
							<text class="row-label">已用激活次数</text>
							<text class="row-value">{{ activationCountText(s.activations) }}</text>
						</view>
						<view class="row">
							<text class="row-label">激活次数上限</text>
							<text class="row-value">{{ s.max_activations === null ? '不限次数' : activationCountText(s.max_activations) }}</text>
						</view>
						<view class="row">
							<text class="row-label">剩余激活次数</text>
							<text class="row-value">{{ s.remaining_activations === null ? '不限次数' : activationCountText(s.remaining_activations) }}</text>
						</view>
						<view class="row" v-if="s.expires_at">
							<text class="row-label">有效期至</text>
							<text class="row-value">{{ s.expires_at }}</text>
						</view>
					</view>

					<!-- 未指派设备:先指派才能用 -->
					<view class="bind-area" v-if="!s.device_id">
						<text class="bind-tip">指派给一台设备即可使用这个技能</text>
						<view class="bind-options" v-if="devices.length">
							<text class="bind-btn" v-for="d in devices" :key="d.id" @click="assign(s, d)">
								指派给 {{ d.sn }}
							</text>
						</view>
						<view class="bind-btn add" v-else @click="goDevices">还没有设备，去登记</view>
					</view>

					<!-- 已指派设备:支持 agent 通道的可一键下发;其余走人工通道 -->
					<view class="actions" v-else>
						<view class="ghost-btn primary" v-if="s.agent_deliverable && s.skill_version_id" @click="deployToDevice(s)">
							{{ s.device_online ? '一键下发' : '一键下发（设备离线将排队）' }}
						</view>
						<view class="ghost-btn" @click="loadAssets(s)">查看交付物</view>
					</view>

					<!-- 运维操作(B-6):卸载 / 回退 / 启停 —— 与「一键下发」共用 POST /api/deploy/tasks,
						 只是 operation 不同(白名单 = DeployTask::OPERATIONS)。每一次都建一条独立下发任务、
						 由 Agent 执行,所以不可逆的操作(卸载/回退)必须先二次确认。
						 ⚠ 仅 agent 可交付技能可见:人工通道平台碰不到那台机器,给了按钮也执行不了。 -->
					<view class="actions ops" v-if="canOperate(s)">
						<text class="ops-label">设备运维</text>
						<view class="ops-row">
							<view class="ops-btn" @click="runOperation(s, OP.UNINSTALL)">卸载</view>
							<view class="ops-btn" @click="runOperation(s, OP.ROLLBACK)">回退版本</view>
							<view class="ops-btn" @click="runOperation(s, OP.ENABLE)">启用</view>
							<view class="ops-btn" @click="runOperation(s, OP.DISABLE)">停用</view>
						</view>
					</view>

					<!-- 交付物清单 -->
					<view class="assets" v-if="assetsOf(s.id).length">
						<view class="asset-item" v-for="a in assetsOf(s.id)" :key="a.id">
							<view class="asset-info">
								<text class="asset-name">{{ a.file_name || carrierLabel(a.carrier_type) }}</text>
								<text class="asset-size" v-if="a.file_size">{{ fmtSize(a.file_size) }}</text>
							</view>
							<text class="download" v-if="a.downloadable" @click="download(s, a)">下载</text>
							<text class="no-download" v-else>{{ carrierLabel(a.carrier_type) }}</text>
						</view>
					</view>

					<!-- 安装指引:厂家没开分发接口,只能由用户照着它自己装 -->
					<view class="guide" v-if="guideOf(s.id)">
						<text class="guide-title">安装指引</text>
						<text class="guide-body">{{ guideOf(s.id) }}</text>
					</view>

					<!-- 兜底通道终点:装完之后由用户自报。平台无法验证安装结果 -->
					<view class="actions" v-if="s.device_id && s.usable && s.status !== 'active'">
						<text class="bind-tip" v-if="activationLimitReached(s)">激活次数已达上限，无法再次确认安装。</text>
						<view class="ghost-btn primary" v-else @click="confirmInstall(s)">我已安装完成</view>
					</view>

					<!-- v3.4:申请售后/退款(虚拟商品政策:试用不退 / 未下发可退 / 已激活需人工审) -->
					<view class="actions">
						<view class="ghost-btn" @click="applyAfterSale(s)">申请售后 / 退款</view>
						<!-- 举报入口(B-7):与"售后"并列但视觉弱化 —— 售后是走钱,举报是走内容安全,两回事 -->
						<text class="report-link" @click="reportSkill(s)">举报该技能</text>
					</view>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { skillApi, deviceApi, deployApi } from '@/utils/api'
import { submitSkillReport } from '@/utils/report'
import { TOKEN_KEY, BASE_URL } from '@/utils/config'
import StateView from '@/components/StateView.vue'
import NavBar from '@/components/NavBar.vue'

const STATUS_TEXT = {
	granted: '已开通',
	// active = 用户已自报装到机器上。平台无法验证,所以文案只说"已安装",不说"运行中"
	active: '已安装',
	pending_bind: '待指派设备',
	expired: '已过期',
	revoked: '已撤销',
	// B-3 · 试用到期、正在回收（设备回报成功后才落 expired）
	expiring: '回收中',
}

// 载体类型 → 中文标签。⚠ 与后端 `SkillAsset::CARRIER_LABELS` **语义须一致**（两门语言，
// 无法共用一份常量，改动时两处一起改）。v3.4 新增的三类此前在此缺失，导致 carrierLabel()
// 返回原始英文码（如 app_package）、买家看不懂（有 || c 兜底，不是空白）。
const CARRIER_TEXT = {
	file: '文件包',
	cloud_config: '云端配置',
	remote_service: '远程协助',
	api_bundle: '接口包',
	motion_data: '动作数据',
	app_package: '应用包',
	vendor_package: '厂商资源包',
}

// B-6 · 下发操作码 —— **与后端 DeployTask::OPERATIONS 对齐**(值即契约,改名可以、改值须报批)。
// 前端此前只会发 operation:1(安装),卸载/回退/启停四个码在后端白名单里却零入口。
const OP = { INSTALL: 1, UNINSTALL: 2, ROLLBACK: 3, ENABLE: 4, DISABLE: 5 }

// 每种操作的二次确认文案。卸载/回退不可逆(平台侧会改 install_record 终态),按危险操作红色确认。
const OP_CONFIRM = {
	[OP.UNINSTALL]: {
		title: '卸载技能',
		okText: '确认卸载',
		danger: true,
		build: (s) => `确认从设备 ${s.device_sn || '（当前设备）'} 卸载「${s.name}」？卸载后该技能在这台设备上不再可用。`,
	},
	[OP.ROLLBACK]: {
		title: '回退版本',
		okText: '确认回退',
		danger: true,
		build: (s) => `确认把「${s.name}」回退到上一版本？当前版本（v${s.version || '未知'}）在这台设备上的安装记录会标记为已回滚。`,
	},
	[OP.ENABLE]: {
		title: '启用技能',
		okText: '确认启用',
		build: (s) => `确认在设备 ${s.device_sn || '（当前设备）'} 上启用「${s.name}」？`,
	},
	[OP.DISABLE]: {
		title: '停用技能',
		okText: '确认停用',
		build: (s) => `确认在设备 ${s.device_sn || '（当前设备）'} 上停用「${s.name}」？停用后技能在这台设备上暂停运行。`,
	},
}

export default {
	components: { StateView, NavBar },
	data() {
		return {
			// 模板要按操作码区分按钮,把常量暴露出去(Options API 模板看不到模块级 const)
			OP,
			loggedIn: false,
			loading: true,
			loadError: '',
			skills: [],
			devices: [],
			// entitlementId -> 交付物数组;按需拉取,不预加载全部
			assetsMap: {},
			// B-3 · 试用倒计时:entitlementId -> 剩余秒数(以**后端**给的值为起点本地递减,
			// 不用本地时钟去解析服务端时间戳 —— 端侧时钟偏一点倒计时就错)
			remaining: {},
			tickTimer: null,
		}
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
		this.startTick()
	},
	onHide() {
		this.stopTick()
	},
	onUnload() {
		this.stopTick()
	},
	methods: {
		// Missing fields remain unknown; only an explicit null means unlimited.
		activationCountText(value) {
			return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? value + ' 次' : '—'
		},
		activationLimitReached(skill) {
			return typeof skill.max_activations === 'number' && skill.max_activations >= 0 && skill.remaining_activations === 0
		},
		startTick() {
			this.stopTick()
			this.tickTimer = setInterval(() => {
				const next = {}
				let anyLive = false
				Object.keys(this.remaining).forEach((id) => {
					const v = this.remaining[id] - 1
					if (v > 0) { next[id] = v; anyLive = true }
					else next[id] = 0
				})
				this.remaining = next
				// 全部归零:不用一直空转;到期后状态由服务端在下一轮回收里推进
				if (!anyLive) this.stopTick()
			}, 1000)
		},
		stopTick() {
			if (this.tickTimer) { clearInterval(this.tickTimer); this.tickTimer = null }
		},
		// 秒 → mm:ss
		countdownText(s) {
			const sec = this.remaining[s.id]
			if (sec === undefined) return s.usable ? '—' : '已结束'
			if (sec <= 0) return '已到期'
			const m = Math.floor(sec / 60)
			const ss = sec % 60
			return m + ':' + (ss < 10 ? '0' : '') + ss
		},
		// 试用行不按普通状态展示:试用的"active"其实是"试用中",直接显示容易读成买断
		statusLabel(s) {
			if (s.trial && s.status !== 'expired' && s.status !== 'expiring' && s.status !== 'revoked') return '试用中'
			return STATUS_TEXT[s.status] || s.status
		},
		goBack() { uni.navigateBack({ delta: 1 }) },
		goRobot() { uni.switchTab({ url: '/pages/robot/index' }) },
		goDevices() { uni.navigateTo({ url: '/pages/mine/devices' }) },
		statusText(s) { return STATUS_TEXT[s] || s },
		carrierLabel(c) { return CARRIER_TEXT[c] || c },
		assetsOf(id) {
			const m = this.assetsMap[id]
			return (m && m.list) || []
		},
		// 安装指引:兜底交付通道靠它 —— 第三方成品机器人改不了固件,
		// 平台没法自动下发,只能给用户一份"怎么装进去"的说明
		guideOf(id) {
			const m = this.assetsMap[id]
			return (m && m.guide) || ''
		},
		fmtSize(bytes) {
			if (!bytes) return ''
			if (bytes < 1024) return bytes + ' B'
			if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
			return (bytes / 1024 / 1024).toFixed(1) + ' MB'
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			// 技能列表(主数据)失败 → 置错误标记,由 StateView 的错误态承载(带重试);
			// 设备只服务于「指派给…」按钮,拉不到不阻断页面,留 warn 不静默。
			const [sk, dv] = await Promise.all([
				skillApi.mine().catch(e => {
					this.loadError = (e && e.message) || '加载失败'
					return null
				}),
				deviceApi.list().catch(e => { console.warn('[skills] 设备列表加载失败', e); return null }),
			])
			this.skills = (sk && Array.isArray(sk.data)) ? sk.data : []
			this.devices = (dv && Array.isArray(dv.data)) ? dv.data : []
			// B-3 · 试用倒计时起点:取后端算好的剩余秒数(见 SkillController::index 的 trial_remaining_seconds)
			const rem = {}
			this.skills.forEach((s) => {
				if (s.trial) rem[s.id] = Number(s.trial_remaining_seconds) || 0
			})
			this.remaining = rem
			if (Object.keys(rem).length) this.startTick()
			// 已指派设备的技能自动展开交付物,用户不用多点一次
			this.skills.filter(s => s.device_id).forEach(s => this.loadAssets(s))
			this.loading = false
		},
		async assign(skill, device) {
			try {
				await skillApi.assignDevice(skill.id, device.id)
				uni.showToast({ title: '已指派', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '指派失败', icon: 'none' })
			}
		},
		async loadAssets(skill) {
			if (this.assetsMap[skill.id]) return
			try {
				const res = await skillApi.assets(skill.id)
				// 用一个新对象赋值,保证 Vue 能侦测到
				this.assetsMap = {
					...this.assetsMap,
					[skill.id]: {
						list: (res && Array.isArray(res.data)) ? res.data : [],
						guide: (res && res.install_guide) || '',
					},
				}
			} catch (e) {
				// 交付物拉不到只影响这一块的展开,不阻断整页 —— 留 warn 不静默
				console.warn('[skills] 交付物加载失败', skill && skill.id, e)
				this.assetsMap = { ...this.assetsMap, [skill.id]: { list: [], guide: '' } }
			}
		},
		// v3.4 步骤 8:一键下发到已配对设备(agent 通道)。
		// 平台建下发任务 → Agent 轮询取走并验签安装 → 回报推进状态;进度去 deploy 页看。
		// ⚠ skill_id 传的是 product_id —— entitlement 的 id 是授权单号,不是技能商品。
		deployToDevice(skill) {
			const content = skill.device_online
				? `把「${skill.name}」下发到设备 ${skill.device_sn || ''}?`
				: '设备当前离线,任务会排队,上线后自动下发。继续?'
			uni.showModal({
				title: '一键下发',
				content,
				success: async (r) => {
					if (!r.confirm) return
					try {
						const res = await deployApi.create({
							device_id: skill.device_id,
							skill_id: skill.product_id,
							skill_version_id: skill.skill_version_id,
							entitlement_id: skill.id,
							operation: 1,
							channel: 'agent',
						})
						const task = (res && res.data) || {}
						uni.navigateTo({
							url: `/pages/mine/deploy?taskNo=${encodeURIComponent(task.task_no || '')}&skillName=${encodeURIComponent(skill.name || '')}`,
						})
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '下发失败', icon: 'none' })
					}
				},
			})
		},
		// B-6 · 运维操作是否可见。
		// 只有 agent 可交付的技能能远程操作(人工通道平台碰不到那台机器);
		// 已终态(过期/撤销/回收中)的技能不再给操作入口 —— 那些授权已经收回来了。
		canOperate(s) {
			if (!s || !s.device_id || !s.agent_deliverable || !s.skill_version_id) return false
			return ['granted', 'active', 'pending_bind'].indexOf(s.status) >= 0
		},
		// B-6 · 卸载 / 回退 / 启停 —— 与「一键下发」同一条通道,只是 operation 不同。
		// 后端 DeployController::store() 的 operation 白名单 = DeployTask::OPERATIONS,
		// 所以这里只需把码传对,归属校验 / 熔断 / 建任务都在服务端。
		runOperation(skill, operation) {
			const conf = OP_CONFIRM[operation]
			if (!conf) return
			uni.showModal({
				title: conf.title,
				content: conf.build(skill),
				confirmText: conf.okText,
				confirmColor: conf.danger ? '#D93025' : '#165DFF',
				success: async (r) => {
					if (!r.confirm) return
					try {
						const res = await deployApi.create({
							device_id: skill.device_id,
							// ⚠ skill_id 传 product_id —— entitlement 的 id 是授权单号,不是技能商品
							skill_id: skill.product_id,
							skill_version_id: skill.skill_version_id,
							entitlement_id: skill.id,
							operation,
							channel: 'agent',
						})
						const task = (res && res.data) || {}
						// 和安装一样去进度页看结果:设备离线时任务会排队,不在这一页假装已完成
						uni.navigateTo({
							url: `/pages/mine/deploy?taskNo=${encodeURIComponent(task.task_no || '')}&skillName=${encodeURIComponent(skill.name || '')}`,
						})
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
					}
				},
			})
		},
		// 用户自报安装完成。平台无法验证安装结果,这是兜底通道的终点
		confirmInstall(skill) {
			if (skill.status !== 'active' && this.activationLimitReached(skill)) {
				uni.showToast({ title: '激活次数已达上限', icon: 'none' })
				return
			}
			uni.showModal({
				title: '确认已安装',
				content: `确认已按指引把「${skill.name}」装到设备上并可以正常使用？`,
				success: async (r) => {
					if (!r.confirm) return
					try {
						await skillApi.confirmInstall(skill.id)
						uni.showToast({ title: '已标记完成', icon: 'success' })
						await this.refresh()
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
					}
				},
			})
		},
		// v3.4:申请售后/退款。政策由后端判定(试用不退/未下发可退/已激活需人工审),
		// 前端只负责收集理由并提交,不做政策判断 —— 政策是后端唯一事实源
		applyAfterSale(skill) {
			uni.showModal({
				title: '申请售后',
				editable: true,
				placeholderText: '请简述原因(可选)',
				success: async (r) => {
					if (!r.confirm) return
					try {
						const res = await skillApi.applyAfterSale({
							entitlement_id: skill.id,
							reason_category: 'other',
							detail: (r.content || '').trim(),
						})
						uni.showToast({ title: (res && res.message) || '已提交', icon: 'none' })
						await this.refresh()
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
					}
				},
			})
		},
		// B-7 · 举报该技能(target_id = product_id)。技能下发到真机后,这是用户能按的
		// 最后一个刹车 —— 所以「我的技能」里也要有入口,不能只在详情页。
		reportSkill(skill) {
			submitSkillReport({ targetType: 'skill', targetId: skill.product_id, name: skill.name })
		},
		// 下载走鉴权接口(文件在 private 盘,没有公开 URL),所以必须自己带 Authorization 头
		async download(skill, asset) {
			const token = uni.getStorageSync(TOKEN_KEY)
			const url = `${BASE_URL}/api/skills/entitlements/${skill.id}/assets/${asset.id}/download`

			uni.showLoading({ title: '下载中...', mask: true })

			if (typeof window !== 'undefined' && typeof document !== 'undefined' && typeof window.fetch === 'function') {
				try {
					const resp = await window.fetch(url, {
						method: 'GET',
						headers: token ? { Authorization: `Bearer ${token}`, Accept: 'application/json, application/octet-stream, */*' } : {},
					})
					uni.hideLoading()
					if (!resp.ok) {
						let errMsg = '下载失败，请重试'
						try {
							const errJson = await resp.json()
							if (errJson && errJson.message) errMsg = errJson.message
						} catch (e) {}
						uni.showToast({ title: errMsg, icon: 'none' })
						return
					}
					const blob = await resp.blob()
					const blobUrl = window.URL.createObjectURL(blob)
					const a = document.createElement('a')
					a.href = blobUrl
					a.download = (asset && asset.file_name) || `${skill.name || 'skill'}.skill`
					document.body.appendChild(a)
					a.click()
					document.body.removeChild(a)
					window.URL.revokeObjectURL(blobUrl)
					uni.showToast({ title: '已开始下载技能包', icon: 'success' })
				} catch (e) {
					uni.hideLoading()
					uni.showToast({ title: (e && e.message) || '下载失败，请检查网络', icon: 'none' })
				}
				return
			}

			uni.downloadFile({
				url,
				header: token ? { Authorization: `Bearer ${token}` } : {},
				success: (res) => {
					uni.hideLoading()
					if (res.statusCode !== 200) {
						uni.showToast({ title: '下载失败，请重试', icon: 'none' })
						return
					}
					// 小程序端:存到本地文件系统,再把路径给用户
					uni.saveFile({
						tempFilePath: res.tempFilePath,
						success: (r) => {
							uni.showModal({
								title: '下载完成',
								content: `已保存到：${r.savedFilePath}`,
								showCancel: false,
							})
						},
						fail: () => {
							// saveFile 在部分端不可用,退化到"打开文件"
							uni.openDocument({
								filePath: res.tempFilePath,
								showMenu: true,
								fail: () => uni.showToast({ title: '已下载,但无法在本机打开', icon: 'none' }),
							})
						},
					})
				},
				fail: () => {
					uni.hideLoading()
					uni.showToast({ title: '下载失败，请检查网络', icon: 'none' })
				},
			})
		},
	},
}
</script>

<style scoped>
.skills-page { min-height: 100vh; background: #F7F8FA; display: flex; flex-direction: column; }
.scroll-content { flex: 1; height: 0; padding: 20rpx 30rpx; box-sizing: border-box; }

.card {
	background: #FFFFFF; border-radius: 20rpx; padding: 28rpx;
	margin-bottom: 20rpx; box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.skill-head { display: flex; align-items: flex-start; justify-content: space-between; }
.skill-info { flex: 1; min-width: 0; }
.skill-name { display: block; font-size: 32rpx; font-weight: 600; color: #1A2A3A; }
.skill-meta { display: block; font-size: 22rpx; color: #9CA3AF; margin-top: 6rpx; }
.status { font-size: 22rpx; padding: 6rpx 16rpx; border-radius: 20rpx; background: #EEF2F6; color: #6B7280; flex-shrink: 0; }
.status.granted { background: #E6F4EA; color: #1E8E3E; }
.status.active { background: #E6F4EA; color: #1E8E3E; }
.status.pending_bind { background: #FFF4E5; color: #B26A00; }
.status.expired, .status.revoked { background: #FDECEC; color: #D93025; }

.rows { margin-top: 20rpx; }
.row { display: flex; justify-content: space-between; padding: 10rpx 0; }
.row-label { font-size: 26rpx; color: #8A9AA8; }
.row-value { font-size: 26rpx; color: #1A2A3A; }

.bind-area { margin-top: 20rpx; padding-top: 18rpx; border-top: 1rpx solid #EFF3F8; }
.bind-tip { display: block; font-size: 24rpx; color: #8A9AA8; margin-bottom: 14rpx; }
.bind-options { display: flex; flex-wrap: wrap; }
.bind-btn {
	font-size: 24rpx; color: #165DFF; background: #EEF4FF;
	border-radius: 24rpx; padding: 10rpx 24rpx; margin: 0 12rpx 12rpx 0;
}
.bind-btn.add { margin: 0; }

.actions { margin-top: 20rpx; padding-top: 18rpx; border-top: 1rpx solid #EFF3F8; }
.ghost-btn {
	display: inline-block; font-size: 26rpx; color: #165DFF;
	border: 1rpx solid #B9D3FF; border-radius: 30rpx; padding: 10rpx 30rpx;
}

/* 举报入口(B-7):与"申请售后"并列,但弱化为小字 —— 它不是主操作 */
.report-link { font-size: 24rpx; color: #9CA3AF; text-decoration: underline; margin-left: 24rpx; }

/* B-6 · 运维操作组:与上面的「一键下发」视觉上分开,且弱化(它是低频危险操作) */
.ops-label { display: block; font-size: 24rpx; color: #8A9AA8; margin-bottom: 14rpx; }
.ops-row { display: flex; flex-wrap: wrap; }
.ops-btn {
	font-size: 24rpx; color: #6B7280; background: #F4F6F9;
	border-radius: 24rpx; padding: 10rpx 26rpx; margin: 0 12rpx 12rpx 0;
}

.assets { margin-top: 18rpx; }
.asset-item {
	display: flex; align-items: center; justify-content: space-between;
	background: #F8FAFC; border-radius: 14rpx; padding: 16rpx 20rpx; margin-bottom: 12rpx;
}
.asset-info { flex: 1; min-width: 0; }
.asset-name { display: block; font-size: 26rpx; color: #1A2A3A; }
.asset-size { display: block; font-size: 22rpx; color: #9CA3AF; margin-top: 4rpx; }
.download { font-size: 26rpx; color: #165DFF; padding-left: 20rpx; }
.no-download { font-size: 24rpx; color: #9CA3AF; padding-left: 20rpx; }

.guide {
	margin-top: 18rpx;
	background: #FFFBF0;
	border: 1rpx solid #FFE7B3;
	border-radius: 14rpx;
	padding: 20rpx;
}
.guide-title { display: block; font-size: 24rpx; font-weight: 600; color: #B26A00; margin-bottom: 10rpx; }
/* 指引是纯文本(可能多行),用 pre-wrap 保住换行 */
.guide-body { display: block; font-size: 24rpx; color: #6B5A38; line-height: 1.7; white-space: pre-wrap; }

.ghost-btn.primary { color: #FFFFFF; background: #165DFF; border-color: #165DFF; }

.empty-state { display: flex; flex-direction: column; align-items: center; padding: 120rpx 40rpx; }
.empty-icon { font-size: 76rpx; margin-bottom: 20rpx; }
.empty-text { font-size: 28rpx; color: #8A9AA8; margin-bottom: 30rpx; }
.primary-btn {
	width: 300rpx; height: 84rpx; line-height: 84rpx; text-align: center;
	background: #165DFF; color: #FFFFFF; font-size: 30rpx; font-weight: 600; border-radius: 42rpx;
}
</style>
