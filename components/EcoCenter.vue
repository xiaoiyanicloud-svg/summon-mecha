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
        <SkillMarket ref="skillMarket" compact :active="currentTab === 'skill'" @select="onSkill" @show-all="goMoreSkills" />
      </view>

      <!-- ========== 配件皮肤 ========== -->
      <view v-show="currentTab === 'accessory'" class="tab-panel">
        <view class="grid-3col">
          <view class="eco-card accessory-card" v-for="item in accessoryCards" :key="item.uid" @click="onAccessoryClick(item)">
            <view class="eco-img accessory-img" :style="{ backgroundColor: item.bg || '#F0F4FA' }">
              <image v-if="item.image" class="eco-img-img" :src="item.image" mode="aspectFill"></image>
              <text v-else class="eco-icon">{{ item.icon }}</text>
            </view>
            <text class="eco-name">{{ item.name }}</text>
            <text class="eco-price" v-if="item.price && String(item.price) !== '0'">¥{{ item.price }}</text>
          </view>
        </view>
        <view class="more-entry" @click="goMoreAccessoriesList">
          <text class="more-text">更多配件 →</text>
        </view>
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
        <Dongtai />
      </view>
    </view>
  </view>
</template>

<script>
import Dongtai from '@/components/shouye/dongtai.vue'
import StateView from '@/components/StateView.vue'
import { productApi, categoryApi, eventApi } from '@/utils/api'
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

// tab 数据刷新节流: 5 分钟内重复切 tab 不重拉(与机器页 loadCatalog 的 TTL 策略一致)
const CATALOG_TTL = 5 * 60 * 1000;

