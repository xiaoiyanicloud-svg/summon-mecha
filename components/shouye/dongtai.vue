<template>
  <view class="feed-panel">
    <view class="feed-list">
      <view class="feed-card" v-for="(item, idx) in feedList" :key="item.id">
        <view class="feed-top">
          <view class="feed-avatar" :style="{ backgroundColor: item.avatarBg }">
            <text>{{ item.avatar }}</text>
          </view>
          <view class="feed-author">
            <view class="feed-name-row">
              <text class="feed-name">{{ item.name }}</text>
              <text class="feed-badge" v-if="item.badge">{{ item.badge }}</text>
            </view>
            <text class="feed-time">{{ item.time }}</text>
          </view>
        </view>

        <text class="feed-text">{{ item.text }}</text>

        <view class="feed-image" v-if="item.image" :style="{ backgroundColor: item.imageBg }">
          <text>{{ item.imageIcon }}</text>
        </view>

        <view class="feed-actions">
          <view class="action-btn" @click.stop="toggleLike(idx)">
            <text>{{ item.liked ? '❤️' : '🤍' }}</text>
            <text class="act-num">{{ item.likes }}</text>
          </view>
          <view class="action-btn" @click.stop="showCommentInput(idx)">
            <text>💬</text>
            <text class="act-num">{{ item.comments }}</text>
          </view>
          <view class="action-btn" @click.stop="toggleFav(idx)">
            <text>⭐</text>
            <text class="act-num">{{ item.favs }}</text>
          </view>
          <view class="action-btn" @click.stop="shareItem(item)">
            <text>↗️</text>
            <text class="act-num">分享</text>
          </view>
        </view>

        <view class="comment-area" v-if="item.showComment">
          <input class="comment-input" type="text" v-model="item.commentText" placeholder="写下你的评论..." />
          <view class="comment-send" @click.stop="sendComment(idx)">发送</view>
          <view class="comment-cancel" @click.stop="hideComment(idx)">取消</view>
        </view>
        <view class="comment-list" v-if="item.commentList && item.commentList.length > 0">
          <view class="comment-item" v-for="(c, ci) in item.commentList" :key="ci">
            <text class="comment-author">{{ c.author }}</text>
            <text class="comment-text">{{ c.text }}</text>
          </view>
        </view>
      </view>
    </view>
    <view class="feed-join" @click="joinCommunity">
      <text class="join-text">🤖 加入召唤机甲机器人生态社区</text>
      <text class="join-arrow">立即加入 →</text>
    </view>
  </view>
</template>

