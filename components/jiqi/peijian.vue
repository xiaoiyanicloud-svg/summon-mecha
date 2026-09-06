<template>
  <view class="product-area-wrapper">
    <!-- ===== 顶部：品牌 & 机型联动筛选（一行） ===== -->
    <view class="filter-section">
      <view class="filter-row">
        <view class="filter-group">
          <text class="filter-label">品牌</text>
          <view class="filter-picker" @click="openPicker">
            <text>{{ selectedBrand }}</text>
            <text class="picker-arrow">▼</text>
          </view>
        </view>

        <view class="filter-group">
          <text class="filter-label">机型</text>
          <view class="filter-picker" @click="openPicker">
            <text>{{ selectedModel || '全部机型' }}</text>
            <text class="picker-arrow">▼</text>
          </view>
        </view>
      </view>
    </view>

    <!-- ===== 主体（左侧分类 + 配件列表） ===== -->
    <view class="main-body">
      <scroll-view class="brand-sidebar" scroll-y="true" show-scrollbar="false">
        <view class="brand-item" :class="{ active: accessoryCategory === 'all' }" @tap="accessoryCategory='all'">
          <text class="brand-name">全部</text>
        </view>
        <view class="brand-item" :class="{ active: accessoryCategory === 'fight' }" @tap="accessoryCategory='fight'">
          <text class="brand-name">格斗改装</text>
        </view>
        <view class="brand-item" :class="{ active: accessoryCategory === 'skin' }" @tap="accessoryCategory='skin'">
          <text class="brand-name">皮肤/外壳</text>
        </view>
        <view class="brand-item" :class="{ active: accessoryCategory === 'battery' }" @tap="accessoryCategory='battery'">
          <text class="brand-name">电池电源</text>
        </view>
        <view class="brand-item" :class="{ active: accessoryCategory === 'sensor' }" @tap="accessoryCategory='sensor'">
          <text class="brand-name">传感器</text>
        </view>
        <view class="brand-item" :class="{ active: accessoryCategory === 'arm' }" @tap="accessoryCategory='arm'">
          <text class="brand-name">机械臂/夹具</text>
        </view>
        <view style="height: 250rpx; flex-shrink: 0;"></view>
      </scroll-view>

      <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false">
        <view class="product-flex-wrapper">
          <view class="accessory-card" v-for="item in filteredAccessoryList" :key="item.id" @tap="onItemClick(item)">
            <view class="accessory-image" :style="{ backgroundColor: item.bg || '#F0F4FA' }">
              <image v-if="item.image" class="accessory-img" :src="item.image" mode="aspectFill"></image>
              <text v-else class="accessory-icon">{{ item.icon }}</text>
            </view>
            <view class="accessory-info">
              <text class="accessory-name">{{ item.name }}</text>
              <view class="accessory-bottom">
                <view class="accessory-price-wrap">
                  <text class="accessory-price"><text class="ap-badge">到手价</text><text class="ap-cur">¥</text>{{ item.bagistoPrice || item.price }}</text>
                  <text class="accessory-original" v-if="item.originalPrice">¥{{ item.originalPrice }}</text>
                </view>
                <!-- [TOB_BTN_ACCESSORY] 已隐藏配件卡按钮(原: <view class="btn-action-accessory" @tap.stop="onItemClick(item)">查看</view>) -->
              </view>
            </view>
          </view>
          <view class="scroll-bottom-placeholder" v-if="filteredAccessoryList.length > 0"></view>
        </view>
        <view class="empty-state" v-if="filteredAccessoryList.length === 0">
          <text class="empty-icon">📭</text>
          <text class="empty-text">没有找到匹配的配件</text>
        </view>
      </scroll-view>
    </view>

    <!-- ===== 品牌 & 机型联动弹窗 ===== -->
    <view class="picker-overlay" v-if="showPicker" @click="closePicker">
      <view class="picker-modal" @click.stop>
        <view class="picker-header">
          <text class="picker-title">选择品牌与机型</text>
          <text class="picker-close" @click="closePicker">✕</text>
        </view>

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

          <!-- 右列：机型 -->
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

        <view class="picker-footer">
          <view class="picker-btn cancel" @click="closePicker">取消</view>
          <view class="picker-btn confirm" @click="confirmPicker">确定</view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import { enrichWithProductImages } from '@/utils/productImage'

