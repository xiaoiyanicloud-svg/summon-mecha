<template>
	<view class="legal-page">
		<!-- 导航栏 -->
		<view class="nav-bar">
			<view class="nav-back" @click="goBack">
				<text class="back-icon">‹</text>
			</view>
			<text class="nav-title">{{ docTitle }}</text>
			<view class="nav-placeholder"></view>
		</view>

		<scroll-view class="scroll-content" scroll-y="true" show-scrollbar="false">
			<view v-if="loading" class="empty-state">
				<text class="empty-text">加载中…</text>
			</view>

			<!-- 取不到就走这里。⚠ 这一支必须存在:否则接口挂掉时页面是一片空白,
			     而空白页会被读成「这个平台没有隐私政策」—— 那是与事实相反的结论。 -->
			<view v-else-if="loadError" class="empty-state">
				<text class="empty-icon">📡</text>
				<text class="empty-text">正文没能取到</text>
				<text class="empty-hint">{{ loadError }}</text>
				<view class="primary-btn" @click="refresh">重试</view>
			</view>

			<!-- 页面存在但正文是空的。同样是「取到了但没有内容」,不是「没有这一页」 -->
			<view v-else-if="!html" class="empty-state">
				<text class="empty-icon">📄</text>
				<text class="empty-text">这一页还没有正文</text>
				<text class="empty-hint">正文由平台在后台维护,发布后这里会自动显示最新版本。</text>
			</view>

			<view v-else>
				<view class="card">
					<rich-text class="legal-body" :nodes="html"></rich-text>
				</view>
				<view class="legal-foot">
					<text class="legal-foot-text">正文取自平台后台,与网页版是同一份内容 —— 后台改了,这里会跟着变。</text>
				</view>
			</view>

			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import { cmsApi } from '@/utils/api'

/**
 * 隐私政策 / 用户协议 —— 同一套壳子,靠 `?doc=` 区分。
 *
 * ⚠ 正文**不写死在这个文件里**,而是读后端 CMS(与网页端 `/page/{slug}` 同源)。
 *   原因:写死会立刻产生第二个"协议正文出处"。律师定稿后改了后台,
 *   网页端更新了、App 还显示旧条款 —— 而没有任何东西能发现这件事。
 *
 * ⚠ 当前这两页的正文是**诚实的「尚未定稿」说明**,不是条款(律师未定稿)。
 *   本文件**不得**为它们补任何"看起来像条款"的兜底文案 ——
 *   兜底文案就是伪造条款,比空白更糟。
 */

/** 允许的 doc → 标题。白名单式:未登记的一律不请求,也不假装存在。 */
const DOCS = {
	'privacy-policy': '隐私政策',
	'terms-of-use': '用户协议',
}

export default {
	data() {
		return {
			slug: '',
			docTitle: '条款',
			loading: true,
			loadError: '',
			html: '',
		}
	},
	onLoad(options) {
		this.slug = (options && options.doc) || ''
		this.docTitle = DOCS[this.slug] || '条款'
		this.refresh()
	},
	methods: {
		goBack() { uni.navigateBack({ delta: 1 }) },

		async refresh() {
			// 白名单外的 slug:不发请求。请求了也只是把 404 变成一次无意义的往返。
			if (!DOCS[this.slug]) {
				this.loading = false
				this.loadError = '页面标识不正确。'
				return
			}

			this.loading = true
			this.loadError = ''
			try {
				const res = await cmsApi.page(this.slug)
				// 兼容两种外壳:res.data 是后端 JSON 的 data,已剥壳时本身就是它
				const page = (res && res.data) ? res.data : res

				this.html = (page && page.html_content) ? page.html_content : ''
				// 标题也以后台为准(DOCS 里的只是加载期间的占位与白名单)。
				// 两份各写一遍必然会分叉 —— 后台把 url_key 改了名,标题却还是旧的。
				if (page && page.title) this.docTitle = page.title
			} catch (e) {
				// ⚠ 如实区分「没有这一页」与「取不到」—— 两者对读者的含义完全不同
				const code = e && (e.statusCode || (e.data && e.data.status))
				this.loadError = code === 404
					? '平台上没有这一页。'
					: '网络或服务暂时不可用,请稍后再试。'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.legal-page { min-height: 100vh; background: #f5f6f8; }
.nav-bar { display: flex; align-items: center; justify-content: space-between; height: 88rpx; padding: 0 24rpx; background: #fff; }
.nav-back { width: 60rpx; }
.back-icon { font-size: 48rpx; color: #333; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-placeholder { width: 60rpx; }
.scroll-content { height: calc(100vh - 88rpx); }
.card { background: #fff; border-radius: 16rpx; margin: 20rpx 24rpx; padding: 28rpx; }
.legal-body { font-size: 28rpx; color: #333; line-height: 1.7; }
.legal-foot { padding: 0 24rpx; }
.legal-foot-text { display: block; font-size: 22rpx; color: #aaa; line-height: 1.6; }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding-top: 200rpx; }
.empty-icon { font-size: 80rpx; }
.empty-text { margin-top: 24rpx; font-size: 28rpx; color: #999; }
.empty-hint { margin-top: 16rpx; padding: 0 60rpx; font-size: 24rpx; color: #bbb; line-height: 1.6; text-align: center; }
.primary-btn { margin-top: 40rpx; padding: 18rpx 60rpx; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
</style>
