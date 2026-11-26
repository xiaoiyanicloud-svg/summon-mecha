<template>
	<view class="skills-page">
		<nav-bar title="我的买断技能">
			<template #right>
				<text v-if="loggedIn" class="nav-link" @tap="goDeployHistory">下发记录</text>
			</template>
		</nav-bar>

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可管理买断技能授权、设备指派、在线下发与物理急停"
			action-text="返回登录"
			@action="goMine"
		/>

		<template v-else>
			<view class="filter-bar">
				<view
					v-for="f in filters"
					:key="f.key"
					class="filter-tab"
					:class="{ active: filterKey === f.key }"
					@tap="filterKey = f.key"
				>{{ f.label }}</view>
			</view>

			<scroll-view class="scroll-content" scroll-y>
				<state-view
					:loading="loading"
					:error="loadError"
					:empty="!filteredSkills.length"
					icon="skills"
					:title="skills.length ? '当前筛选下暂无技能' : '尚未开通任何技能授权'"
					:hint="skills.length ? '可切换其他状态筛选查看' : '前往机器人库选购按次买断的标准化技能包'"
					:action-text="skills.length ? '' : '浏览技能市场'"
					@action="goRobot"
					@retry="refresh"
				>
					<view v-for="s in filteredSkills" :key="s.id" class="card skill-card">
						<view class="skill-head">
							<view class="skill-info">
								<view class="title-row">
									<text class="skill-name">{{ s.name }}</text>
									<text v-if="s.trial" class="trial-tag">20分钟试用</text>
									<text v-else class="buyout-tag">买断授权</text>
								</view>
								<text v-if="s.version" class="skill-ver sm-mono">版本 v{{ s.version }}</text>
							</view>
							<text class="status-pill" :class="s.status">{{ statusLabel(s) }}</text>
						</view>

						<view class="rows">
							<view class="row">
								<text class="row-label">指派目标设备</text>
								<text class="row-value sm-mono">{{ s.device_sn || '尚未指派设备' }}</text>
							</view>
							<view v-if="s.trial" class="row">
								<text class="row-label">试用剩余时长</text>
								<text class="row-value warn sm-mono">{{ countdownText(s) }}</text>
							</view>
							<view class="row">
								<text class="row-label">授权开通时间</text>
								<text class="row-value sm-mono">{{ s.granted_at || '—' }}</text>
							</view>
							<view class="row">
								<text class="row-label">已用 / 剩余激活</text>
								<text class="row-value sm-mono">
									{{ activationCountText(s.activations) }} / {{ s.remaining_activations === null ? '不限次' : activationCountText(s.remaining_activations) }}
								</text>
							</view>
						</view>

						<!-- 未指派设备 -->
						<view v-if="!s.device_id" class="bind-area">
							<text class="bind-tip">选择一台名下机器人设备进行指派：</text>
							<view v-if="devices.length" class="bind-options">
								<text v-for="d in devices" :key="d.id" class="bind-btn" @tap="assign(s, d)">
									指派至 {{ d.display_name || d.sn }}
								</text>
							</view>
							<view v-else class="bind-btn add" @tap="goDevices">尚未登记设备，先去登记 ›</view>
						</view>

						<!-- 已指派：主操作栏 -->
						<view v-else class="primary-actions">
							<view
								v-if="s.agent_deliverable && s.skill_version_id"
								class="btn-solid"
								@tap="prepareDeploy(s)"
							>
								{{ s.device_online ? '安全核验并下发' : '下发（离线排队）' }}
							</view>
							<view class="btn-outline" @tap="loadAssets(s)">查看交付物</view>
						</view>

						<!-- 远程执行与紧急停止 E-Stop（仅已安装且支持 Agent 通道） -->
						<view v-if="canOperate(s)" class="exec-panel">
							<view class="exec-head">
								<text class="exec-title">物理运行与设备运维</text>
								<text v-if="execMap[s.id]" class="exec-st sm-mono">执行态：{{ execMap[s.id].status }}</text>
							</view>
							<view class="exec-btns">
								<view class="exec-btn run" @tap="startExecution(s)">远程触发执行</view>
								<view
									v-if="execMap[s.id] && !['succeeded', 'failed', 'cancelled', 'timeout'].includes(execMap[s.id].status)"
									class="exec-btn estop"
									@tap="stopExecution(s)"
								>紧急停止 (E-Stop)</view>
							</view>
							<view class="ops-row">
								<view class="ops-btn" @tap="runOperation(s, OP.ENABLE)">启用</view>
								<view class="ops-btn" @tap="runOperation(s, OP.DISABLE)">停用</view>
								<view class="ops-btn" @tap="runOperation(s, OP.ROLLBACK)">回退版本</view>
								<view class="ops-btn danger" @tap="runOperation(s, OP.UNINSTALL)">卸载</view>
							</view>
						</view>

						<!-- 交付物清单 -->
						<view v-if="assetsOf(s.id).length" class="assets">
							<view v-for="a in assetsOf(s.id)" :key="a.id" class="asset-item">
								<view class="asset-info">
									<text class="asset-name">{{ a.file_name || carrierLabel(a.carrier_type) }}</text>
									<text v-if="a.file_size" class="asset-size sm-mono">{{ fmtSize(a.file_size) }}</text>
								</view>
								<text v-if="a.downloadable" class="download-btn" @tap="download(s, a)">鉴权下载</text>
								<text v-else class="carrier-tag">{{ carrierLabel(a.carrier_type) }}</text>
							</view>
						</view>

						<view v-if="guideOf(s.id)" class="guide-box">
							<text class="guide-title">人工安装指引</text>
							<text class="guide-body">{{ guideOf(s.id) }}</text>
						</view>

						<view v-if="s.device_id && s.usable && s.status !== 'active'" class="manual-confirm-row">
							<text v-if="activationLimitReached(s)" class="limit-tip">激活次数已达上限</text>
							<view v-else class="btn-outline full" @tap="confirmInstall(s)">人工通道：确认已安装至设备</view>
						</view>

						<!-- 评价 / 售后 / 举报底栏 -->
						<view class="card-foot">
							<text v-if="!s.trial" class="foot-link primary" @tap="openReviewModal(s)">评价技能</text>
							<text class="foot-link" @tap="openAfterSaleModal(s)">申请售后/退款</text>
							<text class="foot-link muted" @tap="reportSkill(s)">举报违规</text>
						</view>
					</view>
				</state-view>
				<view style="height: 60rpx;"></view>
			</scroll-view>
		</template>

		<!-- D6 物理风险分级告知与勾选留痕弹窗 -->
		<view v-if="riskModal.visible" class="modal-mask" @tap="riskModal.visible = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">物理安全告知 · {{ riskModal.profile.label || '下发前确认' }}</text>
					<view class="sheet-close" @tap="riskModal.visible = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<view class="risk-summary-box" :class="'rk-' + (riskModal.profile.risk_level || 'unknown')">
						<text class="risk-summary-text">{{ riskModal.profile.summary }}</text>
					</view>
					<view
						v-for="item in (riskModal.profile.items || [])"
						:key="item.key"
						class="check-item"
						@tap="toggleRiskCheck(item.key)"
					>
						<view class="check-box" :class="{ on: riskModal.checked.includes(item.key) }">
							{{ riskModal.checked.includes(item.key) ? '✓' : '' }}
						</view>
						<text class="check-text">{{ item.text }}</text>
					</view>
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="riskModal.visible = false">取消下发</view>
					<view class="btn-solid flex-2" @tap="confirmRiskAndDeploy">确认安全条件并下发</view>
				</view>
			</view>
		</view>

		<!-- 结构化技能售后弹窗 -->
		<view v-if="asModal.visible" class="modal-mask" @tap="asModal.visible = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">申请技能售后 · {{ asModal.skill && asModal.skill.name }}</text>
					<view class="sheet-close" @tap="asModal.visible = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<text class="field-label">售后原因分类</text>
					<view class="reason-chips">
						<text
							v-for="r in asReasons"
							:key="r.key"
							class="reason-chip"
							:class="{ active: asModal.reason_category === r.key }"
							@tap="asModal.reason_category = r.key"
						>{{ r.label }}</text>
					</view>
					<text class="field-label">问题说明（未下发可极速退，已激活转人工核实）</text>
					<textarea class="textarea" v-model="asModal.detail" placeholder="请补充报错现象、机型固件版本或退款诉求" maxlength="500" />
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="asModal.visible = false">取消</view>
					<view class="btn-solid flex-2" @tap="submitAfterSale">提交售后申请</view>
				</view>
			</view>
		</view>

		<!-- 技能评价弹窗 -->
		<view v-if="revModal.visible" class="modal-mask" @tap="revModal.visible = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">评价买断技能 · {{ revModal.skill && revModal.skill.name }}</text>
					<view class="sheet-close" @tap="revModal.visible = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<text class="field-label">综合评分（1~5 分）</text>
					<view class="score-row">
						<view
							v-for="n in [1, 2, 3, 4, 5]"
							:key="n"
							class="score-pill sm-mono"
							:class="{ active: revModal.rating === n }"
							@tap="revModal.rating = n"
						>{{ n }} 分</view>
					</view>
					<text class="field-label">评价标题</text>
					<input class="field-input" type="text" v-model="revModal.title" placeholder="如：动作流畅，展厅迎宾稳定" maxlength="80" />
					<text class="field-label">真机使用体验</text>
					<textarea class="textarea" v-model="revModal.content" placeholder="请分享下发安装过程与实机表现，帮助其他买家参考" maxlength="500" />
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="revModal.visible = false">取消</view>
					<view class="btn-solid flex-2" @tap="submitReview">发布评价</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { skillApi, deviceApi, deployApi, riskApi } from '@/utils/api'
