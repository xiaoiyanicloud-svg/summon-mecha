<template>
	<view class="address-page">
		<nav-bar :title="showForm ? (editingId ? '编辑收货地址' : '新增收货地址') : '收货地址'" @back="onNavBack">
			<template #right>
				<text v-if="!showForm" class="nav-action" @tap="openCreate">新增</text>
			</template>
		</nav-bar>

		<view v-if="!isLoggedIn" class="sync-notice">
			<SmIcon name="info" :size="28" />
			<text class="sync-notice-text">未登录状态下地址仅保存在当前设备；登录账户后可同步至云端地址簿。</text>
		</view>

		<scroll-view v-if="showForm" class="page-scroll" scroll-y>
			<view class="form-card">
				<view class="field-item">
					<text class="field-label required">收货人 / 现场签收人</text>
					<input class="field-input" type="text" v-model="form.name" placeholder="请输入姓名" maxlength="40" />
				</view>

				<view class="field-item">
					<text class="field-label required">联系电话</text>
					<input class="field-input sm-mono" type="number" v-model="form.phone" placeholder="11 位大陆手机号码" maxlength="11" />
				</view>

				<view class="field-item">
					<view class="label-row">
						<text class="field-label required">所在省市区</text>
						<!-- #ifdef MP-WEIXIN -->
						<picker mode="region" :value="[form.province, form.city, form.district]" @change="onRegionPick">
							<text class="region-pick-btn">选择省市区 ›</text>
						</picker>
						<!-- #endif -->
					</view>
					<view class="region-grid">
						<input class="field-input" type="text" v-model="form.province" placeholder="省份/直辖市" maxlength="24" />
						<input class="field-input" type="text" v-model="form.city" placeholder="城市" maxlength="24" />
						<input class="field-input" type="text" v-model="form.district" placeholder="区/县" maxlength="24" />
					</view>
					<view class="preset-row">
						<text class="preset-label">常用履约城市：</text>
						<text
							v-for="preset in cityPresets"
							:key="preset.label"
							class="preset-chip"
							@tap="applyPreset(preset)"
						>{{ preset.label }}</text>
					</view>
				</view>

				<view class="field-item">
					<view class="label-row">
						<text class="field-label required">详细地址（园区 / 楼栋 / 门牌）</text>
						<!-- #ifndef H5 -->
						<text class="region-pick-btn" @tap="chooseNativeLocation">地图选点 ›</text>
						<!-- #endif -->
					</view>
					<textarea
						class="field-textarea"
						v-model="form.detail"
						placeholder="请填写街道、产业园区、楼栋楼层及门牌号（整机租赁建议注明货梯条件）"
						maxlength="200"
					/>
				</view>

				<view class="switch-row" @tap="form.isDefault = !form.isDefault">
					<view>
						<text class="switch-title">设为默认收货地址</text>
						<text class="switch-sub">整机租售与配件下单结算时自动优先带入</text>
					</view>
					<view class="check-pill" :class="{ active: form.isDefault }">
						<text class="check-pill-text">{{ form.isDefault ? '已开启' : '未开启' }}</text>
					</view>
				</view>

				<view class="form-actions">
					<view class="btn-cancel" @tap="showForm = false">取消</view>
					<view class="btn-save" :class="{ disabled: saving }" @tap="saveAddress">
						{{ saving ? '保存中...' : '保存地址' }}
					</view>
				</view>
			</view>
		</scroll-view>

		<state-view v-else-if="loading" preset="loading" loading-text="正在加载地址簿..." />
		<state-view v-else-if="errorMsg" preset="network" :title="errorMsg" @retry="loadAddresses" />
		<state-view
			v-else-if="list.length === 0"
			icon="location"
			title="暂无收货地址"
			hint="添加收货或现场交付地址后，机器人租赁与配件下单可一键带入"
			action-text="新增收货地址"
			@action="openCreate"
		/>

		<scroll-view v-else class="page-scroll" scroll-y>
			<view v-for="item in list" :key="item.id" class="addr-card">
				<view class="addr-head">
					<view class="addr-person">
						<text class="addr-name">{{ item.name }}</text>
						<text class="addr-phone sm-mono">{{ item.phone }}</text>
						<text v-if="item.isDefault" class="default-tag">默认</text>
					</view>
				</view>
				<text class="addr-full">{{ formatFullAddress(item) }}</text>
				<view class="addr-foot">
					<view class="foot-left" @tap="setAsDefault(item)">
						<text class="foot-link" :class="{ muted: item.isDefault }">
							{{ item.isDefault ? '当前默认地址' : '设为默认' }}
						</text>
					</view>
					<view class="foot-actions">
						<text class="foot-btn" @tap="openEdit(item)">编辑</text>
						<text class="foot-btn danger" @tap="confirmDelete(item)">删除</text>
					</view>
				</view>
			</view>

			<view class="bottom-add-wrap">
				<view class="btn-save full" @tap="openCreate">+ 新增收货地址</view>
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { addressApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config'
import { serverToLocal, localToServerPayload } from '@/utils/address'

const LOCAL_ADDR_KEY = 'addressList'

export default {
	components: { NavBar, StateView, SmIcon },
	data() {
		return {
			isLoggedIn: false,
			loading: false,
			saving: false,
			errorMsg: '',
			list: [],
			showForm: false,
			editingId: null,
			form: {
				name: '',
				phone: '',
				province: '',
				city: '',
				district: '',
				detail: '',
				isDefault: false,
			},
			cityPresets: [
				{ label: '广东·深圳', province: '广东省', city: '深圳市', district: '南山区' },
				{ label: '浙江·杭州', province: '浙江省', city: '杭州市', district: '滨江区' },
				{ label: '江苏·苏州', province: '江苏省', city: '苏州市', district: '工业园区' },
				{ label: '上海·浦东', province: '上海市', city: '上海市', district: '浦东新区' },
				{ label: '北京·海淀', province: '北京市', city: '北京市', district: '海淀区' },
			],
		}
	},
	onShow() {
		this.isLoggedIn = !!uni.getStorageSync(TOKEN_KEY)
		this.loadAddresses()
	},
	methods: {
		onNavBack() {
			if (this.showForm) {
				this.showForm = false
				return
			}
			const pages = typeof getCurrentPages === 'function' ? getCurrentPages() : []
			if (pages.length <= 1) {
				uni.switchTab({ url: '/pages/mine/index' })
			} else {
				uni.navigateBack({ delta: 1 })
			}
		},
		formatFullAddress(item) {
			return [item.province, item.city, item.district, item.detail].filter(Boolean).join(' ')
		},
		loadAddresses() {
			this.errorMsg = ''
			if (!this.isLoggedIn) {
				const cached = uni.getStorageSync(LOCAL_ADDR_KEY)
				this.list = Array.isArray(cached) ? cached : []
				return
			}
			this.loading = true
			addressApi.getAddresses()
				.then((res) => {
					const rows = Array.isArray(res && res.data) ? res.data : []
					this.list = rows.map(serverToLocal)
					uni.setStorageSync(LOCAL_ADDR_KEY, this.list)
				})
				.catch((err) => {
					this.errorMsg = (err && err.message) || '加载收货地址失败'
				})
				.finally(() => {
					this.loading = false
				})
		},
		openCreate() {
			this.editingId = null
			this.form = {
				name: '',
				phone: '',
				province: '',
				city: '',
				district: '',
				detail: '',
				isDefault: this.list.length === 0,
			}
			this.showForm = true
		},
		openEdit(item) {
			this.editingId = item.id
			this.form = {
				name: item.name || '',
				phone: item.phone || '',
				province: item.province || '',
				city: item.city || '',
				district: item.district || '',
				detail: item.detail || '',
				isDefault: !!item.isDefault,
			}
			this.showForm = true
		},
		applyPreset(preset) {
			this.form.province = preset.province
			this.form.city = preset.city
			this.form.district = preset.district
		},
		onRegionPick(e) {
			const val = (e && e.detail && e.detail.value) || []
			if (val.length >= 2) {
				this.form.province = val[0] || ''
				this.form.city = val[1] || ''
				this.form.district = val[2] || ''
			}
		},
		chooseNativeLocation() {
			if (typeof uni.chooseLocation !== 'function') return
			uni.chooseLocation({
				success: (loc) => {
					const addr = [loc.address, loc.name].filter(Boolean).join(' ')
					if (addr) this.form.detail = addr
				},
			})
		},
		saveAddress() {
			if (this.saving) return
			const f = this.form
			if (!String(f.name || '').trim()) {
				uni.showToast({ title: '请填写收货人姓名', icon: 'none' })
				return
			}
			if (!/^1\d{10}$/.test(String(f.phone || '').trim())) {
				uni.showToast({ title: '请填写正确的 11 位手机号', icon: 'none' })
				return
			}
			if (!String(f.province || '').trim() || !String(f.city || '').trim()) {
				uni.showToast({ title: '请填写所在省市', icon: 'none' })
				return
			}
			if (!String(f.detail || '').trim()) {
				uni.showToast({ title: '请填写详细街道门牌地址', icon: 'none' })
				return
			}

			if (!this.isLoggedIn) {
				const next = [...this.list]
				if (f.isDefault) next.forEach(x => { x.isDefault = false })
				if (this.editingId) {
					const idx = next.findIndex(x => x.id === this.editingId)
					if (idx >= 0) next[idx] = { ...f, id: this.editingId }
				} else {
					next.unshift({ ...f, id: 'local_' + Date.now() })
				}
				this.list = next
				uni.setStorageSync(LOCAL_ADDR_KEY, next)
				this.showForm = false
				uni.showToast({ title: '已保存至本机', icon: 'success' })
				return
			}

			const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
			const payload = localToServerPayload(f, customer.email || 'buyer@summon-mecha.local')
			this.saving = true
			const req = this.editingId
				? addressApi.updateAddress(this.editingId, payload)
				: addressApi.createAddress(payload)

			req.then(() => {
				this.showForm = false
				uni.showToast({ title: '地址已保存', icon: 'success' })
				this.loadAddresses()
			})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '保存地址失败', icon: 'none' })
				})
				.finally(() => {
					this.saving = false
				})
		},
		setAsDefault(item) {
			if (item.isDefault) return
			if (!this.isLoggedIn) {
				this.list = this.list.map(x => ({ ...x, isDefault: x.id === item.id }))
				uni.setStorageSync(LOCAL_ADDR_KEY, this.list)
				return
			}
			const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
			const payload = localToServerPayload({ ...item, isDefault: true }, customer.email || 'buyer@summon-mecha.local')
			addressApi.updateAddress(item.id, payload)
				.then(() => {
					uni.showToast({ title: '已设为默认地址', icon: 'success' })
					this.loadAddresses()
				})
				.catch((err) => {
					uni.showToast({ title: (err && err.message) || '设置失败', icon: 'none' })
				})
		},
		confirmDelete(item) {
			uni.showModal({
				title: '删除收货地址',
				content: `确认删除「${item.name}」的收货地址吗？`,
				confirmColor: '#DC2626',
				success: (res) => {
					if (!res.confirm) return
					if (!this.isLoggedIn) {
						this.list = this.list.filter(x => x.id !== item.id)
						uni.setStorageSync(LOCAL_ADDR_KEY, this.list)
						return
					}
					addressApi.deleteAddress(item.id)
						.then(() => {
							uni.showToast({ title: '已删除', icon: 'success' })
							this.loadAddresses()
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
.address-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.nav-action { font-size: 28rpx; font-weight: 600; color: #165DFF; }
.sync-notice { display: flex; align-items: center; gap: 12rpx; padding: 18rpx 28rpx; background: #EEF4FF; border-bottom: 1rpx solid #D6E4FF; }
.sync-notice-text { flex: 1; font-size: 24rpx; color: #1E40AF; line-height: 1.5; }
.page-scroll { flex: 1; padding: 24rpx 28rpx; box-sizing: border-box; }
.addr-card { background: #FFFFFF; border-radius: 22rpx; padding: 28rpx; margin-bottom: 20rpx; border: 1rpx solid #E5EAF2; }
.addr-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12rpx; }
.addr-person { display: flex; align-items: center; gap: 16rpx; flex-wrap: wrap; }
.addr-name { font-size: 31rpx; font-weight: 600; color: #0F172A; }
.addr-phone { font-size: 28rpx; color: #475569; }
.default-tag { font-size: 22rpx; font-weight: 600; color: #165DFF; background: #EEF4FF; padding: 4rpx 14rpx; border-radius: 999rpx; }
.addr-full { display: block; font-size: 27rpx; color: #334155; line-height: 1.55; padding-bottom: 22rpx; border-bottom: 1rpx solid #F1F5F9; }
.addr-foot { display: flex; align-items: center; justify-content: space-between; padding-top: 20rpx; }
.foot-link { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.foot-link.muted { color: #64748B; }
.foot-actions { display: flex; align-items: center; gap: 28rpx; }
.foot-btn { font-size: 26rpx; color: #475569; }
.foot-btn.danger { color: #DC2626; }
.form-card { background: #FFFFFF; border-radius: 24rpx; padding: 30rpx; border: 1rpx solid #E5EAF2; }
.field-item { margin-bottom: 26rpx; }
.label-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12rpx; }
.field-label { display: block; font-size: 26rpx; font-weight: 500; color: #334155; margin-bottom: 10rpx; }
.label-row .field-label { margin-bottom: 0; }
.field-label.required::after { content: ' *'; color: #DC2626; }
.region-pick-btn { font-size: 25rpx; color: #165DFF; font-weight: 500; }
.region-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14rpx; }
.field-input { width: 100%; height: 82rpx; padding: 0 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; }
.field-textarea { width: 100%; min-height: 150rpx; padding: 20rpx 22rpx; box-sizing: border-box; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 16rpx; font-size: 27rpx; color: #0F172A; line-height: 1.5; }
.preset-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12rpx; margin-top: 14rpx; }
.preset-label { font-size: 23rpx; color: #64748B; }
.preset-chip { font-size: 23rpx; color: #1E40AF; background: #EEF4FF; padding: 6rpx 16rpx; border-radius: 999rpx; }
.switch-row { display: flex; align-items: center; justify-content: space-between; padding: 22rpx 0; border-top: 1rpx solid #F1F5F9; margin-bottom: 16rpx; }
.switch-title { display: block; font-size: 28rpx; font-weight: 500; color: #0F172A; }
.switch-sub { display: block; font-size: 23rpx; color: #64748B; margin-top: 4rpx; }
.check-pill { padding: 10rpx 24rpx; border-radius: 999rpx; background: #F1F5F9; color: #64748B; }
.check-pill.active { background: #EEF4FF; color: #165DFF; }
.check-pill-text { font-size: 24rpx; font-weight: 600; }
.form-actions { display: flex; gap: 20rpx; }
.btn-cancel { flex: 1; height: 88rpx; border-radius: 18rpx; background: #F1F5F9; color: #475569; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-save { flex: 2; height: 88rpx; border-radius: 18rpx; background: #165DFF; color: #FFFFFF; font-size: 29rpx; font-weight: 600; display: flex; align-items: center; justify-content: center; }
.btn-save.full { width: 100%; }
.btn-save.disabled { opacity: 0.6; }
.bottom-add-wrap { margin-top: 12rpx; }
</style>
