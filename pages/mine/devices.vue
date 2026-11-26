<template>
	<view class="devices-page">
		<nav-bar title="我的设备" />

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可登记机器人序列号、配对端侧 Agent 并指派买断技能"
			action-text="返回登录"
			@action="goMine"
		/>

		<scroll-view v-else class="scroll-content" scroll-y>
			<!-- Agent 配对入口（工业蓝白卡片） -->
			<view class="agent-banner" @tap="goAgentSetup()">
				<view class="ab-icon"><SmIcon name="bolt" :size="44" /></view>
				<view class="ab-info">
					<text class="ab-title">机器人端侧 Agent 配对向导</text>
					<text class="ab-sub">支持一键生成 30 分钟单次配对令，完成配对后支持远程下发与急停</text>
				</view>
				<SmIcon name="chevron-right" :size="26" />
			</view>

			<!-- 登记新设备 -->
			<view class="card">
				<view class="card-head">
					<text class="card-title">登记机器设备</text>
					<text class="card-tag">未核验登记制</text>
				</view>

				<input class="input sm-mono" v-model="form.sn" placeholder="设备出厂序列号 SN（必填）" maxlength="100" />
				<input class="input" v-model="form.name" placeholder="设备备注名，如：展厅 1 号迎宾机（选填）" maxlength="100" />
				<input class="input sm-mono" v-model="form.national_code" placeholder="国家统一机器人编码（选填）" maxlength="64" />

				<picker :range="modelPickerLabels" :value="modelIndex" @change="onPickModel">
					<view class="picker-row">
						<text class="picker-lbl">适配本体型号</text>
						<view class="picker-right">
							<text class="picker-val">{{ selectedModelLabel }}</text>
							<text class="picker-arrow">›</text>
						</view>
					</view>
				</picker>

				<view v-if="selectedModel && selectedModel.support_level_label" class="level-tip">
					<text class="level-badge" :class="'lv-' + (selectedModel.support_level || 'L3').toLowerCase()">
						{{ selectedModel.support_level || 'L3' }}
					</text>
					<text class="level-text">{{ selectedModel.support_level_label }}</text>
				</view>

				<view class="primary-btn" :class="{ disabled: saving }" @tap="submitRegister">
					{{ saving ? '正在登记...' : '确认登记设备' }}
				</view>

				<view class="honest-box">
					<text class="honest-title">登记说明（诚实口径）</text>
					<text class="honest-body">平台暂无厂商出厂数据库直连核验通道，登记序列号仅用于您名下资产管理与授权归属，固定标注为「未核验」；唯有机器端运行 Agent 握手成功后，才会点亮「Agent 已配对 / 在线」。</text>
				</view>
			</view>

			<!-- 设备列表 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!devices.length"
				icon="devices"
				title="尚未登记任何机器人设备"
				hint="在上方输入机器序列号即可完成登记"
				@retry="refresh"
			>
				<view v-for="d in devices" :key="d.id" class="card device-card">
					<view class="device-top">
						<view class="device-title-wrap">
							<view class="sn-line">
								<text class="device-sn sm-mono">{{ d.sn }}</text>
								<text class="badge verify">{{ d.verify_label || '未核验' }}</text>
								<text v-if="d.agent_paired" class="badge" :class="d.agent_online ? 'online' : 'paired'">
									{{ d.agent_online ? 'Agent 在线' : 'Agent 已配对' }}
								</text>
							</view>
							<text class="device-name">{{ d.display_name || '未命名机器设备' }}</text>
						</view>
						<view class="top-links">
							<text class="link-btn" @tap="openEditModal(d)">编辑</text>
							<text class="link-btn danger" @tap="removeDevice(d)">移除</text>
						</view>
					</view>

					<view class="meta-grid">
						<text class="meta-item">
							本体型号：{{ (d.device_model && `${d.device_model.brand} ${d.device_model.model_name}`) || (d.product && d.product.name) || '未指定型号' }}
							<text v-if="d.device_model && d.device_model.support_level" class="inline-lv">（{{ d.device_model.support_level }}）</text>
						</text>
						<text v-if="d.national_code" class="meta-item sm-mono">国家编码：{{ d.national_code }}</text>
						<text class="meta-item sm-mono">登记时间：{{ d.bound_at || '—' }}</text>
					</view>

					<!-- 已指派技能授权 -->
					<view v-if="d.skills && d.skills.length" class="sub-block">
						<text class="sub-title">已指派技能授权（{{ d.skills.length }}）</text>
						<view v-for="s in d.skills" :key="s.id" class="skill-row">
							<text class="skill-name">{{ s.name }}</text>
							<text class="skill-st">{{ statusText(s.status) }}</text>
						</view>
					</view>

					<!-- Agent 端侧已装实况 -->
					<view v-if="installsMap[d.id] && installsMap[d.id].length" class="sub-block">
						<text class="sub-title">设备端已安装技能包（{{ installsMap[d.id].length }}）</text>
						<view v-for="ins in installsMap[d.id]" :key="ins.skill_id + '_' + ins.version" class="skill-row">
							<text class="skill-name">{{ ins.skill_name }} <text class="ver sm-mono">v{{ ins.version }}</text></text>
							<text class="skill-st">{{ ins.state === 'enabled' ? '已启用' : ins.state }}</text>
						</view>
					</view>

					<view class="device-actions">
						<text class="act-chip" @tap="goAgentSetup(d.id)">{{ d.agent_paired ? '重新配对 Agent' : '配对 Agent' }}</text>
						<text v-if="d.agent_paired" class="act-chip" @tap="loadDeviceInstalls(d.id)">刷新已装包</text>
						<text v-if="d.has_agent_credential || d.agent_paired" class="act-chip warn" @tap="revokeCredential(d)">吊销设备凭据</text>
					</view>
				</view>

				<!-- 待指派技能快捷栏 -->
				<view v-if="pendingSkills.length && devices.length" class="card">
					<text class="card-title">待指派设备的买断技能</text>
					<view v-for="e in pendingSkills" :key="e.id" class="pending-row">
						<text class="pending-name">{{ e.name }}</text>
						<view class="pending-btns">
							<text v-for="d in devices" :key="d.id" class="assign-pill" @tap="assignSkill(e, d)">
								指派至 {{ d.display_name || d.sn }}
							</text>
						</view>
					</view>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- 编辑设备弹窗 -->
		<view v-if="editingDevice" class="modal-mask" @tap="editingDevice = null">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">编辑设备档案 · {{ editingDevice.sn }}</text>
					<view class="sheet-close" @tap="editingDevice = null"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<text class="field-lbl">设备备注名</text>
					<input class="input" v-model="editForm.display_name" placeholder="如：展厅 1 号机" maxlength="100" />

					<text class="field-lbl">国家统一编码（选填）</text>
					<input class="input sm-mono" v-model="editForm.national_code" placeholder="支持后续补录机器人国家编码" maxlength="64" />

					<text class="field-lbl">适配本体型号</text>
					<picker :range="modelPickerLabels" :value="editModelIndex" @change="onPickEditModel">
						<view class="picker-row">
							<text class="picker-lbl">当前选择</text>
							<text class="picker-val">{{ editSelectedModelLabel }} ›</text>
						</view>
					</picker>
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="editingDevice = null">取消</view>
					<view class="primary-btn flex-2" @tap="submitEdit">保存修改</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { deviceApi, skillApi, deployApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

const STATUS_TEXT = {
	granted: '已开通',
	active: '生效中',
	pending_bind: '待指派',
	expired: '已到期',
	revoked: '已撤销',
}

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			saving: false,
			devices: [],
			skills: [],
			models: [],
			installsMap: {},
			modelIndex: 0,
			form: { sn: '', name: '', national_code: '' },
			editingDevice: null,
			editModelIndex: 0,
			editForm: { display_name: '', national_code: '' },
		}
	},
	computed: {
		modelOptions() {
			return [
				{ id: null, label: '暂不指定型号' },
				...this.models.map(m => ({
					...m,
					label: `${m.brand} ${m.model_name}（${m.support_level || 'L3'}）`,
				})),
			]
		},
		modelPickerLabels() {
			return this.modelOptions.map(m => m.label)
		},
		selectedModel() {
			return this.modelOptions[this.modelIndex] || this.modelOptions[0]
		},
		selectedModelLabel() {
			return (this.selectedModel && this.selectedModel.label) || '暂不指定型号'
		},
		editSelectedModel() {
			return this.modelOptions[this.editModelIndex] || this.modelOptions[0]
		},
		editSelectedModelLabel() {
			return (this.editSelectedModel && this.editSelectedModel.label) || '暂不指定型号'
		},
		pendingSkills() {
			return (this.skills || []).filter(s => !s.device_id && (s.status === 'active' || s.status === 'granted'))
		},
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	methods: {
		goMine() {
			uni.switchTab({ url: '/pages/mine/index' })
		},
		goAgentSetup(deviceId = '') {
			const q = deviceId ? `?device_id=${deviceId}` : ''
			uni.navigateTo({ url: `/pages/mine/agent-setup${q}` })
		},
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
				this.loadError = (e && e.message) || '加载设备列表失败'
				this.devices = []
			}
		},
		async loadSkills() {
			try {
				const res = await skillApi.mine()
				this.skills = (res && Array.isArray(res.data)) ? res.data : []
			} catch (_) {
				this.skills = []
			}
		},
		async loadModels() {
			try {
				const res = await deviceApi.models()
				this.models = (res && Array.isArray(res.data)) ? res.data : []
			} catch (_) {
				this.models = []
			}
		},
		onPickModel(e) {
			this.modelIndex = Number(e.detail.value) || 0
		},
		onPickEditModel(e) {
			this.editModelIndex = Number(e.detail.value) || 0
		},
		async submitRegister() {
			const sn = String(this.form.sn || '').trim()
			if (!sn) {
				uni.showToast({ title: '请输入设备序列号 SN', icon: 'none' })
				return
			}
			if (this.saving) return
			this.saving = true
			try {
				await deviceApi.register({
					sn,
					model_id: this.selectedModel.id || null,
					display_name: String(this.form.name || '').trim() || null,
					national_code: String(this.form.national_code || '').trim() || null,
				})
				uni.showToast({ title: '设备已登记', icon: 'success' })
				this.form = { sn: '', name: '', national_code: '' }
				this.modelIndex = 0
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '登记失败', icon: 'none' })
			} finally {
				this.saving = false
			}
		},
		openEditModal(d) {
			this.editingDevice = d
			this.editForm = {
				display_name: d.display_name || '',
				national_code: d.national_code || '',
			}
			const idx = this.modelOptions.findIndex(m => Number(m.id) === Number(d.model_id))
			this.editModelIndex = idx >= 0 ? idx : 0
		},
		async submitEdit() {
			if (!this.editingDevice) return
			try {
				await deviceApi.update(this.editingDevice.id, {
					display_name: String(this.editForm.display_name || '').trim() || null,
					national_code: String(this.editForm.national_code || '').trim() || null,
					model_id: this.editSelectedModel.id || null,
				})
				this.editingDevice = null
				uni.showToast({ title: '设备档案已更新', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '更新失败', icon: 'none' })
			}
		},
		async loadDeviceInstalls(deviceId) {
			try {
				const res = await deployApi.installs(deviceId)
				const rows = (res && Array.isArray(res.data)) ? res.data : []
				this.installsMap = { ...this.installsMap, [deviceId]: rows }
				if (!rows.length) {
					uni.showToast({ title: '该设备暂无 Agent 上报的已装技能包', icon: 'none' })
				}
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '查询失败', icon: 'none' })
			}
		},
		revokeCredential(d) {
			uni.showModal({
				title: '吊销设备 Agent 凭据',
				content: `吊销后，设备 ${d.sn} 当前运行的端侧 Agent 将立即失去拉取指令与包下载权限，需重新生成配对令激活。是否继续？`,
				confirmColor: '#DC2626',
				success: async (res) => {
					if (!res.confirm) return
					try {
						await deviceApi.revokeAgentCredential(d.id)
						uni.showToast({ title: '设备凭据已吊销', icon: 'success' })
						await this.refresh()
					} catch (e) {
						uni.showToast({ title: (e && e.message) || '吊销失败', icon: 'none' })
					}
				},
			})
		},
		removeDevice(d) {
			uni.showModal({
				title: '移除设备',
				content: `确定移除设备 ${d.sn} 吗？已指派给它的买断技能将退回「待指派」状态。`,
				confirmColor: '#DC2626',
				success: async (res) => {
					if (!res.confirm) return
					try {
						await deviceApi.remove(d.id)
						uni.showToast({ title: '已移除设备', icon: 'success' })
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
				uni.showToast({ title: '指派成功', icon: 'success' })
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '指派失败', icon: 'none' })
			}
		},
	},
}
</script>

