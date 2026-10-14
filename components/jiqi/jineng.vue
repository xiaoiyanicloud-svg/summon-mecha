<template>
  <view class="product-area-wrapper">
    <!-- ===== 顶部：品牌 & 机型联动筛选（一行） ===== -->
    <view class="filter-section">
      <view class="filter-row">
        <!-- 品牌选择 -->
        <view class="filter-group">
          <text class="filter-label">品牌</text>
          <view class="filter-picker" @click="openPicker">
            <text>{{ selectedBrand }}</text>
            <text class="picker-arrow">▼</text>
          </view>
        </view>

        <!-- 机型选择 -->
        <view class="filter-group">
          <text class="filter-label">机型</text>
          <view class="filter-picker" @click="openPicker">
            <text>{{ selectedModel || '全部机型' }}</text>
            <text class="picker-arrow">▼</text>
          </view>
        </view>
      </view>
    </view>

    <view class="main-body">
      <!-- ===== 左侧技能分类（保留） ===== -->
      <scroll-view class="brand-sidebar" scroll-y="true" show-scrollbar="false">
        <view class="brand-item" :class="{ active: skillCategory === 'all' }" @tap="skillCategory='all'">
          <text class="brand-name">全部</text>
        </view>
        <view class="brand-item" :class="{ active: skillCategory === '热门' }" @tap="skillCategory='热门'">
          <text class="brand-name">热门</text>
        </view>
        <view class="brand-item" :class="{ active: skillCategory === '免费' }" @tap="skillCategory='免费'">
          <text class="brand-name">免费</text>
        </view>
        <view class="brand-item" :class="{ active: skillCategory === '付费' }" @tap="skillCategory='付费'">
          <text class="brand-name">付费</text>
        </view>
        <view class="brand-item" :class="{ active: skillCategory === '格斗' }" @tap="skillCategory='格斗'">
          <text class="brand-name">格斗</text>
        </view>
        <view class="brand-item" :class="{ active: skillCategory === 'AI生成' }" @tap="skillCategory='AI生成'">
          <text class="brand-name">AI生成</text>
        </view>
        <view style="height: 250rpx; flex-shrink: 0;"></view>
      </scroll-view>

      <!-- ===== 技能列表 ===== -->
      <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false">
        <view class="product-flex-wrapper">
          <view class="skill-card" v-for="item in filteredSkillList" :key="item.id" @tap="onItemClick(item)">
            <view class="skill-image" :style="{ backgroundColor: item.bg }">
              <image v-if="item.image" class="skill-img" :src="item.image" mode="aspectFill"></image>
              <text v-else class="skill-icon">{{ item.icon }}</text>
            </view>
            <view class="skill-info">
              <text class="skill-name">{{ item.name }}</text>
              <view class="skill-bottom">
                <!-- [TOB_BTN_SKILL] 已隐藏技能卡按钮(原: <view class="btn-action-skill" @tap.stop="onItemClick(item)">查看</view>) -->
              </view>
            </view>
          </view>
          <view class="scroll-bottom-placeholder" v-if="filteredSkillList.length > 0"></view>
        </view>
        <view class="empty-state" v-if="filteredSkillList.length === 0">
          <text class="empty-icon">📭</text>
          <text class="empty-text">没有找到匹配的技能</text>
        </view>
      </scroll-view>
    </view>

    <!-- ===== 品牌 & 机型联动弹窗（二合一） ===== -->
    <view class="picker-overlay" v-if="showPicker" @click="closePicker">
      <view class="picker-modal" @click.stop>
        <view class="picker-header">
          <text class="picker-title">选择品牌与机型</text>
          <text class="picker-close" @click="closePicker">✕</text>
        </view>

        <!-- 双列联动选择器 -->
        <view class="picker-double">
          <!-- 左列：品牌 -->
          <view class="picker-col">
            <text class="col-label">品牌</text>
            <picker-view 
              class="picker-view-col" 
              :value="[tempBrandIndex]" 
              @change="onBrandScroll"
              @pickend="onBrandPickEnd"
              indicator-style="height: 76rpx;"
            >
              <picker-view-column>
                <view class="picker-item" v-for="item in brandOptions" :key="item">{{ item }}</view>
              </picker-view-column>
            </picker-view>
          </view>

          <!-- 右列：机型（联动更新） -->
          <view class="picker-col">
            <text class="col-label">机型</text>
            <picker-view 
              class="picker-view-col" 
              :value="[tempModelIndex]" 
              @change="onModelScroll"
              indicator-style="height: 76rpx;"
            >
              <picker-view-column>
                <view class="picker-item" v-for="item in tempModelOptions" :key="item">{{ item }}</view>
              </picker-view-column>
            </picker-view>
          </view>
        </view>

        <!-- 底部按钮 -->
        <view class="picker-footer">
          <view class="picker-btn cancel" @click="closePicker">取消</view>
          <view class="picker-btn confirm" @click="confirmPicker">确定</view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import SKILL_LIST from './skill-data'

