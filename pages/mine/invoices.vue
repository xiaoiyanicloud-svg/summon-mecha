<template>
	<view class="invoice-page">
		<nav-bar title="发票与抬头簿">
			<template #right>
				<text v-if="isLoggedIn" class="nav-action" @tap="activeTab === 'records' ? openApplyModal() : openHeaderModal()">
					{{ activeTab === 'records' ? '申请开票' : '新增抬头' }}
				</text>
			</template>
		</nav-bar>

		<state-view
			v-if="!isLoggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可申请电子普票、增值税专票及管理企业抬头簿"
			action-text="返回登录"
			@action="goMine"
		/>

		<template v-else>
			<view class="stats-banner">
				<view class="stat-col">
					<text class="stat-num sm-mono">{{ stats.total }}</text>
					<text class="stat-label">累计登记</text>
				</view>
				<view class="stat-divider" />
				<view class="stat-col">
					<text class="stat-num sm-mono">{{ stats.pending }}</text>
					<text class="stat-label">待财务开具</text>
				</view>
				<view class="stat-divider" />
				<view class="stat-col">
					<text class="stat-num sm-mono">{{ stats.done }}</text>
					<text class="stat-label">已开具</text>
				</view>
				<view class="stat-divider" />
				<view class="stat-col">
					<text class="stat-num sm-mono">{{ headers.length }}</text>
					<text class="stat-label">常用抬头</text>
				</view>
			</view>

			<view class="tabs-bar">
				<view class="tab-item" :class="{ active: activeTab === 'records' }" @tap="activeTab = 'records'">开票记录</view>
				<view class="tab-item" :class="{ active: activeTab === 'headers' }" @tap="activeTab = 'headers'">企业抬头簿</view>
			</view>

			<!-- 开票记录 -->
			<template v-if="activeTab === 'records'">
				<view class="honest-bar">
					<SmIcon name="info" :size="26" />
					<text class="honest-text">当前开放开票信息登记，由平台财务核对后开具并发送至接收邮箱；税控直连 PDF 自动下载待接入。</text>
				</view>

				<state-view v-if="loading" preset="loading" loading-text="正在读取开票记录..." />
				<state-view v-else-if="errorMsg" preset="network" :title="errorMsg" @retry="loadAll" />
				<state-view
					v-else-if="invoices.length === 0"
					icon="invoice"
					title="暂无开票登记记录"
					hint="支付完成的租售、配件与技能订单均可申请普通发票或增值税专用发票"
					action-text="申请开票登记"
					@action="openApplyModal"
				/>

				<scroll-view v-else class="list-scroll" scroll-y>
					<view v-for="inv in invoices" :key="inv.id" class="inv-card" @tap="selectedInvoice = inv">
						<view class="inv-top">
							<view class="inv-tags">
								<text class="kind-tag" :class="inv.invoice_kind === 'special' ? 'special' : 'ordinary'">
									{{ inv.invoice_kind_label || (inv.invoice_kind === 'special' ? '增值税专用发票' : '电子普通发票') }}
								</text>
								<text class="type-tag">{{ inv.type === 'company' ? '企业' : '个人' }}</text>
							</view>
							<text class="status-pill" :class="inv.status === 'issued' ? 'done' : 'pending'">
								{{ inv.status_label || (inv.status === 'issued' ? '已开具' : '待财务开具') }}
							</text>
						</view>
						<text class="inv-title">{{ inv.title || '未命名抬头' }}</text>
						<view class="inv-meta">
							<text class="meta-line sm-mono">关联订单 #{{ inv.order_id }} · ¥{{ inv.amount }}</text>
							<text v-if="inv.tax_no" class="meta-line sm-mono">税号：{{ inv.tax_no }}</text>
							<text v-if="inv.receive_email" class="meta-line">接收邮箱：{{ inv.receive_email }}</text>
						</view>
						<view class="inv-foot">
							<text class="inv-time sm-mono">{{ inv.created_at || '—' }}</text>
							<text class="inv-link">查看登记详情 ›</text>
						</view>
					</view>
					<view class="bottom-bar">
						<view class="btn-primary" @tap="openApplyModal">+ 申请新订单开票</view>
					</view>
					<view style="height: 60rpx;"></view>
				</scroll-view>
			</template>

			<!-- 企业抬头簿 -->
			<template v-else>
				<state-view
					v-if="headers.length === 0"
					icon="building"
					title="暂无已保存发票抬头"
					hint="预先保存企业税号、开户行与注册地址，后续开具普票或专票可一键填入"
					action-text="新增发票抬头"
					@action="openHeaderModal()"
				/>
				<scroll-view v-else class="list-scroll" scroll-y>
					<view v-for="h in headers" :key="h.id" class="inv-card">
						<view class="inv-top">
							<view class="inv-tags">
								<text class="kind-tag" :class="h.invoice_kind === 'special' ? 'special' : 'ordinary'">
									{{ h.invoice_kind === 'special' ? '专票四要素齐备' : '普票抬头' }}
								</text>
								<text v-if="h.is_default" class="default-pill">默认</text>
							</view>
							<text class="type-tag">{{ h.type === 'company' ? '企业单位' : '个人/非企业' }}</text>
						</view>
						<text class="inv-title">{{ h.title }}</text>
						<view class="inv-meta">
							<text v-if="h.tax_no" class="meta-line sm-mono">税号：{{ h.tax_no }}</text>
							<text v-if="h.bank_name" class="meta-line">开户行：{{ h.bank_name }} {{ h.bank_account || '' }}</text>
							<text v-if="h.receive_email" class="meta-line">接收邮箱：{{ h.receive_email }}</text>
						</view>
						<view class="header-actions">
							<text class="act-btn primary" @tap="useHeaderForApply(h)">用于开票</text>
							<text class="act-btn" @tap="openHeaderModal(h)">编辑</text>
							<text class="act-btn danger" @tap="removeHeader(h)">删除</text>
						</view>
					</view>
					<view class="bottom-bar">
						<view class="btn-primary" @tap="openHeaderModal()">+ 新增发票抬头</view>
					</view>
					<view style="height: 60rpx;"></view>
				</scroll-view>
			</template>
		</template>

		<!-- 申请开票弹窗 -->
		<view v-if="showApplyModal" class="modal-mask" @tap="showApplyModal = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">申请开票登记</text>
					<view class="sheet-close" @tap="showApplyModal = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<scroll-view class="sheet-body" scroll-y>
					<view class="field-item">
						<text class="field-label required">选择已支付订单</text>
						<picker v-if="paidOrders.length" :range="paidOrderLabels" @change="onPickOrder">
							<view class="picker-box">
								<text :class="{ placeholder: !applyForm.order_id }">{{ pickedOrderText || '点击选择需开票的订单' }}</text>
								<text class="picker-arrow">›</text>
							</view>
						</picker>
						<view v-else class="empty-inline">当前暂无可开票的已支付订单</view>
					</view>

					<view class="field-item">
						<text class="field-label required">发票种类</text>
						<view class="seg-row">
							<view class="seg-btn" :class="{ active: applyForm.invoice_kind === 'ordinary' }" @tap="setApplyKind('ordinary')">电子普通发票</view>
							<view class="seg-btn" :class="{ active: applyForm.invoice_kind === 'special' }" @tap="setApplyKind('special')">增值税专用发票</view>
						</view>
					</view>

					<view class="field-item" v-if="applyForm.invoice_kind !== 'special'">
						<text class="field-label required">抬头类型</text>
						<view class="seg-row">
							<view class="seg-btn" :class="{ active: applyForm.type === 'company' }" @tap="applyForm.type = 'company'">企业单位</view>
							<view class="seg-btn" :class="{ active: applyForm.type === 'personal' }" @tap="applyForm.type = 'personal'">个人 / 非企业</view>
						</view>
					</view>

					<view v-if="headers.length" class="quick-header-bar">
						<picker :range="headerPickerLabels" @change="onPickSavedHeader">
							<text class="quick-header-link">从抬头簿一键带入（共 {{ headers.length }} 条） ›</text>
						</picker>
					</view>

					<view class="field-item">
						<text class="field-label required">发票抬头名称</text>
						<input class="field-input" type="text" v-model="applyForm.title" placeholder="企业营业执照全称或个人姓名" maxlength="191" />
					</view>

					<view class="field-item" v-if="applyForm.type === 'company'">
						<text class="field-label required">纳税人识别号（统一社会信用代码）</text>
						<input
							class="field-input sm-mono"
							type="text"
							v-model="applyForm.tax_no"
							@input="applyForm.tax_no = (applyForm.tax_no || '').toUpperCase()"
							placeholder="15~20 位大写字母与数字"
							maxlength="20"
						/>
					</view>

					<template v-if="applyForm.invoice_kind === 'special'">
						<view class="field-item">
							<text class="field-label required">开户银行名称</text>
							<input class="field-input" type="text" v-model="applyForm.bank_name" placeholder="如：招商银行深圳科技园支行" maxlength="191" />
						</view>
						<view class="field-item">
							<text class="field-label required">基本户银行账号</text>
							<input class="field-input sm-mono" type="text" v-model="applyForm.bank_account" placeholder="请输入对公银行账号" maxlength="64" />
						</view>
						<view class="field-item">
							<text class="field-label required">企业注册地址</text>
							<input class="field-input" type="text" v-model="applyForm.company_address" placeholder="须与税务登记地址一致" maxlength="255" />
						</view>
						<view class="field-item">
							<text class="field-label required">企业注册电话</text>
							<input class="field-input sm-mono" type="text" v-model="applyForm.company_phone" placeholder="区号-固话 或 手机号" maxlength="64" />
						</view>
					</template>

					<view class="field-item">
						<text class="field-label">接收电子票据邮箱</text>
						<input class="field-input sm-mono" type="text" v-model="applyForm.receive_email" placeholder="选填，默认发送至账户注册邮箱" maxlength="191" />
					</view>

					<view class="save-check-row" @tap="applyForm.save_header = !applyForm.save_header">
						<view class="mini-box" :class="{ checked: applyForm.save_header }">{{ applyForm.save_header ? '✓' : '' }}</view>
						<text class="save-check-text">同步保存至我的常用抬头簿</text>
					</view>
				</scroll-view>
				<view class="sheet-foot">
					<view class="btn-secondary" @tap="showApplyModal = false">取消</view>
					<view class="btn-primary" :class="{ disabled: submitting }" @tap="submitApply">
						{{ submitting ? '提交中...' : '提交开票登记' }}
					</view>
				</view>
			</view>
		</view>

		<!-- 新增/编辑抬头弹窗 -->
		<view v-if="showHeaderModal" class="modal-mask" @tap="showHeaderModal = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">{{ editingHeaderId ? '编辑发票抬头' : '新增发票抬头' }}</text>
					<view class="sheet-close" @tap="showHeaderModal = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<scroll-view class="sheet-body" scroll-y>
					<view class="field-item">
						<text class="field-label required">适用票种</text>
						<view class="seg-row">
							<view class="seg-btn" :class="{ active: headerForm.invoice_kind === 'ordinary' }" @tap="headerForm.invoice_kind = 'ordinary'">普通发票</view>
							<view class="seg-btn" :class="{ active: headerForm.invoice_kind === 'special' }" @tap="headerForm.invoice_kind = 'special'; headerForm.type = 'company'">专用发票</view>
						</view>
					</view>
					<view class="field-item" v-if="headerForm.invoice_kind !== 'special'">
						<text class="field-label required">主体类型</text>
						<view class="seg-row">
							<view class="seg-btn" :class="{ active: headerForm.type === 'company' }" @tap="headerForm.type = 'company'">企业单位</view>
							<view class="seg-btn" :class="{ active: headerForm.type === 'personal' }" @tap="headerForm.type = 'personal'">个人</view>
						</view>
					</view>
					<view class="field-item">
						<text class="field-label required">抬头名称</text>
						<input class="field-input" type="text" v-model="headerForm.title" placeholder="请输入企业全称或个人姓名" maxlength="191" />
					</view>
					<view class="field-item" v-if="headerForm.type === 'company'">
						<text class="field-label required">纳税人识别号</text>
						<input
							class="field-input sm-mono"
							type="text"
							v-model="headerForm.tax_no"
							@input="headerForm.tax_no = (headerForm.tax_no || '').toUpperCase()"
							placeholder="15~20 位税号"
							maxlength="20"
						/>
					</view>
					<template v-if="headerForm.invoice_kind === 'special'">
						<view class="field-item">
							<text class="field-label required">开户银行</text>
							<input class="field-input" type="text" v-model="headerForm.bank_name" placeholder="请输入开户银行支行名称" maxlength="191" />
						</view>
						<view class="field-item">
							<text class="field-label required">银行账号</text>
							<input class="field-input sm-mono" type="text" v-model="headerForm.bank_account" placeholder="请输入银行账号" maxlength="64" />
						</view>
						<view class="field-item">
							<text class="field-label required">企业注册地址</text>
							<input class="field-input" type="text" v-model="headerForm.company_address" placeholder="请输入注册地址" maxlength="255" />
						</view>
						<view class="field-item">
							<text class="field-label required">企业注册电话</text>
							<input class="field-input sm-mono" type="text" v-model="headerForm.company_phone" placeholder="请输入注册电话" maxlength="64" />
						</view>
					</template>
					<view class="field-item">
						<text class="field-label">默认接收邮箱</text>
						<input class="field-input sm-mono" type="text" v-model="headerForm.receive_email" placeholder="选填，接收电子发票的邮箱" maxlength="191" />
					</view>
					<view class="save-check-row" @tap="headerForm.is_default = !headerForm.is_default">
						<view class="mini-box" :class="{ checked: headerForm.is_default }">{{ headerForm.is_default ? '✓' : '' }}</view>
						<text class="save-check-text">设为默认开票抬头</text>
					</view>
				</scroll-view>
				<view class="sheet-foot">
					<view class="btn-secondary" @tap="showHeaderModal = false">取消</view>
					<view class="btn-primary" :class="{ disabled: savingHeader }" @tap="submitHeader">
						{{ savingHeader ? '保存中...' : '保存抬头' }}
					</view>
				</view>
			</view>
		</view>

		<!-- 登记详情弹窗 -->
		<view v-if="selectedInvoice" class="modal-mask" @tap="selectedInvoice = null">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">开票登记详情</text>
					<view class="sheet-close" @tap="selectedInvoice = null"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="detail-list">
					<view class="detail-row"><text class="dk">登记状态</text><text class="dv">{{ selectedInvoice.status_label || selectedInvoice.status }}</text></view>
					<view class="detail-row"><text class="dk">发票种类</text><text class="dv">{{ selectedInvoice.invoice_kind_label || selectedInvoice.invoice_kind }}</text></view>
					<view class="detail-row"><text class="dk">发票抬头</text><text class="dv">{{ selectedInvoice.title }}</text></view>
					<view class="detail-row" v-if="selectedInvoice.tax_no"><text class="dk">纳税人识别号</text><text class="dv sm-mono">{{ selectedInvoice.tax_no }}</text></view>
					<view class="detail-row"><text class="dk">开票金额</text><text class="dv sm-mono">¥{{ selectedInvoice.amount }}</text></view>
					<view class="detail-row"><text class="dk">关联订单 ID</text><text class="dv sm-mono">#{{ selectedInvoice.order_id }}</text></view>
					<view class="detail-row" v-if="selectedInvoice.bank_name"><text class="dk">开户行及账号</text><text class="dv">{{ selectedInvoice.bank_name }} {{ selectedInvoice.bank_account || '' }}</text></view>
					<view class="detail-row" v-if="selectedInvoice.company_address"><text class="dk">注册地址电话</text><text class="dv">{{ selectedInvoice.company_address }} {{ selectedInvoice.company_phone || '' }}</text></view>
					<view class="detail-row" v-if="selectedInvoice.receive_email"><text class="dk">接收邮箱</text><text class="dv sm-mono">{{ selectedInvoice.receive_email }}</text></view>
					<view class="detail-row" v-if="selectedInvoice.invoice_no"><text class="dk">发票号码</text><text class="dv sm-mono">{{ selectedInvoice.invoice_no }}</text></view>
					<view class="detail-row"><text class="dk">登记时间</text><text class="dv sm-mono">{{ selectedInvoice.created_at || '—' }}</text></view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { invoiceApi, orderApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config'

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			isLoggedIn: false,
			activeTab: 'records',
			loading: false,
			errorMsg: '',
			invoices: [],
			headers: [],
			paidOrders: [],
			showApplyModal: false,
			submitting: false,
			applyForm: {
				order_id: '',
				invoice_kind: 'ordinary',
				type: 'company',
				title: '',
				tax_no: '',
				bank_name: '',
				bank_account: '',
				company_address: '',
				company_phone: '',
				receive_email: '',
				save_header: true,
			},
			showHeaderModal: false,
			savingHeader: false,
			editingHeaderId: null,
			headerForm: {
				invoice_kind: 'ordinary',
				type: 'company',
				title: '',
				tax_no: '',
				bank_name: '',
				bank_account: '',
				company_address: '',
				company_phone: '',
				receive_email: '',
				is_default: false,
			},
			selectedInvoice: null,
		}
	},
	computed: {
		stats() {
			return {
				total: this.invoices.length,
				pending: this.invoices.filter(i => i.status !== 'issued').length,
				done: this.invoices.filter(i => i.status === 'issued').length,
			}
		},
		paidOrderLabels() {
			return this.paidOrders.map(o => `#${o.increment_id || o.id} · ¥${o.grand_total}`)
		},
		pickedOrderText() {
			const hit = this.paidOrders.find(o => o.id === this.applyForm.order_id)
			return hit ? `#${hit.increment_id || hit.id} · ¥${hit.grand_total}` : ''
		},
		headerPickerLabels() {
			return this.headers.map(h => `${h.title}（${h.invoice_kind === 'special' ? '专票' : '普票'}）`)
		},
	},
	onShow() {
		this.isLoggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.isLoggedIn) this.loadAll()
	},
	methods: {
		goMine() {
			uni.switchTab({ url: '/pages/mine/index' })
		},
		loadAll() {
			this.loading = true
			this.errorMsg = ''
			Promise.all([
				invoiceApi.list(),
				invoiceApi.headers().catch(() => ({ data: [] })),
			])
				.then(([invRes, headRes]) => {
					this.invoices = Array.isArray(invRes && invRes.data) ? invRes.data : []
					this.headers = Array.isArray(headRes && headRes.data) ? headRes.data : []
				})
				.catch((err) => {
					this.errorMsg = (err && err.message) || '加载开票数据失败'
				})
				.finally(() => {
					this.loading = false
				})
		},
		loadPaidOrders() {
			orderApi.getOrders({ limit: 50 })
				.then((res) => {
					const rows = Array.isArray(res && res.data) ? res.data : []
					const invoicedOrderIds = new Set(this.invoices.map(i => Number(i.order_id)))
					this.paidOrders = rows.filter(o => !['pending', 'pending_payment', 'canceled', 'closed'].includes(o.status) && !invoicedOrderIds.has(Number(o.id)))
					if (!this.applyForm.order_id && this.paidOrders.length) {
						this.applyForm.order_id = this.paidOrders[0].id
					}
				})
				.catch(() => {})
		},
		openApplyModal() {
			const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
			const def = this.headers.find(h => h.is_default) || this.headers[0]
			this.applyForm = {
				order_id: '',
				invoice_kind: def ? def.invoice_kind : 'ordinary',
				type: def ? def.type : (customer.company_name ? 'company' : 'personal'),
				title: def ? def.title : (customer.company_name || customer.name || ''),
				tax_no: def ? (def.tax_no || '') : '',
				bank_name: def ? (def.bank_name || '') : '',
				bank_account: def ? (def.bank_account || '') : '',
				company_address: def ? (def.company_address || '') : '',
				company_phone: def ? (def.company_phone || '') : '',
				receive_email: (def && def.receive_email) || customer.email || '',
				save_header: !def,
			}
			this.loadPaidOrders()
			this.showApplyModal = true
		},
		setApplyKind(kind) {
			this.applyForm.invoice_kind = kind
			if (kind === 'special') this.applyForm.type = 'company'
		},
		onPickOrder(e) {
			const idx = Number(e.detail.value)
			if (this.paidOrders[idx]) this.applyForm.order_id = this.paidOrders[idx].id
		},
		onPickSavedHeader(e) {
			const h = this.headers[Number(e.detail.value)]
			if (!h) return
			this.fillFromHeader(h)
		},
		useHeaderForApply(h) {
			this.openApplyModal()
			this.fillFromHeader(h)
		},
		fillFromHeader(h) {
			this.applyForm.invoice_kind = h.invoice_kind || 'ordinary'
			this.applyForm.type = h.type || 'company'
			this.applyForm.title = h.title || ''
			this.applyForm.tax_no = h.tax_no || ''
			this.applyForm.bank_name = h.bank_name || ''
			this.applyForm.bank_account = h.bank_account || ''
			this.applyForm.company_address = h.company_address || ''
			this.applyForm.company_phone = h.company_phone || ''
			if (h.receive_email) this.applyForm.receive_email = h.receive_email
			this.applyForm.save_header = false
		},
		submitApply() {
			if (this.submitting) return
			const f = this.applyForm
			if (!f.order_id) {
				uni.showToast({ title: '请选择已支付订单', icon: 'none' })
				return
			}
			if (!String(f.title || '').trim()) {
				uni.showToast({ title: '请填写发票抬头', icon: 'none' })
				return
			}
			if (f.type === 'company' && !/^[A-Z0-9]{15,20}$/.test(String(f.tax_no || '').trim())) {
				uni.showToast({ title: '企业税号须为 15~20 位大写字母或数字', icon: 'none' })
				return
			}
			if (f.invoice_kind === 'special') {
				if (!f.bank_name.trim() || !f.bank_account.trim() || !f.company_address.trim() || !f.company_phone.trim()) {
					uni.showToast({ title: '增值税专票请完整填写开户行、账号与注册地址电话', icon: 'none' })
					return
				}
			}
			this.submitting = true
			invoiceApi.apply(f)
				.then(() => {
					this.showApplyModal = false
					uni.showToast({ title: '开票登记已提交', icon: 'success' })
					this.loadAll()
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '提交失败', icon: 'none' })
				})
				.finally(() => {
					this.submitting = false
				})
		},
		openHeaderModal(item = null) {
			this.editingHeaderId = item ? item.id : null
			this.headerForm = item ? {
				invoice_kind: item.invoice_kind || 'ordinary',
				type: item.type || 'company',
				title: item.title || '',
				tax_no: item.tax_no || '',
				bank_name: item.bank_name || '',
				bank_account: item.bank_account || '',
				company_address: item.company_address || '',
				company_phone: item.company_phone || '',
				receive_email: item.receive_email || '',
				is_default: !!item.is_default,
			} : {
				invoice_kind: 'ordinary',
				type: 'company',
				title: '',
				tax_no: '',
				bank_name: '',
				bank_account: '',
				company_address: '',
				company_phone: '',
				receive_email: '',
				is_default: this.headers.length === 0,
			}
			this.showHeaderModal = true
		},
		submitHeader() {
			if (this.savingHeader) return
			const f = this.headerForm
			if (!String(f.title || '').trim()) {
				uni.showToast({ title: '请填写抬头名称', icon: 'none' })
				return
			}
			if (f.type === 'company' && !/^[A-Z0-9]{15,20}$/.test(String(f.tax_no || '').trim())) {
				uni.showToast({ title: '企业税号须为 15~20 位大写字母或数字', icon: 'none' })
				return
			}
			this.savingHeader = true
			const req = this.editingHeaderId
				? invoiceApi.updateHeader(this.editingHeaderId, f)
				: invoiceApi.createHeader(f)
			req.then(() => {
				this.showHeaderModal = false
				uni.showToast({ title: '抬头已保存', icon: 'success' })
				this.loadAll()
			})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '保存失败', icon: 'none' })
				})
				.finally(() => {
					this.savingHeader = false
				})
		},
		removeHeader(h) {
			uni.showModal({
				title: '删除发票抬头',
				content: `确认删除抬头「${h.title}」吗？`,
				confirmColor: '#DC2626',
				success: (res) => {
					if (!res.confirm) return
					invoiceApi.deleteHeader(h.id)
						.then(() => {
							uni.showToast({ title: '已删除', icon: 'success' })
							this.loadAll()
						})
						.catch((err) => {
							uni.showToast({ title: (err && err.message) || '删除失败', icon: 'none' })
						})
				},
			})
		},
	},
}
</script>

