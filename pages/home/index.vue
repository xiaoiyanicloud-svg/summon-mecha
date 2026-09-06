<template>
	<view class="home-page">
		<view class="page-content">

			<!-- ===== 顶部区域 ===== -->
			<view class="top-section">
				<!-- 状态栏 -->
				<view class="status-bar-wrap">
					<view class="status-bar">
						<view class="brand-text" @click="goHome">
							<text class="brand-subtitle">用机器人上</text>
							<text class="brand-title">召唤机甲</text>
						</view>
						<view class="right"></view>
					</view>
				</view>

				<!-- AI 召唤 -->
				<view class="ai-center">
					<view class="ai-content-wrap">
						<view class="ai-header">
							<text class="ai-title">AI 召唤</text>
							<text class="badge">50+应用场景</text>
						</view>
						<view class="ai-input-row">
							<input class="input" type="text" v-model="need" placeholder="请输入需求，如：展会接待、酒店配送" />
							<button class="btn-generate" @click="handleGenerate">立即召唤</button>
						</view>
					</view>
				</view>

				<!-- Hero -->
				<view class="hero"></view>
			</view>

			<!-- ===== 场景方案 + 热门品牌 ===== -->
			<view class="hot-square-container">
				<!-- 场景方案 -->
				<view class="hot-square left">
					<view class="square-header">
						<text class="square-title">场景方案</text>
						<text class="square-more" @click="showMore">更多 ›</text>
					</view>
					<view class="square-content">
						<swiper 
							class="scheme-swiper" 
							circular="false"
							:autoplay="autoplayEnabled"
							:interval="3000"
							@touchstart="handleTouchStart"
							@touchend="handleTouchEnd"
							@change="handleSwiperChange"
						>
							<swiper-item v-for="(page, pidx) in schemePages" :key="pidx">
								<view class="scheme-grid">
									<view class="scheme-card" v-for="(item, idx) in page" :key="idx" @click="schemeDetail(item)">
										<view class="scheme-card-icon" :style="{ backgroundColor: item.bg || item.bgColor }">
											<image v-if="item.image || item.banner" class="scheme-card-img" :src="item.image || item.banner" mode="aspectFill"></image>
											<text v-else>{{ item.icon }}</text>
										</view>
										<view class="scheme-card-text">
											<text class="scheme-card-title">{{ item.name || item.title }}</text>
										</view>
										<view class="scheme-card-consult">
											<text>{{ item.consultCount ? (item.consultCount + '咨询') : '100咨询' }}</text>
										</view>
									</view>
								</view>
							</swiper-item>
						</swiper>
					</view>
					<view class="custom-indicator">
						<view class="dot" v-for="(page, idx) in schemePages" :key="idx" :class="{ active: currentIndex === idx }"></view>
					</view>
				</view>

				<!-- 热门品牌 -->
				<view class="hot-square right">
					<view class="brand-top">
						<view class="brand-image" @click="goBrandBuy">
							<image src="/static/Hot Brands.png" mode="aspectFill" style="width: 100%; height: 100%; display: block;"></image>
							<view class="brand-overlay-title">热门品牌</view>
							<view class="brand-overlay-sub">精选TOP20</view>
						</view>
					</view>
					<view class="brand-divider"></view>
					<view class="brand-bottom">
						<view class="brand-actions">
							<view class="action-card rental-card" @click="onRent">
								<image class="action-card-img" src="/static/wyzuyong.png" mode="aspectFill"></image>
								<text class="action-card-title">我要租用</text>
							</view>
							<view class="action-card custom-card" @click="onCustom">
								<image class="action-card-img" src="/static/wydingzhi.png" mode="aspectFill"></image>
								<text class="action-card-title">我要定制</text>
							</view>
						</view>
					</view>
				</view>
			</view>

			<!-- ===== 🔥 生态中心（独立组件） ===== -->
			<EcoCenter />

			<!-- 底部安全区 -->
			<view class="bottom-safe"></view>

		</view>

		<!-- ===== 下滑渐显的白色顶部框架(logo) ===== -->
		<view class="top-scroll-header" :style="{ opacity: topNavOpacity }">
			<image class="top-scroll-logo" src="/static/logo.png" mode="aspectFit"></image>
		</view>

		<!-- ===== 自定义底部导航栏 ===== -->
		<CustomTabBar currentPage="/pages/home/index" />

		<!-- ===== 定制弹窗 ===== -->
		<view class="custom-modal-mask" v-if="showCustomModal" @tap="closeCustomModal">
			<view class="custom-modal" @tap.stop>
				<view class="modal-close" @tap="closeCustomModal">✕</view>
				<view class="modal-header">
					<text class="modal-icon">✨</text>
					<text class="modal-title">定制专属机器人方案</text>
					<text class="modal-subtitle">留下您的需求，专家1小时内为您定制</text>
				</view>
				<view class="modal-body">
					<view class="form-item">
						<text class="form-label">身份 <text class="required">*</text></text>
						<view class="identity-group">
							<text class="identity-tag" :class="{ active: customForm.identity === 'enterprise' }" @tap="selectIdentity('enterprise')">🏢 企业</text>
							<text class="identity-tag" :class="{ active: customForm.identity === 'personal' }" @tap="selectIdentity('personal')">👤 个人</text>
						</view>
					</view>
					<view class="form-item">
						<text class="form-label">联系人 <text class="required">*</text></text>
						<input class="form-input" type="text" v-model="customForm.name" placeholder="请输入您的姓名" />
					</view>
					<view class="form-item">
						<text class="form-label">手机号 <text class="required">*</text></text>
						<input class="form-input" type="number" v-model="customForm.phone" placeholder="请输入您的手机号" maxlength="11" />
					</view>
					<view class="form-item">
						<text class="form-label">微信号 <text class="optional">选填</text></text>
						<input class="form-input" type="text" v-model="customForm.wechat" placeholder="请输入微信号" />
					</view>
					<view class="form-item">
						<text class="form-label">公司名称 <text class="required" v-if="customForm.identity === 'enterprise'">*</text><text class="optional" v-else>选填</text></text>
						<input class="form-input" type="text" v-model="customForm.company" :placeholder="customForm.identity === 'enterprise' ? '请输入公司名称' : '请输入公司名称（选填）'" />
					</view>
					<view class="form-item">
						<text class="form-label">需求描述 <text class="optional">选填</text></text>
						<input class="form-input" type="text" v-model="customForm.demand" placeholder="一句话描述您的需求" />
					</view>
				</view>
				<view class="modal-footer">
					<view class="btn-secondary" @tap="closeCustomModal">稍后再说</view>
					<view class="btn-primary" @tap="submitCustom">立即提交</view>
				</view>
			</view>
		</view>

	</view>
