<template>
	<view class="profile-page">
		<nav-bar title="账户与企业资料" />

		<state-view
			v-if="!isLoggedIn"
			icon="lock"
			title="请先登录账户"
			hint="登录后可维护个人资料、企业名片与安全密码"
			action-text="返回登录"
			@action="goMine"
		/>

		<state-view v-else-if="loading" preset="loading" loading-text="正在读取账户资料..." />

		<scroll-view v-else class="profile-scroll" scroll-y>
			<view class="identity-card">
				<view class="identity-top">
					<image class="avatar" src="/static/default-avatar.png" mode="aspectFill" />
					<view class="identity-main">
						<text class="identity-name">{{ displayFullName }}</text>
						<text class="identity-email sm-mono">{{ profile.email || '—' }}</text>
					</view>
				</view>
				<view class="badge-row" v-if="identities.length">
					<text
						v-for="item in identities"
						:key="item.key"
						class="role-badge"
					>{{ item.label }}</text>
				</view>
			</view>

			<view class="section-card">
				<view class="section-head">
					<text class="section-title">基本与企业名片</text>
					<text class="section-sub">用于订单联络、场景对接与企业开票预填</text>
				</view>

				<view class="field-item">
					<text class="field-label required">姓名 / 联系人</text>
					<input
						class="field-input"
						type="text"
						v-model="form.fullName"
						placeholder="请输入真实姓名或联系人称呼"
						maxlength="60"
					/>
				</view>

				<view class="field-item">
					<text class="field-label">联系手机号</text>
					<input
						class="field-input sm-mono"
						type="number"
						v-model="form.phone"
						placeholder="11 位大陆手机号（选填，用于履约通知）"
						maxlength="11"
					/>
				</view>

				<view class="field-item">
					<text class="field-label">所属企业 / 机构名称</text>
					<input
						class="field-input"
						type="text"
						v-model="form.company_name"
						placeholder="选填，如：深圳某某智能科技有限公司"
						maxlength="191"
					/>
				</view>

				<view class="field-item">
					<text class="field-label">职务 / 业务角色</text>
					<input
						class="field-input"
						type="text"
						v-model="form.job_title"
						placeholder="选填，如：采购负责人 / 算法工程师 / 运营总监"
						maxlength="100"
					/>
				</view>

				<view class="primary-btn" :class="{ disabled: savingProfile }" @tap="saveProfile">
					<text class="primary-btn-text">{{ savingProfile ? '保存中...' : '保存资料修改' }}</text>
				</view>
			</view>

			<view class="section-card">
				<view class="section-head">
					<text class="section-title">修改登录密码</text>
					<text class="section-sub">与开发者门户、商家工作台共用同一套账号凭证</text>
				</view>

				<view class="field-item">
					<text class="field-label required">当前密码</text>
					<input
						class="field-input"
						type="password"
						v-model="pwdForm.current_password"
						placeholder="请输入当前登录密码"
					/>
				</view>

				<view class="field-item">
					<text class="field-label required">新密码</text>
					<input
						class="field-input"
						type="password"
						v-model="pwdForm.new_password"
						placeholder="请设置至少 6 位新密码"
					/>
				</view>

				<view class="field-item">
					<text class="field-label required">确认新密码</text>
					<input
						class="field-input"
						type="password"
						v-model="pwdForm.new_password_confirmation"
						placeholder="请再次输入新密码"
					/>
				</view>

				<view class="secondary-btn" :class="{ disabled: savingPwd }" @tap="savePassword">
					<text class="secondary-btn-text">{{ savingPwd ? '提交中...' : '更新登录密码' }}</text>
				</view>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { authApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config'
import { splitName } from '@/utils/address'

export default {
	components: { NavBar, StateView },
	data() {
		return {
			isLoggedIn: false,
			loading: false,
			savingProfile: false,
			savingPwd: false,
			profile: {},
			identities: [],
			form: {
				fullName: '',
				phone: '',
				company_name: '',
				job_title: '',
			},
			pwdForm: {
				current_password: '',
				new_password: '',
				new_password_confirmation: '',
			},
		}
	},
	computed: {
		displayFullName() {
			return (this.form.fullName || this.profile.name || '未命名用户').trim()
		},
	},
	onShow() {
		this.isLoggedIn = !!uni.getStorageSync(TOKEN_KEY)
		if (this.isLoggedIn) {
			this.fetchProfile()
		}
	},
	methods: {
		goMine() {
			uni.switchTab({ url: '/pages/mine/index' })
		},
		fetchProfile() {
			this.loading = true
			authApi.profile()
				.then((res) => {
					const d = (res && res.data) || {}
					this.profile = d
					this.identities = Array.isArray(d.identities) ? d.identities : []
					this.form = {
						fullName: d.name || [d.first_name, d.last_name].filter(Boolean).join(' '),
						phone: d.phone || '',
						company_name: d.company_name || '',
						job_title: d.job_title || '',
					}
					const cached = uni.getStorageSync(CUSTOMER_KEY) || {}
					uni.setStorageSync(CUSTOMER_KEY, { ...cached, ...d })
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '加载资料失败', icon: 'none' })
				})
				.finally(() => {
					this.loading = false
				})
		},
		saveProfile() {
			if (this.savingProfile) return
			const name = String(this.form.fullName || '').trim()
			if (!name) {
				uni.showToast({ title: '请填写姓名或联系人', icon: 'none' })
				return
			}
			const phone = String(this.form.phone || '').trim()
			if (phone && !/^1\d{10}$/.test(phone)) {
				uni.showToast({ title: '请输入正确的 11 位大陆手机号', icon: 'none' })
				return
			}
			const [first_name, last_name] = splitName(name)
			this.savingProfile = true
			authApi.updateProfile({
				first_name,
				last_name,
				phone: phone || null,
				company_name: String(this.form.company_name || '').trim() || null,
				job_title: String(this.form.job_title || '').trim() || null,
			})
				.then((res) => {
					const d = (res && res.data) || {}
					this.profile = { ...this.profile, ...d }
					const cached = uni.getStorageSync(CUSTOMER_KEY) || {}
					uni.setStorageSync(CUSTOMER_KEY, { ...cached, ...d })
					uni.showToast({ title: '资料已保存', icon: 'success' })
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '保存失败', icon: 'none' })
				})
				.finally(() => {
					this.savingProfile = false
				})
		},
		savePassword() {
			if (this.savingPwd) return
			const { current_password, new_password, new_password_confirmation } = this.pwdForm
			if (!current_password) {
				uni.showToast({ title: '请输入当前密码', icon: 'none' })
				return
			}
			if (!new_password || new_password.length < 6) {
				uni.showToast({ title: '新密码至少 6 位', icon: 'none' })
				return
			}
			if (new_password !== new_password_confirmation) {
				uni.showToast({ title: '两次输入的新密码不一致', icon: 'none' })
				return
			}
			this.savingPwd = true
			authApi.updatePassword({
				current_password,
				new_password,
				new_password_confirmation,
			})
				.then(() => {
					this.pwdForm = {
						current_password: '',
						new_password: '',
						new_password_confirmation: '',
					}
					uni.showToast({ title: '密码已更新', icon: 'success' })
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '修改密码失败', icon: 'none' })
				})
				.finally(() => {
					this.savingPwd = false
				})
		},
	},
}
</script>

