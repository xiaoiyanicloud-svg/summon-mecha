<template>
  <view class="page-container">
    <!-- ===== 顶部导航区 ===== -->
    <view class="nav-area">
      <view class="nav-content">
        <view class="nav-left">
          <text class="brand">AI小召</text>
          <text class="subtitle">懂机器.更懂你</text>
        </view>
        <view class="nav-right">
          <text class="menu-icon" @tap="toggleDrawer">☰</text>
        </view>
      </view>
    </view>

    <!-- ===== 固定内容区域（禁止滚动） ===== -->
    <view class="content-area">
      <!-- ===== 融合后的主卡片（半透明） ===== -->
      <view class="main-card">
        <!-- 上半部分：试试这样问我 -->
        <view class="card-section">
          <view class="card-header">
            <text class="card-title"><text class="icon-sparkle">✨</text> 试试这样问我</text>
            <text class="card-date">2026-08-08</text>
          </view>
          <view class="question-btns">
            <view class="q-btn" @tap="onQuestionTap('选型')">
              <text>工业机器人选型要点</text>
              <text class="q-tag">热门</text>
            </view>
            <view class="q-btn" @tap="onQuestionTap('部署')">
              <text>协作机器人如何部署</text>
              <text class="q-tag">实战</text>
            </view>
            <view class="q-btn" @tap="onQuestionTap('续航')">
              <text>移动机器人续航对比</text>
              <text class="q-tag">对比</text>
            </view>
          </view>
          <view class="robot-float">
            <image src="/static/robot.png" mode="aspectFit" class="robot-image"></image>
          </view>
        </view>

        <!-- 分割线 -->
        <view class="divider"></view>

        <!-- 下半部分：AI帮你选机甲 -->
        <view class="card-section filter-section">
          <view class="filter-header">
            <text class="filter-title">AI帮你选机甲</text>
            <text class="filter-badge">智能匹配</text>
          </view>
          <view class="tags-row">
            <text class="tag" :class="{ active: isTagSelected('3万以下') }" @tap="onTagClick('3万以下')">3万以下</text>
            <text class="tag" :class="{ active: isTagSelected('3-10万') }" @tap="onTagClick('3-10万')">3-10万</text>
            <text class="tag" :class="{ active: isTagSelected('10-30万') }" @tap="onTagClick('10-30万')">10-30万</text>
            <text class="tag" :class="{ active: isTagSelected('30万以上') }" @tap="onTagClick('30万以上')">30万以上</text>
          </view>
          <view class="tags-row" style="margin-top: 12rpx;">
            <text class="tag" :class="{ active: isTagSelected('分拣') }" @tap="onTagClick('分拣')">分拣</text>
            <text class="tag" :class="{ active: isTagSelected('搬运') }" @tap="onTagClick('搬运')">搬运</text>
            <text class="tag" :class="{ active: isTagSelected('焊接') }" @tap="onTagClick('焊接')">焊接</text>
            <text class="tag" :class="{ active: isTagSelected('装配') }" @tap="onTagClick('装配')">装配</text>
            <text class="tag" :class="{ active: isTagSelected('检测') }" @tap="onTagClick('检测')">检测</text>
            <text class="tag" :class="{ active: isTagSelected('包装') }" @tap="onTagClick('包装')">包装</text>
          </view>
        </view>
      </view>

      <view class="bottom-space"></view>
    </view>

    <!-- ===== 小召·召唤结果流 ===== -->
    <scroll-view class="summon-scroll" scroll-y="true" show-scrollbar="false" v-if="summonResult">
      <view class="summon-wrap">
        <view class="summon-head">
          <text class="summon-badge">小召为你召唤</text>
          <text class="summon-close" @tap="clearSummon">收起</text>
        </view>

        <view class="summon-scene" v-if="summonResult.scene" @tap="goSceneDetail(summonResult.scene)">
          <text class="summon-scene-icon">{{ summonResult.scene.icon || '🎯' }}</text>
          <view class="summon-scene-info">
            <text class="summon-scene-name">{{ summonResult.scene.name }}</text>
            <text class="summon-scene-desc">{{ summonResult.scene.description }}</text>
          </view>
          <text class="summon-scene-go">查看方案 ›</text>
        </view>

        <view class="summon-machines" v-if="summonResult.machines && summonResult.machines.length">
          <text class="summon-sub">推荐机型（点击立即租用）</text>
          <view class="summon-machine" v-for="m in summonResult.machines" :key="m.id" @tap="goMachineRent(m)">
            <text class="summon-machine-name">{{ m.name }}</text>
            <text class="summon-machine-price" v-if="m.prices && m.prices.final">¥{{ m.prices.final.price }}/天起</text>
          </view>
        </view>

        <view class="summon-alts" v-if="summonResult.alternatives && summonResult.alternatives.length">
          <text class="summon-sub">其他可能匹配的场景</text>
          <view class="summon-alt" v-for="a in summonResult.alternatives" :key="a.id" @tap="goSceneDetail(a)">
            <text>{{ a.name }}</text>
            <text class="summon-alt-arrow">›</text>
          </view>
        </view>

        <view class="summon-empty" v-if="!summonResult.scene && !summonResult.machines.length">
          <text class="summon-empty-text">暂时没有匹配的方案，可试试「方案定制」由专家为你定制</text>
        </view>
      </view>
    </scroll-view>

    <!-- ===== 底部输入区域 ===== -->
    <view class="input-area">
      <input 
        class="input-box" 
        type="text" 
        placeholder="发消息或按住说话.." 
        confirm-type="search" 
        v-model="inputValue"
        @confirm="onSendMsg"
      />
      <!-- 麦克风图标：替换为自定义图片 -->
      <image 
        class="mic-icon" 
        src="/static/maikefeng.png" 
        mode="aspectFit" 
        @tap="onVoiceTap"
      ></image>
      <view class="send-icon" @tap="onSendMsg">➤</view>
    </view>

    <!-- ===== 自定义底部导航栏 ===== -->
    <CustomTabBar currentPage="/pages/ai/index" />

    <!-- ===== 历史对话侧边栏 ===== -->
    <view class="drawer-mask" v-if="drawerVisible" @tap="closeDrawer"></view>
    <view class="drawer" :class="{ open: drawerVisible }">
      <view class="drawer-header">
        <text class="drawer-title">📋 历史对话</text>
        <text class="drawer-close" @tap="closeDrawer">✕</text>
      </view>
      <scroll-view class="drawer-list" scroll-y="true" show-scrollbar="false">
        <view 
          class="drawer-item" 
          v-for="(item, index) in historyList" 
          :key="index"
          @tap="loadHistory(item)"
        >
          <view class="drawer-item-left">
            <text class="drawer-item-icon">💬</text>
            <view class="drawer-item-info">
              <text class="drawer-item-title">{{ item.title }}</text>
              <text class="drawer-item-time">{{ item.time }}</text>
            </view>
          </view>
          <text class="drawer-item-arrow">›</text>
        </view>
        <view class="drawer-empty" v-if="historyList.length === 0">
          <text class="drawer-empty-icon">📭</text>
          <text class="drawer-empty-text">暂无历史对话</text>
          <text class="drawer-empty-sub">开始提问吧！</text>
        </view>
        <view style="height: 60rpx;"></view>
      </scroll-view>
    </view>
  </view>
