<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">我的收藏</text>
      <view class="nav-side"></view>
    </view>

    <view class="tabs">
      <text
        class="tab"
        :class="{ on: tab === 'event' }"
        role="button"
        tabindex="0"
        @click="switchTab('event')"
      >赛事活动</text>
      <text
        class="tab"
        :class="{ on: tab === 'content' }"
        role="button"
        tabindex="0"
        @click="switchTab('content')"
      >动态资讯</text>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '📡'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && items.length === 0 && staleCount === 0"
      empty-icon="⭐"
      empty-text="还没有收藏"
      :empty-hint="tab === 'event' ? '在活动详情页点「收藏」后会出现在这里。' : '在资讯详情页点「收藏」后会出现在这里。'"
      @retry="reload"
    >
      <view class="list">
        <view
          v-for="item in items"
          :key="item.type + '-' + item.key"
          class="fav-card"
          role="button"
          tabindex="0"
          @click="openItem(item)"
        >
          <image v-if="item.cover" class="fav-cover" :src="coverUrl(item.cover)" mode="aspectFill"></image>
          <view class="fav-body">
            <text class="fav-title">{{ item.title }}</text>
            <text v-if="item.summary" class="fav-summary">{{ item.summary }}</text>
            <text v-if="metaLine(item)" class="fav-meta">{{ metaLine(item) }}</text>
          </view>
          <view class="fav-remove" role="button" tabindex="0" @click.stop="remove(item)">取消</view>
        </view>

        <view v-if="staleCount > 0" class="stale-note">
          {{ staleCount }} 条收藏的目标已撤稿或下架，已从列表隐藏。
        </view>

        <view v-if="hasMore" class="load-more">
          <view class="load-btn" :class="{ disabled: loadingMore }" role="button" tabindex="0" @click="loadMore">
            {{ loadingMore ? '加载中…' : '加载更多' }}
          </view>
        </view>
        <view v-else-if="items.length" class="list-end">没有更多了</view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { favoriteApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import {
  errorState,
  formatDateShort,
  formatEventWhen,
  resolveMediaUrl,
} from '@/utils/ecosystem-public'

const PER_PAGE = 20

export default {
  name: 'EcosystemMyFavorites',
  components: { StateView },
  data() {
    return {
      tab: 'event',
      items: [],
      loading: false,
      loadingMore: false,
      errorMessage: '',
      errorInfo: null,
      nextCursor: null,
      hasMore: false,
      staleCount: 0,
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
        fail: () => uni.switchTab({ url: '/pages/mine/index' }),
      })
    },
    switchTab(tab) {
      if (this.tab === tab) return
      this.tab = tab
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

      try {
        const response = await favoriteApi.list(this.tab, {
          cursor: append && this.nextCursor ? this.nextCursor : '',
          perPage: PER_PAGE,
        })

        if (seq !== this.requestSeq) return

        const rows = Array.isArray(response && response.data) ? response.data : null
        if (!rows) throw new Error('收藏列表格式异常')

        const normalized = rows
          .filter((row) => row && typeof row === 'object' && (row.type === 'event' || row.type === 'content'))
          .filter((row) => typeof row.key === 'string' && row.key !== '' && typeof row.title === 'string' && row.title !== '')

        this.items = append ? this.appendUnique(this.items, normalized) : normalized

        const meta = response.meta && typeof response.meta === 'object' ? response.meta : {}
        this.hasMore = meta.has_more === true
        this.nextCursor = typeof meta.next_cursor === 'string' && meta.next_cursor ? meta.next_cursor : null
        this.staleCount = Number.isInteger(Number(meta.stale_count)) ? Number(meta.stale_count) : 0

        if (this.hasMore && !this.nextCursor) {
          throw new Error('收藏列表分页格式异常')
        }
      } catch (error) {
        if (seq !== this.requestSeq) return

        if (append) {
          uni.showToast({ title: errorState(error).message, icon: 'none' })
        } else {
          const info = errorState(error)
          this.items = []
          this.errorMessage = info.message
          this.errorInfo = info
          this.hasMore = false
          this.nextCursor = null
          this.staleCount = 0
        }
      } finally {
        if (seq === this.requestSeq) {
          this.loading = false
          this.loadingMore = false
        }
      }
    },
    appendUnique(current, incoming) {
      const seen = new Set(current.map((item) => item.type + '-' + item.key))
      const merged = current.slice()
      for (const item of incoming) {
        const marker = item.type + '-' + item.key
        if (seen.has(marker)) continue
        seen.add(marker)
        merged.push(item)
      }
      return merged
    },
    loadMore() {
      if (!this.hasMore || !this.nextCursor || this.loading || this.loadingMore) return
      this.fetchPage(true)
    },
    async remove(item) {
      try {
        await favoriteApi.remove(item.type, item.key)
        this.items = this.items.filter((row) => !(row.type === item.type && row.key === item.key))
        uni.showToast({ title: '已取消收藏', icon: 'none' })
      } catch (error) {
        uni.showToast({ title: errorState(error).message, icon: 'none' })
      }
    },
    openItem(item) {
      if (item.type === 'event') {
        uni.navigateTo({ url: `/pages/ecosystem/event-detail?id=${item.id}` })
      } else {
        uni.navigateTo({ url: `/pages/ecosystem/content-detail?slug=${encodeURIComponent(item.key)}` })
      }
    },
    coverUrl(url) {
      return resolveMediaUrl(url, BASE_URL)
    },
    metaLine(item) {
      if (item.type === 'event') {
        return [formatEventWhen({ startsAt: item.starts_at, endsAt: item.ends_at }), item.city]
          .filter(Boolean)
          .join(' · ')
      }
      return item.published_at ? `公开于 ${formatDateShort(item.published_at)}` : ''
    },
  },
}
</script>