import { submitSkillReport } from '@/utils/report'
import { TOKEN_KEY, BASE_URL } from '@/utils/config'

const STATUS_TEXT = {
	granted: '已开通',
	active: '已安装',
	pending_bind: '待指派',
	expired: '已到期',
	revoked: '已撤销',
	expiring: '回收中',
}

const CARRIER_TEXT = {
	file: '文件包',
	cloud_config: '云端配置',
	remote_service: '远程协助',
	api_bundle: '接口包',
	motion_data: '动作数据',
	app_package: '应用包',
	vendor_package: '厂商资源包',
}

const OP = { INSTALL: 1, UNINSTALL: 2, ROLLBACK: 3, ENABLE: 4, DISABLE: 5 }

const OP_CONFIRM = {
	[OP.UNINSTALL]: {
		title: '卸载技能',
		okText: '确认卸载',
		danger: true,
		build: (s) => `确认从设备 ${s.device_sn || '当前设备'} 卸载「${s.name}」吗？`,
	},
	[OP.ROLLBACK]: {
		title: '回退版本',
		okText: '确认回退',
		danger: true,
		build: (s) => `确认将「${s.name}」回退至上一稳定版本吗？`,
	},
	[OP.ENABLE]: {
		title: '启用技能',
		okText: '确认启用',
		build: (s) => `确认在设备 ${s.device_sn || '当前设备'} 上启用「${s.name}」吗？`,
	},
	[OP.DISABLE]: {
		title: '停用技能',
		okText: '确认停用',
		build: (s) => `确认在设备 ${s.device_sn || '当前设备'} 上暂停运行「${s.name}」吗？`,
	},
}

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			OP,
			loggedIn: false,
			loading: true,
			loadError: '',
			filterKey: 'all',
			filters: [
				{ key: 'all', label: '全部' },
				{ key: 'usable', label: '生效可用' },
				{ key: 'unbound', label: '待指派' },
				{ key: 'history', label: '试用/归档' },
			],
			skills: [],
			devices: [],
			assetsMap: {},
			remaining: {},
			execMap: {},
			tickTimer: null,
			riskModal: { visible: false, skill: null, profile: {}, checked: [] },
			asModal: { visible: false, skill: null, reason_category: 'unable_to_install', detail: '' },
			asReasons: [
				{ key: 'unable_to_install', label: '无法下发安装' },
				{ key: 'incompatible_model', label: '机型固件不兼容' },
				{ key: 'execution_error', label: '执行报错异常' },
				{ key: 'description_mismatch', label: '与宣传描述不符' },
				{ key: 'other', label: '其他原因' },
			],
			revModal: { visible: false, skill: null, rating: 5, title: '', content: '' },
		}
	},
	computed: {
		filteredSkills() {
			const list = this.skills || []
			if (this.filterKey === 'usable') return list.filter(s => ['granted', 'active'].includes(s.status) && !s.trial)
			if (this.filterKey === 'unbound') return list.filter(s => !s.device_id && ['granted', 'pending_bind'].includes(s.status))
			if (this.filterKey === 'history') return list.filter(s => s.trial || ['expired', 'revoked', 'expiring'].includes(s.status))
			return list
		},
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
		this.startTick()
	},
	onHide() { this.stopTick() },
	onUnload() { this.stopTick() },
	methods: {
		goMine() { uni.switchTab({ url: '/pages/mine/index' }) },
		goRobot() { uni.switchTab({ url: '/pages/robot/index' }) },
		goDevices() { uni.navigateTo({ url: '/pages/mine/devices' }) },
		goDeployHistory() { uni.navigateTo({ url: '/pages/mine/deploy' }) },
		activationCountText(value) {
			return typeof value === 'number' && Number.isInteger(value) && value >= 0 ? `${value} 次` : '—'
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
				if (!anyLive) this.stopTick()
			}, 1000)
		},
		stopTick() {
			if (this.tickTimer) { clearInterval(this.tickTimer); this.tickTimer = null }
		},
		countdownText(s) {
			const sec = this.remaining[s.id]
			if (sec === undefined) return s.usable ? '—' : '已结束'
			if (sec <= 0) return '已到期'
			const m = Math.floor(sec / 60)
			const ss = sec % 60
			return `${m}:${ss < 10 ? '0' : ''}${ss}`
		},
		statusLabel(s) {
			if (s.trial && !['expired', 'expiring', 'revoked'].includes(s.status)) return '试用中'
			return STATUS_TEXT[s.status] || s.status
		},
		carrierLabel(c) { return CARRIER_TEXT[c] || c },
		assetsOf(id) { return (this.assetsMap[id] && this.assetsMap[id].list) || [] },
		guideOf(id) { return (this.assetsMap[id] && this.assetsMap[id].guide) || '' },
		fmtSize(bytes) {
			if (!bytes) return ''
			if (bytes < 1024) return `${bytes} B`
			if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
			return `${(bytes / 1024 / 1024).toFixed(1)} MB`
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			const [sk, dv] = await Promise.all([
				skillApi.mine().catch((e) => { this.loadError = (e && e.message) || '加载失败'; return null }),
				deviceApi.list().catch(() => null),
			])
			this.skills = (sk && Array.isArray(sk.data)) ? sk.data : []
			this.devices = (dv && Array.isArray(dv.data)) ? dv.data : []
			const rem = {}
			this.skills.forEach((s) => {
				if (s.trial) rem[s.id] = Number(s.trial_remaining_seconds) || 0
			})
			this.remaining = rem
			if (Object.keys(rem).length) this.startTick()
			this.skills.filter(s => s.device_id).forEach(s => this.loadAssets(s))
			this.loading = false
		},
		async assign(skill, device) {
			try {
				await skillApi.assignDevice(skill.id, device.id)
				uni.showToast({ title: '设备已指派', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '指派失败', icon: 'none' })
			}
		},
		async loadAssets(skill) {
			if (this.assetsMap[skill.id]) return
			try {
				const res = await skillApi.assets(skill.id)
				this.assetsMap = {
					...this.assetsMap,
					[skill.id]: {
						list: (res && Array.isArray(res.data)) ? res.data : [],
						guide: (res && res.install_guide) || '',
					},
				}
			} catch (_) {
				this.assetsMap = { ...this.assetsMap, [skill.id]: { list: [], guide: '' } }
			}
		},
		// D6: 下发前查询物理安全风险档案；含勾选项或高风险时强制逐条留痕
		async prepareDeploy(skill) {
			try {
				const res = await riskApi.profile(skill.product_id)
				const profile = (res && res.data) || null
				if (profile && (profile.require_checklist || (profile.items && profile.items.length))) {
					this.riskModal = {
						visible: true,
						skill,
						profile,
						checked: [],
					}
					return
				}
			} catch (_) {}
			this.executeDeploy(skill)
		},
		toggleRiskCheck(key) {
			const cur = this.riskModal.checked
			this.riskModal.checked = cur.includes(key) ? cur.filter(k => k !== key) : [...cur, key]
		},
		async confirmRiskAndDeploy() {
			const { skill, profile, checked } = this.riskModal
			const requiredItems = profile.items || []
			if (profile.require_checklist && checked.length < requiredItems.length) {
				uni.showToast({ title: '高风险物理技能必须逐条勾选全部安全确认项', icon: 'none' })
				return
			}
			try {
				await riskApi.acknowledge({
					product_id: skill.product_id,
					skill_version_id: skill.skill_version_id || null,
					entitlement_id: skill.id,
					channel: 'agent',
					checked,
				})
				this.riskModal.visible = false
				await this.executeDeploy(skill, true)
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '安全留痕提交失败', icon: 'none' })
			}
		},
		async executeDeploy(skill, skipConfirm = false) {
			const doCreate = async () => {
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
			}
			if (skipConfirm) return doCreate()
			uni.showModal({
				title: '确认下发技能',
				content: skill.device_online
					? `确认将「${skill.name}」下发至设备 ${skill.device_sn || ''}？`
					: '设备当前离线，任务将进入待上线队列，设备重连后自动执行下发。是否继续？',
				success: (r) => { if (r.confirm) doCreate() },
			})
		},
		canOperate(s) {
			if (!s || !s.device_id || !s.agent_deliverable || !s.skill_version_id) return false
			return ['granted', 'active', 'pending_bind'].includes(s.status)
		},
		async startExecution(s) {
			try {
				const res = await skillApi.triggerExecution(s.id, {})
				const d = (res && res.data) || {}
				const execId = d.execution_id || d.id
				this.execMap = { ...this.execMap, [s.id]: { id: execId, status: d.status || 'dispatched' } }
				uni.showToast({ title: '已下发执行指令', icon: 'success' })
				if (execId) this.pollExecution(s.id, execId)
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '触发执行失败', icon: 'none' })
			}
		},
		async pollExecution(entId, execId) {
			try {
				const res = await skillApi.executionStatus(execId)
				const d = (res && res.data) || {}
				this.execMap = { ...this.execMap, [entId]: { id: execId, status: d.status || 'running' } }
			} catch (_) {}
		},
		async stopExecution(s) {
			const cur = this.execMap[s.id]
			if (!cur || !cur.id) return
			try {
				await skillApi.cancelExecution(cur.id)
				this.execMap = { ...this.execMap, [s.id]: { ...cur, status: 'cancelling' } }
				uni.showToast({ title: '紧急停止指令已发出', icon: 'none' })
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '急停发送失败', icon: 'none' })
			}
		},
		runOperation(skill, operation) {
			const conf = OP_CONFIRM[operation]
			if (!conf) return
			uni.showModal({
				title: conf.title,
				content: conf.build(skill),
				confirmText: conf.okText,
				confirmColor: conf.danger ? '#DC2626' : '#165DFF',
				success: async (r) => {
					if (!r.confirm) return
					try {
						const res = await deployApi.create({
							device_id: skill.device_id,
							skill_id: skill.product_id,
							skill_version_id: skill.skill_version_id,
							entitlement_id: skill.id,
							operation,
							channel: 'agent',
						})
						const task = (res && res.data) || {}
						uni.navigateTo({
							url: `/pages/mine/deploy?taskNo=${encodeURIComponent(task.task_no || '')}&skillName=${encodeURIComponent(skill.name || '')}`,
						})
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '操作失败', icon: 'none' })
					}
				},
			})
		},
		confirmInstall(skill) {
			if (skill.status !== 'active' && this.activationLimitReached(skill)) {
				uni.showToast({ title: '激活次数已达上限', icon: 'none' })
				return
			}
			uni.showModal({
				title: '确认已人工安装',
				content: `确认已按照安装指引将「${skill.name}」安装至设备并可正常使用？`,
				success: async (r) => {
					if (!r.confirm) return
					try {
						await skillApi.confirmInstall(skill.id)
						uni.showToast({ title: '已标记安装完成', icon: 'success' })
						await this.refresh()
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
					}
				},
			})
		},
		openAfterSaleModal(skill) {
			this.asModal = {
				visible: true,
				skill,
				reason_category: 'unable_to_install',
				detail: '',
			}
		},
		async submitAfterSale() {
			const { skill, reason_category, detail } = this.asModal
			if (!skill) return
			try {
				const res = await skillApi.applyAfterSale({
					entitlement_id: skill.id,
					reason_category,
					detail: String(detail || '').trim(),
				})
				this.asModal.visible = false
				uni.showToast({ title: (res && res.message) || '售后单已提交', icon: 'none' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
			}
		},
		openReviewModal(skill) {
			this.revModal = {
				visible: true,
				skill,
				rating: 5,
				title: '',
				content: '',
			}
		},
		async submitReview() {
			const { skill, rating, title, content } = this.revModal
			if (!skill) return
			if (!String(title || '').trim()) { uni.showToast({ title: '请填写简短评价标题', icon: 'none' }); return }
			if (!String(content || '').trim()) { uni.showToast({ title: '请填写实机体验内容', icon: 'none' }); return }
			try {
				await skillApi.createReview({
					skill_id: skill.product_id,
					rating,
					title: title.trim(),
					content: content.trim(),
				})
				this.revModal.visible = false
				uni.showToast({ title: '评价已提交', icon: 'success' })
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '提交评价失败', icon: 'none' })
			}
		},
		reportSkill(skill) {
			submitSkillReport({ targetType: 'skill', targetId: skill.product_id, name: skill.name })
		},
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
						} catch (_) {}
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
					uni.saveFile({
						tempFilePath: res.tempFilePath,
						success: (r) => {
							uni.showModal({
								title: '下载完成',
								content: `已保存至：${r.savedFilePath}`,
								showCancel: false,
							})
						},
						fail: () => uni.showToast({ title: '本地保存失败', icon: 'none' }),
					})
				},
				fail: () => {
					uni.hideLoading()
					uni.showToast({ title: '网络异常，下载失败', icon: 'none' })
				},
			})
		},
	},
}
</script>