<script>
export default {
  name: 'Dongtai',
  data() {
    return {
      feedList: [
        {
          id: 1,
          name: '智元机器人',
          avatar: '🧑‍🤖',
          avatarBg: '#E8F0FE',
          badge: '官方',
          time: '2小时前',
          text: '人形机器人远征A1在深圳工厂完成部署，实现自动化巡检与数据采集，客户反馈良好。',
          image: true,
          imageIcon: '📸 现场实拍',
          imageBg: '#F0F4FA',
          likes: 234,
          liked: false,
          comments: 45,
          favs: 67,
          showComment: false,
          commentText: '',
          commentList: [
            { author: '张工', text: '这个效率怎么样？能替代人工吗？' },
            { author: '李经理', text: '我们工厂也在考虑，有详细参数吗？' }
          ]
        },
        {
          id: 2,
          name: '宇树科技',
          avatar: '🐕',
          avatarBg: '#E8F0FE',
          badge: '厂家',
          time: '3小时前',
          text: '宇树Go2四足机器人新增消防巡检功能，可识别烟雾、温度异常，已在多个园区部署。',
          image: true,
          imageIcon: '🔥 消防巡检演示',
          imageBg: '#FEF3C7',
          likes: 156,
          liked: false,
          comments: 32,
          favs: 45,
          showComment: false,
          commentText: '',
          commentList: []
        },
        {
          id: 3,
          name: '工信部',
          avatar: '🏛️',
          avatarBg: '#E8F0FE',
          badge: '资讯',
          time: '5小时前',
          text: '工信部发布《机器人产业高质量发展三年行动计划》，明确2026-2028年机器人产业发展目标。',
          image: false,
          likes: 89,
          liked: false,
          comments: 23,
          favs: 34,
          showComment: false,
          commentText: '',
          commentList: [
            { author: '刘总', text: '利好政策，行业要起飞了！' }
          ]
        },
        {
          id: 4,
          name: '机甲格斗联盟',
          avatar: '⚔️',
          avatarBg: '#FEF3C7',
          badge: '赛事',
          time: '6小时前',
          text: '2026机甲格斗大赛深圳站8月15日正式开赛！欢迎各路高手报名参赛，赢取丰厚奖金。',
          image: true,
          imageIcon: '🏟️ 赛事预告',
          imageBg: '#FEF3C7',
          likes: 67,
          liked: false,
          comments: 18,
          favs: 23,
          showComment: false,
          commentText: '',
          commentList: []
        }
      ]
    };
  },
  methods: {
    toggleLike(idx) {
      const item = this.feedList[idx];
      item.liked = !item.liked;
      item.likes += item.liked ? 1 : -1;
    },
    toggleFav(idx) {
      this.feedList[idx].favs += 1;
      uni.showToast({ title: '已收藏', icon: 'success' });
    },
    showCommentInput(idx) {
      this.feedList.forEach((v, i) => {
        if (i !== idx) v.showComment = false;
      });
      const item = this.feedList[idx];
      item.showComment = !item.showComment;
      if (item.showComment) {
        this.$nextTick(() => {
          item.commentText = '';
        });
      }
    },
    hideComment(idx) {
      this.feedList[idx].showComment = false;
      this.feedList[idx].commentText = '';
    },
    sendComment(idx) {
      const item = this.feedList[idx];
      const text = item.commentText.trim();
      if (!text) {
        uni.showToast({ title: '请输入评论内容', icon: 'none' });
        return;
      }
      if (!item.commentList) item.commentList = [];
      item.commentList.push({ author: '我', text: text });
      item.comments += 1;
      item.commentText = '';
      item.showComment = false;
      uni.showToast({ title: '评论成功', icon: 'success' });
    },
    shareItem(item) {
      uni.showToast({ title: '已复制链接，可分享给好友', icon: 'none' });
    },
    joinCommunity() {
      uni.showToast({ title: '加入社区', icon: 'success' });
    }
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
  padding: 20rpx 20rpx 12rpx;
  box-shadow: 0 2rpx 12rpx rgba(0, 0, 0, 0.04);
  border: 1px solid #F1F5F9;
}
.feed-top {
  display: flex;
  align-items: center;
  gap: 14rpx;
  margin-bottom: 12rpx;
}
.feed-avatar {
  width: 72rpx;
  height: 72rpx;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32rpx;
  flex-shrink: 0;
}
.feed-author {
  flex: 1;
  min-width: 0;
}
.feed-name-row {
  display: flex;
  align-items: center;
  gap: 8rpx;
}
.feed-name {
  font-size: 26rpx;
  font-weight: 700;
  color: #1F2937;
}
.feed-badge {
  font-size: 18rpx;
  background: #5588FF;
  color: #fff;
  padding: 2rpx 12rpx;
  border-radius: 12rpx;
}
.feed-time {
  font-size: 20rpx;
  color: #9CA3AF;
  margin-top: 2rpx;
  display: block;
}
.feed-text {
  font-size: 26rpx;
  color: #1F2937;
  line-height: 1.6;
}
.feed-image {
  width: 100%;
  height: 140rpx;
  border-radius: 12rpx;
  margin: 12rpx 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
  color: #6B7280;
}
.feed-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12rpx;
  padding-top: 12rpx;
  border-top: 1rpx solid #F1F5F9;
}
.action-btn {
  display: flex;
  align-items: center;
  gap: 6rpx;
  font-size: 24rpx;
  color: #6B7280;
  cursor: pointer;
  padding: 4rpx 8rpx;
}
.act-num {
  font-size: 22rpx;
  color: #9CA3AF;
}
.comment-area {
  display: flex;
  gap: 10rpx;
  margin-top: 12rpx;
}
.comment-input {
  flex: 1;
  background: #F8FAFC;
  border: 1px solid #E5E7EB;
  border-radius: 30rpx;
  padding: 0 20rpx;
  height: 56rpx;
  font-size: 20rpx;
}
.comment-send {
  background: #165DFF;
  color: #fff;
  padding: 6rpx 24rpx;
  border-radius: 30rpx;
  font-size: 20rpx;
}
.comment-cancel {
  color: #9CA3AF;
  padding: 6rpx 16rpx;
}
.comment-list {
  margin-top: 8rpx;
}
.comment-item {
  display: flex;
  gap: 10rpx;
  padding: 6rpx 0;
  border-bottom: 1px solid #F5F7FA;
}
.comment-author {
  font-size: 18rpx;
  font-weight: 600;
  color: #165DFF;
}
.comment-text {
  font-size: 18rpx;
  color: #1F2937;
}
.feed-join {
  background: linear-gradient(135deg, #165DFF, #3B82F6);
  border-radius: 16rpx;
  padding: 20rpx 24rpx;
  display: flex;
  justify-content: space-between;
  margin-top: 16rpx;
}
.feed-join .join-text {
  font-size: 24rpx;
  font-weight: 600;
  color: #fff;
}
.feed-join .join-arrow {
  font-size: 22rpx;
  color: #fff;
  opacity: 0.9;
}
</style>