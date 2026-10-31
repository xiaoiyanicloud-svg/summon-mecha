<template>
  <view class="skill-market" :class="{ compact }">
    <view class="market-search">
      <input v-model="query" placeholder="搜索已上架技能" confirm-type="search" @confirm="refreshNow" @input="onQueryInput" />
      <button size="mini" @click="refreshNow">搜索</button>
    </view>
    <view v-if="showFilters">
      <view v-if="filterLoading" class="market-note">筛选条件加载中…</view>
      <view v-else-if="filterError" class="market-state">
        <text>{{ filterError }}</text><button size="mini" @click="loadFilters">重试筛选条件</button>
      </view>
      <view v-else class="market-filters">
        <picker v-for="group in filterGroups" :key="group.key" :range="group.options" range-key="label"
          :value="filterIndex(group)" @change="pickFilter(group, $event)">
          <view class="market-filter">{{ group.title }}：{{ group.options[filterIndex(group)].label }} ▾</view>
        </picker>
      </view>
    </view>
    <view v-if="market.error" class="market-state market-error">
      <text>技能加载失败</text><text class="market-note">{{ market.error }}</text>
      <button size="mini" @click="refreshNow">重新加载</button>
    </view>
    <view v-else-if="market.loading && market.page === 0 && !market.rows.length" class="market-state"><text>正在加载技能…</text></view>
    <view v-else-if="!market.rows.length" class="market-state">
      <text>{{ hasFilters ? '没有符合条件的技能' : '暂无已上架技能' }}</text>
      <text class="market-note">{{ hasFilters ? '试试其它筛选条件' : '开发者完成审核上架后，技能将在这里展示。' }}</text>
      <button v-if="hasFilters" size="mini" @click="resetFilters">清空筛选</button>
      <button v-else size="mini" @click="refreshNow">刷新</button>
    </view>
    <view v-else>
      <view class="market-grid">
        <view v-for="item in market.rows" :key="item.id" class="market-card" @click="select(item)">
          <image class="market-image" :src="displayImage(item)" mode="aspectFill" @error="imageFailed(item)" />
          <view class="market-card-body">
            <text class="market-name">{{ item.name }}</text>
            <text v-if="item.summary && !compact" class="market-summary">{{ item.summary }}</text>
            <text class="market-price">{{ item.price === 0 ? '免费' : '¥' + item.price }}</text>
            <text class="market-note">查看适配与交付说明 →</text>
          </view>
        </view>
      </view>
      <view v-if="market.hasMore" class="market-footer">
        <button size="mini" :disabled="market.loading" @click="loadMore">{{ market.loading ? '加载中…' : '加载更多' }}</button>
      </view>
    </view>
    <view v-if="compact" class="market-more" @click="$emit('show-all')">打开完整技能市场 →</view>
  </view>
</template>

