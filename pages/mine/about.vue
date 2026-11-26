<template>
	<view class="about-page">
		<nav-bar title="关于召唤机甲" />

		<scroll-view class="scroll-content" scroll-y>
			<view class="brand-card">
				<image class="brand-logo" src="/static/logo.png" mode="aspectFit" />
				<text class="brand-name">{{ filing.app_name || '召唤机甲' }}</text>
				<text class="brand-version sm-mono">版本 {{ appVersion }}</text>
				<text class="brand-slogan">机器人租售、买断技能交付、场景定制履约与生态赛事综合运营平台</text>
			</view>

			<view class="menu-card">
				<view class="menu-item" @tap="goLegal('terms-of-use')">
					<view class="mi-left">
						<SmIcon name="doc-text" :size="36" />
						<text class="mi-label">用户服务协议</text>
					</view>
					<SmIcon name="chevron-right" :size="24" />
				</view>
				<view class="menu-item" @tap="goLegal('privacy-policy')">
					<view class="mi-left">
						<SmIcon name="shield-lock" :size="36" />
						<text class="mi-label">隐私保护政策</text>
					</view>
					<SmIcon name="chevron-right" :size="24" />
				</view>
				<view class="menu-item last" @tap="goCustomerService">
					<view class="mi-left">
						<SmIcon name="service" :size="36" />
						<text class="mi-label">联系平台客服中心</text>
					</view>
					<SmIcon name="chevron-right" :size="24" />
				</view>
			</view>

			<view class="compliance-card">
				<text class="comp-title">主体与电信合规公示</text>
				<template v-if="filing.has_filings">
					<view v-if="filing.operator_entity" class="comp-row"><text class="ck">运营主体</text><text class="cv">{{ filing.operator_entity }}</text></view>
					<view v-if="filing.icp_filing_no" class="comp-row"><text class="ck">ICP 备案号</text><text class="cv sm-mono">{{ filing.icp_filing_no }}</text></view>
					<view v-if="filing.edi_license_no" class="comp-row"><text class="ck">经营许可证</text><text class="cv sm-mono">{{ filing.edi_license_no }}</text></view>
					<view v-if="filing.public_security_no" class="comp-row"><text class="ck">网安备案号</text><text class="cv sm-mono">{{ filing.public_security_no }}</text></view>
				</template>
				<text v-else class="comp-honest">
					{{ filing.honest_statement || '本平台尚未取得 ICP 备案与增值电信业务经营许可等资质，因此暂不展示备案编号；取得真实资质后将在此动态公示。' }}
				</text>
			</view>

			<view class="tail sm-mono">Summon Mecha · {{ appVersion }}</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import SmIcon from '@/components/SmIcon.vue'
import { cmsApi } from '@/utils/api'
import { APP_VERSION } from '@/utils/config'

export default {
	components: { NavBar, SmIcon },
	data() {
		return {
			appVersion: APP_VERSION,
			filing: {
				app_name: '召唤机甲',
				has_filings: false,
				honest_statement: '',
			},
		}
	},
	onLoad() {
		cmsApi.complianceFilings()
			.then((res) => {
				if (res && res.data) this.filing = res.data
			})
			.catch(() => {})
	},
	methods: {
		goLegal(doc) {
			uni.navigateTo({ url: `/pages/mine/legal?doc=${doc}` })
		},
		goCustomerService() {
			uni.navigateTo({ url: '/pages/mine/tickets' })
		},
	},
}
</script>

<style scoped>
.about-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 24rpx 28rpx; box-sizing: border-box; }
.brand-card { background: #FFFFFF; border-radius: 24rpx; padding: 48rpx 36rpx 40rpx; margin-bottom: 22rpx; border: 1rpx solid #E5EAF2; display: flex; flex-direction: column; align-items: center; }
.brand-logo { width: 132rpx; height: 132rpx; border-radius: 28rpx; background: #F8FAFC; padding: 12rpx; box-sizing: border-box; }
.brand-name { margin-top: 22rpx; font-size: 36rpx; font-weight: 700; color: #0F172A; }
.brand-version { margin-top: 6rpx; font-size: 24rpx; color: #64748B; }
.brand-slogan { margin-top: 18rpx; font-size: 25rpx; color: #475569; line-height: 1.6; text-align: center; }
.menu-card { background: #FFFFFF; border-radius: 24rpx; padding: 8rpx 28rpx; margin-bottom: 22rpx; border: 1rpx solid #E5EAF2; }
.menu-item { display: flex; align-items: center; justify-content: space-between; padding: 26rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.menu-item.last { border-bottom: none; }
.mi-left { display: flex; align-items: center; gap: 18rpx; }
.mi-label { font-size: 29rpx; font-weight: 500; color: #0F172A; }
.compliance-card { background: #FFFFFF; border-radius: 24rpx; padding: 28rpx; border: 1rpx solid #E5EAF2; }
.comp-title { display: block; font-size: 28rpx; font-weight: 600; color: #0F172A; margin-bottom: 14rpx; }
.comp-row { display: flex; justify-content: space-between; gap: 20rpx; padding: 10rpx 0; font-size: 25rpx; }
.ck { color: #64748B; }
.cv { color: #0F172A; font-weight: 500; text-align: right; }
.comp-honest { display: block; font-size: 24rpx; color: #64748B; line-height: 1.65; }
.tail { text-align: center; font-size: 22rpx; color: #94A3B8; margin-top: 32rpx; }
</style>