<style scoped>
.devices-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.agent-banner { background: #FFFFFF; border: 1rpx solid #D6E4FF; border-radius: 22rpx; padding: 26rpx 28rpx; margin-bottom: 20rpx; display: flex; align-items: center; gap: 20rpx; }
.ab-icon { width: 80rpx; height: 80rpx; border-radius: 18rpx; background: #EEF4FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ab-info { flex: 1; display: flex; flex-direction: column; gap: 6rpx; }
.ab-title { font-size: 29rpx; font-weight: 600; color: #0F172A; }
.ab-sub { font-size: 23rpx; color: #64748B; line-height: 1.45; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20rpx; }
.card-title { font-size: 30rpx; font-weight: 600; color: #0F172A; }
.card-tag { font-size: 22rpx; color: #64748B; background: #F1F5F9; padding: 4rpx 14rpx; border-radius: 999rpx; }
.input { width: 100%; height: 84rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; margin-bottom: 16rpx; }
.picker-row { height: 84rpx; padding: 0 22rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; display: flex; align-items: center; justify-content: space-between; margin-bottom: 16rpx; }
.picker-lbl { font-size: 27rpx; color: #475569; }
.picker-right { display: flex; align-items: center; gap: 8rpx; }
.picker-val { font-size: 27rpx; font-weight: 500; color: #0F172A; }
.picker-arrow { font-size: 34rpx; color: #94A3B8; }
.level-tip { display: flex; align-items: center; gap: 12rpx; padding: 14rpx 18rpx; background: #F8FAFC; border-radius: 12rpx; margin-bottom: 18rpx; }
.level-badge { font-size: 21rpx; font-weight: 700; padding: 2rpx 12rpx; border-radius: 6rpx; }
.lv-l1 { background: #ECFDF5; color: #059669; }
.lv-l2 { background: #EEF4FF; color: #165DFF; }
.lv-l3 { background: #FEF3C7; color: #B45309; }
.level-text { flex: 1; font-size: 23rpx; color: #475569; }
.primary-btn { height: 86rpx; border-radius: 18rpx; background: #165DFF; color: #FFFFFF; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.primary-btn.disabled { opacity: 0.6; }
.flex-2 { flex: 2; }
.honest-box { margin-top: 22rpx; padding: 20rpx 22rpx; border-radius: 16rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; }
.honest-title { display: block; font-size: 24rpx; font-weight: 600; color: #334155; margin-bottom: 6rpx; }
.honest-body { display: block; font-size: 23rpx; color: #64748B; line-height: 1.55; }
.device-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16rpx; margin-bottom: 16rpx; }
.device-title-wrap { flex: 1; min-width: 0; }
.sn-line { display: flex; flex-wrap: wrap; align-items: center; gap: 10rpx; margin-bottom: 8rpx; }
.device-sn { font-size: 30rpx; font-weight: 700; color: #0F172A; }
.badge { font-size: 21rpx; font-weight: 600; padding: 3rpx 12rpx; border-radius: 8rpx; }
.badge.verify { background: #F1F5F9; color: #64748B; }
.badge.paired { background: #EEF4FF; color: #165DFF; }
.badge.online { background: #ECFDF5; color: #059669; }
.device-name { font-size: 26rpx; color: #334155; }
.top-links { display: flex; gap: 22rpx; flex-shrink: 0; }
.link-btn { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.link-btn.danger { color: #DC2626; }
.meta-grid { display: flex; flex-direction: column; gap: 6rpx; padding: 16rpx 0; border-top: 1rpx solid #F1F5F9; border-bottom: 1rpx solid #F1F5F9; }
.meta-item { font-size: 24rpx; color: #64748B; }
.inline-lv { color: #165DFF; font-weight: 600; }
.sub-block { margin-top: 18rpx; padding: 18rpx 20rpx; background: #F8FAFC; border-radius: 14rpx; }
.sub-title { display: block; font-size: 23rpx; font-weight: 600; color: #475569; margin-bottom: 10rpx; }
.skill-row { display: flex; justify-content: space-between; align-items: center; padding: 8rpx 0; }
.skill-name { font-size: 26rpx; color: #0F172A; font-weight: 500; }
.ver { font-size: 22rpx; color: #64748B; margin-left: 8rpx; }
.skill-st { font-size: 23rpx; color: #165DFF; font-weight: 500; }
.device-actions { display: flex; flex-wrap: wrap; gap: 14rpx; margin-top: 20rpx; }
.act-chip { font-size: 24rpx; font-weight: 500; color: #165DFF; background: #EEF4FF; padding: 10rpx 22rpx; border-radius: 999rpx; }
.act-chip.warn { color: #DC2626; background: #FEF2F2; }
.pending-row { padding: 18rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.pending-name { display: block; font-size: 27rpx; font-weight: 600; color: #0F172A; margin-bottom: 12rpx; }
.pending-btns { display: flex; flex-wrap: wrap; gap: 12rpx; }
.assign-pill { font-size: 23rpx; color: #165DFF; border: 1rpx solid #165DFF; padding: 8rpx 18rpx; border-radius: 999rpx; }
.modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 100; display: flex; align-items: flex-end; }
.modal-sheet { width: 100%; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; padding-bottom: env(safe-area-inset-bottom); }
.sheet-head { display: flex; justify-content: space-between; align-items: center; padding: 30rpx 32rpx 22rpx; border-bottom: 1rpx solid #EEF2F7; }
.sheet-title { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.sheet-close { padding: 8rpx; }
.sheet-body { padding: 24rpx 32rpx; }
.field-lbl { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.sheet-foot { display: flex; gap: 20rpx; padding: 12rpx 32rpx 28rpx; }
.btn-cancel { flex: 1; height: 86rpx; border-radius: 18rpx; background: #F1F5F9; color: #475569; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
</style>
