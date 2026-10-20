<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">动态资讯</text>
      <view class="nav-side"></view>
    </view>

    <view class="filter-wrap">
      <view class="search-row">
        <input
          v-model="filters.q"
          class="search-input"
          type="text"
          confirm-type="search"
          maxlength="50"
          placeholder="搜索标题 / 摘要"
          @confirm="applyFilters"
        />
        <input
          v-model="filters.category"
          class="search-input category-input"
          type="text"
          confirm-type="search"
          maxlength="64"
          placeholder="分类"
          @confirm="applyFilters"
        />
        <text class="search-btn" role="button" tabindex="0" @click="applyFilters">搜索</text>
      </view>

      <scroll-view class="chip-scroll" scroll-x="true" show-scrollbar="false">
        <view class="chip-row">
          <text
            class="chip"
            :class="{ on: datePreset === '' }"
            role="button"
            tabindex="0"
            @click="chooseDatePreset('')"
          >全部时间</text>
          <text
            v-for="option in datePresetOptions"
            :key="option.value"
            class="chip"
            :class="{ on: datePreset === option.value }"
            role="button"
            tabindex="0"
            @click="chooseDatePreset(option.value)"
          >{{ option.label }}</text>
        </view>
      </scroll-view>

      <scroll-view class="chip-scroll" scroll-x="true" show-scrollbar="false">
        <view class="chip-row">
          <text
            class="chip"
            :class="{ on: filters.type === '' }"
            role="button"
            tabindex="0"
            @click="chooseType('')"
          >全部类型</text>
          <text
            v-for="option in typeOptions"
            :key="option.value"
            class="chip"
            :class="{ on: filters.type === option.value }"
            role="button"
            tabindex="0"
            @click="chooseType(option.value)"
          >{{ option.label }}</text>
        </view>
      </scroll-view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '📡'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && posts.length === 0"
      empty-icon="📰"
      empty-text="暂无资讯"
      empty-hint="资讯发布后会在这里展示。"
      @retry="reload"
    >
      <view class="list">
        <view
          v-for="item in posts"
          :key="item.slug"
          class="post-card"
          role="button"
          tabindex="0"
          @click="openDetail(item)"
          @keyup.enter="openDetail(item)"
        >
          <view class="post-head">
            <text class="post-type">{{ typeOf(item) }}</text>
            <text class="post-date">{{ dateOf(item) }}</text>
          </view>
          <text class="post-title">{{ item.title }}</text>
          <text v-if="item.summary" class="post-summary">{{ item.summary }}</text>
          <text v-if="authorOf(item)" class="post-author">{{ authorOf(item) }}</text>
        </view>

        <view v-if="hasMore" class="load-more">
          <view
            class="load-btn"
            :class="{ disabled: loadingMore }"
            role="button"
            tabindex="0"
            @click="loadMore"
          >{{ loadingMore ? '加载中…' : '加载更多' }}</view>
        </view>
        <view v-else-if="posts.length" class="list-end">没有更多了</view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { contentApi } from '@/utils/api'
import {
  normalizeContentPage,
  appendUnique,
  errorState,
  contentTypeLabel,
  formatDateShort,
  buildSharePath,
} from '@/utils/ecosystem-public'

const PER_PAGE = 10

