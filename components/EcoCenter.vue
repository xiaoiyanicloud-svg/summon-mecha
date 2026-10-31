<template>
  <view class="section eco-section eco-bg">
    <view class="eco-sticky">
      <view class="section-header">
        <text class="section-title">生态中心</text>
      </view>

      <view class="eco-tabs">
        <text class="eco-tab" :class="{ active: currentTab === 'skill' }" @click="switchTab('skill')">技能市场</text>
        <text class="eco-tab" :class="{ active: currentTab === 'accessory' }" @click="switchTab('accessory')">配件皮肤</text>
        <text class="eco-tab" :class="{ active: currentTab === 'event' }" @click="switchTab('event')">赛事活动</text>
        <text class="eco-tab" :class="{ active: currentTab === 'feed' }" @click="switchTab('feed')">动态资讯</text>
      </view>
    </view>

    <view class="eco-content">
      <!-- ========== 技能市场 ========== -->
      <view v-show="currentTab === 'skill'" class="tab-panel">
        <SkillMarket ref="skillMarket" compact :active="currentTab === 'skill'" :show-filters="false" @select="onSkill" @show-all="goMoreSkills" />
      </view>

      <!-- ========== 配件皮肤 ========== -->
      <view v-show="currentTab === 'accessory'" class="tab-panel">
        <!-- 首页配件只保留关键词搜索，分类和机型筛选留给完整配件目录 -->
        <view class="acc-search">
          <view class="acc-search-icon" aria-hidden="true">
            <view class="acc-search-icon-ring"></view>
            <view class="acc-search-icon-handle"></view>
          </view>
          <input
            class="acc-search-input"
            v-model="accessoryQuery"
            confirm-type="search"
            placeholder="搜索配件名称 / SKU"
            placeholder-style="color: #94A3B8"
            @input="onAccessoryQueryInput"
            @confirm="searchAccessories"
          />
          <text v-if="accessoryQuery" class="acc-search-clear" @click="clearAccessorySearch">✕</text>
        </view>

        <state-view
          :loading="accessoryLoading && accessoryCards.length === 0"
          :error="accessoryError"
          :empty="!accessoryLoading && !accessoryError && accessoryCards.length === 0"
          empty-icon="🧩"
          :empty-text="accessoryEmptyText"
          :empty-hint="accessoryEmptyHint"
          :empty-action="hasAccessoryQuery"
          empty-action-text="清空搜索"
          compact
          @retry="loadAccessory(true)"
          @empty-action="clearAccessorySearch"
        >
          <view class="acc-grid">
            <view
              class="acc-card"
              v-for="item in accessoryCards"
              :key="item.id"
              role="button"
              tabindex="0"
              @click="onAccessoryClick(item)"
              @keyup.enter="onAccessoryClick(item)"
            >
              <view class="acc-img">
                <image class="acc-img-img" :src="displayAccessoryImage(item)" mode="aspectFill" @error="accessoryImageFailed(item)"></image>
              </view>
              <view class="acc-card-body">
                <text class="acc-name">{{ item.name }}</text>
                <text class="acc-fit" :class="'fit-' + fitLevelOf(item)">{{ fitLabelOf(item) }}</text>
                <text class="acc-price" v-if="item.price && Number(item.price) > 0">¥{{ item.price }}</text>
                <text class="acc-quote" v-else-if="item.custom_service">定制需报价</text>
                <text class="acc-install" v-if="item.install && item.install.label">{{ item.install.label }}</text>
              </view>
            </view>
          </view>
          <view class="more-entry" @click="goMoreAccessoriesList">
            <text class="more-text">查看更多配件 →</text>
          </view>
        </state-view>
      </view>

      <!-- ========== 赛事活动（G1 公开只读预览） ========== -->
      <view v-show="currentTab === 'event'" class="tab-panel">
        <view class="eco-subhead">
          <text class="eco-subtitle">近期赛事</text>
          <text class="eco-more" role="button" tabindex="0" @click="goAllEvents">查看全部 →</text>
        </view>

        <state-view
          :loading="eventLoading"
          :error="eventError"
          :error-text="eventErrorInfo ? eventErrorInfo.title : ''"
          :error-icon="eventErrorInfo ? eventErrorInfo.icon : '📡'"
          :retry="eventErrorInfo ? eventErrorInfo.retryable : true"
          :empty="!eventLoading && !eventError && eventList.length === 0"
          empty-icon="🏟️"
          empty-text="暂无赛事"
          empty-hint="赛事发布后会在这里展示。"
          compact
          @retry="loadEvents"
        >
          <view class="event-list">
            <view
              v-for="item in eventList"
              :key="item.id"
              class="event-card"
              role="button"
              tabindex="0"
              @click="openEvent(item)"
              @keyup.enter="openEvent(item)"
            >
              <view class="event-cover">
                <image v-if="coverOf(item)" class="event-cover-img" :src="coverOf(item)" mode="aspectFill"></image>
                <text v-else class="event-cover-icon">🏁</text>
                <text class="event-status" :class="'st-' + statusOf(item).code">{{ statusOf(item).label }}</text>
              </view>
              <view class="event-info">
                <text class="event-name">{{ item.name }}</text>
                <text class="event-meta">{{ whenOf(item) }}</text>
                <text v-if="placeOf(item)" class="event-meta">{{ placeOf(item) }}</text>
              </view>
            </view>
          </view>
        </state-view>
      </view>

      <!-- ========== 动态资讯（Dongtai 真实预览） ========== -->
      <view v-show="currentTab === 'feed'" class="tab-panel">
        <Dongtai :active="currentTab === 'feed'" />
      </view>
    </view>
  </view>