</template>

<script>
	import CustomTabBar from '@/components/CustomTabBar.vue';
	import EcoCenter from '@/components/EcoCenter.vue';
import { sceneProducts, loadBackendScenes } from '@/components/jiqi/changjing.vue';
import { tobOrderApi } from '@/utils/api';

	export default {
		components: {
			CustomTabBar,
			EcoCenter
		},
		data() {
			return {
				need: '',
				scrollY: 0,
				autoplayEnabled: true,
				autoPlayTimer: null,
				currentIndex: 0,
				showCustomModal: false,
				customForm: {
					identity: 'enterprise',
					name: '',
					phone: '',
					wechat: '',
					company: '',
					demand: ''
				},
				// 场景方案初始给一份本地场景兜底（避免首屏空）；onLoad/onShow 用后台热门覆盖
				schemes: [...sceneProducts]
			};
		},
		computed: {
			// 下滑渐显: 从顶部(scrollY=0)就开始缓缓渐变, 到 hero 区(scrollY≈240)完全显示
			topNavOpacity() {
				const start = 0, range = 240;
				return Math.min(1, Math.max(0, (this.scrollY - start) / range));
			},
			schemePages() {
				const pageSize = 4;
				const pages = [];
				for (let i = 0; i < this.schemes.length; i += pageSize) {
					pages.push(this.schemes.slice(i, i + pageSize));
				}
				return pages;
			}
		},
		methods: {
			handleGenerate() {
				if (!this.need.trim()) {
					uni.showToast({ title: '请先输入需求', icon: 'none' });
					return;
				}
				uni.showToast({ title: `✅ 已生成「${this.need}」方案`, icon: 'success' });
			},
			showMore() {
				uni.showToast({ title: '查看更多方案', icon: 'none' });
			},
			schemeDetail(item) {
				const params = {
					title: item.name || item.title,
					sceneId: item.id || '',
					category: item.category || '',
					image: item.image || '',
					icon: item.icon,
					consultCount: item.consultCount,
					bgColor: item.bgColor
				};
				const queryString = Object.keys(params).map(key => `${key}=${encodeURIComponent(params[key])}`).join('&');
				uni.navigateTo({ url: `/pages/robot/fangan?${queryString}` });
			},
			onRent() {
				uni.switchTab({ url: '/pages/robot/index' });
			},
			goBrandBuy() {
				uni.setStorageSync('robotTab', 'buy');
				uni.switchTab({ url: '/pages/robot/index' });
			},
			onCustom() {
				this.customForm = { identity: 'enterprise', name: '', phone: '', wechat: '', company: '', demand: '' };
				this.showCustomModal = true;
			},
			selectIdentity(type) {
				this.customForm.identity = type;
			},
			closeCustomModal() {
				this.showCustomModal = false;
			},
			async submitCustom() {
				const { identity, name, phone, company, wechat, demand } = this.customForm;
				if (!identity) { uni.showToast({ title: '请选择您的身份', icon: 'none' }); return; }
				if (!name.trim()) { uni.showToast({ title: '请输入联系人姓名', icon: 'none' }); return; }
				if (!phone || phone.length < 11) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return; }
				if (identity === 'enterprise' && !company.trim()) { uni.showToast({ title: '请输入公司名称', icon: 'none' }); return; }
				// 真实提交:线索走 tob-order(type=custom),后台订单列表可见
				let submitted = true;
				try {
					const res = await tobOrderApi.create({
						type: 'custom',
						product_name: '首页定制咨询·' + (identity === 'enterprise' ? '企业' : '个人'),
						name: name.trim(), phone, wechat, company, demand,
						qty: 1,
					});
					if (!res || !res.success) throw new Error((res && res.message) || '提交失败');
				} catch (e) {
					submitted = false;
				}
				uni.showToast({ title: submitted ? '已提交，专家将1小时内联系您' : '网络异常，请稍后重试', icon: submitted ? 'success' : 'none' });
				if (submitted) {
					this.closeCustomModal();
					this.customForm = { identity: 'enterprise', name: '', phone: '', wechat: '', company: '', demand: '' };
				}
			},
			handleTouchStart() {
				this.autoplayEnabled = false;
				if (this.autoPlayTimer) { clearTimeout(this.autoPlayTimer); this.autoPlayTimer = null; }
			},
			handleTouchEnd() {
				if (this.autoPlayTimer) { clearTimeout(this.autoPlayTimer); this.autoPlayTimer = null; }
				this.autoPlayTimer = setTimeout(() => { this.autoplayEnabled = true; this.autoPlayTimer = null; }, 3000);
			},
			handleSwiperChange(e) {
				this.currentIndex = e.detail.current;
			},
			async refreshScenes() {
				try { await loadBackendScenes() } catch (e) {}
				// 首页「场景方案」= 后台设为热门的场景；无热门时回退全部场景（避免空屏）
				const hot = sceneProducts.filter(s => s.is_hot);
				this.schemes = hot.length ? hot : sceneProducts;
			},
			goHome() {
				uni.showToast({ title: '回到首页', icon: 'none' });
			}
		},
		onLoad() {
			this.refreshScenes();
		},
		onShow() {
			this.refreshScenes();
		},
		onPageScroll(e) {
			this.scrollY = (e && e.scrollTop) || 0;
		},
		beforeDestroy() {
			if (this.autoPlayTimer) { clearTimeout(this.autoPlayTimer); this.autoPlayTimer = null; }
		}
	};
