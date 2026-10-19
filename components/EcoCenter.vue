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
        <scroll-view class="category-scroll" scroll-x="true" show-scrollbar="false">
          <view class="category-list">
            <text class="category-item" :class="{ active: accessoryCategory === 'all' }" @click="accessoryCategory='all'">全部</text>
            <text class="category-item" :class="{ active: accessoryCategory === 'fight' }" @click="accessoryCategory='fight'">格斗改装</text>
            <text class="category-item" :class="{ active: accessoryCategory === 'skin' }" @click="accessoryCategory='skin'">皮肤/外壳</text>
            <text class="category-item" :class="{ active: accessoryCategory === 'battery' }" @click="accessoryCategory='battery'">电池电源</text>
            <text class="category-item" :class="{ active: accessoryCategory === 'sensor' }" @click="accessoryCategory='sensor'">传感器</text>
            <text class="category-item" :class="{ active: accessoryCategory === 'arm' }" @click="accessoryCategory='arm'">机械臂/夹具</text>
            <text class="category-item more" @click="goMoreAccessoriesList">更多 →</text>
          </view>
        </scroll-view>
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

      <!-- ========== 赛事活动 ========== -->
      <view v-show="currentTab === 'event'" class="tab-panel">
        <state-view
          v-if="!selectedEventId"
          :loading="eventLoading"
          :error="eventError"
          :empty="!eventList.length"
          empty-icon="🏟️"
          empty-text="暂无赛事"
          empty-hint="赛事发布后会在这里展示。"
          compact
          @retry="loadEvents"
        >
          <view class="event-list">
            <view class="event-card" v-for="item in eventList" :key="item.id" @click="showEventDetail(item.id)">
              <view class="event-icon-area" :style="{ backgroundColor: item.bg }">
                <text class="event-icon">{{ item.icon }}</text>
              </view>
              <view class="event-info">
                <text class="event-name">{{ item.name }}</text>
                <text class="event-meta">{{ item.dateShort }} · {{ item.city }}</text>
              </view>
              <view class="event-actions">
                <view class="action-btn primary">查看</view>
              </view>
            </view>
          </view>
        </state-view>

        <view v-else class="event-detail-view">
          <view class="detail-back-btn" @click="closeEventDetail">
            <text class="detail-back-icon">‹</text>
            <text class="detail-back-text">返回列表</text>
          </view>

          <state-view
            :loading="eventDetailLoading"
            :error="eventDetailError"
            :empty="!selectedEvent"
            empty-icon="🏟️"
            empty-text="赛事不存在或已下线"
            compact
            @retry="loadEventDetail"
          >
            <view class="detail-hero" :style="{ backgroundColor: selectedEvent.bg }">
              <text class="detail-hero-icon">{{ selectedEvent.icon }}</text>
              <view class="detail-status-tag" :class="getStatusClass(selectedEvent.status)">
                {{ getStatusText(selectedEvent.status) }}
              </view>
            </view>

            <view class="detail-info-area">
              <text class="detail-name">{{ selectedEvent.name }}</text>

              <view class="detail-info-grid">
                <view class="detail-info-item">
                  <text class="detail-info-label">📅 时间</text>
                  <text class="detail-info-value">{{ selectedEvent.date }}</text>
                </view>
                <view class="detail-info-item">
                  <text class="detail-info-label">📍 地点</text>
                  <text class="detail-info-value">{{ selectedEvent.location }}</text>
                </view>
                <view class="detail-info-item">
                  <text class="detail-info-label">🏢 主办方</text>
                  <text class="detail-info-value">{{ selectedEvent.organizer }}</text>
                </view>
                <view class="detail-info-item">
                  <text class="detail-info-label">📋 类型</text>
                  <text class="detail-info-value">{{ selectedEvent.type }}</text>
                </view>
              </view>

              <view class="detail-section">
                <text class="detail-section-title">📋 赛事简介</text>
                <text class="detail-section-content">{{ selectedEvent.detail }}</text>
              </view>

              <view class="detail-section" v-if="selectedEvent.prize">
                <text class="detail-section-title">🏆 奖项设置</text>
                <text class="detail-section-content">{{ selectedEvent.prize }}</text>
              </view>

              <view class="detail-section" v-if="selectedEvent.schedule">
                <text class="detail-section-title">⏰ 赛程安排</text>
                <text class="detail-section-content">{{ selectedEvent.schedule }}</text>
              </view>

              <view class="detail-section" v-if="selectedEvent.history">
                <text class="detail-section-title">📜 往届回顾</text>
                <text class="detail-section-content">{{ selectedEvent.history }}</text>
              </view>

              <view class="detail-actions">
                <view class="detail-btn primary" v-if="selectedEventRegistration.enabled" @click="registerEvent(selectedEvent)">📝 立即报名</view>
                <view class="detail-reg-reason" v-else>ℹ️ {{ selectedEventRegistration.reason }}</view>
              </view>

              <view style="height: 40rpx;"></view>
            </view>
          </state-view>
        </view>
      </view>

      <!-- ========== 动态资讯（引用组件） ========== -->
      <view v-show="currentTab === 'feed'" class="tab-panel">
        <Dongtai />
      </view>
    </view>

    <!-- ===== 赛事报名弹窗（真实落库） ===== -->
    <view class="event-reg-mask" v-if="showEventReg" @tap="closeEventReg">
      <view class="event-reg-modal" @tap.stop>
        <view class="event-reg-title">赛事报名</view>
        <view class="event-reg-sub">留下联系方式，赛事动态与参赛指引将发送给您</view>
        <input class="event-reg-input" v-model="eventRegForm.name" placeholder="姓名" />
        <input class="event-reg-input" v-model="eventRegForm.phone" type="number" maxlength="11" placeholder="手机号" />
        <view class="event-reg-btn" @tap="submitEventRegistration">确认报名</view>
      </view>
    </view>
  </view>
