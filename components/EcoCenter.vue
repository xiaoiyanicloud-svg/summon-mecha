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
        <!-- v3.4:服务端搜索(接 /api/skill-catalog/search) -->
        <view class="skill-search">
          <text class="ss-icon">🔍</text>
          <input class="ss-input" v-model="skillQuery" placeholder="搜索技能（名称/关键词）" confirm-type="search" @confirm="doSkillSearch" />
          <text v-if="skillQuery" class="ss-clear" @click="clearSkillSearch">✕</text>
        </view>
        <!-- B-9:筛选维度(机型/通道/动作类型/风险等级)—— 服务端筛选 -->
        <view class="skill-filter">
          <view class="sf-toggle" @click="showFilters = !showFilters">
            <text class="sf-toggle-text">筛选</text>
            <text v-if="activeFilterCount" class="sf-toggle-badge">{{ activeFilterCount }}</text>
            <text class="sf-toggle-arrow">{{ showFilters ? '▲' : '▼' }}</text>
            <text v-if="activeFilterCount" class="sf-reset" @click.stop="resetFilters">重置</text>
          </view>
          <view v-show="showFilters" class="sf-panel">
            <view class="sf-group" v-if="filterOptions.models.length">
              <text class="sf-label">机型</text>
              <view class="sf-chips">
                <text class="sf-chip" :class="{ on: !filters.model }" @click="pickFilter('model', '')">全部</text>
                <text
                  v-for="m in filterOptions.models"
                  :key="m.code"
                  class="sf-chip"
                  :class="{ on: filters.model === m.code }"
                  @click="pickFilter('model', m.code)"
                >{{ m.name }}</text>
              </view>
            </view>
            <view class="sf-group">
              <text class="sf-label">通道</text>
              <view class="sf-chips">
                <text class="sf-chip" :class="{ on: !filters.channel }" @click="pickFilter('channel', '')">全部</text>
                <text
                  v-for="c in filterOptions.channels"
                  :key="c.value"
                  class="sf-chip"
                  :class="{ on: filters.channel === c.value }"
                  @click="pickFilter('channel', c.value)"
                >{{ c.label }}</text>
              </view>
            </view>
            <view class="sf-group" v-if="filterOptions.motions.length">
              <text class="sf-label">动作</text>
              <view class="sf-chips">
                <text class="sf-chip" :class="{ on: !filters.motion }" @click="pickFilter('motion', '')">全部</text>
                <text
                  v-for="t in filterOptions.motions"
                  :key="t.id"
                  class="sf-chip"
                  :class="{ on: filters.motion === t.name }"
                  @click="pickFilter('motion', t.name)"
                >{{ t.name }}</text>
              </view>
            </view>
            <view class="sf-group">
              <text class="sf-label">风险</text>
              <view class="sf-chips">
                <text class="sf-chip" :class="{ on: !filters.risk }" @click="pickFilter('risk', '')">全部</text>
                <text
                  v-for="r in filterOptions.risks"
                  :key="r.value"
                  class="sf-chip"
                  :class="{ on: filters.risk === r.value }"
                  @click="pickFilter('risk', r.value)"
                >{{ r.label }}</text>
              </view>
            </view>
          </view>
        </view>
        <view class="grid-3col">
          <view class="eco-card skill-card" v-for="item in displayedSkills" :key="item.uid" @click.stop="onSkill(item)">
            <view class="eco-img" :style="{ backgroundColor: item.bg }">
              <image v-if="item.image" class="eco-img-img" :src="item.image" mode="aspectFill"></image>
              <text v-else class="eco-icon">{{ item.icon }}</text>
            </view>
            <text class="eco-name">{{ item.name }}</text>
            <!-- 价格为空或 0 时不显示, 避免真机无价商品出现"¥0" -->
            <text class="eco-price" v-if="item.price && String(item.price) !== '0'">¥{{ item.price }}</text>
          </view>
        </view>
        <view v-if="skillSearchActive && !skillSearching && displayedSkills.length === 0" class="skill-empty">
          <text>没有符合条件的技能</text>
        </view>
        <view class="more-entry" @click="goMoreSkills">
          <text class="more-text">更多技能 →</text>
        </view>
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
        <view v-if="!selectedEvent" class="event-list">
          <view class="event-card" v-for="item in eventList" :key="item.id" @click="showEventDetail(item)">
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

        <view v-else class="event-detail-view">
          <view class="detail-back-btn" @click="closeDetail">
            <text class="detail-back-icon">‹</text>
            <text class="detail-back-text">返回列表</text>
          </view>

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
              <view class="detail-info-item">
                <text class="detail-info-label">🔥 热度</text>
                <text class="detail-info-value">{{ selectedEvent.hot }}/100</text>
              </view>
              <view class="detail-info-item">
                <text class="detail-info-label">👥 参赛队伍</text>
                <text class="detail-info-value">{{ selectedEvent.teams }} 支</text>
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
              <view class="detail-btn primary" @click="registerEvent(selectedEvent)">📝 立即报名</view>
              <view class="detail-btn outline" @click="toggleFavEvent(selectedEvent)">
                {{ selectedEvent.fav ? '❤️ 已收藏' : '🤍 收藏' }}
              </view>
              <view class="detail-btn share" @click="shareEvent(selectedEvent)">↗️ 分享</view>
            </view>

            <view style="height: 40rpx;"></view>
          </view>
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
import { productApi, categoryApi, eventApi, skillCatalogApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import SKILL_LIST, { ECO_SKILL_IDS } from '@/components/jiqi/skill-data'

// tab 数据刷新节流: 5 分钟内重复切 tab 不重拉(与机器页 loadCatalog 的 TTL 策略一致)
const CATALOG_TTL = 5 * 60 * 1000;

export default {
  name: 'EcoCenter',
  components: { Dongtai },
  data() {
    return {
      currentTab: 'skill',
      accessoryCategory: 'all',
      selectedEvent: null,
      skillCatalog: [],
      // v3.4:技能搜索(服务端)—— skillResults 为 null 表示"未搜索,显示全部"
      skillQuery: '',
      skillResults: null,
      skillSearching: false,
      // B-9:筛选维度(机型/通道/动作类型/风险等级) —— 服务端筛选
      showFilters: false,
      filters: { model: '', channel: '', motion: '', risk: '' },
      filterOptions: { models: [], channels: [], risks: [], motions: [] },
      accessoryCatalog: [],
      // 赛事:后台 events 表数据(拉取失败保留 mock 兜底);报名弹窗
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

      eventList: [
        {
          id: 1,
          name: '机甲格斗大赛',
          status: 'registering',
          date: '2026年8月15日 9:00-18:00',
          dateShort: '2026年8月15日',
          location: '深圳国际会展中心',
          city: '深圳',
          organizer: '机甲格斗联盟',
          type: '格斗竞技',
          hot: 98,
          teams: 32,
          icon: '🏟️',
          bg: '#FEF3C7',
          detail: '2026机甲格斗大赛深圳站即将开赛！汇聚全国顶尖选手，采用3V3团队赛制，奖金池50万元。现场设有观众体验区，可亲自操控迷你格斗机器人。',
          prize: '🏆 冠军：50万元 + 奖杯\n🥈 亚军：20万元\n🥉 季军：10万元\n🌟 最佳设计奖：5万元',
          schedule: '📌 8月15日 9:00 开幕式\n📌 8月15日 10:00 小组赛\n📌 8月15日 14:00 半决赛\n📌 8月15日 17:00 决赛+颁奖',
          history: '2025年首届大赛吸引60支队伍参赛，现场观众超5000人，线上直播观看量突破100万。',
          fav: false
        },
        {
          id: 2,
          name: '机器人竞速赛',
          status: 'upcoming',
          date: '2026年9月1日 8:00-17:00',
          dateShort: '2026年9月1日',
          location: '北京亦庄机器人产业园',
          city: '北京',
          organizer: '中国机器人运动协会',
          type: '竞速赛',
          hot: 85,
          teams: 24,
          icon: '🏁',
          bg: '#E8F0FE',
          detail: '2026机器人竞速赛北京站正式开启！包含地面竞速和空中竞速两个组别，赛道总长500米，设有多种障碍关卡。',
          prize: '🏆 冠军：10万元 + 奖牌\n🥈 亚军：5万元\n🥉 季军：3万元',
          schedule: '📌 9月1日 8:00 选手签到\n📌 9月1日 9:00 预赛\n📌 9月1日 13:00 半决赛\n📌 9月1日 16:00 决赛',
          history: '往届赛事吸引了来自全国50余所高校和企业队伍参与，打破了多项纪录。',
          fav: false
        },
        {
          id: 3,
          name: '技术交流沙龙',
          status: 'ongoing',
          date: '2026年8月22日 14:00-18:00',
          dateShort: '2026年8月22日',
          location: '上海陆家嘴金融中心',
          city: '上海',
          organizer: '机器人产业联盟',
          type: '行业沙龙',
          hot: 72,
          teams: 0,
          icon: '💬',
          bg: '#F0F4FA',
          detail: '聚焦「人形机器人商业化路径」，邀请行业专家、企业高管分享最新成果与市场洞察，现场圆桌讨论+自由交流。',
          prize: '🎁 参与即送行业报告',
          schedule: '📌 8月22日 14:00 主题演讲\n📌 8月22日 15:30 圆桌论坛\n📌 8月22日 17:00 自由交流',
          history: '已成功举办10届，累计参与人数超过2000人。',
          fav: false
        },
        {
          id: 4,
          name: '高校机器人挑战',
          status: 'registering',
          date: '2026年8月30日 线上赛',
          dateShort: '2026年8月30日',
          location: '线上平台',
          city: '线上',
          organizer: '教育部机器人竞赛组委会',
          type: '高校竞赛',
          hot: 90,
          teams: 48,
          icon: '🎯',
          bg: '#E8F0FE',
          detail: '面向全国高校在校生，参赛队伍需完成自主导航、物体抓取、语音交互三项任务。优秀队伍将获实习推荐和科研资助。',
          prize: '🏆 一等奖：科研基金 5万元\n🥈 二等奖：3万元\n🥉 三等奖：1万元\n🏅 优秀奖：精美礼品',
          schedule: '📌 8月30日 10:00 任务发布\n📌 8月30日 14:00 提交截止\n📌 8月31日 公布结果',
          history: '去年有超过200支队伍报名参赛，覆盖全国150所高校。',
          fav: false
        },
        {
          id: 5,
          name: '改装工坊开放日',
          status: 'upcoming',
          date: '2026年9月5日 10:00-17:00',
          dateShort: '2026年9月5日',
          location: '广州机器人创客空间',
          city: '广州',
          organizer: '机器人创客空间',
          type: '体验活动',
          hot: 65,
          teams: 0,
          icon: '🔧',
          bg: '#FEF3C7',
          detail: '向公众免费开放！现场提供改装工具与材料，专业工程师手把手指导改装技巧。适合爱好者、极客、亲子家庭。',
          prize: '🎁 完成作品可带走纪念品',
          schedule: '📌 9月5日 10:00 开场介绍\n📌 10:30 改装教学\n📌 14:00 自由改装\n📌 16:30 作品展示',
          history: '往期活动参与人数超过300人，满意度达98%。',
          fav: false
        },
        {
          id: 6,
          name: '机器人格斗联赛',
          status: 'ongoing',
          date: '2026年8月-12月（全国巡回）',
          dateShort: '2026年8月起',
          location: '全国10个城市',
          city: '全国巡回',
          organizer: '全国机器人格斗联赛组委会',
          type: '巡回联赛',
          hot: 95,
          teams: 120,
          icon: '🏆',
          bg: '#E8F0FE',
          detail: '2026全国机器人格斗联赛覆盖深圳、北京、上海、广州、成都等10城。赛季总冠军获「机甲之王」称号及100万元奖金。',
          prize: '🏆 总冠军：100万元 + 定制奖杯\n🥈 分站冠军：10万元\n🥉 最佳人气奖：5万元',
          schedule: '📌 8月 深圳站\n📌 9月 北京站\n📌 10月 上海站\n📌 11月 广州站\n📌 12月 成都站（决赛）',
          history: '联赛已成功举办三届，累计参赛队伍超过500支，已成为国内规模最大的机器人格斗赛事。',
          fav: false
        },
        {
          id: 7,
          name: '机甲科技展',
          status: 'upcoming',
          date: '2026年9月18日 9:00-17:00',
          dateShort: '2026年9月18日',
          location: '杭州国际博览中心',
          city: '杭州',
          organizer: '机甲科技展组委会',
          type: '展览',
          hot: 78,
          teams: 0,
          icon: '🎪',
          bg: '#FCE4EC',
          detail: '大型机甲主题科技展，现场展示多款人形与四足机器人，设互动体验区与新品首发。',
          prize: '🎫 现场扫码领门票',
          schedule: '📌 9月18日 9:00 开幕\n📌 9月18日 10:30 新品发布\n📌 9月18日 14:00 互动体验',
          history: '首届机甲科技展，预计观众超万人。',
          fav: false
        },
        {
          id: 8,
          name: '机器人马拉松',
          status: 'registering',
          date: '2026年10月1日 8:00-16:00',
          dateShort: '2026年10月1日',
          location: '苏州工业园区',
          city: '苏州',
          organizer: '智能机器人协会',
          type: '耐力赛',
          hot: 82,
          teams: 40,
          icon: '🏃',
          bg: '#D1F5E0',
          detail: '参赛机器人需自主完成20公里循迹赛道，考验续航与算法稳定性。',
          prize: '🏆 冠军：20万元\n🥈 亚军：8万元\n🥉 季军：4万元',
          schedule: '📌 10月1日 8:00 签到\n📌 10月1日 9:00 出发\n📌 10月1日 14:00 颁奖',
          history: '新增挑战组别，面向高校与企业开放。',
          fav: false
        }
      ]
    };
  },
  computed: {
    // 技能市场: 后台真实商品在前; mock 兜底展示 ECO_SKILL_IDS 子集(保持历史卡片顺序)。
    // mock 按名称去重 —— 后台已建同名商品时不再重复展示(后台全量建品后 mock 自然退场, 与机器页策略对齐)
    skillCards() {
      const real = this.skillCatalog.map(p => ({ ...p, uid: 'r' + p.id }));
      const realNames = new Set(real.map(p => String(p.name || '').trim()));
      const mock = ECO_SKILL_IDS
        .map(id => SKILL_LIST.find(m => m.id === id))
        .filter(m => m && !realNames.has(m.name))
        .map(m => ({ ...m, uid: 'm' + m.id }));
      return real.concat(mock);
    },
    // v3.4:搜索中显示搜索结果,否则显示全部(skillResults 为 null = 未搜索)
    displayedSkills() {
      if (this.skillResults === null) return this.skillCards
      return this.skillResults.map(p => ({ ...p, uid: 's' + p.id, icon: p.icon || '🤖', bg: p.bg || '#F0F4FA' }))
    },
    // B-9:非空筛选维度(送给服务端)
    activeFilters() {
      const f = this.filters
      const out = {}
      if (f.model) out.model = f.model
      if (f.channel) out.channel = f.channel
      if (f.motion) out.motion = f.motion
      if (f.risk) out.risk = f.risk
      return out
    },
    activeFilterCount() {
      return Object.keys(this.activeFilters).length
    },
    // 有搜索词或任一筛选维度 → 走服务端搜索(否则显示全部)
    skillSearchActive() {
      return (this.skillQuery || '').trim() !== '' || this.activeFilterCount > 0
    },
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
    }
  },
  mounted() {
    this.loadSkill();
    this.loadAccessory();
    this.loadEvents();
    this.loadFilters();
  },
  methods: {
    switchTab(tab) {
      if (['skill', 'accessory', 'event', 'feed'].includes(tab)) {
        this.currentTab = tab;
        if (tab !== 'event') this.selectedEvent = null;
      }
      if (tab === 'skill') { this.loadSkill(); this.loadFilters(); }
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
    async loadSkill() {
      // TTL 内跳过(切 tab 高频触发); 后台建品后最多 5 分钟生效
      if (Date.now() - (this._skillLoadedAt || 0) < CATALOG_TTL) return;
      try {
        const catsRes = await categoryApi.getCategories({ limit: 200 });
        const cats = (catsRes && Array.isArray(catsRes.data)) ? catsRes.data : [];
        const sc = cats.find(c => c.slug === 'skill' || c.name === '技能');
        if (!sc) {
          console.warn('[EcoCenter] 后台未找到「技能」分类(slug=skill), 技能市场仅显示兜底 mock');
          return;
        }
        const res = await productApi.getProducts({ limit: 200, page: 1, category_id: sc.id });
        const items = (res && Array.isArray(res.data)) ? res.data : [];
        this.skillCatalog = items.map(p => this.mapProduct(p));
        this._skillLoadedAt = Date.now();
      } catch (e) {
        // 后台拉取失败时保留 mock 兜底, 不置空
        console.warn('[EcoCenter] 技能商品拉取失败, 使用 mock 兜底', e);
      }
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
    onSkill(item) {
      // 跳机器页技能 tab, 打开该商品的详情浮层(复用机器页完整详情/下单逻辑)
      const data = { ...item, fromHome: true };
      uni.setStorageSync('skillDetail', data);
      uni.setStorageSync('robotTab', 'skill');
      uni.switchTab({ url: '/pages/robot/index' });
    },
    // v3.4:服务端技能搜索(接 /api/skill-catalog/search) + B-9 四筛选维度
    async doSkillSearch() {
      const q = (this.skillQuery || '').trim()
      const f = this.activeFilters
      // 无搜索词也无筛选 → 回到"显示全部"(不请求)
      if (!q && Object.keys(f).length === 0) { this.skillResults = null; return }
      this.skillSearching = true
      try {
        const res = await skillCatalogApi.search(q, f)
        this.skillResults = (res && Array.isArray(res.data)) ? res.data : []
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '搜索失败', icon: 'none' })
      } finally {
        this.skillSearching = false
      }
    },
    clearSkillSearch() {
      this.skillQuery = ''
      this.doSkillSearch()
    },
    // B-9:筛选候选项(机型/通道/风险走 filters;动作形态走既有 tags 接口)
    async loadFilters() {
      if (this._filtersLoaded) return
      this._filtersLoaded = true
      try {
        const [f, motions] = await Promise.all([
          skillCatalogApi.filters(),
          skillCatalogApi.tags('motion_type'),
        ])
        const d = (f && f.data) || {}
        this.filterOptions = {
          models: Array.isArray(d.models) ? d.models : [],
          channels: Array.isArray(d.channels) ? d.channels : [],
          risks: Array.isArray(d.risks) ? d.risks : [],
          motions: (motions && Array.isArray(motions.data)) ? motions.data : [],
        }
      } catch (e) {
        // 候选项拉不到 → 筛选面板留空(不编假选项),搜索本身仍可用
        console.warn('[EcoCenter] 筛选候选项加载失败', e)
        this._filtersLoaded = false
      }
    },
    // B-9:点选一个筛选维度(再点一次取消)→ 立即重新搜索
    pickFilter(key, value) {
      this.filters = { ...this.filters, [key]: this.filters[key] === value ? '' : value }
      this.doSkillSearch()
    },
    resetFilters() {
      this.filters = { model: '', channel: '', motion: '', risk: '' }
      this.doSkillSearch()
    },
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

    showEventDetail(item) {
      this.selectedEvent = item;
    },
    closeDetail() {
      this.selectedEvent = null;
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
    // 赛事:后台 events 表优先(平台级闭环),失败保留 mock 兜底
    loadEvents() {
      eventApi.getEvents()
        .then((res) => {
          const list = (res && res.data) || []
          if (Array.isArray(list) && list.length) {
            this.eventList = list.map(e => ({ ...e, fav: false }))
          }
        })
        .catch((e) => {
          // 保留 mock 兜底(页面不白屏),但留 warn —— 否则"后台赛事一直不来"这件事没人知道
          console.warn('[EcoCenter] 赛事列表加载失败,保留本地兜底', e)
        })
    },

    registerEvent(item) {
      if (item.status === 'ended') {
        uni.showToast({ title: '该赛事已结束报名', icon: 'none' });
        return;
      }
      // 真实报名:落 event_registrations 表(后台报名列表可查)
      this.regEventId = item.id
      this.eventRegForm = { name: '', phone: '' }
      this.showEventReg = true
    },
    closeEventReg() {
      this.showEventReg = false
    },
    submitEventRegistration() {
      const f = this.eventRegForm
      if (!f.name.trim()) { uni.showToast({ title: '请输入姓名', icon: 'none' }); return }
      if (!/^1\d{10}$/.test(f.phone.trim())) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return }
      eventApi.register(this.regEventId, { name: f.name.trim(), phone: f.phone.trim() })
        .then((res) => {
          this.showEventReg = false
          uni.showToast({ title: (res && res.message) || '报名成功', icon: 'success' })
        })
        .catch((err) => {
          uni.showToast({ title: (err && err.message) || '报名失败，请稍后重试', icon: 'none' })
        })
    },
    toggleFavEvent(item) {
      item.fav = !item.fav;
      uni.showToast({
        title: item.fav ? '已收藏' : '已取消收藏',
        icon: 'none'
      });
    },
    shareEvent(item) {
      uni.showToast({ title: '已复制分享链接', icon: 'none' });
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
.detail-btn.outline {
  background: transparent;
  color: #165DFF;
  border: 2rpx solid #165DFF;
}
.detail-btn.share {
  background: #EEF2F6;
  color: #1F2937;
  border: none;
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