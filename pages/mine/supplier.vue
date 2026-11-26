<template>
	<view class="supplier-page">
		<nav-bar title="供应链伙伴入驻" />

		<view class="hero-banner">
			<text class="hero-title">召唤机甲供应链准入中心</text>
			<text class="hero-sub">面向机器人整机厂商、核心零部件与授权租赁服务商，核验通过后开通多租户「商家工作台」。</text>
		</view>

		<view class="tabs-bar">
			<view class="tab-item" :class="{ active: activeTab === 'verify' }" @tap="activeTab = 'verify'">邀请码核验</view>
			<view class="tab-item" :class="{ active: activeTab === 'apply' }" @tap="activeTab = 'apply'">供货资质申报</view>
			<view class="tab-item" :class="{ active: activeTab === 'history' }" @tap="switchHistory">
				申报进度<text v-if="records.length" class="tab-count sm-mono">{{ records.length }}</text>
			</view>
		</view>

		<!-- Tab 1: 邀请码实时核验与极速入驻 -->
		<scroll-view v-if="activeTab === 'verify'" class="page-scroll" scroll-y>
			<view class="card">
				<text class="card-title">核验供应链邀请码</text>
				<text class="card-sub">输入商务团队签发的邀请码，核验有效后可直接前往商家工作台开设店铺，或登记供应链供货档案。</text>

				<view class="code-input-row">
					<input
						class="code-input sm-mono"
						type="text"
						v-model="verifyCode"
						@input="verifyCode = (verifyCode || '').toUpperCase()"
						placeholder="请输入邀请码，如 SM-SUP-2026"
						maxlength="40"
					/>
					<view class="btn-verify" :class="{ disabled: verifying }" @tap="runVerify">
						{{ verifying ? '核验中...' : '立即核验' }}
					</view>
				</view>

				<view v-if="verifyResult" class="verify-box" :class="verifyResult.redeemable ? 'ok' : 'fail'">
					<view class="vb-head">
						<SmIcon :name="verifyResult.redeemable ? 'file-check' : 'state-warn'" :size="34" />
						<text class="vb-title">{{ verifyResult.redeemable ? '邀请码核验有效' : '邀请码不可用' }}</text>
					</view>
					<text class="vb-desc">{{ verifyResult.redeemable ? (verifyResult.note || '官方供应链准入凭证') : verifyResult.reason }}</text>
					<view v-if="verifyResult.redeemable" class="vb-meta sm-mono">
						<text>剩余可用次数：{{ verifyResult.remaining_uses }}/{{ verifyResult.max_uses }}</text>
						<text v-if="verifyResult.expires_at">有效期至：{{ verifyResult.expires_at.slice(0, 10) }}</text>
					</view>
					<view v-if="verifyResult.redeemable" class="vb-actions">
						<view class="btn-solid" @tap="goMerchantOnboarding(verifyResult.code)">前往商家工作台开店</view>
						<view class="btn-outline" @tap="useCodeForApply(verifyResult.code)">带码填写供货档案</view>
					</view>
				</view>
			</view>

			<view class="card guide-card">
				<text class="card-title">还没有供应链邀请码？</text>
				<text class="card-sub">您可切换至「供货资质申报」选择无码申请模式，平台采购审核通过后将在站内直接签发专属邀请码。</text>
				<view class="btn-outline full" @tap="startNoCodeApply">无码申请供应链准入 ›</view>
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- Tab 2: 供货资质申报 -->
		<scroll-view v-else-if="activeTab === 'apply'" class="page-scroll" scroll-y>
			<view class="card">
				<view class="field-item">
					<text class="field-label required">准入通道模式</text>
					<view class="seg-row">
						<view class="seg-btn" :class="{ active: form.entry_mode === 'with_code' }" @tap="form.entry_mode = 'with_code'">持邀请码登记</view>
						<view class="seg-btn" :class="{ active: form.entry_mode === 'apply_code' }" @tap="form.entry_mode = 'apply_code'">无码申请邀约</view>
					</view>
				</view>

				<view v-if="form.entry_mode === 'with_code'" class="field-item">
					<text class="field-label required">供应链邀请码</text>
					<input
						class="field-input sm-mono"
						type="text"
						v-model="form.invite_code"
						@input="form.invite_code = (form.invite_code || '').toUpperCase()"
						placeholder="请输入有效的供应链邀请码"
						maxlength="40"
					/>
				</view>

				<view class="field-item">
					<text class="field-label required">主营供应品类</text>
					<view class="chip-row">
						<text
							v-for="cat in categories"
							:key="cat"
							class="choice-chip"
							:class="{ active: form.coop_direction === cat }"
							@tap="form.coop_direction = cat"
						>{{ cat }}</text>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">供应商企业全称</text>
					<input class="field-input" type="text" v-model="form.org_name" placeholder="须与营业执照主体一致" maxlength="120" />
				</view>

				<view class="field-item">
					<text class="field-label">统一社会信用代码（选填）</text>
					<input
						class="field-input sm-mono"
						type="text"
						v-model="form.credit_code"
						@input="form.credit_code = (form.credit_code || '').toUpperCase()"
						placeholder="18 位统一社会信用代码"
						maxlength="64"
					/>
				</view>

				<view class="field-item">
					<text class="field-label">营业执照 / 代理授权书（真实上传）</text>
					<view class="upload-box" @tap="uploadLicense">
						<template v-if="license.file_name">
							<SmIcon name="file-check" :size="38" />
							<view class="upload-meta">
								<text class="upload-name">{{ license.file_name }}</text>
								<text class="upload-tip">已加密归档至平台私密存储</text>
							</view>
							<text class="upload-del" @tap.stop="license = { attachment_token: '', file_name: '' }">移除</text>
						</template>
						<template v-else>
							<SmIcon name="upload-doc" :size="40" />
							<view class="upload-meta">
								<text class="upload-name">{{ uploading ? '正在上传...' : '点击上传营业执照或品牌授权书' }}</text>
								<text class="upload-tip">支持 JPG / PNG，最大 10MB</text>
							</view>
						</template>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">核心供货型号与服务清单</text>
					<input class="field-input" type="text" v-model="form.supply_items" placeholder="如：Unitree G1 整机现货、原厂电池包、驻场运维" maxlength="200" />
					<view class="preset-row">
						<text v-for="q in quickItems" :key="q" class="preset-chip" @tap="appendSupplyItem(q)">+ {{ q }}</text>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">月度供货或服务覆盖量级</text>
					<view class="chip-row">
						<text
							v-for="s in capacityScales"
							:key="s"
							class="choice-chip"
							:class="{ active: form.capacity_scale === s }"
							@tap="form.capacity_scale = s"
						>{{ s }}</text>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">履约与质保响应承诺</text>
					<view class="chip-row">
						<text
							v-for="w in warrantyOptions"
							:key="w"
							class="choice-chip"
							:class="{ active: form.warranty_commitment === w }"
							@tap="form.warranty_commitment = w"
						>{{ w }}</text>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">业务负责人</text>
					<input class="field-input" type="text" v-model="form.contact_name" placeholder="请输入联系人姓名" maxlength="60" />
				</view>

				<view class="field-item">
					<text class="field-label required">联系手机号</text>
					<input class="field-input sm-mono" type="number" v-model="form.contact_phone" placeholder="11 位大陆手机号" maxlength="11" />
				</view>

				<view class="field-item">
					<text class="field-label">仓储发运城市</text>
					<input class="field-input" type="text" v-model="form.region" placeholder="如：深圳 / 苏州 / 上海" maxlength="80" />
				</view>

				<view class="field-item">
					<text class="field-label">补充说明（选填）</text>
					<textarea class="field-textarea" v-model="form.summary" placeholder="可说明账期支持、现货库存规模或过往大型项目经验" maxlength="500" />
				</view>

				<view class="btn-solid full" :class="{ disabled: submitting }" @tap="submitSupplier">
					{{ submitting ? '提交中...' : '提交供应链准入申报' }}
				</view>
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- Tab 3: 申报进度 -->
		<template v-else>
			<state-view v-if="loadingRecords" preset="loading" loading-text="正在读取申报记录..." />
			<state-view
				v-else-if="records.length === 0"
				icon="hub-supplier"
				title="暂无供应链申报记录"
				hint="提交供货资质申报后，采购审核进度与签发的专属邀请码将在此展示"
				action-text="立即填写申报"
				@action="activeTab = 'apply'"
			/>
			<scroll-view v-else class="page-scroll" scroll-y>
				<view v-for="app in records" :key="app.id" class="card rec-card">
					<view class="rec-top">
						<text class="rec-no sm-mono">{{ app.application_no }}</text>
						<text class="rec-status" :class="'st-' + app.status">{{ app.status_label }}</text>
					</view>
					<text class="rec-org">{{ app.org_name }}</text>
					<view class="rec-meta">
						<text class="meta-line">供应品类：{{ app.coop_direction || '—' }}</text>
						<text v-if="app.meta && app.meta.supply_items" class="meta-line">主营清单：{{ app.meta.supply_items }}</text>
						<text v-if="app.followup_person" class="meta-line">采购对接：{{ app.followup_person }} {{ app.follower_contact || '' }}</text>
						<text v-if="app.admin_note" class="meta-line note">审核意见：{{ app.admin_note }}</text>
					</view>

					<view v-if="app.issued_invite_code || app.status === 'approved'" class="issued-box">
						<view>
							<text class="issued-label">{{ app.issued_invite_code ? '专属准入邀请码已签发' : '供应链准入已审核通过' }}</text>
							<text v-if="app.issued_invite_code" class="issued-code sm-mono">{{ app.issued_invite_code }}</text>
						</view>
						<view class="issued-btns">
							<text v-if="app.issued_invite_code" class="mini-btn" @tap="copyCode(app.issued_invite_code)">复制码</text>
							<text class="mini-btn solid" @tap="goMerchantOnboarding(app.issued_invite_code || app.invite_code || '')">进入商家工作台</text>
						</view>
					</view>

					<view class="rec-foot">
						<text class="rec-time sm-mono">{{ app.created_at }}</text>
						<text v-if="app.can_withdraw" class="withdraw-link" @tap="withdrawRecord(app)">撤回申报</text>
					</view>
				</view>
				<view style="height: 60rpx;"></view>
			</scroll-view>
		</template>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { cooperationApi, portalApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY, BASE_URL } from '@/utils/config'

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			activeTab: 'verify',
			verifyCode: '',
			verifying: false,
			verifyResult: null,
			uploading: false,
			submitting: false,
			license: { attachment_token: '', file_name: '' },
			categories: ['人形机器人整机租售', '四足/轮式底盘供应', '核心零部件与电池配件', '区域驻场运维与物流'],
			quickItems: ['Unitree G1 现货', '智元远征 A2', '原厂快充电池组', '灵巧手模组', '展会驻场工程师'],
			capacityScales: ['月供 5~20 台', '月供 20~100 台', '月供 100 台以上', '全国多仓覆盖'],
			warrantyOptions: ['原厂全国联保 + 24h响应', '48h备机替换保障', '提供驻场技术保障'],
			form: {
				entry_mode: 'with_code',
				invite_code: '',
				coop_direction: '人形机器人整机租售',
				org_name: '',
				credit_code: '',
				supply_items: '',
				capacity_scale: '月供 5~20 台',
				warranty_commitment: '原厂全国联保 + 24h响应',
				contact_name: '',
				contact_phone: '',
				region: '',
				summary: '',
			},
			loadingRecords: false,
			records: [],
		}
	},
	onLoad(q) {
		if (q && q.code) {
			this.verifyCode = String(q.code).toUpperCase()
			this.runVerify()
		}
		if (q && (q.tab === 'apply' || q.tab === 'history')) {
			this.activeTab = q.tab
		}
	},
	onShow() {
		const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
		if (customer.company_name && !this.form.org_name) this.form.org_name = customer.company_name
		if (customer.name && !this.form.contact_name) this.form.contact_name = customer.name
		if (customer.phone && !this.form.contact_phone) this.form.contact_phone = customer.phone
		this.loadRecords()
	},
	methods: {
		switchHistory() {
			this.activeTab = 'history'
			this.loadRecords()
		},
		loadRecords() {
			this.loadingRecords = true
			cooperationApi.list()
				.then((res) => {
					const all = Array.isArray(res && res.data) ? res.data : []
					this.records = all.filter(x => x.coop_type === 'supplier_invite')
				})
				.catch(() => {})
				.finally(() => {
					this.loadingRecords = false
				})
		},
		runVerify() {
			const code = String(this.verifyCode || '').trim()
			if (!code) {
				uni.showToast({ title: '请输入邀请码', icon: 'none' })
				return
			}
			this.verifying = true
			cooperationApi.verifyInvite(code, 'supplier')
				.then((res) => {
					this.verifyResult = (res && res.data) || null
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '核验失败', icon: 'none' })
				})
				.finally(() => {
					this.verifying = false
				})
		},
		useCodeForApply(code) {
			this.form.entry_mode = 'with_code'
			this.form.invite_code = code
			this.activeTab = 'apply'
		},
		startNoCodeApply() {
			this.form.entry_mode = 'apply_code'
			this.form.invite_code = ''
			this.activeTab = 'apply'
		},
		appendSupplyItem(item) {
			const cur = String(this.form.supply_items || '').trim()
			if (!cur) {
				this.form.supply_items = item
			} else if (!cur.includes(item)) {
				this.form.supply_items = `${cur}、${item}`
			}
		},
		uploadLicense() {
			if (this.uploading) return
			uni.chooseImage({
				count: 1,
				sizeType: ['compressed'],
				success: (res) => {
					const filePath = res.tempFilePaths && res.tempFilePaths[0]
					if (!filePath) return
					this.uploading = true
					cooperationApi.uploadAttachment(filePath)
						.then((up) => {
							const d = (up && up.data) || {}
							this.license = {
								attachment_token: d.attachment_token || '',
								file_name: d.file_name || '营业执照.jpg',
							}
							uni.showToast({ title: '资质已上传', icon: 'success' })
						})
						.catch((err) => {
							uni.showToast({ title: (err && err.message) || '上传失败', icon: 'none' })
						})
						.finally(() => {
							this.uploading = false
						})
				},
			})
		},
		submitSupplier() {
			if (this.submitting) return
			const f = this.form
			if (f.entry_mode === 'with_code' && !f.invite_code.trim()) {
				uni.showToast({ title: '请填写有效的供应链邀请码，或切换为无码申请', icon: 'none' })
				return
			}
			if (!f.org_name.trim()) { uni.showToast({ title: '请填写供应商企业全称', icon: 'none' }); return }
			if (!f.supply_items.trim()) { uni.showToast({ title: '请填写核心供货型号或服务清单', icon: 'none' }); return }
			if (!f.contact_name.trim()) { uni.showToast({ title: '请填写业务负责人姓名', icon: 'none' }); return }
			if (!/^1\d{10}$/.test(String(f.contact_phone || '').trim())) { uni.showToast({ title: '请填写正确的 11 位手机号', icon: 'none' }); return }

			const meta = {
				entry_mode: f.entry_mode,
				supply_items: f.supply_items.trim(),
				capacity_scale: f.capacity_scale,
				warranty_commitment: f.warranty_commitment,
			}
			if (f.invite_code.trim()) meta.invite_code = f.invite_code.trim()
			if (f.credit_code.trim()) meta.credit_code = f.credit_code.trim()
			if (this.license.attachment_token) {
				meta.license_attachment_token = this.license.attachment_token
				meta.license_file_name = this.license.file_name
			}

			this.submitting = true
			cooperationApi.submit({
				coop_type: 'supplier_invite',
				org_name: f.org_name.trim(),
				contact_name: f.contact_name.trim(),
				contact_phone: f.contact_phone.trim(),
				region: f.region.trim() || null,
				coop_direction: f.coop_direction,
				summary: f.summary.trim() || null,
				meta,
			})
				.then(() => {
					uni.showToast({ title: '供应链申报已提交', icon: 'success' })
					this.switchHistory()
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '提交失败', icon: 'none' })
				})
				.finally(() => {
					this.submitting = false
				})
		},
		withdrawRecord(app) {
			uni.showModal({
				title: '撤回准入申报',
				content: `确认撤回「${app.org_name}」的待审核申报吗？`,
				confirmColor: '#DC2626',
				success: (res) => {
					if (!res.confirm) return
					cooperationApi.withdraw(app.id)
						.then(() => {
							uni.showToast({ title: '已撤回', icon: 'success' })
							this.loadRecords()
						})
						.catch((err) => {
							uni.showToast({ title: (err && err.message) || '撤回失败', icon: 'none' })
						})
				},
			})
		},
		copyCode(code) {
			uni.setClipboardData({
				data: String(code || ''),
				success: () => uni.showToast({ title: '邀请码已复制', icon: 'success' }),
			})
		},
		goMerchantOnboarding(code = '') {
			const redirectPath = '/merchant/onboarding' + (code ? `?code=${encodeURIComponent(code)}` : '')
			const fallbackUrl = BASE_URL + redirectPath
			if (uni.getStorageSync(TOKEN_KEY)) {
				portalApi.handoffTicket('merchant_admin', redirectPath)
					.then((res) => {
						const url = (res && res.data && res.data.sso_url) || fallbackUrl
						this.openUrl(url)
					})
					.catch(() => this.openUrl(fallbackUrl))
				return
			}
			this.openUrl(fallbackUrl)
		},
		openUrl(url) {
			// #ifdef H5
			window.location.href = url
			// #endif
			// #ifndef H5
			uni.setClipboardData({
				data: url,
				success: () => uni.showToast({ title: '商家工作台链接已复制，请在电脑浏览器打开', icon: 'none' }),
			})
			// #endif
		},
	},
}
</script>

