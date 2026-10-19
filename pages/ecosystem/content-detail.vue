<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">资讯详情</text>
      <!-- #ifdef H5 -->
      <view class="nav-share" role="button" tabindex="0" @click="copyLink">复制链接</view>
      <!-- #endif -->
      <!-- #ifndef H5 -->
      <view class="nav-side"></view>
      <!-- #endif -->
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '📡'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && !post"
      empty-icon="📰"
      empty-text="资讯不存在或未公开"
      empty-hint="请返回列表查看已发布的资讯。"
      @retry="load"
    >
      <view v-if="post" class="detail">
        <view v-if="coverUrl" class="hero">
          <image class="hero-img" :src="coverUrl" mode="aspectFill"></image>
        </view>

        <view class="detail-body">
          <view class="title-row">
            <text class="detail-type">{{ typeLabel }}</text>
            <text class="detail-title">{{ post.title }}</text>
          </view>
          <text v-if="post.summary" class="detail-summary">{{ post.summary }}</text>

          <view class="meta-row">
            <text v-if="authorText" class="meta-item">{{ authorText }}</text>
            <text class="meta-item">{{ publishedText }}</text>
          </view>

          <view v-if="post.correctionNote" class="correction">
            <text class="correction-title">更正说明</text>
            <text class="correction-text">{{ post.correctionNote }}</text>
          </view>

          <view v-if="bodyHtml" class="section">
            <rich-text class="rich-body" :nodes="bodyHtml"></rich-text>
          </view>

          <view v-if="sources.length" class="section">
            <text class="section-title">信息来源</text>
            <view v-for="(source, index) in sources" :key="index" class="source-item">
              <text class="source-name">{{ source.name }}</text>
              <text v-if="source.url" class="source-url">{{ source.url }}</text>
              <text class="source-meta">
                <text v-if="source.rightsBasisText">{{ source.rightsBasisText }}</text>
                <text v-if="source.verifiedText"> · 核验于 {{ source.verifiedText }}</text>
              </text>
            </view>
          </view>

          <view class="publish-line">
            <text>公开时间：{{ publishedText }}</text>
            <text v-if="firstPublishedText && firstPublishedText !== publishedText">首次公开：{{ firstPublishedText }}</text>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { contentApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import {
  normalizeContent,
  errorState,
  contentTypeLabel,
  formatDateTime,
  formatDateISO,
  rightsBasisLabel,
  resolveMediaUrl,
  pickCover,
  renderRichHtml,
  buildSharePath,
  copyShareLink,
  resolveShareBase,
} from '@/utils/ecosystem-public'

/**
 * 分享基址必须是**当前 H5 站点**，不是 API 的 BASE_URL。
 * 小程序没有可复制的网页地址，返回空串（分享仍走 buildSharePath）。
 */
function h5ShareBase() {
  // #ifdef H5
  return resolveShareBase(window.location)
  // #endif
  // #ifndef H5
  return ''
  // #endif
}

export default {
  name: 'EcosystemContentDetail',
  components: { StateView },
  data() {
    return {
      slug: '',
      post: null,
      loading: false,
      errorMessage: '',
      errorInfo: null,
      requestSeq: 0,
    }
  },
  computed: {
    typeLabel() {
      return this.post ? contentTypeLabel(this.post.type) : '资讯'
    },
    authorText() {
      if (!this.post) return ''
      return [this.post.author.name, this.post.author.org].filter(Boolean).join(' · ')
    },
    publishedText() {
      return this.post ? formatDateTime(this.post.publishedAt) : ''
    },
    firstPublishedText() {
      return this.post ? formatDateTime(this.post.firstPublishedAt) : ''
    },
    coverUrl() {
      if (!this.post) return ''
      const cover = pickCover(this.post.media)
      return cover ? resolveMediaUrl(cover.url, BASE_URL) : ''
    },
    bodyHtml() {
      if (!this.post) return ''
      return renderRichHtml(this.post.bodyHtml, this.post.media, BASE_URL)
    },
    sources() {
      if (!this.post) return []
      return this.post.sources.map((source) => ({
        ...source,
        rightsBasisText: rightsBasisLabel(source.rightsBasis),
        verifiedText: formatDateISO(source.verifiedAt),
      }))
    },
    sharePath() {
      return this.post ? buildSharePath('content', this.post.slug) : ''
    },
  },
  onLoad(query) {
    const slug = typeof (query && query.slug) === 'string' ? query.slug.trim() : ''

    if (!slug) {
      this.applyError(errorState({ statusCode: 404 }))
      return
    }

    this.slug = slug
    this.load()
  },
  onPullDownRefresh() {
    this.load().finally(() => uni.stopPullDownRefresh())
  },
  onShareAppMessage() {
    if (this.post && this.sharePath) {
      return { title: this.post.title, path: this.sharePath }
    }
    return { title: '召唤机甲 · 动态资讯', path: '/pages/ecosystem/content' }
  },
  methods: {
    goBack() {
      uni.navigateBack({
        delta: 1,
        fail: () => uni.switchTab({ url: '/pages/home/index' }),
      })
    },
    applyError(error) {
      const info = errorState(error)
      this.post = null
      this.loading = false
      this.errorMessage = info.message
      this.errorInfo = info
    },
    async load() {
      if (!this.slug) return

      const seq = ++this.requestSeq
      this.loading = true
      this.errorMessage = ''
      this.errorInfo = null

      try {
        // 详情只按 slug 重新请求：支持直达/刷新，不依赖列表内存对象。
        const response = await contentApi.getPost(this.slug)
        if (seq !== this.requestSeq) return

        const post = normalizeContent(response && response.data)
        if (!post) throw new Error('资讯详情格式异常')

        this.post = post
      } catch (error) {
        if (seq !== this.requestSeq) return
        this.applyError(error)
      } finally {
        if (seq === this.requestSeq) this.loading = false
      }
    },
    copyLink() {
      const path = this.sharePath
      if (!path) {
        uni.showToast({ title: '内容尚未公开，无法分享', icon: 'none' })
        return
      }

      copyShareLink(path, h5ShareBase(), (text) => new Promise((resolve, reject) => {
        uni.setClipboardData({ data: text, success: resolve, fail: reject })
      })).then((result) => {
        uni.showToast({ title: result.message, icon: result.ok ? 'success' : 'none' })
      })
    },
  },
}
</script>

<style scoped>
.eco-page {
  min-height: 100vh;
  background: #F5F7FB;
  padding-bottom: 40rpx;
  box-sizing: border-box;
}
.eco-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 88rpx 24rpx 22rpx;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
  color: #FFFFFF;
}
.nav-back,
.nav-side {
  width: 96rpx;
  height: 60rpx;
  line-height: 52rpx;
  font-size: 48rpx;
  font-weight: 300;
}
.nav-back:focus-visible,
.nav-share:focus-visible {
  outline: 3rpx solid #FFFFFF;
  border-radius: 8rpx;
}
.nav-title {
  flex: 1;
  text-align: center;
  font-size: 34rpx;
  font-weight: 600;
  letter-spacing: 2rpx;
}
.nav-share {
  width: 96rpx;
  text-align: right;
  font-size: 24rpx;
  color: #FFFFFF;
}
.detail { padding: 24rpx; }
.hero {
  height: 340rpx;
  border-radius: 20rpx;
  overflow: hidden;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
}
.hero-img { width: 100%; height: 100%; }
.detail-body {
  margin-top: 24rpx;
  background: #FFFFFF;
  border: 1rpx solid #E5EAF3;
  border-radius: 20rpx;
  padding: 28rpx 26rpx 34rpx;
}
.title-row { display: flex; flex-direction: column; }
.detail-type {
  align-self: flex-start;
  padding: 4rpx 16rpx;
  margin-bottom: 12rpx;
  border-radius: 8rpx;
  background: #EEF4FF;
  color: #102F6E;
  font-size: 22rpx;
  font-weight: 600;
}
.detail-title {
  font-size: 40rpx;
  font-weight: 700;
  line-height: 1.4;
  color: #102F6E;
}
.detail-summary {
  display: block;
  margin-top: 14rpx;
  font-size: 26rpx;
  line-height: 1.7;
  color: #475569;
}
.meta-row {
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx 24rpx;
  margin-top: 18rpx;
  padding-bottom: 18rpx;
  border-bottom: 1rpx solid #E5EAF3;
}
.meta-item {
  font-size: 23rpx;
  color: #7A8AA3;
}
.correction {
  margin-top: 22rpx;
  padding: 18rpx 20rpx;
  border-radius: 14rpx;
  background: #FFF8EC;
  border: 1rpx solid #F2D8A8;
}
.correction-title {
  display: block;
  font-size: 24rpx;
  font-weight: 700;
  color: #92400E;
  margin-bottom: 6rpx;
}
.correction-text {
  font-size: 24rpx;
  line-height: 1.6;
  color: #7C4A03;
}
.section { margin-top: 30rpx; }
.section-title {
  display: block;
  margin-bottom: 14rpx;
  padding-left: 16rpx;
  border-left: 6rpx solid #165DFF;
  font-size: 29rpx;
  font-weight: 700;
  color: #102F6E;
}
.rich-body {
  font-size: 27rpx;
  line-height: 1.8;
  color: #334155;
  word-break: break-word;
}
.source-item {
  display: flex;
  flex-direction: column;
  padding: 16rpx 18rpx;
  margin-bottom: 12rpx;
  border-radius: 12rpx;
  background: #F8FAFF;
  border: 1rpx solid #E5EAF3;
}
.source-name {
  font-size: 25rpx;
  font-weight: 600;
  color: #102F6E;
}
.source-url {
  margin-top: 4rpx;
  font-size: 22rpx;
  color: #5B6B85;
  word-break: break-all;
}
.source-meta {
  margin-top: 4rpx;
  font-size: 21rpx;
  color: #8A9AA8;
}
.publish-line {
  display: flex;
  flex-direction: column;
  gap: 6rpx;
  margin-top: 30rpx;
  padding-top: 18rpx;
  border-top: 1rpx solid #E5EAF3;
  font-size: 22rpx;
  color: #8A9AA8;
}
</style>
