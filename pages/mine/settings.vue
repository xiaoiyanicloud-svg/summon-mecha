<template>
	<view class="settings-page">
		<nav-bar title="账户与安全设置" />

		<scroll-view class="page-scroll" scroll-y>
			<view class="group-card">
				<text class="group-title">账户与业务资料</text>
				<view class="cell" @tap="goPage('/pages/mine/profile', true)">
					<view class="cell-left">
						<SmIcon name="user" :size="36" />
						<text class="cell-label">个人资料与企业名片</text>
					</view>
					<view class="cell-right">
						<text class="cell-val">{{ customerName || '去完善' }}</text>
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
				<view class="cell" @tap="goPage('/pages/mine/profile', true)">
					<view class="cell-left">
						<SmIcon name="lock" :size="36" />
						<text class="cell-label">修改登录密码</text>
					</view>
					<view class="cell-right">
						<text class="cell-val">全端工作台通用</text>
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
				<view class="cell" @tap="goPage('/pages/mine/address', false)">
					<view class="cell-left">
						<SmIcon name="location" :size="36" />
						<text class="cell-label">收货与现场交付地址</text>
					</view>
					<view class="cell-right">
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
				<view class="cell last" @tap="goPage('/pages/mine/invoices', true)">
					<view class="cell-left">
						<SmIcon name="invoice" :size="36" />
						<text class="cell-label">常用发票抬头簿</text>
					</view>
					<view class="cell-right">
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
			</view>

			<view class="group-card">
				<text class="group-title">合规条款与关于平台</text>
				<view class="cell" @tap="goPage('/pages/mine/legal?slug=terms-of-use', false)">
					<view class="cell-left">
						<SmIcon name="doc-text" :size="36" />
						<text class="cell-label">用户服务协议</text>
					</view>
					<view class="cell-right"><SmIcon name="chevron-right" :size="24" /></view>
				</view>
				<view class="cell" @tap="goPage('/pages/mine/legal?slug=privacy-policy', false)">
					<view class="cell-left">
						<SmIcon name="shield-lock" :size="36" />
						<text class="cell-label">隐私保护政策</text>
					</view>
					<view class="cell-right"><SmIcon name="chevron-right" :size="24" /></view>
				</view>
				<view class="cell" @tap="clearLocalCache">
					<view class="cell-left">
						<SmIcon name="settings" :size="36" />
						<text class="cell-label">清理本机浏览缓存</text>
					</view>
					<view class="cell-right">
						<text class="cell-val">保留登录凭证</text>
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
				<view class="cell last" @tap="goPage('/pages/mine/about', false)">
					<view class="cell-left">
						<SmIcon name="info" :size="36" />
						<text class="cell-label">关于召唤机甲</text>
					</view>
					<view class="cell-right">
						<text class="cell-val sm-mono">{{ appVersion }}</text>
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
			</view>

			<view v-if="isLoggedIn" class="group-card">
				<text class="group-title">隐私与账号注销（PIPL 合规）</text>
				<view class="cell last" @tap="showDeleteModal = true">
					<view class="cell-left">
						<SmIcon name="state-warn" :size="36" />
						<text class="cell-label">申请注销账号</text>
					</view>
					<view class="cell-right">
						<text class="cell-val">{{ deletionRequestedAt ? '已提交申请' : '人工复核注销' }}</text>
						<SmIcon name="chevron-right" :size="24" />
					</view>
				</view>
			</view>

			<view v-if="isLoggedIn" class="logout-btn" @tap="confirmLogout">
				<text class="logout-text">退出当前账号</text>
			</view>

			<view class="version-foot sm-mono">Summon Mecha · {{ appVersion }}</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>

		<!-- 注销申请弹窗 -->
		<view v-if="showDeleteModal" class="modal-mask" @tap="showDeleteModal = false">
			<view class="modal-sheet" @tap.stop>
				<view class="sheet-head">
					<text class="sheet-title">申请注销召唤机甲账号</text>
					<view class="sheet-close" @tap="showDeleteModal = false"><SmIcon name="close" :size="26" /></view>
				</view>
				<view class="sheet-body">
					<view class="warn-box">
						<text class="warn-text">注销须知：若名下存在未完结租售订单、在租机器或待处理售后，系统将依法阻断注销。提交后进入 7 个工作日冷静复核期，核准后买断技能授权与设备配对记录将不可恢复。</text>
					</view>
					<view class="field-item">
						<text class="field-label required">验证当前登录密码</text>
						<input class="field-input" type="password" v-model="delForm.password" placeholder="请输入当前账号密码以确认本人操作" />
					</view>
					<view class="field-item">
						<text class="field-label">注销原因（选填）</text>
						<input class="field-input" type="text" v-model="delForm.reason" placeholder="如：更换企业采购主体账号" maxlength="200" />
					</view>
				</view>
				<view class="sheet-foot">
					<view class="btn-cancel" @tap="showDeleteModal = false">暂不注销</view>
					<view class="btn-danger" :class="{ disabled: submittingDel }" @tap="submitDeletion">
						{{ submittingDel ? '提交中...' : '确认提交申请' }}
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import SmIcon from '@/components/SmIcon.vue'
import { authApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY, APP_VERSION } from '@/utils/config'

