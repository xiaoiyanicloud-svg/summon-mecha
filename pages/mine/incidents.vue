<template>
	<view class="incident-page">
		<!-- 导航栏 -->
		<view class="nav-bar">
			<view class="nav-back" @click="goBack">
				<text class="back-icon">‹</text>
			</view>
			<text class="nav-title">事故上报</text>
			<view class="nav-placeholder"></view>
		</view>

		<view v-if="!loggedIn" class="empty-state">
			<text class="empty-icon">🔒</text>
			<text class="empty-text">请先在「我的」页面登录</text>
			<view class="primary-btn" @click="goBack">返回</view>
		</view>

		<scroll-view v-else class="scroll-content" scroll-y="true" show-scrollbar="false">

			<!-- ⚠ 紧急提示：平台不是急救通道。这条必须在最上方，不能藏在流程后面。 -->
			<view class="card alert-card">
				<text class="alert-title">有人受伤或存在现实危险，请先拨 110 / 120</text>
				<text class="alert-body">
					本页是事后上报，不是紧急通道 —— 我们无法实时响应。
					先确保现场安全、先救人，再来这里补记录。记录用于追溯与定责，不用于求救。
				</text>
			</view>

			<!-- 上报表单 -->
			<view class="card">
				<text class="sec-title">发生了什么</text>

				<!-- 严重程度 -->
				<text class="field-label">严重程度 <text class="req">*</text></text>
				<view class="sev-list">
					<view
						v-for="s in severities"
						:key="s.value"
						class="sev-item"
						:class="{ active: form.severity === s.value }"
						@click="form.severity = s.value"
					>
						<text class="sev-name">{{ s.label }}</text>
						<text class="sev-desc">{{ s.desc }}</text>
					</view>
				</view>

				<!-- 关联设备（可选，但强烈建议：追溯链的一半靠它） -->
				<text class="field-label">涉及设备（建议选，能大幅提升可追溯性）</text>
				<picker class="picker" :range="deviceLabels" @change="onDevicePick">
					<view class="picker-box">{{ pickedDeviceText }}</view>
				</picker>

				<!-- 关联技能（可选） -->
				<text class="field-label">涉及技能（建议选）</text>
				<picker class="picker" :range="skillLabels" @change="onSkillPick">
					<view class="picker-box">{{ pickedSkillText }}</view>
				</picker>

				<!-- 描述 -->
				<text class="field-label">情况描述 <text class="req">*</text></text>
				<textarea
					class="nt-input"
					v-model="form.description"
					placeholder="时间、地点、机器人当时在做什么、造成了什么后果"
					maxlength="2000"
				/>

				<!-- 证据：只收文字/链接 -->
				<text class="field-label">证据说明（一行一条，只收文字或链接）</text>
				<textarea
					class="nt-input small"
					v-model="evidenceText"
					placeholder="如：现场照片已交给客服 / 视频链接 https://..."
					maxlength="2000"
				/>
				<text class="hint">暂不支持上传文件 —— 事故照片/视频属敏感材料，需要专门的安全方案，尚未接入。</text>

				<view class="nt-btn" @click="submit">{{ submitting ? '上报中…' : '提交上报' }}</view>
			</view>

			<!-- 我的上报记录 -->
			<text class="list-title">我的上报记录</text>

			<!-- 三态:加载中 / 故障(带重试) / 真·还没有上报记录。故障不再伪装成「还没有」 -->
			<state-view
				:loading="loading"
				:error="loadError"
				:empty="!list.length"
				compact
				empty-icon=""
				empty-text="还没有上报记录"
				btn-color="#ff6b00"
				@retry="refresh"
			>
				<view v-for="it in list" :key="it.id" class="card incident-item" @click="goDetail(it)">
					<view class="ii-head">
						<text class="ii-no">{{ it.incident_no }}</text>
						<text class="ii-sev" :class="it.severity">{{ it.severity_label }}</text>
					</view>
					<view class="ii-foot">
						<text class="ii-status">{{ it.status_label }}</text>
						<view class="ii-right">
							<text class="ii-time">{{ it.created_at }}</text>
							<text class="ii-arrow">详情 ›</text>
						</view>
					</view>
				</view>
			</state-view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { incidentApi, deviceApi, skillApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import StateView from '@/components/StateView.vue'

// 与后端 Incident::SEVERITY_LABELS 对齐
const SEVERITIES = [
	{ value: 'minor', label: '轻微', desc: '无人员受伤，轻微财产损失' },
	{ value: 'moderate', label: '一般', desc: '设备需停机检修' },
	{ value: 'severe', label: '严重', desc: '有人受伤，或重大财产损失' },
	{ value: 'critical', label: '重大', desc: '人身重伤或更严重' },
]

export default {
	components: { StateView },
	data() {
		return {
			loggedIn: false,
			loading: true,
			loadError: '',
			submitting: false,
			severities: SEVERITIES,
			form: { severity: '', description: '' },
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
				? this.devices.map(d => d.display_name || d.sn || ('设备 #' + d.id))
				: ['（暂无已登记设备）']
		},
		skillLabels() {
			return this.skills.length
				? this.skills.map(s => s.name || ('技能 #' + s.id))
				: ['（暂无已购技能）']
		},
		pickedDeviceText() {
			return this.deviceIndex >= 0 && this.devices[this.deviceIndex]
				? this.deviceLabels[this.deviceIndex]
				: '不指定'
		},
		pickedSkillText() {
			return this.skillIndex >= 0 && this.skills[this.skillIndex]
				? this.skillLabels[this.skillIndex]
				: '不指定'
		},
	},
	onShow() {
		this.loggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.loggedIn) this.refresh()
		else this.loading = false
	},
	methods: {
		goBack() { uni.navigateBack({ delta: 1 }) },
		// 消费既有接口 GET /api/incidents/{id} —— 此前列表项没有任何入口,
		// 报案人看不到责任认定,事故闭环缺最后一公里
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
			// 主列表(上报记录)失败 → 置错误标记,由 StateView 的错误态承载(带重试)。
			// 设备/技能只服务于下方两个「可选项」下拉,拉不到不阻断页面,但留 warn 不静默。
			const [inc, dev, sk] = await Promise.all([
				incidentApi.list().catch(e => {
					this.loadError = (e && e.message) || '加载失败'
					return null
				}),
				deviceApi.list().catch(e => { console.warn('[incidents] 设备下拉加载失败', e); return null }),
				skillApi.mine().catch(e => { console.warn('[incidents] 技能下拉加载失败', e); return null }),
			])
			this.list = (inc && Array.isArray(inc.data)) ? inc.data : []
			this.devices = (dev && Array.isArray(dev.data)) ? dev.data : []
			this.skills = (sk && Array.isArray(sk.data)) ? sk.data : []
			this.loading = false
		},

		async submit() {
			if (!this.form.severity) { uni.showToast({ title: '请选择严重程度', icon: 'none' }); return }

			const desc = (this.form.description || '').trim()
			if (!desc) { uni.showToast({ title: '请描述情况', icon: 'none' }); return }

			// 严重及以上再确认一次 —— 误报会触发平台的应急流程
			if (this.form.severity === 'severe' || this.form.severity === 'critical') {
				const ok = await new Promise(resolve => {
					uni.showModal({
						title: '确认上报严重事故',
						content: '请确认已确保现场安全；如需急救请先拨打 120。继续上报？',
						success: r => resolve(r.confirm),
					})
				})
				if (!ok) return
			}

			const evidence = (this.evidenceText || '')
				.split('\n').map(s => s.trim()).filter(Boolean)

			const payload = {
				severity: this.form.severity,
				description: desc,
			}
			// ⚠ 用 product_id 而不是 id：/api/skills/mine 返回的是**授权记录**，
			//   它的 id 是 entitlement id，而后端 incident.skill_id 要的是 products.id。
			//   传错会关联到别的技能（或关联不上），追溯链就断在最关键的一环。
			if (this.skillIndex >= 0 && this.skills[this.skillIndex]) {
				const s = this.skills[this.skillIndex]
				if (s.product_id) payload.skill_id = s.product_id
			}
			if (this.deviceIndex >= 0 && this.devices[this.deviceIndex]) payload.device_id = this.devices[this.deviceIndex].id
			if (evidence.length) payload.evidence = evidence

			this.submitting = true
			try {
				await incidentApi.create(payload)
				uni.showToast({ title: '已上报', icon: 'success' })
				this.form = { severity: '', description: '' }
				this.evidenceText = ''
				this.deviceIndex = -1
				this.skillIndex = -1
				await this.refresh()
			} catch (e) {
				uni.showToast({ title: (e && e.message) || '上报失败', icon: 'none' })
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.incident-page { min-height: 100vh; background: #f5f6f8; }
.nav-bar { display: flex; align-items: center; justify-content: space-between; height: 88rpx; padding: 0 24rpx; background: #fff; }
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #333; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-placeholder { width: 60rpx; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }

/* 紧急提示 —— 用红色，它是这一页最重要的信息 */
.alert-card { background: #fff1f0; border: 1rpx solid #ffa39e; }
.alert-title { display: block; font-size: 30rpx; font-weight: 700; color: #cf1322; }
.alert-body { display: block; margin-top: 12rpx; font-size: 25rpx; line-height: 1.6; color: #a8071a; }

.sec-title { display: block; font-size: 30rpx; font-weight: 600; color: #222; margin-bottom: 8rpx; }
.field-label { display: block; margin-top: 24rpx; font-size: 26rpx; color: #555; }
.req { color: #ff4d4f; }

.sev-list { margin-top: 12rpx; }
.sev-item { padding: 18rpx 20rpx; margin-bottom: 12rpx; background: #f7f8fa; border-radius: 12rpx; border: 2rpx solid transparent; }
.sev-item.active { border-color: #ff6b00; background: #fff7f0; }
.sev-name { display: block; font-size: 27rpx; color: #222; font-weight: 600; }
.sev-desc { display: block; margin-top: 6rpx; font-size: 23rpx; color: #888; }

.picker { display: block; margin-top: 10rpx; }
.picker-box { padding: 18rpx; background: #f7f8fa; border-radius: 12rpx; font-size: 26rpx; color: #333; }

.nt-input { margin-top: 12rpx; width: 100%; min-height: 160rpx; padding: 16rpx; background: #f7f8fa; border-radius: 12rpx; font-size: 26rpx; box-sizing: border-box; }
.nt-input.small { min-height: 110rpx; }
.hint { display: block; margin-top: 8rpx; font-size: 22rpx; color: #aaa; line-height: 1.5; }

.nt-btn { margin-top: 24rpx; text-align: center; padding: 20rpx 0; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }

.list-title { display: block; margin: 28rpx 24rpx 0; font-size: 28rpx; font-weight: 600; color: #333; }
.incident-item { padding: 22rpx 28rpx; }
.ii-head { display: flex; align-items: center; justify-content: space-between; }
.ii-no { font-size: 27rpx; color: #222; }
.ii-sev { font-size: 24rpx; color: #888; }
.ii-sev.severe, .ii-sev.critical { color: #cf1322; font-weight: 600; }
.ii-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 10rpx; }
.ii-status { font-size: 24rpx; color: #ff6b00; }
.ii-time { font-size: 22rpx; color: #aaa; }
.ii-right { display: flex; align-items: center; }
.ii-arrow { margin-left: 14rpx; font-size: 22rpx; color: #ff6b00; }

.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: 120rpx; }
.empty-state.small { padding-top: 40rpx; }
.empty-icon { font-size: 80rpx; }
.empty-text { margin-top: 20rpx; font-size: 27rpx; color: #999; }
.primary-btn { margin-top: 40rpx; padding: 18rpx 60rpx; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
</style>
