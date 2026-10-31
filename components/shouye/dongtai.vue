<template>
  <view class="feed-panel">
    <!--
      G1-11：动态资讯预览只消费 G1-10 公开 DTO。
      加载/错误/空三态分开；空态是诚实的「暂无资讯」，绝不用本地样例补位。
      G1 不提供点赞/评论/收藏/报名等写交互。
    -->
    <view class="feed-head">
      <text class="feed-title">最新资讯</text>
      <text class="more-link" role="button" tabindex="0" @click="goAll">查看全部 →</text>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '📡'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && feedList.length === 0"
      empty-icon="📰"
      empty-text="暂无资讯"
      empty-hint="资讯内容尚未开放，请以官方发布为准。"
      compact
      @retry="load"
    >
      <view class="feed-list">
        <view
          v-for="item in feedList"
          :key="item.slug"
          class="feed-card"
          role="button"
          tabindex="0"
          @click="openDetail(item)"
          @keyup.enter="openDetail(item)"
        >
          <view class="feed-top">
            <text class="feed-type">{{ typeOf(item) }}</text>
            <text class="feed-date">{{ dateOf(item) }}</text>
          </view>
          <text class="feed-name">{{ item.title }}</text>
          <text v-if="item.summary" class="feed-text">{{ item.summary }}</text>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { contentApi } from '@/utils/api'
import {
  normalizeContentPage,
  errorState,
  contentTypeLabel,
  formatDateShort,
  buildSharePath,
} from '@/utils/ecosystem-public'

const PREVIEW_SIZE = 5

export default {
  name: 'Dongtai',
  components: { StateView },
  props: {
    // 首页生态中心里只在切到「动态资讯」Tab 时才加载;独立使用(不传)保持原有立即加载
    active: { type: Boolean, default: true },
  },
  data() {
    return {
      feedList: [],
      loaded: false,
      loading: false,
      errorMessage: '',
      errorInfo: null,
      requestSeq: 0,
    }
  },
  watch: {
    active(value) { if (value) this.loadIfNeeded() },
  },
  mounted() {
    if (this.active) this.loadIfNeeded()
  },
  methods: {
    loadIfNeeded() {
      if (this.loaded || this.loading) return
      this.load()
    },
    async load() {
      const seq = ++this.requestSeq
      this.loading = true
      this.errorMessage = ''
      this.errorInfo = null

      try {
        const response = await contentApi.getPosts({ page: 1, per_page: PREVIEW_SIZE })
        if (seq !== this.requestSeq) return

        const page = normalizeContentPage(response, 1)
        this.feedList = page.items
        this.loaded = true
      } catch (error) {
        if (seq !== this.requestSeq) return
        const info = errorState(error)
        this.feedList = []
        this.errorMessage = info.message
        this.errorInfo = info
      } finally {
        if (seq === this.requestSeq) this.loading = false
      }
    },
    goAll() {
      uni.navigateTo({ url: '/pages/ecosystem/content' })
    },
    openDetail(item) {
      const path = buildSharePath('content', item.slug)
      if (path) uni.navigateTo({ url: path })
    },
    typeOf(item) {
      return contentTypeLabel(item.type)
    },
    dateOf(item) {
      return formatDateShort(item.firstPublishedAt || item.publishedAt)
    },
  },
}
</script>

<style scoped>
.feed-panel {
  padding-bottom: 10rpx;
}
.feed-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8rpx 2rpx 16rpx;
}
.feed-title {
  font-size: 26rpx;
  font-weight: 700;
  color: #102F6E;
}
.more-link {
  font-size: 24rpx;
  color: #165DFF;
  font-weight: 600;
}
.more-link:focus-visible,
.feed-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.feed-list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.feed-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 20rpx;
  box-shadow: 0 2rpx 12rpx rgba(16, 47, 110, 0.05);
  border: 1rpx solid #E5EAF3;
}
.feed-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8rpx;
}
.feed-type {
  padding: 2rpx 14rpx;
  border-radius: 8rpx;
  background: #EEF4FF;
  color: #102F6E;
  font-size: 20rpx;
  font-weight: 600;
}
.feed-date {
  font-size: 21rpx;
  color: #8A9AA8;
}
.feed-name {
  font-size: 27rpx;
  font-weight: 700;
  line-height: 1.5;
  color: #102F6E;
}
.feed-text {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #475569;
  line-height: 1.6;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
