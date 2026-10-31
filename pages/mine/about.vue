<template>
	<view class="about-page">
		<!-- 导航栏 -->
		<nav-bar title="关于" />

		<scroll-view class="scroll-content" scroll-y="true" show-scrollbar="false">
			<!-- 品牌 -->
			<view class="brand">
				<view class="brand-logo">
					<text class="brand-logo-text">机甲</text>
				</view>
				<text class="brand-name">召唤机甲</text>
				<text class="brand-version">{{ appVersion }}</text>
				<text class="brand-slogan">机器人技能供需平台 —— 技能上架、下发到设备、售后可追溯。</text>
			</view>

			<!-- 条款 -->
			<view class="about-card">
				<view class="about-item" @click="goLegal('privacy-policy')">
					<view class="about-left">
						<text class="about-icon">🔒</text>
						<text class="about-label">隐私政策</text>
					</view>
					<text class="about-arrow">›</text>
				</view>
				<view class="about-divider"></view>
				<view class="about-item" @click="goLegal('terms-of-use')">
					<view class="about-left">
						<text class="about-icon">📄</text>
						<text class="about-label">用户协议</text>
					</view>
					<text class="about-arrow">›</text>
				</view>
				<view class="about-divider"></view>
				<view class="about-item" @click="goCustomerService">
					<view class="about-left">
						<text class="about-icon">💬</text>
						<text class="about-label">客服中心</text>
					</view>
					<text class="about-arrow">›</text>
				</view>
			</view>

			<!-- 合规状态 —— 如实写,并且**刻意不放空的备案号占位** -->
			<view class="about-card">
				<view class="about-notice">
					<text class="about-notice-title">合规资质</text>
					<text class="about-notice-body">本平台尚未取得 ICP 备案与增值电信业务经营许可等资质,因此本页不展示备案号。展示一个没有真实来源的号码等同于伪造。取得后会在本页如实更新。</text>
				</view>
			</view>

			<view class="about-tail">
				<text class="about-tail-text">{{ appName }} {{ appVersion }}</text>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { APP_VERSION } from '@/utils/config'
import NavBar from '@/components/NavBar.vue'

/**
 * 关于页。
 *
 * ⚠ 两个刻意的"没有":
 *
 * 1. **没有备案号占位**。合规资质尚未取得(见 docs/skill-remaining-closure.md 的 E2/E3),
 *    这里放一个空的备案号容器或一串编造的号码 —— 前者会被读成"已合规",后者是伪造。
 *    所以显式写明"没有"和"为什么没有"。
 *
 * 2. **没有版权声明**。署名主体尚未确定,写一个不存在的法律主体比不写更糟。
 *
 * 版本号取自 utils/config.js 的 APP_VERSION —— 与「我的 → 设置」同一出处,
 * 不再各写一份(两处各写一遍必然分叉)。
 */
export default {
	components: { NavBar },
	data() {
		return {
			appName: '召唤机甲',
			appVersion: APP_VERSION,
		}
	},
	methods: {
		goLegal(doc) { uni.navigateTo({ url: `/pages/mine/legal?doc=${doc}` }) },
		goCustomerService() { uni.navigateTo({ url: '/pages/mine/tickets' }) },
	},
}
</script>

<style scoped>
.about-page { min-height: 100vh; background: #f5f6f8; }
.scroll-content { height: calc(100vh - 88rpx); }

.brand { display: flex; flex-direction: column; align-items: center; padding: 60rpx 40rpx 40rpx; }
.brand-logo { display: flex; align-items: center; justify-content: center; width: 128rpx; height: 128rpx; border-radius: 32rpx; background: #ff6b00; }
.brand-logo-text { font-size: 40rpx; font-weight: 700; color: #fff; }
.brand-name { margin-top: 24rpx; font-size: 36rpx; font-weight: 600; color: #222; }
.brand-version { margin-top: 8rpx; font-size: 24rpx; color: #aaa; }
.brand-slogan { margin-top: 20rpx; font-size: 24rpx; color: #999; line-height: 1.6; text-align: center; }

.about-card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; overflow: hidden; }
.about-item { display: flex; align-items: center; justify-content: space-between; padding: 28rpx; }
.about-left { display: flex; align-items: center; }
.about-icon { font-size: 34rpx; width: 60rpx; }
.about-label { font-size: 28rpx; color: #333; }
.about-arrow { font-size: 36rpx; color: #ccc; }
.about-divider { height: 1rpx; margin-left: 88rpx; background: #f0f0f0; }

.about-notice { padding: 28rpx; }
.about-notice-title { display: block; font-size: 28rpx; font-weight: 600; color: #333; }
.about-notice-body { display: block; margin-top: 16rpx; font-size: 24rpx; color: #999; line-height: 1.7; }

.about-tail { padding: 20rpx 24rpx; }
.about-tail-text { display: block; font-size: 22rpx; color: #bbb; text-align: center; }
</style>