</template>

<script>
import CustomTabBar from '@/components/CustomTabBar.vue';
import { summonApi } from '@/utils/api';

export default {
  components: {
    CustomTabBar
  },
  data() {
    return {
      inputValue: '',
      drawerVisible: false,
      // 小召·召唤结果(意图→场景→机型)
      summonResult: null,
      summoning: false,
      historyList: [
        { id: 1, title: '工业机器人选型建议', time: '2026-08-08 14:32' },
        { id: 2, title: '协作机器人部署方案', time: '2026-08-08 11:20' },
        { id: 3, title: '移动机器人续航对比', time: '2026-08-07 16:45' },
        { id: 4, title: '焊接机器人参数咨询', time: '2026-08-07 09:12' },
        { id: 5, title: 'AGV搬运车选型推荐', time: '2026-08-06 20:30' },
        { id: 6, title: '机器人维护保养指南', time: '2026-08-06 13:08' }
      ]
    };
  },
  methods: {
    isTagSelected(tag) {
      const tags = this.inputValue.split(/\s+/).filter(t => t !== '');
      return tags.includes(tag);
    },
    onTagClick(tag) {
      let tags = this.inputValue.split(/\s+/).filter(t => t !== '');
      const index = tags.indexOf(tag);
      if (index !== -1) {
        tags.splice(index, 1);
      } else {
        tags.push(tag);
      }
      this.inputValue = tags.join(' ');
    },
    onQuestionTap(type) {
      uni.showToast({ title: `提问：${type}`, icon: 'none' });
    },
    onSendMsg() {
      const val = this.inputValue.trim();
      if (!val) { uni.showToast({ title: '请描述你的需求', icon: 'none' }); return; }

      // 历史(本地)
      const now = new Date();
      const timeStr =
        now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-' +
        String(now.getDate()).padStart(2, '0') + ' ' +
        String(now.getHours()).padStart(2, '0') + ':' +
        String(now.getMinutes()).padStart(2, '0');
      this.historyList.unshift({
        id: Date.now(),
        title: val.length > 20 ? val.slice(0, 20) + '...' : val,
        time: timeStr
      });

      // 小召·召唤引擎:意图→场景→机型→方案
      this.summoning = true
      summonApi.summon(val)
        .then((res) => {
          this.summoning = false
          this.summonResult = (res && res.data) || null
          if (this.summonResult && !this.summonResult.scene && !(this.summonResult.machines || []).length) {
            uni.showToast({ title: '暂无匹配，可试试「方案定制」', icon: 'none' })
          }
        })
        .catch((err) => {
          this.summoning = false
          uni.showToast({ title: (err && err.message) || '召唤失败，请稍后重试', icon: 'none' })
        })
      this.inputValue = ''
    },
    clearSummon() {
      this.summonResult = null
    },
    goSceneDetail(scene) {
      uni.navigateTo({ url: `/pages/robot/fangan?sceneId=${scene.id}` })
    },
    goMachineRent(m) {
      const price = (m.prices && m.prices.final && m.prices.final.price) || (m.min_price || 0)
      uni.navigateTo({
        url: `/pages/order/checkout?productId=${m.id}&name=${encodeURIComponent(m.name || '机器人')}&price=${price}&type=rent&qty=1&scene=${encodeURIComponent('小召推荐')}`
      })
    },
    onVoiceTap() {
      uni.showToast({ title: '语音识别中...', icon: 'none' });
    },
    toggleDrawer() {
      this.drawerVisible = !this.drawerVisible;
    },
    closeDrawer() {
      this.drawerVisible = false;
    },
    loadHistory(item) {
      uni.showToast({ title: `加载对话：${item.title}`, icon: 'none' });
      this.inputValue = item.title;
      this.closeDrawer();
    }
  }
};
</script>

