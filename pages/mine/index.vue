<template>
	<view class="mine-page">
		<!-- 第 1 层：身份与消息顶栏（清爽天空科技蓝） -->
		<view class="hero-header" :style="headerSafeStyle">
			<view class="top-utility-row">
				<view class="top-actions">
					<view class="icon-btn" @tap="goPage('/pages/mine/notifications', true)">
						<SmIcon name="top-notify" :size="38" />
						<text v-if="counts.unread_notifications > 0" class="dot-badge sm-mono">
							{{ counts.unread_notifications > 99 ? '99+' : counts.unread_notifications }}
						</text>
					</view>
					<view class="icon-btn" @tap="goPage('/pages/mine/settings', false)">
						<SmIcon name="top-settings" :size="38" />
					</view>
				</view>
			</view>

			<view class="profile-row" @tap="onTapProfile">
				<image class="avatar" src="/static/default-avatar.png" mode="aspectFill" />
				<view class="profile-main">
					<view class="name-line">
						<text class="user-name">{{ isLoggedIn ? (customer.name || '召唤机甲用户') : '登录 / 注册账户' }}</text>
						<text v-if="isLoggedIn" class="edit-pill">编辑资料 ›</text>
					</view>
					<text v-if="userSubText" class="user-sub">{{ userSubText }}</text>
					<view v-if="isLoggedIn && identities.length" class="identity-badges">
						<text v-for="idItem in identities" :key="idItem.key" class="id-chip">{{ idItem.label }}</text>
					</view>
				</view>
			</view>
		</view>

		<!-- 第 2 层：核心资产驾驶舱 -->
		<view class="cockpit-card">
			<view class="cockpit-item" @tap="goPage('/pages/mine/devices', true)">
				<text class="cp-num sm-mono">{{ isLoggedIn ? counts.devices_total : '—' }}</text>
				<text class="cp-label">我的设备</text>
			</view>
			<view class="cp-divider" />
			<view class="cockpit-item" @tap="goPage('/pages/mine/skills', true)">
				<text class="cp-num sm-mono">{{ isLoggedIn ? counts.active_skills : '—' }}</text>
				<text class="cp-label">我的技能</text>
			</view>
			<view class="cp-divider" />
			<view class="cockpit-item" @tap="goPage('/pages/mine/deploy', true)">
				<text class="cp-num sm-mono">{{ isLoggedIn ? counts.running_deploy_tasks : '—' }}</text>
				<text class="cp-label">下发记录</text>
			</view>
			<view class="cp-divider" />
			<view class="cockpit-item" @tap="goPage('/pages/mine/invoices', true)">
				<text class="cp-num sm-mono">{{ isLoggedIn ? counts.invoices_total : '—' }}</text>
				<text class="cp-label">发票</text>
			</view>
		</view>

		<view class="body-wrap">
			<!-- 第 3 层：订单履约与售后 -->
			<view class="panel-card">
				<view class="panel-head">
					<text class="panel-title">我的订单</text>
					<view class="panel-more" @tap="goOrderTab('all')">
						<text class="more-text">全部订单</text>
						<SmIcon name="chevron-right" :size="22" />
					</view>
				</view>
				<view class="order-row">
					<view class="order-cell" @tap="goOrderTab('pending')">
						<view class="order-icon-wrap">
							<SmIcon name="order-pending" :size="46" />
							<text v-if="orderCounts.pending > 0" class="cell-badge sm-mono">{{ orderCounts.pending }}</text>
						</view>
						<text class="order-label">待付款</text>
					</view>
					<view class="order-cell" @tap="goOrderTab('processing')">
						<view class="order-icon-wrap">
							<SmIcon name="order-shipping" :size="46" />
							<text v-if="orderCounts.processing > 0" class="cell-badge sm-mono">{{ orderCounts.processing }}</text>
						</view>
						<text class="order-label">履约中</text>
					</view>
					<view class="order-cell" @tap="goOrderTab('completed')">
						<view class="order-icon-wrap">
							<SmIcon name="order-done" :size="46" />
						</view>
						<text class="order-label">已完成</text>
					</view>
					<view class="order-cell" @tap="goPage('/pages/mine/after-sales', true)">
						<view class="order-icon-wrap">
							<SmIcon name="after-sales" :size="46" />
							<text v-if="counts.open_after_sales > 0" class="cell-badge warn sm-mono">{{ counts.open_after_sales }}</text>
						</view>
						<text class="order-label">售后</text>
					</view>
					<view class="order-cell" @tap="goPage('/pages/scene/requests', false)">
						<view class="order-icon-wrap">
							<SmIcon name="custom" :size="46" />
							<text v-if="counts.scene_requests > 0" class="cell-badge subtle sm-mono">{{ counts.scene_requests }}</text>
						</view>
						<text class="order-label">场景定制</text>
					</view>
				</view>
			</view>

			<!-- 第 4 层：常用服务宫格 -->
			<view class="panel-card">
				<view class="panel-head">
					<text class="panel-title">常用服务</text>
				</view>
				<view class="tool-grid">
					<view class="tool-item" @tap="goPage('/pages/mine/address', false)">
						<view class="tool-icon"><SmIcon name="location" :size="46" /></view>
						<text class="tool-label">收货地址</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/ecosystem/my-favorites?tab=product', false)">
						<view class="tool-icon"><SmIcon name="favorite" :size="46" /></view>
						<text class="tool-label">我的收藏</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/ecosystem/my-registrations', true)">
						<view class="tool-icon"><SmIcon name="event" :size="46" /></view>
						<text class="tool-label">我的赛事</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/mine/tickets', true)">
						<view class="tool-icon">
							<SmIcon name="service" :size="46" />
							<text v-if="counts.open_tickets > 0" class="cell-badge sm-mono">{{ counts.open_tickets }}</text>
						</view>
						<text class="tool-label">客服工单</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/mine/incidents', true)">
						<view class="tool-icon"><SmIcon name="shield-lock" :size="46" /></view>
						<text class="tool-label">事故上报</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/mine/notifications', true)">
						<view class="tool-icon">
							<SmIcon name="notify" :size="46" />
							<text v-if="counts.unread_notifications > 0" class="cell-badge sm-mono">{{ counts.unread_notifications }}</text>
						</view>
						<text class="tool-label">消息通知</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/mine/legal?slug=terms-of-use', false)">
						<view class="tool-icon"><SmIcon name="doc-text" :size="46" /></view>
						<text class="tool-label">服务条款</text>
					</view>
					<view class="tool-item" @tap="goPage('/pages/mine/settings', false)">
						<view class="tool-icon"><SmIcon name="settings" :size="46" /></view>
						<text class="tool-label">账户设置</text>
					</view>
				</view>
			</view>

			<!-- 第 5 层：企业服务 -->
			<view class="panel-card">
				<view class="panel-head">
					<text class="panel-title">企业服务</text>
				</view>

				<view class="tob-main-card" @tap="goPage('/pages/mine/portal-hub', true)">
					<view class="tob-main-left">
						<view class="tob-main-icon"><SmIcon name="hub-portal" :size="44" /></view>
						<view class="tob-main-info">
							<view class="tob-title-line">
								<text class="tob-main-title">商家/开发者工作台</text>
								<text v-if="hasBusinessRole" class="tob-active-tag">已开通</text>
							</view>
						</view>
					</view>
					<view class="tob-enter-pill">
						<text class="tob-enter-text">工作台</text>
						<text class="tob-enter-arrow">›</text>
					</view>
				</view>

				<view class="tob-sub-grid">
					<view class="tob-sub-card" @tap="goPage('/pages/mine/supplier', false)">
						<view class="tob-sub-icon"><SmIcon name="hub-supplier" :size="38" /></view>
						<view class="tob-sub-info">
							<text class="tob-sub-title">供应商入驻</text>
						</view>
					</view>
					<view class="tob-sub-card" @tap="goPage('/pages/mine/cooperate', false)">
						<view class="tob-sub-icon"><SmIcon name="hub-coop" :size="38" /></view>
						<view class="tob-sub-info">
							<text class="tob-sub-title">商务合作</text>
						</view>
					</view>
				</view>
			</view>

			<view style="height: 150rpx;"></view>
		</view>

		<!-- 底部主导航 -->
		<CustomTabBar currentPage="/pages/mine/index" />

		<!-- 登录 / 注册 / 找回密码弹窗 -->
		<view v-if="showAuthModal" class="auth-mask" @tap="showAuthModal = false">
			<view class="auth-sheet" @tap.stop>
				<view class="auth-head">
					<view>
						<text class="auth-title">
							{{ authMode === 'login' ? '登录召唤机甲账户' : (authMode === 'register' ? '注册新账户' : '找回登录密码') }}
						</text>
					</view>
					<view class="auth-close" @tap="showAuthModal = false"><SmIcon name="close" :size="26" /></view>
				</view>

				<view v-if="authMode !== 'forgot'" class="mode-switch">
					<view class="mode-btn" :class="{ active: authMode === 'login' }" @tap="switchMode('login')">邮箱密码登录</view>
					<view class="mode-btn" :class="{ active: authMode === 'register' }" @tap="switchMode('register')">新用户注册</view>
				</view>

				<!-- 登录表单 -->
				<view v-if="authMode === 'login'" class="auth-body">
					<view class="input-box">
						<SmIcon name="mail" :size="32" />
						<input class="auth-input" type="text" v-model="loginForm.email" placeholder="请输入注册邮箱" />
					</view>
					<view class="input-box">
						<SmIcon name="lock" :size="32" />
						<input class="auth-input" type="password" v-model="loginForm.password" placeholder="请输入登录密码" />
					</view>
					<view class="forgot-row">
						<text class="forgot-link" @tap="switchMode('forgot')">忘记密码？</text>
					</view>
				</view>

				<!-- 注册表单 -->
				<view v-else-if="authMode === 'register'" class="auth-body">
					<view class="input-box">
						<SmIcon name="user" :size="32" />
						<input class="auth-input" type="text" v-model="regForm.name" placeholder="真实姓名或企业联系人" maxlength="50" />
					</view>
					<view class="input-box">
						<SmIcon name="mail" :size="32" />
						<input class="auth-input" type="text" v-model="regForm.email" placeholder="常用邮箱（作为登录账号）" />
					</view>
					<view class="input-box">
						<SmIcon name="building" :size="32" />
						<input class="auth-input" type="text" v-model="regForm.company_name" placeholder="所属企业/机构（选填）" maxlength="120" />
					</view>
					<view class="input-box">
						<SmIcon name="lock" :size="32" />
						<input class="auth-input" type="password" v-model="regForm.password" placeholder="设置登录密码（至少 6 位）" />
					</view>
					<view class="input-box">
						<SmIcon name="lock" :size="32" />
						<input class="auth-input" type="password" v-model="regForm.confirmPassword" placeholder="再次确认密码" />
					</view>
				</view>

				<!-- 找回密码表单 -->
				<view v-else class="auth-body">
					<view class="code-row">
						<view class="input-box flex-1">
							<SmIcon name="mail" :size="32" />
							<input class="auth-input" type="text" v-model="forgotForm.email" placeholder="请输入注册邮箱" />
						</view>
						<view class="send-code-btn" :class="{ disabled: codeCooldown > 0 }" @tap="sendResetCode">
							{{ codeCooldown > 0 ? `${codeCooldown}s` : '获取验证码' }}
						</view>
					</view>
					<view class="input-box">
						<SmIcon name="key" :size="32" />
						<input class="auth-input sm-mono" type="number" v-model="forgotForm.code" placeholder="6 位邮箱重置验证码" maxlength="6" />
					</view>
					<view class="input-box">
						<SmIcon name="lock" :size="32" />
						<input class="auth-input" type="password" v-model="forgotForm.password" placeholder="设置新密码（至少 6 位）" />
					</view>
					<view class="input-box">
						<SmIcon name="lock" :size="32" />
						<input class="auth-input" type="password" v-model="forgotForm.password_confirmation" placeholder="再次确认新密码" />
					</view>
					<view class="forgot-row">
						<text class="forgot-link" @tap="switchMode('login')">‹ 返回密码登录</text>
					</view>
				</view>

				<!-- 协议勾选 -->
				<view v-if="authMode !== 'forgot'" class="agree-row" @tap="agreedPolicy = !agreedPolicy">
					<view class="agree-box" :class="{ checked: agreedPolicy }">{{ agreedPolicy ? '✓' : '' }}</view>
					<text class="agree-text">
						我已阅读并同意
						<text class="policy-link" @tap.stop="goPage('/pages/mine/legal?slug=terms-of-use', false)">《用户服务协议》</text>
						与
						<text class="policy-link" @tap.stop="goPage('/pages/mine/legal?slug=privacy-policy', false)">《隐私政策》</text>
					</text>
				</view>

				<view class="auth-submit-btn" @tap="handleAuthSubmit">
					{{ authMode === 'login' ? '立即登录' : (authMode === 'register' ? '同意协议并注册' : '确认重置密码') }}
				</view>
			</view>
		</view>
	</view>