export default {
	components: { NavBar, SmIcon },
	data() {
		return {
			appVersion: APP_VERSION,
			isLoggedIn: false,
			customerName: '',
			deletionRequestedAt: '',
			showDeleteModal: false,
			submittingDel: false,
			delForm: { password: '', reason: '' },
		}
	},
	onShow() {
		this.isLoggedIn = !!uni.getStorageSync(TOKEN_KEY)
		const c = uni.getStorageSync(CUSTOMER_KEY) || {}
		this.customerName = c.name || ''
		this.deletionRequestedAt = c.deletion_requested_at || ''
	},
	methods: {
		goPage(url, needAuth = false) {
			if (needAuth && !this.isLoggedIn) {
				uni.showToast({ title: '请先返回登录账户', icon: 'none' })
				setTimeout(() => uni.switchTab({ url: '/pages/mine/index' }), 400)
				return
			}
			uni.navigateTo({ url })
		},
		clearLocalCache() {
			const token = uni.getStorageSync(TOKEN_KEY)
			const customer = uni.getStorageSync(CUSTOMER_KEY)
			const guestKey = uni.getStorageSync('guest_key')
			uni.clearStorageSync()
			if (token) uni.setStorageSync(TOKEN_KEY, token)
			if (customer) uni.setStorageSync(CUSTOMER_KEY, customer)
			if (guestKey) uni.setStorageSync('guest_key', guestKey)
			uni.showToast({ title: '缓存已清理', icon: 'success' })
		},
		submitDeletion() {
			if (this.submittingDel) return
			if (!this.delForm.password) {
				uni.showToast({ title: '请输入当前登录密码确认身份', icon: 'none' })
				return
			}
			this.submittingDel = true
			authApi.deletionRequest(this.delForm)
				.then((res) => {
					const d = (res && res.data) || {}
					this.deletionRequestedAt = d.deletion_requested_at || 'submitted'
					const c = uni.getStorageSync(CUSTOMER_KEY) || {}
					uni.setStorageSync(CUSTOMER_KEY, { ...c, deletion_requested_at: this.deletionRequestedAt })
					this.showDeleteModal = false
					this.delForm = { password: '', reason: '' }
					uni.showToast({ title: (res && res.message) || '注销申请已登记', icon: 'none' })
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '提交失败', icon: 'none' })
				})
				.finally(() => {
					this.submittingDel = false
				})
		},
		confirmLogout() {
			uni.showModal({
				title: '退出登录',
				content: '确认退出当前召唤机甲账户吗？',
				confirmColor: '#DC2626',
				success: (res) => {
					if (!res.confirm) return
					authApi.logout().catch(() => {}).finally(() => {
						uni.removeStorageSync(TOKEN_KEY)
						uni.removeStorageSync(CUSTOMER_KEY)
						uni.removeStorageSync('orderStatus')
						uni.removeStorageSync('addressList')
						this.isLoggedIn = false
						this.customerName = ''
						uni.showToast({ title: '已安全退出', icon: 'success' })
						setTimeout(() => uni.switchTab({ url: '/pages/mine/index' }), 350)
					})
				},
			})
		},
	},
}
</script>

<style scoped>
.settings-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.page-scroll { flex: 1; padding: 24rpx 28rpx; box-sizing: border-box; }
.group-card { background: #FFFFFF; border-radius: 24rpx; padding: 12rpx 28rpx; margin-bottom: 22rpx; border: 1rpx solid #E5EAF2; }
.group-title { display: block; font-size: 23rpx; font-weight: 600; color: #64748B; padding: 18rpx 0 8rpx; }
.cell { display: flex; align-items: center; justify-content: space-between; padding: 26rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.cell.last { border-bottom: none; }
.cell-left { display: flex; align-items: center; gap: 18rpx; }
.cell-label { font-size: 29rpx; color: #0F172A; font-weight: 500; }
.cell-right { display: flex; align-items: center; gap: 12rpx; }
.cell-val { font-size: 25rpx; color: #64748B; }
.logout-btn { height: 92rpx; border-radius: 20rpx; background: #FFFFFF; border: 1rpx solid #FECACA; display: flex; align-items: center; justify-content: center; margin-top: 12rpx; }
.logout-text { font-size: 29rpx; font-weight: 600; color: #DC2626; }
.version-foot { text-align: center; font-size: 23rpx; color: #94A3B8; margin-top: 32rpx; }
.modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); z-index: 100; display: flex; align-items: flex-end; }
.modal-sheet { width: 100%; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; padding-bottom: env(safe-area-inset-bottom); }
.sheet-head { display: flex; align-items: center; justify-content: space-between; padding: 30rpx 32rpx 22rpx; border-bottom: 1rpx solid #EEF2F7; }
.sheet-title { font-size: 32rpx; font-weight: 600; color: #0F172A; }
.sheet-close { padding: 8rpx; }
.sheet-body { padding: 24rpx 32rpx; }
.warn-box { padding: 20rpx 22rpx; border-radius: 16rpx; background: #FEF2F2; border: 1rpx solid #FECACA; margin-bottom: 24rpx; }
.warn-text { font-size: 24rpx; color: #991B1B; line-height: 1.55; }
.field-item { margin-bottom: 22rpx; }
.field-label { display: block; font-size: 25rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.field-label.required::after { content: ' *'; color: #DC2626; }
.field-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; }
.sheet-foot { display: flex; gap: 20rpx; padding: 16rpx 32rpx 28rpx; }
.btn-cancel { flex: 1; height: 86rpx; border-radius: 18rpx; background: #F1F5F9; color: #475569; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-danger { flex: 2; height: 86rpx; border-radius: 18rpx; background: #DC2626; color: #FFFFFF; font-size: 28rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-danger.disabled { opacity: 0.6; }
</style>