</template>

<script>
import Dongtai from '@/components/shouye/dongtai.vue'
import StateView from '@/components/StateView.vue'
import { productApi, categoryApi, eventApi } from '@/utils/api'
import { normalizeEvent, normalizeEventList, eventRegistrationState, eventRegistrationFeedback } from '@/utils/ecosystem-events'
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
      accessoryCategory: 'all',
      selectedEvent: null,
      selectedEventId: null,
      eventList: [],
      eventLoading: false,
      eventError: '',
      eventDetailLoading: false,
      eventDetailError: '',

      // v3.4:技能搜索(服务端)—— skillResults 为 null 表示"未搜索,显示全部"



      // B-9:筛选维度(机型/通道/动作类型/风险等级) —— 服务端筛选



      accessoryCatalog: [],
      // 赛事:只展示后台 events 表数据;空/错/格式异常分别交给 StateView
      showEventReg: false,
      regEventId: null,
      eventRegForm: { name: '', phone: '' },

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

    // 配件皮肤: 后台真实商品 + 静态 mock 共存(mock 仍受分类 chips 筛选, 同样按名称去重)
    accessoryCards() {
      const real = this.accessoryCatalog.map(p => ({ ...p, uid: 'r' + p.id }));
      const realNames = new Set(real.map(p => String(p.name || '').trim()));
      const mock = this.filteredAccessoryList
        .filter(m => !realNames.has(m.name))
        .map(m => ({ ...m, uid: 'm' + m.id }));
      return real.concat(mock);
    },
    filteredAccessoryList() {
      return this.accessoryCategory === 'all'
        ? this.accessoryList
        : this.accessoryList.filter(item => item.category === this.accessoryCategory);
    },
    // 报名按钮只认后端 can_register + status=registering,不在前端猜。
    selectedEventRegistration() {
      return eventRegistrationState(this.selectedEvent);
    }
  },
  mounted() {
    this.loadAccessory();
    this.loadEvents();
  },
  methods: {
    switchTab(tab) {
      if (['skill', 'accessory', 'event', 'feed'].includes(tab)) {
        this.currentTab = tab;
        if (tab !== 'event') {
          this.selectedEvent = null;
          this.selectedEventId = null;
          this.eventDetailError = '';
        }
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

    showEventDetail(id) {
      const eventId = Number(id);
      if (!Number.isInteger(eventId) || eventId <= 0) return;
      this.selectedEventId = eventId;
      this.selectedEvent = null;
      this.loadEventDetail();
    },
    closeEventDetail() {
      this.selectedEventId = null;
      this.selectedEvent = null;
      this.eventDetailError = '';
    },
    getStatusClass(status) {
      const map = {
        registering: 'status-registering',
        ongoing: 'status-ongoing',
        ended: 'status-ended',
        upcoming: 'status-upcoming'
      };
      return map[status] || '';
    },
    getStatusText(status) {
      const map = {
        registering: '🔥 报名中',
        ongoing: '⏳ 进行中',
        ended: '✅ 已结束',
        upcoming: '📅 即将开始'
      };
      return map[status] || '';
    },
    // 赛事列表:只用后台真实数据。空列表是空态,请求失败/格式异常是错误态,
    // 都不能拿样例补位 —— 否则用户会把假赛事当真报名。
    async loadEvents() {
      this.eventLoading = true;
      this.eventError = '';
      try {
        const res = await eventApi.getEvents();
        this.eventList = normalizeEventList(res);
      } catch (e) {
        this.eventList = [];
        this.eventError = (e && e.message) || '赛事加载失败';
      } finally {
        this.eventLoading = false;
      }
    },
    // 详情必须按 ID 重新取,不能只依赖列表里的内存对象(列表字段可能已过期)。
    async loadEventDetail() {
      const id = this.selectedEventId;
      if (!id) return;

      this.eventDetailLoading = true;
      this.eventDetailError = '';
      try {
        const res = await eventApi.getEvent(id);
        const event = normalizeEvent(res && res.data);
        if (!event) throw new Error('赛事数据格式异常');
        // 用户可能在请求返回前已经返回列表/切 tab,丢弃过期响应。
        if (this.selectedEventId !== id) return;
        this.selectedEvent = event;
      } catch (e) {
        if (this.selectedEventId !== id) return;
        this.selectedEvent = null;
        this.eventDetailError = (e && e.message) || '赛事详情加载失败';
      } finally {
        if (this.selectedEventId === id) this.eventDetailLoading = false;
      }
    },

    registerEvent(item) {
      const state = eventRegistrationState(item);
      if (!state.enabled) {
        uni.showToast({ title: state.reason, icon: 'none' });
        return;
      }
      this.regEventId = item.id;
      this.eventRegForm = { name: '', phone: '' };
      this.showEventReg = true;
    },
    closeEventReg() {
      this.showEventReg = false;
    },
    submitEventRegistration() {
      const f = this.eventRegForm;
      if (!f.name.trim()) { uni.showToast({ title: '请输入姓名', icon: 'none' }); return }
      if (!/^1\d{10}$/.test(f.phone.trim())) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return }
      eventApi.register(this.regEventId, { name: f.name.trim(), phone: f.phone.trim() })
        .then((res) => {
          const feedback = eventRegistrationFeedback(res);
          if (!feedback.success) {
            // 结果未知:保留弹窗,避免用户误以为已报名或重复提交。
            uni.showToast({ title: feedback.message, icon: 'none' });
            return;
          }
          this.showEventReg = false;
          uni.showToast({ title: feedback.message, icon: 'success' });
          this.loadEventDetail();
        })
        .catch((err) => {
          uni.showToast({ title: (err && err.message) || '报名失败，请稍后重试', icon: 'none' });
        });
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
.category-scroll {
  width: 100%;
  white-space: nowrap;
  margin-bottom: 16rpx;
}
.category-list {
  display: inline-block;
  white-space: nowrap;
  padding: 4rpx 0;
}
.category-item {
  display: inline-block;
  padding: 6rpx 24rpx;
  margin-right: 12rpx;
  font-size: 22rpx;
  color: #6B7280;
  background: #F8FAFC;
  border-radius: 30rpx;
  border: 1px solid #E5E7EB;
  white-space: nowrap;
}
.category-item.active {
  background: #5588FF;
  color: #fff;
  border-color: #165DFF;
}
.category-item.more {
  color: #165DFF;
  border-color: #165DFF;
  background: #F0F7FF;
}

/* ===== 赛事列表 ===== */
.event-list {
  display: flex;
  flex-direction: column;
  gap: 12rpx;
}
.event-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 26rpx 24rpx;
  display: flex;
  align-items: center;
  min-height: 200rpx;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
  border: 1px solid #F1F5F9;
  cursor: pointer;
  transition: all 0.2s;
}
.event-card:active {
  transform: scale(0.98);
  background: #F8FAFC;
}
.event-icon-area {
  width: 160rpx;
  height: 160rpx;
  border-radius: 16rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-right: 20rpx;
}
.event-icon {
  font-size: 72rpx;
}
.event-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
}
.event-name {
  font-size: 30rpx;
  font-weight: 600;
  color: #1F2937;
  margin-bottom: 6rpx;
}
.event-meta {
  font-size: 23rpx;
  color: #9CA3AF;
  margin-top: 4rpx;
}
.event-actions {
  flex-shrink: 0;
}
.action-btn {
  padding: 6rpx 20rpx;
  border-radius: 30rpx;
  font-size: 22rpx;
  font-weight: 500;
  text-align: center;
  white-space: nowrap;
}
.action-btn.primary {
  background: #5588FF;
  color: #fff;
  border: none;
}

/* ===== 赛事详情 ===== */
.event-detail-view {
  animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20rpx); }
  to { opacity: 1; transform: translateY(0); }
}

