<template>
  <view class="scene-detail-page">
    <!-- 自定义导航栏 -->
    <view class="custom-nav">
      <view class="nav-left" @tap="goBack">
        <text class="nav-back">‹</text>
      </view>
      <text class="nav-title">{{ sceneInfo.name || '场景方案' }}</text>
      <view class="nav-right" @tap="openService">
        <SmIcon name="service" size="36rpx" />
      </view>
    </view>

    <!-- 可滚动内容 -->
    <scroll-view class="page-scroll" scroll-y="true" :show-scrollbar="false">
      <StateView :loading="loading" :error="loadError" @retry="reload" />
      <view v-if="!loading && !loadError && sceneLoaded">
      <!-- 顶部Banner + 简介 -->
      <view class="banner-area">
        <image v-if="sceneInfo.banner" class="banner-image" :src="sceneInfo.banner" mode="aspectFill" @error="sceneInfo.banner = ''"></image>
        <view class="banner-text">
          <text class="scene-name">{{ sceneInfo.name }}</text>
          <text class="scene-desc">{{ sceneInfo.desc }}</text>
        </view>
      </view>

      <!-- 服务入口：场景租用 / 方案定制 -->
      <view class="mode-bar">
        <view class="mode-btn" :class="{ active: activeMode === 'rent' }" @tap="setActiveMode('rent')">场景租用</view>
        <view class="mode-btn" :class="{ active: activeMode === 'custom' }" @tap="setActiveMode('custom')">方案定制</view>
      </view>

      <!-- ===== 场景租用（场景说明 + 适用机型） ===== -->
      <view v-if="activeMode === 'rent'">
        <!-- 场景说明卡片 -->
        <view class="scene-intro-card">
          <text class="intro-title">场景说明</text>
          <text class="intro-content">{{ sceneInfo.intro || '具体服务条件请提交需求后确认。' }}</text>
        </view>
        <view v-if="contentEvidence.length" class="scene-intro-card"><text class="intro-title">已授权观察记录</text><view v-for="(proof,index) in contentEvidence" :key="proof.id || index"><text class="intro-title">{{ proof.title }}</text><text class="intro-content">{{ proof.summary }}</text><text class="intro-content">适用边界：{{ proof.applicability }}</text><text v-if="proof.kind==='metric'" class="intro-content">观察值 {{ proof.value }} {{ proof.unit }} · 样本 {{ proof.sample_size }}</text><text class="intro-content">{{ proof.observed_from }} 至 {{ proof.observed_until }} UTC；观察记录不构成效果保证。</text></view></view>

        <!-- 适用机型 -->
        <view class="product-section">
          <view class="product-section-head">
            <text class="section-title">场景配置机型</text>
            <text class="section-badge" v-if="filteredProducts.length">可选 {{ filteredProducts.length }} 款</text>
          </view>
          <view class="data-note">选择机型、台数与租期后可直接下单；如需定制改造可切换「方案定制」。</view>
          <StateView :empty="!filteredProducts.length" empty-text="当前未配置可租机型" empty-hint="可切换方案定制，提交您的具体需求" />
          <view class="product-grid">
            <view class="product-card" :class="{ selected: isMachineSelected(item) }" v-for="(item, idx) in filteredProducts" :key="item.id || idx" @tap="goProductDetail(item)">
              <view class="product-image" :style="{ backgroundColor: item.bg || '#F0F4FA' }">
                <image v-if="item.image" class="product-image-img" :src="item.image" mode="aspectFill" @error="onMachineImageError(item)"></image>
                <SmIcon v-else-if="!item.icon || item.icon === '🤖'" name="empty-robot" size="64rpx" />
                <text v-else class="product-icon">{{ item.icon }}</text>
              </view>
              <view class="product-info">
                <text class="product-name">{{ item.name }}</text>
                <view class="product-tags-row" v-if="item.brandName || item.typeLabel || item.minRentDays > 1 || item.rentableStock !== null">
                  <text class="product-tag brand" v-if="item.brandName">{{ item.brandName }}</text>
                  <text class="product-tag" v-if="item.typeLabel">{{ item.typeLabel }}</text>
                  <text class="product-tag min-days" v-if="item.minRentDays > 1">{{ item.minRentDays }}天起租</text>
                  <text class="product-tag stock-out" v-if="item.rentableStock !== null && item.rentableStock <= 0">暂无可租库存</text>
                </view>
                <text class="product-scene" v-else>{{ item.scene }}</text>
                <view class="product-price-row">
                  <view class="product-price">
                    <text class="price">¥{{ item.price }}</text>
                    <text class="unit" v-if="item.unit">{{ item.unit }}</text>
                    <text class="origin-price" v-if="item.originalPrice">¥{{ item.originalPrice }}</text>
                  </view>
                </view>
                <view class="card-qty-row" v-if="isMachineSelected(item)" @tap.stop>
                  <text class="card-qty-label">数量</text>
                  <view class="card-stepper">
                    <text class="step-btn" @tap.stop="changeMachineQty(item, -1)">−</text>
                    <text class="step-num">{{ machineQty(item) }}</text>
                    <text class="step-btn" @tap.stop="changeMachineQty(item, 1)">＋</text>
                  </view>
                </view>
              </view>
              <!-- 多机合单:选择/取消,不触发卡片跳详情 -->
              <view class="product-select-btn" :class="{ on: isMachineSelected(item) }" @tap.stop="toggleMachine(item)">
                <text>{{ isMachineSelected(item) ? '✓' : '＋' }}</text>
              </view>
            </view>
          </view>

        </view>
      </view>

      <!-- ===== 方案定制（痛点→方案 / 亮点 / 预期收益；不罗列机型） ===== -->
      <view v-else>
        <view v-if="!scheme.pains.length && !scheme.benefits.length && !scheme.scenes.length" class="data-note">具体服务范围、交付条件及报价需根据您的需求确认。</view>
        <view class="card" v-if="scheme.pains.length">
          <view class="card-title-row">
            <text class="card-title">你的核心痛点</text>
            <text class="card-sub">遇到这些难题？我们这样解决</text>
          </view>
          <view class="pain-list">
            <view class="pain-item" v-for="(pain, idx) in scheme.pains" :key="idx">
              <view class="pain-left">
                <text class="pain-icon">{{ pain.icon }}</text>
                <text class="pain-text">{{ pain.text }}</text>
              </view>
              <view class="pain-solve">
                <text class="solve-icon">✓</text>
                <text class="solve-text">{{ pain.solve }}</text>
              </view>
            </view>
          </view>
        </view>

        <view class="card" v-if="scheme.benefits.length">
          <view class="card-title-row">
            <text class="card-title">方案亮点</text>
          </view>
          <view class="benefit-grid">
            <view class="benefit-item" v-for="(b, idx) in scheme.benefits" :key="idx">
              <view class="benefit-icon">{{ b.icon }}</view>
              <text class="benefit-title">{{ b.title }}</text>
              <text class="benefit-desc">{{ b.desc }}</text>
            </view>
          </view>
        </view>

        <view class="card" v-if="scheme.scenes.length">
          <view class="card-title-row">
            <text class="card-title">服务内容说明</text>
          </view>
          <scroll-view class="scene-scroll" scroll-x :show-scrollbar="false">
            <view class="scheme-scene" v-for="(scene, idx) in scheme.scenes" :key="idx">
              <view class="scheme-scene-mark">
                <text class="scheme-scene-name">{{ scene.name }}</text>
              </view>
              <text class="scheme-scene-desc">{{ scene.desc }}</text>

            </view>
          </scroll-view>

        </view>

      </view>

      </view>
      <!-- 底部占位（给固定底部选型栏留出安全高度） -->
      <view :style="{ height: (activeMode === 'rent' && selectedMachines.length > 0) ? '420rpx' : '160rpx' }"></view>
    </scroll-view>

    <!-- 免费定制 CTA（固定悬浮底部，仅方案定制模式） -->
    <view class="custom-cta" v-if="sceneLoaded && !loading && activeMode === 'custom'" @tap="onConsult">
      <text>提交场景需求</text>
    </view>

    <!-- ===== 场景租用多机选型下单栏 ===== -->
    <view class="scene-cart-bar" v-if="sceneLoaded && !loading && activeMode === 'rent' && selectedMachines.length > 0">
      <view class="cart-head-row">
        <view class="cart-summary-left">
          <text class="scene-cart-count">已选 {{ selectedMachines.length }} 款 · 共 {{ totalMachineCount }} 台</text>
          <text class="cart-min-tip" v-if="minRentDaysRequired > 1">最低 {{ minRentDaysRequired }} 天起租</text>
        </view>
        <text class="cart-toggle-btn" @tap="showSelectedDrawer = !showSelectedDrawer">{{ showSelectedDrawer ? '收起清单 ▾' : '调整数量 ▴' }}</text>
      </view>

      <!-- 可展开的已选机型数量清单 -->
      <scroll-view class="cart-selected-list" scroll-y="true" v-if="showSelectedDrawer">
        <view class="cart-selected-item" v-for="m in selectedMachines" :key="m.id">
          <view class="cart-selected-info">
            <text class="cart-selected-name">{{ m.name }}</text>
            <text class="cart-selected-price">¥{{ m.price }}/天{{ m.minRentDays > 1 ? ' · ' + m.minRentDays + '天起' : '' }}</text>
          </view>
          <view class="card-stepper">
            <text class="step-btn" @tap="changeMachineQty(m, -1)">−</text>
            <text class="step-num">{{ m.qty || 1 }}</text>
            <text class="step-btn" @tap="changeMachineQty(m, 1)">＋</text>
          </view>
          <text class="cart-item-remove" @tap="removeMachine(m)">✕</text>
        </view>
      </scroll-view>

      <!-- 起租日期 & 预计归还 -->
      <view class="cart-date-row">
        <text class="cart-row-label">起租日期</text>
        <view class="cart-date-pill" @tap="openStartDatePicker">
          <text>{{ rentStartDate || todayStr() }}</text>
          <text class="date-arrow">▾</text>
        </view>
        <text class="cart-date-end">至 {{ rentEndDate }}</text>
      </view>

      <!-- 租期快选 + 天数步进 -->
      <view class="scene-cart-row">
        <scroll-view class="scene-cart-days" scroll-x="true" :show-scrollbar="false">
          <view class="days-track">
            <text
              class="day-chip"
              :class="{ active: rentDays === d, disabled: d < minRentDaysRequired || d > maxRentDaysAllowed }"
              v-for="d in rentDayOptions"
              :key="d"
              @tap="selectRentDays(d)"
            >{{ d }}天</text>
          </view>
        </scroll-view>
        <view class="days-stepper">
          <text class="day-step-btn" @tap="adjustRentDays(-1)">−</text>
          <text class="day-step-val">{{ rentDays }}天</text>
          <text class="day-step-btn" @tap="adjustRentDays(1)">＋</text>
        </view>
      </view>

      <view class="cart-submit-row">
        <view class="cart-total-box">
          <view class="cart-total-main">
            <text class="cart-total-label">合计</text>
            <text class="scene-cart-total">¥{{ formatMoney(sceneTotal()) }}</text>
          </view>
          <text class="cart-deposit-note" v-if="totalDeposit() > 0">另含参考押金 ¥{{ formatMoney(totalDeposit()) }}</text>
        </view>
        <view class="scene-cart-submit" :class="{ disabled: orderSubmitting }" @tap="submitSceneOrder">
          {{ orderSubmitting ? '核验中…' : '立即下单' }}
        </view>
      </view>
    </view>

    <!-- ===== 单机预览与快速下单弹层 ===== -->
    <view class="machine-modal-mask" v-if="showMachineModal && previewMachine" @tap="closeMachineDetail">
      <view class="machine-sheet" @tap.stop>
        <view class="machine-sheet-head">
          <view class="machine-sheet-thumb" :style="{ backgroundColor: previewMachine.bg || '#F0F4FA' }">
            <image v-if="previewMachine.image" class="machine-sheet-img" :src="previewMachine.image" mode="aspectFill" @error="previewMachine.image = ''" />
            <SmIcon v-else name="empty-robot" size="64rpx" />
          </view>
          <view class="machine-sheet-meta">
            <text class="machine-sheet-name">{{ previewMachine.name }}</text>
            <view class="product-tags-row" v-if="previewMachine.brandName || previewMachine.typeLabel">
              <text class="product-tag brand" v-if="previewMachine.brandName">{{ previewMachine.brandName }}</text>
              <text class="product-tag" v-if="previewMachine.typeLabel">{{ previewMachine.typeLabel }}</text>
            </view>
            <view class="machine-sheet-price-row">
              <text class="price">¥{{ previewMachine.price }}</text>
              <text class="unit">/天</text>
              <text class="origin-price" v-if="previewMachine.originalPrice">¥{{ previewMachine.originalPrice }}</text>
            </view>
          </view>
          <text class="machine-sheet-close" @tap="closeMachineDetail">✕</text>
        </view>

        <scroll-view class="machine-sheet-body" scroll-y="true">
          <view class="machine-specs-card" v-if="previewSpecs.length">
            <view class="machine-spec-row" v-for="(sp, idx) in previewSpecs" :key="idx">
              <text class="machine-spec-label">{{ sp.name }}</text>
              <text class="machine-spec-val">{{ sp.value }}</text>
            </view>
          </view>

          <view class="machine-desc-box" v-if="previewMachine.description">
            <text class="machine-desc-text">{{ previewMachine.description }}</text>
          </view>

          <view class="machine-config-row">
            <text class="machine-config-label">租用台数</text>
            <view class="card-stepper lg">
              <text class="step-btn" @tap="changePreviewQty(-1)">−</text>
              <text class="step-num">{{ previewQty }}</text>
              <text class="step-btn" @tap="changePreviewQty(1)">＋</text>
            </view>
          </view>

          <view class="machine-config-row">
            <text class="machine-config-label">起租日期</text>
            <view class="cart-date-pill" @tap="openStartDatePicker">
              <text>{{ rentStartDate || todayStr() }}</text>
              <text class="date-arrow">▾</text>
            </view>
          </view>

          <view class="machine-config-row column">
            <view class="machine-config-head">
              <text class="machine-config-label">租期天数</text>
              <text class="cart-min-tip" v-if="previewMachine.minRentDays > 1">最低起租 {{ previewMachine.minRentDays }} 天</text>
            </view>
            <view class="preview-days-wrap">
              <text
                class="day-chip"
                :class="{ active: previewDays === d, disabled: d < (previewMachine.minRentDays || 1) || (previewMachine.maxRentDays > 0 && d > previewMachine.maxRentDays) }"
                v-for="d in rentDayOptions"
                :key="d"
                @tap="selectPreviewDays(d)"
              >{{ d }}天</text>
            </view>
          </view>
        </scroll-view>

        <view class="machine-sheet-footer">
          <view class="sheet-btn secondary" @tap="addPreviewToSelection">加入选型</view>
          <view class="sheet-btn primary" :class="{ disabled: orderSubmitting }" @tap="orderSingleMachine">
            立即下单 · ¥{{ formatMoney(previewSubtotal) }}
          </view>
        </view>
        <view class="sheet-custom-link" @tap="consultFromMachine">需要定制改造或现场勘察？提交需求 ›</view>
      </view>
    </view>

    <!-- ===== 起租日期选择弹层 ===== -->
    <view class="date-modal-mask" v-if="showDatePicker" @tap="showDatePicker = false">
      <view class="date-modal" @tap.stop>
        <view class="date-modal-head">
          <text class="date-modal-title">选择起租日期</text>
          <text class="date-modal-close" @tap="showDatePicker = false">✕</text>
        </view>
        <picker-view class="date-pv" :value="dateVal" @change="onStartDateChange">
          <picker-view-column><view v-for="y in dateYearList" :key="y" class="pv-item">{{ y }}年</view></picker-view-column>
          <picker-view-column><view v-for="m in monthColumn()" :key="m" class="pv-item">{{ m }}月</view></picker-view-column>
          <picker-view-column><view v-for="d in dayColumn()" :key="d" class="pv-item">{{ d }}日</view></picker-view-column>
        </picker-view>
        <view class="date-modal-ok" @tap="showDatePicker = false">确定（{{ rentStartDate }}）</view>
      </view>
    </view>

  </view>
