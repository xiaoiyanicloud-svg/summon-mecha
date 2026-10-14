<template>
  <view class="product-area-wrapper">
    <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false">
      <view class="scene-list-wrapper">
        <view v-if="currentCategory !== 'all'" class="scene-category-header">
          <text class="scene-category-title">{{ currentCategoryLabel }}</text>
        </view>
        <view class="scene-list-item" v-for="scene in list" :key="scene.id" @tap="onItemClick(scene)">
          <view class="scene-list-icon" :style="{ backgroundColor: scene.bg }">
            <text>{{ scene.icon }}</text>
          </view>
          <view class="scene-list-info">
            <text class="scene-list-name">{{ scene.name }}</text>
            <text class="scene-list-desc">{{ scene.desc }}</text>
            <view class="scene-list-tags">
              <text class="scene-list-tag" v-for="tag in scene.tags" :key="tag">{{ tag }}</text>
            </view>
          </view>
          <view class="scene-list-arrow">›</view>
        </view>
        <view class="scroll-bottom-placeholder" v-if="list.length > 0"></view>
      </view>
      <view class="empty-state" v-if="list.length === 0">
        <text class="empty-icon">📭</text>
        <text class="empty-text">没有找到匹配的场景方案</text>
      </view>
    </scroll-view>
  </view>
</template>

<script>
// 场景方案数据已迁至纯 .js 模块 changjing-data.js：
// 微信小程序(mp-weixin)编译下，从 .vue 文件 import 具名导出会失效(绑定丢失 → "not defined")，
// 故数据源改用 .js 承载；此处仅转发(export ... from)以兼容旧引用路径，组件本体保留。
export {
  categoryMap, sceneProducts, loadBackendScenes, DEFAULT_SCHEME, SCHEME_DB, SCENE_SCHEMES
} from './changjing-data.js'

export default {
  name: 'Changjing',
  props: {
    list: {
      type: Array,
      default: () => []
    }
  },
  computed: {
    currentCategory() {
      if (this.list.length === 0) return 'all'
      return this.list[0]?.category || 'all'
    },
    currentCategoryLabel() {
      const map = {
        business_event: '商业活动',
        enterprise: '企业商用',
        culture_tourism: '文旅景区',
        education: '科普教育',
        security: '安全巡检',
        warehouse_logistics: '仓储物流',
        industry: '工业现场',
        medical: '医疗康养',
        home: '家庭个人'
      }
      return map[this.currentCategory] || ''
    }
  },
  methods: {
    onItemClick(item) {
      this.$emit('item-click', item)
    }
  }
}
</script>

<style scoped>
.product-area-wrapper {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  position: relative;
}
.product-scroll {
  height: 100%;
  padding: 16rpx 12rpx 0 12rpx;
  box-sizing: border-box;
}
.scene-list-wrapper {
  padding: 0 4rpx;
}
.scene-category-header {
  padding: 8rpx 12rpx 20rpx 12rpx;
  border-bottom: 2rpx solid #F1F5F9;
  margin-bottom: 16rpx;
}
.scene-category-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #1F2937;
  display: block;
}
.scene-list-item {
  display: flex;
  align-items: stretch;
  background: #F8FAFC;
  border-radius: 16rpx;
  margin-bottom: 16rpx;
  border: 1px solid #F1F5F9;
  transition: all 0.2s;
  height: 160rpx;
}
.scene-list-item:active {
  background: #E8F0FE;
  border-color: #165DFF;
}
.scene-list-icon {
  flex: 0 0 35%;
  height: 100%;
  border-radius: 16rpx 0 0 16rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 60rpx;
}
.scene-list-info {
  flex: 1;
  padding: 14rpx 16rpx;
  display: flex;
  flex-direction: column;
  justify-content: center;
  min-width: 0;
}
.scene-list-name {
  font-size: 28rpx;
  font-weight: 700;
  color: #1F2937;
}
.scene-list-desc {
  font-size: 22rpx;
  color: #6B7280;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.scene-list-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6rpx;
  margin-top: 4rpx;
}
.scene-list-tag {
  font-size: 18rpx;
  color: #165DFF;
  background: rgba(22, 93, 255, 0.10);
  padding: 2rpx 14rpx;
  border-radius: 20rpx;
  line-height: 1.6;
}
.scene-list-arrow {
  font-size: 32rpx;
  color: #D1D5DB;
  margin-left: 4rpx;
  flex-shrink: 0;
}
.scroll-bottom-placeholder {
  width: 100%;
  height: 380rpx;
  flex-shrink: 0;
  pointer-events: none;
  visibility: hidden;
}
.empty-state {
  text-align: center;
  padding: 80rpx 0;
}
.empty-icon {
  font-size: 64rpx;
  display: block;
}
.empty-text {
  font-size: 28rpx;
  color: #9CA3AF;
  margin-top: 16rpx;
}
</style>
