<template>
  <view class="scene-detail-page">
    <!-- 自定义导航栏 -->
    <view class="custom-nav">
      <view class="nav-left" @tap="goBack">
        <text class="nav-back">‹</text>
      </view>
      <view class="nav-right" @tap="openService">
        <text class="nav-service">💬</text>
      </view>
    </view>

    <!-- 可滚动内容 -->
    <scroll-view class="page-scroll" scroll-y="true" :show-scrollbar="false">
      <StateView :loading="loading" :error="loadError" @retry="reload" />
      <view v-if="!loading && !loadError && sceneLoaded">
      <!-- 顶部Banner + 简介 -->
      <view class="banner-area">
        <image v-if="sceneInfo.banner" class="banner-image" :src="sceneInfo.banner" mode="aspectFill"></image>
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
          <text class="intro-title">📌 场景说明</text>
          <text class="intro-content">{{ sceneInfo.intro || '具体服务条件请提交需求后确认。' }}</text>
        </view>
        <view v-if="contentEvidence.length" class="scene-intro-card"><text class="intro-title">已授权观察记录</text><view v-for="(proof,index) in contentEvidence" :key="proof.id || index"><text class="intro-title">{{ proof.title }}</text><text class="intro-content">{{ proof.summary }}</text><text class="intro-content">适用边界：{{ proof.applicability }}</text><text v-if="proof.kind==='metric'" class="intro-content">观察值 {{ proof.value }} {{ proof.unit }} · 样本 {{ proof.sample_size }}</text><text class="intro-content">{{ proof.observed_from }} 至 {{ proof.observed_until }} UTC；观察记录不构成效果保证。</text></view></view>

        <!-- 适用机型 -->
        <view class="product-section">
          <text class="section-title">场景配置机型</text>
          <view class="data-note">目录价格供参考，档期与现场服务条件需进一步确认。</view>
          <StateView :empty="!filteredProducts.length" empty-text="当前未配置可租机型" empty-hint="可切换方案定制，提交您的具体需求" />
          <view class="product-grid">
            <view class="product-card" :class="{ selected: isMachineSelected(item) }" v-for="(item, idx) in filteredProducts" :key="idx" @tap="goProductDetail(item)">
              <view class="product-image" :style="{ backgroundColor: item.bg || '#F0F4FA' }">
                <image v-if="item.image" class="product-image-img" :src="item.image" mode="aspectFill"></image>
                <text v-else class="product-icon">{{ item.icon || '🤖' }}</text>
              </view>
              <view class="product-info">
                <text class="product-name">{{ item.name }}</text>
                <text class="product-scene">{{ item.scene }}</text>
                <view class="product-price">
                  <text class="price">¥{{ item.price }}</text>
                  <text class="unit" v-if="item.unit">{{ item.unit }}</text>
                </view>
              </view>
              <!-- 多机合单:选择/取消,不触发卡片跳详情 -->
              <view class="product-select-btn" @tap.stop="toggleMachine(item)">
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
      <!-- 底部占位 -->
      <view style="height: 160rpx;"></view>
    </scroll-view>

    <!-- 免费定制 CTA（固定悬浮底部，仅方案定制模式） -->
    <view class="custom-cta" v-if="sceneLoaded && !loading && activeMode === 'custom'" @tap="onConsult">
      <text>提交场景需求</text>
    </view>

    <!-- ===== 多机合单底部结算栏 ===== -->
    <view class="scene-cart-bar" v-if="sceneLoaded && !loading && activeMode === 'rent' && selectedMachines.length > 0">
      <view class="scene-cart-row">
        <text class="scene-cart-count">已选 {{ selectedMachines.length }} 台</text>
        <view class="scene-cart-days">
          <text
            class="day-chip"
            :class="{ active: rentDays === d }"
            v-for="d in rentDayOptions"
            :key="d"
            @tap="rentDays = d"
          >{{ d }}天</text>
        </view>
        <text class="scene-cart-total">¥{{ sceneTotal() }}</text>
      </view>
      <view class="scene-cart-submit" @tap="submitSceneOrder">提交选型意向 · 获取正式报价</view>
    </view>

    <!-- ===== 免费定制方案 · 留资弹窗 ===== -->
    <view class="lead-mask" v-if="showLeadForm" @tap="closeLeadForm">
      <view class="lead-modal" @tap.stop>
        <view class="lead-title">免费定制「{{ leadSchemeTitle }}」方案</view>
        <view class="lead-sub">留下联系方式，工作人员将与您确认服务安排</view>
        <input class="lead-input" v-model="leadForm.name" placeholder="联系人姓名" />
        <input class="lead-input" v-model="leadForm.phone" type="number" maxlength="11" placeholder="手机号" />
        <textarea class="lead-textarea" v-model="leadForm.demand" placeholder="补充需求（选填）：时间 / 数量 / 城市" />
        <view class="lead-submit" @tap="submitLead">{{ leadSubmitting ? '提交中…' : '提交' }}</view>
      </view>
    </view>

    <!-- 提交成功后显示真实受理提示 -->
    <view class="lead-mask" v-if="showLeadSuccess" @tap="closeLeadSuccess">
      <view class="lead-modal" @tap.stop>
        <view class="lead-success-icon">✅</view>
        <view class="lead-title-sm">已收到您的需求</view>
        <view class="lead-sub">工作人员将与您确认服务安排</view>


        <view class="lead-close" @tap="closeLeadSuccess">知道了</view>
      </view>
    </view>

  </view>
