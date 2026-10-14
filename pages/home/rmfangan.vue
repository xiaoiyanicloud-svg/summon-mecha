<template>
  <view class="detail-page">
    <!-- ===== 顶部导航 ===== -->
    <view class="nav-bar">
      <view class="nav-left" @tap="goBack">
        <text class="back-icon">‹</text>
        <text class="back-text">返回</text>
      </view>
      <view class="nav-right">
        <text class="nav-share">↗</text>
      </view>
    </view>

    <scroll-view class="scroll-content" scroll-y :show-scrollbar="false">
      <!-- ===== 1. Hero：价值主张 + 信任 + 关键数字 ===== -->
      <view class="hero">
        <view class="hero-top">
          <view class="hero-icon-wrap" :style="{ background: scheme.bgColor || '#E8F0FE' }">
            <text class="hero-icon">{{ scheme.icon || '🤖' }}</text>
          </view>
          <view class="hero-head">
            <text class="hero-title">{{ scheme.title }}</text>
            <text class="hero-consult" v-if="scheme.consultCount">{{ scheme.consultCount }} 人已咨询</text>
          </view>
        </view>
        <text class="hero-desc">{{ scheme.desc }}</text>

        <view class="hero-tags">
          <text class="hero-tag" v-for="(t, i) in scheme.tags" :key="i">{{ t }}</text>
        </view>

        <view class="hero-stats">
          <view class="stat-item">
            <text class="stat-num">{{ scheme.stats.amp }}</text>
            <text class="stat-label">效率提升</text>
          </view>
          <view class="stat-item">
            <text class="stat-num">{{ scheme.stats.roi }}</text>
            <text class="stat-label">平均回本</text>
          </view>
          <view class="stat-item">
            <text class="stat-num">{{ scheme.stats.scene }}</text>
            <text class="stat-label">适配场景</text>
          </view>
        </view>
      </view>

      <!-- ===== 2. 核心痛点 → 方案 ===== -->
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

      <!-- ===== 3. 方案价值亮点 ===== -->
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

      <!-- ===== 4. 典型场景预期收益 ===== -->
      <view class="card" v-if="scheme.scenes && scheme.scenes.length">
        <view class="card-title-row">
          <text class="card-title">典型场景预期收益</text>
        </view>
        <scroll-view class="scene-scroll" scroll-x :show-scrollbar="false">
          <view class="scene-card" v-for="(scene, idx) in scheme.scenes" :key="idx">
            <view class="scene-mark">
              <text class="scene-name">{{ scene.name }}</text>
            </view>
            <text class="scene-desc">{{ scene.desc }}</text>
            <view class="scene-data">
              <text class="scene-data-num">{{ scene.data }}</text>
            </view>
          </view>
        </scroll-view>
        <view class="data-note">*数据基于行业公开信息及实验室测试推算</view>
      </view>

      <!-- ===== 5. 本方案可用机器人 ===== -->
      <view class="card" v-if="mainRobots.length">
        <view class="card-title-row">
          <text class="card-title">本方案可用机器人</text>
        </view>
        <view class="product-grid">
          <view class="product-card" v-for="robot in mainRobots" :key="robot.id" @tap="onCardTap(robot)">
            <view class="product-img-wrap">
              <image v-if="robot.image" class="product-img" :src="robot.image" mode="aspectFill"></image>
              <text v-else class="product-emoji">🤖</text>
              <!-- ★ 修改点：动态 class 使用对象语法，不直接使用中文类名 -->
              <text v-if="robot.tag" class="product-tag" :class="{'tag-new': robot.tag === '新品', 'tag-hot': robot.tag === '热卖'}">{{ robot.tag }}</text>
            </view>
            <view class="product-info">
              <text class="product-name">{{ robot.title }}</text>
              <text class="product-desc">{{ robot.desc }}</text>
            </view>
            <view class="product-action">了解详情</view>
          </view>
        </view>
      </view>

      <!-- ===== 交付流程 ===== -->
      <view class="card">
        <view class="card-title-row">
          <text class="card-title">交付流程</text>
        </view>
        <view class="process">
          <view class="process-item" v-for="(p, idx) in processList" :key="idx">
            <view class="process-dot">
              <text class="process-num">{{ idx + 1 }}</text>
            </view>
            <text class="process-name">{{ p }}</text>
          </view>
        </view>
      </view>

      <view style="height: 180rpx;"></view>
    </scroll-view>

    <!-- ===== 底部吸底 CTA ===== -->
    <view class="bottom-bar">
      <button class="btn-cta" @tap="onConsult">
        <text>免费定制方案</text>
      </button>
    </view>
  </view>