</template>

<script>
import Dongtai from '@/components/shouye/dongtai.vue'
import StateView from '@/components/StateView.vue'
import { eventApi, accessoryCatalogApi } from '@/utils/api'
import {
  normalizeEventPage,
  errorState,
  eventStatus,
  formatEventWhen,
  resolveMediaUrl,
  pickCover,
  buildSharePath,
} from '@/utils/ecosystem-public'
import SkillMarket from '@/components/SkillMarket.vue'
import { skillDetailUrl } from '@/utils/skill-market'
import { BASE_URL } from '@/utils/config'
import { normalizeCatalogImageUrl, catalogFallbackImage } from '@/utils/product-catalog'

export default {
  name: 'EcoCenter',
  components: { Dongtai, SkillMarket, StateView },
  data() {
    return {
      currentTab: 'skill',
      // 赛事预览:只消费 G1-10 公开 DTO;空/错/格式异常分别交给 StateView
      eventList: [],
      eventLoading: false,
      eventLoaded: false,
      eventError: '',
      eventErrorInfo: null,

      // v3.4:技能搜索(服务端)—— skillResults 为 null 表示"未搜索,显示全部"



      // B-9:筛选维度(机型/通道/动作类型/风险等级) —— 服务端筛选



      accessoryCatalog: [],
      accessoryLoading: false,
      accessoryLoaded: false,
      accessoryError: '',
      accessoryQuery: '',
      accessorySearchTimer: null,
      _accessorySeq: 0,
    };
  },
  computed: {
    // 技能市场: 后台真实商品在前; mock 兜底展示 ECO_SKILL_IDS 子集(保持历史卡片顺序)。
    // mock 按名称去重 —— 后台已建同名商品时不再重复展示(后台全量建品后 mock 自然退场, 与机器页策略对齐)

    // v3.4:搜索中显示搜索结果,否则显示全部(skillResults 为 null = 未搜索)

    // B-9:非空筛选维度(送给服务端)


    // 有搜索词或任一筛选维度 → 走服务端搜索(否则显示全部)

    // 配件皮肤:只展示后台真实商品。没有数据就进入空态,不再用 mock 补位 ——
    // mock 会与真实商品 id 冲突,并让用户对买不到的东西下单。
    // 配件皮肤：P1 目录接口返回的卡片 DTO（已带适配、安装、交期）
    accessoryCards() {
      return this.accessoryCatalog;
    },
    hasAccessoryQuery() {
      return !!this.accessoryQuery.trim();
    },
    accessoryEmptyText() {
      return this.hasAccessoryQuery ? '没有符合条件的配件' : '配件正在上架';
    },
    accessoryEmptyHint() {
      return this.hasAccessoryQuery
        ? '换个关键词试试。'
        : '新配件上架后会在这里展示；需要特定配件可联系客服登记需求。';
    },
  },
  // 首屏只加载可见的「技能市场」;配件/赛事/资讯切到对应 Tab 再拉(见 switchTab),
  // 不再一进首页就并发 4 个请求 —— 本机单进程后端会串行排队,拖慢首屏。
  beforeUnmount() {
    this._accessorySeq++;
    if (this.accessorySearchTimer) clearTimeout(this.accessorySearchTimer);
  },
  methods: {
    switchTab(tab) {
      if (['skill', 'accessory', 'event', 'feed'].includes(tab)) {
        this.currentTab = tab;
      }
      if (tab === 'accessory' && !this.accessoryLoaded && !this.accessoryLoading) this.loadAccessory();
      if (tab === 'event' && !this.eventLoaded && !this.eventLoading) this.loadEvents();
    },
    // 配件图片可能来自 localhost/相对路径，统一改写到 BASE_URL
    imageUrl(url) {
      return normalizeCatalogImageUrl(url, BASE_URL);
    },
    displayAccessoryImage(item) {
      return this.imageUrl(item && item.image) || catalogFallbackImage('accessory', item && item.id);
    },
    accessoryImageFailed(item) {
      if (item) item.image = catalogFallbackImage('accessory', item.id);
    },
    async loadAccessory(force = false) {
      const seq = ++this._accessorySeq;
      this.accessoryLoading = true;
      this.accessoryLoaded = false;
      this.accessoryError = '';
      try {
        const params = { limit: 6, page: 1, sort: 'default' };
        const query = this.accessoryQuery.trim();
        if (query) params.q = query;
        const res = await accessoryCatalogApi.search(params);
        if (seq !== this._accessorySeq) return;
        const rows = (res && Array.isArray(res.data)) ? res.data : [];
        this.accessoryCatalog = rows.map(row => ({ ...row, image: this.imageUrl(row.image) }));
        this.accessoryLoaded = true;
      } catch (e) {
        if (seq !== this._accessorySeq) return;
        this.accessoryCatalog = [];
        this.accessoryError = (e && e.message) || '配件加载失败';
      } finally {
        if (seq === this._accessorySeq) this.accessoryLoading = false;
      }
    },
    onAccessoryQueryInput() {
      if (this.accessorySearchTimer) clearTimeout(this.accessorySearchTimer);
      this.accessorySearchTimer = setTimeout(() => {
        this.accessorySearchTimer = null;
        this.loadAccessory(true);
      }, 350);
    },
    searchAccessories() {
      if (this.accessorySearchTimer) clearTimeout(this.accessorySearchTimer);
      this.accessorySearchTimer = null;
      this.loadAccessory(true);
    },
    clearAccessorySearch() {
      if (this.accessorySearchTimer) clearTimeout(this.accessorySearchTimer);
      this.accessorySearchTimer = null;
      if (!this.accessoryQuery) return;
      this.accessoryQuery = '';
      this.loadAccessory(true);
    },
    fitLevelOf(item) {
      return (item && item.fitment && item.fitment.level) || 'unknown';
    },
    fitLabelOf(item) {
      return (item && item.fitment && item.fitment.label) || '适配待确认';
    },
    refreshSkills() { return this.$refs.skillMarket && this.$refs.skillMarket.refreshIfStale() },
    onSkill(item) {
      const url = skillDetailUrl(item)
      if (url) uni.navigateTo({ url })
    },
    // v3.4:服务端技能搜索(接 /api/skill-catalog/search) + B-9 四筛选维度


    // B-9:筛选候选项(机型/通道/风险走 filters;动作形态走既有 tags 接口)

    // B-9:点选一个筛选维度(再点一次取消)→ 立即重新搜索


    goMoreSkills() {
      uni.setStorageSync('robotTab', 'skill');
      uni.switchTab({ url: '/pages/robot/index' });
    },
    goMoreAccessoriesList() {
      // 把首页当前搜索词带到完整配件目录，避免用户进去后重新输入
      uni.setStorageSync('accessoryInitialFilters', {
        q: this.accessoryQuery.trim(),
      });
      uni.setStorageSync('robotTab', 'accessory');
      uni.switchTab({ url: '/pages/robot/index' });
    },
    onAccessoryClick(item) {
      const id = Number(item && item.id);
      if (!item || !Number.isSafeInteger(id) || id <= 0) {
        uni.showToast({ title: '该配件暂不可在线购买', icon: 'none' });
        return;
      }
      // 统一成机器页详情能消费的字段；完整详情由 /api/accessory-catalog/{id} 再拉一次
      const data = {
        ...item,
        bagistoId: id,
        bagistoPrice: item.price,
        icon: '🧩',
        bg: '#F0F4FA',
        fromHome: true,
      };
      uni.setStorageSync('accessoryDetail', data);
      uni.setStorageSync('robotTab', 'accessory');
      uni.switchTab({ url: '/pages/robot/index' });
    },

    // 赛事预览:只消费 G1-10 公开 DTO。空列表是空态,请求失败/格式异常是错误态,
    // 都不能拿样例补位 —— 也不提供任何报名写交互(G1 不允许 register)。
    async loadEvents() {
      this.eventLoading = true;
      this.eventError = '';
      this.eventErrorInfo = null;
      try {
        const res = await eventApi.getEvents({ page: 1, per_page: 5 });
        const page = normalizeEventPage(res, 1);
        this.eventList = page.items;
        this.eventLoaded = true;
      } catch (e) {
        const info = errorState(e);
        this.eventList = [];
        this.eventError = info.message;
        this.eventErrorInfo = info;
      } finally {
        this.eventLoading = false;
      }
    },
    openEvent(item) {
      const path = buildSharePath('event', item.id);
      if (path) uni.navigateTo({ url: path });
    },
    goAllEvents() {
      uni.navigateTo({ url: '/pages/ecosystem/events' });
    },
    coverOf(item) {
      const cover = pickCover(item.media);
      return cover ? resolveMediaUrl(cover.url, BASE_URL) : '';
    },
    statusOf(item) {
      return eventStatus(item);
    },
    whenOf(item) {
      return formatEventWhen(item);
    },
    placeOf(item) {
      return [item.city, item.locationText].filter(Boolean).join(' · ');
    }
  }
};
</script>

