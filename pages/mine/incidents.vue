<template>
	<view class="incident-page">
		<nav-bar title="物理安全事故上报" />

		<state-view
			v-if="!loggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可上报设备运行事故并冻结技能版本与授权证据链"
			action-text="返回登录"
			@action="goMine"
		/>

		<scroll-view v-else class="scroll-content" scroll-y>
			<view class="alert-card">
				<text class="alert-title">现场存在人员受伤或现实危险，请立即拨打 120 / 110</text>
				<text class="alert-body">本通道用于事后证据链存证与责任调查，不具备实时急救调度功能。请先切断机器电源或按下物理急停开关确保现场安全。</text>
			</view>

			<view class="card">
				<text class="sec-title">事故信息填报</text>

				<text class="field-label">事故严重等级 <text class="req">*</text></text>
				<view class="sev-grid">
					<view
						v-for="s in severities"
						:key="s.value"
						class="sev-item"
						:class="[s.value, { active: form.severity === s.value }]"
						@tap="form.severity = s.value"
					>
						<text class="sev-name">{{ s.label }}</text>
						<text class="sev-desc">{{ s.desc }}</text>
					</view>
				</view>

				<text class="field-label">事发日期与时间（选填，辅助日志对齐）</text>
				<view class="dt-row">
					<picker mode="date" :value="occurredDate" @change="e => occurredDate = e.detail.value" class="flex-1">
						<view class="picker-box sm-mono">{{ occurredDate || '选择事发日期' }}</view>
					</picker>
					<picker mode="time" :value="occurredTime" @change="e => occurredTime = e.detail.value" class="flex-1">
						<view class="picker-box sm-mono">{{ occurredTime || '选择大概时刻' }}</view>
					</picker>
				</view>

				<text class="field-label">涉及机器设备（建议选择以自动锁定配对档案）</text>
				<picker :range="deviceLabels" @change="onDevicePick">
					<view class="picker-box">{{ pickedDeviceText }}</view>
				</picker>

				<text class="field-label">涉及买断技能（建议选择以锁定包 SHA-256 指纹）</text>
				<picker :range="skillLabels" @change="onSkillPick">
					<view class="picker-box">{{ pickedSkillText }}</view>
				</picker>

				<text class="field-label">事发经过与后果描述 <text class="req">*</text></text>
				<textarea
					class="nt-input"
					v-model="form.description"
					placeholder="请描述现场环境、机器当时执行的动作、碰撞或停机过程及实际损失情况"
					maxlength="2000"
				/>

				<text class="field-label">外部证据说明或链接（每行一条，选填）</text>
				<textarea
					class="nt-input small"
					v-model="evidenceText"
					placeholder="如：监控录像网盘链接 https://... 或已交由驻场工程师留存"
					maxlength="2000"
				/>
				<text class="hint">注：事故影像属高敏定责资料，当前仅采集文字索引与链接，专员介入后将通过安全通道提取原件。</text>

				<view class="submit-btn" :class="{ disabled: submitting }" @tap="submit">
					{{ submitting ? '正在固化证据链并上报...' : '提交事故上报' }}
				</view>
			</view>

			<text class="list-title">历史报案记录</text>

			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!list.length"
				compact
				icon="shield-lock"
				title="暂无历史事故上报记录"
				@retry="refresh"
			>
				<view v-for="it in list" :key="it.id" class="card incident-item" @tap="goDetail(it)">
					<view class="ii-head">
						<text class="ii-no sm-mono">{{ it.incident_no }}</text>
						<text class="ii-sev" :class="it.severity">{{ it.severity_label }}</text>
					</view>
					<view class="ii-foot">
						<text class="ii-status">{{ it.status_label }}</text>
						<view class="ii-right">
							<text class="ii-time sm-mono">{{ it.created_at }}</text>
							<text class="ii-arrow">查看详情 ›</text>
						</view>
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
import { incidentApi, deviceApi, skillApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'

const SEVERITIES = [
	{ value: 'minor', label: '轻微异常', desc: '无人员受伤，轻微剐蹭' },
	{ value: 'moderate', label: '一般故障', desc: '设备受损需停机检修' },
	{ value: 'severe', label: '严重事故', desc: '人员轻伤或较大财产损失' },
	{ value: 'critical', label: '重大事故', desc: '人身重伤或重大现场风险' },
]

export default {
	components: { NavBar, StateView },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			submitting: false,
			severities: SEVERITIES,
			form: { severity: '', description: '' },
			occurredDate: '',
			occurredTime: '',
			evidenceText: '',
			devices: [],
			skills: [],
			deviceIndex: -1,
			skillIndex: -1,
			list: [],
		}
	},
	computed: {
		deviceLabels() {
			return this.devices.length
				? this.devices.map(d => `${d.sn}${d.display_name ? `（${d.display_name}）` : ''}`)
				: ['（暂无已登记设备）']
		},
		skillLabels() {
			return this.skills.length
				? this.skills.map(s => `${s.name || `技能 #${s.id}`}${s.version ? ` v${s.version}` : ''}`)
				: ['（暂无已购技能）']
		},
		pickedDeviceText() {
			return this.deviceIndex >= 0 && this.devices[this.deviceIndex]
				? this.deviceLabels[this.deviceIndex]
				: '点击选择涉及设备（不指定则留空）'
		},
		pickedSkillText() {
			return this.skillIndex >= 0 && this.skills[this.skillIndex]
				? this.skillLabels[this.skillIndex]
				: '点击选择涉及技能（不指定则留空）'
		},
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	methods: {
		goMine() { uni.switchTab({ url: '/pages/mine/index' }) },
		goDetail(it) {
			if (!it || it.id == null) return
			uni.navigateTo({ url: `/pages/mine/incident-detail?id=${it.id}` })
		},
		onDevicePick(e) {
			const i = Number(e.detail.value)
			this.deviceIndex = this.devices.length ? i : -1
		},
		onSkillPick(e) {
			const i = Number(e.detail.value)
			this.skillIndex = this.skills.length ? i : -1
		},
		async refresh() {
			this.loading = true
			this.loadError = ''
			const [inc, dev, sk] = await Promise.all([
				incidentApi.list().catch((e) => { this.loadError = (e && e.message) || '加载失败'; return null }),
				deviceApi.list().catch(() => null),
				skillApi.mine().catch(() => null),
			])
			this.list = (inc && Array.isArray(inc.data)) ? inc.data : []
			this.devices = (dev && Array.isArray(dev.data)) ? dev.data : []
			this.skills = (sk && Array.isArray(sk.data)) ? sk.data : []
			this.loading = false
		},
		async submit() {
			if (this.submitting) return
			if (!this.form.severity) { uni.showToast({ title: '请选择事故严重等级', icon: 'none' }); return }
			const desc = String(this.form.description || '').trim()
			if (!desc) { uni.showToast({ title: '请填写事发经过描述', icon: 'none' }); return }

			if (this.form.severity === 'severe' || this.form.severity === 'critical') {
				const ok = await new Promise((resolve) => {
					uni.showModal({
						title: '确认提交严重事故报告',
						content: '请确认现场已断电安全隔离；如有人身伤害请务必先拨打 120。是否继续提交？',
						confirmColor: '#DC2626',
						success: r => resolve(r.confirm),
					})
				})
				if (!ok) return
			}

			const evidence = String(this.evidenceText || '').split('\n').map(s => s.trim()).filter(Boolean)
			const payload = {
				severity: this.form.severity,
				description: desc,
			}
			if (this.occurredDate) {
				payload.occurred_at = `${this.occurredDate} ${this.occurredTime || '00:00'}:00`
			}
			if (this.skillIndex >= 0 && this.skills[this.skillIndex] && this.skills[this.skillIndex].product_id) {
				payload.skill_id = this.skills[this.skillIndex].product_id
			}
			if (this.deviceIndex >= 0 && this.devices[this.deviceIndex]) {
				payload.device_id = this.devices[this.deviceIndex].id
			}
			if (evidence.length) payload.evidence = evidence

			this.submitting = true
			try {
				await incidentApi.create(payload)
				uni.showToast({ title: '事故报告已提交', icon: 'success' })
				this.form = { severity: '', description: '' }
				this.occurredDate = ''
				this.occurredTime = ''
				this.evidenceText = ''
				this.deviceIndex = -1
				this.skillIndex = -1
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.incident-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.alert-card { background: #FEF2F2; border: 1rpx solid #FECACA; border-radius: 22rpx; padding: 26rpx 28rpx; margin-bottom: 20rpx; }
.alert-title { display: block; font-size: 28rpx; font-weight: 700; color: #991B1B; margin-bottom: 8rpx; }
.alert-body { display: block; font-size: 24rpx; color: #7F1D1D; line-height: 1.55; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.sec-title { display: block; font-size: 30rpx; font-weight: 600; color: #0F172A; margin-bottom: 8rpx; }
.field-label { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin: 20rpx 0 10rpx; }
.req { color: #DC2626; }
.sev-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14rpx; }
.sev-item { padding: 18rpx 20rpx; border-radius: 16rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; }
.sev-item.active { background: #EEF4FF; border-color: #165DFF; }
.sev-item.severe.active, .sev-item.critical.active { background: #FEF2F2; border-color: #DC2626; }
.sev-name { display: block; font-size: 27rpx; font-weight: 600; color: #0F172A; }
.sev-desc { display: block; font-size: 22rpx; color: #64748B; margin-top: 4rpx; }
.dt-row { display: flex; gap: 14rpx; }
.flex-1 { flex: 1; }
.picker-box { height: 82rpx; padding: 0 22rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; display: flex; align-items: center; font-size: 26rpx; color: #0F172A; }
.nt-input { width: 100%; min-height: 170rpx; padding: 18rpx 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; line-height: 1.55; }
.nt-input.small { min-height: 120rpx; }
.hint { display: block; font-size: 22rpx; color: #64748B; margin-top: 10rpx; line-height: 1.5; }
.submit-btn { height: 88rpx; border-radius: 18rpx; background: #165DFF; color: #FFFFFF; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; margin-top: 26rpx; }
.submit-btn.disabled { opacity: 0.6; }
.list-title { display: block; font-size: 28rpx; font-weight: 600; color: #334155; margin: 12rpx 4rpx 16rpx; }
.incident-item { padding: 24rpx 28rpx; }
.ii-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12rpx; }
.ii-no { font-size: 28rpx; font-weight: 600; color: #0F172A; }
.ii-sev { font-size: 23rpx; font-weight: 600; padding: 3rpx 12rpx; border-radius: 8rpx; background: #F1F5F9; color: #475569; }
.ii-sev.severe, .ii-sev.critical { background: #FEF2F2; color: #DC2626; }
.ii-foot { display: flex; justify-content: space-between; align-items: center; }
.ii-status { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.ii-right { display: flex; align-items: center; gap: 16rpx; }
.ii-time { font-size: 23rpx; color: #94A3B8; }
.ii-arrow { font-size: 24rpx; color: #475569; }
</style>