<style scoped>
.supplier-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.hero-banner { margin: 20rpx 28rpx 0; padding: 30rpx 32rpx; border-radius: 24rpx; background: linear-gradient(135deg, #102F6E 0%, #174299 100%); color: #FFFFFF; }
.hero-title { display: block; font-size: 33rpx; font-weight: 600; margin-bottom: 8rpx; }
.hero-sub { display: block; font-size: 24rpx; color: rgba(255, 255, 255, 0.82); line-height: 1.55; }
.tabs-bar { display: flex; margin: 20rpx 28rpx 0; background: #E9EEF6; border-radius: 16rpx; padding: 6rpx; }
.tab-item { flex: 1; height: 68rpx; border-radius: 12rpx; display: flex; align-items: center; justify-content: center; gap: 8rpx; font-size: 26rpx; font-weight: 600; color: #475569; }
.tab-item.active { background: #FFFFFF; color: #165DFF; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06); }
.tab-count { font-size: 21rpx; padding: 2rpx 10rpx; border-radius: 999rpx; background: #EEF4FF; color: #165DFF; }
.page-scroll { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 30rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.card-title { display: block; font-size: 30rpx; font-weight: 600; color: #0F172A; margin-bottom: 8rpx; }
.card-sub { display: block; font-size: 24rpx; color: #64748B; line-height: 1.55; margin-bottom: 24rpx; }
.code-input-row { display: flex; gap: 16rpx; }
.code-input { flex: 1; height: 86rpx; padding: 0 24rpx; background: #F8FAFC; border: 1rpx solid #CBD5E1; border-radius: 16rpx; font-size: 29rpx; font-weight: 600; color: #0F172A; }
.btn-verify { padding: 0 34rpx; height: 86rpx; border-radius: 16rpx; background: #165DFF; color: #FFFFFF; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-verify.disabled { opacity: 0.6; }
.verify-box { margin-top: 24rpx; padding: 26rpx; border-radius: 18rpx; }
.verify-box.ok { background: #ECFDF5; border: 1rpx solid #A7F3D0; }
.verify-box.fail { background: #FEF2F2; border: 1rpx solid #FECACA; }
.vb-head { display: flex; align-items: center; gap: 12rpx; margin-bottom: 8rpx; }
.vb-title { font-size: 29rpx; font-weight: 600; color: #0F172A; }
.vb-desc { display: block; font-size: 25rpx; color: #334155; line-height: 1.5; }
.vb-meta { display: flex; flex-wrap: wrap; gap: 24rpx; font-size: 23rpx; color: #047857; margin-top: 12rpx; }
.vb-actions { display: flex; gap: 16rpx; margin-top: 22rpx; }
.btn-solid { flex: 1; height: 84rpx; border-radius: 16rpx; background: #165DFF; color: #FFFFFF; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-solid.full { width: 100%; }
.btn-solid.disabled { opacity: 0.6; }
.btn-outline { flex: 1; height: 84rpx; border-radius: 16rpx; background: #FFFFFF; border: 1rpx solid #165DFF; color: #165DFF; font-size: 27rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-outline.full { width: 100%; }
.field-item { margin-bottom: 24rpx; }
.field-label { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.field-label.required::after { content: ' *'; color: #DC2626; }
.field-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; }
.field-textarea { width: 100%; min-height: 140rpx; padding: 20rpx 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; line-height: 1.5; }
.seg-row { display: flex; gap: 16rpx; }
.seg-btn { flex: 1; height: 74rpx; border-radius: 14rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-size: 26rpx; color: #475569; font-weight: 500; }
.seg-btn.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.chip-row { display: flex; flex-wrap: wrap; gap: 12rpx; }
.choice-chip { padding: 10rpx 22rpx; border-radius: 999rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; font-size: 24rpx; color: #475569; }
.choice-chip.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.preset-row { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 12rpx; }
.preset-chip { font-size: 22rpx; color: #1E40AF; background: #EEF4FF; padding: 6rpx 16rpx; border-radius: 999rpx; }
.upload-box { padding: 24rpx; border-radius: 16rpx; border: 2rpx dashed #CBD5E1; background: #F8FAFC; display: flex; align-items: center; gap: 20rpx; }
.upload-meta { flex: 1; display: flex; flex-direction: column; gap: 4rpx; }
.upload-name { font-size: 26rpx; font-weight: 500; color: #0F172A; }
.upload-tip { font-size: 22rpx; color: #64748B; }
.upload-del { font-size: 25rpx; color: #DC2626; }
.rec-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10rpx; }
.rec-no { font-size: 24rpx; color: #64748B; }
.rec-status { font-size: 24rpx; font-weight: 600; }
.st-pending { color: #D97706; }
.st-contacting { color: #165DFF; }
.st-approved { color: #059669; }
.st-rejected, .st-withdrawn { color: #64748B; }
.rec-org { display: block; font-size: 31rpx; font-weight: 600; color: #0F172A; margin-bottom: 12rpx; }
.rec-meta { display: flex; flex-direction: column; gap: 6rpx; }
.meta-line { font-size: 25rpx; color: #475569; }
.meta-line.note { color: #1E40AF; }
.issued-box { margin-top: 18rpx; padding: 18rpx 22rpx; border-radius: 16rpx; background: #ECFDF5; border: 1rpx solid #A7F3D0; display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.issued-label { display: block; font-size: 22rpx; color: #047857; }
.issued-code { display: block; font-size: 29rpx; font-weight: 700; color: #065F46; margin-top: 4rpx; }
.issued-btns { display: flex; gap: 12rpx; }
.mini-btn { font-size: 23rpx; padding: 10rpx 18rpx; border-radius: 10rpx; color: #047857; border: 1rpx solid #6EE7B7; }
.mini-btn.solid { background: #059669; color: #FFFFFF; border-color: #059669; }
.rec-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 18rpx; padding-top: 16rpx; border-top: 1rpx solid #F1F5F9; }
.rec-time { font-size: 23rpx; color: #94A3B8; }
.withdraw-link { font-size: 25rpx; color: #DC2626; }
</style>