export default {
  name: 'Peijian',
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
      // 是否正在滚动品牌（用于防抖）
      isBrandScrolling: false,
      pendingBrandIndex: 0,

      // 配件分类（左侧）
      accessoryCategory: 'all',
      accessoryCategoryMap: {
        fight: '格斗改装',
        skin: '皮肤/外壳',
        battery: '电池电源',
        sensor: '传感器',
        arm: '机械臂/夹具'
      },

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

      // ===== 配件数据 =====
      accessoryList: [
        { id: 1, name: '合金护甲', price: '299', icon: '🛡️', category: 'fight', 
          brands: ['宇树', '智元', '乐聚', '众擎', '加速进化'], 
          models: ['G1', 'H1', 'Go2', '远征A1', '远征A2', '夸父', 'T800', 'SE01', 'Booster T1'] },
        { id: 2, name: '格斗铲', price: '199', icon: '🔧', category: 'fight', 
          brands: ['宇树', '众擎', '加速进化', '其他第三方'], 
          models: ['G1', 'Go2', 'T800', 'PM01', 'Booster T1', 'Booster K1'] },
        { id: 3, name: '高速电机', price: '599', icon: '⚡', category: 'fight', 
          brands: ['智元', '宇树', '小米', 'Figure AI'], 
          models: ['远征A1', '远征A2', 'G1', 'H1', 'CyberOne', 'Figure 01', 'Figure 02'] },
        { id: 4, name: '钛合金格斗爪', price: '899', icon: '⚔️', category: 'fight', 
          brands: ['宇树', '众擎', '加速进化', '智元', '乐聚'], 
          models: ['G1', 'Go2', 'B2', 'T800', 'Booster T1', '远征A1', '夸父'] },
        { id: 5, name: '机甲炫彩皮肤', price: '159', icon: '🎨', category: 'skin', 
          brands: ['宇树', '智元', '乐聚', '小米', '众擎'], 
          models: ['Go2', 'G1', '远征A1', '远征A2', '夸父', 'CyberOne', 'T800'] },
        { id: 6, name: '碳纤维外壳', price: '399', icon: '🛡️', category: 'skin', 
          brands: ['宇树', '智元', '优必选', '银河通用', '傅利叶'], 
          models: ['G1', 'H1', 'Go2', '远征A1', '远征A2', 'Walker S1', 'Galbot G1', 'GR-2'] },
        { id: 7, name: '夜光涂装', price: '259', icon: '✨', category: 'skin', 
          brands: ['宇树', '逐际动力', '云深处', '其他第三方'], 
          models: ['G1', 'Go2', 'TRON 1', '绝影X30'] },
        { id: 8, name: '赛博朋克皮肤', price: '299', icon: '🎭', category: 'skin', 
          brands: ['小米', '小鹏', '星动纪元', '优必选'], 
          models: ['CyberOne', 'IRON', '星动L7', 'Walker S1'] },
        { id: 9, name: '金属拉丝外壳', price: '459', icon: '🔩', category: 'skin', 
          brands: ['宇树', '智元', '新松', '汇川', '乐聚'], 
          models: ['G1', 'H1', '远征A1', '夸父', '睿可系列'] },
        { id: 10, name: '智能电池', price: '399', icon: '🔋', category: 'battery', 
          brands: ['宇树', '智元', '优必选', '傅利叶', '小米', '乐聚'], 
          models: ['G1', 'H1', 'Go2', '远征A1', '远征A2', 'Walker S1', 'GR-2', 'CyberOne', '夸父'] },
        { id: 11, name: '高容量电池组', price: '599', icon: '🔋', category: 'battery', 
          brands: ['宇树', '云深处', '逐际动力', 'Figure AI'], 
          models: ['B2', 'Go2', '绝影X30', 'TRON 1', 'Figure 02'] },
        { id: 12, name: '快充电源模块', price: '299', icon: '⚡', category: 'battery', 
          brands: ['智元', '众擎', '加速进化', '越疆', '星动纪元'], 
          models: ['远征A2', 'T800', 'Booster T1', 'Dobot Atom', '星动L7'] },
        { id: 13, name: '激光雷达', price: '899', icon: '📡', category: 'sensor', 
          brands: ['智元', '宇树', '优必选', '银河通用', '小鹏', 'Figure AI'], 
          models: ['远征A1', '远征A2', 'G1', 'H1', 'Walker S1', 'Galbot G1', 'IRON', 'Figure 02'] },
        { id: 14, name: '深度摄像头', price: '499', icon: '👁️', category: 'sensor', 
          brands: ['宇树', '智元', '乐聚', '小米', '星尘智能'], 
          models: ['G1', 'Go2', '远征A1', '夸父', 'CyberOne', 'Astribot S1'] },
        { id: 15, name: '防撞轮', price: '149', icon: '🔄', category: 'sensor', 
          brands: ['宇树', '云深处', '逐际动力', '其他第三方'], 
          models: ['Go2', 'B2', '绝影X30', 'TRON 1', 'TRON 2'] },
        { id: 16, name: '六轴IMU', price: '299', icon: '🧭', category: 'sensor', 
          brands: ['宇树', '智元', '众擎', '加速进化', '松延动力'], 
          models: ['G1', 'H1', '远征A2', 'T800', 'Booster T1', 'N系列'] },
        { id: 17, name: '超声波传感器', price: '199', icon: '📊', category: 'sensor', 
          brands: ['优必选', '傅利叶', '新松', '汇川', '艾利特'], 
          models: ['Walker C1', 'GR-3', '睿可系列', 'MetaBot S1', 'CS系列'] },
        { id: 18, name: '机械臂', price: '1,299', icon: '🦾', category: 'arm', 
          brands: ['智元', '宇树', '埃斯顿', '节卡', '越疆', '艾利特'], 
          models: ['远征A1', '远征A2', 'G1', 'ER12', 'ER30', 'Zu 3', 'Zu 7', 'Dobot Atom', 'CS系列'] },
        { id: 19, name: '灵巧手', price: '1,899', icon: '🤖', category: 'arm', 
          brands: ['智元', '宇树', '优必选', '傅利叶', '新松'], 
          models: ['远征A1', '远征A2', 'G1', 'Walker S1', 'GR-2', '睿可系列'] },
        { id: 20, name: '柔性夹具', price: '699', icon: '🔧', category: 'arm', 
          brands: ['节卡', '埃斯顿', '汇川', '艾利特', '微亿智造'], 
          models: ['Zu 3', 'Zu 7', 'ER12', 'ER30', 'MetaBot S1', 'CS系列', 'EIIR'] }
      ]
    };
  },
  computed: {
    filteredAccessoryList() {
      // 后台分类商品(传入 items)优先;为空则回退内部 mock
      if (this.items && this.items.length) return this.items;

      let list = this.accessoryList;
      if (this.selectedBrand !== '全部品牌') {
        list = list.filter(item => item.brands && item.brands.includes(this.selectedBrand));
      }
      if (this.selectedModel && this.selectedModel !== '全部机型') {
        list = list.filter(item => item.models && item.models.includes(this.selectedModel));
      }
      if (this.accessoryCategory !== 'all') {
        list = list.filter(item => item.category === this.accessoryCategory);
      }
      return list;
    }
  },
  created() {
    // ===== 初始化缓存 =====
    this.buildBrandModelCache();

    // ===== 预置稳定 sku,并使配件加购/结算可用真 id(若后台建了同 sku/同名已上架商品) =====
    this.accessoryList.forEach(it => { if (!it.sku) it.sku = 'ACC-' + it.id; });
    this.enrichAccessories();
  },
  methods: {
    // ===== 商品桥接:补图 + 真 id/真价(幂等、静默,接口失败或无匹配则保持现状) =====
    async enrichAccessories() {
      try {
        await enrichWithProductImages(this.accessoryList, { prefix: 'ACC' });
      } catch (e) {
        // 忽略,页面照旧
      }
    },
    // ===== 构建品牌 -> 机型缓存 =====
    buildBrandModelCache() {
      // 对每个品牌（包括"全部品牌"）预计算机型列表
      const allModels = new Set();
      this.accessoryList.forEach(item => {
        item.models.forEach(m => allModels.add(m));
      });
      this.brandModelCache['全部品牌'] = ['全部机型', ...Array.from(allModels).sort()];

      // 对每个具体品牌单独计算
      const uniqueBrands = [...new Set(this.accessoryList.flatMap(item => item.brands))];
      uniqueBrands.forEach(brand => {
        const models = new Set();
        this.accessoryList
          .filter(item => item.brands.includes(brand))
          .forEach(item => {
            item.models.forEach(m => models.add(m));
          });
        this.brandModelCache[brand] = ['全部机型', ...Array.from(models).sort()];
      });
    },

    // ===== 获取机型列表（从缓存中读取，O(1)） =====
    getModelsByBrand(brand) {
      return this.brandModelCache[brand] || ['全部机型'];
    },

    getCategoryLabel(category) {
      return this.accessoryCategoryMap[category] || category;
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
      // 只记录当前索引，不立即更新列表（减少卡顿）
      const idx = e.detail.value[0];
      this.pendingBrandIndex = idx;
      this.isBrandScrolling = true;
      // 同步更新临时索引（用于显示）
      this.tempBrandIndex = idx;
    },

    // ===== 品牌滑动结束（才真正更新机型列表） =====
    onBrandPickEnd() {
      if (!this.isBrandScrolling) return;
      this.isBrandScrolling = false;
      const idx = this.pendingBrandIndex;
      const brand = this.brandOptions[idx];
      // 从缓存读取机型列表
      this.tempModelOptions = this.getModelsByBrand(brand);
      // 机型滚轮回滚到第一个
      this.tempModelIndex = 0;
      // 强制更新视图
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
      // 确保机型索引有效
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

/* ===== 顶部筛选区域 ===== */
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

/* ===== 主体样式 ===== */
.main-body {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
}
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
  font-size: 24rpx;
  color: #1F2937;
  text-align: center;
  font-weight: 500;
}
.brand-item.active .brand-name {
  color: #165DFF;
  font-weight: 700;
}
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
.accessory-card {
  width: calc(50% - 8rpx);
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: auto;
  box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.06);
}
.accessory-image {
  width: 100%;
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.accessory-icon {
  font-size: 72rpx;
}
.accessory-img {
  width: 100%;
  height: 100%;
  display: block;
}
.accessory-info {
  flex: 1;
  padding: 8rpx 12rpx 12rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.accessory-name {
  font-size: 26rpx;
  font-weight: 600;
  color: #4B5563;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.accessory-category {
  font-size: 16rpx;
  color: #6B7280;
  background: #EEF2F6;
  padding: 2rpx 14rpx;
  border-radius: 20rpx;
  display: inline-block;
  align-self: flex-start;
}
.accessory-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.accessory-price-wrap {
  display: flex;
  align-items: baseline;
  gap: 8rpx;
  flex-wrap: wrap;
}
.accessory-price {
  font-size: 34rpx;
  font-weight: 700;
  color: #FF7820;
}
.accessory-price .ap-badge {
  font-size: 20rpx;
  font-weight: 500;
  color: #FF7820;
  margin-right: 2rpx;
}
.accessory-price .ap-cur {
  font-size: 20rpx;
  font-weight: 500;
  color: #FF7820;
}
.accessory-original {
  font-size: 18rpx;
  color: #C0C8D4;
  text-decoration: line-through;
  font-weight: 400;
}
.btn-action-accessory {
  background: #6EA8FF;
  border-radius: 20rpx;
  padding: 4rpx 20rpx;
  font-size: 20rpx;
  font-weight: 600;
  color: #fff;
  text-align: center;
  line-height: 1.8;
}
.btn-action-accessory:active {
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