<style scoped>
.eco-page { min-height: 100vh; background: #F5F7FB; padding-bottom: 40rpx; box-sizing: border-box; }
.eco-nav {
  display: flex; align-items: center; justify-content: space-between;
  padding: 88rpx 24rpx 22rpx;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
  color: #FFFFFF;
}
.nav-back, .nav-side { width: 88rpx; height: 60rpx; line-height: 52rpx; font-size: 48rpx; font-weight: 300; text-align: left; }
.nav-back:focus-visible { outline: 3rpx solid #FFFFFF; border-radius: 8rpx; }
.nav-side { text-align: right; }
.nav-title { flex: 1; text-align: center; font-size: 34rpx; font-weight: 600; letter-spacing: 2rpx; }
.tabs {
  display: flex; gap: 16rpx; padding: 20rpx 24rpx;
  background: #FFFFFF; border-bottom: 1rpx solid #E5EAF3;
}
.tab {
  flex: 1; text-align: center; padding: 14rpx 0; border-radius: 32rpx;
  background: #F5F7FB; color: #4B5563; font-size: 26rpx;
}
.tab.on { background: #165DFF; color: #FFFFFF; font-weight: 600; }
.tab:focus-visible { outline: 3rpx solid #102F6E; }
.list { padding: 24rpx; display: flex; flex-direction: column; gap: 18rpx; }
.fav-card {
  display: flex; align-items: stretch; gap: 16rpx;
  background: #FFFFFF; border: 1rpx solid #E5EAF3; border-radius: 18rpx; padding: 16rpx;
}
.fav-card:focus-visible { outline: 3rpx solid #165DFF; }
.fav-cover { width: 150rpx; height: 150rpx; border-radius: 12rpx; flex: 0 0 auto; background: #E8EEF9; }
.fav-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.fav-title { font-size: 28rpx; font-weight: 600; color: #102F6E; }
.fav-summary { margin-top: 6rpx; font-size: 24rpx; color: #64748B; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.fav-meta { margin-top: 8rpx; font-size: 22rpx; color: #94A3B8; }
.fav-remove {
  align-self: flex-start; flex: 0 0 auto; padding: 6rpx 16rpx;
  border-radius: 20rpx; border: 1rpx solid #DCE5F5; color: #64748B; font-size: 22rpx;
}
.stale-note { padding: 12rpx 16rpx; border-radius: 12rpx; background: #FFF8EC; color: #7C4A03; font-size: 23rpx; }
.load-more { padding: 10rpx 0 20rpx; text-align: center; }
.load-btn { display: inline-block; padding: 14rpx 40rpx; border-radius: 32rpx; background: #FFFFFF; border: 1rpx solid #DCE5F5; color: #165DFF; font-size: 25rpx; }
.load-btn.disabled { color: #94A3B8; }
.list-end { padding: 10rpx 0 20rpx; text-align: center; color: #94A3B8; font-size: 22rpx; }
</style>
