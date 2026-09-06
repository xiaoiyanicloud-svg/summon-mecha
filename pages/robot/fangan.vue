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
    <scroll-view class="page-scroll" scroll-y="true" :show-scrollbar="false" @scrolltolower="loadMore">
      <!-- 顶部Banner + 简介 -->
      <view class="banner-area">
        <image class="banner-image" :src="sceneInfo.banner" mode="aspectFill"></image>
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
          <text class="intro-content">{{ sceneInfo.intro || '该场景适用于多种机器人应用，提供专业解决方案。' }}</text>
        </view>

        <!-- 案例展示 -->
        <view class="case-section">
          <text class="section-title">案例展示</text>
          <view class="case-grid">
            <view class="case-item" v-for="(item, idx) in cases" :key="idx" @tap="viewCase(item)">
              <image class="case-image" :src="item.image" mode="aspectFill"></image>
              <text class="case-title">{{ item.title }}</text>
            </view>
          </view>
        </view>

        <!-- 适用机型 -->
        <view class="product-section">
          <text class="section-title">适用机型</text>
          <view class="product-grid">
            <view class="product-card" v-for="(item, idx) in filteredProducts" :key="idx" @tap="goProductDetail(item)">
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
            </view>
          </view>
          <view class="load-more" v-if="hasMore">
            <text>加载更多...</text>
          </view>
          <view class="load-more" v-else>
            <text>— 已加载全部 —</text>
          </view>
        </view>
      </view>

      <!-- ===== 方案定制（痛点→方案 / 亮点 / 预期收益；不罗列机型） ===== -->
      <view v-else>
        <view class="card">
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

        <view class="card">
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

        <view class="card">
          <view class="card-title-row">
            <text class="card-title">典型场景预期收益</text>
          </view>
          <scroll-view class="scene-scroll" scroll-x :show-scrollbar="false">
            <view class="scheme-scene" v-for="(scene, idx) in scheme.scenes" :key="idx">
              <view class="scheme-scene-mark">
                <text class="scheme-scene-name">{{ scene.name }}</text>
              </view>
              <text class="scheme-scene-desc">{{ scene.desc }}</text>
              <view class="scheme-scene-data">
                <text class="scheme-scene-data-num">{{ scene.data }}</text>
              </view>
            </view>
          </scroll-view>
          <view class="data-note">*数据基于行业公开信息及实验室测试推算</view>
        </view>

        <!-- 免费定制 CTA -->
        <view class="custom-cta" @tap="onConsult">
          <text>免费定制方案</text>
        </view>
      </view>

      <!-- 底部占位 -->
      <view style="height: 160rpx;"></view>
    </scroll-view>

    <!-- ===== 免费定制方案 · 留资弹窗 ===== -->
    <view class="lead-mask" v-if="showLeadForm" @tap="closeLeadForm">
      <view class="lead-modal" @tap.stop>
        <view class="lead-title">免费定制「{{ leadSchemeTitle }}」方案</view>
        <view class="lead-sub">留下联系方式，方案专家将在 1 小时内联系您</view>
        <input class="lead-input" v-model="leadForm.name" placeholder="联系人姓名" />
        <input class="lead-input" v-model="leadForm.phone" type="number" maxlength="11" placeholder="手机号" />
        <textarea class="lead-textarea" v-model="leadForm.demand" placeholder="补充需求（选填）：时间 / 数量 / 城市" />
        <view class="lead-submit" @tap="submitLead">提交</view>
      </view>
    </view>

    <!-- ===== 提交成功页（含企微活码占位） ===== -->
    <view class="lead-mask" v-if="showLeadSuccess" @tap="closeLeadSuccess">
      <view class="lead-modal" @tap.stop>
        <view class="lead-success-icon">✅</view>
        <view class="lead-title-sm">已收到您的需求</view>
        <view class="lead-sub">方案专家将在 1 小时内联系您</view>
        <view class="lead-qr-box">
          <!-- [WECOM_QR] 企微活码占位：上线前把活码图片放 static/ 并替换此块为 image 标签 -->
          <text class="lead-qr-text">企微咨询码（占位）</text>
        </view>
        <view class="lead-qr-tip">想立刻聊？微信扫码添加方案专家</view>
        <view class="lead-close" @tap="closeLeadSuccess">知道了</view>
      </view>
    </view>

  </view>