</template>

<script>
import { SCHEME_DB, DEFAULT_SCHEME } from '@/components/jiqi/changjing-data.js'
// ================================================================
// 机器人库（供各方案推荐引用）
// ================================================================
const ROBOTS = [
  { id: 1, title: '宇树 Go2',  desc: '四足巡检机器人', image: '/static/robot1.png', tag: '热卖' },
  { id: 2, title: '智元远征 A1', desc: '人形工业机器人', image: '/static/robot2.png', tag: '' },
  { id: 3, title: '大疆 M300', desc: '无人机巡检平台', image: '/static/robot3.png', tag: '新品' },
  { id: 4, title: '擎朗 T5',  desc: '物流配送机器人', image: '/static/robot4.png', tag: '' },
  { id: 5, title: '普渡欢乐送', desc: '智能配送机器人', image: '/static/robot5.png', tag: '' },
  { id: 6, title: '高仙 50', desc: '商用清洁机器人', image: '/static/robot6.png', tag: '' }
]

// ================================================================
// 通用兜底方案（无匹配 title 时使用）
// ================================================================

// ================================================================
// 各热门方案专属内容（点谁显示谁；无匹配走上面的通用兜底）
// ================================================================

export default {
  data() {
    return {
      robots: ROBOTS,
      scheme: {
        ...DEFAULT_SCHEME,
        title: '',
        icon: DEFAULT_SCHEME.icon,
        consultCount: DEFAULT_SCHEME.consultCount,
        bgColor: DEFAULT_SCHEME.bgColor
      },
      processList: ['需求诊断', '方案定制', '上门部署', '全程售后']
    }
  },

  computed: {
    pageTitle() {
      return this.scheme.title || '方案定制'
    },
    mainRobots() {
      const ids = Array.isArray(this.scheme.robots) ? this.scheme.robots : []
      const list = ids.map(id => this.robots.find(r => r.id === id)).filter(Boolean)
      // 在「本方案」推荐基础上，从机器人库再补 2 台（自动去重），合计多 2 台
      const extras = this.robots.filter(r => !ids.includes(r.id)).slice(0, 2)
      return list.concat(extras)
    }
  },

  onLoad(options) {
    const title = options.title ? decodeURIComponent(options.title) : ''
    const icon = options.icon ? decodeURIComponent(options.icon) : ''
    const consultCount = options.consultCount ? decodeURIComponent(options.consultCount) : ''
    const bgColor = options.bgColor ? decodeURIComponent(options.bgColor) : ''

    const base = SCHEME_DB[title] || DEFAULT_SCHEME
    this.scheme = {
      ...base,
      // 点谁显示谁的方案名/图标/咨询数（无对应 content 时走通用兜底）
      title: title || base.title || '场景方案',
      icon: icon || base.icon,
      consultCount: consultCount || base.consultCount,
      bgColor: bgColor || base.bgColor
    }
  },

  methods: {
    goBack() {
      uni.navigateBack()
    },
    onConsult() {
      uni.showModal({
        title: '免费定制方案',
        content: `方案专家将在 1 小时内联系您\n为您定制「${this.scheme.title}」专属方案`,
        confirmText: '好的',
        showCancel: false
      })
    },
    onCardTap(robot) {
      uni.showToast({ title: `「${robot.title}」工程师将为您讲解`, icon: 'none' })
    }
  }
}
</script>