</template>

<script>
import CustomTabBar from '@/components/CustomTabBar.vue'
import SmIcon from '@/components/SmIcon.vue'
import { authApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config'
import { splitName } from '@/utils/address'

const EMPTY_COUNTS = () => ({
	devices_total: 0,
	devices_online: 0,
	active_skills: 0,
	running_deploy_tasks: 0,
	invoices_total: 0,
	unread_notifications: 0,
	open_after_sales: 0,
	open_tickets: 0,
	scene_requests: 0,
	order_counts: { all: 0, pending: 0, processing: 0, completed: 0, canceled: 0 },
})

export default {
	components: { CustomTabBar, SmIcon },
	data() {
		let statusBarPx = 0
		try {
			const sys = uni.getSystemInfoSync && uni.getSystemInfoSync()
			if (sys && Number(sys.statusBarHeight) > 0) statusBarPx = Number(sys.statusBarHeight)
		} catch (_) {}
		return {
			statusBarPx,
			isLoggedIn: false,
			customer: {},
			identities: [],
			counts: EMPTY_COUNTS(),
			pendingJumpUrl: '',
			showAuthModal: false,
			authMode: 'login',
			agreedPolicy: false,
			codeCooldown: 0,
			cooldownTimer: null,
			loginForm: { email: '', password: '' },
			regForm: { name: '', email: '', company_name: '', password: '', confirmPassword: '' },
			forgotForm: { email: '', code: '', password: '', password_confirmation: '' },
		}
	},
	computed: {
		headerSafeStyle() {
			if (this.statusBarPx > 0) {
				return { paddingTop: `${this.statusBarPx + 12}px` }
			}
			return {}
		},
		maskedPhone() {
			const p = String((this.customer && this.customer.phone) || '').trim()
			if (!p) return ''
			return p.replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2')
		},
		orderCounts() {
			return (this.counts && this.counts.order_counts) || { pending: 0, processing: 0, completed: 0 }
		},
		hasBusinessRole() {
			return this.identities.some(i => i.key === 'merchant' || i.key === 'developer' || i.key === 'supplier')
		},
		userSubText() {
			if (!this.isLoggedIn) return '登录后查看订单与设备'
			return this.customer.company_name || this.maskedPhone || ''
		},
	},
	onLoad(q) {
		if (q && q.page) {
			const map = {
				address: '/pages/mine/address',
				invoice: '/pages/mine/invoices',
				cooperate: '/pages/mine/cooperate',
				supplier: '/pages/mine/supplier',
				settings: '/pages/mine/settings',
			}
			if (map[q.page]) {
				setTimeout(() => uni.navigateTo({ url: map[q.page] }), 60)
			}
		}
	},
	onShow() {
		this.syncSessionState()
	},
	onUnload() {
		if (this.cooldownTimer) clearInterval(this.cooldownTimer)
	},
	methods: {
		syncSessionState() {
			const token = uni.getStorageSync(TOKEN_KEY)
			if (!token) {
				this.isLoggedIn = false
				this.customer = {}
				this.identities = []
				this.counts = EMPTY_COUNTS()
				return
			}
			this.isLoggedIn = true
			const cached = uni.getStorageSync(CUSTOMER_KEY) || {}
			this.customer = cached
			this.identities = Array.isArray(cached.identities) ? cached.identities : []
			this.fetchOverview()
		},
		fetchOverview() {
			authApi.overview()
				.then((res) => {
					const d = (res && res.data) || {}
					if (d.customer) {
						this.customer = { ...this.customer, ...d.customer }
						uni.setStorageSync(CUSTOMER_KEY, this.customer)
					}
					if (Array.isArray(d.identities)) {
						this.identities = d.identities
					}
					if (d.counts) {
						this.counts = { ...EMPTY_COUNTS(), ...d.counts }
					}
				})
				.catch((err) => {
					if (err && err.statusCode === 401) {
						this.isLoggedIn = false
						this.customer = {}
						this.identities = []
						this.counts = EMPTY_COUNTS()
					}
				})
		},
		onTapProfile() {
			if (this.isLoggedIn) {
				uni.navigateTo({ url: '/pages/mine/profile' })
			} else {
				this.pendingJumpUrl = ''
				this.authMode = 'login'
				this.showAuthModal = true
			}
		},
		goPage(url, requireAuth = false) {
			if (requireAuth && !this.isLoggedIn) {
				this.pendingJumpUrl = url
				this.authMode = 'login'
				this.showAuthModal = true
				return
			}
			uni.navigateTo({ url })
		},
		goOrderTab(status) {
			uni.setStorageSync('orderStatus', status)
			uni.switchTab({ url: '/pages/order/index' })
		},
		switchMode(mode) {
			this.authMode = mode
		},
		handleAuthSubmit() {
			if (this.authMode === 'forgot') {
				this.submitForgotReset()
				return
			}
			if (!this.agreedPolicy) {
				uni.showToast({ title: '请先阅读并勾选同意用户服务协议与隐私政策', icon: 'none' })
				return
			}
			if (this.authMode === 'login') this.submitLogin()
			else this.submitRegister()
		},
		submitLogin() {
			const email = String(this.loginForm.email || '').trim()
			const password = this.loginForm.password
			if (!email) { uni.showToast({ title: '请输入登录邮箱', icon: 'none' }); return }
			if (!password) { uni.showToast({ title: '请输入登录密码', icon: 'none' }); return }

			authApi.login(email, password)
				.then((res) => {
					const d = (res && res.data) || {}
					if (!d.token) {
						uni.showToast({ title: '登录失败：未获取到会话凭证', icon: 'none' })
						return
					}
					this.applyAuthSuccess(d.token, d.customer || { email })
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '登录失败', icon: 'none' })
				})
		},
		submitRegister() {
			const f = this.regForm
			const name = String(f.name || '').trim()
			const email = String(f.email || '').trim()
			if (!name) { uni.showToast({ title: '请填写姓名或联系人', icon: 'none' }); return }
			if (!/^[\w.-]+@[\w-]+(\.[\w-]+)+$/.test(email)) { uni.showToast({ title: '请输入有效的邮箱地址', icon: 'none' }); return }
			if (!f.password || f.password.length < 6) { uni.showToast({ title: '登录密码至少 6 位', icon: 'none' }); return }
			if (f.password !== f.confirmPassword) { uni.showToast({ title: '两次输入的密码不一致', icon: 'none' }); return }

			const [first_name, last_name] = splitName(name)
			const payload = {
				first_name,
				last_name,
				email,
				password: f.password,
			}
			if (String(f.company_name || '').trim()) {
				payload.company_name = String(f.company_name).trim()
			}
			authApi.register(payload)
				.then((res) => {
					const d = (res && res.data) || {}
					if (!d.token) {
						uni.showToast({ title: '注册成功，请使用邮箱登录', icon: 'none' })
						this.authMode = 'login'
						this.loginForm.email = email
						return
					}
					this.applyAuthSuccess(d.token, d.customer || { name, email })
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '注册失败', icon: 'none' })
				})
		},
		applyAuthSuccess(token, customer) {
			uni.setStorageSync(TOKEN_KEY, token)
			uni.setStorageSync(CUSTOMER_KEY, customer)
			this.showAuthModal = false
			this.loginForm = { email: '', password: '' }
			this.regForm = { name: '', email: '', company_name: '', password: '', confirmPassword: '' }
			this.syncSessionState()
			uni.showToast({ title: '欢迎回来', icon: 'success' })
			if (this.pendingJumpUrl) {
				const target = this.pendingJumpUrl
				this.pendingJumpUrl = ''
				setTimeout(() => uni.navigateTo({ url: target }), 250)
			}
		},
		sendResetCode() {
			if (this.codeCooldown > 0) return
			const email = String(this.forgotForm.email || '').trim()
			if (!/^[\w.-]+@[\w-]+(\.[\w-]+)+$/.test(email)) {
				uni.showToast({ title: '请先输入正确的注册邮箱', icon: 'none' })
				return
			}
			authApi.forgotPassword(email)
				.then((res) => {
					uni.showToast({ title: (res && res.message) || '若邮箱已注册，验证码已发出', icon: 'none' })
					this.codeCooldown = 60
					if (this.cooldownTimer) clearInterval(this.cooldownTimer)
					this.cooldownTimer = setInterval(() => {
						this.codeCooldown -= 1
						if (this.codeCooldown <= 0) {
							clearInterval(this.cooldownTimer)
							this.cooldownTimer = null
						}
					}, 1000)
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '发送失败', icon: 'none' })
				})
		},
		submitForgotReset() {
			const f = this.forgotForm
			if (!f.email.trim() || !f.code.trim()) {
				uni.showToast({ title: '请填写邮箱与 6 位验证码', icon: 'none' })
				return
			}
			if (!f.password || f.password.length < 6) {
				uni.showToast({ title: '新密码至少 6 位', icon: 'none' })
				return
			}
			if (f.password !== f.password_confirmation) {
				uni.showToast({ title: '两次输入的新密码不一致', icon: 'none' })
				return
			}
			authApi.resetPassword({
				email: f.email.trim(),
				code: f.code.trim(),
				password: f.password,
				password_confirmation: f.password_confirmation,
			})
				.then(() => {
					uni.showToast({ title: '密码已重置，请登录', icon: 'success' })
					this.loginForm.email = f.email.trim()
					this.forgotForm = { email: '', code: '', password: '', password_confirmation: '' }
					this.authMode = 'login'
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '重置密码失败', icon: 'none' })
				})
		},
	},
}
</script>

