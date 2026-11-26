<template>
	<view class="coop-page">
		<nav-bar title="生态合作申请" />

		<view class="hero-card">
			<text class="hero-title">召唤机甲生态合作中心</text>
			<text class="hero-desc">连接本体制造厂商、具身技能开发者、达人创作者与区域履约服务伙伴，共建标准化交付生态。</text>
		</view>

		<view class="tabs-bar">
			<view class="tab-item" :class="{ active: activeTab === 'channels' }" @tap="activeTab = 'channels'">合作通道</view>
			<view class="tab-item" :class="{ active: activeTab === 'referral' }" @tap="activeTab = 'referral'">合伙人推荐</view>
			<view class="tab-item" :class="{ active: activeTab === 'history' }" @tap="switchHistory">
				申请进度<text v-if="records.length" class="tab-count sm-mono">{{ records.length }}</text>
			</view>
		</view>

		<!-- 合作通道 -->
		<scroll-view v-if="activeTab === 'channels'" class="page-scroll" scroll-y>
			<view v-for="ch in channels" :key="ch.key" class="channel-card">
				<view class="ch-head">
					<view class="ch-icon-box">
						<SmIcon :name="ch.icon" :size="44" />
					</view>
					<view class="ch-info">
						<view class="ch-title-row">
							<text class="ch-name">{{ ch.name }}</text>
							<text class="ch-tag">{{ ch.tag }}</text>
						</view>
						<text class="ch-desc">{{ ch.desc }}</text>
					</view>
				</view>
				<view class="benefit-row">
					<text v-for="b in ch.benefits" :key="b" class="benefit-pill">{{ b }}</text>
				</view>
				<view class="ch-foot">
					<text class="ch-hint">{{ ch.portalHint }}</text>
					<view class="btn-apply" @tap="openChannelModal(ch)">发起申请</view>
				</view>
			</view>

			<view class="supplier-entry-card" @tap="goSupplier">
				<view class="se-left">
					<SmIcon name="hub-supplier" :size="44" />
					<view class="se-info">
						<text class="se-title">整机与配件供应链入驻</text>
						<text class="se-sub">持有供应链邀请码或申请成为供货商家，请前往供应链专属通道</text>
					</view>
				</view>
				<SmIcon name="chevron-right" :size="26" />
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- 合伙人推荐 -->
		<scroll-view v-else-if="activeTab === 'referral'" class="page-scroll" scroll-y>
			<view class="form-card">
				<view class="card-head">
					<text class="card-title">推荐优质企业成为生态合伙人</text>
					<text class="card-sub">支持销售渠道合伙人与本地驻场履约合伙人两类方向，资质原件加密存储。</text>
				</view>

				<view class="field-item">
					<text class="field-label required">推荐合作角色</text>
					<view class="seg-row">
						<view class="seg-btn" :class="{ active: refForm.recommender_type === 'sales' }" @tap="refForm.recommender_type = 'sales'">区域销售合伙人</view>
						<view class="seg-btn" :class="{ active: refForm.recommender_type === 'fulfill' }" @tap="refForm.recommender_type = 'fulfill'">驻场履约合伙人</view>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">被推荐企业全称</text>
					<input class="field-input" type="text" v-model="refForm.org_name" placeholder="请输入营业执照上的企业全称" maxlength="120" />
				</view>

				<view class="field-item">
					<text class="field-label required">意向合作级别</text>
					<view class="chip-row">
						<text
							v-for="lv in ['城市合伙人', '省级核心伙伴', '行业战略伙伴']"
							:key="lv"
							class="choice-chip"
							:class="{ active: refForm.level === lv }"
							@tap="refForm.level = lv"
						>{{ lv }}</text>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label required">企业负责人 / 联系人</text>
					<input class="field-input" type="text" v-model="refForm.contact_name" placeholder="请输入姓名" maxlength="60" />
				</view>

				<view class="field-item">
					<text class="field-label required">联系电话</text>
					<input class="field-input sm-mono" type="number" v-model="refForm.contact_phone" placeholder="11 位大陆手机号码" maxlength="11" />
				</view>

				<view class="field-item">
					<text class="field-label required">业务覆盖区域</text>
					<input class="field-input" type="text" v-model="refForm.region" placeholder="如：华南区 / 广东省深圳市" maxlength="80" />
				</view>

				<view class="field-item">
					<text class="field-label">营业执照或资质扫描件（真实上传）</text>
					<view class="upload-box" @tap="pickAndUploadLicense('referral')">
						<template v-if="refLicense.file_name">
							<SmIcon name="file-check" :size="38" />
							<view class="upload-meta">
								<text class="upload-name">{{ refLicense.file_name }}</text>
								<text class="upload-tip">已安全上传至平台私密存储</text>
							</view>
							<text class="upload-del" @tap.stop="clearLicense('referral')">移除</text>
						</template>
						<template v-else>
							<SmIcon name="upload-doc" :size="42" />
							<view class="upload-meta">
								<text class="upload-name">{{ uploadingLicense ? '正在上传...' : '点击选择营业执照图片（JPG/PNG）' }}</text>
								<text class="upload-tip">单张不超过 10MB，仅用于平台商务资质核验</text>
							</view>
						</template>
					</view>
				</view>

				<view class="field-item">
					<text class="field-label">推荐码 / 邀约码（选填）</text>
					<input
						class="field-input sm-mono"
						type="text"
						v-model="refForm.invite_code"
						@input="refForm.invite_code = (refForm.invite_code || '').toUpperCase()"
						placeholder="如有商务推荐码可填写"
						maxlength="40"
					/>
				</view>

				<view class="field-item">
					<text class="field-label required">资源优势与合作设想</text>
					<textarea class="field-textarea" v-model="refForm.summary" placeholder="请简述客户资源、技术服务团队或典型落地案例" maxlength="500" />
				</view>

				<view class="btn-submit" :class="{ disabled: submitting }" @tap="submitReferral">
					{{ submitting ? '提交中...' : '提交合伙人推荐申请' }}
				</view>
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- 申请进度 -->
		<template v-else>
			<state-view v-if="loadingRecords" preset="loading" loading-text="正在同步合作申请进度..." />
			<state-view
				v-else-if="records.length === 0"
				icon="clipboard"
				title="暂无合作申请记录"
				hint="提交本体厂商、算法合伙人、达人共创或合伙人推荐后，可在此实时追踪审核进展"
				action-text="浏览合作通道"
				@action="activeTab = 'channels'"
			/>
			<scroll-view v-else class="page-scroll" scroll-y>
				<view v-for="app in records" :key="app.id" class="record-card" @tap="openDetail(app)">
					<view class="rec-top">
						<text class="rec-type">{{ app.type_label }}</text>
						<text class="rec-status" :class="'st-' + app.status">{{ app.status_label }}</text>
					</view>
					<text class="rec-org">{{ app.org_name }}</text>
					<view class="rec-meta">
						<text class="meta-line sm-mono">申请单号：{{ app.application_no }}</text>
						<text v-if="app.coop_direction" class="meta-line">合作方向：{{ app.coop_direction }}</text>
						<text v-if="app.followup_person" class="meta-line">对接顾问：{{ app.followup_person }} {{ app.follower_contact || '' }}</text>
						<text v-if="app.admin_note" class="meta-line note">商务回复：{{ app.admin_note }}</text>
					</view>

					<view v-if="app.issued_invite_code" class="issued-box" @tap.stop>
						<view>
							<text class="issued-label">平台已签发专属入驻邀请码</text>
							<text class="issued-code sm-mono">{{ app.issued_invite_code }}</text>
						</view>
						<view class="issued-btns">
							<text class="mini-act" @tap="copyText(app.issued_invite_code)">复制</text>
							<text v-if="app.onboarding_url" class="mini-act solid" @tap="jumpOnboarding(app)">去入驻</text>
						</view>
					</view>

					<view class="rec-foot" @tap.stop>
						<text class="rec-time sm-mono">{{ app.created_at }}</text>
						<view class="rec-actions">
							<text class="foot-link" @tap="openDetail(app)">查看详情</text>
							<text v-if="app.can_withdraw" class="foot-link danger" @tap="withdrawApp(app)">撤回申请</text>
							<text v-if="app.status === 'rejected' || app.status === 'withdrawn'" class="foot-link primary" @tap="reapplyFrom(app)">修改重提</text>
						</view>
					</view>
				</view>
				<view style="height: 60rpx;"></view>
			</scroll-view>
		</template>

		<!-- 通道申请弹窗 -->
		<view v-if="showChannelModal && activeChannel" class="modal-mask" @tap="showChannelModal = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">{{ activeChannel.name }} · 合作申请</text>
					<view class="sheet-close" @tap="showChannelModal = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<scroll-view class="sheet-body" scroll-y>
					<view class="field-item">
						<text class="field-label required">合作方向</text>
						<view class="chip-row">
							<text
								v-for="d in activeChannel.directions"
								:key="d"
								class="choice-chip"
								:class="{ active: chForm.coop_direction === d }"
								@tap="chForm.coop_direction = d"
							>{{ d }}</text>
						</view>
					</view>

					<view v-if="activeChannel.key === 'raas'" class="field-item">
						<text class="field-label required">开发主体类型</text>
						<view class="seg-row">
							<view class="seg-btn" :class="{ active: chForm.entity_type === 'company' }" @tap="chForm.entity_type = 'company'">企业 / 实验室团队</view>
							<view class="seg-btn" :class="{ active: chForm.entity_type === 'individual' }" @tap="chForm.entity_type = 'individual'">个人独立开发者</view>
						</view>
					</view>

					<view class="field-item">
						<text class="field-label required">{{ activeChannel.key === 'influencer' ? '达人昵称 / 机构名称' : '企业 / 团队名称' }}</text>
						<input class="field-input" type="text" v-model="chForm.org_name" placeholder="请输入真实主体或团队名称" maxlength="120" />
					</view>

					<template v-if="activeChannel.key === 'manufacturer' || activeChannel.key === 'raas'">
						<view class="field-item">
							<text class="field-label required">{{ activeChannel.key === 'manufacturer' ? '主营机器人本体型号' : '目标适配机型' }}</text>
							<input class="field-input" type="text" v-model="chForm.robot_models" placeholder="如：Unitree G1 / 智元远征 A2 / 四足巡检平台" maxlength="200" />
						</view>
					</template>

					<template v-if="activeChannel.key === 'influencer'">
						<view class="field-item">
							<text class="field-label required">主营内容平台</text>
							<view class="chip-row">
								<text
									v-for="p in ['抖音', 'B站', '小红书', '视频号', 'YouTube']"
									:key="p"
									class="choice-chip"
									:class="{ active: chForm.platform === p }"
									@tap="chForm.platform = p"
								>{{ p }}</text>
							</view>
						</view>
						<view class="field-item">
							<text class="field-label required">账号粉丝量级</text>
							<view class="chip-row">
								<text
									v-for="t in ['1万~5万', '5万~30万', '30万~100万', '100万以上']"
									:key="t"
									class="choice-chip"
									:class="{ active: chForm.followers_tier === t }"
									@tap="chForm.followers_tier = t"
								>{{ t }}</text>
							</view>
						</view>
						<view class="field-item">
							<text class="field-label">主页链接或账号 ID</text>
							<input class="field-input" type="text" v-model="chForm.account_url" placeholder="选填，便于商务快速了解内容风格" maxlength="255" />
						</view>
					</template>

					<view v-if="activeChannel.key === 'raas'" class="field-item">
						<text class="field-label">GitHub / 演示视频链接（选填）</text>
						<input class="field-input" type="text" v-model="chForm.portfolio_url" placeholder="https://..." maxlength="255" />
					</view>

					<view class="field-item">
						<text class="field-label required">联系人姓名</text>
						<input class="field-input" type="text" v-model="chForm.contact_name" placeholder="请输入联系人姓名" maxlength="60" />
					</view>

					<view class="field-item">
						<text class="field-label required">联系手机</text>
						<input class="field-input sm-mono" type="number" v-model="chForm.contact_phone" placeholder="11 位大陆手机号" maxlength="11" />
					</view>

					<view class="field-item">
						<text class="field-label">所在城市</text>
						<input class="field-input" type="text" v-model="chForm.region" placeholder="如：深圳 / 杭州 / 北京" maxlength="80" />
					</view>

					<view v-if="activeChannel.key !== 'influencer'" class="field-item">
						<text class="field-label">主体资质扫描件（选填，真实上传）</text>
						<view class="upload-box" @tap="pickAndUploadLicense('channel')">
							<template v-if="chLicense.file_name">
								<SmIcon name="file-check" :size="36" />
								<view class="upload-meta">
									<text class="upload-name">{{ chLicense.file_name }}</text>
									<text class="upload-tip">已安全上传至私密盘</text>
								</view>
								<text class="upload-del" @tap.stop="clearLicense('channel')">移除</text>
							</template>
							<template v-else>
								<SmIcon name="upload-doc" :size="38" />
								<view class="upload-meta">
									<text class="upload-name">{{ uploadingLicense ? '上传中...' : '上传营业执照或资质图片' }}</text>
									<text class="upload-tip">支持 JPG / PNG，最大 10MB</text>
								</view>
							</template>
						</view>
					</view>

					<view class="field-item">
						<text class="field-label">合作诉求说明</text>
						<textarea class="field-textarea" v-model="chForm.summary" placeholder="请补充产品能力、量产阶段或希望平台提供的支持" maxlength="500" />
					</view>
				</scroll-view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="showChannelModal = false">取消</view>
					<view class="btn-submit" :class="{ disabled: submitting }" @tap="submitChannel">
						{{ submitting ? '提交中...' : '提交合作申请' }}
					</view>
				</view>
			</view>
		</view>

		<!-- 申请详情弹窗 -->
		<view v-if="detailApp" class="modal-mask" @tap="detailApp = null">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">合作申请单详情</text>
					<view class="sheet-close" @tap="detailApp = null"><SmIcon name="close" :size="26" /></view>
				</view>
				<scroll-view class="sheet-body" scroll-y>
					<view class="kv-row"><text class="kv-k">申请单号</text><text class="kv-v sm-mono">{{ detailApp.application_no }}</text></view>
					<view class="kv-row"><text class="kv-k">合作类型</text><text class="kv-v">{{ detailApp.type_label }}</text></view>
					<view class="kv-row"><text class="kv-k">当前状态</text><text class="kv-v">{{ detailApp.status_label }}</text></view>
					<view class="kv-row"><text class="kv-k">申请主体</text><text class="kv-v">{{ detailApp.org_name }}</text></view>
					<view class="kv-row"><text class="kv-k">联系人</text><text class="kv-v">{{ detailApp.contact_name }}（{{ detailApp.contact_phone }}）</text></view>
					<view class="kv-row" v-if="detailApp.coop_direction"><text class="kv-k">合作方向</text><text class="kv-v">{{ detailApp.coop_direction }}</text></view>
					<view class="kv-row" v-for="m in (detailApp.formatted_meta || [])" :key="m.key">
						<text class="kv-k">{{ m.label }}</text>
						<text class="kv-v">{{ m.value }}</text>
					</view>
					<view class="kv-row" v-if="detailApp.summary"><text class="kv-k">补充说明</text><text class="kv-v">{{ detailApp.summary }}</text></view>
					<view class="kv-row" v-if="detailApp.admin_note"><text class="kv-k">平台审核意见</text><text class="kv-v">{{ detailApp.admin_note }}</text></view>
				</scroll-view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { cooperationApi, portalApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config'

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			activeTab: 'channels',
			channels: [
				{
					key: 'manufacturer',
					name: '机器人本体厂商',
					icon: 'coop-manufacturer',
					tag: '整机租售 · 硬件接入',
					desc: '面向人形与四足机器人整机厂商，开放官方旗舰租赁、直采分销及硬件型号目录收录。',
					benefits: ['多租户商家工作台', '全国驻场交付协同', '标准机型目录收录'],
					directions: ['整机租赁与销售入驻', '新机型目录与Agent适配', '行业标杆场景联合交付', '赛事指定用机合作'],
					portalHint: '审核通过后开通「商家工作台」',
				},
				{
					key: 'raas',
					name: 'RaaS 算法与技能开发者',
					icon: 'coop-raas',
					tag: '买断分发 · 开发者门户',
					desc: '面向具身算法实验室与独立开发者，提供技能包安检、L2 自测存证与按次买断商业分发。',
					benefits: ['独立开发者门户', '技能包合规签名', '买断授权收益结算'],
					directions: ['商演迎宾与主持技能', '展厅讲解与导览技能', '巡检与行业作业技能', '教育科研示范技能'],
					portalHint: '审核通过后开通「开发者工作台」',
				},
				{
					key: 'influencer',
					name: '机甲评测与赛事达人',
					icon: 'coop-influencer',
					tag: '样机体验 · 内容共创',
					desc: '面向科技评测博主、高校社团主理人与赛事解说达人，提供真机借测与官方活动共创。',
					benefits: ['新款真机优先体验', '官方赛事现场席位', '联合内容推广支持'],
					directions: ['真机深度开箱评测', '线下机甲赛事直播', '高校科普内容共创', '品牌商业探店打卡'],
					portalHint: '专属内容运营顾问 1 对 1 对接',
				},
			],
			showChannelModal: false,
			activeChannel: null,
			submitting: false,
			uploadingLicense: false,
			chLicense: { attachment_token: '', file_name: '' },
			refLicense: { attachment_token: '', file_name: '' },
			chForm: {
				coop_direction: '',
				entity_type: 'company',
				org_name: '',
				robot_models: '',
				platform: 'B站',
				followers_tier: '5万~30万',
				account_url: '',
				portfolio_url: '',
				contact_name: '',
				contact_phone: '',
				region: '',
				summary: '',
			},
			refForm: {
				recommender_type: 'sales',
				org_name: '',
				level: '城市合伙人',
				contact_name: '',
				contact_phone: '',
				region: '',
				invite_code: '',
				summary: '',
			},
			loadingRecords: false,
			records: [],
			detailApp: null,
		}
	},
	onLoad(q) {
		if (q && (q.tab === 'history' || q.tab === 'referral')) {
			this.activeTab = q.tab
		}
	},
	onShow() {
		const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
		if (customer.name && !this.chForm.contact_name) this.chForm.contact_name = customer.name
		if (customer.phone && !this.chForm.contact_phone) this.chForm.contact_phone = customer.phone
		this.loadRecords()
	},
	methods: {
		goSupplier() {
			uni.navigateTo({ url: '/pages/mine/supplier' })
		},
		switchHistory() {
			this.activeTab = 'history'
			this.loadRecords()
		},
		loadRecords() {
			this.loadingRecords = true
			cooperationApi.list()
				.then((res) => {
					const all = Array.isArray(res && res.data) ? res.data : []
					this.records = all.filter(x => x.coop_type !== 'supplier_invite')
				})
				.catch(() => {})
				.finally(() => {
					this.loadingRecords = false
				})
		},
		openChannelModal(ch) {
			const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
			this.activeChannel = ch
			this.chLicense = { attachment_token: '', file_name: '' }
			this.chForm = {
				coop_direction: ch.directions[0] || '',
				entity_type: 'company',
				org_name: customer.company_name || '',
				robot_models: '',
				platform: 'B站',
				followers_tier: '5万~30万',
				account_url: '',
				portfolio_url: '',
				contact_name: customer.name || '',
				contact_phone: customer.phone || '',
				region: '',
				summary: '',
			}
			this.showChannelModal = true
		},
		pickAndUploadLicense(target) {
			if (this.uploadingLicense) return
			uni.chooseImage({
				count: 1,
				sizeType: ['compressed'],
				success: (res) => {
					const filePath = res.tempFilePaths && res.tempFilePaths[0]
					if (!filePath) return
					this.uploadingLicense = true
					cooperationApi.uploadAttachment(filePath)
						.then((up) => {
							const d = (up && up.data) || {}
							const info = {
								attachment_token: d.attachment_token || '',
								file_name: d.file_name || '已上传资质图片.jpg',
							}
							if (target === 'referral') this.refLicense = info
							else this.chLicense = info
							uni.showToast({ title: '资质已上传', icon: 'success' })
						})
						.catch((err) => {
							uni.showToast({ title: (err && err.message) || '上传失败', icon: 'none' })
						})
						.finally(() => {
							this.uploadingLicense = false
						})
				},
			})
		},
		clearLicense(target) {
			if (target === 'referral') this.refLicense = { attachment_token: '', file_name: '' }
			else this.chLicense = { attachment_token: '', file_name: '' }
		},
		submitChannel() {
			if (this.submitting || !this.activeChannel) return
			const ch = this.activeChannel
			const f = this.chForm
			if (!f.org_name.trim()) { uni.showToast({ title: '请填写主体或团队名称', icon: 'none' }); return }
			if (!f.contact_name.trim()) { uni.showToast({ title: '请填写联系人姓名', icon: 'none' }); return }
			if (!/^1\d{10}$/.test(String(f.contact_phone || '').trim())) { uni.showToast({ title: '请填写正确的 11 位手机号', icon: 'none' }); return }
			if ((ch.key === 'manufacturer' || ch.key === 'raas') && !f.robot_models.trim()) {
				uni.showToast({ title: '请填写主营或适配的机器人型号', icon: 'none' })
				return
			}

			const meta = {}
			if (ch.key === 'manufacturer') {
				meta.robot_models = f.robot_models.trim()
			} else if (ch.key === 'raas') {
				meta.entity_type = f.entity_type
				meta.robot_models = f.robot_models.trim()
				if (f.portfolio_url.trim()) meta.portfolio_url = f.portfolio_url.trim()
			} else if (ch.key === 'influencer') {
				meta.platform = f.platform
				meta.followers_tier = f.followers_tier
				if (f.account_url.trim()) meta.account_url = f.account_url.trim()
			}
			if (this.chLicense.attachment_token) {
				meta.license_attachment_token = this.chLicense.attachment_token
				meta.license_file_name = this.chLicense.file_name
			}

			this.submitting = true
			cooperationApi.submit({
				coop_type: ch.key,
				org_name: f.org_name.trim(),
				contact_name: f.contact_name.trim(),
				contact_phone: f.contact_phone.trim(),
				region: f.region.trim() || null,
				coop_direction: f.coop_direction,
				summary: f.summary.trim() || null,
				meta,
			})
				.then(() => {
					this.showChannelModal = false
					uni.showToast({ title: '合作申请已提交', icon: 'success' })
					this.switchHistory()
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '提交失败', icon: 'none' })
				})
				.finally(() => {
					this.submitting = false
				})
		},
		submitReferral() {
			if (this.submitting) return
			const f = this.refForm
			if (!f.org_name.trim()) { uni.showToast({ title: '请填写被推荐企业全称', icon: 'none' }); return }
			if (!f.contact_name.trim()) { uni.showToast({ title: '请填写联系人姓名', icon: 'none' }); return }
			if (!/^1\d{10}$/.test(String(f.contact_phone || '').trim())) { uni.showToast({ title: '请填写正确的 11 位手机号', icon: 'none' }); return }
			if (!f.region.trim()) { uni.showToast({ title: '请填写业务覆盖区域', icon: 'none' }); return }
			if (!f.summary.trim()) { uni.showToast({ title: '请填写资源优势与合作设想', icon: 'none' }); return }

			const meta = {
				recommender_type: f.recommender_type,
				level: f.level,
			}
			if (f.invite_code.trim()) meta.invite_code = f.invite_code.trim()
			if (this.refLicense.attachment_token) {
				meta.license_attachment_token = this.refLicense.attachment_token
				meta.license_file_name = this.refLicense.file_name
			}

			this.submitting = true
			cooperationApi.submit({
				coop_type: 'partner_referral',
				org_name: f.org_name.trim(),
				contact_name: f.contact_name.trim(),
				contact_phone: f.contact_phone.trim(),
				region: f.region.trim(),
				coop_direction: f.recommender_type === 'fulfill' ? '驻场履约合伙人' : '区域销售合伙人',
				summary: f.summary.trim(),
				meta,
			})
				.then(() => {
					uni.showToast({ title: '合伙人推荐已提交', icon: 'success' })
					this.switchHistory()
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '提交失败', icon: 'none' })
				})
				.finally(() => {
					this.submitting = false
				})
		},
		openDetail(app) {
			cooperationApi.detail(app.id)
				.then((res) => {
					this.detailApp = (res && res.data) || app
				})
				.catch(() => {
					this.detailApp = app
				})
		},
		withdrawApp(app) {
			uni.showModal({
				title: '撤回合作申请',
				content: `确认撤回「${app.org_name}」的待审核申请吗？撤回后可修改资料重新提交。`,
				confirmColor: '#DC2626',
				success: (res) => {
					if (!res.confirm) return
					cooperationApi.withdraw(app.id)
						.then(() => {
							uni.showToast({ title: '申请已撤回', icon: 'success' })
							this.loadRecords()
						})
						.catch((err) => {
							uni.showToast({ title: (err && err.message) || '撤回失败', icon: 'none' })
						})
				},
			})
		},
		reapplyFrom(app) {
			if (app.coop_type === 'partner_referral') {
				this.refForm.org_name = app.org_name || ''
				this.refForm.contact_name = app.contact_name || ''
				this.refForm.contact_phone = app.contact_phone || ''
				this.refForm.region = app.region || ''
				this.refForm.summary = app.summary || ''
				this.activeTab = 'referral'
				return
			}
			const ch = this.channels.find(c => c.key === app.coop_type) || this.channels[0]
			this.openChannelModal(ch)
			this.chForm.org_name = app.org_name || ''
			this.chForm.contact_name = app.contact_name || ''
			this.chForm.contact_phone = app.contact_phone || ''
			this.chForm.region = app.region || ''
			this.chForm.summary = app.summary || ''
		},
		copyText(text) {
			uni.setClipboardData({
				data: String(text || ''),
				success: () => uni.showToast({ title: '邀请码已复制', icon: 'success' }),
			})
		},
		jumpOnboarding(app) {
			const isLogged = !!uni.getStorageSync(TOKEN_KEY)
			const portalKey = app.target_portal === 'merchant' ? 'merchant_admin' : 'developer_skill'
			const redirectPath = app.target_portal === 'merchant'
				? `/merchant/onboarding?code=${encodeURIComponent(app.issued_invite_code || '')}`
				: `/dev/join?code=${encodeURIComponent(app.issued_invite_code || '')}`

			if (isLogged) {
				portalApi.handoffTicket(portalKey, redirectPath)
					.then((res) => {
						const url = res && res.data && res.data.sso_url
						this.openExternalPortal(url || app.onboarding_url)
					})
					.catch(() => {
						this.openExternalPortal(app.onboarding_url)
					})
				return
			}
			this.openExternalPortal(app.onboarding_url)
		},
		openExternalPortal(url) {
			if (!url) return
			// #ifdef H5
			window.location.href = url
			// #endif
			// #ifndef H5
			uni.setClipboardData({
				data: url,
				success: () => uni.showToast({ title: '入驻链接已复制，请在电脑浏览器打开', icon: 'none' }),
			})
			// #endif
		},
	},
}
</script>