function isoDate(date) {
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function daysAgo(days) {
  const date = new Date()
  date.setDate(date.getDate() - days)
  return isoDate(date)
}

export default {
  name: 'EcosystemContent',
  components: { StateView },
  data() {
    return {
      filters: { q: '', type: '', category: '', published_from: '', published_to: '' },
      datePreset: '',
      datePresetOptions: [
        { value: '7d', label: '近7天' },
        { value: '30d', label: '近30天' },
      ],
      typeOptions: [
        { value: 'news', label: '资讯' },
        { value: 'announcement', label: '公告' },
        { value: 'case', label: '案例' },
        { value: 'competition_report', label: '赛事报道' },
      ],
      posts: [],
      loading: false,
      loadingMore: false,
      errorMessage: '',
      errorInfo: null,
      nextCursor: null,
      hasMore: false,
      requestSeq: 0,
    }
  },
  onLoad() {
    this.reload()
  },
  onPullDownRefresh() {
    this.reload().finally(() => uni.stopPullDownRefresh())
  },
  onReachBottom() {
    this.loadMore()
  },
  methods: {
    goBack() {
      uni.navigateBack({
        delta: 1,
        fail: () => uni.switchTab({ url: '/pages/home/index' }),
      })
    },
    chooseType(value) {
      if (this.filters.type === value) return
      this.filters.type = value
      this.reload()
    },
    applyFilters() {
      this.filters.q = String(this.filters.q || '').trim()
      this.filters.category = String(this.filters.category || '').trim()
      this.reload()
    },
    chooseDatePreset(value) {
      if (this.datePreset === value) return
      this.datePreset = value

      if (value === '7d' || value === '30d') {
        const days = value === '7d' ? 7 : 30
        this.filters.published_from = daysAgo(days)
        this.filters.published_to = isoDate(new Date())
      } else {
        this.filters.published_from = ''
        this.filters.published_to = ''
      }

      this.reload()
    },
    reload() {
      this.nextCursor = null
      return this.fetchPage(false)
    },
    async fetchPage(append) {
      const seq = ++this.requestSeq

      if (append) {
        this.loadingMore = true
      } else {
        this.loading = true
        this.errorMessage = ''
        this.errorInfo = null
      }

      const params = { mode: 'cursor', per_page: PER_PAGE }
      if (this.filters.q) params.q = this.filters.q
      if (this.filters.type) params.type = this.filters.type
      if (this.filters.category) params.category = this.filters.category
      if (this.filters.published_from) params.published_from = this.filters.published_from
      if (this.filters.published_to) params.published_to = this.filters.published_to
      if (append && this.nextCursor) params.cursor = this.nextCursor

      try {
        const response = await contentApi.getPosts(params)
        if (seq !== this.requestSeq) return

        const page = normalizeContentPage(response, 1)

        this.posts = append ? appendUnique(this.posts, page.items, 'slug') : page.items
        this.nextCursor = page.nextCursor
        this.hasMore = page.hasMore
      } catch (error) {
        if (seq !== this.requestSeq) return

        if (append) {
          uni.showToast({ title: errorState(error).message, icon: 'none' })
        } else {
          const info = errorState(error)
          this.posts = []
          this.errorMessage = info.message
          this.errorInfo = info
          this.hasMore = false
          this.nextCursor = null
        }
      } finally {
        if (seq === this.requestSeq) {
          this.loading = false
          this.loadingMore = false
        }
      }
    },
    loadMore() {
      if (!this.hasMore || !this.nextCursor || this.loading || this.loadingMore) return
      this.fetchPage(true)
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
    authorOf(item) {
      return [item.author.name, item.author.org].filter(Boolean).join(' · ')
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
  width: 88rpx;
  height: 60rpx;
  line-height: 52rpx;
  font-size: 48rpx;
  font-weight: 300;
}
.nav-back:focus-visible {
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
.filter-wrap {
  padding: 20rpx 0 8rpx;
  background: #FFFFFF;
  border-bottom: 1rpx solid #E5EAF3;
}
.search-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
  padding: 0 24rpx 14rpx;
}
.search-input {
  flex: 1;
  min-width: 0;
  height: 64rpx;
  padding: 0 20rpx;
  border-radius: 32rpx;
  background: #F5F7FB;
  border: 1rpx solid #D7DFEE;
  font-size: 24rpx;
  color: #1F2937;
}
.category-input {
  flex: 0 0 160rpx;
}
.search-btn {
  flex: 0 0 auto;
  padding: 0 22rpx;
  height: 64rpx;
  line-height: 64rpx;
  border-radius: 32rpx;
  background: #165DFF;
  color: #FFFFFF;
  font-size: 24rpx;
  font-weight: 600;
}
.chip-scroll {
  width: 100%;
  white-space: nowrap;
}
.chip-row {
  display: inline-block;
  white-space: nowrap;
  padding: 0 24rpx 12rpx;
}
.chip {
  display: inline-block;
  padding: 10rpx 26rpx;
  margin-right: 14rpx;
  border-radius: 32rpx;
  border: 1rpx solid #D7DFEE;
  background: #F8FAFF;
  color: #4B5563;
  font-size: 24rpx;
  line-height: 1.4;
}
.chip.on {
  background: #165DFF;
  border-color: #165DFF;
  color: #FFFFFF;
  font-weight: 600;
}
.chip:focus-visible {
  outline: 3rpx solid #102F6E;
  outline-offset: 2rpx;
}
.list {
  padding: 24rpx;
  display: flex;
  flex-direction: column;
  gap: 20rpx;
}
.post-card {
  background: #FFFFFF;
  border: 1rpx solid #E5EAF3;
  border-radius: 20rpx;
  padding: 26rpx 24rpx;
  box-shadow: 0 6rpx 24rpx rgba(16, 47, 110, 0.06);
  display: flex;
  flex-direction: column;
}
.post-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.post-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12rpx;
}
.post-type {
  padding: 4rpx 16rpx;
  border-radius: 8rpx;
  background: #EEF4FF;
  color: #102F6E;
  font-size: 22rpx;
  font-weight: 600;
}
.post-date {
  font-size: 22rpx;
  color: #8A9AA8;
}
.post-title {
  font-size: 32rpx;
  font-weight: 700;
  line-height: 1.45;
  color: #102F6E;
}
.post-summary {
  margin-top: 12rpx;
  font-size: 25rpx;
  line-height: 1.6;
  color: #475569;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.post-author {
  margin-top: 14rpx;
  font-size: 22rpx;
  color: #7A8AA3;
}
.load-more {
  padding: 16rpx 0 28rpx;
  display: flex;
  justify-content: center;
}
.load-btn {
  min-width: 260rpx;
  padding: 20rpx 44rpx;
  border-radius: 40rpx;
  border: 2rpx solid #165DFF;
  color: #165DFF;
  background: #FFFFFF;
  font-size: 27rpx;
  font-weight: 600;
  text-align: center;
}
.load-btn.disabled { opacity: 0.6; }
.load-btn:focus-visible {
  outline: 3rpx solid #102F6E;
  outline-offset: 2rpx;
}
.list-end {
  padding: 16rpx 0 32rpx;
  text-align: center;
  font-size: 24rpx;
  color: #8A9AA8;
}
</style>