<style scoped>
.mine-page { min-height: 100vh; background: #F4F7FB; }
.hero-header {
	padding: calc(28rpx + env(safe-area-inset-top)) 32rpx 88rpx;
	background:
		radial-gradient(circle at 88% 12%, rgba(255, 255, 255, 0.24) 0%, rgba(255, 255, 255, 0) 48%),
		linear-gradient(155deg, #277BF6 0%, #459BFF 54%, #72BEFF 100%);
	color: #FFFFFF;
}
.top-utility-row { display: flex; align-items: center; justify-content: flex-end; margin-bottom: 16rpx; }
.top-actions { display: flex; align-items: center; gap: 18rpx; }
.icon-btn {
	width: 68rpx;
	height: 68rpx;
	border-radius: 50%;
	background: rgba(255, 255, 255, 0.2);
	border: 1rpx solid rgba(255, 255, 255, 0.34);
	display: flex;
	align-items: center;
	justify-content: center;
	position: relative;
}
.dot-badge {
	position: absolute;
	top: -6rpx;
	right: -6rpx;
	min-width: 32rpx;
	height: 32rpx;
	padding: 0 8rpx;
	border-radius: 999rpx;
	background: #EF4444;
	color: #FFFFFF;
	font-size: 20rpx;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
}
.profile-row { display: flex; align-items: center; gap: 24rpx; }
.avatar {
	width: 116rpx;
	height: 116rpx;
	border-radius: 50%;
	background: #FFFFFF;
	border: 4rpx solid rgba(255, 255, 255, 0.65);
	box-shadow: 0 6rpx 18rpx rgba(21, 90, 200, 0.16);
	flex-shrink: 0;
}
.profile-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 8rpx; }
.name-line { display: flex; align-items: center; gap: 16rpx; }
.user-name { font-size: 36rpx; font-weight: 700; color: #FFFFFF; text-shadow: 0 1rpx 3rpx rgba(16, 62, 140, 0.12); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.edit-pill { font-size: 22rpx; color: #FFFFFF; background: rgba(255, 255, 255, 0.22); border: 1rpx solid rgba(255, 255, 255, 0.32); padding: 4rpx 16rpx; border-radius: 999rpx; flex-shrink: 0; }
.user-sub { font-size: 24rpx; color: rgba(255, 255, 255, 0.92); line-height: 1.45; }
.identity-badges { display: flex; flex-wrap: wrap; gap: 10rpx; margin-top: 6rpx; }
.id-chip {
	font-size: 21rpx;
	font-weight: 600;
	color: #1A62D6;
	background: rgba(255, 255, 255, 0.92);
	padding: 3rpx 16rpx;
	border-radius: 999rpx;
}

.cockpit-card {
	margin: -54rpx 28rpx 22rpx;
	background: #FFFFFF;
	border-radius: 24rpx;
	padding: 28rpx 12rpx;
	display: flex;
	align-items: center;
	box-shadow: 0 10rpx 28rpx rgba(15, 23, 42, 0.06);
	border: 1rpx solid #E5EAF2;
	position: relative;
	z-index: 5;
}
.cockpit-item { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4rpx; }
.cp-num { font-size: 38rpx; font-weight: 700; color: #0F172A; line-height: 1.2; }
.cp-label { font-size: 25rpx; font-weight: 600; color: #334155; margin-top: 4rpx; }
.cp-divider { width: 1rpx; height: 64rpx; background: #EDF2F7; }

.body-wrap { padding: 0 28rpx; }
.panel-card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; margin-bottom: 22rpx; border: 1rpx solid #E5EAF2; }
.panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24rpx; }
.panel-title { font-size: 30rpx; font-weight: 600; color: #0F172A; }
.panel-more { display: flex; align-items: center; gap: 6rpx; }
.more-text { font-size: 25rpx; color: #64748B; }

.order-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8rpx; }
.order-cell { display: flex; flex-direction: column; align-items: center; gap: 12rpx; padding: 8rpx 0; }
.order-icon-wrap { position: relative; width: 64rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.order-label { font-size: 24rpx; color: #334155; font-weight: 500; }
.cell-badge {
	position: absolute;
	top: -6rpx;
	right: -10rpx;
	min-width: 30rpx;
	height: 30rpx;
	padding: 0 8rpx;
	border-radius: 999rpx;
	background: #2979FF;
	color: #FFFFFF;
	font-size: 20rpx;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
}
.cell-badge.warn { background: #D97706; }
.cell-badge.subtle { background: #475569; }

.tob-main-card {
	padding: 24rpx;
	border-radius: 20rpx;
	background: #F8FAFD;
	border: 1rpx solid #E2ECF8;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16rpx;
	margin-bottom: 18rpx;
}
.tob-main-left { display: flex; align-items: center; gap: 18rpx; flex: 1; min-width: 0; }
.tob-main-icon { width: 72rpx; height: 72rpx; border-radius: 18rpx; background: #EAF3FF; border: 1rpx solid #D6E7FF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.tob-main-info { flex: 1; min-width: 0; }
.tob-title-line { display: flex; align-items: center; gap: 10rpx; flex-wrap: nowrap; }
.tob-main-title { font-size: 28rpx; font-weight: 600; color: #0F172A; white-space: nowrap; }
.tob-active-tag { font-size: 21rpx; font-weight: 600; color: #059669; background: #ECFDF5; padding: 3rpx 12rpx; border-radius: 999rpx; white-space: nowrap; flex-shrink: 0; }
.tob-enter-pill { display: flex; align-items: center; gap: 6rpx; padding: 12rpx 22rpx; border-radius: 999rpx; background: #2979FF; color: #FFFFFF; flex-shrink: 0; }
.tob-enter-text { font-size: 24rpx; font-weight: 600; color: #FFFFFF; white-space: nowrap; }
.tob-enter-arrow { font-size: 26rpx; font-weight: 600; color: #FFFFFF; line-height: 1; }

.tob-sub-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16rpx; }
.tob-sub-card { padding: 20rpx 22rpx; border-radius: 18rpx; background: #F8FAFD; border: 1rpx solid #E8EEF6; display: flex; align-items: center; gap: 16rpx; }
.tob-sub-icon { width: 60rpx; height: 60rpx; border-radius: 14rpx; background: #EAF3FF; border: 1rpx solid #DCEAFF; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.tob-sub-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.tob-sub-title { font-size: 27rpx; font-weight: 600; color: #0F172A; white-space: nowrap; }

.tool-grid { display: grid; grid-template-columns: repeat(4, 1fr); row-gap: 28rpx; column-gap: 12rpx; }
.tool-item { display: flex; flex-direction: column; align-items: center; gap: 12rpx; padding: 4rpx 0; }
.tool-icon { width: 60rpx; height: 60rpx; display: flex; align-items: center; justify-content: center; position: relative; }
.tool-label { font-size: 24rpx; color: #334155; font-weight: 500; }

.auth-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.52); z-index: 200; display: flex; align-items: flex-end; }
.auth-sheet { width: 100%; background: #FFFFFF; border-radius: 32rpx 32rpx 0 0; padding: 32rpx 32rpx calc(36rpx + env(safe-area-inset-bottom)); box-sizing: border-box; }
.auth-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24rpx; }
.auth-title { display: block; font-size: 34rpx; font-weight: 700; color: #0F172A; }
.auth-close { padding: 8rpx; }
.mode-switch { display: flex; background: #F1F5F9; border-radius: 16rpx; padding: 6rpx; margin-bottom: 24rpx; }
.mode-btn { flex: 1; height: 68rpx; border-radius: 12rpx; display: flex; align-items: center; justify-content: center; font-size: 27rpx; font-weight: 600; color: #475569; }
.mode-btn.active { background: #FFFFFF; color: #165DFF; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.06); }
.auth-body { display: flex; flex-direction: column; gap: 18rpx; }
.code-row { display: flex; gap: 14rpx; align-items: center; }
.flex-1 { flex: 1; }
.input-box { height: 86rpx; padding: 0 24rpx; border-radius: 16rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; display: flex; align-items: center; gap: 16rpx; }
.auth-input { flex: 1; font-size: 28rpx; color: #0F172A; }
.send-code-btn { height: 86rpx; padding: 0 26rpx; border-radius: 16rpx; background: #EEF4FF; color: #165DFF; font-size: 25rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.send-code-btn.disabled { opacity: 0.55; }
.forgot-row { display: flex; justify-content: flex-end; padding: 4rpx 0; }
.forgot-link { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.agree-row { display: flex; align-items: flex-start; gap: 14rpx; margin: 22rpx 0 8rpx; }
.agree-box { width: 34rpx; height: 34rpx; border-radius: 8rpx; border: 2rpx solid #CBD5E1; display: flex; align-items: center; justify-content: center; font-size: 22rpx; color: #FFFFFF; flex-shrink: 0; margin-top: 2rpx; }
.agree-box.checked { background: #165DFF; border-color: #165DFF; }
.agree-text { flex: 1; font-size: 24rpx; color: #64748B; line-height: 1.5; }
.policy-link { color: #165DFF; font-weight: 500; }
.auth-submit-btn { height: 92rpx; border-radius: 20rpx; background: #165DFF; color: #FFFFFF; font-size: 30rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; margin-top: 24rpx; }
</style>