</template>

<script>
import { sceneApi, categoryApi, productApi, tobOrderApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'
import { SCENE_SCHEMES, SCHEME_DB, DEFAULT_SCHEME, sceneProducts } from '@/components/jiqi/changjing.vue'

export default {
  data() {
    return {
      // 场景信息（从路由参数获取）
      sceneId: '',
      // 当前场景分类(TOB=enterprise 时走去后端拉真实图+描述)
      currentCategory: '',
      // 内容模式：rent=场景租用(默认) / custom=方案定制
      activeMode: 'rent',
      // 方案标题（首页场景方案传入；机器页场景无则兜底）
      rentTitle: '',
      // 方案定制内容（切到定制 tab 时按 title 取 SCHEME_DB）
      scheme: {},
      sceneInfo: {
        name: '商业活动',
        banner: '/static/hero.png',
        desc: '适用于展会、商场、品牌活动的机器人解决方案',
        intro: '提供租赁、购买、定制化服务，满足各类商业活动需求，提升现场互动体验。'
      },
      // 机器人列表（模拟数据）
      allProducts: [
        { id: 1, name: '宇树Go2', scene: '巡检·安防', price: '1,200', unit: '/天', icon: '🐕', bg: '#F0F4FA', brand: '宇树', type: 'quadruped', distance: 2.5, rating: 4.8 },
        { id: 2, name: '智元远征A1', scene: '工业·制造', price: '2,800', unit: '/天', icon: '🧑‍🤖', bg: '#E8F0FE', brand: '智元', type: 'humanoid', distance: 5.0, rating: 4.9 },
        { id: 3, name: '优必选Walker', scene: '高端服务', price: '4,200', unit: '/天', icon: '🤖', bg: '#F0F4FA', brand: '优必选', type: 'humanoid', distance: 3.2, rating: 4.7 },
        { id: 4, name: '小米CyberDog 2', scene: '家庭陪伴', price: '1,500', unit: '/天', icon: '📱', bg: '#F0F4FA', brand: '小米', type: 'quadruped', distance: 1.8, rating: 4.5 },
        { id: 5, name: '大疆M300', scene: '工业巡检', price: '3,200', unit: '/天', icon: '✈️', bg: '#E8F0FE', brand: '大疆', type: 'inspection', distance: 6.0, rating: 4.6 },
        { id: 6, name: '擎朗T5', scene: '医院物流', price: '950', unit: '/天', icon: '🏥', bg: '#E8F0FE', brand: '擎朗', type: 'delivery', distance: 4.0, rating: 4.4 },
        { id: 7, name: '普渡欢乐送', scene: '酒店·餐饮', price: '800', unit: '/天', icon: '📦', bg: '#F0F4FA', brand: '普渡', type: 'delivery', distance: 2.0, rating: 4.3 },
        { id: 8, name: '高仙商用', scene: '商场·办公', price: '600', unit: '/天', icon: '🧹', bg: '#E8F0FE', brand: '高仙', type: 'cleaning', distance: 3.5, rating: 4.2 },
      ],
      // 适用机器（后台配置的目录租用产品 id + 拉取的产品列表）
      sceneRobots: [],
      rentCatalog: [],
      // 免费定制方案 · 留资弹窗/成功页
      showLeadForm: false,
      showLeadSuccess: false,
      leadSchemeTitle: '',
      leadForm: { name: '', phone: '', demand: '' },
      // 分页加载
      page: 1,
      pageSize: 10,
      hasMore: true,
      // 案例展示
      cases: [
        { title: '上海车展机器人互动', image: '/static/case1.png' },
        { title: '商场导购机器人', image: '/static/case2.png' },
        { title: '工厂产线自动化', image: '/static/case3.png' }
      ]
    }
  },
  computed: {
    filteredProducts() {
      // 优先显示后台配置的适用机器（目录租用产品）；未配置/拉取失败回退本地 mock
      if (this.sceneRobots.length && this.rentCatalog.length) {
        const list = this.sceneRobots
          .map(id => this.rentCatalog.find(r => String(r.bagistoId ?? r.id) === String(id)))
          .filter(Boolean)
        if (list.length) return list
      }
      return this.allProducts
    }
  },
  onLoad(options) {
    this.rentTitle = options.title || ''
    if (options.sceneId) {
      this.sceneId = options.sceneId
      this.currentCategory = options.category || ''
    }
    // 用进入的场景填充顶部（避免默认"商业活动"）：优先本地 sceneProducts 匹配
    const localScene = this.sceneId
      ? sceneProducts.find(x => Number(x.id) === Number(this.sceneId))
      : null
    if (localScene) {
      this.sceneInfo = {
        ...this.sceneInfo,
        name: localScene.name,
        desc: localScene.desc,
        intro: localScene.desc,
        banner: this.normalizeImageUrl(localScene.banner) || this.sceneInfo.banner
      }
    }
    // 带图方案（仓储分拣/电力巡检/酒店服务/展会接待）：用 SCHEME_DB 完整数据 + 图覆盖
    if (this.rentTitle && SCHEME_DB[this.rentTitle]) {
      const s = SCHEME_DB[this.rentTitle]
      this.sceneInfo = {
        ...this.sceneInfo,
        name: this.rentTitle,
        desc: s.desc,
        intro: s.desc,
        banner: options.image || this.sceneInfo.banner
      }
    }
    if (this.sceneId) {
      // 所有场景都尝试连后端(后台 scenes 表有该 id 才用,否则回退本地数据)
      this.loadTobScene()
    }
    // 载入方案定制内容：优先 sceneId 专属方案，其次 title 完整方案
    this.loadScheme(this.sceneId, this.rentTitle)
    // 拉取目录租用产品（供场景租用机型显示）
    this.loadRentProducts()
  },
  methods: {
    // 方案定制：按 title 取 SCHEME_DB，无则用 DEFAULT_SCHEME 兜底
    // 拉取目录「租用」分类产品，映射成机型卡片
    async loadRentProducts() {
      try {
        const res = await categoryApi.getCategories({ limit: 200 })
        const cats = Array.isArray(res) ? res : ((res && res.data) || [])
        const rent = cats.find(c => c.slug === 'rent' || c.name === '租用')
        if (!rent) return
        const prods = await productApi.getProducts({ limit: 200, page: 1, category_id: rent.id })
        this.rentCatalog = (prods && Array.isArray(prods.data)) ? prods.data.map(p => this.mapCatalogProduct(p)) : []
      } catch (e) {
        this.rentCatalog = []
      }
    },
    mapCatalogProduct(p) {
      const regular = (p.prices && p.prices.regular && p.prices.regular.price) || ''
      const final = (p.prices && p.prices.final && p.prices.final.price) || ''
      const minPrice = (typeof p.min_price === 'number' ? p.min_price : '0')
      const effVal = final || regular || minPrice
      const num = parseFloat(String(effVal).replace(/[^\d.]/g, ''))
      const price = (isNaN(num) ? '' : String(num))
      const img = (p.base_image && (p.base_image.medium_image_url || p.base_image.large_image_url || p.base_image.small_image_url)) || ''
      return {
        id: p.id,
        name: p.name || '商品',
        price,
        icon: '🤖',
        bg: '#F0F4FA',
        scene: '',
        unit: '/天',
        image: img,
        bagistoId: p.id,
        bagistoPrice: price,
      }
    },
    loadScheme(sceneId, title) {
      // 优先该 sceneId 的专属方案(SCENE_SCHEMES)，其次按方案 title(SCHEME_DB)完整数据，最后兜底
      this.scheme = (sceneId && SCENE_SCHEMES[sceneId])
        || (title && SCHEME_DB[title])
        || DEFAULT_SCHEME
    },
    // 切换内容模式（场景租用 / 方案定制）
    setActiveMode(mode) {
      this.activeMode = mode
      // 切到定制时确保方案内容已载入（scheme 无 title 字段，用键数判断）
      if (mode === 'custom' && Object.keys(this.scheme).length === 0) {
        this.loadScheme(this.sceneId, this.rentTitle)
      }
    },
    // 场景详情:后台 scenes 表有该场景时才用后端描绘/图,否则保持本地
    async loadTobScene() {
      try {
        const res = await sceneApi.getSceneDetail(this.sceneId)
        // 未匹配到后端(或接口失败) → 保持本地兜底,不改名
        if (!res || !res.matched) return
        if (res.description) {
          this.sceneInfo.intro = res.description
        }
        if (res.name) {
          this.sceneInfo.name = res.name
        }
        if (res.banner) {
          this.sceneInfo.banner = this.normalizeImageUrl(res.banner)
        }
        // 后台「场景管理」配置的完整方案内容（说明/痛点/亮点/收益等）→ 覆盖本地兜底
        if (res.scheme && typeof res.scheme === 'object') {
          this.scheme = res.scheme
        }
        // 后台配置的适用机器（目录租用产品 id）
        if (res.scheme && Array.isArray(res.scheme.robots)) {
          this.sceneRobots = res.scheme.robots
        }
      } catch (e) {
        // 接口失败保持本地现状,不打扰用户
      }
    },
    // 后端图片 URL 可能是相对路径或以 APP_URL(localhost)开头的完整 URL,
    // 统一换成前端 BASE_URL 的主机,保证真机用局域网 IP 时也能加载。
    normalizeImageUrl(url) {
      if (!url) return ''
      const m = String(url).match(/^https?:\/\/[^/]+(\/.*)$/)
      const path = m ? m[1] : String(url)
      return path.startsWith('/') ? BASE_URL + path : url
    },
    goBack() {
      uni.navigateBack()
    },
    openService() {
      uni.showToast({ title: '客服功能', icon: 'none' })
    },
    loadMore() {
      if (!this.hasMore) return
      // 模拟加载更多
      this.page++
      // 模拟数据追加（实际请求接口）
      // 这里简单演示，不再追加
      this.hasMore = false
      uni.showToast({ title: '已加载全部', icon: 'none' })
    },
    goProductDetail(item) {
      // 修复：原跳不存在的 /pages/product/detail（坏路由）→ 改走统一结算（type=rent）
      uni.navigateTo({
        url: `/pages/order/checkout?productId=${item.id}&name=${encodeURIComponent(item.name)}&price=${item.price}&icon=${encodeURIComponent(item.icon)}&bg=${encodeURIComponent(item.bg)}&scene=${encodeURIComponent(this.sceneInfo.name)}&type=rent&qty=1`
      })
    },
    viewCase(item) {
      uni.showToast({ title: `查看案例：${item.title}`, icon: 'none' })
    },
    onConsult() {
      this.leadSchemeTitle = this.scheme.title || this.sceneInfo.name || '专属'
      this.leadForm = { name: '', phone: '', demand: '' }
      this.showLeadForm = true
    },
    closeLeadForm() {
      this.showLeadForm = false
    },
    closeLeadSuccess() {
      this.showLeadSuccess = false
    },
    // 真实提交:线索走 tob-order(type=custom) → 后台订单列表可见;成功后展示企微码成功页
    async submitLead() {
      const { name, phone, demand } = this.leadForm
      if (!name.trim()) { uni.showToast({ title: '请输入联系人姓名', icon: 'none' }); return }
      if (!phone || phone.length < 11) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return }
      try {
        const res = await tobOrderApi.create({
          type: 'custom',
          product_name: '方案定制·' + this.leadSchemeTitle,
          name: name.trim(),
          phone,
          demand,
          qty: 1,
        })
        if (!res || !res.success) throw new Error((res && res.message) || '提交失败')
      } catch (e) {
        uni.showToast({ title: e.message || '网络异常，请稍后重试', icon: 'none' })
        return
      }
      this.showLeadForm = false
      this.showLeadSuccess = true
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
  height: 88rpx;
  background: #ffffff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20rpx;
  box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.06);
}
.nav-left, .nav-right {
  width: 60rpx;
  height: 60rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.nav-back {
  font-size: 48rpx;
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
  margin-top: 88rpx;
  height: 0; /* 配合flex */
  padding-bottom: 20rpx;
}

/* Banner */
.banner-area {
  position: relative;
  width: 100%;
  height: 360rpx;
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

/* 免费定制 CTA */
.custom-cta {
  margin: 24rpx 30rpx 0;
  height: 88rpx;
  line-height: 88rpx;
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
</style>