<script>
import { skillCatalogApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import { createSkillMarketState, loadSkillMarket } from '@/utils/skill-market'
import { normalizeCatalogImageUrl, catalogFallbackImage } from '@/utils/product-catalog'

// 首页/机器页共用:2 分钟内重复进入不再请求技能列表。
// 返回首页(onShow)只做「过期才刷新」,且刷新期间保留旧卡片,避免闪成加载态。
const SKILL_MARKET_TTL = 120000

export default {
  name: 'SkillMarket',
  props: {
    compact: Boolean,
    active: { type: Boolean, default: true },
    // 首页生态中心内嵌时不展示机型/交付/动作分类筛选；机器页技能分类页保持展示
    showFilters: { type: Boolean, default: true },
  },
  emits: ['select', 'show-all'],
  data() {
    return {
      query: '', filters: { model: '', channel: '', motion: '' }, market: createSkillMarketState(),
      filterLoading: false, filterError: '', options: { models: [], channels: [], risks: [], motions: [] },
      _loadedKey: '', _loadedAt: 0, _pending: null, _pendingKey: '',
    }
  },
  computed: {
    hasFilters() { return !!this.query.trim() || Object.values(this.filters).some(Boolean) },
    filterGroups() {
      return [
        { key: 'model', title: '机型', source: this.options.models.map(x => ({ value: x.code, label: x.name })) },
        { key: 'channel', title: '交付', source: this.options.channels.map(x => ({ value: x.value, label: x.label })) },
        { key: 'motion', title: '动作', source: this.options.motions.map(x => ({ value: x.name, label: x.name })) },
      ].filter(x => x.source.length).map(x => ({ ...x, options: [{ value: '', label: '全部' }, ...x.source] }))
    },
  },
  watch: {
    active(value) {
      // 已拿到首屏结果的 Tab 不再重复清空内容；避免每次切回都先收缩成加载态
      if (value) {
        this.refreshIfStale()
        if (this.showFilters && this.filterError) this.loadFilters()
      } else { this.market.sequence++; this.market.loading = false }
    },
  },
  mounted() { if (this.showFilters) this.loadFilters(); if (this.active) this.refreshIfStale() },
  beforeUnmount() { this.market.sequence++ },
  methods: {
    // 用户显式动作(搜索/筛选/重试):强制走网络;同条件刷新时保留旧卡片直到新结果返回
    refreshNow() { return this.refresh({ force: true, keepRows: true }) },
    // 进入页面/切回 Tab:缓存未过期就不发请求
    refreshIfStale() { return this.refresh({ force: false, keepRows: true }) },
    refresh({ force = false, keepRows = true } = {}) {
      const key = JSON.stringify([this.query.trim(), this.filters])
      if (!force && this._loadedKey === key && this._loadedAt && Date.now() - this._loadedAt < SKILL_MARKET_TTL) {
        return Promise.resolve()
      }
      // 同一组条件已有请求在飞:直接复用,避免 mounted 与首页 onShow 各打一次
      if (!force && this._pending && this._pendingKey === key) return this._pending
      const sameKey = this._loadedKey === key
      const seq = this.market.sequence + 1
      this._loadedKey = key
      // 同一组条件下静默刷新保留旧列表;查询/筛选变了才清空,避免旧结果冒充新条件
      const request = loadSkillMarket(this.market, skillCatalogApi.search, {
        query: this.query, filters: this.filters, keepRows: keepRows && sameKey && this.market.rows.length > 0,
      }).then(() => {
        if (this.market.sequence !== seq) return
        if (this.market.error) { this._loadedKey = ''; this._loadedAt = 0 }
        else this._loadedAt = Date.now()
      }).finally(() => {
        if (this._pending === request) { this._pending = null; this._pendingKey = '' }
      })
      this._pending = request; this._pendingKey = key
      return request
    },
    loadMore() { return loadSkillMarket(this.market, skillCatalogApi.search, { query: this.query, filters: this.filters, append: true }) },
    filterIndex(group) { return Math.max(0, group.options.findIndex(x => x.value === this.filters[group.key])) },
    pickFilter(group, event) { this.filters[group.key] = group.options[Number(event.detail.value)]?.value || ''; this.refreshNow() },
    resetFilters() { this.query = ''; Object.keys(this.filters).forEach(key => { this.filters[key] = '' }); this.refreshNow() },
    onQueryInput(event) { if (!String(event.detail.value || '').trim()) this.refreshNow() },
    select(item) { if (item.market_eligible === true) this.$emit('select', item) },
    imageUrl(url) { return normalizeCatalogImageUrl(url, BASE_URL) },
    displayImage(item) { return this.imageUrl(item.image) || catalogFallbackImage('skill', item.id) },
    imageFailed(item) { item.image = catalogFallbackImage('skill', item.id) },
    async loadFilters() {
      if (this.filterLoading) return
      this.filterLoading = true; this.filterError = ''
      try {
        const [filters, motions] = await Promise.all([skillCatalogApi.filters(), skillCatalogApi.tags('motion_type')])
        const data = filters && filters.data
        if (!data || !Array.isArray(data.models) || !Array.isArray(data.channels) || !Array.isArray(data.risks) || !Array.isArray(motions?.data)) throw new Error('筛选条件加载失败')
        this.options = { ...data, motions: motions.data }
      } catch (error) { this.filterError = error.message || '筛选条件加载失败' }
      finally { this.filterLoading = false }
    },
  },
}
</script>

<style scoped>
.skill-market { padding: 24rpx; color: #102f6e; background: #f5f7fc; }
.market-search { display: flex; gap: 16rpx; align-items: center; margin-bottom: 20rpx; }
.market-search input { flex: 1; min-width: 0; padding: 18rpx 22rpx; background: #fff; border: 1rpx solid #dbe4f3; border-radius: 14rpx; font-size: 26rpx; }
.skill-market button { color: #165dff; background: #eef3ff; border-radius: 12rpx; font-size: 24rpx; flex-shrink: 0; }
.market-filters { display: flex; flex-wrap: wrap; gap: 12rpx; margin-bottom: 16rpx; }
.market-filter { padding: 12rpx 16rpx; background: #fff; border: 1rpx solid #dbe4f3; border-radius: 10rpx; font-size: 23rpx; }
.market-note { display: block; color: #64748b; font-size: 23rpx; line-height: 1.7; }
.market-state { display: flex; flex-direction: column; align-items: center; gap: 16rpx; text-align: center; padding: 56rpx 22rpx; font-size: 30rpx; }
.market-error { color: #9f3030; }
.market-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20rpx; margin-top: 22rpx; }
.market-card { overflow: hidden; background: #fff; border: 1rpx solid #e2e8f0; border-radius: 18rpx; }
.market-image { display: block; width: 100%; height: 190rpx; background: #e8effc; }
.market-placeholder { display: flex; align-items: center; justify-content: center; font-size: 68rpx; }
.market-card-body { padding: 18rpx; }
.market-name { display: block; font-weight: 600; font-size: 28rpx; line-height: 1.5; }
.market-summary { display: block; font-size: 23rpx; color: #64748b; line-height: 1.6; margin-top: 10rpx; }
.market-price { display: block; margin: 16rpx 0 6rpx; font-size: 29rpx; color: #FF7820; }
.market-footer { text-align: center; padding: 24rpx 0; }
.market-more { text-align: center; padding-top: 28rpx; font-size: 26rpx; color: #165dff; }
.compact { border-radius: 16rpx; padding: 20rpx 10rpx; }
</style>