export default {
  name: 'EcoCenter',
  components: { Dongtai, SkillMarket, StateView },
  data() {
    return {
      currentTab: 'skill',
      // 赛事预览:只消费 G1-10 公开 DTO;空/错/格式异常分别交给 StateView
      eventList: [],
      eventLoading: false,
      eventError: '',
      eventErrorInfo: null,

      // v3.4:技能搜索(服务端)—— skillResults 为 null 表示"未搜索,显示全部"



      // B-9:筛选维度(机型/通道/动作类型/风险等级) —— 服务端筛选



      accessoryCatalog: [],

      // 技能 mock 已抽到 components/jiqi/skill-data.js(与机器页共用一份, 见 skillCards 计算属性)
      // 配件 mock 仅本页使用(机器页配件是纯后台数据)
      accessoryList: [
        { id: 1, name: '合金护甲', price: '299', icon: '🛡️', category: 'fight' },
        { id: 2, name: '格斗铲', price: '199', icon: '🔧', category: 'fight' },
        { id: 3, name: '高速电机', price: '599', icon: '⚡', category: 'fight' },
        { id: 4, name: '机甲炫彩皮肤', price: '159', icon: '🎨', category: 'skin' },
        { id: 5, name: '碳纤维外壳', price: '399', icon: '🛡️', category: 'skin' },
        { id: 6, name: '夜光涂装', price: '259', icon: '✨', category: 'skin' },
        { id: 7, name: '智能电池', price: '399', icon: '🔋', category: 'battery' },
        { id: 8, name: '激光雷达', price: '899', icon: '📡', category: 'sensor' },
        { id: 9, name: '防撞轮', price: '149', icon: '🔄', category: 'sensor' },
        { id: 10, name: '机械臂', price: '1,299', icon: '🦾', category: 'arm' },
        { id: 11, name: '钛合金关节', price: '699', icon: '🦿', category: 'arm' },
        { id: 12, name: '石墨烯电池', price: '899', icon: '🔋', category: 'battery' },
        { id: 13, name: '磁悬浮模组', price: '1,099', icon: '🧲', category: 'sensor' },
        { id: 14, name: '迷彩涂装', price: '329', icon: '🎨', category: 'skin' },
        { id: 15, name: '重装护甲', price: '799', icon: '🛡️', category: 'fight' }
      ],
    };
  },
  computed: {
    // 技能市场: 后台真实商品在前; mock 兜底展示 ECO_SKILL_IDS 子集(保持历史卡片顺序)。
    // mock 按名称去重 —— 后台已建同名商品时不再重复展示(后台全量建品后 mock 自然退场, 与机器页策略对齐)

    // v3.4:搜索中显示搜索结果,否则显示全部(skillResults 为 null = 未搜索)

    // B-9:非空筛选维度(送给服务端)


    // 有搜索词或任一筛选维度 → 走服务端搜索(否则显示全部)

    // 配件皮肤: 后台真实商品 + 静态 mock 共存(同样按名称去重)
    accessoryCards() {
      const real = this.accessoryCatalog.map(p => ({ ...p, uid: 'r' + p.id }));
      const realNames = new Set(real.map(p => String(p.name || '').trim()));
      const mock = this.accessoryList
        .filter(m => !realNames.has(m.name))
        .map(m => ({ ...m, uid: 'm' + m.id }));
      return real.concat(mock);
    },
  },
  mounted() {
    this.loadAccessory();
    this.loadEvents();
  },
  methods: {
    switchTab(tab) {
      if (['skill', 'accessory', 'event', 'feed'].includes(tab)) {
        this.currentTab = tab;
      }
      if (tab === 'accessory') this.loadAccessory();
    },
    // 富文本图片地址修复(与机器人页一致): 后台描述里的 localhost/相对路径统一重写为 BASE_URL, 图片可加载
    fixDescUrls(html) {
      if (!html) return html;
      const base = String(BASE_URL);
      let out = String(html)
        .replace(/(src|href)=["']https?:\/\/[^\/"']+(?=\/)/gi, (_m, a) => `${a}="${base}`)
        .replace(/(src|href)=["'](\/(?!\/)[^"']*)/g, (_m, a, p) => `${a}="${base}${p}`);
      out = out.replace(/<img([^>]*)>/gi, (_m, attrs) => {
        let a = String(attrs)
          .replace(/\s+(?:width|height|style)=("[^"]*"|'[^']*'|\S+)/gi, '')
          .replace(/\s*\/\s*$/, '');
        return `<img${a} width="100%" style="height:auto;display:block;max-width:100%">`;
      });
      return out;
    },
    // 后台商品 → 卡片/详情所用字段(与机器人页 mapCatalogProduct 对齐)
    mapProduct(p) {
      const regular = (p.prices && p.prices.regular && p.prices.regular.price) || '';
      const final = (p.prices && p.prices.final && p.prices.final.price) || '';
      const minPrice = (typeof p.min_price === 'number' ? p.min_price : '0');
      const effVal = final || regular || minPrice;
      const rawEff = parseFloat(String(effVal).replace(/[^\d.]/g, ''));
      const rawReg = parseFloat(String(regular).replace(/[^\d.]/g, ''));
      const num = isNaN(rawEff) ? '0' : String(Math.round(rawEff * 100) / 100);
      const originalPrice = rawEff > 0 ? String(Math.round(((!isNaN(rawReg) && rawReg > rawEff) ? rawReg : rawEff * 1.2) * 100) / 100) : '';
      const img = (p.base_image && (p.base_image.medium_image_url || p.base_image.large_image_url || p.base_image.small_image_url)) || '';
      return {
        id: p.id,
        name: p.name || '商品',
        sku: p.sku || '',
        price: num,
        bagistoPrice: num,
        originalPrice,
        image: img,
        icon: '🤖',
        bg: '#F0F4FA',
        scene: '',
        bagistoId: p.id,
        bagistoDescription: this.fixDescUrls(p.description || ''),
        brand: 'all',
        type: 'all',
      };
    },

    async loadAccessory() {
      if (Date.now() - (this._accessoryLoadedAt || 0) < CATALOG_TTL) return;
      try {
        const catsRes = await categoryApi.getCategories({ limit: 200 });
        const cats = (catsRes && Array.isArray(catsRes.data)) ? catsRes.data : [];
        const sc = cats.find(c => c.slug === 'accessory' || c.name === '配件');
        if (!sc) {
          console.warn('[EcoCenter] 后台未找到「配件」分类(slug=accessory), 配件皮肤仅显示兜底 mock');
          return;
        }
        const res = await productApi.getProducts({ limit: 200, page: 1, category_id: sc.id });
        const items = (res && Array.isArray(res.data)) ? res.data : [];
        this.accessoryCatalog = items.map(p => ({ ...this.mapProduct(p), category: '配件' }));
        this._accessoryLoadedAt = Date.now();
      } catch (e) {
        // 后台拉取失败时保留 mock 兜底, 不置空
        console.warn('[EcoCenter] 配件商品拉取失败, 使用 mock 兜底', e);
      }
    },
    refreshSkills() { return this.$refs.skillMarket && this.$refs.skillMarket.refresh() },
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
    goMoreAccessories() {
      uni.showToast({ title: '查看全部配件分类', icon: 'none' });
    },
    goMoreAccessoriesList() {
      uni.setStorageSync('robotTab', 'accessory');
      uni.switchTab({ url: '/pages/robot/index' });
    },
    onAccessoryClick(item) {
      const data = { ...item, fromHome: true };
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
  z-index: 850;
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
}
.eco-price {
  font-size: 22rpx;
  font-weight: 700;
  color: #165DFF;
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
</style>