<style scoped>
.coop-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.hero-card { margin: 20rpx 28rpx 0; padding: 30rpx 32rpx; border-radius: 24rpx; background: linear-gradient(135deg, #102F6E 0%, #174299 100%); color: #FFFFFF; }
.hero-title { display: block; font-size: 33rpx; font-weight: 600; margin-bottom: 10rpx; }
.hero-desc { display: block; font-size: 24rpx; color: rgba(255, 255, 255, 0.82); line-height: 1.55; }
.tabs-bar { display: flex; margin: 20rpx 28rpx 0; background: #E9EEF6; border-radius: 16rpx; padding: 6rpx; }
.tab-item { flex: 1; height: 68rpx; border-radius: 12rpx; display: flex; align-items: center; justify-content: center; gap: 8rpx; font-size: 26rpx; font-weight: 600; color: #475569; }
.tab-item.active { background: #FFFFFF; color: #165DFF; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06); }
.tab-count { font-size: 21rpx; padding: 2rpx 10rpx; border-radius: 999rpx; background: #EEF4FF; color: #165DFF; }
.page-scroll { flex: 1; padding: 22rpx 28rpx; box-sizing: border-box; }
.channel-card { background: #FFFFFF; border-radius: 24rpx; padding: 30rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.ch-head { display: flex; gap: 22rpx; align-items: flex-start; }
.ch-icon-box { width: 84rpx; height: 84rpx; border-radius: 20rpx; background: #EEF4FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ch-info { flex: 1; }
.ch-title-row { display: flex; align-items: center; gap: 14rpx; flex-wrap: wrap; margin-bottom: 8rpx; }
.ch-name { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.ch-tag { font-size: 22rpx; color: #165DFF; background: #EEF4FF; padding: 4rpx 14rpx; border-radius: 8rpx; font-weight: 500; }
.ch-desc { font-size: 25rpx; color: #475569; line-height: 1.55; }
.benefit-row { display: flex; flex-wrap: wrap; gap: 12rpx; margin: 22rpx 0; }
.benefit-pill { font-size: 23rpx; color: #334155; background: #F8FAFC; border: 1rpx solid #E2E8F0; padding: 6rpx 16rpx; border-radius: 999rpx; }
.ch-foot { display: flex; align-items: center; justify-content: space-between; padding-top: 20rpx; border-top: 1rpx solid #F1F5F9; }
.ch-hint { font-size: 23rpx; color: #64748B; }
.btn-apply { padding: 14rpx 32rpx; border-radius: 14rpx; background: #165DFF; color: #FFFFFF; font-size: 26rpx; font-weight: 600; }
.supplier-entry-card { background: #FFFFFF; border-radius: 22rpx; padding: 28rpx; border: 1rpx solid #D6E4FF; display: flex; align-items: center; justify-content: space-between; gap: 20rpx; }
.se-left { display: flex; align-items: center; gap: 20rpx; flex: 1; }
.se-info { flex: 1; display: flex; flex-direction: column; gap: 6rpx; }
.se-title { font-size: 29rpx; font-weight: 600; color: #0F172A; }
.se-sub { font-size: 23rpx; color: #64748B; line-height: 1.45; }
.form-card { background: #FFFFFF; border-radius: 24rpx; padding: 30rpx; border: 1rpx solid #E5EAF2; }
.card-head { margin-bottom: 26rpx; display: flex; flex-direction: column; gap: 6rpx; }
.card-title { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.card-sub { font-size: 24rpx; color: #64748B; line-height: 1.5; }
.field-item { margin-bottom: 24rpx; }
.field-label { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.field-label.required::after { content: ' *'; color: #DC2626; }
.field-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; }
.field-textarea { width: 100%; min-height: 150rpx; padding: 20rpx 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; line-height: 1.5; }
.seg-row { display: flex; gap: 16rpx; }
.seg-btn { flex: 1; height: 74rpx; border-radius: 14rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-size: 26rpx; color: #475569; font-weight: 500; }
.seg-btn.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.chip-row { display: flex; flex-wrap: wrap; gap: 12rpx; }
.choice-chip { padding: 10rpx 22rpx; border-radius: 999rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; font-size: 24rpx; color: #475569; }
.choice-chip.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.upload-box { padding: 24rpx; border-radius: 16rpx; border: 2rpx dashed #CBD5E1; background: #F8FAFC; display: flex; align-items: center; gap: 20rpx; }
.upload-meta { flex: 1; display: flex; flex-direction: column; gap: 4rpx; }
.upload-name { font-size: 26rpx; font-weight: 500; color: #0F172A; }
.upload-tip { font-size: 22rpx; color: #64748B; }
.upload-del { font-size: 25rpx; color: #DC2626; font-weight: 500; }
.btn-submit { height: 88rpx; border-radius: 18rpx; background: #165DFF; color: #FFFFFF; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; flex: 2; }
.btn-submit.disabled { opacity: 0.6; }
.btn-cancel { height: 88rpx; border-radius: 18rpx; background: #F1F5F9; color: #475569; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; flex: 1; }
.record-card { background: #FFFFFF; border-radius: 22rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.rec-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12rpx; }
.rec-type { font-size: 23rpx; font-weight: 600; color: #165DFF; background: #EEF4FF; padding: 4rpx 14rpx; border-radius: 8rpx; }
.rec-status { font-size: 24rpx; font-weight: 600; }
.st-pending { color: #D97706; }
.st-contacting { color: #165DFF; }
.st-approved { color: #059669; }
.st-rejected, .st-withdrawn { color: #64748B; }
.rec-org { display: block; font-size: 31rpx; font-weight: 600; color: #0F172A; margin-bottom: 12rpx; }
.rec-meta { display: flex; flex-direction: column; gap: 6rpx; }
.meta-line { font-size: 25rpx; color: #475569; }
.meta-line.note { color: #1E40AF; }
.issued-box { margin-top: 18rpx; padding: 18rpx 22rpx; border-radius: 16rpx; background: #ECFDF5; border: 1rpx solid #A7F3D0; display: flex; align-items: center; justify-content: space-between; }
.issued-label { display: block; font-size: 22rpx; color: #047857; }
.issued-code { display: block; font-size: 30rpx; font-weight: 700; color: #065F46; margin-top: 4rpx; }
.issued-btns { display: flex; gap: 14rpx; }
.mini-act { font-size: 24rpx; padding: 8rpx 20rpx; border-radius: 10rpx; color: #047857; border: 1rpx solid #6EE7B7; }
.mini-act.solid { background: #059669; color: #FFFFFF; border-color: #059669; }
.rec-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 20rpx; padding-top: 18rpx; border-top: 1rpx solid #F1F5F9; }
.rec-time { font-size: 23rpx; color: #94A3B8; }
.rec-actions { display: flex; gap: 24rpx; }
.foot-link { font-size: 25rpx; color: #475569; }
.foot-link.primary { color: #165DFF; font-weight: 600; }
.foot-link.danger { color: #DC2626; }
.modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 100; display: flex; align-items: flex-end; }
.modal-sheet { width: 100%; max-height: 88vh; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; display: flex; flex-direction: column; padding-bottom: env(safe-area-inset-bottom); }
.sheet-head { display: flex; align-items: center; justify-content: space-between; padding: 30rpx 32rpx 22rpx; border-bottom: 1rpx solid #EEF2F7; }
.sheet-title { font-size: 32rpx; font-weight: 600; color: #0F172A; }
.sheet-close { padding: 8rpx; }
.sheet-body { padding: 24rpx 32rpx; max-height: 65vh; box-sizing: border-box; }
.sheet-foot { display: flex; gap: 20rpx; padding: 20rpx 32rpx 28rpx; border-top: 1rpx solid #EEF2F7; }
.kv-row { display: flex; justify-content: space-between; gap: 24rpx; padding: 18rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.kv-k { font-size: 26rpx; color: #64748B; flex-shrink: 0; }
.kv-v { font-size: 26rpx; color: #0F172A; text-align: right; word-break: break-all; }
</style>
