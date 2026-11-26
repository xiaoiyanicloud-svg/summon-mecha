<template>
	<view class="legal-page">
		<nav-bar :title="docTitle" />

		<scroll-view class="scroll-content" scroll-y>
			<state-view v-if="loading" preset="loading" loading-text="正在读取最新条款正文..." />
			<state-view v-else-if="loadError" preset="network" :title="loadError" @retry="refresh" />
			<state-view
				v-else-if="!html"
				icon="doc-text"
				title="该条款尚未发布定稿正文"
				hint="正文与网页端共用同一后台内容源，法务审定发布后将自动同步展示"
			/>
			<view v-else>
				<view class="card">
					<rich-text class="legal-body" :nodes="html" />
				</view>
				<view class="legal-foot">
					<text class="legal-foot-text">本页正文实时同步自平台内容管理系统，与网页端展示保持同一法务版本。</text>
				</view>
			</view>
			<view style="height: 60rpx;"></view>
		</scroll-view>
	</view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { cmsApi } from '@/utils/api'

const DOCS = {
	'privacy-policy': '隐私保护政策',
	'terms-of-use': '用户服务协议',
}

export default {
	components: { NavBar, StateView },
	data() {
		return {
			slug: '',
			docTitle: '合规条款',
			loading: true,
			loadError: '',
			html: '',
		}
	},
	onLoad(options) {
		this.slug = (options && (options.doc || options.slug)) || 'terms-of-use'
		this.docTitle = DOCS[this.slug] || '合规条款'
		this.refresh()
	},
	methods: {
		async refresh() {
			if (!DOCS[this.slug]) {
				this.loading = false
				this.loadError = '未找到对应的条款文档标识'
				return
			}
			this.loading = true
			this.loadError = ''
			try {
				const res = await cmsApi.page(this.slug)
				const page = (res && res.data) ? res.data : res
				this.html = (page && page.html_content) ? page.html_content : ''
				if (page && page.title) this.docTitle = page.title
			} catch (e) {
				const code = e && (e.statusCode || (e.data && e.data.status))
				this.loadError = code === 404 ? '平台上暂无该条款页面' : '网络或服务暂时不可用，请稍后重试'
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.legal-page { min-height: 100vh; background: #F4F7FB; display: flex; flex-direction: column; }
.scroll-content { flex: 1; padding: 24rpx 28rpx; box-sizing: border-box; }
.card { background: #FFFFFF; border-radius: 24rpx; padding: 32rpx; border: 1rpx solid #E5EAF2; }
.legal-body { font-size: 28rpx; color: #1E293B; line-height: 1.75; }
.legal-foot { padding: 22rpx 8rpx; }
.legal-foot-text { display: block; font-size: 23rpx; color: #64748B; line-height: 1.55; }
</style>
