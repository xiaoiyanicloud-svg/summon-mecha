<template>
  <view class="eco-page">
    <!-- 自定义导航：深蓝底色 + 返回。H5 直达/刷新时退回首页 tab。 -->
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">赛事活动</text>
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
          placeholder="搜索活动 / 城市 / 地点"
          @confirm="applyFilters"
        />
        <input
          v-model="filters.city"
          class="search-input city-input"
          type="text"
          confirm-type="search"
          maxlength="100"
          placeholder="城市"
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
            :class="{ on: filters.registration_state === '' }"
            role="button"
            tabindex="0"
            @click="chooseFilter('registration_state', '')"
          >全部报名状态</text>
          <text
            v-for="option in registrationStateOptions"
            :key="option.value"
            class="chip"
            :class="{ on: filters.registration_state === option.value }"
            role="button"
            tabindex="0"
            @click="chooseFilter('registration_state', option.value)"
          >{{ option.label }}</text>
        </view>
      </scroll-view>

      <scroll-view class="chip-scroll" scroll-x="true" show-scrollbar="false">
        <view class="chip-row">
          <text
            class="chip"
            :class="{ on: filters.kind === '' }"
            role="button"
            tabindex="0"
            @click="chooseFilter('kind', '')"
          >全部类型</text>
          <text
            v-for="option in kindOptions"
            :key="option.value"
            class="chip"
            :class="{ on: filters.kind === option.value }"
            role="button"
            tabindex="0"
            @click="chooseFilter('kind', option.value)"
          >{{ option.label }}</text>
        </view>
      </scroll-view>

      <scroll-view class="chip-scroll" scroll-x="true" show-scrollbar="false">
        <view class="chip-row">
          <text
            class="chip"
            :class="{ on: filters.hosting_status === '' }"
            role="button"
            tabindex="0"
            @click="chooseFilter('hosting_status', '')"
          >全部状态</text>
          <text
            v-for="option in statusOptions"
            :key="option.value"
            class="chip"
            :class="{ on: filters.hosting_status === option.value }"
            role="button"
            tabindex="0"
            @click="chooseFilter('hosting_status', option.value)"
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
      :empty="!loading && !errorMessage && events.length === 0"
      empty-icon="🏟️"
      empty-text="暂无赛事"
      empty-hint="赛事发布后会在这里展示。"
      @retry="reload"
    >
      <view class="list">
        <view
          v-for="item in events"
          :key="item.id"
          class="event-card"
          role="button"
          tabindex="0"
          @click="openDetail(item)"
          @keyup.enter="openDetail(item)"
        >
          <view class="cover">
            <image v-if="coverOf(item)" class="cover-img" :src="coverOf(item)" mode="aspectFill"></image>
            <text v-else class="cover-icon">🏁</text>
            <text class="status-badge" :class="'st-' + statusOf(item).code">{{ statusOf(item).label }}</text>
          </view>
          <view class="card-body">
            <text class="card-kind">{{ kindOf(item) }}</text>
            <text class="card-title">{{ item.name }}</text>
            <text class="card-meta">{{ whenOf(item) }}</text>
            <text v-if="placeOf(item)" class="card-meta">{{ placeOf(item) }}</text>
            <text v-if="item.summary" class="card-summary">{{ item.summary }}</text>
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
        <view v-else-if="events.length" class="list-end">没有更多了</view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { eventApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import {
  normalizeEventPage,
  appendUnique,
  errorState,
  eventStatus,
  eventKindLabel,
  formatEventWhen,
  resolveMediaUrl,
  pickCover,
  buildSharePath,
} from '@/utils/ecosystem-public'

const PER_PAGE = 10

function isoDate(date) {
  const pad = (value) => String(value).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function addDays(days) {
  const date = new Date()
  date.setDate(date.getDate() + days)
  return isoDate(date)
}

function monthRange() {
  const now = new Date()
  const start = new Date(now.getFullYear(), now.getMonth(), 1)
  const end = new Date(now.getFullYear(), now.getMonth() + 1, 0)
  return [isoDate(start), isoDate(end)]
}

export default {
  name: 'EcosystemEvents',
  components: { StateView },
  data() {
    return {
      filters: { q: '', kind: '', hosting_status: '', city: '', registration_state: '', starts_from: '', starts_to: '' },
      datePreset: '',
      datePresetOptions: [
        { value: 'upcoming', label: '未来30天' },
        { value: 'month', label: '本月' },
      ],
      kindOptions: [
        { value: 'competition', label: '竞赛' },
        { value: 'salon', label: '沙龙' },
        { value: 'workshop', label: '工作坊' },
        { value: 'expo', label: '展会' },
        { value: 'open_day', label: '开放日' },
      ],
      statusOptions: [
        { value: 'scheduled', label: '已排期' },
        { value: 'postponed', label: '已延期' },
        { value: 'rescheduled', label: '已改期' },
        { value: 'completed', label: '已结束' },
        { value: 'canceled', label: '已取消' },
      ],
      registrationStateOptions: [
        { value: 'open', label: '报名中' },
        { value: 'upcoming', label: '未开始' },
        { value: 'full', label: '已满' },
        { value: 'closed', label: '已截止' },
        { value: 'ended', label: '已结束' },
        { value: 'external', label: '外部报名' },
        { value: 'unavailable', label: '暂无报名' },
      ],
      events: [],
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
    chooseFilter(group, value) {
      if (this.filters[group] === value) return
      this.filters[group] = value
      this.reload()
    },
    applyFilters() {
      this.filters.q = String(this.filters.q || '').trim()
      this.filters.city = String(this.filters.city || '').trim()
      this.reload()
    },
    chooseDatePreset(value) {
      if (this.datePreset === value) return
      this.datePreset = value

      if (value === 'upcoming') {
        this.filters.starts_from = isoDate(new Date())
        this.filters.starts_to = addDays(30)
      } else if (value === 'month') {
        const [from, to] = monthRange()
        this.filters.starts_from = from
        this.filters.starts_to = to
      } else {
        this.filters.starts_from = ''
        this.filters.starts_to = ''
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
      if (this.filters.kind) params.kind = this.filters.kind
      if (this.filters.hosting_status) params.hosting_status = this.filters.hosting_status
      if (this.filters.city) params.city = this.filters.city
      if (this.filters.registration_state) params.registration_state = this.filters.registration_state
      if (this.filters.starts_from) params.starts_from = this.filters.starts_from
      if (this.filters.starts_to) params.starts_to = this.filters.starts_to
      if (append && this.nextCursor) params.cursor = this.nextCursor

      try {
        const response = await eventApi.getEvents(params)
        if (seq !== this.requestSeq) return

        const page = normalizeEventPage(response, 1)

        this.events = append ? appendUnique(this.events, page.items, 'id') : page.items
        this.nextCursor = page.nextCursor
        this.hasMore = page.hasMore
      } catch (error) {
        if (seq !== this.requestSeq) return

        if (append) {
          // 已有数据时不把整页打成错误态；明确提示本次加载失败。
          uni.showToast({ title: errorState(error).message, icon: 'none' })
        } else {
          const info = errorState(error)
          this.events = []
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
      const path = buildSharePath('event', item.id)
      if (path) uni.navigateTo({ url: path })
    },
    coverOf(item) {
      const cover = pickCover(item.media)
      return cover ? resolveMediaUrl(cover.url, BASE_URL) : ''
    },
    statusOf(item) {
      return eventStatus(item)
    },
    kindOf(item) {
      return eventKindLabel(item.kind)
    },
    whenOf(item) {
      return formatEventWhen(item)
    },
    placeOf(item) {
      return [item.city, item.locationText].filter(Boolean).join(' · ')
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
  text-align: left;
}
.nav-back:focus-visible {
  outline: 3rpx solid #FFFFFF;
  border-radius: 8rpx;
}
.nav-side { text-align: right; }
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
.city-input {
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
.event-card {
  background: #FFFFFF;
  border: 1rpx solid #E5EAF3;
  border-radius: 20rpx;
  overflow: hidden;
  box-shadow: 0 6rpx 24rpx rgba(16, 47, 110, 0.06);
}
.event-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.cover {
  position: relative;
  height: 240rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
}
.cover-img {
  width: 100%;
  height: 100%;
}
.cover-icon { font-size: 84rpx; }
.status-badge {
  position: absolute;
  top: 16rpx;
  right: 16rpx;
  padding: 6rpx 20rpx;
  border-radius: 28rpx;
  font-size: 22rpx;
  font-weight: 600;
  color: #FFFFFF;
  background: #475569;
}
.st-ongoing { background: #165DFF; }
.st-upcoming { background: #0E7C5A; }
.st-ended,
.st-canceled { background: #52607A; }
.st-pending { background: #92400E; }
.card-body {
  display: flex;
  flex-direction: column;
  padding: 22rpx 24rpx 26rpx;
}
.card-kind {
  align-self: flex-start;
  padding: 4rpx 16rpx;
  margin-bottom: 10rpx;
  border-radius: 8rpx;
  background: #EEF4FF;
  color: #102F6E;
  font-size: 22rpx;
  font-weight: 600;
}
.card-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #102F6E;
  line-height: 1.4;
}
.card-meta {
  margin-top: 10rpx;
  font-size: 24rpx;
  color: #5B6B85;
}
.card-summary {
  margin-top: 12rpx;
  font-size: 25rpx;
  line-height: 1.6;
  color: #475569;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
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
.load-btn.disabled {
  opacity: 0.6;
}
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