.detail-back-btn {
  display: flex;
  align-items: center;
  padding: 12rpx 0 20rpx;
  border-bottom: 2rpx solid #F1F5F9;
  margin-bottom: 16rpx;
  cursor: pointer;
}
.detail-back-icon {
  font-size: 44rpx;
  color: #1F2937;
  font-weight: 300;
  margin-right: 6rpx;
}
.detail-back-text {
  font-size: 26rpx;
  color: #1F2937;
}

.detail-hero {
  width: 100%;
  height: 240rpx;
  border-radius: 20rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20rpx;
  position: relative;
}
.detail-hero-icon {
  font-size: 120rpx;
}
.detail-status-tag {
  position: absolute;
  top: 16rpx;
  right: 16rpx;
  font-size: 22rpx;
  font-weight: 600;
  padding: 6rpx 20rpx;
  border-radius: 30rpx;
  color: #fff;
}
.status-registering { background: #FF6B00; }
.status-ongoing { background: #165DFF; }
.status-ended { background: #9CA3AF; }
.status-upcoming { background: #10B981; }

.detail-info-area {
  padding: 0 6rpx;
}
.detail-name {
  font-size: 40rpx;
  font-weight: 700;
  color: #1F2937;
  display: block;
  margin-bottom: 20rpx;
}
.detail-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12rpx 20rpx;
  background: #F8FAFC;
  border-radius: 16rpx;
  padding: 16rpx 20rpx;
  margin-bottom: 20rpx;
}
.detail-info-item {
  display: flex;
  flex-direction: column;
}
.detail-info-label {
  font-size: 22rpx;
  color: #9CA3AF;
}
.detail-info-value {
  font-size: 26rpx;
  color: #1F2937;
  font-weight: 500;
}
.detail-section {
  margin-bottom: 20rpx;
}
.detail-section-title {
  font-size: 26rpx;
  font-weight: 600;
  color: #1F2937;
  display: block;
  margin-bottom: 6rpx;
}
.detail-section-content {
  font-size: 24rpx;
  color: #4B5563;
  line-height: 1.7;
  white-space: pre-line;
}
.detail-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 16rpx;
}
.detail-btn {
  padding: 12rpx 30rpx;
  border-radius: 48rpx;
  font-size: 26rpx;
  font-weight: 600;
  text-align: center;
  flex: 1;
  min-width: 120rpx;
}
.detail-btn.primary {
  background: #165DFF;
  color: #fff;
  border: none;
}
.detail-reg-reason {
  flex: 1;
  min-width: 220rpx;
  padding: 12rpx 24rpx;
  border-radius: 14rpx;
  background: #F8FAFC;
  border: 1px solid #E5E7EB;
  font-size: 24rpx;
  color: #6B7280;
  line-height: 1.5;
}
/* ===== 赛事报名弹窗 ===== */
.event-reg-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.55); z-index: 1200; display: flex; align-items: center; justify-content: center; }
.event-reg-modal { width: 580rpx; background: #fff; border-radius: 24rpx; padding: 40rpx 36rpx; box-sizing: border-box; }
.event-reg-title { font-size: 34rpx; font-weight: 700; color: #1F2937; text-align: center; }
.event-reg-sub { font-size: 24rpx; color: #6B7280; margin: 12rpx 0 24rpx; text-align: center; }
.event-reg-input { height: 84rpx; border: 1px solid #E5E7EB; border-radius: 14rpx; padding: 0 22rpx; font-size: 28rpx; margin-bottom: 18rpx; background: #F8FAFC; }
.event-reg-btn { height: 84rpx; line-height: 84rpx; text-align: center; color: #fff; font-size: 30rpx; font-weight: 600; background: #165DFF; border-radius: 42rpx; }

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