<style scoped lang="scss">
/* ===== 背景图片 + 禁止页面滚动 ===== */
page {
  background: url('~@/static/AIxiaozhao.png') no-repeat center center / cover, 
              linear-gradient(180deg, #4291F6 0%, #B3D5FF 100%);
  min-height: 100vh;
  height: 100%;
  background-attachment: fixed;
  overflow: hidden;
}

.page-container {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: transparent;
  position: relative;
  overflow: hidden;
}

/* ===== 顶部导航 ===== */
.nav-area {
  flex-shrink: 0;
  padding: calc(80rpx + env(safe-area-inset-top)) 32rpx 10rpx 32rpx;
  padding: calc(80rpx + constant(safe-area-inset-top)) 32rpx 10rpx 32rpx;
  background: transparent;
  position: relative;
  z-index: 20;
}
.nav-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.nav-left {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4rpx;
  transform: translate(60rpx, 90rpx);
}
.brand {
  font-size: 56rpx;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 2rpx;
  text-shadow: 0 4rpx 12rpx rgba(0, 0, 0, 0.20);
  line-height: 1.2;
}
.subtitle {
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.95);
  background: rgba(0, 0, 0, 0.2);
  padding: 4rpx 16rpx;
  border-radius: 30rpx;
  backdrop-filter: blur(4rpx);
  margin-left: 0;
  align-self: flex-start;
}
.nav-right {
  display: flex;
  align-items: center;
}
.menu-icon {
  font-size: 44rpx;
  color: #ffffff;
  opacity: 0.9;
  transform: scaleX(1.2);
  text-shadow: 0 2rpx 8rpx rgba(0,0,0,0.15);
  padding: 12rpx 16rpx;
  border-radius: 12rpx;
  background: rgba(255, 255, 255, 0.10);
  backdrop-filter: blur(8rpx);
  transition: all 0.2s;
}
.menu-icon:active {
  background: rgba(255, 255, 255, 0.25);
  transform: scaleX(1.2) scale(0.92);
}