<style scoped>
/* ===== 基础样式 ===== */
.eco-bg {
  background-color: #ffffff;
  border-radius: 24rpx;
  padding: 20rpx 20rpx 12rpx;
  margin-bottom: 24rpx;
}
/* 生态中心 标题+分类按钮 吸顶: 吸附在首页白条logo下方, 仅卡片区继续滚动 */
.eco-sticky {
  position: sticky;
  top: calc(env(safe-area-inset-top) + 149rpx);
  /* ⚠ 必须低于底部自定义 tabBar(.custom-tabbar z-index:200), 否则吸顶条会盖住主导航 */
  z-index: 100;
  /* ⚠ 必须与 .eco-bg 同色: 吸顶滚动时这层会盖在卡片上, 颜色不一致会看出接缝 */
  background: #ffffff;
  margin: -20rpx -20rpx 0;
  padding: 20rpx 20rpx 8rpx;
  border-radius: 24rpx 24rpx 0 0;
}
.eco-tabs {
  display: flex;
  gap: 8rpx;
  margin-bottom: 16rpx;
}
.eco-tab {
  flex: 1;
  text-align: center;
  padding: 10rpx 0;
  font-size: 24rpx;
  color: #6B7280;
  background: #F8FAFC;
  border-radius: 16rpx;
  border: 1px solid #E5E7EB;
}
.eco-tab.active {
  background: #5588FF;
  color: #fff;
  border-color: #165DFF;
}
.eco-content {
  min-height: 200rpx;
}
.tab-panel {
  width: 100%;
}