<style scoped>
.profile-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.profile-scroll { flex: 1; padding: 24rpx 28rpx; box-sizing: border-box; }
.identity-card { background: linear-gradient(135deg, #102F6E 0%, #174299 100%); border-radius: 24rpx; padding: 32rpx; color: #FFFFFF; margin-bottom: 24rpx; }
.identity-top { display: flex; align-items: center; gap: 24rpx; }
.avatar { width: 104rpx; height: 104rpx; border-radius: 50%; border: 3rpx solid rgba(255, 255, 255, 0.35); background: #FFFFFF; }
.identity-main { flex: 1; display: flex; flex-direction: column; gap: 8rpx; }
.identity-name { font-size: 34rpx; font-weight: 600; color: #FFFFFF; }
.identity-email { font-size: 24rpx; color: rgba(255, 255, 255, 0.8); }
.badge-row { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 22rpx; }
.role-badge { font-size: 22rpx; padding: 6rpx 18rpx; border-radius: 999rpx; background: rgba(255, 255, 255, 0.16); color: #F8FAFC; border: 1rpx solid rgba(255, 255, 255, 0.24); }
.section-card { background: #FFFFFF; border-radius: 24rpx; padding: 30rpx; margin-bottom: 24rpx; border: 1rpx solid #E5EAF2; }
.section-head { display: flex; flex-direction: column; gap: 6rpx; margin-bottom: 24rpx; }
.section-title { font-size: 30rpx; font-weight: 600; color: #0F172A; }
.section-sub { font-size: 24rpx; color: #64748B; }
.field-item { margin-bottom: 24rpx; }
.field-label { display: block; font-size: 26rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.field-label.required::after { content: ' *'; color: #DC2626; }
.field-input { width: 100%; height: 84rpx; padding: 0 24rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 28rpx; color: #0F172A; }
.primary-btn { height: 88rpx; border-radius: 18rpx; background: #165DFF; display: flex; align-items: center; justify-content: center; margin-top: 8rpx; }
.primary-btn.disabled, .secondary-btn.disabled { opacity: 0.6; }
.primary-btn-text { font-size: 29rpx; font-weight: 600; color: #FFFFFF; }
.secondary-btn { height: 88rpx; border-radius: 18rpx; background: #EEF4FF; border: 1rpx solid #C7DCFF; display: flex; align-items: center; justify-content: center; margin-top: 8rpx; }
.secondary-btn-text { font-size: 29rpx; font-weight: 600; color: #165DFF; }
</style>