/* ===== 内容区域 ===== */
.content-area {
  flex: 1;
  padding: calc(40rpx + 2cm) 44rpx 20rpx 0rpx;
  position: relative;
  z-index: 10;
  height: 0;
  overflow: hidden;
}

/* ===== 融合后的主卡片（半透明） ===== */
.main-card {
  background: rgba(255, 255, 255, 0.45);
  backdrop-filter: blur(20rpx);
  border-radius: 40rpx;
  padding: 36rpx 32rpx 32rpx 32rpx;
  box-shadow: 0 16rpx 48rpx rgba(0, 0, 0, 0.12);
  border: 1rpx solid rgba(255, 255, 255, 0.4);
  position: relative;
  overflow: visible;
  margin-left: 30rpx;
}

.card-section {
  position: relative;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24rpx;
}
.card-title {
  font-size: 34rpx;
  font-weight: 600;
  color: #1a2a3a;
}
.icon-sparkle {
  margin-right: 10rpx;
  font-size: 30rpx;
}
.card-date {
  font-size: 24rpx;
  color: #4a5a6a;
  background: rgba(255, 255, 255, 0.5);
  padding: 6rpx 20rpx;
  border-radius: 30rpx;
}
.question-btns {
  display: flex;
  flex-direction: column;
  gap: 18rpx;
  padding-right: 80rpx;
}
.q-btn {
  background: rgba(255, 255, 255, 0.75);
  backdrop-filter: blur(8rpx);
  border: 1rpx solid rgba(255, 255, 255, 0.8);
  border-radius: 60rpx;
  padding: 18rpx 32rpx;
  font-size: 28rpx;
  color: #1a2a3a;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 16rpx;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.06);
  position: relative;
  overflow: hidden;
}
.q-btn::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  width: 6rpx;
  height: 100%;
  background: linear-gradient(180deg, #4291F6, #6EB5FF);
  border-radius: 6rpx 0 0 6rpx;
  opacity: 0.6;
}
.q-tag {
  font-size: 20rpx;
  color: #4291F6;
  background: rgba(66, 145, 246, 0.15);
  padding: 2rpx 16rpx;
  border-radius: 30rpx;
  margin-left: auto;
}
.robot-float {
  position: absolute;
  right: -16rpx;
  top: -32rpx;
  width: 160rpx;
  height: 200rpx;
  pointer-events: none;
  z-index: 15;
  filter: drop-shadow(0 12rpx 32rpx rgba(0, 0, 0, 0.15));
}
.robot-image {
  width: 100%;
  height: 100%;
}

.divider {
  height: 2rpx;
  background: rgba(255, 255, 255, 0.5);
  margin: 28rpx 0 24rpx 0;
}

