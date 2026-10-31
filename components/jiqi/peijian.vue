<template>
  <view class="product-area-wrapper">
    <!-- 搜索 / 机型 / 排序：全部走服务端，数据来自 /api/accessory-catalog -->
    <view class="filter-section">
      <view class="search-row">
        <view class="search-icon" aria-hidden="true">
          <view class="search-icon-ring"></view>
          <view class="search-icon-handle"></view>
        </view>
        <input
          class="search-input"
          v-model="q"
          confirm-type="search"
          placeholder="搜索配件名称 / SKU"
          placeholder-style="color: #94A3B8"
          @input="onSearchInput"
          @confirm="refresh"
        />
        <text v-if="q" class="search-clear" @click="clearSearch">✕</text>
      </view>

      <view class="filter-row" :class="{ 'has-sidebar': filters.categories.length }">
        <view class="filter-picker" :class="{ open: showModelPanel }" @tap="toggleModelPanel">
          <text class="filter-text">机型：{{ modelLabel }}</text>
          <text class="picker-arrow">{{ showModelPanel ? '▴' : '▾' }}</text>
        </view>

        <view class="sort-picker-wrap">
          <view class="sort-picker" :class="{ open: showSortPanel }" @tap.stop="toggleSortPanel">
            <text class="sort-picker-text">{{ sortLabel }}</text>
            <text class="sort-picker-arrow">{{ showSortPanel ? '▴' : '▾' }}</text>
          </view>

          <view v-if="showSortPanel" class="sort-panel" @tap.stop>
            <view
              v-for="option in sortOptions"
              :key="option.value"
              class="sort-option"
              :class="{ active: sort === option.value }"
              @tap="pickSort(option.value)"
            >
              <text>{{ option.label }}</text>
              <text v-if="sort === option.value" class="sort-option-check">✓</text>
            </view>
          </view>
        </view>
      </view>

      <!-- 适配机型：左品牌 / 右机型。18 个机型平铺成一列要翻很久，两级各点一次就到 -->
      <view class="model-panel" v-if="showModelPanel">
        <scroll-view class="model-brands" scroll-y="true" show-scrollbar="false">
          <view class="model-brand" :class="{ active: modelBrand === '' }" @tap="pickModelBrand('')">
            <text class="model-brand-name">全部</text>
          </view>
          <view
            class="model-brand"
            :class="{ active: modelBrand === b.brand }"
            v-for="b in modelBrands"
            :key="b.brand"
            @tap="pickModelBrand(b.brand)"
          >
            <text class="model-brand-name">{{ b.label }}</text>
          </view>
        </scroll-view>

        <scroll-view class="model-list" scroll-y="true" show-scrollbar="false">
          <view
            class="model-opt"
            :class="{ active: model === '' }"
            @tap="pickModel('')"
          >
            <text class="model-opt-name">全部机型</text>
            <text class="model-check" v-if="model === ''">✓</text>
          </view>
          <view
            class="model-opt"
            :class="{ active: model === m.model_code }"
            v-for="m in panelModels"
            :key="m.model_code"
            @tap="pickModel(m.model_code)"
          >
            <text class="model-opt-name">{{ m.model_name }}</text>
            <text class="model-check" v-if="model === m.model_code">✓</text>
          </view>
          <text class="model-empty" v-if="!panelModels.length">{{ filtersReady ? '暂无可用机型' : '机型加载中…' }}</text>
        </scroll-view>
      </view>
    </view>

    <view class="main-body">
      <scroll-view v-if="filters.categories.length" class="brand-sidebar" scroll-y="true" show-scrollbar="false">
        <view
          class="brand-item"
          :class="{ active: category === '' }"
          @tap="selectCategory('')"
        >
          <text class="brand-name">全部</text>
        </view>
        <view
          class="brand-item"
          :class="{ active: category === c.code }"
          v-for="c in filters.categories"
          :key="c.code"
          @tap="selectCategory(c.code)"
        >
          <text class="brand-name">{{ c.name }}</text>
        </view>
        <view style="height: 250rpx; flex-shrink: 0;"></view>
      </scroll-view>

      <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false" @scrolltolower="loadMore">
        <state-view
          :loading="loading && rows.length === 0"
          :error="error"
          :empty="!loading && !error && rows.length === 0"
          empty-icon="🧩"
          :empty-text="emptyText"
          empty-hint="换个机型或分类试试，或联系客服登记需求。"
          compact
          @retry="refresh"
        >
          <view class="product-flex-wrapper">
            <view
              class="accessory-card"
              v-for="item in rows"
              :key="item.id"
              role="button"
              tabindex="0"
              @tap="select(item)"
              @keyup.enter="select(item)"
            >
              <view class="accessory-image">
                <image class="accessory-img" :src="displayImage(item)" mode="aspectFill" @error="imageFailed(item)"></image>
              </view>
              <view class="accessory-info">
                <text class="accessory-name">{{ item.name }}</text>
                <view class="accessory-tags">
                  <text class="fit-badge" :class="'fit-' + fitLevel(item)">{{ fitLabel(item) }}</text>
                  <text v-if="item.install && item.install.label" class="install-badge">{{ item.install.label }}</text>
                </view>
                <view class="accessory-bottom">
                  <view class="accessory-price-wrap">
                    <text class="accessory-price" v-if="item.price && Number(item.price) > 0"><text class="ap-cur">¥</text>{{ item.price }}</text>
                    <text class="accessory-quote" v-else-if="item.custom_service">定制需报价</text>
                    <text class="accessory-original" v-if="item.compare_at_price">¥{{ item.compare_at_price }}</text>
                  </view>
                </view>
              </view>
            </view>
            <view class="scroll-bottom-placeholder" v-if="rows.length > 0"></view>
          </view>

          <view v-if="hasMore" class="load-more" @click="loadMore">
            {{ loading ? '加载中…' : '加载更多' }}
          </view>
        </state-view>
      </scroll-view>
    </view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { accessoryCatalogApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import { normalizeCatalogImageUrl, catalogFallbackImage } from '@/utils/product-catalog'

// 配件皮肤 · 机器页列表（P1）
// 数据源统一为 /api/accessory-catalog：分类、机型、适配等级、搜索、排序、分页全部服务端完成。
// 不再有本地 mock / 写死品牌 / 由 mock 反推的机型。
export default {
  name: 'Peijian',
  components: { StateView },
  props: {
    active: { type: Boolean, default: true },
  },
  emits: ['item-click'],
  data() {
    return {
      q: '',
      category: '',
      model: '',
      modelBrand: '',        // 面板左列当前品牌（只影响右列展示，不参与请求）
      showModelPanel: false,
      showSortPanel: false,
      sort: 'default',
      sortOptions: [
        { value: 'default', label: '综合排序' },
        { value: 'rating_desc', label: '好评优先' },
        { value: 'sales_desc', label: '销量优先' },
        { value: 'price_asc', label: '最低价格' },
        { value: 'price_desc', label: '最高价格' },
      ],
      filters: { categories: [], models: [] },
      filtersReady: false,
      rows: [],
      loading: true,
      error: '',
      page: 1,
      hasMore: false,
      seq: 0,
      searchTimer: null,
    }
  },
  computed: {
    // 左列品牌：按接口返回顺序分组（接口已按 sort_order 排过，等于机型库的优先级）
    modelBrands() {
      const groups = new Map()
      for (const m of this.filters.models || []) {
        const brand = m.brand || ''
        if (!groups.has(brand)) {
          groups.set(brand, { brand, label: this.brandLabel(m), models: [] })
        }
        groups.get(brand).models.push(m)
      }
      return [...groups.values()]
    },
    // 右列机型：左列选中某品牌就只列该品牌，选「全部」则列全部
    panelModels() {
      if (!this.modelBrand) return this.filters.models || []
      const group = this.modelBrands.find(b => b.brand === this.modelBrand)
      return group ? group.models : []
    },
    modelLabel() {
      const hit = (this.filters.models || []).find(m => m.model_code === this.model)
      return hit ? hit.model_name : '全部'
    },
    sortLabel() {
      const hit = this.sortOptions.find(option => option.value === this.sort)
      return hit ? hit.label : '综合排序'
    },
    emptyText() {
      return this.q || this.model || this.category ? '没有符合条件的配件' : '配件正在上架'
    },
  },
  watch: {
    active(value) {
      // 切走再切回时不要把上次展开的筛选/排序面板留在屏幕上
      this.showModelPanel = false
      this.showSortPanel = false
      if (value) this.refresh()
    },
  },
  mounted() {
    const initial = uni.getStorageSync('accessoryInitialFilters')
    if (initial && typeof initial === 'object') {
      uni.removeStorageSync('accessoryInitialFilters')
      if (initial.q) this.q = String(initial.q)
      if (initial.category) this.category = String(initial.category)
      if (initial.model) this.model = String(initial.model)
    }
    this.loadFilters()
    this.refresh()
  },
  beforeUnmount() {
    this.seq++
    if (this.searchTimer) clearTimeout(this.searchTimer)
  },
  methods: {
    imageUrl(url) { return normalizeCatalogImageUrl(url, BASE_URL) },
    displayImage(item) { return this.imageUrl(item.image) || catalogFallbackImage('accessory', item.id) },
    imageFailed(item) { item.image = catalogFallbackImage('accessory', item.id) },
    fitLevel(item) {
      return (item && item.fitment && item.fitment.level) || 'unknown'
    },
    fitLabel(item) {
      return (item && item.fitment && item.fitment.label) || '适配待确认'
    },
    async loadFilters() {
      try {
        const res = await accessoryCatalogApi.filters()
        const data = (res && res.data) || {}
        this.filters = {
          categories: Array.isArray(data.categories) ? data.categories : [],
          models: Array.isArray(data.models) ? data.models : [],
        }
      } catch (e) {
        this.filters = { categories: [], models: [] }
      } finally {
        // 面板要靠它区分「还在加载」和「确实没有机型」，别让用户对着"加载中"干等
        this.filtersReady = true
      }
    },
    async load(append = false) {
      if (append && (this.loading || !this.hasMore)) return
      const seq = ++this.seq
      const page = append ? this.page + 1 : 1
      this.loading = true
      this.error = ''
      if (!append) {
        this.rows = []
        this.page = 1
        this.hasMore = false
      }
      try {
        const params = { page, limit: 12, sort: this.sort }
        if (this.q.trim()) params.q = this.q.trim()
        if (this.category) params.category = this.category
        if (this.model) params.model = this.model
        const res = await accessoryCatalogApi.search(params)
        if (seq !== this.seq) return
        const rows = (res && Array.isArray(res.data)) ? res.data : []
        this.rows = append ? [...this.rows, ...rows] : rows
        this.page = page
        this.hasMore = !!(res && res.pagination && res.pagination.has_more)
      } catch (e) {
        if (seq !== this.seq) return
        if (append) {
          // 加载更多失败不能把已有列表换成整页错误态
          uni.showToast({ title: '加载更多失败，请重试', icon: 'none' })
          return
        }
        this.error = (e && e.message) || '配件加载失败'
        this.rows = []
      } finally {
        if (seq === this.seq) this.loading = false
      }
    },
    refresh() {
      this.seq++
      return this.load(false)
    },
    loadMore() {
      return this.load(true)
    },
    onSearchInput() {
      this.showSortPanel = false
      if (this.searchTimer) clearTimeout(this.searchTimer)
      this.searchTimer = setTimeout(() => this.refresh(), 350)
    },
    clearSearch() {
      this.q = ''
      this.refresh()
    },
    // 品牌中文名取机型名第一个词（"宇树 G1" → "宇树"、"Tesla Optimus" → "Tesla"），
    // 接口只透出 brand 代码，没有品牌名表；取不到就退回代码，保证左列不空白。
    brandLabel(model) {
      const first = String((model && model.model_name) || '').trim().split(/\s+/)[0]
      return first || (model && model.brand) || '其他'
    },
    toggleModelPanel() {
      this.showModelPanel = !this.showModelPanel
      this.showSortPanel = false
      if (!this.showModelPanel) return
      // 打开时把左列定位到当前已选机型所属品牌，省一次点击
      const hit = (this.filters.models || []).find(m => m.model_code === this.model)
      this.modelBrand = hit ? (hit.brand || '') : ''
    },
    // 左列只切换右列内容，不发请求：品牌本身不是筛选条件（接口只支持按 model 过滤）
    pickModelBrand(brand) {
      this.modelBrand = brand || ''
    },
    pickModel(code) {
      this.showModelPanel = false
      this.showSortPanel = false
      const next = code || ''
      if (next === this.model) return
      this.model = next
      this.refresh()
    },
    selectCategory(code) {
      this.showModelPanel = false
      this.showSortPanel = false
      if (this.category === code) return
      this.category = code
      this.refresh()
    },
    toggleSortPanel() {
      this.showSortPanel = !this.showSortPanel
      this.showModelPanel = false
    },
    pickSort(sort) {
      this.showSortPanel = false
      if (this.sort === sort) return
      this.sort = sort
      this.refresh()
    },
    select(item) {
      this.$emit('item-click', { ...item, bagistoId: item.id, bagistoPrice: item.price, fromCatalog: true })
    },
  },
}
</script>

<style scoped>
.product-area-wrapper {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  position: relative;
  display: flex;
  flex-direction: column;
}

/* ===== 搜索 / 机型 / 排序 ===== */
.filter-section {
  background: #ffffff;
  padding: 8rpx 20rpx 6rpx;
  border-bottom: 2rpx solid #F1F5F9;
  flex-shrink: 0;
}
.search-row {
  display: flex;
  align-items: center;
  height: 72rpx;
  margin-bottom: 12rpx;
  padding: 0 14rpx 0 22rpx;
  background: #FFFFFF;
  border: 1rpx solid #DCE5F2;
  border-radius: 18rpx;
  box-shadow: 0 4rpx 14rpx rgba(16, 47, 110, 0.05);
  box-sizing: border-box;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.search-row:focus-within {
  border-color: #94B8FF;
  box-shadow: 0 4rpx 16rpx rgba(22, 93, 255, 0.10);
}
.search-icon {
  position: relative;
  width: 26rpx;
  height: 26rpx;
  flex: 0 0 26rpx;
}
.search-icon-ring {
  position: absolute;
  left: 0;
  top: 0;
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid #6E8AB8;
  border-radius: 50%;
  box-sizing: border-box;
}
.search-icon-handle {
  position: absolute;
  left: 16rpx;
  top: 16rpx;
  width: 10rpx;
  height: 3rpx;
  border-radius: 2rpx;
  background: #6E8AB8;
  transform: rotate(45deg);
  transform-origin: left center;
}
.search-input {
  flex: 1;
  min-width: 0;
  height: 100%;
  padding: 0 12rpx;
  background: transparent;
  border: 0;
  font-size: 25rpx;
  color: #1F2937;
  box-sizing: border-box;
}
.search-clear {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40rpx;
  height: 40rpx;
  flex: 0 0 40rpx;
  border-radius: 50%;
  background: #F1F5F9;
  font-size: 22rpx;
  color: #64748B;
}
.search-clear:active { background: #E2E8F0; }
.filter-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
}
/* 左列分类栏 150rpx + 商品区左内边距 12rpx − 筛选区自身 20rpx 内边距：
   让「机型：全部」与第一张商品卡左边缘严格对齐，不再悬在分类栏上方 */
.filter-row.has-sidebar { padding-left: 142rpx; }
.filter-picker {
  display: flex;
  align-items: center;
  gap: 6rpx;
  padding: 12rpx 18rpx;
  background: #FFFFFF;
  border: 1rpx solid #DBE4F3;
  border-radius: 12rpx;
  font-size: 23rpx;
  color: #102F6E;
  /* 与技能页的「机型：全部 / 交付：全部」筛选块同一套视觉语言 */
}
.filter-text {
  max-width: 300rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.picker-arrow { font-size: 18rpx; color: #94A3B8; }
.filter-picker.open { background: #EEF3FF; border-color: #165DFF; color: #165DFF; }
.filter-picker.open .picker-arrow { color: #165DFF; }

/* ===== 适配机型：左品牌 / 右机型 ===== */
.model-panel {
  display: flex;
  margin: 8rpx 0 12rpx;
  height: 420rpx;
  background: #FFFFFF;
  border: 1rpx solid #E7EAF0;
  border-radius: 16rpx;
  box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.06);
  overflow: hidden;
}
.model-brands {
  width: 200rpx;
  height: 100%;
  flex-shrink: 0;
  background: #F8FAFC;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.model-brands::-webkit-scrollbar { display: none; width: 0; height: 0; }
.model-brand {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20rpx 8rpx;
  border-left: 4rpx solid transparent;
  box-sizing: border-box;
}
.model-brand-name {
  font-size: 24rpx;
  color: #475569;
  text-align: center;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.model-brand.active { background: #FFFFFF; border-left-color: #165DFF; }
.model-brand.active .model-brand-name { color: #165DFF; font-weight: 700; }
.model-list {
  flex: 1;
  min-width: 0;
  height: 100%;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.model-list::-webkit-scrollbar { display: none; width: 0; height: 0; }
.model-opt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
  padding: 20rpx;
}
.model-opt-name {
  font-size: 26rpx;
  color: #1F2937;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.model-opt.active { background: #EAF1FF; }
.model-opt.active .model-opt-name { color: #165DFF; font-weight: 600; }
.model-check { font-size: 24rpx; color: #165DFF; font-weight: 700; flex-shrink: 0; }
.model-empty { display: block; padding: 24rpx 20rpx; font-size: 24rpx; color: #9CA3AF; }
.sort-picker-wrap {
  position: relative;
  flex-shrink: 0;
  z-index: 30;
}
.sort-picker {
  display: flex;
  align-items: center;
  gap: 8rpx;
  padding: 12rpx 16rpx;
  background: #FFFFFF;
  border: 1rpx solid #DBE4F3;
  border-radius: 12rpx;
  font-size: 22rpx;
  color: #102F6E;
  box-sizing: border-box;
}
.sort-picker.open {
  background: #EEF3FF;
  border-color: #165DFF;
  color: #165DFF;
}
.sort-picker-text {
  max-width: 150rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sort-picker-arrow { font-size: 18rpx; color: #94A3B8; }
.sort-picker.open .sort-picker-arrow { color: #165DFF; }
.sort-panel {
  position: absolute;
  top: calc(100% + 8rpx);
  right: 0;
  width: 220rpx;
  background: #FFFFFF;
  border: 1rpx solid #E7EAF0;
  border-radius: 16rpx;
  box-shadow: 0 12rpx 30rpx rgba(15, 23, 42, 0.14);
  overflow: hidden;
}
.sort-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
  padding: 22rpx 24rpx;
  font-size: 24rpx;
  color: #475569;
  border-bottom: 1rpx solid #F1F5F9;
}
.sort-option:last-child { border-bottom: 0; }
.sort-option.active { background: #EAF1FF; color: #165DFF; font-weight: 700; }
.sort-option-check { color: #165DFF; font-size: 22rpx; font-weight: 700; }

/* ===== 主体 ===== */
.main-body {
  display: flex;
  flex: 1;
  min-height: 0;
  /* 分类栏需向上跨过筛选行，不能在主体顶部被裁切 */
  overflow: visible;
}
.brand-sidebar {
  width: 150rpx;
  background: #F8FAFC;
  padding: 8rpx 0;
  flex-shrink: 0;
  /* 提升 80rpx，使「全部」与机型、综合排序同一中线；补回高度避免底部缩短 */
  margin-top: -80rpx;
  height: calc(100% + 80rpx);
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.brand-sidebar::-webkit-scrollbar { display: none; width: 0; height: 0; }
.brand-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 16rpx 4rpx;
  border-left: 4rpx solid transparent;
  flex-shrink: 0;
}
.brand-item.active { background: #ffffff; }
.brand-item .brand-name { font-size: 24rpx; color: #1F2937; text-align: center; font-weight: 500; }
.brand-item.active .brand-name { color: #165DFF; font-weight: 700; }
.product-scroll {
  flex: 1;
  height: 100%;
  padding: 16rpx 12rpx 0 12rpx;
  box-sizing: border-box;
}
.product-flex-wrapper {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-start;
  gap: 16rpx;
}
.accessory-card {
  width: calc(50% - 8rpx);
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: auto;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.06);
}
.accessory-card:focus-visible { outline: 3rpx solid #165DFF; outline-offset: 2rpx; }
.accessory-image {
  width: 100%;
  aspect-ratio: 1 / 1;
  background: #F0F4FA;
  display: flex;
  align-items: center;
  justify-content: center;
}
.accessory-icon { font-size: 72rpx; }
.accessory-img { width: 100%; height: 100%; display: block; }
.accessory-info {
  flex: 1;
  padding: 10rpx 12rpx 12rpx;
  display: flex;
  flex-direction: column;
  gap: 8rpx;
}
.accessory-name {
  font-size: 25rpx;
  font-weight: 600;
  color: #4B5563;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.accessory-tags { display: flex; flex-wrap: wrap; gap: 8rpx; }
.fit-badge {
  font-size: 19rpx;
  padding: 2rpx 10rpx;
  border-radius: 16rpx;
  background: #EEF2F6;
  color: #52607A;
}
.fit-badge.fit-direct { background: #E8F7F0; color: #0E7C5A; }
.fit-badge.fit-adapter { background: #FFF4E5; color: #B45309; }
.fit-badge.fit-custom { background: #F3E8FF; color: #7E22CE; }
.install-badge {
  font-size: 19rpx;
  padding: 2rpx 10rpx;
  border-radius: 16rpx;
  background: #EEF4FF;
  color: #165DFF;
}
.accessory-bottom { display: flex; align-items: center; justify-content: space-between; margin-top: auto; }
.accessory-price-wrap { display: flex; align-items: baseline; gap: 8rpx; flex-wrap: wrap; }
.accessory-price { font-size: 32rpx; font-weight: 700; color: #FF7820; }
.accessory-price .ap-cur { font-size: 20rpx; font-weight: 500; color: #FF7820; }
.accessory-quote { font-size: 24rpx; font-weight: 600; color: #7E22CE; }
.accessory-original {
  font-size: 18rpx;
  color: #C0C8D4;
  text-decoration: line-through;
  font-weight: 400;
}
.scroll-bottom-placeholder { width: 100%; height: 380rpx; flex-shrink: 0; pointer-events: none; visibility: hidden; }
.load-more { text-align: center; padding: 24rpx 0 40rpx; font-size: 24rpx; color: #165DFF; }
</style>