</template>

<script>

import { sceneApi } from '@/utils/api'
import { openSceneRequest, sceneDraftPayload } from '@/utils/scene-request'
import { BASE_URL } from '@/utils/config'
import StateView from '@/components/StateView.vue'
import SmIcon from '@/components/SmIcon.vue'
import { sceneId, configuredSceneProducts, normalizeSceneImage } from '@/utils/scene-catalog'

export default {
  components: { StateView, SmIcon },
  data() {
    const now = new Date()
    const y = now.getFullYear()
    return {
      sceneId: '', activeMode: 'rent', loading: true, loadError: '', loadVersion: 0, sceneLoaded: false, contentEvidence: [],
      sceneInfo: { name: '', banner: '', desc: '', intro: '', category: 'enterprise', scene_revision_id: null },
      scheme: { pains: [], benefits: [], scenes: [] }, sceneRobots: [], rentCatalog: [],
      selectedMachines: [], rentDays: 1, rentDayOptions: [1, 3, 7, 15, 30],
      rentStartDate: `${y}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`,
      showDatePicker: false, dateYearList: [y, y + 1, y + 2], dateVal: [0, now.getMonth(), now.getDate() - 1],
      showSelectedDrawer: false, orderSubmitting: false,
      showMachineModal: false, previewMachine: null, previewQty: 1, previewDays: 1,
    }
  },
  computed: {
    filteredProducts() { return configuredSceneProducts(this.sceneRobots, this.rentCatalog) },
    totalMachineCount() {
      return this.selectedMachines.reduce((sum, m) => sum + Math.max(1, Number(m.qty) || 1), 0)
    },
    minRentDaysRequired() {
      if (!this.selectedMachines.length) return 1
      return this.selectedMachines.reduce((mx, m) => Math.max(mx, Number(m.minRentDays) || 1), 1)
    },
    rentEndDate() {
      return this.addDays(this.rentStartDate || this.todayStr(), this.rentDays || 1)
    },
    previewSubtotal() {
      if (!this.previewMachine) return 0
      return Math.round(Number(this.previewMachine.price || 0) * Math.max(1, this.previewQty) * Math.max(1, this.previewDays) * 100) / 100
    },
    previewSpecs() {
      const m = this.previewMachine
      if (!m) return []
      const rows = []
      if (m.brandName) rows.push({ name: '品牌', value: m.brandName })
      if (m.typeLabel) rows.push({ name: '机器人形态', value: m.typeLabel })
      if (m.compatibleModelNames && m.compatibleModelNames.length) {
        rows.push({ name: '适配机型', value: m.compatibleModelNames.join('、') })
      }
      if (m.commChannels && m.commChannels.length) {
        rows.push({ name: '指令通道', value: m.commChannels.join(' / ') })
      }
      if (m.minRentDays > 1) rows.push({ name: '最低起租', value: m.minRentDays + ' 天' })
      if (m.depositYuan) rows.push({ name: '参考押金', value: '¥' + m.depositYuan + '/台' })
      if (Array.isArray(m.paramSpecs)) {
        for (const ps of m.paramSpecs) {
          if (ps && ps.name && ps.value) rows.push({ name: ps.name, value: ps.value })
        }
      }
      return rows
    },
  },
  onLoad(options) {
    const q = options || {}
    this.sceneId = sceneId(q.sceneId) || ''
    if (q.mode === 'custom' || q.mode === 'rent') this.activeMode = q.mode
  },
  onShow() { this.reload() },
  onUnload() { this.loadVersion++ },
  methods: {
    async reload() {
      const version = ++this.loadVersion
      this.loading = true; this.loadError = ''; this.sceneLoaded = false
      try {
        const res = await sceneApi.getSceneDetail(this.sceneId)
        if (version !== this.loadVersion) return
        if (!res || !res.matched || !res.scheme || !Array.isArray(res.products)) throw new Error('场景数据已更新，请刷新或重新进入场景')
        this.sceneInfo = { name: res.name, category: res.category, scene_revision_id: res.scene_revision_id, banner: normalizeSceneImage(res.banner, BASE_URL), desc: res.description || '', intro: res.description || '' }
        if (['pains', 'benefits', 'scenes', 'robots'].some(key => res.scheme[key] != null && !Array.isArray(res.scheme[key]))) throw new Error('场景数据格式异常，请重试')
        this.scheme = { pains: res.scheme.pains || [], benefits: res.scheme.benefits || [], scenes: res.scheme.scenes || [] }
        this.contentEvidence = Array.isArray(res.content_evidence) ? res.content_evidence : []
        this.sceneRobots = res.scheme.robots || []
        this.rentCatalog = res.products.map(this.mapCatalogProduct).filter(Boolean)
        const prevQtyById = new Map(this.selectedMachines.map(old => [old.id, Math.max(1, Number(old.qty) || 1)]))
        this.selectedMachines = this.filteredProducts
          .filter(item => prevQtyById.has(item.id))
          .map(item => ({ ...item, qty: prevQtyById.get(item.id) || 1 }))
        if (this.minRentDaysRequired > this.rentDays) this.rentDays = this.minRentDaysRequired
        if (!this.selectedMachines.length) this.showSelectedDrawer = false
        this.sceneLoaded = true
      } catch (e) {
        if (version !== this.loadVersion) return
        this.loadError = e.message || '场景加载失败，请重试'
        this.sceneRobots = []; this.rentCatalog = []; this.selectedMachines = []; this.showSelectedDrawer = false
      } finally { if (version === this.loadVersion) this.loading = false }
    },
    mapCatalogProduct(p) {
      const finalPrice = p.prices && ((p.prices.final && p.prices.final.price) ?? (p.prices.regular && p.prices.regular.price))
      const regPrice = p.prices && p.prices.regular && p.prices.regular.price
      const price = typeof finalPrice === 'number' || (typeof finalPrice === 'string' && /^\d+(\.\d+)?$/.test(finalPrice.trim())) ? Number(finalPrice) : NaN
      if (!sceneId(p.id) || p.type !== 'simple' || !Number.isFinite(price) || price < 0) return null
      const regularNum = (typeof regPrice === 'number' || (typeof regPrice === 'string' && /^\d+(\.\d+)?$/.test(regPrice.trim()))) ? Number(regPrice) : NaN
      const raasMeta = p.raas_meta && typeof p.raas_meta === 'object' ? p.raas_meta : null
      const minRentDays = Math.max(1, Number((raasMeta && raasMeta.min_rent_days) || 1))
      const plainDesc = String(p.description || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
      return {
        id: Number(p.id),
        bagistoId: Number(p.id),
        sku: p.sku || '',
        name: p.name || '机器人',
        price: String(price),
        originalPrice: Number.isFinite(regularNum) && regularNum > price ? String(regularNum) : '',
        type: p.type,
        brandName: p.brand && p.brand.name ? p.brand.name : '',
        typeLabel: p.robot_type && p.robot_type.label ? p.robot_type.label : '',
        scene: (p.robot_type && p.robot_type.label) || this.sceneInfo.name || '场景租赁',
        minRentDays,
        depositYuan: (raasMeta && raasMeta.deposit_yuan) ? String(raasMeta.deposit_yuan) : '',
        compatibleModelNames: (raasMeta && Array.isArray(raasMeta.compatible_model_names)) ? raasMeta.compatible_model_names : [],
        commChannels: (raasMeta && Array.isArray(raasMeta.comm_channels)) ? raasMeta.comm_channels : [],
        paramSpecs: (raasMeta && Array.isArray(raasMeta.param_specs)) ? raasMeta.param_specs : [],
        description: plainDesc,
        icon: '🤖',
        bg: '#F0F4FA',
        unit: '/天',
        image: normalizeSceneImage(p.base_image && p.base_image.medium_image_url, BASE_URL),
      }
    },
    setActiveMode(mode) { this.activeMode = mode },
    goBack() { uni.navigateBack() },
    openService() { if (this.sceneLoaded && !this.loading) this.onConsult() },
    goProductDetail(item) {
      if (!this.canSelect(item)) return
      const current = this.selectedMachines.find(m => m.id === item.id)
      this.previewMachine = { ...item }
      this.previewQty = current ? Math.max(1, Number(current.qty) || 1) : 1
      this.previewDays = Math.max(this.rentDays || 1, item.minRentDays || 1)
      this.showMachineModal = true
    },
    closeMachineDetail() {
      this.showMachineModal = false
      this.previewMachine = null
    },
    canSelect(item) { return this.sceneLoaded && !this.loading && this.filteredProducts.some(p => p.id === item.id) },
    isMachineSelected(item) { return this.selectedMachines.some(m => m.id === item.id) },
    machineQty(item) {
      const hit = this.selectedMachines.find(m => m.id === item.id)
      return hit ? Math.max(1, Number(hit.qty) || 1) : 0
    },
    toggleMachine(item) {
      if (!this.canSelect(item)) return
      const index = this.selectedMachines.findIndex(m => m.id === item.id)
      if (index >= 0) {
        this.selectedMachines.splice(index, 1)
        if (!this.selectedMachines.length) this.showSelectedDrawer = false
      } else {
        this.selectedMachines.push({ ...item, qty: 1 })
        if (this.minRentDaysRequired > this.rentDays) {
          this.rentDays = this.minRentDaysRequired
        }
      }
    },
    changeMachineQty(item, delta) {
      if (!this.canSelect(item)) return
      const index = this.selectedMachines.findIndex(m => m.id === item.id)
      if (index < 0) return
      const next = (Number(this.selectedMachines[index].qty) || 1) + delta
      if (next <= 0) {
        this.selectedMachines.splice(index, 1)
        if (!this.selectedMachines.length) this.showSelectedDrawer = false
      } else if (next <= 99) {
        this.selectedMachines.splice(index, 1, { ...this.selectedMachines[index], qty: next })
      }
    },
    removeMachine(item) {
      const index = this.selectedMachines.findIndex(m => m.id === item.id)
      if (index >= 0) {
        this.selectedMachines.splice(index, 1)
        if (!this.selectedMachines.length) this.showSelectedDrawer = false
      }
    },
    selectRentDays(days) {
      const d = Math.max(1, Number(days) || 1)
      if (d < this.minRentDaysRequired) {
        uni.showToast({ title: '所选机型最低起租 ' + this.minRentDaysRequired + ' 天', icon: 'none' })
        return
      }
      this.rentDays = d
    },
    adjustRentDays(delta) {
      const next = (Number(this.rentDays) || 1) + delta
      if (next < this.minRentDaysRequired) {
        uni.showToast({ title: '所选机型最低起租 ' + this.minRentDaysRequired + ' 天', icon: 'none' })
        return
      }
      if (next <= 3650) this.rentDays = next
    },
    selectPreviewDays(days) {
      if (!this.previewMachine) return
      const minD = this.previewMachine.minRentDays || 1
      if (days < minD) {
        uni.showToast({ title: '该机型最低起租 ' + minD + ' 天', icon: 'none' })
        return
      }
      this.previewDays = days
    },
    addPreviewToSelection() {
      const m = this.previewMachine
      if (!m || !this.canSelect(m)) return
      const qty = Math.max(1, Math.min(99, Number(this.previewQty) || 1))
      const index = this.selectedMachines.findIndex(x => x.id === m.id)
      if (index >= 0) {
        this.selectedMachines.splice(index, 1, { ...m, qty })
      } else {
        this.selectedMachines.push({ ...m, qty })
      }
      this.rentDays = Math.max(this.rentDays, this.previewDays, this.minRentDaysRequired)
      this.closeMachineDetail()
      uni.showToast({ title: '已加入选型方案', icon: 'none' })
    },
    async orderSingleMachine() {
      const m = this.previewMachine
      if (!m || !this.canSelect(m) || this.orderSubmitting) return
      const qty = Math.max(1, Math.min(99, Number(this.previewQty) || 1))
      const days = Math.max(m.minRentDays || 1, Number(this.previewDays) || 1)
      this.closeMachineDetail()
      await this.dispatchCheckout([{ ...m, qty }], days)
    },
    consultFromMachine() {
      const m = this.previewMachine
      this.closeMachineDetail()
      if (!m) return
      this.openRequest('咨询「' + (this.sceneInfo.name || '场景方案') + '」中的机型「' + m.name + '」定制改造或现场部署。')
    },
    todayStr() {
      const d = new Date()
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    },
    addDays(dateStr, days) {
      if (!dateStr) return ''
      const [yy, mm, dd] = String(dateStr).split('-').map(Number)
      const d = new Date(yy, (mm || 1) - 1, dd || 1)
      d.setDate(d.getDate() + (days || 0))
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    },
    openStartDatePicker() {
      const base = this.rentStartDate || this.todayStr()
      const [yy, mm, dd] = base.split('-').map(Number)
      const nowY = new Date().getFullYear()
      this.dateYearList = [nowY, nowY + 1, nowY + 2]
      const yi = Math.max(0, this.dateYearList.indexOf(yy))
      this.dateVal = [yi, Math.max(0, (mm || 1) - 1), Math.max(0, (dd || 1) - 1)]
      this.showDatePicker = true
    },
    monthColumn() { return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] },
    dayColumn() {
      const y = this.dateYearList[this.dateVal[0]] || this.dateYearList[0]
      const m = (this.dateVal[1] || 0) + 1
      const n = new Date(y, m, 0).getDate()
      const arr = []
      for (let i = 1; i <= n; i++) arr.push(i)
      return arr
    },
    onStartDateChange(e) {
      const [yi, mi, di] = (e && e.detail && e.detail.value) || [0, 0, 0]
      const y = this.dateYearList[yi] || this.dateYearList[0]
      const m = mi + 1
      const maxDay = new Date(y, m, 0).getDate()
      const d = Math.min(di + 1, maxDay)
      this.dateVal = [yi, mi, d - 1]
      this.rentStartDate = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    },
    scenePerDay() {
      return this.selectedMachines.reduce((sum, m) => sum + Number(m.price || 0) * Math.max(1, Number(m.qty) || 1), 0)
    },
    sceneTotal() {
      return Math.round(this.scenePerDay() * Math.max(1, Number(this.rentDays) || 1) * 100) / 100
    },
    totalDeposit() {
      return Math.round(this.selectedMachines.reduce((sum, m) => sum + Number(m.depositYuan || 0) * Math.max(1, Number(m.qty) || 1), 0) * 100) / 100
    },
    formatMoney(v) {
      return Number(v || 0).toLocaleString('en-US')
    },
    async submitSceneOrder() {
      if (!this.selectedMachines.length || !this.selectedMachines.every(this.canSelect) || this.orderSubmitting) return
      const minDays = this.minRentDaysRequired
      if (this.rentDays < minDays) {
        this.rentDays = minDays
        uni.showToast({ title: '所选机型最低起租 ' + minDays + ' 天，已自动调整租期', icon: 'none' })
        return
      }
      await this.dispatchCheckout(this.selectedMachines, this.rentDays)
    },
    async dispatchCheckout(machines, days) {
      if (!machines.length || this.orderSubmitting) return
      this.orderSubmitting = true
      try {
        // 下单前向服务端重新核验场景状态、配置机型、最新价格与最低起租天数
        const latest = await sceneApi.getSceneDetail(this.sceneId)
        if (!latest || !latest.matched || !latest.scheme || !Array.isArray(latest.products)) {
          throw new Error('场景数据已更新，请刷新后重试')
        }
        const latestCatalog = latest.products.map(this.mapCatalogProduct).filter(Boolean)
        const latestEligible = configuredSceneProducts(latest.scheme.robots || [], latestCatalog)
        const resolved = machines.map(m => {
          const hit = latestEligible.find(p => Number(p.id) === Number(m.id))
          return hit ? { ...hit, qty: Math.max(1, Number(m.qty) || 1) } : null
        })
        if (resolved.some(x => !x)) {
          await this.reload()
          uni.showToast({ title: '部分机型已下线或移出本场景，请重新选型', icon: 'none' })
          return
        }
        this.sceneInfo = {
          name: latest.name || this.sceneInfo.name,
          category: latest.category || this.sceneInfo.category,
          scene_revision_id: latest.scene_revision_id || null,
          banner: normalizeSceneImage(latest.banner, BASE_URL),
          desc: latest.description || '',
          intro: latest.description || '',
        }
        this.sceneRobots = latest.scheme.robots || []
        this.rentCatalog = latestCatalog
        const minRequired = resolved.reduce((mx, m) => Math.max(mx, Number(m.minRentDays) || 1), 1)
        const finalDays = Math.max(1, Number(days) || 1)
        if (finalDays < minRequired) {
          this.rentDays = minRequired
          uni.showToast({ title: '所选机型最低起租 ' + minRequired + ' 天，请重新确认', icon: 'none' })
          return
        }
        const startDate = this.rentStartDate || this.todayStr()
        const totalQty = resolved.reduce((sum, m) => sum + m.qty, 0)
        const perDay = resolved.reduce((sum, m) => sum + Number(m.price || 0) * m.qty, 0)
        const items = resolved.map(m => ({
          product_id: m.bagistoId || m.id,
          name: m.name,
          price: m.price,
          qty: m.qty,
          min_rent_days: m.minRentDays || 1,
          deposit_yuan: m.depositYuan || '',
          scene: this.sceneInfo.name || m.scene || '场景租用',
          icon: m.icon || '🤖',
          bg: m.bg || '#F0F4FA',
          image: m.image || '',
        }))
        let url =
          `/pages/order/checkout?items=${encodeURIComponent(JSON.stringify(items))}` +
          `&rent_days=${encodeURIComponent(String(finalDays))}` +
          `&type=rent` +
          `&from=scene` +
          `&sceneId=${encodeURIComponent(String(this.sceneId))}` +
          `&scene=${encodeURIComponent(this.sceneInfo.name || '')}` +
          `&rentPeriod=${encodeURIComponent(finalDays + '天')}` +
          `&startDate=${encodeURIComponent(startDate)}` +
          `&name=${encodeURIComponent((this.sceneInfo.name || '场景租用') + '（' + totalQty + '台）')}` +
          `&price=${encodeURIComponent(String(perDay))}`
        if (this.sceneInfo.scene_revision_id) {
          url += `&sceneRevisionId=${encodeURIComponent(String(this.sceneInfo.scene_revision_id))}`
        }
        uni.navigateTo({ url })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '场景机型核验失败，请重试', icon: 'none' })
      } finally {
        this.orderSubmitting = false
      }
    },
    onConsult() {
      if (!this.sceneLoaded || this.loading) return
      this.openRequest('希望沟通「' + this.sceneInfo.name + '」的场景服务方案。')
    },
    openRequest(text) {
      openSceneRequest(sceneDraftPayload({ source: 'scene', sceneId: this.sceneId, revisionId: this.sceneInfo.scene_revision_id, category: this.sceneInfo.category, text, contact: { name: '', phone: '' } }))
    }
  }
}