.filter-header {
  display: flex;
  align-items: center;
  gap: 14rpx;
  margin-bottom: 14rpx;
}
.filter-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #1a2a3a;
}
.filter-badge {
  font-size: 20rpx;
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  color: #fff;
  padding: 2rpx 18rpx;
  border-radius: 30rpx;
}
.tags-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
}
.tag {
  background: rgba(255, 255, 255, 0.65);
  border: 1rpx solid rgba(255, 255, 255, 0.8);
  border-radius: 60rpx;
  padding: 6rpx 16rpx;
  font-size: 22rpx;
  color: #1a2a3a;
  font-weight: 450;
  backdrop-filter: blur(4rpx);
  box-shadow: 0 2rpx 8rpx rgba(0, 0, 0, 0.03);
  pointer-events: auto;
  user-select: none;
  transition: all 0.2s ease;
}
.tag.active {
  background: rgba(66, 145, 246, 0.25);
  border-color: #4291F6;
  color: #4291F6;
  font-weight: 600;
  box-shadow: 0 0 0 2rpx rgba(66, 145, 246, 0.2);
}

/* ===== 底部输入区域 ===== */
.input-area {
  flex-shrink: 0;
  padding: 16rpx 28rpx 12rpx 28rpx;
  background: rgba(255, 255, 255, 0.50);
  backdrop-filter: blur(30rpx);
  border-top: 1rpx solid rgba(255, 255, 255, 0.3);
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin: 0 60rpx 160rpx 60rpx;
  border-radius: 60rpx;
  padding-bottom: calc(12rpx + env(safe-area-inset-bottom));
  padding-bottom: calc(12rpx + constant(safe-area-inset-bottom));
}
.input-box {
  flex: 1;
  height: 72rpx;
  background: rgba(255, 255, 255, 0.6);
  border: 1rpx solid rgba(255, 255, 255, 0.7);
  border-radius: 60rpx;
  padding: 0 28rpx;
  font-size: 28rpx;
  color: #1a2a3a;
}
.input-box::placeholder {
  color: #4a5a6a;
}
/* 麦克风图标改为图片，保留原有尺寸和样式 */
.mic-icon {
  width: 72rpx;
  height: 50rpx;
  border-radius: 50%;
  flex-shrink: 0;
  /* 移除原有背景和文字颜色，由图片本身显示 */
  background: transparent !important;
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.25);
}
.send-icon {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
  color: #fff;
  flex-shrink: 0;
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.25);
}
.mic-icon:active,
.send-icon:active {
  transform: scale(0.90);
}

.bottom-space {
  height: 20rpx;
}

