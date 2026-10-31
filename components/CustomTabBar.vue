<template>
  <view class="custom-tabbar">
    <view 
      class="tab-item" 
      v-for="(tab, index) in tabs" 
      :key="index"
      :class="{ 'is-center': index === 2 }"
      @click="switchTab(tab.pagePath)"
    >
      <!-- 动态渲染图标：图片或文字，同时根据激活状态切换 -->
      <view class="tab-icon-wrapper" :class="{ 'center-active': index === 2 && currentPage === '/pages/ai/index' }">
        <image 
          v-if="isImagePath(getIcon(tab))" 
          class="tab-icon-img" 
          :src="getIcon(tab)" 
          mode="aspectFit"
        ></image>
        <text v-else class="tab-icon-text">{{ getIcon(tab) }}</text>
      </view>
      <text class="tab-text" :class="{ active: currentPage === tab.pagePath }">
        {{ tab.text }}
      </text>
    </view>
  </view>
</template>

<script>
export default {
  name: 'CustomTabBar',
  props: {
    currentPage: {
      type: String,
      default: '/pages/home/index'
    }
  },
  data() {
    return {
      tabs: [
        { 
          pagePath: '/pages/home/index', 
          text: '首页', 
          iconEmoji: '/static/shouye.png',
          iconActive: '/static/shouye1.png'
        },
        { 
          pagePath: '/pages/robot/index', 
          text: '分类',
          iconEmoji: '/static/jiqi.png',
          iconActive: '/static/jiqi1.png'
        },
        { 
          pagePath: '/pages/ai/index', 
          text: 'AI召唤', 
          iconEmoji: '/static/aizh.png'
          // 无激活态图片
        },
        { 
          pagePath: '/pages/order/index', 
          text: '订单', 
          iconEmoji: '/static/dingdan.png',
          iconActive: '/static/dingdan1.png'
        },
        { 
          pagePath: '/pages/mine/index', 
          text: '我的', 
          iconEmoji: '/static/wode.png',
          iconActive: '/static/wode1.png'
        }
      ]
    };
  },
  methods: {
    switchTab(url) {
      if (this.currentPage === url) return;
      uni.switchTab({ url });
    },
    isImagePath(path) {
      return typeof path === 'string' && (path.startsWith('/') || path.includes('.'));
    },
    getIcon(tab) {
      const isActive = this.currentPage === tab.pagePath;
      if (isActive && tab.iconActive) {
        return tab.iconActive;
      }
      return tab.iconEmoji;
    }
  }
};
</script>

<style scoped>
.custom-tabbar {
  position: fixed;
  bottom: 36rpx;
  left: 30rpx;
  right: 30rpx;
  height: 115rpx;
  background: #ffffff;
  border-radius: 34rpx;
  display: flex;
  justify-content: space-around;
  align-items: center;
  box-shadow: 0 8rpx 32rpx rgba(0, 0, 0, 0.08);
  z-index: 200;
  padding: 0 10rpx;
}
.tab-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2rpx;
  transform: translateY(-5rpx);
  overflow: visible; /* 允许子元素溢出 */
}
.tab-icon-wrapper {
  height: 46rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tab-icon-text {
  font-size: 46rpx;
  line-height: 1.2;
}
.tab-icon-img {
  width: 46rpx;
  height: 46rpx;
  display: block;
}
.tab-text {
  font-size: 21rpx;
  color: #666666;
}
.tab-text.active {
  color: #007aff;
  font-weight: 600;
}

/* ===== 中间项（AI召唤）圆形凸起 ===== */
.tab-item.is-center .tab-icon-wrapper {
  width: 80rpx;
  height: 80rpx;
  border-radius: 50%;
  background: #ffffff;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.1);
  margin-top: -30rpx; /* 向上凸出 */
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s ease, box-shadow 0.3s ease;
}
.tab-item.is-center .tab-icon-wrapper.center-active {
  background: linear-gradient(135deg, #6EA8FF, #165DFF);
  box-shadow: 0 4rpx 16rpx rgba(22, 93, 255, 0.3);
}
.tab-item.is-center .tab-icon-img {
  width: 48rpx;
  height: 48rpx;
}
.tab-item.is-center .tab-icon-text {
  font-size: 48rpx;
}
</style>