<style scoped>
.invoice-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.nav-action { font-size: 27rpx; font-weight: 600; color: #165DFF; }
.stats-banner { margin: 20rpx 28rpx 0; background: linear-gradient(135deg, #102F6E 0%, #174299 100%); border-radius: 22rpx; padding: 28rpx 16rpx; display: flex; align-items: center; color: #FFFFFF; }
.stat-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6rpx; }
.stat-num { font-size: 36rpx; font-weight: 700; color: #FFFFFF; }
.stat-label { font-size: 22rpx; color: rgba(255, 255, 255, 0.78); }
.stat-divider { width: 1rpx; height: 48rpx; background: rgba(255, 255, 255, 0.18); }
.tabs-bar { display: flex; margin: 20rpx 28rpx 0; background: #E9EEF6; border-radius: 16rpx; padding: 6rpx; }
.tab-item { flex: 1; height: 68rpx; display: flex; align-items: center; justify-content: center; font-size: 27rpx; font-weight: 600; color: #475569; border-radius: 12rpx; }
.tab-item.active { background: #FFFFFF; color: #165DFF; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06); }
.honest-bar { margin: 18rpx 28rpx 0; padding: 16rpx 22rpx; background: #EEF4FF; border: 1rpx solid #D6E4FF; border-radius: 16rpx; display: flex; align-items: flex-start; gap: 12rpx; }
.honest-text { flex: 1; font-size: 23rpx; color: #1E40AF; line-height: 1.5; }
.list-scroll { flex: 1; padding: 20rpx 28rpx; box-sizing: border-box; }
.inv-card { background: #FFFFFF; border-radius: 22rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.inv-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14rpx; }
.inv-tags { display: flex; align-items: center; gap: 12rpx; }
.kind-tag { font-size: 22rpx; font-weight: 600; padding: 4rpx 14rpx; border-radius: 8rpx; }
.kind-tag.ordinary { background: #EEF4FF; color: #165DFF; }
.kind-tag.special { background: #FEF3C7; color: #B45309; }
.type-tag { font-size: 22rpx; color: #475569; background: #F1F5F9; padding: 4rpx 14rpx; border-radius: 8rpx; }
.default-pill { font-size: 21rpx; font-weight: 600; color: #059669; background: #ECFDF5; padding: 4rpx 12rpx; border-radius: 8rpx; }
.status-pill { font-size: 23rpx; font-weight: 600; }
.status-pill.pending { color: #D97706; }
.status-pill.done { color: #059669; }
.inv-title { display: block; font-size: 31rpx; font-weight: 600; color: #0F172A; margin-bottom: 12rpx; }
.inv-meta { display: flex; flex-direction: column; gap: 6rpx; padding-bottom: 18rpx; border-bottom: 1rpx solid #F1F5F9; }
.meta-line { font-size: 25rpx; color: #475569; }
.inv-foot { display: flex; align-items: center; justify-content: space-between; padding-top: 16rpx; }
.inv-time { font-size: 23rpx; color: #94A3B8; }
.inv-link { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.header-actions { display: flex; justify-content: flex-end; gap: 28rpx; padding-top: 18rpx; }
.act-btn { font-size: 26rpx; color: #475569; }
.act-btn.primary { color: #165DFF; font-weight: 600; }
.act-btn.danger { color: #DC2626; }
.bottom-bar { margin-top: 16rpx; }
.btn-primary { height: 88rpx; border-radius: 18rpx; background: #165DFF; color: #FFFFFF; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; flex: 2; }
.btn-primary.disabled { opacity: 0.6; }
.btn-secondary { height: 88rpx; border-radius: 18rpx; background: #F1F5F9; color: #475569; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; flex: 1; }
.modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 100; display: flex; align-items: flex-end; }
.modal-sheet { width: 100%; max-height: 86vh; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; display: flex; flex-direction: column; padding-bottom: env(safe-area-inset-bottom); }
.sheet-head { display: flex; align-items: center; justify-content: space-between; padding: 30rpx 32rpx 22rpx; border-bottom: 1rpx solid #EEF2F7; }
.sheet-title { font-size: 32rpx; font-weight: 600; color: #0F172A; }
.sheet-close { padding: 8rpx; }
.sheet-body { padding: 24rpx 32rpx; max-height: 62vh; box-sizing: border-box; }
.sheet-foot { display: flex; gap: 20rpx; padding: 20rpx 32rpx 28rpx; border-top: 1rpx solid #EEF2F7; }
.field-item { margin-bottom: 22rpx; }
.field-label { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.field-label.required::after { content: ' *'; color: #DC2626; }
.field-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; }
.picker-box { height: 82rpx; padding: 0 22rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; display: flex; align-items: center; justify-content: space-between; font-size: 27rpx; color: #0F172A; }
.placeholder { color: #94A3B8; }
.picker-arrow { font-size: 34rpx; color: #94A3B8; }
.empty-inline { padding: 20rpx 22rpx; background: #F8FAFC; border-radius: 16rpx; font-size: 25rpx; color: #64748B; }
.seg-row { display: flex; gap: 16rpx; }
.seg-btn { flex: 1; height: 74rpx; border-radius: 14rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-size: 26rpx; font-weight: 500; color: #475569; }
.seg-btn.active { background: #EEF4FF; border-color: #165DFF; color: #165DFF; font-weight: 600; }
.quick-header-bar { margin-bottom: 20rpx; }
.quick-header-link { font-size: 25rpx; color: #165DFF; font-weight: 600; }
.save-check-row { display: flex; align-items: center; gap: 14rpx; padding: 12rpx 0 8rpx; }
.mini-box { width: 36rpx; height: 36rpx; border-radius: 8rpx; border: 2rpx solid #CBD5E1; display: flex; align-items: center; justify-content: center; font-size: 24rpx; color: #FFFFFF; }
.mini-box.checked { background: #165DFF; border-color: #165DFF; }
.save-check-text { font-size: 26rpx; color: #334155; }
.detail-list { padding: 20rpx 32rpx 40rpx; }
.detail-row { display: flex; justify-content: space-between; gap: 24rpx; padding: 18rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.dk { font-size: 26rpx; color: #64748B; flex-shrink: 0; }
.dv { font-size: 26rpx; color: #0F172A; text-align: right; word-break: break-all; }
</style>
