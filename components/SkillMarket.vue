<template>
  <view class="skill-market" :class="{ compact }">
    <view class="market-search">
      <input v-model="query" placeholder="搜索已上架技能" confirm-type="search" @confirm="refresh" @input="onQueryInput" />
      <button size="mini" @click="refresh">搜索</button>
    </view>
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
    <view v-if="market.error" class="market-state market-error">
      <text>技能加载失败</text><text class="market-note">{{ market.error }}</text>
      <button size="mini" @click="refresh">重新加载</button>
    </view>
    <view v-else-if="market.loading && market.page === 0" class="market-state"><text>正在加载技能…</text></view>
    <view v-else-if="!market.rows.length" class="market-state">
      <text>{{ hasFilters ? '没有符合条件的技能' : '暂无已上架技能' }}</text>
      <text class="market-note">{{ hasFilters ? '试试其它筛选条件' : '开发者完成审核上架后，技能将在这里展示。' }}</text>
      <button v-if="hasFilters" size="mini" @click="resetFilters">清空筛选</button>
      <button v-else size="mini" @click="refresh">刷新</button>
    </view>
    <view v-else>
      <view class="market-grid">
        <view v-for="item in market.rows" :key="item.id" class="market-card" @click="select(item)">
          <image v-if="item.image" class="market-image" :src="imageUrl(item.image)" mode="aspectFill" @error="imageFailed(item)" />
          <view v-else class="market-image market-placeholder">🤖</view>
          <view class="market-card-body">
            <text class="market-name">{{ item.name }}</text>
            <text v-if="item.summary && !compact" class="market-summary">{{ item.summary }}</text>
            <text class="market-price">{{ item.price === 0 ? '免费' : '¥' + item.price }}</text>
            <text class="market-note">查看适配与交付说明 →</text>
          </view>
        </view>
      </view>
      <view class="market-footer">
        <button v-if="market.hasMore" size="mini" :disabled="market.loading" @click="loadMore">{{ market.loading ? '加载中…' : '加载更多' }}</button>
        <text v-else class="market-note">已展示全部匹配技能</text>
      </view>
    </view>
    <view class="market-examples">
      <view class="examples-toggle" @click="showExamples = !showExamples"><text>技能构想示例 · 不可购买</text><text>{{ showExamples ? '收起 −' : '展开 +' }}</text></view>
      <view v-if="showExamples">
        <text class="market-note">以下内容用于说明能力方向，不代表已上架、已适配或能够交付。</text>
        <view v-for="example in examples" :key="example.key" class="example-row" @click="explainExample">
          <text>{{ example.icon }} {{ example.name }}</text><text class="example-label">构想示例</text>
        </view>
      </view>
    </view>
    <view v-if="compact" class="market-more" @click="$emit('show-all')">打开完整技能市场 →</view>
  </view>
</template>

<script>
import { skillCatalogApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import SKILL_LIST from '@/components/jiqi/skill-data'
import { createSkillMarketState, loadSkillMarket } from '@/utils/skill-market'

export default {
  name: 'SkillMarket',
  props: { compact: Boolean, active: { type: Boolean, default: true } },
  emits: ['select', 'show-all'],
  data() {
    return {
      query: '', filters: { model: '', channel: '', motion: '' }, market: createSkillMarketState(),
      filterLoading: false, filterError: '', options: { models: [], channels: [], risks: [], motions: [] },
      showExamples: false,
      examples: SKILL_LIST.slice(0, 3).map((row, i) => ({ key: `example-${i}`, name: row.name, icon: row.icon || '💡' })),
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
      if (value) { this.refresh(); if (this.filterError) this.loadFilters() }
      else { this.market.sequence++; this.market.loading = false }
    },
  },
  mounted() { this.loadFilters(); if (this.active) this.refresh() },
  beforeUnmount() { this.market.sequence++ },
  methods: {
    refresh() { return loadSkillMarket(this.market, skillCatalogApi.search, { query: this.query, filters: this.filters }) },
    loadMore() { return loadSkillMarket(this.market, skillCatalogApi.search, { query: this.query, filters: this.filters, append: true }) },
    filterIndex(group) { return Math.max(0, group.options.findIndex(x => x.value === this.filters[group.key])) },
    pickFilter(group, event) { this.filters[group.key] = group.options[Number(event.detail.value)]?.value || ''; this.refresh() },
    resetFilters() { this.query = ''; Object.keys(this.filters).forEach(key => { this.filters[key] = '' }); this.refresh() },
    onQueryInput(event) { if (!String(event.detail.value || '').trim()) this.refresh() },
    select(item) { if (item.market_eligible === true) this.$emit('select', item) },
    explainExample() { uni.showModal({ title: '技能构想示例', content: '这是能力方向示例，尚不代表已上架或适配，也不能购买。请以市场中的真实技能与交付说明为准。', showCancel: false }) },
    imageUrl(url) { return String(url).replace(/^https?:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?(?=\/)/, BASE_URL) },
    imageFailed(item) { item.image = '' },
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
.market-price { display: block; margin: 16rpx 0 6rpx; font-size: 29rpx; color: #165dff; }
.market-footer { text-align: center; padding: 24rpx 0; }
.market-examples { margin-top: 22rpx; padding: 20rpx; background: #fff; border: 1rpx dashed #c5cfe1; border-radius: 14rpx; }
.examples-toggle { display: flex; justify-content: space-between; gap: 12rpx; font-size: 24rpx; padding: 8rpx 0; }
.example-row { display: flex; justify-content: space-between; align-items: center; padding: 20rpx 0; font-size: 25rpx; gap: 12rpx; }
.example-label { font-size: 21rpx; color: #64748b; flex-shrink: 0; }
.market-more { text-align: center; padding-top: 28rpx; font-size: 26rpx; color: #165dff; }
.compact { border-radius: 16rpx; padding: 20rpx 10rpx; }
</style>