<style scoped>
.skills-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.nav-link { font-size: 26rpx; font-weight: 600; color: #165DFF; }
.filter-bar { display: flex; margin: 18rpx 28rpx 0; background: #E9EEF6; border-radius: 16rpx; padding: 6rpx; }
.filter-tab { flex: 1; height: 64rpx; border-radius: 12rpx; display: flex; align-items: center; justify-content: center; font-size: 25rpx; font-weight: 600; color: #475569; }
.filter-tab.active { background: #FFFFFF; color: #165DFF; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06); }
.scroll-content { flex: 1; padding: 20rpx 28rpx; box-sizing: border-box; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.skill-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16rpx; margin-bottom: 16rpx; }
.skill-info { flex: 1; min-width: 0; }
.title-row { display: flex; align-items: center; flex-wrap: wrap; gap: 12rpx; }
.skill-name { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.buyout-tag { font-size: 21rpx; color: #165DFF; background: #EEF4FF; padding: 2rpx 12rpx; border-radius: 6rpx; font-weight: 600; }
.trial-tag { font-size: 21rpx; color: #D97706; background: #FFFBEB; padding: 2rpx 12rpx; border-radius: 6rpx; font-weight: 600; }
.skill-ver { display: block; font-size: 23rpx; color: #64748B; margin-top: 6rpx; }
.status-pill { font-size: 23rpx; font-weight: 600; padding: 4rpx 14rpx; border-radius: 999rpx; background: #EEF4FF; color: #165DFF; flex-shrink: 0; }
.status-pill.active { background: #ECFDF5; color: #059669; }
.status-pill.expired, .status-pill.revoked { background: #F1F5F9; color: #64748B; }
.rows { padding: 14rpx 0; border-top: 1rpx solid #F1F5F9; border-bottom: 1rpx solid #F1F5F9; }
.row { display: flex; justify-content: space-between; padding: 8rpx 0; font-size: 25rpx; }
.row-label { color: #64748B; }
.row-value { color: #0F172A; font-weight: 500; }
.row-value.warn { color: #D97706; font-weight: 700; }
.bind-area { margin-top: 20rpx; padding: 20rpx; background: #F8FAFC; border-radius: 16rpx; }
.bind-tip { display: block; font-size: 24rpx; color: #475569; margin-bottom: 14rpx; }
.bind-options { display: flex; flex-wrap: wrap; gap: 12rpx; }
.bind-btn { font-size: 24rpx; font-weight: 600; color: #165DFF; background: #EEF4FF; padding: 10rpx 22rpx; border-radius: 999rpx; }
.bind-btn.add { border: 1rpx solid #165DFF; background: #FFFFFF; display: inline-block; }
.primary-actions { display: flex; gap: 16rpx; margin-top: 22rpx; }
.btn-solid { flex: 1; height: 82rpx; border-radius: 16rpx; background: #165DFF; color: #FFFFFF; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-outline { flex: 1; height: 82rpx; border-radius: 16rpx; background: #EEF4FF; border: 1rpx solid #C7DCFF; color: #165DFF; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-outline.full { width: 100%; }
.flex-2 { flex: 2; }
.exec-panel { margin-top: 20rpx; padding: 22rpx; border-radius: 18rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; }
.exec-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16rpx; }
.exec-title { font-size: 25rpx; font-weight: 600; color: #334155; }
.exec-st { font-size: 23rpx; color: #165DFF; font-weight: 600; }
.exec-btns { display: flex; gap: 14rpx; margin-bottom: 16rpx; }
.exec-btn { flex: 1; height: 74rpx; border-radius: 14rpx; font-size: 26rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.exec-btn.run { background: #102F6E; color: #FFFFFF; }
.exec-btn.estop { background: #DC2626; color: #FFFFFF; }
.ops-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12rpx; }
.ops-btn { height: 64rpx; border-radius: 12rpx; background: #FFFFFF; border: 1rpx solid #CBD5E1; font-size: 24rpx; color: #334155; display: flex; align-items: center; justify-content: center; }
.ops-btn.danger { color: #DC2626; border-color: #FECACA; }
.assets { margin-top: 18rpx; padding: 16rpx 20rpx; background: #F8FAFC; border-radius: 14rpx; }
.asset-item { display: flex; justify-content: space-between; align-items: center; padding: 10rpx 0; }
.asset-info { flex: 1; min-width: 0; }
.asset-name { display: block; font-size: 25rpx; color: #0F172A; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.asset-size { font-size: 22rpx; color: #64748B; }
.download-btn { font-size: 24rpx; font-weight: 600; color: #165DFF; padding: 6rpx 16rpx; background: #EEF4FF; border-radius: 8rpx; }
.carrier-tag { font-size: 22rpx; color: #64748B; }
.guide-box { margin-top: 18rpx; padding: 20rpx; background: #F8FAFC; border-radius: 14rpx; }
.guide-title { display: block; font-size: 24rpx; font-weight: 600; color: #334155; margin-bottom: 6rpx; }
.guide-body { font-size: 24rpx; color: #475569; line-height: 1.55; }
.manual-confirm-row { margin-top: 18rpx; }
.limit-tip { font-size: 23rpx; color: #64748B; }
.card-foot { display: flex; justify-content: flex-end; gap: 28rpx; margin-top: 20rpx; padding-top: 18rpx; border-top: 1rpx solid #F1F5F9; }
.foot-link { font-size: 25rpx; color: #475569; }
.foot-link.primary { color: #165DFF; font-weight: 600; }
.foot-link.muted { color: #94A3B8; }
.modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 100; display: flex; align-items: flex-end; }
.modal-sheet { width: 100%; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; padding-bottom: env(safe-area-inset-bottom); }
.sheet-head { display: flex; justify-content: space-between; align-items: center; padding: 30rpx 32rpx 22rpx; border-bottom: 1rpx solid #EEF2F7; }
.sheet-title { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.sheet-close { padding: 8rpx; }
.sheet-body { padding: 24rpx 32rpx; max-height: 62vh; overflow-y: auto; }
.sheet-foot { display: flex; gap: 20rpx; padding: 16rpx 32rpx 28rpx; border-top: 1rpx solid #EEF2F7; }
.btn-cancel { flex: 1; height: 82rpx; border-radius: 16rpx; background: #F1F5F9; color: #475569; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.risk-summary-box { padding: 20rpx 22rpx; border-radius: 14rpx; background: #FFFBEB; border: 1rpx solid #FDE68A; margin-bottom: 20rpx; }
.risk-summary-box.rk-high { background: #FEF2F2; border-color: #FECACA; }
.risk-summary-text { font-size: 25rpx; color: #0F172A; line-height: 1.55; }
.check-item { display: flex; align-items: flex-start; gap: 16rpx; padding: 16rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.check-box { width: 38rpx; height: 38rpx; border-radius: 8rpx; border: 2rpx solid #CBD5E1; display: flex; align-items: center; justify-content: center; font-size: 24rpx; color: #FFFFFF; flex-shrink: 0; margin-top: 2rpx; }
.check-box.on { background: #165DFF; border-color: #165DFF; }
.check-text { flex: 1; font-size: 26rpx; color: #334155; line-height: 1.5; }
.field-label { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin: 14rpx 0 10rpx; }
.reason-chips { display: flex; flex-wrap: wrap; gap: 12rpx; margin-bottom: 16rpx; }
.reason-chip { padding: 10rpx 20rpx; border-radius: 999rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; font-size: 24rpx; color: #475569; }
.reason-chip.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.field-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; }
.textarea { width: 100%; min-height: 140rpx; padding: 18rpx 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; line-height: 1.5; }
.score-row { display: flex; gap: 14rpx; margin-bottom: 12rpx; }
.score-pill { flex: 1; height: 68rpx; border-radius: 12rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-size: 26rpx; color: #475569; font-weight: 600; }
.score-pill.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; }
</style>