</script>

<style scoped>
page {
  background: #f5f7fa;
  height: 100%;
}
.scene-detail-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: #ffffff;
  position: relative;
}

/* 自定义导航栏 */
.custom-nav {
  position: relative;
  flex-shrink: 0;
  z-index: 100;
  height: 88rpx;
  padding: env(safe-area-inset-top) 24rpx 0;
  background: #ffffff;
  border-bottom: 1rpx solid #F1F5F9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16rpx;
}
.nav-left, .nav-right {
  width: 60rpx;
  height: 60rpx;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #F8FAFC;
  border-radius: 50%;
}
.nav-back {
  font-size: 40rpx;
  font-weight: 300;
  line-height: 1;
  color: #1F2937;
}
.nav-title {
  flex: 1;
  min-width: 0;
  text-align: center;
  font-size: 32rpx;
  font-weight: 600;
  color: #1F2937;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nav-service {
  font-size: 36rpx;
}

/* 滚动内容 */
.page-scroll {
  flex: 1;
  margin-top: 0;
  height: 0; /* 配合flex */
  padding-bottom: 20rpx;
}

/* Banner */
.banner-area {
  position: relative;
  width: 100%;
  height: 360rpx;
  background: linear-gradient(135deg, #102F6E, #165DFF);
}
.banner-image {
  width: 100%;
  height: 100%;
}
.banner-text {
  position: absolute;
  bottom: 20rpx;
  left: 30rpx;
  right: 30rpx;
  background: rgba(0,0,0,0.4);
  border-radius: 16rpx;
  padding: 16rpx 24rpx;
}
.scene-name {
  font-size: 36rpx;
  font-weight: 700;
  color: #ffffff;
  display: block;
}
.scene-desc {
  font-size: 24rpx;
  color: rgba(255,255,255,0.85);
  margin-top: 4rpx;
}

/* 场景说明卡片 */
.scene-intro-card {
  margin: 20rpx 30rpx;
  background: #F8FAFC;
  border-radius: 24rpx;
  padding: 24rpx;
}
.intro-title {
  font-size: 28rpx;
  font-weight: 600;
  color: #1F2937;
  display: block;
  margin-bottom: 12rpx;
}
.intro-content {
  font-size: 26rpx;
  color: #4B5563;
  line-height: 1.6;
}

/* 通用区块标题 */
.section-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #1F2937;
  display: block;
  padding: 20rpx 30rpx 12rpx;
}