/* ===== 历史对话侧边栏 ===== */
.drawer-mask {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.40);
  z-index: 999;
  animation: fadeIn 0.25s ease;
}
.drawer {
  position: fixed;
  top: 0;
  right: 0;
  width: 560rpx;
  max-width: 80%;
  height: 100vh;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(30rpx);
  z-index: 1000;
  transform: translateX(100%);
  transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1);
  display: flex;
  flex-direction: column;
  box-shadow: -8rpx 0 40rpx rgba(0, 0, 0, 0.12);
}
.drawer.open {
  transform: translateX(0);
}
.drawer-header {
  flex-shrink: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 40rpx 32rpx 24rpx 32rpx;
  border-bottom: 2rpx solid rgba(0, 0, 0, 0.05);
  padding-top: calc(80rpx + env(safe-area-inset-top));
  padding-top: calc(80rpx + constant(safe-area-inset-top));
}
.drawer-title {
  font-size: 36rpx;
  font-weight: 700;
  color: #1a2a3a;
}
.drawer-close {
  font-size: 40rpx;
  color: #8a9aa8;
  padding: 8rpx 16rpx;
  border-radius: 12rpx;
  transition: all 0.2s;
}
.drawer-close:active {
  background: rgba(0, 0, 0, 0.06);
  transform: scale(0.90);
}
.drawer-list {
  flex: 1;
  padding: 16rpx 20rpx 20rpx 20rpx;
  overflow: hidden;
}
.drawer-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 20rpx;
  border-radius: 20rpx;
  background: rgba(255, 255, 255, 0.6);
  margin-bottom: 12rpx;
  transition: all 0.15s;
  border: 1rpx solid rgba(255, 255, 255, 0.8);
}
.drawer-item:active {
  background: rgba(66, 145, 246, 0.08);
  border-color: rgba(66, 145, 246, 0.2);
  transform: scale(0.98);
}
.drawer-item-left {
  display: flex;
  align-items: center;
  gap: 16rpx;
  flex: 1;
  min-width: 0;
}
.drawer-item-icon {
  font-size: 32rpx;
  flex-shrink: 0;
}
.drawer-item-info {
  display: flex;
  flex-direction: column;
  gap: 4rpx;
  flex: 1;
  min-width: 0;
}
.drawer-item-title {
  font-size: 28rpx;
  color: #1a2a3a;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.drawer-item-time {
  font-size: 20rpx;
  color: #8a9aa8;
}
.drawer-item-arrow {
  font-size: 36rpx;
  color: #c0c8d0;
  flex-shrink: 0;
  margin-left: 12rpx;
}
.drawer-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 120rpx 0;
}
.drawer-empty-icon {
  font-size: 80rpx;
  margin-bottom: 24rpx;
}
.drawer-empty-text {
  font-size: 30rpx;
  color: #6a7a8a;
  font-weight: 500;
}
.drawer-empty-sub {
  font-size: 24rpx;
  color: #9aabbb;
  margin-top: 8rpx;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* ===== 【新增】强制锁定导航栏尺寸，与订单页完全一致 ===== */
.custom-tabbar {
  height: 115rpx !important;
}
.custom-tabbar .tab-icon {
  font-size: 46rpx !important;
}
.custom-tabbar .tab-text {
  font-size: 21rpx !important;
}
</style>
<style scoped lang="scss">
/* ===== 小召·召唤结果流 ===== */
.summon-scroll {
  position: fixed;
  left: 24rpx;
  right: 24rpx;
  bottom: 150rpx;
  max-height: 52vh;
  z-index: 40;
  background: rgba(255, 255, 255, 0.96);
  border-radius: 24rpx;
  box-shadow: 0 12rpx 40rpx rgba(15, 23, 42, 0.18);
}
.summon-wrap { padding: 24rpx; }
.summon-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16rpx; }
.summon-badge { font-size: 26rpx; font-weight: 700; color: #165DFF; background: rgba(22, 93, 255, 0.1); border-radius: 20rpx; padding: 6rpx 20rpx; }
.summon-close { font-size: 24rpx; color: #94A3B8; padding: 6rpx 12rpx; }
.summon-scene { display: flex; align-items: center; gap: 16rpx; background: #F0F6FF; border: 1px solid #D6E4FF; border-radius: 16rpx; padding: 18rpx; }
.summon-scene-icon { font-size: 44rpx; }
.summon-scene-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4rpx; }
.summon-scene-name { font-size: 30rpx; font-weight: 700; color: #1F2937; }
.summon-scene-desc { font-size: 22rpx; color: #6B7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.summon-scene-go { font-size: 24rpx; color: #165DFF; flex-shrink: 0; }
.summon-sub { display: block; font-size: 24rpx; color: #6B7280; margin: 20rpx 0 10rpx; }
.summon-machine { display: flex; align-items: center; justify-content: space-between; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 14rpx; padding: 16rpx 20rpx; margin-bottom: 10rpx; }
.summon-machine-name { font-size: 26rpx; color: #1F2937; font-weight: 600; }
.summon-machine-price { font-size: 24rpx; color: #165DFF; font-weight: 700; }
.summon-alts { margin-top: 6rpx; }
.summon-alt { display: flex; align-items: center; justify-content: space-between; padding: 14rpx 6rpx; border-bottom: 1px solid #F1F5F9; font-size: 26rpx; color: #334155; }
.summon-alt-arrow { color: #CBD5E1; }
.summon-empty-text { font-size: 26rpx; color: #6B7280; line-height: 1.6; }
</style>