export default {
  name: 'Jineng',
  props: {
    items: { type: Array, default: () => [] },
  },
  data() {
    return {
      // ===== 弹窗控制 =====
      showPicker: false,
      tempBrandIndex: 0,
      tempModelIndex: 0,
      tempModelOptions: ['全部机型'],
      isBrandScrolling: false,
      pendingBrandIndex: 0,

      // ===== 品牌 & 机型 =====
      brandIndex: 0,
      brandOptions: [
        '全部品牌', 
        '宇树', '智元', '优必选', '银河通用', '乐聚', 
        '众擎', '加速进化', '傅利叶', '小米', '云深处',
        '逐际动力', '越疆', '新松', '汇川', '小鹏',
        '星动纪元', '星海图', '智平方', '鹿明', '魔法原子',
        '星尘智能', '墨甲智创', '千寻智能', '它石智航', '优艾智合',
        '智身科技', '艾利特', '微亿智造', '自变量', '松延动力',
        '卧安', '蔚蓝智能', '七腾', '优宝特', '具微科技',
        'Figure AI', '大疆', '擎朗', '普渡', '高仙', '云迹',
        '埃斯顿', '节卡'
      ],
      selectedBrand: '全部品牌',

      modelIndex: 0,
      selectedModel: '全部机型',

      // ===== 预缓存品牌 -> 机型列表 =====
      brandModelCache: {},

      // ===== 技能分类（左侧） =====
      skillCategory: 'all',

      // ===== 技能数据(单一数据源见 ./skill-data.js, 25 条含 tag/brands/models) =====
      skillList: SKILL_LIST
    };
  },
  computed: {
    filteredSkillList() {
      // 后台分类商品(传入 items)优先;为空则回退内部 mock
      // TODO: 后台数据存在时下方品牌/机型/左侧分类筛选不生效(真实商品暂无 tag/brands/models 属性),
      // 待后台商品补齐属性后在此统一接入筛选
      if (this.items && this.items.length) return this.items;

      let list = this.skillList;

      // 1. 品牌过滤
      if (this.selectedBrand !== '全部品牌') {
        list = list.filter(item => item.brands && item.brands.includes(this.selectedBrand));
      }

      // 2. 机型过滤
      if (this.selectedModel && this.selectedModel !== '全部机型') {
        list = list.filter(item => item.models && item.models.includes(this.selectedModel));
      }

      // 3. 技能分类过滤（左侧分类）
      if (this.skillCategory !== 'all') {
        list = list.filter(item => item.tag === this.skillCategory);
      }

      return list;
    }
  },
  created() {
    this.buildBrandModelCache();
  },
  methods: {
    // ===== 构建品牌 -> 机型缓存 =====
    buildBrandModelCache() {
      const allModels = new Set();
      this.skillList.forEach(item => {
        if (item.models) {
          item.models.forEach(m => allModels.add(m));
        }
      });
      this.brandModelCache['全部品牌'] = ['全部机型', ...Array.from(allModels).sort()];

      const uniqueBrands = [...new Set(this.skillList.flatMap(item => item.brands || []))];
      uniqueBrands.forEach(brand => {
        const models = new Set();
        this.skillList
          .filter(item => item.brands && item.brands.includes(brand))
          .forEach(item => {
            if (item.models) {
              item.models.forEach(m => models.add(m));
            }
          });
        this.brandModelCache[brand] = ['全部机型', ...Array.from(models).sort()];
      });
    },

    getModelsByBrand(brand) {
      return this.brandModelCache[brand] || ['全部机型'];
    },

    onItemClick(item) {
      this.$emit('item-click', item);
    },

    // ===== 打开联动弹窗 =====
    openPicker() {
      this.tempBrandIndex = this.brandIndex;
      const currentBrand = this.brandOptions[this.tempBrandIndex];
      this.tempModelOptions = this.getModelsByBrand(currentBrand);
      const maxModelIndex = this.tempModelOptions.length - 1;
      this.tempModelIndex = Math.min(this.modelIndex, maxModelIndex);
      this.showPicker = true;
      this.isBrandScrolling = false;
    },

    closePicker() {
      this.showPicker = false;
      this.isBrandScrolling = false;
    },

    // ===== 品牌滚轮滑动 =====
    onBrandScroll(e) {
      const idx = e.detail.value[0];
      this.pendingBrandIndex = idx;
      this.isBrandScrolling = true;
      this.tempBrandIndex = idx;
    },

    onBrandPickEnd() {
      if (!this.isBrandScrolling) return;
      this.isBrandScrolling = false;
      const idx = this.pendingBrandIndex;
      const brand = this.brandOptions[idx];
      this.tempModelOptions = this.getModelsByBrand(brand);
      this.tempModelIndex = 0;
      this.$forceUpdate();
    },

    // ===== 机型滚轮滑动 =====
    onModelScroll(e) {
      const idx = e.detail.value[0];
      this.tempModelIndex = idx;
    },

    // ===== 确认选择 =====
    confirmPicker() {
      this.brandIndex = this.tempBrandIndex;
      this.selectedBrand = this.brandOptions[this.brandIndex];
      const maxIdx = this.tempModelOptions.length - 1;
      this.modelIndex = Math.min(this.tempModelIndex, maxIdx);
      this.selectedModel = this.tempModelOptions[this.modelIndex] || '全部机型';
      this.showPicker = false;
      this.isBrandScrolling = false;
    }
  },
  mounted() {
    this.selectedBrand = '全部品牌';
    this.brandIndex = 0;
    this.selectedModel = '全部机型';
    this.modelIndex = 0;
    this.tempModelOptions = ['全部机型'];
  }
};
</script>

