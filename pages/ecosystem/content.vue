<template>
  <view class="eco-page">
    <nav-bar title="动态资讯" fallback-tab="/pages/home/index" />

    <view class="filter-wrap">
      <view class="search-row">
        <view class="search-box">
          <view class="search-icon" aria-hidden="true">
            <view class="search-icon-ring"></view>
            <view class="search-icon-handle"></view>
          </view>
          <input
            v-model="filters.q"
            class="search-input"
            type="text"
            confirm-type="search"
            maxlength="50"
            placeholder="搜索标题 / 摘要"
            placeholder-style="color: #94A3B8"
            @confirm="applyFilters"
          />
          <text v-if="filters.q" class="search-clear" role="button" tabindex="0" @click="clearQuery">✕</text>
        </view>
        <text class="search-btn" role="button" tabindex="0" @click="applyFilters">搜索</text>
      </view>

      <view class="tab-bar">
        <scroll-view class="tab-scroll" scroll-x="true" :enhanced="true" :show-scrollbar="false">
          <view class="tab-row">
            <text
              class="type-tab"
              :class="{ on: filters.type === '' }"
              role="button"
              tabindex="0"
              @click="chooseType('')"
            >全部</text>
            <text
              v-for="option in typeOptions"
              :key="option.value"
              class="type-tab"
              :class="{ on: filters.type === option.value }"
              role="button"
              tabindex="0"
              @click="chooseType(option.value)"
            >{{ option.label }}</text>
          </view>
        </scroll-view>
        <view
          class="filter-toggle"
          :class="{ active: showFilterPanel || activeSecondaryCount > 0 }"
          role="button"
          tabindex="0"
          @click="showFilterPanel = !showFilterPanel"
        >
          <text class="filter-toggle-text">筛选</text>
          <text v-if="activeSecondaryCount > 0" class="filter-badge sm-mono">{{ activeSecondaryCount }}</text>
          <text class="filter-chevron">{{ showFilterPanel ? '▴' : '▾' }}</text>
        </view>
      </view>

      <view v-if="!showFilterPanel && activeSecondaryTags.length" class="active-tags">
        <view
          v-for="tag in activeSecondaryTags"
          :key="tag.key"
          class="active-tag"
          role="button"
          tabindex="0"
          @click="clearOneFilter(tag.key)"
        >
          <text>{{ tag.label }}</text>
          <text class="active-tag-x">×</text>
        </view>
        <text class="active-reset" role="button" tabindex="0" @click="resetSecondaryFilters">重置</text>
      </view>

      <view v-if="showFilterPanel" class="filter-drawer">
        <view class="drawer-group">
          <text class="drawer-label">时间</text>
          <view class="drawer-chips">
            <text
              class="drawer-chip"
              :class="{ on: datePreset === '' }"
              role="button"
              tabindex="0"
              @click="chooseDatePreset('')"
            >全部时间</text>
            <text
              v-for="option in datePresetOptions"
              :key="option.value"
              class="drawer-chip"
              :class="{ on: datePreset === option.value }"
              role="button"
              tabindex="0"
              @click="chooseDatePreset(option.value)"
            >{{ option.label }}</text>
          </view>
        </view>

        <view class="drawer-group">
          <text class="drawer-label">分类</text>
          <input
            v-model="filters.category"
            class="cat-input"
            type="text"
            confirm-type="search"
            maxlength="64"
            placeholder="输入分类关键词（选填）"
            placeholder-style="color: #94A3B8"
            @confirm="applyFiltersAndClose"
          />
        </view>

        <view class="drawer-actions">
          <text class="drawer-btn ghost" role="button" tabindex="0" @click="resetAllFilters">重置全部</text>
          <text class="drawer-btn primary" role="button" tabindex="0" @click="applyFiltersAndClose">完成筛选</text>
        </view>
      </view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '/static/icons/mine/state-error.png'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && posts.length === 0"
      empty-icon="/static/icons/mine/empty-news.png"
      empty-text="暂无资讯"
      empty-hint="资讯发布后将在此展示。"
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
          <view class="post-row">
            <view class="post-main">
              <text class="post-title">{{ item.title }}</text>
              <text v-if="item.summary" class="post-summary">{{ item.summary }}</text>
              <view class="post-foot">
                <text class="post-type" :class="'tp-' + (item.type || 'news')">{{ typeOf(item) }}</text>
                <text v-if="authorOf(item)" class="post-author">{{ authorOf(item) }}</text>
                <text v-if="dateOf(item)" class="post-date sm-mono">{{ dateOf(item) }}</text>
              </view>
            </view>
            <image v-if="coverOf(item)" class="post-thumb" :src="coverOf(item)" mode="aspectFill"></image>
          </view>
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
        <view v-else-if="posts.length" class="list-end">已展示全部内容</view>
      </view>
    </state-view>
  </view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { contentApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import {
  normalizeContentPage,
  appendUnique,
  errorState,
  contentTypeLabel,
  formatDateShort,
  resolveMediaUrl,
  pickCover,
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
  components: { NavBar, StateView },
  data() {
    return {
      filters: { q: '', type: '', category: '', published_from: '', published_to: '' },
      datePreset: '',
      showFilterPanel: false,
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
  computed: {
    activeSecondaryTags() {
      const tags = []
      if (this.datePreset) {
        const hit = this.datePresetOptions.find((o) => o.value === this.datePreset)
        tags.push({ key: 'datePreset', label: hit ? hit.label : this.datePreset })
      }
      if (this.filters.category) {
        tags.push({ key: 'category', label: `分类: ${this.filters.category}` })
      }
      return tags
    },
    activeSecondaryCount() {
      return this.activeSecondaryTags.length
    },
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
    applyFiltersAndClose() {
      this.showFilterPanel = false
      this.applyFilters()
    },
    clearQuery() {
      if (!this.filters.q) return
      this.filters.q = ''
      this.reload()
    },
    clearOneFilter(key) {
      if (key === 'datePreset') {
        this.chooseDatePreset('')
        return
      }
      if (this.filters[key]) {
        this.filters[key] = ''
        this.reload()
      }
    },
    resetSecondaryFilters() {
      this.datePreset = ''
      this.filters.published_from = ''
      this.filters.published_to = ''
      this.filters.category = ''
      this.reload()
    },
    resetAllFilters() {
      this.filters.q = ''
      this.filters.type = ''
      this.showFilterPanel = false
      this.resetSecondaryFilters()
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
      return [item.author.name, item.author.org].filter(Boolean).join(' · ')
    },
  },
}
</script>

<style scoped>
.eco-page {
  min-height: 100vh;
  background: #F4F7FB;
  padding-bottom: 48rpx;
  box-sizing: border-box;
}
.filter-wrap {
  background: #FFFFFF;
  border-bottom: 1rpx solid #E5EAF2;
  padding-top: 16rpx;
}
.search-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
  padding: 0 24rpx 14rpx;
}
.search-box {
  flex: 1;
  min-width: 0;
  height: 70rpx;
  padding: 0 14rpx 0 22rpx;
  border-radius: 999rpx;
  background: #F4F7FB;
  border: 1rpx solid #E5EAF2;
  display: flex;
  align-items: center;
  box-sizing: border-box;
}
.search-box:focus-within {
  background: #FFFFFF;
  border-color: #94B8FF;
}
.search-icon {
  position: relative;
  width: 24rpx;
  height: 24rpx;
  flex: 0 0 24rpx;
}
.search-icon-ring {
  position: absolute;
  left: 0;
  top: 0;
  width: 17rpx;
  height: 17rpx;
  border: 3rpx solid #64748B;
  border-radius: 50%;
  box-sizing: border-box;
}
.search-icon-handle {
  position: absolute;
  left: 15rpx;
  top: 15rpx;
  width: 9rpx;
  height: 3rpx;
  border-radius: 2rpx;
  background: #64748B;
  transform: rotate(45deg);
  transform-origin: left center;
}
.search-input {
  flex: 1;
  min-width: 0;
  height: 100%;
  padding: 0 12rpx;
  border: 0;
  outline: none;
  font-size: 25rpx;
  color: #0F172A;
  background: transparent;
}
.search-clear {
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  background: #E2E8F0;
  color: #475569;
  font-size: 20rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.search-btn {
  flex: 0 0 auto;
  height: 70rpx;
  line-height: 70rpx;
  padding: 0 26rpx;
  border-radius: 999rpx;
  background: #165DFF;
  color: #FFFFFF;
  font-size: 25rpx;
  font-weight: 500;
}
.tab-bar {
  display: flex;
  align-items: center;
  padding: 0 24rpx 0 8rpx;
  border-top: 1rpx solid #F1F5F9;
}
.tab-scroll {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.tab-scroll::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
.tab-row {
  display: inline-flex;
  align-items: center;
  padding: 0 12rpx;
}
.type-tab {
  position: relative;
  padding: 20rpx 18rpx;
  font-size: 27rpx;
  color: #64748B;
  font-weight: 500;
  white-space: nowrap;
}
.type-tab.on {
  color: #0F172A;
  font-weight: 600;
}
.type-tab.on::after {
  content: '';
  position: absolute;
  left: 20rpx;
  right: 20rpx;
  bottom: 0;
  height: 4rpx;
  border-radius: 2rpx;
  background: #165DFF;
}
.filter-toggle {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  gap: 6rpx;
  padding: 10rpx 18rpx;
  margin-left: 8rpx;
  border-radius: 999rpx;
  background: #F4F7FB;
  border: 1rpx solid #E5EAF2;
  color: #475569;
  font-size: 23rpx;
}
.filter-toggle.active {
  background: #EEF4FF;
  border-color: #BFDBFE;
  color: #165DFF;
  font-weight: 500;
}
.filter-badge {
  min-width: 28rpx;
  height: 28rpx;
  line-height: 28rpx;
  padding: 0 8rpx;
  border-radius: 999rpx;
  background: #165DFF;
  color: #FFFFFF;
  font-size: 19rpx;
  text-align: center;
}
.filter-chevron {
  font-size: 20rpx;
  line-height: 1;
}
.active-tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10rpx;
  padding: 12rpx 24rpx 14rpx;
  background: #F8FAFC;
  border-top: 1rpx solid #F1F5F9;
}
.active-tag {
  display: inline-flex;
  align-items: center;
  gap: 8rpx;
  padding: 6rpx 16rpx;
  border-radius: 999rpx;
  background: #FFFFFF;
  border: 1rpx solid #DCE5F2;
  color: #334155;
  font-size: 22rpx;
}
.active-tag-x {
  color: #94A3B8;
  font-size: 24rpx;
}
.active-reset {
  margin-left: auto;
  font-size: 22rpx;
  color: #64748B;
  padding: 4rpx 8rpx;
}
.filter-drawer {
  padding: 18rpx 24rpx 22rpx;
  background: #F8FAFC;
  border-top: 1rpx solid #E5EAF2;
  display: flex;
  flex-direction: column;
  gap: 18rpx;
}
.drawer-group {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.drawer-label {
  width: 64rpx;
  flex-shrink: 0;
  font-size: 23rpx;
  color: #64748B;
}
.drawer-chips {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx;
}
.drawer-chip {
  padding: 8rpx 20rpx;
  border-radius: 999rpx;
  background: #FFFFFF;
  border: 1rpx solid #E5EAF2;
  color: #475569;
  font-size: 23rpx;
}
.drawer-chip.on {
  background: #EEF4FF;
  border-color: #165DFF;
  color: #165DFF;
  font-weight: 500;
}
.cat-input {
  flex: 1;
  height: 60rpx;
  padding: 0 18rpx;
  border-radius: 12rpx;
  background: #FFFFFF;
  border: 1rpx solid #DCE5F2;
  font-size: 24rpx;
  color: #0F172A;
}
.drawer-actions {
  display: flex;
  gap: 16rpx;
  padding-top: 6rpx;
}
.drawer-btn {
  flex: 1;
  height: 68rpx;
  line-height: 68rpx;
  text-align: center;
  border-radius: 14rpx;
  font-size: 25rpx;
  font-weight: 500;
}
.drawer-btn.ghost {
  background: #FFFFFF;
  border: 1rpx solid #DCE5F2;
  color: #475569;
}
.drawer-btn.primary {
  background: #165DFF;
  color: #FFFFFF;
}
.list {
  padding: 20rpx 24rpx 24rpx;
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.post-card {
  background: #FFFFFF;
  border: 1rpx solid #E5EAF2;
  border-radius: 18rpx;
  padding: 24rpx;
  transition: background-color 0.15s ease;
}
.post-card:active {
  background: #F8FAFC;
}
.post-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.post-row {
  display: flex;
  align-items: flex-start;
  gap: 20rpx;
}
.post-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.post-title {
  font-size: 31rpx;
  font-weight: 600;
  line-height: 1.45;
  color: #0F172A;
}
.post-summary {
  margin-top: 10rpx;
  font-size: 25rpx;
  line-height: 1.58;
  color: #475569;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.post-foot {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 16rpx;
}
.post-type {
  padding: 2rpx 12rpx;
  border-radius: 6rpx;
  background: #F1F5F9;
  color: #475569;
  font-size: 21rpx;
  font-weight: 500;
}
.post-type.tp-announcement {
  background: #FFFBEB;
  color: #B45309;
}
.post-type.tp-competition_report {
  background: #EEF4FF;
  color: #165DFF;
}
.post-author {
  font-size: 22rpx;
  color: #64748B;
}
.post-date {
  font-size: 22rpx;
  color: #94A3B8;
  margin-left: auto;
}
.post-thumb {
  width: 196rpx;
  height: 136rpx;
  border-radius: 12rpx;
  flex-shrink: 0;
  background: #F1F5F9;
}
.load-more {
  padding: 16rpx 0 24rpx;
  display: flex;
  justify-content: center;
}
.load-btn {
  min-width: 240rpx;
  padding: 18rpx 44rpx;
  border-radius: 999rpx;
  border: 1rpx solid #CBD5E1;
  color: #334155;
  background: #FFFFFF;
  font-size: 26rpx;
  font-weight: 500;
  text-align: center;
}
.load-btn.disabled { opacity: 0.55; }
.list-end {
  padding: 16rpx 0 28rpx;
  text-align: center;
  font-size: 23rpx;
  color: #94A3B8;
}
</style>

<style>
::-webkit-scrollbar,
scroll-view::-webkit-scrollbar,
uni-scroll-view::-webkit-scrollbar,
.uni-scroll-view::-webkit-scrollbar {
  width: 0 !important;
  height: 0 !important;
  display: none !important;
}
scroll-view,
uni-scroll-view,
.uni-scroll-view {
  scrollbar-width: none !important;
  -ms-overflow-style: none !important;
}
</style>