/* ===== 技能 & 配件 ===== */
.grid-3col {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12rpx;
}
.eco-card {
  background: #fff;
  border-radius: 16rpx;
  padding: 8rpx 8rpx 14rpx;
  text-align: center;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
  border: 1px solid #F1F5F9;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.eco-card .eco-img {
  width: 100%;
  aspect-ratio: 1 / 1;
  border-radius: 12rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 8rpx;
}
.eco-card .eco-icon {
  font-size: 48rpx;
}
.eco-img-img {
  width: 100%;
  height: 100%;
  border-radius: 12rpx;
}
.eco-name {
  font-size: 20rpx;
  font-weight: 600;
  color: #1F2937;
  margin-bottom: 8rpx;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  max-height: 2.7em;
  word-break: break-all;
}
.accessory-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.eco-price {
  font-size: 22rpx;
  font-weight: 700;
  color: #FF7820;
  margin-bottom: 6rpx;
}
.more-entry {
  margin-top: 16rpx;
  padding: 20rpx 0;
  background: rgba(255, 255, 255, 0.6);
  border-radius: 16rpx;
  border: 1.5rpx dashed #B0C4DE;
  text-align: center;
}
.more-text {
  font-size: 26rpx;
  color: #165DFF;
  font-weight: 500;
  letter-spacing: 2rpx;
}
/* ===== 赛事预览（G1 公开只读） ===== */
.eco-subhead {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6rpx 2rpx 14rpx;
}
.eco-subtitle {
  font-size: 26rpx;
  font-weight: 700;
  color: #102F6E;
}
.eco-more {
  font-size: 24rpx;
  font-weight: 600;
  color: #165DFF;
}
.eco-more:focus-visible,
.event-card:focus-visible {
  outline: 3rpx solid #165DFF;
  outline-offset: 2rpx;
}
.event-list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.event-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 18rpx;
  display: flex;
  align-items: center;
  min-height: 180rpx;
  box-shadow: 0 2rpx 12rpx rgba(16, 47, 110, 0.05);
  border: 1rpx solid #E5EAF3;
  cursor: pointer;
  transition: all 0.2s;
}
.event-card:active {
  transform: scale(0.98);
  background: #F8FAFF;
}
.event-cover {
  position: relative;
  width: 180rpx;
  height: 140rpx;
  border-radius: 14rpx;
  overflow: hidden;
  flex-shrink: 0;
  margin-right: 20rpx;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
  display: flex;
  align-items: center;
  justify-content: center;
}
.event-cover-img {
  width: 100%;
  height: 100%;
}
.event-cover-icon { font-size: 64rpx; }
.event-status {
  position: absolute;
  left: 8rpx;
  bottom: 8rpx;
  padding: 2rpx 12rpx;
  border-radius: 20rpx;
  font-size: 19rpx;
  font-weight: 600;
  color: #FFFFFF;
  background: #475569;
}
.st-ongoing { background: #165DFF; }
.st-upcoming { background: #0E7C5A; }
.st-ended,
.st-canceled { background: #52607A; }
.st-pending { background: #92400E; }
.event-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.event-name {
  font-size: 29rpx;
  font-weight: 700;
  color: #102F6E;
  line-height: 1.4;
}
.event-meta {
  font-size: 22rpx;
  color: #7A8AA3;
  margin-top: 6rpx;
}

/* ===== 技能搜索(v3.4) ===== */
.skill-search { display: flex; align-items: center; height: 76rpx; margin: 16rpx 0 8rpx; padding: 0 24rpx; background: #fff; border-radius: 38rpx; }
.ss-icon { font-size: 30rpx; margin-right: 12rpx; }
.ss-input { flex: 1; font-size: 28rpx; }
.ss-clear { font-size: 28rpx; color: #9CA3AF; padding: 0 8rpx; }

/* ===== 技能筛选(B-9) ===== */
.skill-filter { margin-bottom: 8rpx; }
.sf-toggle { display: flex; align-items: center; padding: 10rpx 4rpx; }
.sf-toggle-text { font-size: 24rpx; color: #165DFF; font-weight: 600; }
.sf-toggle-badge { margin-left: 8rpx; min-width: 30rpx; height: 30rpx; line-height: 30rpx; padding: 0 8rpx; border-radius: 15rpx; background: #165DFF; color: #fff; font-size: 20rpx; text-align: center; }
.sf-toggle-arrow { margin-left: 10rpx; font-size: 20rpx; color: #9CA3AF; }
.sf-reset { margin-left: auto; font-size: 24rpx; color: #9CA3AF; }
.sf-panel { background: #F8FAFC; border: 1px solid #EEF2F6; border-radius: 16rpx; padding: 12rpx 16rpx; margin-bottom: 12rpx; }
.sf-group { display: flex; align-items: flex-start; padding: 8rpx 0; }
.sf-label { width: 66rpx; flex-shrink: 0; font-size: 22rpx; color: #6B7280; line-height: 44rpx; }
.sf-chips { flex: 1; display: flex; flex-wrap: wrap; }
.sf-chip { padding: 6rpx 20rpx; margin: 0 12rpx 12rpx 0; font-size: 22rpx; color: #6B7280; background: #fff; border: 1px solid #E5E7EB; border-radius: 30rpx; }
.sf-chip.on { background: #165DFF; color: #fff; border-color: #165DFF; }
.skill-empty { text-align: center; color: #9CA3AF; font-size: 24rpx; padding: 40rpx 0; }

/* ===== 配件皮肤（P1） ===== */
.acc-search {
  display: flex;
  align-items: center;
  height: 72rpx;
  margin: 8rpx 0 20rpx;
  padding: 0 14rpx 0 22rpx;
  background: #FFFFFF;
  border: 1rpx solid #DCE5F2;
  border-radius: 18rpx;
  box-shadow: 0 4rpx 14rpx rgba(16, 47, 110, 0.05);
  box-sizing: border-box;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.acc-search:focus-within {
  border-color: #94B8FF;
  box-shadow: 0 4rpx 16rpx rgba(22, 93, 255, 0.10);
}
.acc-search-icon {
  position: relative;
  width: 26rpx;
  height: 26rpx;
  flex: 0 0 26rpx;
}
.acc-search-icon-ring {
  position: absolute;
  left: 0;
  top: 0;
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid #6E8AB8;
  border-radius: 50%;
  box-sizing: border-box;
}
.acc-search-icon-handle {
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
.acc-search-input {
  flex: 1;
  min-width: 0;
  height: 100%;
  padding: 0 12rpx;
  font-size: 25rpx;
  color: #1F2937;
}
.acc-search-clear {
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
.acc-search-clear:active { background: #E2E8F0; }
.acc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16rpx; }
.acc-card {
  background: #fff;
  border-radius: 16rpx;
  overflow: hidden;
  border: 1px solid #F1F5F9;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
}
.acc-card:focus-visible { outline: 3rpx solid #165DFF; outline-offset: 2rpx; }
.acc-img {
  width: 100%;
  aspect-ratio: 1 / 1;
  background: #F0F4FA;
  display: flex;
  align-items: center;
  justify-content: center;
}
.acc-img-img { width: 100%; height: 100%; }
.acc-icon { font-size: 72rpx; }
.acc-card-body { padding: 12rpx 14rpx 16rpx; display: flex; flex-direction: column; gap: 6rpx; }
.acc-name {
  font-size: 26rpx;
  font-weight: 600;
  color: #1F2937;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-all;
}
.acc-fit {
  align-self: flex-start;
  font-size: 20rpx;
  padding: 2rpx 12rpx;
  border-radius: 20rpx;
  background: #EEF2F6;
  color: #52607A;
}
.acc-fit.fit-direct { background: #E8F7F0; color: #0E7C5A; }
.acc-fit.fit-adapter { background: #FFF4E5; color: #B45309; }
.acc-fit.fit-custom { background: #F3E8FF; color: #7E22CE; }
.acc-price { font-size: 30rpx; font-weight: 700; color: #FF7820; }
.acc-quote { font-size: 24rpx; font-weight: 600; color: #7E22CE; }
.acc-install { font-size: 20rpx; color: #8A9AA8; }
</style>