<style scoped>
.product-area-wrapper {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  position: relative;
  display: flex;
  flex-direction: column;
}

/* ===== 顶部品牌-机型筛选 ===== */
.filter-section {
  background: #ffffff;
  padding: 8rpx 20rpx;
  border-bottom: 2rpx solid #F1F5F9;
  flex-shrink: 0;
}
.filter-row {
  display: flex;
  align-items: center;
  gap: 16rpx;
}
.filter-group {
  display: flex;
  align-items: center;
  gap: 8rpx;
}
.filter-group:first-child {
  flex: 0 0 auto;
}
.filter-group:last-child {
  flex: 1;
  min-width: 0;
}
.filter-label {
  font-size: 24rpx;
  color: #6B7280;
  white-space: nowrap;
}
.filter-picker {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #F8FAFC;
  border: 1px solid #E5E7EB;
  border-radius: 8rpx;
  padding: 6rpx 16rpx;
  font-size: 24rpx;
  color: #1F2937;
  min-width: 140rpx;
  cursor: pointer;
}
.filter-group:last-child .filter-picker {
  flex: 1;
  min-width: 0;
}
.picker-arrow {
  font-size: 18rpx;
  color: #9CA3AF;
  margin-left: 8rpx;
}

/* ===== 主体 ===== */
.main-body {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}