</template>

<script>

import { sceneApi } from '@/utils/api'
import { openSceneRequest, sceneDraftPayload } from '@/utils/scene-request'
import { BASE_URL } from '@/utils/config'
import StateView from '@/components/StateView.vue'
import { sceneId, configuredSceneProducts, normalizeSceneImage } from '@/utils/scene-catalog'

export default {
  components: { StateView },
  data() {
    return {
      sceneId: '', activeMode: 'rent', loading: true, loadError: '', loadVersion: 0, sceneLoaded: false, contentEvidence: [],
      sceneInfo: { name: '', banner: '', desc: '', intro: '' },
      scheme: { pains: [], benefits: [], scenes: [] }, sceneRobots: [], rentCatalog: [],
      selectedMachines: [], rentDays: 1, rentDayOptions: [1, 3, 7, 30],
      showLeadForm: false, showLeadSuccess: false, leadSchemeTitle: '', leadSubmitting: false,
      leadForm: { name: '', phone: '', demand: '' }
    }
  },
  computed: {
    filteredProducts() { return configuredSceneProducts(this.sceneRobots, this.rentCatalog) }
  },
  onLoad(options) { this.sceneId = sceneId(options.sceneId) || '' },
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
        this.contentEvidence=Array.isArray(res.content_evidence)?res.content_evidence:[]
        this.sceneRobots = res.scheme.robots || []
        this.rentCatalog = res.products.map(this.mapCatalogProduct).filter(Boolean)
        this.selectedMachines = this.filteredProducts.filter(item => this.selectedMachines.some(old => old.id === item.id))
        this.sceneLoaded = true
      } catch (e) {
        if (version !== this.loadVersion) return
        this.loadError = e.message || '场景加载失败，请重试'
        this.sceneRobots = []; this.rentCatalog = []; this.selectedMachines = []
      } finally { if (version === this.loadVersion) this.loading = false }
    },
    mapCatalogProduct(p) {
      const raw = p.prices && ((p.prices.final && p.prices.final.price) ?? (p.prices.regular && p.prices.regular.price))
      const price = typeof raw === 'number' || (typeof raw === 'string' && /^\d+(\.\d+)?$/.test(raw.trim())) ? Number(raw) : NaN
      if (!sceneId(p.id) || p.type !== 'simple' || !Number.isFinite(price) || price < 0) return null
      return { id: Number(p.id), bagistoId: Number(p.id), name: p.name || '机器人', price: String(price), type: p.type,
        icon: '🤖', bg: '#F0F4FA', unit: '/天', image: normalizeSceneImage(p.base_image && p.base_image.medium_image_url, BASE_URL) }
    },
    setActiveMode(mode) { this.activeMode = mode },
    goBack() { uni.navigateBack() },
    openService() { if (this.sceneLoaded && !this.loading) this.onConsult() },
    goProductDetail(item) {
      if (!this.canSelect(item)) return
      this.openRequest('意向机型：' + item.name + '。具体设备、任务能力和档期待评估。')
    },
    canSelect(item) { return this.sceneLoaded && !this.loading && this.filteredProducts.some(p => p.id === item.id) },
    isMachineSelected(item) { return this.selectedMachines.some(m => m.id === item.id) },
    toggleMachine(item) {
      if (!this.canSelect(item)) return
      const index = this.selectedMachines.findIndex(m => m.id === item.id)
      if (index >= 0) this.selectedMachines.splice(index, 1)
      else this.selectedMachines.push({ ...item })
    },
    sceneTotal() { return Math.round(this.selectedMachines.reduce((sum, m) => sum + Number(m.price), 0) * this.rentDays * 100) / 100 },
    submitSceneOrder() {
      if (!this.selectedMachines.length || !this.selectedMachines.every(this.canSelect)) return
      this.openRequest('意向机型：' + this.selectedMachines.map(m => m.name).join('、') + '；意向服务 ' + this.rentDays + ' 天。目录金额仅供参考，以正式报价为准。')
    },
    onConsult() {
      if (!this.sceneLoaded || this.loading) return
      this.openRequest('希望沟通「' + this.sceneInfo.name + '」的场景服务方案。')
    },
    openRequest(text) {
      openSceneRequest(sceneDraftPayload({ source: 'scene', sceneId: this.sceneId, revisionId: this.sceneInfo.scene_revision_id, category: this.sceneInfo.category, text, contact: { name: '', phone: '' } }))
    },
    closeLeadForm() { if (!this.leadSubmitting) this.showLeadForm = false },
    closeLeadSuccess() { this.showLeadSuccess = false },
    async submitLead() {
      if (!this.sceneLoaded || this.loading) return
      openSceneRequest(sceneDraftPayload({ source: 'scene', sceneId: this.sceneId, revisionId: this.sceneInfo.scene_revision_id, category: this.sceneInfo.category, text: this.leadForm.demand, contact: this.leadForm }))
      this.showLeadForm = false
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
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 100;
  height: calc(env(safe-area-inset-top) + 88rpx);
  background: #ffffff;
}
.nav-left, .nav-right {
  position: absolute;
  top: calc(env(safe-area-inset-top) + 100rpx);
  width: 60rpx;
  height: 60rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255,255,255,0.9);
  border-radius: 50%;
  box-shadow: 0 2rpx 8rpx rgba(0,0,0,0.15);
}
.nav-left {
  left: 24rpx;
}
.nav-right {
  right: 24rpx;
}
.nav-back {
  font-size: 40rpx;
  font-weight: 300;
  color: #1F2937;
}
.nav-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #1F2937;
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
/* ===== 留资弹窗 / 成功页 ===== */
.lead-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.55); z-index: 1200; display: flex; align-items: center; justify-content: center; }
.lead-modal { width: 600rpx; background: #fff; border-radius: 24rpx; padding: 40rpx 36rpx; box-sizing: border-box; }
.lead-title { font-size: 34rpx; font-weight: 700; color: #1F2937; line-height: 1.4; }
.lead-sub { font-size: 24rpx; color: #6B7280; margin: 12rpx 0 24rpx; }
.lead-input { height: 84rpx; border: 1px solid #E5E7EB; border-radius: 14rpx; padding: 0 22rpx; font-size: 28rpx; margin-bottom: 18rpx; background: #F8FAFC; }
.lead-textarea { width: 100%; height: 140rpx; border: 1px solid #E5E7EB; border-radius: 14rpx; padding: 18rpx 22rpx; font-size: 26rpx; margin-bottom: 24rpx; background: #F8FAFC; box-sizing: border-box; }
.lead-submit { height: 88rpx; line-height: 88rpx; text-align: center; color: #fff; font-size: 30rpx; font-weight: 600; background: linear-gradient(135deg, #1E6FFF, #0EA5B7); border-radius: 44rpx; }
.lead-success-icon { font-size: 72rpx; text-align: center; margin-bottom: 8rpx; }
.lead-title-sm { font-size: 34rpx; font-weight: 700; color: #1F2937; text-align: center; }
.lead-qr-box { width: 300rpx; height: 300rpx; margin: 28rpx auto 12rpx; border: 2rpx dashed #94A3B8; border-radius: 16rpx; display: flex; align-items: center; justify-content: center; background: #F8FAFC; }
.lead-qr-text { font-size: 24rpx; color: #94A3B8; text-align: center; }
.lead-qr-tip { font-size: 22rpx; color: #6B7280; text-align: center; margin-bottom: 28rpx; }
.lead-close { height: 80rpx; line-height: 80rpx; text-align: center; border-radius: 40rpx; border: 1px solid #CBD5E1; color: #475569; font-size: 28rpx; }

/* ===== 场景租用多机合单 ===== */
.product-card { position: relative; }
.product-card.selected { border-color: #165DFF; box-shadow: 0 0 0 2rpx rgba(22, 93, 255, 0.35); }
.product-select-btn { position: absolute; top: 10rpx; right: 10rpx; width: 52rpx; height: 52rpx; border-radius: 50%; background: rgba(22, 93, 255, 0.92); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 34rpx; line-height: 1; z-index: 2; }
.scene-cart-bar { position: fixed; left: 20rpx; right: 20rpx; bottom: calc(env(safe-area-inset-bottom) + 20rpx); background: #fff; border-radius: 20rpx; box-shadow: 0 8rpx 32rpx rgba(15, 23, 42, 0.18); padding: 18rpx 24rpx; z-index: 1100; }
.scene-cart-row { display: flex; align-items: center; gap: 16rpx; }
.scene-cart-count { font-size: 26rpx; font-weight: 700; color: #1F2937; flex-shrink: 0; }
.scene-cart-days { display: flex; gap: 8rpx; flex: 1; flex-wrap: wrap; }
.day-chip { font-size: 22rpx; color: #475569; background: #F1F5F9; border-radius: 24rpx; padding: 6rpx 18rpx; }
.day-chip.active { background: #165DFF; color: #fff; }
.scene-cart-total { font-size: 32rpx; font-weight: 700; color: #165DFF; flex-shrink: 0; }
.scene-cart-submit { margin-top: 14rpx; height: 84rpx; line-height: 84rpx; text-align: center; color: #fff; font-size: 30rpx; font-weight: 600; background: linear-gradient(135deg, #1E6FFF, #0EA5B7); border-radius: 42rpx; }
</style>