/* 案例展示 */
.case-section {
  padding: 0 20rpx 10rpx;
}
.case-grid {
  display: flex;
  gap: 16rpx;
  padding: 0 10rpx;
}
.case-item {
  flex: 1;
  border-radius: 16rpx;
  overflow: hidden;
  background: #F8FAFC;
}
.case-image {
  width: 100%;
  height: 140rpx;
}
.case-title {
  display: block;
  font-size: 22rpx;
  color: #1F2937;
  padding: 8rpx 12rpx;
  text-align: center;
}

/* 商品列表 */
.product-section {
  padding: 0 20rpx;
}
.product-grid {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 16rpx;
}
.product-card {
  width: calc(50% - 8rpx);
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.06);
  margin-bottom: 16rpx;
}
.product-image {
  height: 220rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.product-image-img {
  width: 100%;
  height: 100%;
}
.product-icon {
  font-size: 72rpx;
}
.product-info {
  padding: 12rpx 16rpx 16rpx;
}
.product-name {
  font-size: 26rpx;
  font-weight: 600;
  color: #1F2937;
  display: block;
}
.product-scene {
  font-size: 20rpx;
  color: #6B7280;
  display: block;
  margin-top: 4rpx;
}
.product-price {
  margin-top: 8rpx;
}
.price {
  font-size: 30rpx;
  font-weight: 700;
  color: #FF7820;
}
.unit {
  font-size: 22rpx;
  color: #9CA3AF;
  margin-left: 4rpx;
}

.load-more {
  text-align: center;
  padding: 20rpx 0;
  font-size: 24rpx;
  color: #9CA3AF;
}

.product-card:active {
  opacity: 0.95;
}

/* 底部占位 */

/* ===== 服务入口：场景租用 / 方案定制 ===== */
.mode-bar {
  display: flex;
  gap: 16rpx;
  margin: 20rpx 30rpx;
}
.mode-btn {
  flex: 1;
  height: 76rpx;
  line-height: 76rpx;
  text-align: center;
  font-size: 28rpx;
  font-weight: 600;
  color: #64748B;
  background: #F1F5F9;
  border-radius: 40rpx;
  border: 2rpx solid #E2E8F0;
  transition: all 0.2s;
}
.mode-btn.active {
  color: #fff;
  background: linear-gradient(135deg, #165DFF, #3B82F6);
  border-color: #165DFF;
}

/* ===== 方案定制卡片 ===== */
.card {
  background: #ffffff;
  border-radius: 24rpx;
  margin: 20rpx 30rpx 0;
  padding: 30rpx 28rpx;
  box-shadow: 0 6rpx 24rpx rgba(15, 23, 42, 0.05);
}
.card-title-row { display: flex; flex-direction: column; margin-bottom: 24rpx; }
.card-title { font-size: 32rpx; font-weight: 700; color: #0F172A; }
.card-sub { font-size: 24rpx; color: #64748B; margin-top: 6rpx; }

/* 痛点 → 方案 */
.pain-list { display: flex; flex-direction: column; gap: 20rpx; }
.pain-item {
  background: #F6F9FE;
  border-radius: 18rpx;
  padding: 22rpx 20rpx;
  border-left: 6rpx solid #2563EB;
}
.pain-left { display: flex; align-items: flex-start; gap: 12rpx; }
.pain-icon { font-size: 32rpx; }
.pain-text { font-size: 27rpx; color: #1E293B; font-weight: 500; line-height: 1.5; flex: 1; }
.pain-solve {
  display: flex;
  align-items: center;
  gap: 10rpx;
  margin-top: 16rpx;
  padding-top: 16rpx;
  border-top: 1rpx solid rgba(37, 99, 235, 0.14);
}
.solve-icon {
  width: 32rpx; height: 32rpx; line-height: 32rpx; text-align: center;
  border-radius: 50%;
  background: #2563EB; color: #fff; font-size: 20rpx; font-weight: 700;
  flex-shrink: 0;
}
.solve-text { font-size: 26rpx; color: #1D4ED8; font-weight: 500; flex: 1; line-height: 1.5; }

/* 方案亮点 */
.benefit-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14rpx; }
.benefit-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 26rpx 12rpx;
  background: #FAFCFF;
  border: 1rpx solid #EDF1F7;
  border-radius: 16rpx;
}
.benefit-icon {
  width: 64rpx; height: 64rpx; border-radius: 18rpx;
  display: flex; align-items: center; justify-content: center;
  background: #E4EEFF; font-size: 34rpx; margin-bottom: 12rpx;
}
.benefit-title { font-size: 26rpx; font-weight: 600; color: #1E293B; }
.benefit-desc {
  font-size: 22rpx; color: #7A8BA6; margin-top: 6rpx; line-height: 1.4;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}

/* 预期收益（横滑） */
.scene-scroll { white-space: nowrap; padding-bottom: 8rpx; }
.scheme-scene {
  display: inline-block;
  width: 300rpx;
  margin-right: 16rpx;
  background: #F6F9FE;
  border-radius: 18rpx;
  overflow: hidden;
  border: 1rpx solid #E6EEFF;
  vertical-align: top;
}
.scheme-scene-mark {
  padding: 22rpx 20rpx 18rpx;
  background: linear-gradient(135deg, #2563EB, #0EA5B7);
}
.scheme-scene-name { font-size: 27rpx; font-weight: 600; color: #fff; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.scheme-scene-desc { display: block; padding: 16rpx 20rpx 4rpx; font-size: 24rpx; color: #475569; line-height: 1.5; white-space: normal; min-height: 76rpx; }
.scheme-scene-data { padding: 0 20rpx 20rpx; }
.scheme-scene-data-num { display: inline-block; font-size: 24rpx; font-weight: 700; color: #2563EB; }
.data-note { font-size: 20rpx; color: #94A3B8; margin-top: 16rpx; padding-top: 16rpx; border-top: 1rpx solid #EEF2F7; }

/* 免费定制 CTA（固定悬浮底部，与底部边框保持安全距离） */
.custom-cta {
  position: fixed;
  left: 30rpx;
  right: 30rpx;
  bottom: calc(env(safe-area-inset-bottom) + 30rpx);
  height: 88rpx;
  line-height: 88rpx;
  z-index: 1100;
  text-align: center;
  border-radius: 44rpx;
  font-size: 30rpx;
  font-weight: 600;
  color: #fff;
  background: linear-gradient(135deg, #1E6FFF, #0EA5B7);
  box-shadow: 0 10rpx 24rpx rgba(30, 111, 255, 0.3);
}
.custom-cta:active { opacity: 0.85; }

/* ===== 场景租用多机选型下单 ===== */
.product-section-head { display: flex; align-items: center; justify-content: space-between; padding: 20rpx 10rpx 8rpx; }
.product-section-head .section-title { padding: 0; }
.section-badge { font-size: 22rpx; color: #165DFF; background: #EEF4FF; padding: 4rpx 14rpx; border-radius: 999rpx; font-weight: 600; }
.product-section .data-note { margin: 0 10rpx 18rpx; padding: 0; border-top: 0; font-size: 22rpx; color: #64748B; }
.product-card { position: relative; display: flex; flex-direction: column; border: 2rpx solid transparent; box-sizing: border-box; }
.product-card.selected { border-color: #165DFF; box-shadow: 0 4rpx 18rpx rgba(22, 93, 255, 0.16); }
.product-info { flex: 1; display: flex; flex-direction: column; padding: 14rpx 16rpx 16rpx; }
.product-name { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35; min-height: 70rpx; }
.product-tags-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6rpx; margin-top: 8rpx; }
.product-tag { font-size: 18rpx; color: #475569; background: #F1F5F9; padding: 2rpx 10rpx; border-radius: 8rpx; line-height: 1.4; }
.product-tag.brand { color: #1D4ED8; background: #EFF6FF; font-weight: 600; }
.product-tag.min-days { color: #B45309; background: #FEF3C7; font-weight: 600; }
.product-price-row { margin-top: auto; padding-top: 10rpx; }
.origin-price { font-size: 20rpx; color: #94A3B8; text-decoration: line-through; margin-left: 8rpx; }
.card-qty-row { display: flex; align-items: center; justify-content: space-between; margin-top: 10rpx; padding-top: 10rpx; border-top: 1rpx solid #F1F5F9; }
.card-qty-label { font-size: 22rpx; color: #64748B; }
.card-stepper { display: inline-flex; align-items: center; gap: 12rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 999rpx; padding: 4rpx 10rpx; }
.card-stepper.lg { padding: 8rpx 16rpx; gap: 18rpx; }
.step-btn { width: 34rpx; height: 34rpx; line-height: 32rpx; text-align: center; font-size: 26rpx; font-weight: 600; color: #1E293B; border-radius: 50%; background: #FFFFFF; }
.step-num { min-width: 28rpx; text-align: center; font-size: 24rpx; font-weight: 600; color: #0F172A; }
.product-select-btn { position: absolute; top: 12rpx; right: 12rpx; width: 50rpx; height: 50rpx; border-radius: 50%; background: rgba(255, 255, 255, 0.92); border: 1rpx solid #CBD5E1; color: #475569; display: flex; align-items: center; justify-content: center; font-size: 30rpx; line-height: 1; z-index: 2; box-shadow: 0 2rpx 8rpx rgba(15, 23, 42, 0.1); }
.product-select-btn.on { background: #165DFF; border-color: #165DFF; color: #fff; }
.scene-cart-bar { position: fixed; left: 20rpx; right: 20rpx; bottom: calc(env(safe-area-inset-bottom) + 20rpx); background: #fff; border-radius: 24rpx; box-shadow: 0 10rpx 36rpx rgba(15, 23, 42, 0.18); padding: 18rpx 22rpx 20rpx; z-index: 1100; border: 1rpx solid #E2E8F0; }
.cart-head-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12rpx; }
.cart-summary-left { display: flex; align-items: center; gap: 12rpx; flex-wrap: wrap; }
.scene-cart-count { font-size: 25rpx; font-weight: 700; color: #1F2937; flex-shrink: 0; }
.cart-min-tip { font-size: 20rpx; color: #B45309; background: #FEF3C7; padding: 2rpx 10rpx; border-radius: 8rpx; font-weight: 600; }
.cart-toggle-btn { font-size: 22rpx; color: #165DFF; font-weight: 600; flex-shrink: 0; }
.cart-selected-list { max-height: 220rpx; margin-bottom: 12rpx; background: #F8FAFC; border-radius: 14rpx; padding: 8rpx 14rpx; box-sizing: border-box; }
.cart-selected-item { display: flex; align-items: center; gap: 12rpx; padding: 10rpx 0; border-bottom: 1rpx solid #E2E8F0; }
.cart-selected-item:last-child { border-bottom: 0; }
.cart-selected-info { flex: 1; min-width: 0; }
.cart-selected-name { display: block; font-size: 23rpx; font-weight: 600; color: #1E293B; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cart-selected-price { display: block; font-size: 20rpx; color: #FF7820; margin-top: 2rpx; }
.cart-item-remove { font-size: 24rpx; color: #94A3B8; padding: 6rpx 10rpx; }
.cart-date-row { display: flex; align-items: center; gap: 12rpx; margin-bottom: 12rpx; }
.cart-row-label { font-size: 22rpx; color: #64748B; flex-shrink: 0; }
.cart-date-pill { display: inline-flex; align-items: center; gap: 8rpx; padding: 8rpx 16rpx; background: #F1F5F9; border-radius: 12rpx; font-size: 22rpx; color: #1E293B; font-weight: 600; }
.date-arrow { font-size: 18rpx; color: #64748B; }
.cart-date-end { font-size: 21rpx; color: #64748B; }
.scene-cart-row { display: flex; align-items: center; gap: 12rpx; margin-bottom: 14rpx; }
.scene-cart-days { flex: 1; min-width: 0; white-space: nowrap; }
.days-track { display: inline-flex; gap: 8rpx; }
.day-chip { display: inline-block; font-size: 22rpx; color: #475569; background: #F1F5F9; border-radius: 24rpx; padding: 8rpx 18rpx; flex-shrink: 0; }
.day-chip.active { background: #165DFF; color: #fff; font-weight: 600; }
.day-chip.disabled { opacity: 0.42; }
.days-stepper { display: inline-flex; align-items: center; gap: 8rpx; background: #F8FAFC; border: 1rpx solid #E2E8F0; border-radius: 999rpx; padding: 4rpx 8rpx; flex-shrink: 0; }
.day-step-btn { width: 36rpx; height: 36rpx; line-height: 34rpx; text-align: center; font-size: 24rpx; color: #1E293B; background: #FFFFFF; border-radius: 50%; }
.day-step-val { font-size: 22rpx; font-weight: 600; color: #165DFF; min-width: 58rpx; text-align: center; }
.cart-submit-row { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.cart-total-box { display: flex; flex-direction: column; }
.cart-total-main { display: flex; align-items: baseline; gap: 8rpx; }
.cart-total-label { font-size: 22rpx; color: #64748B; }
.scene-cart-total { font-size: 36rpx; font-weight: 700; color: #FF7820; flex-shrink: 0; }
.cart-deposit-note { font-size: 19rpx; color: #64748B; margin-top: 2rpx; }
.scene-cart-submit { flex: 1; max-width: 300rpx; height: 80rpx; line-height: 80rpx; text-align: center; color: #fff; font-size: 28rpx; font-weight: 600; background: linear-gradient(135deg, #165DFF, #0EA5B7); border-radius: 40rpx; box-shadow: 0 6rpx 18rpx rgba(22, 93, 255, 0.28); }
.scene-cart-submit.disabled, .sheet-btn.disabled { opacity: 0.5; }

/* ===== 单机预览与日期弹层 ===== */
.machine-modal-mask, .date-modal-mask { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); z-index: 1200; display: flex; align-items: flex-end; justify-content: center; }
.date-modal-mask { align-items: center; }
.machine-sheet { width: 100%; max-height: 84vh; background: #fff; border-radius: 28rpx 28rpx 0 0; padding: 28rpx 28rpx calc(24rpx + env(safe-area-inset-bottom)); box-sizing: border-box; display: flex; flex-direction: column; }
.machine-sheet-head { display: flex; align-items: flex-start; gap: 18rpx; padding-bottom: 20rpx; border-bottom: 1rpx solid #F1F5F9; }
.machine-sheet-thumb { width: 128rpx; height: 128rpx; border-radius: 16rpx; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.machine-sheet-img { width: 100%; height: 100%; }
.machine-sheet-meta { flex: 1; min-width: 0; }
.machine-sheet-name { display: block; font-size: 30rpx; font-weight: 700; color: #0F172A; line-height: 1.35; }
.machine-sheet-price-row { display: flex; align-items: baseline; margin-top: 10rpx; }
.machine-sheet-close { font-size: 34rpx; color: #94A3B8; padding: 4rpx 8rpx; }
.machine-sheet-body { max-height: 46vh; margin: 16rpx 0; }
.machine-specs-card { background: #F8FAFC; border-radius: 14rpx; padding: 8rpx 18rpx; margin-bottom: 16rpx; }
.machine-spec-row { display: flex; justify-content: space-between; align-items: center; padding: 12rpx 0; border-bottom: 1rpx solid #EEF2F7; gap: 16rpx; }
.machine-spec-row:last-child { border-bottom: 0; }
.machine-spec-label { font-size: 23rpx; color: #64748B; flex-shrink: 0; }
.machine-spec-val { font-size: 23rpx; color: #0F172A; font-weight: 500; text-align: right; }
.machine-desc-box { margin-bottom: 16rpx; padding: 14rpx 18rpx; background: #F8FAFC; border-radius: 14rpx; }
.machine-desc-text { font-size: 23rpx; color: #475569; line-height: 1.6; }
.machine-config-row { display: flex; align-items: center; justify-content: space-between; padding: 14rpx 0; border-bottom: 1rpx solid #F1F5F9; }
.machine-config-row.column { flex-direction: column; align-items: stretch; gap: 12rpx; border-bottom: 0; }
.machine-config-head { display: flex; align-items: center; justify-content: space-between; }
.machine-config-label { font-size: 26rpx; font-weight: 600; color: #1E293B; }
.preview-days-wrap { display: flex; flex-wrap: wrap; gap: 10rpx; }
.machine-sheet-footer { display: flex; gap: 16rpx; margin-top: 12rpx; }
.sheet-btn { flex: 1; height: 84rpx; line-height: 84rpx; text-align: center; border-radius: 42rpx; font-size: 28rpx; font-weight: 600; }
.sheet-btn.secondary { background: #EEF4FF; color: #165DFF; border: 1rpx solid #BFDBFE; }
.sheet-btn.primary { background: linear-gradient(135deg, #165DFF, #0EA5B7); color: #fff; }
.sheet-custom-link { text-align: center; font-size: 22rpx; color: #64748B; margin-top: 16rpx; padding: 6rpx 0; }
.date-modal { width: 640rpx; background: #fff; border-radius: 24rpx; padding: 28rpx; box-sizing: border-box; }
.date-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16rpx; }
.date-modal-title { font-size: 30rpx; font-weight: 700; color: #0F172A; }
.date-modal-close { font-size: 30rpx; color: #94A3B8; padding: 6rpx 10rpx; }
.date-pv { height: 340rpx; }
.pv-item { height: 68rpx; line-height: 68rpx; text-align: center; font-size: 26rpx; color: #1E293B; }
.date-modal-ok { margin-top: 20rpx; height: 80rpx; line-height: 80rpx; text-align: center; border-radius: 40rpx; background: #165DFF; color: #fff; font-size: 28rpx; font-weight: 600; }
</style>
