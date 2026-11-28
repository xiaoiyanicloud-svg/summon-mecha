<template>
  <view class="feed-panel">
    <!--
      G1-11：动态资讯预览只消费 G1-10 公开 DTO。
      加载/错误/空三态分开；空态是诚实的「暂无资讯」，绝不用本地样例补位。
      G1 不提供点赞/评论/收藏/报名等写交互。
    -->
    <view class="feed-head">
      <text class="feed-title">最新资讯</text>
      <text class="more-link" role="button" tabindex="0" @click="goAll">查看全部 ›</text>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '/static/icons/mine/state-error.png'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && feedList.length === 0"
      empty-icon="/static/icons/mine/empty-news.png"
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
          <view class="feed-row">
            <view class="feed-main">
              <text class="feed-name">{{ item.title }}</text>
              <text v-if="item.summary" class="feed-text">{{ item.summary }}</text>
              <view class="feed-foot">
                <text class="feed-type" :class="'tp-' + (item.type || 'news')">{{ typeOf(item) }}</text>
                <text v-if="authorOf(item)" class="feed-author">{{ authorOf(item) }}</text>
                <text v-if="dateOf(item)" class="feed-date sm-mono">{{ dateOf(item) }}</text>
              </view>
            </view>
            <image v-if="coverOf(item)" class="feed-thumb" :src="coverOf(item)" mode="aspectFill"></image>
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
  normalizeContentPage,
  errorState,
  contentTypeLabel,
  formatDateShort,
  resolveMediaUrl,
  pickCover,
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
    coverOf(item) {
      const cover = pickCover(item && item.media)
      return cover ? resolveMediaUrl(cover.url, BASE_URL) : ''
    },
    typeOf(item) {
      return contentTypeLabel(item.type)
    },
    dateOf(item) {
      return formatDateShort(item.firstPublishedAt || item.publishedAt)
    },
    authorOf(item) {
      if (!item || !item.author) return ''
      return item.author.org || item.author.name || ''
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
  padding: 6rpx 2rpx 14rpx;
}
.feed-title {
  font-size: 27rpx;
  font-weight: 600;
  color: #0F172A;
}
.more-link {
  font-size: 24rpx;
  color: #165DFF;
  font-weight: 500;
}
.more-link:focus-visible,
.feed-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.feed-list {
  display: flex;
  flex-direction: column;
  gap: 14rpx;
}
.feed-card {
  background: #FFFFFF;
  border-radius: 16rpx;
  padding: 20rpx;
  border: 1rpx solid #E5EAF2;
  transition: background-color 0.15s ease;
}
.feed-card:active {
  background: #F8FAFC;
}
.feed-row {
  display: flex;
  align-items: flex-start;
  gap: 18rpx;
}
.feed-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.feed-name {
  font-size: 28rpx;
  font-weight: 600;
  line-height: 1.45;
  color: #0F172A;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.feed-text {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #475569;
  line-height: 1.55;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.feed-foot {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 12rpx;
}
.feed-type {
  padding: 2rpx 10rpx;
  border-radius: 6rpx;
  background: #F1F5F9;
  color: #475569;
  font-size: 20rpx;
  font-weight: 500;
}
.feed-type.tp-announcement {
  background: #FFFBEB;
  color: #B45309;
}
.feed-type.tp-competition_report {
  background: #EEF4FF;
  color: #165DFF;
}
.feed-author {
  font-size: 21rpx;
  color: #64748B;
  max-width: 240rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.feed-date {
  font-size: 21rpx;
  color: #94A3B8;
  margin-left: auto;
}
.feed-thumb {
  width: 164rpx;
  height: 116rpx;
  border-radius: 10rpx;
  flex-shrink: 0;
  background: #F1F5F9;
}
</style>