/* ===== 左侧分类（保留） ===== */
.brand-sidebar {
  width: 150rpx;
  background: #F8FAFC;
  padding: 8rpx 0;
  flex-shrink: 0;
  height: 100%;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.brand-sidebar::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
.brand-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 16rpx 4rpx;
  border-left: 4rpx solid transparent;
  flex-shrink: 0;
}
.brand-item.active {
  background: #ffffff;
}
.brand-item .brand-name {
  font-size: 26.4rpx;
  color: #1F2937;
  text-align: center;
  font-weight: 500;
}
.brand-item.active .brand-name {
  color: #165DFF;
  font-weight: 700;
}

/* ===== 技能列表 ===== */
.product-scroll {
  flex: 1;
  height: 100%;
  padding: 16rpx 12rpx 0 12rpx;
  box-sizing: border-box;
}
.product-flex-wrapper {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-start;
  gap: 16rpx;
}
.skill-card {
  width: calc(50% - 8rpx);
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: auto;
  box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.06);
}
.skill-image {
  width: 100%;
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.skill-img {
  width: 100%;
  height: 100%;
}
.skill-icon {
  font-size: 72rpx;
}
.skill-info {
  flex: 1;
  padding: 8rpx 12rpx 12rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.skill-name {
  font-size: 26rpx;
  font-weight: 600;
  color: #4B5563;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.skill-tag {
  font-size: 16rpx;
  color: #165DFF;
  background: rgba(22, 93, 255, 0.10);
  padding: 2rpx 14rpx;
  border-radius: 20rpx;
  display: inline-block;
  align-self: flex-start;
}
.skill-bottom {
  display: flex;
  align-items: center;
  justify-content: flex-end;
}
.btn-action-skill {
  background: #6EA8FF;
  border-radius: 20rpx;
  padding: 4rpx 20rpx;
  font-size: 20rpx;
  font-weight: 600;
  color: #fff;
  text-align: center;
  line-height: 1.8;
}
.btn-action-skill:active {
  opacity: 0.7;
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

/* ===== 联动弹窗（双列） ===== */
.picker-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: fadeIn 0.25s ease;
}
.picker-modal {
  width: 660rpx;
  max-height: 80vh;
  background: #ffffff;
  border-radius: 24rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  animation: scaleIn 0.25s ease;
}
.picker-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20rpx 30rpx 14rpx;
  border-bottom: 1px solid #F1F5F9;
  flex-shrink: 0;
}
.picker-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #1F2937;
}
.picker-close {
  font-size: 32rpx;
  color: #9CA3AF;
  padding: 4rpx 8rpx;
  cursor: pointer;
}

.picker-double {
  display: flex;
  height: 480rpx;
  flex-shrink: 0;
}
.picker-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  border-right: 1px solid #F1F5F9;
}
.picker-col:last-child {
  border-right: none;
}
.col-label {
  text-align: center;
  font-size: 22rpx;
  color: #9CA3AF;
  padding: 10rpx 0 6rpx;
  flex-shrink: 0;
  background: #FAFBFC;
  border-bottom: 1px solid #F1F5F9;
}
.picker-view-col {
  width: 100%;
  flex: 1;
  background: #ffffff;
}
.picker-item {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 76rpx;
  font-size: 26rpx;
  color: #1F2937;
}

.picker-footer {
  display: flex;
  border-top: 1px solid #F1F5F9;
  flex-shrink: 0;
}
.picker-btn {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  font-size: 28rpx;
  cursor: pointer;
}
.picker-btn.cancel {
  color: #6B7280;
  border-right: 1px solid #F1F5F9;
}
.picker-btn.confirm {
  color: #165DFF;
  font-weight: 600;
}
.picker-btn:active {
  background: #F8FAFC;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes scaleIn {
  from { transform: scale(0.92); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
</style>