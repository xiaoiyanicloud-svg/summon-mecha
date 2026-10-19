<template>
  <view class="feed-panel">
    <!--
      G0-02:动态资讯发布域尚未接入,这里只展示诚实空态。
      不再渲染本地硬编码资讯,也不提供点赞/评论/收藏/分享/加入社区的假成功交互。
    -->
    <state-view
      :empty="!feedList.length"
      empty-icon="📰"
      empty-text="暂无资讯"
      empty-hint="资讯内容尚未开放，请以官方发布为准。"
      compact
    >
      <view class="feed-list">
        <view class="feed-card" v-for="item in feedList" :key="item.id">
          <text class="feed-name">{{ item.name }}</text>
          <text class="feed-text">{{ item.text }}</text>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'

export default {
  name: 'Dongtai',
  components: { StateView },
  data() {
    return {
      // 真实资讯接口接入前保持为空,不得用样例数据填充。
      feedList: []
    };
  }
};
</script>

<style scoped>
.feed-panel {
  padding-bottom: 10rpx;
}
.feed-list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.feed-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 20rpx;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
  border: 1px solid #F1F5F9;
}
.feed-name {
  font-size: 26rpx;
  font-weight: 700;
  color: #1F2937;
}
.feed-text {
  display: block;
  margin-top: 8rpx;
  font-size: 26rpx;
  color: #1F2937;
  line-height: 1.6;
}
</style>