<style scoped>
/* ===== 冷科技蓝 · 企业服务感 ===== */
page {
  background: #F2F5FA;
  height: 100%;
}
.detail-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: linear-gradient(180deg, #EAF1FF 0%, #F2F5FA 220rpx);
  color: #0F172A;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

/* ===== 顶部导航 ===== */
.nav-bar {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 88rpx;
  padding: 0 20rpx;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(10rpx);
  box-shadow: 0 2rpx 12rpx rgba(15, 23, 42, 0.04);
}
.nav-left, .nav-right {
  width: 76rpx;
  display: flex;
  align-items: center;
}
.nav-back { font-size: 44rpx; font-weight: 300; color: #0F172A; }
.back-text { font-size: 26rpx; color: #334155; margin-left: 4rpx; }
.nav-title {
  flex: 1;
  text-align: center;
  font-size: 32rpx;
  font-weight: 600;
  color: #0F172A;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.nav-right { justify-content: flex-end; }
.nav-share { font-size: 34rpx; color: #2563EB; }

/* ===== 滚动区 ===== */
.scroll-content { flex: 1; min-height: 0; }

/* ===== Hero 大卡 ===== */
.hero {
  margin: 20rpx 20rpx 0;
  padding: 40rpx 32rpx 36rpx;
  border-radius: 28rpx;
  background: linear-gradient(150deg, #D3E3FF 0%, #FFFFFF 72%);
  box-shadow: 0 20rpx 50rpx rgba(37, 99, 235, 0.12);
  color: #0F172A;
}
.hero-top { display: flex; align-items: center; gap: 20rpx; }
.hero-icon-wrap {
  width: 96rpx;
  height: 96rpx;
  border-radius: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.55);
}
.hero-icon { font-size: 56rpx; }
.hero-head { flex: 1; min-width: 0; }
.hero-title {
  font-size: 40rpx;
  font-weight: 700;
  line-height: 1.2;
  display: block;
}
.hero-consult {
  display: inline-block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #1D4ED8;
  background: rgba(37, 99, 235, 0.10);
  padding: 4rpx 18rpx;
  border-radius: 30rpx;
}
.hero-desc {
  margin-top: 20rpx;
  font-size: 28rpx;
  line-height: 1.6;
  color: #334155;
}
.hero-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 14rpx;
  margin-top: 24rpx;
}
.hero-tag {
  font-size: 22rpx;
  color: #1D4ED8;
  background: rgba(37, 99, 235, 0.10);
  border: 1rpx solid rgba(37, 99, 235, 0.20);
  padding: 6rpx 20rpx;
  border-radius: 30rpx;
}
.hero-stats {
  display: flex;
  margin-top: 30rpx;
  padding-top: 26rpx;
  border-top: 1rpx solid rgba(37, 99, 235, 0.15);
}
.stat-item { flex: 1; text-align: center; }
.stat-item + .stat-item { border-left: 1rpx solid rgba(37, 99, 235, 0.15); }
.stat-num { font-size: 40rpx; font-weight: 700; display: block; }
.stat-label { font-size: 22rpx; color: #475569; margin-top: 6rpx; display: block; }

/* ===== 通用卡片 ===== */
.card {
  background: #fff;
  border-radius: 24rpx;
  margin: 20rpx 20rpx 0;
  padding: 30rpx 28rpx;
  box-shadow: 0 6rpx 24rpx rgba(15, 23, 42, 0.05);
}
.card-title-row { display: flex; flex-direction: column; margin-bottom: 24rpx; }
.card-title { font-size: 32rpx; font-weight: 700; color: #0F172A; }
.card-sub { font-size: 24rpx; color: #64748B; margin-top: 6rpx; }

/* ===== 痛点 → 方案 ===== */
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

/* ===== 方案亮点（白色卡片 · 三列） ===== */
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

/* ===== 场景横滑 ===== */
.scene-scroll { white-space: nowrap; padding-bottom: 8rpx; }
.scene-card {
  display: inline-block;
  width: 300rpx;
  margin-right: 16rpx;
  background: #F6F9FE;
  border-radius: 18rpx;
  overflow: hidden;
  border: 1rpx solid #E6EEFF;
  vertical-align: top;
}
.scene-mark {
  padding: 22rpx 20rpx 18rpx;
  background: linear-gradient(135deg, #2563EB, #0EA5B7);
}
.scene-name { font-size: 27rpx; font-weight: 600; color: #fff; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.scene-desc { display: block; padding: 16rpx 20rpx 4rpx; font-size: 24rpx; color: #475569; line-height: 1.5; white-space: normal; min-height: 76rpx; }
.scene-data { padding: 0 20rpx 20rpx; }
.scene-data-num { display: inline-block; font-size: 24rpx; font-weight: 700; color: #2563EB; }
.data-note { font-size: 20rpx; color: #94A3B8; margin-top: 16rpx; padding-top: 16rpx; border-top: 1rpx solid #EEF2F7; }

/* ===== 产品（机器人）双列 · 一行两个 ===== */
.product-grid { display: flex; flex-wrap: wrap; justify-content: space-between; }
.product-card {
  width: 48%;
  background: #fff;
  border: 1rpx solid #EDF1F7;
  border-radius: 18rpx;
  overflow: hidden;
  margin-bottom: 16rpx;
}
.product-img-wrap { position: relative; width: 100%; aspect-ratio: 1 / 1; background: #F0F4FA; display: flex; align-items: center; justify-content: center; }
.product-img { width: 100%; height: 100%; }
.product-emoji { font-size: 72rpx; }
.product-tag {
  position: absolute; top: 12rpx; left: 12rpx;
  font-size: 18rpx; padding: 4rpx 14rpx; border-radius: 20rpx; color: #fff;
  background: #FF6B00; /* 默认热卖背景，会被具体类覆盖 */
}
/* ★ 修改点：删除 .product-tag.新品，改用英文类名 */
.product-tag.tag-new { background: #2563EB; }
.product-tag.tag-hot { background: #FF6B00; }

.product-info { padding: 16rpx 18rpx 10rpx; }
.product-name { font-size: 28rpx; font-weight: 600; color: #0F172A; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.product-desc { font-size: 22rpx; color: #64748B; margin-top: 6rpx; display: block; }
.product-action {
  margin: 10rpx 18rpx 18rpx;
  text-align: center;
  font-size: 24rpx;
  font-weight: 600;
  color: #2563EB;
  padding: 14rpx 0;
  border-radius: 40rpx;
  border: 1rpx solid #BBD2FF;
  background: #F3F8FF;
}

/* ===== 交付流程 ===== */
.process { display: flex; align-items: flex-start; justify-content: space-between; }
.process-item { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 12rpx; }
.process-dot {
  width: 56rpx; height: 56rpx; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: #E4EEFF; border: 1rpx solid #BBD2FF;
}
.process-num { font-size: 28rpx; font-weight: 700; color: #2563EB; }
.process-name { font-size: 22rpx; color: #475569; text-align: center; }

/* ===== 底部吸底 CTA（悬浮胶囊条） ===== */
.bottom-bar {
  position: fixed;
  bottom: calc(28rpx + env(safe-area-inset-bottom));
  left: 24rpx; right: 24rpx;
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 18rpx 24rpx;
  background: #fff;
  border: 1rpx solid #EDF1F7;
  border-radius: 48rpx;
  box-shadow: 0 10rpx 30rpx rgba(15, 23, 42, 0.10);
  z-index: 50;
}
.btn-cta {
  flex: 1;
  height: 88rpx;
  border: none;
  border-radius: 44rpx;
  background: linear-gradient(135deg, #1E6FFF, #0EA5B7);
  color: #fff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10rpx 24rpx rgba(30, 111, 255, 0.3);
}
.btn-cta:active { opacity: 0.85; }
</style>