</script>

<style>
	/* ===== 首页样式（仅保留首页自身模块，生态中心样式已移至组件） ===== */
	.home-page {
		background-color: #ffffff;
		min-height: 100vh;
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
	}
	.page-content {
		padding-bottom: 160rpx;
		background-color: #F7F8FA;
	}
	.top-section {
		background: linear-gradient(180deg, #4F87FF 25%, #FFFFFF 100%);
		padding: 0 20rpx 30rpx 20rpx;
		margin-bottom: 24rpx;
		overflow: visible;
	}
	.status-bar-wrap {
		position: relative;
		padding: 0;
		margin: 0 -20rpx;
		background: transparent;
	}
	.status-bar {
		display: flex;
		justify-content: space-between;
		align-items: center;
		height: 140rpx;
		padding: 0 20rpx;
		color: #1F2937;
		font-size: 24rpx;
	}
	.brand-text {
		display: flex;
		flex-direction: row;
		align-items: baseline;
		cursor: pointer;
		gap: 10rpx;
		padding-top: 100rpx;
	}
	.brand-subtitle {
		color: #FFFFFF;
		font-size: 30rpx;
		font-weight: 400;
		line-height: 1.2;
	}
	.brand-title {
		color: #FFFFFF;
		font-size: 42rpx;
		font-weight: 800;
		line-height: 1.2;
		letter-spacing: 2rpx;
	}
	.brand-text:active { opacity: 0.7; }
	.ai-center {
		background-image: url('/static/zhaohuan.png');
		background-size: 100% 80%;
		background-repeat: no-repeat;
		border-radius: 16rpx;
		padding: 32rpx 28rpx 128rpx;
		margin-top: -30rpx;
		margin-bottom: -50rpx;
		position: relative;
		z-index: 10;
		overflow: visible;
	}
	.ai-content-wrap { margin-top: 56rpx; }
	.ai-header {
		display: flex;
		align-items: center;
		gap: 12rpx;
		margin-bottom: 20rpx;
	}
	.ai-title {
		font-size: 34rpx;
		font-weight: 600;
		background: linear-gradient(135deg, #165DFF, #3B82F6);
		-webkit-background-clip: text;
		-webkit-text-fill-color: transparent;
		background-clip: text;
	}
	.badge {
		background: #B2D4FF;
		color: #FFFFFF;
		font-size: 16rpx;
		font-weight: 500;
		padding: 4rpx 12rpx;
		border-radius: 12rpx;
	}
	.ai-input-row {
		display: flex;
		gap: 20rpx;
	}
	.ai-input-row .input {
		flex: 1;
		background: #fff;
		border: 1px solid #E5E7EB;
		border-radius: 16rpx;
		padding: 0 24rpx;
		height: 72rpx;
		color: #1F2937;
		font-size: 28rpx;
	}
	.ai-input-row .input::placeholder { color: #9CA3AF; }
	.btn-generate {
		background: linear-gradient(135deg, #165DFF, #3B82F6);
		border: none;
		border-radius: 16rpx;
		padding: 0 32rpx;
		font-size: 28rpx;
		font-weight: 600;
		color: #fff;
		height: 72rpx;
		line-height: 72rpx;
	}
	.hero {
		width: 100%;
		display: flex;
		flex-direction: column;
		justify-content: flex-start;
		padding-top: 20rpx;
		min-height: 180rpx;
		margin-bottom: -30rpx;
		position: relative;
		z-index: 1;
		background-image: url('/static/hero.png');
		background-size: cover;
		background-position: center;
		background-repeat: no-repeat;
		background-color: rgba(255, 255, 255, 0.9);
		border-radius: 16rpx 16rpx 0 0;
	}
	.hot-square-container {
		display: flex;
		gap: 20rpx;
		margin-bottom: 24rpx;
	}
	.hot-square {
		flex: 1;
		border-radius: 24rpx;
		padding: 20rpx 16rpx 16rpx;
		display: flex;
		flex-direction: column;
	}
	.hot-square.left {
		background: linear-gradient(to bottom right, #94C4FF 3%, #FFFFFF 40%);
	}
	.hot-square.right {
		background: linear-gradient(to bottom left, #94C4FF 3%, #FFFFFF 40%);
		padding: 0;
		overflow: hidden;
	}
	.square-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 12rpx;
	}
	.square-title {
		font-size: 32rpx;
		font-weight: 800;
		color: #1F2937;
	}
	.square-more {
		font-size: 20rpx;
		color: #999999;
		cursor: pointer;
	}
	.square-content {
		flex: 1;
		display: flex;
		flex-direction: column;
		justify-content: center;
	}
	.scheme-swiper {
		width: 100%;
		height: 400rpx;
	}
	.scheme-grid {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 6rpx;
		height: 400rpx;
	}
	.scheme-card {
		border-radius: 16rpx;
		display: flex;
		flex-direction: column;
		overflow: hidden;
		height: 195rpx;
		box-shadow: 0 2rpx 8rpx rgba(0, 0, 0, 0.02);
	}
	.scheme-card-icon {
		height: 68%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 40rpx;
	}
	.scheme-card-img {
		width: 100%;
		height: 100%;
	}
	.scheme-card-text {
		height: 20%;
		display: flex;
		align-items: center;
		justify-content: center;
		background-color: transparent;
		padding: 0 2rpx;
	}
	.scheme-card-title {
		font-size: 24rpx;
		font-weight: 600;
		color: #1A1A1A;
		text-align: center;
		line-height: 1.3;
		max-width: 100%;
		padding: 0 6rpx;
		box-sizing: border-box;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.scheme-card-consult {
		flex: 1;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 18rpx;
		color: #1A1A1A;
		background-color: transparent;
		line-height: 1;
		padding-bottom: 4rpx;
		box-sizing: border-box;
	}
	.custom-indicator {
		display: flex;
		justify-content: center;
		align-items: center;
		gap: 12rpx;
		padding: 16rpx 0 4rpx 0;
		position: relative;
		top: 20rpx;
		flex-shrink: 0;
	}
	.custom-indicator .dot {
		width: 16rpx;
		height: 16rpx;
		border-radius: 50%;
		background: #D1D5DB;
		transition: background 0.3s;
	}
	.custom-indicator .dot.active { background: #165DFF; }
	.brand-top {
		flex: 1;
		display: flex;
		flex-direction: column;
		min-height: 0;
	}
	.brand-image {
		flex: 1;
		min-height: 60rpx;
		position: relative;
	}
	.brand-overlay-title {
		position: absolute;
		top: 20rpx;
		left: 20rpx;
		font-size: 32rpx;
		font-weight: 800;
		color: #1F2937;
	}
	.brand-overlay-sub {
		position: absolute;
		top: 66rpx;
		left: 20rpx;
		font-size: 22rpx;
		color: #9CA3AF;
	}
	.brand-image image {
		width: 100%;
		height: 100%;
		display: block;
	}
	.brand-divider {
		height: 1px;
		background: rgba(0, 0, 0, 0.06);
		margin: 12rpx 16rpx;
		flex-shrink: 0;
	}
	.brand-bottom {
		flex: 1;
		display: flex;
		align-items: stretch;
		padding: 0 16rpx 16rpx 16rpx;
		min-height: 0;
	}
	.brand-actions {
		width: 100%;
		display: flex;
		gap: 16rpx;
		align-items: stretch;
	}
	.action-card {
		flex: 1;
		border-radius: 16rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		box-shadow: 0 2rpx 8rpx rgba(0, 0, 0, 0.04);
		cursor: pointer;
		overflow: hidden;
		position: relative;
	}
	.action-card-img {
		position: absolute;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;
	}
	.rental-card {
		background: linear-gradient(to bottom, #4F87FF 0%, #FFFFFF 120%);
	}
	.custom-card {
		background: linear-gradient(to bottom, #4f87ff -20%, #FFFFFF 120%);
	}
	.action-card-title {
		position: absolute;
		left: 0;
		right: 0;
		bottom: 14rpx;
		text-align: center;
		font-size: 30rpx;
		font-weight: 800;
		color: #1F2937;
	}
	.bottom-safe { height: 20rpx; }
	/* ===== 下滑渐显的白色顶部框架(logo) ===== */
	.top-scroll-header {
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		z-index: 900;
		/* 白条固定高度(安全区+135rpx), 高度与logo解耦——logo再大也不会撑高 */
		height: calc(env(safe-area-inset-top) + 149rpx);
		background-color: #ffffff;
		display: flex;
		align-items: center;
		justify-content: center;
		box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.06);
		pointer-events: none;
	}
	.top-scroll-logo {
		width: 240rpx;
		height: 88rpx;
		display: block;
		/* 单纯把图片往下平移, 不占布局, 白条高度不受影响 */
		transform: translateY(40rpx);
	}
	::-webkit-scrollbar { width: 4rpx; height: 4rpx; background: transparent; }
	::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 8rpx; }
	::-webkit-scrollbar-track { background: transparent; }

	/* ===== 定制弹窗样式 ===== */
	.custom-modal-mask {
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.5);
		z-index: 999;
		display: flex;
		align-items: center;
		justify-content: center;
		animation: fadeIn 0.25s ease;
	}
	.custom-modal {
		width: 85%;
		max-height: 80vh;
		background: #ffffff;
		border-radius: 32rpx;
		padding: 32rpx 32rpx 28rpx;
		position: relative;
		animation: scaleIn 0.3s ease;
	}
	.modal-close {
		position: absolute;
		top: 16rpx;
		right: 24rpx;
		font-size: 36rpx;
		color: #9aaab8;
		padding: 8rpx;
		z-index: 10;
	}
	.modal-header {
		text-align: center;
		margin-bottom: 24rpx;
		padding-right: 40rpx;
	}
	.modal-icon { font-size: 48rpx; display: block; }
	.modal-title {
		font-size: 34rpx;
		font-weight: 700;
		color: #1a2a3a;
		display: block;
		margin-top: 8rpx;
	}
	.modal-subtitle {
		font-size: 24rpx;
		color: #8a9aa8;
		display: block;
		margin-top: 6rpx;
	}
	.modal-body {
		max-height: 50vh;
		overflow-y: auto;
		padding-right: 4rpx;
	}
	.form-item { margin-bottom: 20rpx; }
	.form-label {
		font-size: 26rpx;
		font-weight: 500;
		color: #1a2a3a;
		display: block;
		margin-bottom: 8rpx;
	}
	.required { color: #ef4444; }
	.optional { color: #9aaab8; font-weight: 400; font-size: 22rpx; }
	.form-input {
		width: 100%;
		height: 76rpx;
		padding: 0 20rpx;
		border-radius: 16rpx;
		font-size: 26rpx;
		color: #1a2a3a;
		background: #f5f7fa;
		border: 1rpx solid #e8edf3;
		box-sizing: border-box;
	}
	.form-input:focus { border-color: #4291F6; }
	.identity-group {
		display: flex;
		gap: 16rpx;
	}
	.identity-tag {
		flex: 1;
		text-align: center;
		padding: 16rpx 0;
		border-radius: 40rpx;
		font-size: 28rpx;
		color: #6a7a8a;
		background: #f5f7fa;
		border: 2rpx solid #e8edf3;
		transition: all 0.2s;
	}
	.identity-tag.active {
		background: rgba(66, 145, 246, 0.12);
		color: #4291F6;
		border-color: #4291F6;
		font-weight: 500;
	}
	.modal-footer {
		display: flex;
		gap: 16rpx;
		margin-top: 24rpx;
	}
	.modal-footer .btn-secondary,
	.modal-footer .btn-primary {
		flex: 1;
		height: 80rpx;
		border-radius: 40rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 28rpx;
		font-weight: 500;
	}
	.btn-secondary {
		background: #f5f7fa;
		color: #6a7a8a;
	}
	.btn-secondary:active { background: #e8edf3; }
	.btn-primary {
		background: linear-gradient(135deg, #4291F6, #6EB5FF);
		color: #fff;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.25);
	}
	.btn-primary:active { opacity: 0.8; }
	@keyframes fadeIn {
		from { opacity: 0; }
		to { opacity: 1; }
	}
	@keyframes scaleIn {
		from { transform: scale(0.9); opacity: 0; }
		to { transform: scale(1); opacity: 1; }
	}
</style>