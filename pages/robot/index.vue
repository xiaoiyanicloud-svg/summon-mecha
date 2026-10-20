<template>
  <view class="rental-page">
    <!-- ===== 顶部 Tab（五个）：每格 750/5=150rpx 宽 × 150rpx 高＝正方形。
         选中那格整块铺白，且顶栏不画 border-bottom，让白块与下方白色内容区连成一体。 ===== -->
    <view class="top-tabs" v-if="!customPlanMode">
      <text class="tab-item" :class="{ active: currentTab === 'rent' }" @tap="switchTab('rent')">租用</text>
      <text class="tab-item" :class="{ active: currentTab === 'scene' }" @tap="switchTab('scene')">场景</text>
      <text class="tab-item" :class="{ active: currentTab === 'buy' }" @tap="switchTab('buy')">购买</text>
      <text class="tab-item" :class="{ active: currentTab === 'skill' }" @tap="switchTab('skill')">技能</text>
      <text class="tab-item" :class="{ active: currentTab === 'accessory' }" @tap="switchTab('accessory')">配件</text>
    </view>

    <!-- ===== 自定义方案顶栏 ===== -->
    <view class="custom-plan-header" v-if="customPlanMode"></view>

    <!-- ===== 类别选择：原弹窗已改为内联向下展开（见 .type-bar 下方 .type-inline） ===== -->

    <!-- ===== 场景分类滚动条 - 已删除 ===== -->

    <!-- ===== 搜索框(租/购) =====
    <view class="search-bar" v-show="currentTab === 'rent' || currentTab === 'buy'">
      <input class="search-input" v-model="searchQ" placeholder="搜索机型 / 品牌 / 关键词" confirm-type="search" />
    </view>

    <!-- ===== 主体 ===== -->
    <view class="main-body">
      <!-- 租用/购买：品牌侧边栏 -->
      <scroll-view class="brand-sidebar" scroll-y="true" show-scrollbar="false" v-show="currentTab === 'rent' || currentTab === 'buy'" @scroll="onPanelScroll('brand')">
        <view class="brand-item" :class="{ active: currentBrand === 'all' }" @tap="selectBrand('all')">
          <text class="brand-name">全部品牌</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'yushu' }" @tap="selectBrand('yushu')">
          <text class="brand-name">宇树</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'zhiyuan' }" @tap="selectBrand('zhiyuan')">
          <text class="brand-name">智元</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'youbixuan' }" @tap="selectBrand('youbixuan')">
          <text class="brand-name">优必选</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'zhongqing' }" @tap="selectBrand('zhongqing')">
          <text class="brand-name">众擎</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'leju' }" @tap="selectBrand('leju')">
          <text class="brand-name">乐聚</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'galaxy' }" @tap="selectBrand('galaxy')">
          <text class="brand-name">银河通用</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'engine' }" @tap="selectBrand('engine')">
          <text class="brand-name">加速进化</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'fourier' }" @tap="selectBrand('fourier')">
          <text class="brand-name">傅利叶</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'xiaomi' }" @tap="selectBrand('xiaomi')">
          <text class="brand-name">小米</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'figure' }" @tap="selectBrand('figure')">
          <text class="brand-name">Figure AI</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'dajiang' }" @tap="selectBrand('dajiang')">
          <text class="brand-name">大疆</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'qinglang' }" @tap="selectBrand('qinglang')">
          <text class="brand-name">擎朗</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'pudu' }" @tap="selectBrand('pudu')">
          <text class="brand-name">普渡</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'gaoxian' }" @tap="selectBrand('gaoxian')">
          <text class="brand-name">高仙</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'yunjii' }" @tap="selectBrand('yunjii')">
          <text class="brand-name">云迹</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'estun' }" @tap="selectBrand('estun')">
          <text class="brand-name">埃斯顿</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'inovance' }" @tap="selectBrand('inovance')">
          <text class="brand-name">汇川</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'siasun' }" @tap="selectBrand('siasun')">
          <text class="brand-name">新松</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'efort' }" @tap="selectBrand('efort')">
          <text class="brand-name">埃夫特</text>
        </view>
        <view class="brand-item" :class="{ active: currentBrand === 'jaka' }" @tap="selectBrand('jaka')">
          <text class="brand-name">节卡</text>
        </view>
        <view style="height: 250rpx; flex-shrink: 0;"></view>
      </scroll-view>

      <!-- 场景：场景分类左侧 -->
      <scroll-view class="brand-sidebar" scroll-y="true" show-scrollbar="false" v-show="currentTab === 'scene'" @scroll="onPanelScroll('brand')">
        <view class="brand-item" :class="{ active: currentSceneCategory === 'all' }" @tap="selectSceneCategory('all')">
          <text class="brand-name">全部场景</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'business_event' }" @tap="selectSceneCategory('business_event')">
          <text class="brand-name">商业活动</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'enterprise' }" @tap="selectSceneCategory('enterprise')">
          <text class="brand-name">企业商用</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'culture_tourism' }" @tap="selectSceneCategory('culture_tourism')">
          <text class="brand-name">文旅景区</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'education' }" @tap="selectSceneCategory('education')">
          <text class="brand-name">科普教育</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'security' }" @tap="selectSceneCategory('security')">
          <text class="brand-name">安全巡检</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'warehouse_logistics' }" @tap="selectSceneCategory('warehouse_logistics')">
          <text class="brand-name">仓储物流</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'industry' }" @tap="selectSceneCategory('industry')">
          <text class="brand-name">工业现场</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'medical' }" @tap="selectSceneCategory('medical')">
          <text class="brand-name">医疗康养</text>
        </view>
        <view class="brand-item" :class="{ active: currentSceneCategory === 'home' }" @tap="selectSceneCategory('home')">
          <text class="brand-name">家庭个人</text>
        </view>
        <view style="height: 250rpx; flex-shrink: 0;"></view>
      </scroll-view>

      <!-- ===== 右侧内容列 ===== -->
      <view class="content-column">

        <!-- 租用/购买 分类滚动条（与左边栏「全部品牌」同行） -->
        <view class="type-bar" v-if="currentTab === 'rent' || currentTab === 'buy'">
          <!-- 电商排序：综合 / 价格（在类别左边） -->
          <view class="sort-group">
            <text class="sort-item" :class="{ active: currentSort === 'default' }" @tap="setSort('default')">综合</text>
            <!-- 默认态显示 ↓（真机上 ↕ 会被当 emoji 渲染成大块，故不用那个字符）；正序/倒序都由 ↓/↑ 表示 -->
            <text class="sort-item price" :class="{ active: currentSort !== 'default' }" @tap="togglePriceSort">价格<text class="sort-arrow">{{ priceSortMark }}</text></text>
          </view>
          <!-- 分类：「分类」标签与类别值合体成一段文字（默认「全部分类」），点开在下方就地展开 -->
          <view class="cat-group">
            <view class="cat-picker" @tap="openTypePicker">
              <text class="cat-text">{{ currentTypeLabel }}</text>
              <text class="cat-arrow">{{ showTypePicker ? '▲' : '▼' }}</text>
            </view>
          </view>
          <text class="type-item custom-plan-fixed" v-if="!customPlanMode && currentTab === 'rent'" @tap="enterCustomPlanMode">+方案</text>
        </view>

        <!-- 类别：点「分类」后在下方就地展开（非弹窗），再点一次收起 -->
        <view class="type-inline" v-if="showTypePicker && (currentTab === 'rent' || currentTab === 'buy')">
          <view class="type-opt" :class="{ active: currentType === 'all' }" @tap="selectType('all'); showTypePicker = false">全部分类</view>
          <view class="type-opt" :class="{ active: currentType === t.code }" v-for="t in rentTypeOptions" :key="t.code" @tap="selectType(t.code); showTypePicker = false">{{ t.label }}</view>
        </view>

        <!-- 租用 -->
        <view class="product-area-wrapper" v-show="currentTab === 'rent'">
        <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false" enable-flex @scroll="onPanelScroll('product')">
          <view class="product-flex-wrapper">
            <view v-if="currentBrand === 'all'" class="enterprise-banner" @tap="goEnterprise">
              <view class="banner-left">
                <text class="banner-icon"></text>
                <text class="banner-title">企业部署</text>
              </view>
              <view class="banner-right">
                <view class="banner-btn">咨询</view>
              </view>
            </view>
            <view class="product-card" :class="{ selected: customPlanMode && isPlanSelected(item) }" v-for="item in filteredRentList" :key="item.id" @tap="customPlanMode ? togglePlanItem(item) : goDetail(item)">
              <view class="product-image" :style="{ backgroundColor: item.bg }">
                <image v-if="item.image" class="product-img" :src="item.image" mode="aspectFill"></image>
                <text v-else class="product-icon">{{ item.icon }}</text>
              </view>
              <view v-if="customPlanMode" class="product-select-btn" @tap.stop="togglePlanItem(item)">{{ isPlanSelected(item) ? '✓' : '＋' }}</view>
              <view class="product-info">
                <text class="product-name">{{ item.name }}</text>
                <text class="scene-tag">{{ item.scene }}</text>
                <view class="product-bottom">
                  <view class="product-price-wrap">
                    <text class="product-price"><text class="price-badge">到手价</text><text class="price-cur">¥</text>{{ item.bagistoPrice || item.price }}</text>
                    <text class="product-original" v-if="item.originalPrice">¥{{ item.originalPrice }}</text>
                  </view>
                  <!-- [TOB_BTN_RENT] 已隐藏租用卡按钮(原: <view class="btn-action" @tap.stop="handleAction(item,'rent')">租用</view>) -->
                </view>
              </view>
            </view>
            <view class="scroll-bottom-placeholder" v-if="filteredRentList.length > 0"></view>
          </view>
          <view class="empty-state" v-if="filteredRentList.length === 0 && !catalogLoading && !catalogError">
            <text class="empty-icon">📭</text>
            <text class="empty-text">没有找到匹配的机器人</text>
          </view>
          <state-view
            v-else-if="filteredRentList.length === 0"
            :loading="catalogLoading" :error="catalogError" compact btn-color="#102F6E" @retry="reloadCatalog"
          />
        </scroll-view>
      </view>

      <!-- 购买 -->
      <view class="product-area-wrapper" v-show="currentTab === 'buy'">
        <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false" enable-flex @scroll="onPanelScroll('product')">
          <view class="product-flex-wrapper">
            <view v-if="currentBrand === 'all'" class="enterprise-banner" @tap="goEnterprise">
              <view class="banner-left">
                <text class="banner-icon"></text>
                <text class="banner-title">企业部署</text>
              </view>
              <view class="banner-right">
                <view class="banner-btn">咨询</view>
              </view>
            </view>
            <view class="product-card" v-for="item in filteredBuyList" :key="item.id" @tap="goDetail(item)">
              <view class="product-image" :style="{ backgroundColor: item.bg }">
                <image v-if="item.image" class="product-img" :src="item.image" mode="aspectFill"></image>
                <text v-else class="product-icon">{{ item.icon }}</text>
              </view>
              <view class="product-info">
                <text class="product-name">{{ item.name }}</text>
                <text class="scene-tag">{{ item.scene }}</text>
                <view class="product-bottom">
                  <view class="product-price-wrap">
                    <text class="product-price"><text class="price-badge">到手价</text><text class="price-cur">¥</text>{{ item.bagistoPrice || item.price }}</text>
                    <text class="product-original" v-if="item.originalPrice">¥{{ item.originalPrice }}</text>
                  </view>
                  <!-- [TOB_BTN_BUY] 已隐藏购买卡按钮(原: <view class="btn-action" @tap.stop="handleAction(item,'buy')">购买</view>) -->
                </view>
              </view>
            </view>
            <view class="scroll-bottom-placeholder" v-if="filteredBuyList.length > 0"></view>
          </view>
          <view class="empty-state" v-if="filteredBuyList.length === 0 && !catalogLoading && !catalogError">
            <text class="empty-icon">📭</text>
            <text class="empty-text">没有找到匹配的机器人</text>
          </view>
          <state-view
            v-else-if="filteredBuyList.length === 0"
            :loading="catalogLoading" :error="catalogError" compact btn-color="#102F6E" @retry="reloadCatalog"
          />
        </scroll-view>
      </view>

      <!-- 场景 -->
      <view class="product-area-wrapper" v-show="currentTab === 'scene'">
        <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false" enable-flex @scroll="onPanelScroll('product')">
          <StateView :loading="scenesLoading" :error="scenesError" :empty="!filteredSceneList.length" empty-text="没有找到匹配的场景方案" @retry="refreshScenes" />
          <view class="scene-list-wrapper" v-if="!scenesLoading && !scenesError">
            <view v-if="currentSceneCategory !== 'all'" class="scene-category-header">
              <text class="scene-category-title">{{ currentCategoryLabel }}</text>
            </view>
            <view class="scene-list-item" v-for="scene in filteredSceneList" :key="scene.id" @tap="goSceneDetail(scene)">
              <view class="scene-list-icon" :style="{ backgroundColor: scene.bg || '#E8F0FE' }">
                <image v-if="scene.banner" class="scene-list-img" :src="scene.banner" mode="aspectFill"></image>
                <text v-else>{{ scene.icon }}</text>
              </view>
              <view class="scene-list-info">
                <text class="scene-list-name">{{ scene.name }}</text>
                <text v-if="scene.desc" class="scene-list-desc">{{ scene.desc }}</text>
                <view class="scene-list-tags">
                  <text class="scene-list-tag" v-for="tag in scene.tags" :key="tag">{{ tag }}</text>
                </view>
              </view>
              <!-- [TOB_BTN_SCENE] 场景卡无操作按钮(仅箭头›导航), 无需去除 -->
              <view class="scene-list-arrow">›</view>
            </view>
            <view class="scroll-bottom-placeholder" v-if="filteredSceneList.length > 0"></view>
          </view>
        </scroll-view>
      </view>

      <!-- 技能组件 -->
      <Jineng ref="skillMarket" v-show="currentTab === 'skill'" :active="currentTab === 'skill'" @item-click="onSkill" />

      <!-- 配件组件 -->
      <Peijian v-show="currentTab === 'accessory'" :items="accessoryCatalog" @item-click="onAccessory" />
      </view>

      <!-- 滚动提示：轻微淡入，暗示可继续滑动查看 -->
      <view class="scroll-hint brand-hint" :class="{ 'is-visible': brandHint }"></view>
      <view class="scroll-hint product-hint" :class="{ 'is-visible': scrollHint }"></view>
    </view>

    <!-- ========== 全屏商品详情页 ========== -->
    <view class="detail-full-overlay" v-if="showDetail" @tap="closeDetail">
      <view class="detail-full" @tap.stop>
        <view class="detail-nav">
          <text class="detail-back" @tap="closeDetail">‹</text>
          <!-- [DETAIL_TITLE] 已去除顶部标题"商品详情"(如需恢复: 把下行改为"商品详情") -->
          <text class="detail-nav-title"></text>
          <text class="detail-nav-placeholder"></text>
        </view>
        <scroll-view class="detail-scroll" scroll-y="true" show-scrollbar="false" v-if="selectedProduct">
          <swiper class="detail-swiper" :style="{ height: detailImgHeight + 'rpx' }" indicator-dots indicator-color="rgba(255,255,255,0.5)" indicator-active-color="#ffffff" autoplay circular>
            <swiper-item v-for="(img, idx) in detailImageList" :key="idx">
              <view class="swiper-slide" :style="{ backgroundColor: selectedProduct.bg }">
                <image v-if="typeof img === 'string'" class="swiper-img" :src="img" mode="widthFix" @load="onDetailImgLoad"></image>
                <text v-else class="swiper-icon">{{ selectedProduct.icon }}</text>
              </view>
            </swiper-item>
          </swiper>
          <view class="detail-info-area">
            <view class="detail-price-row">
              <text class="detail-price"><text class="detail-badge">到手价</text><text class="detail-cur">¥</text>{{ detailPrice }}</text>
              <text class="detail-day" v-if="actionType === 'rent'">/天起</text>
              <text class="detail-original" v-if="selectedProduct.originalPrice">¥{{ selectedProduct.originalPrice }}</text>
            </view>
            <text class="detail-name">{{ selectedProduct.name }}</text>
            <!-- [LINK_TAG] 已隐藏"已对接官方商品"状态提示(原: ●已对接官方商品 / ●未对接官方商品) -->
          </view>
          <!-- 适配机型:技能/配件专用,数据来自后台「关联商品」(GET /api/products/{id}/related) -->
          <view class="adapt-card" v-if="relatedList.length">
            <text class="adapt-title">适配机型</text>
            <view class="adapt-list">
              <text class="adapt-name" v-for="r in relatedList" :key="r.id">{{ r.name }}</text>
            </view>
          </view>
          <!-- 产品参数:有后端属性时展示(已过滤系统字段),无则隐藏 -->
          <view class="spec-card" v-if="detailSpecs.length">
            <view class="spec-row" v-for="(s, idx) in detailSpecs" :key="idx">
              <text class="spec-label">{{ s.name }}</text>
              <text class="spec-value">{{ s.value }}</text>
            </view>
          </view>
          <!-- [DETAIL_COUPON] 已隐藏详情页优惠券区(恢复: 取消下行注释,恢复原有横向滚动优惠券列表) -->
          <!-- <view class="coupon-area">
            <text class="coupon-label">优惠券</text>
            <scroll-view class="coupon-scroll" scroll-x="true" show-scrollbar="false">
              <view class="coupon-item" v-for="(coupon, idx) in coupons" :key="idx">{{ coupon }}</view>
            </scroll-view>
          </view> -->
          <view class="service-area">
            <text class="service-title">{{ serviceInfo.title }}</text>
            <view class="service-item" v-for="(svc, idx) in serviceInfo.items" :key="idx">
              <text class="service-label" v-if="svc.label">{{ svc.label }}</text>
              <text class="service-value">{{ svc.value }}</text>
            </view>
          </view>
          <view class="detail-tabs">
            <text class="detail-tab" :class="{ active: detailTab === 'desc' }" @tap="detailTab = 'desc'">商品详情</text>
            <text class="detail-tab" :class="{ active: detailTab === 'faq' }" @tap="detailTab = 'faq'">常见问题</text>
          </view>
          <view class="tab-content" v-show="detailTab === 'desc'">
            <view class="rich-text">
              <text class="rich-title">产品介绍</text>
              <!-- 连接后台商品描述(富文本);无则显示占位,不再用硬代码 -->
              <rich-text v-if="selectedProduct.bagistoDescription" class="rich-html" :nodes="selectedProduct.bagistoDescription"></rich-text>
              <text v-else class="rich-text-empty">暂无商品详情</text>
            </view>
          </view>
          <view class="tab-content" v-show="detailTab === 'faq'">
            <view class="faq-list">
              <view class="faq-item" v-for="(faq, idx) in faqList" :key="idx">
                <text class="faq-q">Q：{{ faq.q }}</text>
                <text class="faq-a">A：{{ faq.a }}</text>
              </view>
            </view>
          </view>
          <view style="height: 140rpx;"></view>
        </scroll-view>
        <view class="detail-bottom-bar">
          <view class="bottom-left-icons">
            <view class="icon-btn" @tap="callService">
              <text>💬</text>
              <text class="icon-label">客服</text>
            </view>
            <view class="icon-btn" @tap="addCart">
              <text>🛒</text>
              <text class="icon-label">购物车</text>
            </view>
          </view>
          <view class="bottom-right-btns">
            <view class="bottom-btn secondary" @tap="addCart">加入购物车</view>
            <view class="bottom-btn primary" @tap="confirmAction(actionType)">
              {{ actionType === 'rent' ? '立即租用' : (actionType === 'skill' ? '立即开通' : '立即下单') }}
            </view>
          </view>
        </view>
      </view>
    </view>

    <!-- ===== 租用底部弹层（价格/商品/数量/产品类型/服务时间/确认） ===== -->
    <view class="rent-modal-mask" v-if="showRentModal">
      <view class="rent-modal-backdrop" @tap="cancelRentModal"></view>
      <view class="rent-sheet">
        <!-- 顶部：到手价 + 原价 -->
        <view class="sheet-head">
          <view class="sheet-price-wrap">
            <text class="sheet-price">¥{{ selectedProduct.price }}</text>
            <text class="sheet-unit">/天起</text>
            <text class="sheet-origin">¥{{ Math.round(Number(String(selectedProduct.price).replace(/,/g, '')) * 1.2).toLocaleString() }}</text>
          </view>
          <text class="sheet-close" @tap="cancelRentModal">✕</text>
        </view>

        <!-- 商品：缩略图 + 名称 + 数量 -->
        <view class="sheet-product">
          <view class="sheet-thumb" :style="{ backgroundColor: selectedProduct.bg }">
            <text class="sheet-thumb-icon">{{ selectedProduct.icon }}</text>
          </view>
          <text class="sheet-product-name">{{ selectedProduct.name }}</text>
          <view class="sheet-qty">
            <text class="qty-btn" @tap="onRentQtyChange(-1)">-</text>
            <text class="qty-num">{{ rentForm.qty }}</text>
            <text class="qty-btn" @tap="onRentQtyChange(1)">+</text>
          </view>
        </view>

        <!-- 产品类型名称（只读展示） -->
        <view class="sheet-field">
          <text class="sheet-label">产品类型名称</text>
          <view class="sheet-value static">
            <text class="sheet-value-text">{{ selectedProduct.name }}（1天起单台每日价格）【{{ rentTypeOptions[rentForm.productTypeIndex].label }}租】</text>
          </view>
        </view>

        <!-- 服务时间（日期 picker-view + 租期点选） -->
        <view class="sheet-field">
          <text class="sheet-label">服务时间</text>
          <view class="sheet-date-row">
            <view class="date-pill" @tap="showDatePicker = !showDatePicker">
              {{ rentForm.startDate || '选择日期' }}<text class="pv-arrow">⌄</text>
            </view>
            <view class="date-pill days">{{ rentPeriodOptions[rentForm.daysIndex] }}</view>
            <view class="date-pill">{{ addDays(rentForm.startDate, rentDays()) }}</view>
          </view>

          <!-- 日期三列滚动选择 -->
          <picker-view v-if="showDatePicker" :value="dateVal" @change="onDatePvChange" class="date-pv">
            <picker-view-column>
              <view v-for="y in dateYearList" :key="y" class="pv-item">{{ y }}年</view>
            </picker-view-column>
            <picker-view-column>
              <view v-for="m in monthColumn()" :key="m" class="pv-item">{{ m }}月</view>
            </picker-view-column>
            <picker-view-column>
              <view v-for="d in dayColumn()" :key="d" class="pv-item">{{ d }}日</view>
            </picker-view-column>
          </picker-view>

          <!-- 租期点选 -->
          <view class="days-chips">
            <view class="days-chip" v-for="(op, i) in rentPeriodOptions" :key="op"
                  :class="{ active: rentForm.daysIndex === i }" @tap="rentForm.daysIndex = i">{{ op }}</view>
          </view>
        </view>

        <!-- 确认 -->
        <view class="sheet-confirm" @tap="confirmRent">确认 ¥{{ rentTotal() }}元</view>
      </view>
    </view>

    <!-- ===== 自定义方案：底部方案框 ===== -->
    <view class="scene-cart-bar" v-if="customPlanMode">
      <!-- 右上角操作：我的方案 / 退出 -->
      <view class="cart-bar-top">
        <view class="cpp-btn" @tap="openCustomPlans">我的方案</view>
        <view class="cpp-btn" @tap="exitCustomPlanMode">退出</view>
      </view>
      <!-- 无机型时的引导 -->
      <view v-if="!selectedPlanItems.length" class="scene-cart-empty">点上方机型的「＋」添加到方案</view>
      <!-- 已选机型列表(每台：图/名/单价/移除) -->
      <scroll-view v-else class="cart-items-scroll" scroll-x="true" show-scrollbar="false">
        <view class="cart-item" v-for="m in selectedPlanItems" :key="(m.bagistoId || m.id)">
          <view class="cart-item-img" :style="{ backgroundColor: m.bg || '#F0F4FA' }">
            <image v-if="m.image" class="cart-item-img-img" :src="m.image" mode="aspectFill" />
            <text v-else class="cart-item-img-icon">{{ m.icon || '🤖' }}</text>
            <view class="cart-item-del" @tap.stop="togglePlanItem(m)">×</view>
          </view>
          <text class="cart-item-name">{{ m.name }}</text>
          <text class="cart-item-price">¥{{ m.price }}/天</text>
        </view>
      </scroll-view>
      <!-- 租期(开始/结束日期) + 合计 -->
      <view class="scene-cart-row">
        <text class="cart-date-label">租期</text>
        <view class="date-pill" @tap="planShowStartPicker = !planShowStartPicker">{{ planStartDate || '开始' }}<text class="pv-arrow">⌄</text></view>
        <view class="date-pill" @tap="planShowEndPicker = !planShowEndPicker">{{ planEndDate || '结束' }}<text class="pv-arrow">⌄</text></view>
      </view>
      <view class="scene-cart-row">
        <text class="scene-cart-total">已选 {{ selectedPlanItems.length }} 台 · 共 {{ planDays }} 天 ¥{{ planTotal() }}</text>
      </view>
      <view class="scene-cart-actions">
        <view class="scene-cart-save" @tap="saveCustomPlan">保存方案</view>
        <view class="scene-cart-submit" @tap="submitCustomPlanOrder">提交订单</view>
      </view>
    </view>

    <!-- ===== 方案栏 · 日期选择弹层(开始/结束) ===== -->
    <view class="plan-date-mask" v-if="customPlanMode && (planShowStartPicker || planShowEndPicker)" @tap="planShowStartPicker = false; planShowEndPicker = false">
      <view class="plan-date-modal" @tap.stop>
        <view class="plan-date-head">
          <text class="plan-date-title">{{ planShowStartPicker ? '选择开始日期' : '选择结束日期' }}</text>
          <text class="plan-date-close" @tap="planShowStartPicker = false; planShowEndPicker = false">✕</text>
        </view>
        <picker-view class="date-pv" :value="dateVal" @change="planShowStartPicker ? onPlanStartDateChange : onPlanEndDateChange">
          <picker-view-column><view v-for="y in dateYearList" :key="y" class="pv-item">{{ y }}年</view></picker-view-column>
          <picker-view-column><view v-for="m in monthColumn()" :key="m" class="pv-item">{{ m }}月</view></picker-view-column>
          <picker-view-column><view v-for="d in dayColumn()" :key="d" class="pv-item">{{ d }}日</view></picker-view-column>
        </picker-view>
        <view class="plan-date-ok" @tap="planShowStartPicker = false; planShowEndPicker = false">确定</view>
      </view>
    </view>

    <!-- ===== 我的方案弹层 ===== -->
    <view class="plan-mask" v-if="customPlanMode && showCustomPlanModal" @tap="showCustomPlanModal = false">
      <view class="plan-modal" @tap.stop>
        <view class="plan-modal-head">
          <text class="plan-modal-title">我的方案</text>
          <text class="plan-modal-close" @tap="showCustomPlanModal = false">✕</text>
        </view>
        <scroll-view class="plan-modal-list" scroll-y="true">
          <view class="plan-item" v-for="p in customPlanList" :key="p.id" @tap="loadPlanFromList(p)">
            <view class="plan-item-info">
              <text class="plan-item-name">{{ p.name }} · {{ p.items.length }}台 · {{ p.days }}天</text>
              <text class="plan-item-total">¥{{ p.total }}</text>
            </view>
            <view class="plan-item-del" @tap.stop="deleteCustomPlan(p)">删除</view>
          </view>
          <view class="plan-empty" v-if="!customPlanList.length">还没有保存的方案</view>
        </scroll-view>
      </view>
    </view>

    <!-- ============================================================ -->
    <!-- ===== 自定义底部导航栏 ===== -->
    <view class="custom-tabbar" v-show="!showDetail && !customPlanMode">
      <view 
        class="tab-item" 
        v-for="(tab, index) in bottomTabs" 
        :key="index"
        :class="{ 'is-center': index === 2 }"
        @click="switchBottomTab(tab.pagePath)"
      >
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

    <!-- ============================================================ -->
    <!-- ===== 企业部署咨询弹窗 ===== -->
    <view class="enterprise-mask" v-if="showEnterpriseModal" @tap="closeEnterpriseModal">
      <view class="enterprise-modal" @tap.stop>
        <view class="em-close" @tap="closeEnterpriseModal">✕</view>
        <view class="em-header">
          <text class="em-title">企业部署咨询</text>
          <text class="em-subtitle">{{ enterpriseForm.mode === 'rent' ? '批量租用享专属优惠，专家1小时内为您定制方案' : '批量采购享阶梯折扣，专家1小时内为您定制方案' }}</text>
        </view>
        <scroll-view class="em-body" scroll-y="true" show-scrollbar="false">
          <!-- 意向类型 -->
          <view class="em-item">
            <text class="em-label">意向类型 <text class="required">*</text></text>
            <view class="em-tag-group">
              <text class="em-tag" :class="{ active: enterpriseForm.mode === 'rent' }" @tap="selectEnterpriseMode('rent')">批量租用</text>
              <text class="em-tag" :class="{ active: enterpriseForm.mode === 'buy' }" @tap="selectEnterpriseMode('buy')">批量采购</text>
            </view>
          </view>
          <!-- 身份 -->
          <view class="em-item">
            <text class="em-label">您的身份 <text class="required">*</text></text>
            <view class="em-tag-group">
              <text class="em-tag" :class="{ active: enterpriseForm.identity === 'enterprise' }" @tap="selectEnterpriseIdentity('enterprise')">企业</text>
              <text class="em-tag" :class="{ active: enterpriseForm.identity === 'personal' }" @tap="selectEnterpriseIdentity('personal')">个人</text>
            </view>
          </view>
          <!-- 联系人 -->
          <view class="em-item">
            <text class="em-label">联系人 <text class="required">*</text></text>
            <input class="em-input" type="text" v-model="enterpriseForm.name" placeholder="请输入您的姓名" />
          </view>
          <!-- 手机号 -->
          <view class="em-item">
            <text class="em-label">手机号 <text class="required">*</text></text>
            <input class="em-input" type="number" v-model="enterpriseForm.phone" placeholder="请输入您的手机号" maxlength="11" />
          </view>
          <!-- 微信号 -->
          <view class="em-item">
            <text class="em-label">微信号 <text class="optional">选填</text></text>
            <input class="em-input" type="text" v-model="enterpriseForm.wechat" placeholder="请输入微信号" />
          </view>
          <!-- 公司名称 -->
          <view class="em-item">
            <text class="em-label">公司名称 <text class="required" v-if="enterpriseForm.identity === 'enterprise'">*</text><text class="optional" v-else>选填</text></text>
            <input class="em-input" type="text" v-model="enterpriseForm.company" :placeholder="enterpriseForm.identity === 'enterprise' ? '请输入公司名称' : '请输入公司名称（选填）'" />
          </view>
          <!-- 需求描述 -->
          <view class="em-item">
            <text class="em-label">需求描述 <text class="optional">选填</text></text>
            <input class="em-input" type="text" v-model="enterpriseForm.demand" placeholder="如：需要人形机器人10台，园区接待" />
          </view>
        </scroll-view>
        <view class="em-footer">
          <view class="em-btn secondary" @tap="closeEnterpriseModal">稍后再说</view>
          <view class="em-btn primary" @tap="submitEnterprise">立即提交</view>
        </view>
      </view>
    </view>
  </view>
</template>

<script>
import Jineng from '@/components/jiqi/jineng.vue'
import { skillDetailUrl } from '@/utils/skill-market'
import Peijian from '@/components/jiqi/peijian.vue'
import StateView from '@/components/StateView.vue'
import { cartApi, productApi, categoryApi, catalogApi } from '@/utils/api'
import { openSceneRequest, sceneDraftPayload } from '@/utils/scene-request'
import { sceneProducts, categoryMap, loadBackendScenes } from '@/components/jiqi/changjing-data.js'
import { BASE_URL } from '@/utils/config'

// 场景数据统一在 components/jiqi/changjing.vue（见上方 import sceneProducts）



const accessoryCategoryMap = {
  fight: '格斗改装',
  skin: '皮肤/外壳',
  battery: '电池电源',
  sensor: '传感器',
  arm: '机械臂/夹具'
}

// 稳定 sku:用于与 Bagisto 商品对齐。运营在后台按 RENT-<id> 或同名建已上架商品,
// 前端加购/结算即可用真 id 走 Bagisto 真下单(否则回退意向单,mock 不变)。

// ===== 详情「服务/售后」区内容（按板块区分: 租赁 / 购买・配件 / 技能） =====
const BUY_SERVICE = {
  title: '大平台・有保障｜官方正品',
  items: [
    { label: '配送', value: '全国包邮' },
    { label: '维保', value: '官方质保' },
    { label: '售后', value: '7 天无理由（不影响二次销售）' },
  ],
}
const serviceConfigs = {
  rent: {
    title: '大平台・有保障',
    items: [
      { label: '配送', value: '工程师上门 1v 服务' },
      { label: '服务区域', value: '全国（偏远地区除外）' },
    ],
  },
  buy: BUY_SERVICE,
  accessory: BUY_SERVICE,
  skill: {
    title: '大平台・有保障',
    // ⚠ 原第一行是「绑定序列号云端下发」—— 那时后端零实现,是对用户的假承诺。
    //    P0 的真实能力是:下单即自动开通授权 → 在「我的设备」绑定机器 → 技能包在应用内鉴权下载。
    //    等 P1 设备拉取通道上线,再把措辞改回「云端下发」。
    items: [
      { label: '开通', value: '下单即开通,指派一台已登记设备后即可使用' },
      { label: '', value: '技能包可在应用内下载' },
      { label: '', value: '提供远程协助' },
      { label: '', value: '使用后不支持退货' },
    ],
  },
}

// 富文本图片地址修复: 把描述里的绝对URL(如 http://localhost/..)或相对路径(如 /storage/..)统一重写为前端 BASE_URL 绝对地址, 否则 <rich-text> 加载不出图片
function fixDescUrls(html) {
  if (!html) return html
  const base = String(BASE_URL)
  let out = String(html)
    .replace(/(src|href)=["']https?:\/\/[^\/"']+(?=\/)/gi, (_m, a) => `${a}="${base}`)
    .replace(/(src|href)=["'](\/(?!\/)[^"']*)/g, (_m, a, p) => `${a}="${base}${p}`)
  // 图片响应式: 去掉固定 width/height/内联style, 改成宽100%+高度自适应, 收进产品介绍区避免右半被裁切
  // width="100%" 用属性实现(uni-app rich-text 对 img 的 width 属性必定生效, 现状660px截断即为证据); 内联style兜底给浏览器直接生效
  out = out.replace(/<img([^>]*)>/gi, (_m, attrs) => {
    let a = String(attrs)
      .replace(/\s+(?:width|height|style)=("[^"]*"|'[^']*'|\S+)/gi, '')
      .replace(/\s*\/\s*$/, '')
    return `<img${a} width="100%" style="height:auto;display:block;max-width:100%">`
  })
  return out
}

// ===== 品牌/类别推断(v1:按商品名关键词,后台建品用规范命名即可被侧栏筛选识别;识别不出回退 'all' 恒显示) =====
function detectBrandKey(name) {
  const n = String(name || '')
  const table = [
    ['宇树', 'yushu'], ['智元', 'zhiyuan'], ['优必选', 'youbixuan'], ['众擎', 'zhongqing'],
    ['乐聚', 'leju'], ['银河', 'galaxy'], ['加速进化', 'engine'], ['傅利叶', 'fourier'],
    ['小米', 'xiaomi'], ['Figure', 'figure'], ['大疆', 'dajiang'], ['擎朗', 'qinglang'],
    ['普渡', 'pudu'], ['高仙', 'gaoxian'], ['云迹', 'yunjii'], ['埃斯顿', 'estun'],
    ['汇川', 'inovance'], ['新松', 'siasun'], ['埃夫特', 'efort'], ['节卡', 'jaka'],
  ]
  for (const [kw, key] of table) { if (n.includes(kw)) return key }
  return 'all'
}
function detectTypeKey(name) {
  const n = String(name || '')
  if (/人形|仿生|humanoid|Walker|远征|CyberOne|Figure/i.test(n)) return 'humanoid'
  if (/四足|机器狗|Go1|Go2|CyberDog|绝影/i.test(n)) return 'quadruped'
  if (/配送|送餐|传菜|欢乐送|贝拉|闪电|T3|T5/i.test(n)) return 'delivery'
  if (/清洁|扫地|洗地|高仙/i.test(n)) return 'cleaning'
  if (/迎宾|接待|讲解|导览|Cruzr/i.test(n)) return 'reception'
  if (/巡检|巡逻|巡查|M300|M30T|无人机/i.test(n)) return 'inspection'
  if (/机械臂|机械手|ER\d|IR\d|SR\d/i.test(n)) return 'arm'
  if (/陪伴|教育|Aelos|Roban/i.test(n)) return 'companion'
  return 'all'
}

// ===== 目录缓存(模块级,5 分钟 TTL):onShow 频繁触发不再全量重拉,切回本页秒开 =====
const CATALOG_TTL = 5 * 60 * 1000
let _catalogRawCache = null // {rent:[],buy:[],accessory:[],skill:[]} 原始商品数组;命中后经 applyCatalog 重新映射,mapCatalogProduct 为纯函数无共享可变风险
let _catalogCachedAt = 0

// 适配机型请求的竞态 token:详情浮层可以在上一个请求在途时被替换成另一个商品,
// 回来时比对一下,过期的响应直接丢弃(项目的 request.js 不返回 task,没法 abort)
let _relatedSeq = 0

export default {
  components: { Jineng, Peijian, StateView },
  data() {
    return {
      currentTab: 'rent',
      rentCatalog: [],
      buyCatalog: [],
      accessoryCatalog: [],
      skillCatalog: [],
      // 目录三态:加载中 / 拉取失败 / 真·没有匹配。失败不再伪装成「没有找到匹配的机器人」
      catalogLoading: false,
      catalogError: '',
      currentType: 'all',
      showTypePicker: false,
      currentBrand: 'all',
      currentSort: 'default',
      currentSceneCategory: 'all',
      sceneVersion: 0,
      scenesLoading: true, scenesError: '', planSubmitting: false,

      showDetail: false,
      detailImgHeight: 777.6,
      selectedProduct: null,
      actionType: 'rent',
      detailTab: 'desc',
      // 适配机型(技能/配件详情页):来自 GET /api/products/{id}/related
      relatedProducts: [],
      // ===== 租用弹窗（价格/商品/数量/产品类型/服务时间/确认） =====
      showRentModal: false,
      rentForm: { productTypeIndex: 0, daysIndex: 0, qty: 1, startDate: '' },
      showDatePicker: false,
      dateYearList: [],
      dateVal: [0, 0, 0],
      rentTypeOptions: [
        { code: 'humanoid', label: '人形' },
        { code: 'quadruped', label: '四足' },
        { code: 'delivery', label: '配送' },
        { code: 'cleaning', label: '清洁' },
        { code: 'reception', label: '迎宾' },
        { code: 'inspection', label: '巡检' },
        { code: 'arm', label: '机械臂' },
        { code: 'companion', label: '陪伴' },
      ],
      rentPeriodOptions: ['1天', '3天', '7天', '15天', '30天', '60天', '90天', '180天'],
      // ===== 企业部署咨询弹窗 =====
      showEnterpriseModal: false,
      enterpriseForm: {
        mode: 'rent',
        identity: 'enterprise',
        name: '',
        phone: '',
        wechat: '',
        company: '',
        demand: ''
      },
      // ===== 滚动提示（轻微淡入，暗示可滑动查看更多） =====
      scrollHint: true,
      brandHint: true,
      _hintProductTimer: null,
      _hintBrandTimer: null,
      detailImages: [1, 2, 3],
      coupons: ['满1000减50', '满5000减300', '首单立减100', '新用户专享'],
      faqList: [
        { q: '如何租用？', a: '点击“立即租用”按钮，填写租用信息，工作人员将在1个工作日内联系您。' },
        { q: '购买后多久发货？', a: '现货商品下单后48小时内发货，定制商品以合同约定为准。' },
        { q: '是否提供技术支持？', a: '我们提供全程技术支持，包括安装调试、操作培训和售后维护。' },
        { q: '付款方式有哪些？', a: '支持银行转账、支付宝、微信支付及企业对公账户。' }
      ],
      // 分类映射 categoryMap 已迁至 components/jiqi/changjing.vue（见 import）
      currentPage: '/pages/robot/index',
      bottomTabs: [
        { pagePath: '/pages/home/index', text: '首页', iconEmoji: '/static/shouye.png', iconActive: '/static/shouye1.png' },
        { pagePath: '/pages/robot/index', text: '机器', iconEmoji: '/static/jiqi.png', iconActive: '/static/jiqi1.png' },
        { pagePath: '/pages/ai/index', text: 'AI召唤', iconEmoji: '/static/aizh.png' },
        { pagePath: '/pages/order/index', text: '订单', iconEmoji: '/static/dingdan.png', iconActive: '/static/dingdan1.png' },
        { pagePath: '/pages/mine/index', text: '我的', iconEmoji: '/static/wode.png', iconActive: '/static/wode1.png' }
      ],
      searchQ: '',
      fromHome: false,
      // 本次详情是否来自 AI 召唤页(关闭详情时切回 AI 页,而不是停在机器 tab)
      fromAi: false,
      // ===== 自定义方案(机器页多选 + 方案框) =====
      customPlanMode: false,
      selectedPlanItems: [],
      planDays: 1,
      planDayOptions: [1, 3, 7, 30],
      planStartDate: '',
      planEndDate: '',
      planShowStartPicker: false,
      planShowEndPicker: false,
      customPlanList: [],
      showCustomPlanModal: false
    }
  },
  computed: {
    // 「分类」标签已与类别值合体：默认显示「全部分类」，选中后为「人形分类」等
    currentTypeLabel() {
      const map = { humanoid: '人形', quadruped: '四足', delivery: '配送', cleaning: '清洁', reception: '迎宾', inspection: '巡检', arm: '机械臂', companion: '陪伴' }
      if (this.currentType === 'all') return '全部分类'
      return (map[this.currentType] || this.currentType) + '分类'
    },
    // 默认态与「价格↓」都是 ↓（默认态灰色，排序激活后变蓝加粗），「价格↑」为 ↑
    priceSortMark() {
      if (this.currentSort === 'lowest') return '↑'
      return '↓'
    },
    filteredRentList() {
      const list = this.rentCatalog.filter(item => {
        const q = (this.searchQ || '').trim().toLowerCase()
				const matchSearch = !q || String(item.name || '').toLowerCase().includes(q)
				const matchType = this.currentType === 'all' || item.type === this.currentType || item.type === 'all'
        const matchBrand = this.currentBrand === 'all' || item.brand === this.currentBrand || item.brand === 'all'
        return matchType && matchBrand && matchSearch
      })
      return this.sortList(list)
    },
    filteredBuyList() {
      const list = this.buyCatalog.filter(item => {
        const q = (this.searchQ || '').trim().toLowerCase()
				const matchSearch = !q || String(item.name || '').toLowerCase().includes(q)
				const matchType = this.currentType === 'all' || item.type === this.currentType || item.type === 'all'
        const matchBrand = this.currentBrand === 'all' || item.brand === this.currentBrand || item.brand === 'all'
        return matchType && matchBrand && matchSearch
      })
      return this.sortList(list)
    },
    filteredSceneList() {
      // 依赖响应式 sceneVersion，使 loadBackendScenes 合并后端场景后强制重算（sceneProducts 是非响应数组）
      this.sceneVersion
      if (this.currentSceneCategory === 'all') {
        // 权重高的排前面（后端 scenes.sort 决定优先级）
        return [...sceneProducts].sort((a, b) => ((b.sort ?? 0) - (a.sort ?? 0)) || (a.id - b.id))
      }
      return sceneProducts.filter(item => item.category === this.currentSceneCategory)
    },
    currentCategoryLabel() {
      if (this.currentSceneCategory === 'all') return '全部场景'
      return categoryMap[this.currentSceneCategory]?.label || ''
    },
    // 详情轮播：优先使用商品图片，否则回退占位
    detailImageList() {
      const p = this.selectedProduct
      if (p) {
        if (Array.isArray(p.images) && p.images.length) return p.images
        if (p.image) return [p.image]
      }
      return this.detailImages
    },
    // 详情页价格:优先用后端真价(bagistoPrice),否则回退 mock 价
    detailPrice() {
      const p = this.selectedProduct
      const v = (p && p.bagistoPrice != null) ? p.bagistoPrice : (p ? p.price : '0')
      return String(v || '0').replace(/[^\d.]/g, '')
    },
    // 详情页「产品参数」:取后端透出的自定义属性,过滤系统/冗余字段
    detailSpecs() {
      const attrs = this.selectedProduct && this.selectedProduct.bagistoAttributes
      if (!attrs || typeof attrs !== 'object') return []
      const skip = new Set(['sku', 'name', 'url_key', 'description', 'short_description', 'price',
        'special_price', 'weight', 'status', 'new', 'featured', 'visible_individually',
        'guest_checkout', 'manage_stock', 'tax_category_id', 'product_number'])
      return Object.keys(attrs)
        .filter(code => !code.startsWith('meta_') && !skip.has(code) && attrs[code] && attrs[code].value != null)
        .map(code => ({ name: (attrs[code].name || code), value: String(attrs[code].value).trim() }))
        .filter(s => s.value !== '')
    },
    // 适配机型:只展示机型名。不用 mapCatalogProduct —— 那套是为了取图片/价格的,
    // 这里只需要 name,直接映射更简单
    relatedList() {
      return this.relatedProducts.map(p => ({ id: p.id, name: p.name || '商品' }))
    },
    // 详情「服务/售后」区按板块取内容(租赁/购买/配件/技能);未知类型兜底用「购买」
    serviceInfo() {
      return serviceConfigs[this.actionType] || serviceConfigs.buy
    }
  },
  methods: {
    async refreshScenes() {
      this.scenesLoading = true; this.scenesError = '';
      try { await loadBackendScenes({ force: true }) }
      catch (e) { this.scenesError = e.message || '场景加载失败，请重试' }
      finally { this.sceneVersion++; this.scenesLoading = false }
    },
    switchTab(tab) {
      this.currentTab = tab
      this.showTypePicker = false   // 切 Tab 收起类别展开面板
      if (tab === 'rent' || tab === 'buy') {
        this.currentType = 'all'
        this.currentBrand = 'all'
        this.currentSort = 'default'
      } else if (tab === 'scene') {
        this.currentSceneCategory = 'all'
      }
    },
    selectType(type) { this.currentType = type },
    setSort(kind) { this.currentSort = kind },
    togglePriceSort() {
      if (this.currentSort === 'default') this.currentSort = 'highest'
      else if (this.currentSort === 'highest') this.currentSort = 'lowest'
      else this.currentSort = 'default'
    },
    sortList(list) {
      const s = this.currentSort
      if (s === 'lowest') return [...list].sort((a, b) => this.priceNum(a) - this.priceNum(b))
      if (s === 'highest') return [...list].sort((a, b) => this.priceNum(b) - this.priceNum(a))
      return list
    },
    priceNum(item) {
      const v = (item && item.bagistoPrice != null) ? item.bagistoPrice : (item ? item.price : 0)
      const n = Number(String(v == null ? '' : v).replace(/[^\d.]/g, ''))
      return isNaN(n) ? 0 : n
    },
    // 点击「分类」在下方就地展开 / 收起（原为弹窗）
    openTypePicker() { this.showTypePicker = !this.showTypePicker },
    selectBrand(brand) { this.currentBrand = brand },
    selectSceneCategory(category) { this.currentSceneCategory = category },

    // ===== 自定义方案：机器页多选 + 方案框 + 下单/保存 =====
    enterCustomPlanMode() {
      this.customPlanMode = true
      this.currentTab = 'rent'
      this.selectedPlanItems = []
      this.planDays = 1
      this.initPlanDate()
      this.loadCustomPlans()
    },
    initPlanDate() {
      const now = new Date()
      this.dateYearList = [now.getFullYear(), now.getFullYear() + 1, now.getFullYear() + 2, now.getFullYear() + 3, now.getFullYear() + 4, now.getFullYear() + 5]
      this.dateVal = [0, now.getMonth(), now.getDate() - 1]
      this.planStartDate = this.todayStr()
      this.planEndDate = this.todayStr()
      this.planShowStartPicker = false
      this.planShowEndPicker = false
    },
    planDateFromPv(e) {
      const [yi, mi, di] = e.detail.value || [0, 0, 0]
      const y = this.dateYearList[yi] || this.dateYearList[0]
      const m = mi + 1
      const maxDay = new Date(y, m, 0).getDate()
      const d = Math.min(di + 1, maxDay)
      this.dateVal = [yi, mi, d - 1]
      return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    },
    onPlanStartDateChange(e) {
      this.planStartDate = this.planDateFromPv(e)
      this.planShowStartPicker = false
      this.calcPlanDays()
    },
    onPlanEndDateChange(e) {
      this.planEndDate = this.planDateFromPv(e)
      this.planShowEndPicker = false
      this.calcPlanDays()
    },
    calcPlanDays() {
      if (this.planStartDate && this.planEndDate) {
        const s = new Date(this.planStartDate), en = new Date(this.planEndDate)
        const diff = Math.round((en - s) / 86400000)
        if (diff > 0) this.planDays = diff
      }
    },
    exitCustomPlanMode() {
      this.customPlanMode = false
      this.selectedPlanItems = []
      this.showCustomPlanModal = false
    },
    isPlanSelected(item) {
      const key = item.bagistoId || item.id
      return this.selectedPlanItems.some(m => (m.bagistoId || m.id) === key)
    },
    togglePlanItem(item) {
      const key = item.bagistoId || item.id
      const idx = this.selectedPlanItems.findIndex(m => (m.bagistoId || m.id) === key)
      if (idx !== -1) this.selectedPlanItems.splice(idx, 1)
      else this.selectedPlanItems.push({ ...item })
    },
    planPerDay() {
      return this.selectedPlanItems.reduce((sum, m) => sum + (parseFloat(String(m.price).replace(/,/g, '')) || 0), 0)
    },
    planTotal() {
      return Math.round(this.planPerDay() * this.planDays * 100) / 100
    },
    async submitCustomPlanOrder() {
      if (!this.selectedPlanItems.length || this.planSubmitting) return
      this.planSubmitting = true
      try {
        await this.reloadCatalog()
        const current = this.selectedPlanItems.map(item => item.bagistoId && this.rentCatalog.find(p => String(p.bagistoId) === String(item.bagistoId)))
        if (this.catalogError || current.some(item => !item)) {
          uni.showToast({ title: '部分机型已失效，请从当前租用目录重新选择', icon: 'none' })
          return
        }
        this.selectedPlanItems = current
        this.showCustomPlanModal = false
        openSceneRequest(sceneDraftPayload({ source: 'robot', text: '意向机型：' + current.map(m => m.name).join('、') + '；意向服务 ' + this.planDays + ' 天。具体场地、任务和档期待评估。', contact: { name: '', phone: '' } }))
      } catch (e) {
        uni.showToast({ title: e.message || '目录刷新失败，请重试', icon: 'none' })
      } finally { this.planSubmitting = false }
    },
    saveCustomPlan() {
      if (!this.selectedPlanItems.length) { uni.showToast({ title: '请先选择机型', icon: 'none' }); return }
      const list = uni.getStorageSync('customPlans') || []
      list.unshift({
        id: Date.now(),
        name: '自定义方案',
        items: this.selectedPlanItems.map(m => ({ ...m })),
        days: this.planDays,
        perDay: this.planPerDay(),
        total: this.planTotal(),
        status: 'draft',
        time: new Date().toLocaleString('zh-CN', { hour12: false }),
        synced: false,
      })
      uni.setStorageSync('customPlans', list)
      this.customPlanList = list
      uni.showToast({ title: '草稿已保存到本机', icon: 'success' })
    },
    loadCustomPlans() {
      this.customPlanList = uni.getStorageSync('customPlans') || []
    },
    openCustomPlans() {
      this.loadCustomPlans()
      this.showCustomPlanModal = true
    },
    loadPlanFromList(p) {
      this.selectedPlanItems = (p.items || []).map(m => ({ ...m }))
      this.planDays = p.days || 1
      this.showCustomPlanModal = false
      uni.showToast({ title: `已载入「${p.name}」`, icon: 'none' })
    },
    deleteCustomPlan(p) {
      uni.showModal({
        title: '删除方案', content: `删除「${p.name}」？`,
        success: (res) => {
          if (!res.confirm) return
          const list = this.customPlanList.filter(x => x.id !== p.id)
          uni.setStorageSync('customPlans', list)
          this.customPlanList = list
        }
      })
    },

    goDetail(item) {
      this.selectedProduct = item
      this.actionType = this.currentTab
      this.detailTab = 'desc'
      this.showDetail = true
      this.loadRelatedProducts()
    },

    // 技能/配件的「适配机型」:读后台关联商品关系(GET /api/products/{id}/related)。
    // 机器人商品(rent/buy)不走这里 —— 与后台 links.blade.php 切换标题的条件保持一致。
    // request.js 失败一律 reject 且不返回 task(没法 abort),所以用 _relatedSeq 比对来丢弃过期响应。
    async loadRelatedProducts() {
      const seq = ++_relatedSeq
      this.relatedProducts = []
      const item = this.selectedProduct
      if (this.actionType !== 'accessory' && this.actionType !== 'skill') return
      if (!item || !item.bagistoId) return
      try {
        const res = await productApi.getRelatedProducts(item.bagistoId)
        if (seq !== _relatedSeq) return // 在途期间详情已被换成别的商品,丢弃
        this.relatedProducts = (res && Array.isArray(res.data)) ? res.data : []
      } catch (e) {
        if (seq !== _relatedSeq) return
        // 静默降级:拿不到就不显示这一块,不打扰用户(同 fangan.vue 的接口失败处理)
        this.relatedProducts = []
      }
    },

    // AI 召唤页点推荐商品 → 先写 storage 再跳本页;这里读出来自动打开详情。
    // 只传了 id(不传分类),所以四个目录都找一遍——同一商品可能归在不同分类下。
    async openPendingAiProduct() {
      let req = null
      try {
        req = uni.getStorageSync('aiOpenProduct')
      } catch (e) { return }
      if (!req || !req.id) return
      uni.removeStorageSync('aiOpenProduct')

      // 目录是异步拉的,等它落地再找
      try { await this.loadCatalog() } catch (e) { return }

      const buckets = [
        ['rent', this.rentCatalog, 'detail'],
        ['buy', this.buyCatalog, 'detail'],
        ['skill', this.skillCatalog, 'skill'],
        ['accessory', this.accessoryCatalog, 'accessory'],
      ]
      for (const [tab, list, kind] of buckets) {
        const hit = (list || []).find(x => Number(x.bagistoId || x.id) === Number(req.id))
        if (!hit) continue
        this.currentTab = tab
        this.fromAi = true   // 关闭详情时切回 AI 召唤页
        if (kind === 'detail') this.goDetail(hit)
        else if (kind === 'skill') this.onSkill(hit, false)
        else this.onAccessory(hit, false)
        return
      }
    },
    // 详情顶部图：按图片实际比例自适应框架高度，整图完整显示（不裁切、不留白）
    // 详情顶部图：框架高度直接取图片实际渲染高度，保证=图片 —— 无留白、无裁切
    onDetailImgLoad(e) {
      // 1) 先用宽高比算个初值兜底（防止加载瞬间框架高度不对/闪一下）
      const { width, height } = (e && e.detail) || {}
      if (width && height) this.detailImgHeight = Math.round(750 * height / width)
      // 2) 再用图片真实渲染高度校准（这是最终准值：框架=图片）
      this.$nextTick(() => {
        setTimeout(() => {
          uni.createSelectorQuery().in(this)
            .select('.detail-swiper .swiper-img')
            .boundingClientRect(rect => {
              if (rect && rect.height) {
                const winW = uni.getSystemInfoSync().windowWidth || 375
                this.detailImgHeight = Math.round(rect.height * 750 / winW)
              }
            })
            .exec()
        }, 80)
      })
    },

    onAccessory(item, fromHome = false) {
      const accessoryDetail = {
        ...item,
        scene: accessoryCategoryMap[item.category] || item.category || '配件',
        bg: '#F0F4FA',
      }
      this.selectedProduct = accessoryDetail
      this.actionType = 'accessory'
      this.detailTab = 'desc'
      this.showDetail = true
      this.fromHome = fromHome
      this.loadRelatedProducts()
    },

    onSkill(item) {
      const url = skillDetailUrl(item)
      if (url) uni.navigateTo({ url })
    },

    closeDetail() {
      this.showDetail = false
      this.selectedProduct = null
      if (this.fromHome) {
        this.fromHome = false
        uni.switchTab({ url: '/pages/home/index' })
      }
      // 从 AI 召唤页点推荐商品跳过来的:关掉详情就回到 AI 召唤页,
      // 而不是把用户丢在"机器"tab 上(与上面 fromHome 的处理保持一致)。
      if (this.fromAi) {
        this.fromAi = false
        uni.switchTab({ url: '/pages/ai/index' })
      }
      // 清掉适配机型,并作废在途请求(关了浮层还可能有一个 related 请求在路上)
      this.relatedProducts = []
      _relatedSeq++
    },

    confirmAction(type) {
      if (!this.selectedProduct) return
      const product = this.selectedProduct
      if (type === 'buy') {
        this.closeDetail()
        this.buyNow(product)
        return
      }
      if (type === 'rent') {
        this.openRentModal()
        return
      }
      if (type === 'accessory') {
        // 配件购买：进入确认订单（复用商品购买逻辑），不触发「来自首页」的自动返回
        this.showDetail = false
        this.selectedProduct = null
        this.fromHome = false
        this.goCheckout(product, 'accessory')
        return
      }
      if (type === 'skill') {
        this.showDetail = false
        this.onSkill(product)
        return
      }
    },

    // 立即购买 → 进入结算页（完整购买下单路径）
    buyNow(product) {
      this.goCheckout(product, 'buy')
    },

    // 立即租用 → 先弹窗选择产品类型 + 租期，再进结算页
    rentNow(product, opts) {
      this.goCheckout(product, 'rent', opts)
    },

    // 组装结算页参数并跳转
    goCheckout(product, type, opts) {
      opts = opts || {}
      const scene = product.scene || ''
      const qty = opts.qty || product.qty || 1
      // 有真实 Bagisto id(已对齐商品)则用真 id 走真下单;否则回退 mock id(意向单)
      const productId = product.bagistoId || product.id
      // 有真实价则用真价显示;否则回退 mock 价
      const priceStr = product.bagistoPrice != null ? product.bagistoPrice : (product.price || '0')
      // 真实图片(卡片图/详情轮播第一张)传给确认页
      const img = product.image || (Array.isArray(product.images) && product.images[0]) || ''
      let url =
        `/pages/order/checkout?productId=${productId}` +
        `&name=${encodeURIComponent(product.name)}` +
        `&price=${encodeURIComponent(priceStr)}` +
        `&icon=${encodeURIComponent(product.icon || '🤖')}` +
        `&bg=${encodeURIComponent(product.bg || '#F0F4FA')}` +
        `&sku=${encodeURIComponent(product.sku || '')}` +
        `&scene=${encodeURIComponent(scene)}&type=${type}&qty=${qty}`
      if (img) url += `&image=${encodeURIComponent(img)}`
      if (opts.productType) url += `&productType=${encodeURIComponent(opts.productType)}`
      if (opts.rentPeriod) url += `&rentPeriod=${encodeURIComponent(opts.rentPeriod)}`
      if (opts.startDate) url += `&startDate=${encodeURIComponent(opts.startDate)}`
      uni.navigateTo({ url })
    },

    // ===== 租用弹窗（价格/数量/类型/服务时间） =====
    openRentModal() {
      if (!this.selectedProduct) return
      const code = this.selectedProduct.type || 'humanoid'
      const idx = this.rentTypeOptions.findIndex(o => o.code === code)
      this.rentForm.productTypeIndex = idx >= 0 ? idx : 0
      this.rentForm.daysIndex = 0 // 默认 1 天起租
      this.rentForm.qty = 1
      const now = new Date()
      const y = now.getFullYear()
      this.dateYearList = [y, y + 1, y + 2, y + 3, y + 4, y + 5]
      this.dateVal = [0, now.getMonth(), now.getDate() - 1]
      this.rentForm.startDate = this.todayStr()
      this.showDatePicker = false
      this.showRentModal = true
    },
    todayStr() {
      const d = new Date()
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    },
    addDays(dateStr, days) {
      if (!dateStr) return ''
      const d = new Date(dateStr)
      d.setDate(d.getDate() + (days || 0))
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    },
    rentDays() {
      return parseInt(this.rentPeriodOptions[this.rentForm.daysIndex]) || 1
    },
    rentTotal() {
      const p = Number(String(this.selectedProduct && this.selectedProduct.price || '0').replace(/,/g, '')) || 0
      return (p * this.rentForm.qty * this.rentDays()).toLocaleString('en-US')
    },
    onRentQtyChange(delta) {
      const v = this.rentForm.qty + delta
      this.rentForm.qty = v >= 1 && v <= 99 ? v : this.rentForm.qty
    },
    // ===== 日期三列选择（picker-view） =====
    monthColumn() { return [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] },
    dayColumn() {
      const y = this.dateYearList[this.dateVal[0]] || this.dateYearList[0]
      const m = (this.dateVal[1] || 0) + 1
      const n = new Date(y, m, 0).getDate()
      const arr = []
      for (let i = 1; i <= n; i++) arr.push(i)
      return arr
    },
    onDatePvChange(e) {
      const [yi, mi, di] = e.detail.value || [0, 0, 0]
      const y = this.dateYearList[yi] || this.dateYearList[0]
      const m = mi + 1
      const maxDay = new Date(y, m, 0).getDate()
      const d = Math.min(di + 1, maxDay)
      this.dateVal = [yi, mi, d - 1]
      this.rentForm.startDate = `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`
    },
    cancelRentModal() { this.showRentModal = false },
    confirmRent() {
      const product = this.selectedProduct
      const opts = {
        productType: this.rentTypeOptions[this.rentForm.productTypeIndex].code,
        rentPeriod: this.rentPeriodOptions[this.rentForm.daysIndex],
        qty: this.rentForm.qty,
        startDate: this.rentForm.startDate,
      }
      this.showRentModal = false
      this.closeDetail()
      this.rentNow(product, opts)
    },

    callService() { uni.showToast({ title: '正在连接客服...', icon: 'none' }) },

    // 加入购物车（对接购物车 API）
    addCart() {
      if (!this.selectedProduct) return
      const product = this.selectedProduct
      uni.showLoading({ title: '加入中...', mask: true })
      cartApi.addToCart({ product_id: product.bagistoId || product.id, quantity: 1 })
        .then(() => {
          uni.hideLoading()
          uni.showToast({ title: '已加入购物车', icon: 'success' })
        })
        .catch((err) => {
          uni.hideLoading()
          uni.showToast({ title: (err && err.message) || '加入购物车失败', icon: 'none' })
        })
    },
    goSceneDetail(scene) {
      // 带上 category,供场景详情页判断是否为 TOB(企业商用)场景去拉后端真实图+描述
      uni.navigateTo({ url: `/pages/robot/fangan?sceneId=${scene.id}&category=${scene.category}` })
    },
    goEnterprise() {
      this.enterpriseForm = {
        mode: this.currentTab === 'rent' ? 'rent' : 'buy',
        identity: 'enterprise',
        name: '',
        phone: '',
        wechat: '',
        company: '',
        demand: ''
      }
      this.showEnterpriseModal = true
    },
    selectEnterpriseMode(mode) {
      this.enterpriseForm.mode = mode
    },
    selectEnterpriseIdentity(type) {
      this.enterpriseForm.identity = type
    },
    closeEnterpriseModal() {
      this.showEnterpriseModal = false
    },
    async submitEnterprise() {
      const { name, phone, company, wechat, demand, identity } = this.enterpriseForm
      if (!name.trim() || !/^\+?[0-9 -]{7,25}$/.test(phone)) { uni.showToast({ title: '请填写联系人及有效电话', icon: 'none' }); return }
      if (identity === 'enterprise' && !company.trim()) { uni.showToast({ title: '请输入公司名称', icon: 'none' }); return }
      openSceneRequest(sceneDraftPayload({ source: 'robot', text: demand, contact: { name: name.trim(), phone, company, wechat } }))
      this.closeEnterpriseModal()
    },
    switchBottomTab(url) {
      if (this.currentPage === url) return
      uni.switchTab({ url })
    },
    isImagePath(path) {
      return typeof path === 'string' && (path.startsWith('/') || path.includes('.'))
    },
    getIcon(tab) {
      const isActive = this.currentPage === tab.pagePath
      if (isActive && tab.iconActive) {
        return tab.iconActive
      }
      return tab.iconEmoji
    },
    // ===== 滚动提示控制（轻微淡入，持续 2 秒后淡出） =====
    hintShow(type) {
      const isBrand = type === 'brand'
      const key = isBrand ? 'brandHint' : 'scrollHint'
      const timerKey = isBrand ? '_hintBrandTimer' : '_hintProductTimer'
      this[key] = true
      clearTimeout(this[timerKey])
      this[timerKey] = setTimeout(() => { this[key] = false }, 2000)
    },
    onPanelScroll(type) {
      this.hintShow(type)
    },


    // 场景对接 loadBackendScenes 已迁至 components/jiqi/changjing.vue（见 import）

    // ===== 最小闭环:租用/购买 动态从后台商品库拉取(后台建品即在前端显示) =====
    // 带 5 分钟模块级缓存;后台改数据最多 5 分钟生效,急用重启前端/刷新页面即清
    async loadCatalog() {
      if (_catalogRawCache && Date.now() - _catalogCachedAt < CATALOG_TTL) {
        this.applyCatalog(_catalogRawCache)
        return
      }
      this.catalogLoading = true
      this.catalogError = ''
      // 优先聚合端点:1 个请求拿全部(后端未部署该接口时自动回退旧多请求逻辑)
      let aggFailed = false
      try {
        const aggRes = await catalogApi.getAggregate()
        const d = aggRes && aggRes.data
        if (d && Array.isArray(d.rent) && Array.isArray(d.buy)) {
          _catalogRawCache = { rent: d.rent, buy: d.buy, accessory: d.accessory, skill: d.skill }
          _catalogCachedAt = Date.now()
          this.applyCatalog(_catalogRawCache)
          this.catalogLoading = false
          return
        }
      } catch (e) { aggFailed = true /* 聚合端点不可用 → 回退旧多请求逻辑 */ }
      // 按分类区分:rent=租用,buy=购买,accessory=配件,skill=技能(找不到则对应为空)
      const catIds = { rent: null, buy: null, accessory: null, skill: null }
      let catFailed = false
      try {
        const catRes = await categoryApi.getCategories({ limit: 200 })
        const cats = (catRes && Array.isArray(catRes.data)) ? catRes.data : []
        const pick = (slug, name) => { const c = cats.find(x => x.slug === slug || x.name === name); return c ? c.id : null }
        catIds.rent = pick('rent', '租用')
        catIds.buy = pick('buy', '购买')
        catIds.accessory = pick('accessory', '配件')
        catIds.skill = pick('skill', '技能')
      } catch (e) { catFailed = true /* 分类拉取失败则全空 */ }

      const f = async (id) => id ? this.fetchProductsByCat(id) : Promise.resolve([])
      const [rentItems, buyItems, accItems, sklItems] = await Promise.all([
        f(catIds.rent), f(catIds.buy), f(catIds.accessory), f(catIds.skill),
      ])
      _catalogRawCache = { rent: rentItems, buy: buyItems, accessory: accItems, skill: sklItems }
      _catalogCachedAt = Date.now()
      this.applyCatalog(_catalogRawCache)
      this.catalogLoading = false
      // 两条来源都失败 = 服务不可用 —— 置错误态,不让空目录伪装成「没有找到匹配的机器人」
      if (aggFailed && catFailed) {
        this.catalogError = '目录加载失败'
      }
    },
    // 错误态「重试」:先作废 5 分钟缓存,再重拉 —— 否则 TTL 内会直接命中失败前的空缓存
    reloadCatalog() {
      _catalogRawCache = null
      _catalogCachedAt = 0
      return this.loadCatalog()
    },
    // 原始商品数组 → 四个目录(纯展示映射,缓存命中与首拉共用一条路径)
    applyCatalog(raw) {
      this.rentCatalog = (raw.rent || []).map(p => this.mapCatalogProduct(p))
      this.buyCatalog = (raw.buy || []).map(p => this.mapCatalogProduct(p))
      this.accessoryCatalog = (raw.accessory || []).map(p => ({ ...this.mapCatalogProduct(p), category: '配件' }))
      this.skillCatalog = (raw.skill || []).map(p => ({ ...this.mapCatalogProduct(p), tag: '技能' }))
    },
    async fetchProductsByCat(categoryId) {
      try {
        const res = await productApi.getProducts({ limit: 200, page: 1, category_id: categoryId })
        return (res && Array.isArray(res.data)) ? res.data : []
      } catch (e) {
        return []
      }
    },
    mapCatalogProduct(p) {
      const regular = (p.prices && p.prices.regular && p.prices.regular.price) || ''
      const final = (p.prices && p.prices.final && p.prices.final.price) || ''
      const minPrice = (typeof p.min_price === 'number' ? p.min_price : '0')
      const effVal = final || regular || minPrice
      const rawEff = parseFloat(String(effVal).replace(/[^\d.]/g, ''))
      const rawReg = parseFloat(String(regular).replace(/[^\d.]/g, ''))
      const num = isNaN(rawEff) ? '0' : String(Math.round(rawEff * 100) / 100) // 去尾随零: 500.0000→500, 12.50→12.5
      const originalPrice = rawEff > 0 ? String(Math.round(((!isNaN(rawReg) && rawReg > rawEff) ? rawReg : rawEff * 1.2) * 100) / 100) : ''
      const img = (p.base_image && (p.base_image.medium_image_url || p.base_image.large_image_url || p.base_image.small_image_url)) || ''
      return {
        id: p.id,
        name: p.name || '商品',
        price: num,
        originalPrice,
        icon: '🤖',
        bg: '#F0F4FA',
        scene: '',
        sku: p.sku || '',
        image: img,
        bagistoId: p.id,
        bagistoPrice: num,
        bagistoDescription: fixDescUrls(p.description || ''),
        // v1:品牌/类别按商品名推断(见 detectBrandKey/detectTypeKey);后续迁移为后台商品属性驱动
        brand: detectBrandKey(p.name),
        type: detectTypeKey(p.name),
      }
    }
  },

  onShow() {
    this.$nextTick(() => { if (this.currentTab === 'skill' && this.$refs.skillMarket) this.$refs.skillMarket.refresh() })
    const tab = uni.getStorageSync('robotTab')
    if (tab && ['rent', 'scene', 'buy', 'skill', 'accessory'].includes(tab)) {
      this.currentTab = tab
      uni.removeStorageSync('robotTab')
    }

    const accessoryData = uni.getStorageSync('accessoryDetail')
    if (accessoryData) {
      uni.removeStorageSync('accessoryDetail')
      this.currentTab = 'accessory'
      const fromHome = accessoryData.fromHome || false
      this.onAccessory(accessoryData, fromHome)
    }

    const skillData = uni.getStorageSync('skillDetail')
    if (skillData) {
      uni.removeStorageSync('skillDetail')
      this.currentTab = 'skill'
      this.onSkill(skillData, skillData.fromHome || false)
    }

    // 租用/购买 动态从后台商品库拉取(内部自带 5 分钟缓存,onShow 高频触发不再全量重拉)
    this.loadCatalog()

    // AI 召唤页点推荐商品跳过来的:等目录加载完再自动打开该商品详情
    this.openPendingAiProduct()

    // 拉取后台维护的场景列表,按 id 合并进 enterprise(TOB)卡片; 完成后刷新场景列表
    this.refreshScenes()

    // 进入页面先展示一次滚动提示（2 秒后淡出）
    this.hintShow('product')
    this.hintShow('brand')
  }
}
</script>

<style scoped>
/* ===== 搜索框(租/购) ===== */
.search-bar { padding: 12rpx 20rpx 4rpx; }
.search-input { width: 100%; height: 68rpx; background: #F1F5F9; border-radius: 34rpx; padding: 0 28rpx; font-size: 26rpx; box-sizing: border-box; }

/* ===== 基础布局 ===== */
page {
  height: 100%;
  overflow: hidden;
}
.rental-page {
  background-color: #ffffff;
  height: 100vh;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
  padding-bottom: 160rpx;
  box-sizing: border-box;
}
.rental-page::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 88rpx;
  background: linear-gradient(to bottom, #4291F6, #B3D5FF);
  z-index: 0;
  pointer-events: none;
}
/* ★ 已还原本意：让 .rental-page 直接子元素浮在顶部渐变(::before)之上。
   新版 WXSS 不支持通配符 * 作子代选择器(报 error at token "*")；改显式类名是唯一
   既不报错、又不改布局的写法。注意：勿用 `> view`(元素选择器会把 specificity 抬到 0,2,1，
   盖过弹层自身的 position:fixed → 布局崩)。
   这里只列真正需要抬升的常驻流量元素：top-tabs / custom-plan-header。
   其余直接子元素均为 position:fixed 全屏弹层(自带 z-index 999~1250，天然在渐变之上)、
   或 .main-body(其规则已自带 position:relative + z-index:1)。 */
.rental-page > .top-tabs,
.rental-page > .custom-plan-header {
  position: relative;
  z-index: 1;
}

/* ============================================================ */
/* ===== 顶部 Tab（五个主Tab） - 蓝色框架（下划线） ===== */
.top-tabs {
  display: flex;
  background: #EEF4FF;
  padding: 140rpx 20rpx 0;  /* 左右各留 20rpx：首格左边、末格右边不贴屏幕边，正方形才完整 */
  flex-shrink: 0;
  /* 刻意不写 border-bottom：选中方块要与下方白色内容区连成一体，有线就割开了 */
}
.top-tabs .tab-item {
  flex: 1;                 /* (750-20×2)/5 = 142rpx 宽 */
  height: 142rpx;          /* 与格宽 142rpx 相等 → 正方形 */
  line-height: 142rpx;     /* 垂直居中；不用 flex，<text> 上更稳 */
  text-align: center;
  font-size: 33rpx;
  font-weight: 600;
  color: #1F2937;
  letter-spacing: 0.5rpx;
  transition: background 0.25s ease;
}
/* 激活态：整格铺白（原为蓝字 + 蓝下划线）。底边无线，视觉上与下方内容区连成一块 */
.top-tabs .tab-item.active {
  background: #FFFFFF;
}

/* ============================================================ */
/* ===== 分类滚动条（全部类别、人形等）- 淡蓝色 ===== */
.type-scroll {
  white-space: nowrap;
  padding: 10rpx 16rpx;
  background: transparent;
  flex-shrink: 0;
  height: 60rpx;
  z-index: 1;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.type-scroll::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
.type-item {
  display: inline-block;
  padding: 2rpx 18rpx;
  margin-right: 8rpx;
  font-size: 26rpx;
  color: #6B7280;
  background: #ffffff;
  border-radius: 24rpx;
  line-height: 1.8;
  border: 1rpx solid #E5E7EB;
  transition: all 0.2s ease;
}
/* 分类选择：label + 下拉框（对标技能/配件的品牌机型格式） */
.cat-group {
  display: flex;
  align-items: center;
  gap: 8rpx;
  flex-shrink: 0;
}
.cat-label {
  font-size: 26rpx;
  color: #6B7280;
  white-space: nowrap;
}
.cat-picker {
  display: flex;
  align-items: center;
  /* 去掉方框：无背景/描边/圆角，与左侧「综合/价格」同款纯文字 */
  padding: 6rpx 0;
  font-size: 26rpx;
  color: #6B7280;   /* 与「分类」标签同色深，不再用更深的 #1F2937 */
}
.cat-arrow {
  font-size: 18rpx;
  color: #9CA3AF;
  margin-left: 8rpx;
}
/* 激活态：淡蓝色背景 + 蓝色文字 + 蓝色边框 */
.type-item.active {
  background: #E8F0FE;        /* 淡蓝色背景 */
  color: #165DFF;             /* 蓝色文字 */
  border-color: #B3D5FF;      /* 淡蓝色边框 */
  font-weight: 500;
}
.type-item:active {
  opacity: 0.7;
}
/* 电商排序：综合 / 价格 */
.sort-group {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.sort-item {
  padding: 4rpx 14rpx;
  font-size: 26rpx;
  color: #6B7280;
  line-height: 1.6;
  flex-shrink: 0;
}
.sort-item.active {
  color: #165DFF;
  font-weight: 600;
}
.sort-arrow {
  font-size: 20rpx;
  margin-left: 4rpx;
}

/* ===== 主体 ===== */
.main-body {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  z-index: 1;
  position: relative;
  margin-top: 38rpx;
}

/* ===== 滚动提示条（轻微淡入，暗示可滑动查看更多） ===== */
.scroll-hint {
  position: absolute;
  top: 10rpx;
  bottom: 10rpx;
  width: 3rpx;
  border-radius: 3rpx;
  opacity: 0;
  transition: opacity 0.5s ease;
  pointer-events: none;
  z-index: 5;
}
.scroll-hint.brand-hint {
  left: 147rpx;
  background: linear-gradient(180deg, rgba(66, 145, 246, 0.0), rgba(66, 145, 246, 0.4), rgba(66, 145, 246, 0.0));
}
.scroll-hint.product-hint {
  right: 3rpx;
  background: linear-gradient(180deg, rgba(66, 145, 246, 0.0), rgba(66, 145, 246, 0.32), rgba(66, 145, 246, 0.0));
}
.scroll-hint.is-visible {
  opacity: 0.85;
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
  font-size: 25.1rpx;
  color: #1F2937;
  text-align: center;
  font-weight: 500;
}
.brand-item.active .brand-name {
  color: #165DFF;
  font-weight: 700;
}

/* 右侧内容列：顶部放「类别栏」，下为广告/卡片 */
.content-column {
  flex: 1;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
  z-index: 1;
  background: #ffffff;
}
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
  transform: translateZ(0);
  will-change: scroll-position;
}
.product-flex-wrapper {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
}

/* ===== 租用/购买 卡片 ===== */
.product-card {
  width: 48.5%;
  margin-bottom: 16rpx;
  background: #ffffff;
  border-radius: 16rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: auto;
  box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.06);
}
.product-image {
  width: 100%;
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.product-img {
  width: 100%;
  height: 100%;
}
.product-icon {
  font-size: 64rpx;
}
.product-info {
  flex: 1;
  padding: 8rpx 10rpx 10rpx;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.product-name {
  font-size: 26rpx;
  font-weight: 600;
  color: #4B5563;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.scene-tag {
  font-size: 16rpx;
  color: #6B7280;
  background: #EEF2F6;
  padding: 2rpx 10rpx;
  border-radius: 10rpx;
  display: inline-block;
  align-self: flex-start;
}
.product-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.product-price-wrap {
  display: flex;
  align-items: baseline;
  gap: 8rpx;
  flex-wrap: wrap;
}
.product-price {
  font-size: 36rpx;
  font-weight: 700;
  color: #FF7820;
}
.product-price .price-badge {
  font-size: 22rpx;
  font-weight: 500;
  color: #FF7820;
  margin-right: 2rpx;
}
.product-price .price-cur {
  font-size: 22rpx;
  font-weight: 500;
  color: #FF7820;
}
.product-original {
  font-size: 20rpx;
  color: #C0C8D4;
  text-decoration: line-through;
  font-weight: 400;
}
.btn-action {
  background: #6EA8FF;
  border-radius: 20rpx;
  padding: 4rpx 16rpx;
  font-size: 20rpx;
  font-weight: 600;
  color: #fff;
  text-align: center;
  line-height: 1.8;
}
.btn-action:active {
  opacity: 0.7;
}

/* ===== 企业部署横幅 ===== */
.enterprise-banner {
  width: 90%;
  margin: 0 auto;
  background: linear-gradient(to left, #BBD8FF, #F5F9FF);
  border-radius: 16rpx;
  padding: 10rpx 16rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 2rpx solid #165DFF;
  box-shadow: 0 2rpx 12rpx rgba(22, 93, 255, 0.18);
  margin-bottom: 16rpx;
  flex-shrink: 0;
  height: 76rpx;
}
.banner-left {
  display: flex;
  align-items: center;
  gap: 6rpx;
}
.banner-icon {
  font-size: 32rpx;
}
.banner-title {
  font-size: 22rpx;
  font-weight: 700;
  color: #4B5563;
}
.banner-right {
  display: flex;
  align-items: center;
  gap: 8rpx;
}
.banner-phone {
  font-size: 18rpx;
  color: #6B7280;
}
.banner-btn {
  background: #4291F6;
  color: #fff;
  padding: 4rpx 16rpx;
  border-radius: 30rpx;
  font-size: 20rpx;
  font-weight: 600;
  text-align: center;
  line-height: 1.8;
}
.banner-btn:active {
  opacity: 0.8;
}

/* ===== 场景列表 ===== */
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
  overflow: hidden;
}
.scene-list-img {
  width: 100%;
  height: 100%;
  display: block;
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
  font-size: 30rpx;
  font-weight: 700;
  color: #4B5563;
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
  color: #6B7280;
  background: #EEF2F6;
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

/* ========== 全屏详情页 ========== */
.detail-full-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: fadeIn 0.3s ease;
}
.detail-full {
  width: 100%;
  height: 100%;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  animation: slideIn 0.3s ease;
  position: relative;
}
.detail-nav {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  z-index: 15;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: calc(env(safe-area-inset-top) + 86rpx);
  background: #F2F4F7;
  box-shadow: 0 2rpx 10rpx rgba(0, 0, 0, 0.06);
}
.detail-back {
  position: absolute;
  top: calc(env(safe-area-inset-top) + 100rpx);
  left: 24rpx;
  right: auto;
  z-index: 30;
  width: 72rpx;
  height: 72rpx;
  line-height: 72rpx;
  text-align: center;
  border-radius: 20rpx;
  background: #EEF2F6;
  color: #4B5563;
  font-size: 48rpx;
  font-weight: 400;
}
.detail-nav-title {
  font-size: 32rpx;
  font-weight: 600;
  color: #1F2937;
}
.detail-nav-placeholder {
  width: 60rpx;
}
.detail-scroll {
  flex: 1;
  min-height: 0;
  /* 顶部灰条(.detail-nav)高 safe+86rpx; 这里给 84rpx 让图片上移 2rpx 塞到灰条正下方,
     消除两者间因 rpx 取整露出的白线(灰条不透明且 z-index 更高, 压住接缝) */
  padding-top: calc(env(safe-area-inset-top) + 84rpx);
  padding-bottom: 20rpx;
}
.detail-swiper {
  width: 100%;
}
.swiper-slide {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.swiper-img {
  width: 100%;
  height: 103%;
}
.swiper-icon {
  font-size: 160rpx;
}
.detail-info-area {
  padding: 24rpx 30rpx;
}
.detail-name {
  font-size: 40rpx;
  font-weight: 700;
  color: #4B5563;
  margin-top: 14rpx;
  display: block;
}
.detail-price-row {
  margin-top: 16rpx;
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 12rpx;
}
.detail-price {
  font-size: 56rpx;
  font-weight: 700;
  color: #FF7820;
}
.detail-price .detail-badge {
  font-size: 26rpx;
  font-weight: 500;
  color: #FF7820;
  margin-right: 4rpx;
}
.detail-price .detail-cur {
  font-size: 26rpx;
  font-weight: 500;
  color: #FF7820;
}
.detail-day {
  font-size: 22rpx;
  font-weight: 500;
  color: #1F2937;
}
.detail-unit {
  font-size: 28rpx;
  color: #9CA3AF;
  margin-left: 4rpx;
}
.detail-original {
  font-size: 22rpx;
  color: #9CA3AF;
  text-decoration: line-through;
}
.link-tag {
  display: inline-block;
  font-size: 20rpx;
  margin-top: 12rpx;
  padding: 4rpx 16rpx;
  border-radius: 30rpx;
  line-height: 1.6;
}
.link-tag.ok {
  color: #059669;
  background: rgba(5, 150, 105, 0.1);
}
.link-tag.warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.12);
}
/* 适配机型(技能/配件详情页):只列机型名。视觉与 .spec-card / .service-area 同一套语言 */
.adapt-card {
  margin: 0 30rpx 20rpx;
  background: #F8FAFC;
  border-radius: 16rpx;
  padding: 20rpx 24rpx;
}
.adapt-title {
  display: block;
  font-size: 26rpx;
  font-weight: 600;
  color: #4A5568;
  margin-bottom: 16rpx;
}
/* 名字标签自动换行。用 margin 而非 gap:小程序对 flex-wrap + gap 的支持不稳 */
.adapt-list {
  display: flex;
  flex-wrap: wrap;
  margin-bottom: -12rpx;
}
.adapt-name {
  font-size: 24rpx;
  color: #1A2A3A;
  background: #FFFFFF;
  border: 1rpx solid #E5E7EB;
  border-radius: 10rpx;
  padding: 8rpx 18rpx;
  margin: 0 12rpx 12rpx 0;
}
.spec-card {
  margin: 0 30rpx 20rpx;
  background: #F8FAFC;
  border-radius: 16rpx;
  padding: 8rpx 24rpx;
}
.spec-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14rpx 0;
  border-bottom: 1rpx solid #EFF3F8;
}
.spec-row:last-child { border-bottom: none; }
.spec-label {
  font-size: 24rpx;
  color: #8A9AA8;
  flex-shrink: 0;
  margin-right: 20rpx;
}
.spec-value {
  font-size: 24rpx;
  color: #1A2A3A;
  font-weight: 500;
  text-align: right;
  flex: 1;
  min-width: 0;
  word-break: break-all;
}
.coupon-area {
  padding: 0 30rpx 20rpx;
  display: flex;
  align-items: center;
  gap: 20rpx;
}
.coupon-label {
  font-size: 28rpx;
  color: #1F2937;
  font-weight: 500;
  flex-shrink: 0;
}
.coupon-scroll {
  white-space: nowrap;
  flex: 1;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.coupon-scroll::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
.coupon-item {
  display: inline-block;
  background: linear-gradient(135deg, #FFF3E0, #FFE0B2);
  border: 1rpx solid #FFB74D;
  border-radius: 8rpx;
  padding: 6rpx 20rpx;
  font-size: 22rpx;
  color: #E65100;
  margin-right: 16rpx;
}
.service-area {
  padding: 0 30rpx 20rpx;
  background: #F8FAFC;
  border-radius: 16rpx;
  margin: 0 30rpx 20rpx;
  padding: 16rpx 20rpx;
}
.service-title {
  display: block;
  font-size: 26rpx;
  font-weight: 600;
  color: #4A5568;
  margin-bottom: 6rpx;
}
.service-item {
  display: flex;
  justify-content: space-between;
  padding: 12rpx 0;
  border-bottom: 2rpx solid #EEF2F6;
}
.service-item:last-child {
  border-bottom: none;
}
.service-label {
  font-size: 26rpx;
  color: #6B7280;
}
.service-value {
  font-size: 26rpx;
  color: #1F2937;
  font-weight: 500;
}
.detail-tabs {
  display: flex;
  background: #ffffff;
  border-bottom: 2rpx solid #F1F5F9;
  margin: 0 30rpx;
}
.detail-tab {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  font-size: 28rpx;
  color: #6B7280;
  position: relative;
}
.detail-tab.active {
  color: #1F2937;
  font-weight: 600;
}
.tab-content {
  padding: 20rpx 30rpx;
}
.rich-text {
  background: #F8FAFC;
  border-radius: 16rpx;
  padding: 24rpx;
}
.rich-title {
  font-size: 30rpx;
  font-weight: 600;
  color: #1F2937;
  display: block;
  margin-bottom: 16rpx;
}
.rich-text-content {
  font-size: 26rpx;
  color: #4B5563;
  line-height: 1.8;
  white-space: pre-line;
}
.rich-html {
  font-size: 26rpx;
  color: #4B5563;
  line-height: 1.8;
}
.rich-text-empty {
  font-size: 26rpx;
  color: #9CA3AF;
  text-align: center;
  display: block;
  padding: 30rpx 0;
}
.rich-image-placeholder {
  width: 100%;
  height: 200rpx;
  border-radius: 12rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 16rpx 0;
}
.rich-icon {
  font-size: 80rpx;
}
.faq-list {
  display: flex;
  flex-direction: column;
  gap: 24rpx;
}
.faq-item {
  background: #F8FAFC;
  border-radius: 16rpx;
  padding: 20rpx 24rpx;
}
.faq-q {
  font-size: 28rpx;
  font-weight: 600;
  color: #1F2937;
  display: block;
  margin-bottom: 8rpx;
}
.faq-a {
  font-size: 26rpx;
  color: #6B7280;
  line-height: 1.6;
}
.detail-bottom-bar {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  background: #ffffff;
  padding: 20rpx 24rpx;
  /* #ifdef H5 */
  padding-bottom: 56rpx;
  /* #endif */
  /* #ifndef H5 */
  padding-bottom: calc(12rpx + env(safe-area-inset-bottom));
  /* #endif */
  border-top: 2rpx solid #F1F5F9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  z-index: 10;
}
.bottom-left-icons {
  display: flex;
  align-items: center;
  gap: 24rpx;
}
.icon-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: 32rpx;
  color: #4B5563;
}
.icon-label {
  font-size: 20rpx;
  color: #6B7280;
  margin-top: 2rpx;
}
.bottom-right-btns {
  display: flex;
  gap: 16rpx;
}
.bottom-btn {
  padding: 16rpx 48rpx;
  border-radius: 56rpx;
  font-size: 30rpx;
  font-weight: 600;
  text-align: center;
}
.bottom-btn.primary {
  background: #4291F6;
  color: #fff;
}
.bottom-btn.secondary {
  background: #EEF2F6;
  color: #1F2937;
}
.bottom-btn:active {
  opacity: 0.7;
}

/* ============================================================ */
/* ===== 底部自定义导航栏 ===== */
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
  z-index: 1000;
  padding: 0 10rpx;
}
.custom-tabbar .tab-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2rpx;
  transform: translateY(-5rpx);
  overflow: visible;
}
.custom-tabbar .tab-icon-wrapper {
  height: 46rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.custom-tabbar .tab-icon-text {
  font-size: 46rpx;
  line-height: 1.2;
}
.custom-tabbar .tab-icon-img {
  width: 46rpx;
  height: 46rpx;
  display: block;
}
.custom-tabbar .tab-text {
  font-size: 21rpx;
  color: #666666;
}
.custom-tabbar .tab-text.active {
  color: #007aff;
  font-weight: 600;
}

.custom-tabbar .tab-item.is-center .tab-icon-wrapper {
  width: 80rpx;
  height: 80rpx;
  border-radius: 50%;
  background: #ffffff;
  box-shadow: 0 4rpx 16rpx rgba(0, 0, 0, 0.1);
  margin-top: -30rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.3s ease, box-shadow 0.3s ease;
}
.custom-tabbar .tab-item.is-center .tab-icon-wrapper.center-active {
  background: linear-gradient(135deg, #6EA8FF, #165DFF);
  box-shadow: 0 4rpx 16rpx rgba(22, 93, 255, 0.3);
}
.custom-tabbar .tab-item.is-center .tab-icon-img {
  width: 48rpx;
  height: 48rpx;
}
.custom-tabbar .tab-item.is-center .tab-icon-text {
  font-size: 48rpx;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
@keyframes slideIn {
  from { transform: translateX(100%); }
  to { transform: translateX(0); }
}

/* ===== 租用底部弹层 ===== */
.rent-modal-mask {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background: rgba(0,0,0,0.5);
  z-index: 1200;
}
.rent-modal-backdrop {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
}
.rent-sheet {
  position: absolute;
  left: 0; right: 0; bottom: 0;
  z-index: 2;
  box-sizing: border-box;
  width: 100%;
  background: #ffffff;
  border-radius: 28rpx 28rpx 0 0;
  padding: 28rpx 28rpx calc(28rpx + env(safe-area-inset-bottom));
  animation: slideUp 0.25s ease;
}
@keyframes slideUp {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}
.sheet-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20rpx;
}
.sheet-price-wrap { display: flex; align-items: baseline; gap: 8rpx; }
.sheet-price { font-size: 34rpx; font-weight: 700; color: #ff5722; }
.sheet-unit { font-size: 20rpx; color: #ff5722; }
.sheet-origin { font-size: 20rpx; color: #9ca3af; text-decoration: line-through; }
.sheet-close { font-size: 36rpx; color: #9ca3af; padding: 4rpx 8rpx; }
.sheet-product {
  display: flex;
  align-items: center;
  gap: 16rpx;
  padding: 12rpx 0 20rpx;
  border-bottom: 1rpx solid #f1f5f9;
}
.sheet-thumb {
  width: 96rpx; height: 96rpx;
  border-radius: 16rpx;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.sheet-thumb-icon { font-size: 52rpx; }
.sheet-product-name {
  flex: 1;
  min-width: 0;
  font-size: 26rpx; font-weight: 600; color: #1a2a3a;
}
.sheet-qty {
  flex-shrink: 0;
  display: flex; align-items: center; gap: 16rpx;
  border: 1rpx solid #e2e8f0; border-radius: 12rpx; padding: 4rpx 10rpx;
}
.qty-btn { font-size: 30rpx; color: #334155; padding: 4rpx 10rpx; }
.qty-num { font-size: 26rpx; color: #1a2a3a; min-width: 28rpx; text-align: center; }
.sheet-field {
  padding: 20rpx 0;
  border-bottom: 1rpx solid #f1f5f9;
}
.sheet-label {
  font-size: 26rpx; color: #1a2a3a; font-weight: 600; display: block; margin-bottom: 14rpx;
}
.sheet-value {
  display: flex; align-items: center; justify-content: space-between;
  background: #f0f6ff; border: 2rpx solid #4291f6;
  border-radius: 12rpx; padding: 18rpx 20rpx;
}
.sheet-value-text { font-size: 22rpx; color: #2563eb; flex: 1; min-width: 0; padding-right: 12rpx; }
.sheet-arrow { color: #94a3b8; font-size: 28rpx; }
.sheet-date-row {
  display: flex; align-items: center; justify-content: space-between; gap: 12rpx;
}
.date-pill {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  box-sizing: border-box;
  background: #f8faff; border: 1rpx solid #e2e8f0; border-radius: 12rpx;
  padding: 16rpx 4rpx; text-align: center; font-size: 22rpx; color: #1a2a3a;
}
.date-pill.days { color: #4291f6; font-weight: 600; }
.sheet-confirm {
  margin-top: 28rpx;
  height: 88rpx; border-radius: 44rpx;
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  color: #ffffff; font-size: 30rpx; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6rpx 20rpx rgba(66, 145, 246, 0.3);
}
.sheet-confirm:active { opacity: 0.85; }

.sheet-value.static { justify-content: flex-start; }
.pv-arrow { color: #94a3b8; font-size: 22rpx; margin-left: 6rpx; }
.date-pv {
  height: 220rpx;
  margin-top: 12rpx;
  background: #f8faff;
  border-radius: 12rpx;
  border: 1rpx solid #e2e8f0;
}
.pv-item {
  height: 68rpx;
  line-height: 68rpx;
  text-align: center;
  font-size: 26rpx;
  color: #1a2a3a;
}
.days-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin-top: 16rpx;
}
.days-chip {
  padding: 10rpx 18rpx;
  border-radius: 30rpx;
  background: #f1f5f9;
  color: #4a5a6a;
  font-size: 20rpx;
}
.days-chip.active {
  background: #e8f1ff;
  color: #2563eb;
  border: 1rpx solid #4291f6;
}

/* ===== 企业部署咨询弹窗 ===== */
.enterprise-mask {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 1200;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: fadeIn 0.25s ease;
}
.enterprise-modal {
  width: 86%;
  max-height: 82vh;
  background: #ffffff;
  border-radius: 32rpx;
  padding: 32rpx 30rpx 28rpx;
  position: relative;
  display: flex;
  flex-direction: column;
  animation: enterpriseScale 0.3s ease;
}
.em-close {
  position: absolute;
  top: 16rpx;
  right: 24rpx;
  font-size: 36rpx;
  color: #9aaab8;
  padding: 8rpx;
  z-index: 10;
}
.em-header {
  text-align: center;
  margin-bottom: 20rpx;
  padding-right: 40rpx;
}
.em-title {
  font-size: 34rpx;
  font-weight: 700;
  color: #1a2a3a;
  display: block;
  margin-top: 8rpx;
}
.em-subtitle {
  font-size: 24rpx;
  color: #8a9aa8;
  display: block;
  margin-top: 6rpx;
}
.em-body {
  flex: 1;
  max-height: 46vh;
  min-height: 0;
}
.em-item { margin-bottom: 20rpx; }
.em-label {
  font-size: 26rpx;
  font-weight: 500;
  color: #1a2a3a;
  display: block;
  margin-bottom: 8rpx;
}
.required { color: #ef4444; }
.optional { color: #9aaab8; font-weight: 400; font-size: 22rpx; }
.em-input {
  width: 100%;
  box-sizing: border-box;
  height: 76rpx;
  padding: 0 20rpx;
  border-radius: 16rpx;
  font-size: 26rpx;
  color: #1a2a3a;
  background: #f5f7fa;
  border: 1rpx solid #e8edf3;
}
.em-input:focus { border-color: #4291F6; }
.em-tag-group { display: flex; gap: 16rpx; }
.em-tag {
  flex: 1;
  text-align: center;
  padding: 16rpx 0;
  border-radius: 40rpx;
  font-size: 28rpx;
  color: #6a7a8a;
  background: #f5f7fa;
  border: 2rpx solid #e8edf3;
  transition: all 0.2s;
}
.em-tag.active {
  background: rgba(66, 145, 246, 0.12);
  color: #4291F6;
  border-color: #4291F6;
  font-weight: 500;
}
.em-footer {
  display: flex;
  gap: 16rpx;
  margin-top: 24rpx;
  flex-shrink: 0;
}
.em-btn {
  flex: 1;
  height: 80rpx;
  border-radius: 40rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28rpx;
  font-weight: 500;
}
.em-btn.secondary { background: #f5f7fa; color: #6a7a8a; }
.em-btn.secondary:active { background: #e8edf3; }
.em-btn.primary {
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  color: #fff;
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.25);
}
.em-btn.primary:active { opacity: 0.8; }
@keyframes enterpriseScale {
  from { transform: scale(0.9); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
/* ===== 自定义方案(机器页多选 + 方案框) ===== */
.custom-plan-header { display: flex; align-items: center; justify-content: center; background: #fff; padding: 88rpx 24rpx 0; border-bottom: 2rpx solid #E8EDF3; flex-shrink: 0; }
.cpp-btns { display: flex; flex-direction: row; align-items: center; gap: 12rpx; }
.cpp-btn { font-size: 24rpx; color: #165DFF; background: #EAF1FF; border-radius: 28rpx; padding: 10rpx 30rpx; text-align: center; }
.type-bar { display: flex; align-items: center; gap: 12rpx; border-bottom: 1rpx solid #E7EAF0; }
.type-bar .type-scroll { flex: 1; }
.custom-plan-fixed { flex-shrink: 0; margin-left: auto; }
.type-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.55); z-index: 1200; display: flex; align-items: center; justify-content: center; }
.type-modal { width: 640rpx; max-height: 70vh; background: #fff; border-radius: 24rpx; padding: 30rpx 28rpx; box-sizing: border-box; }
.type-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24rpx; }
.type-modal-title { font-size: 32rpx; font-weight: 700; color: #1F2937; }
.type-modal-close { font-size: 30rpx; color: #94A3B8; padding: 6rpx 12rpx; }
.type-grid { display: flex; flex-wrap: wrap; gap: 20rpx; }
/* 类别内联展开：点「分类」后在排序栏下方就地展开，把产品列表往下推 */
.type-inline {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
  margin: 8rpx 12rpx 12rpx;
  padding: 18rpx 18rpx 14rpx;
  background: #FFFFFF;
  border: 1rpx solid #E7EAF0;
  border-radius: 16rpx;
  box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.06);
  box-sizing: border-box;
}
.type-inline .type-opt {
  width: calc(33.33% - 11rpx);
  padding: 14rpx 0;
  font-size: 26rpx;
}
.type-opt { width: calc(33.33% - 14rpx); text-align: center; padding: 18rpx 0; background: #F1F5F9; border-radius: 14rpx; color: #475569; font-size: 28rpx; box-sizing: border-box; }
.type-opt.active { background: #EAF1FF; color: #165DFF; font-weight: 600; }
.product-card { position: relative; }
.product-card.selected { border-color: #165DFF; box-shadow: 0 0 0 2rpx rgba(22, 93, 255, 0.35); }
.product-select-btn { position: absolute; top: 12rpx; right: 12rpx; width: 52rpx; height: 52rpx; border-radius: 50%; background: rgba(22, 93, 255, 0.92); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 34rpx; line-height: 1; z-index: 2; }
.scene-cart-bar { position: fixed; left: 20rpx; right: 20rpx; bottom: calc(env(safe-area-inset-bottom) + 20rpx); background: #fff; border-radius: 20rpx; box-shadow: 0 8rpx 32rpx rgba(15, 23, 42, 0.18); padding: 18rpx 24rpx; z-index: 1100; }
/* #ifdef MP-WEIXIN */
.scene-cart-bar { bottom: calc(env(safe-area-inset-bottom) + 2rpx); }
/* #endif */
.cart-bar-top { display: flex; justify-content: flex-end; gap: 12rpx; margin-bottom: 12rpx; }
.scene-cart-empty { font-size: 24rpx; color: #94A3B8; text-align: center; padding: 10rpx 0 18rpx; }
.cart-items-scroll { white-space: nowrap; margin-bottom: 14rpx; }
.cart-item { display: inline-flex; flex-direction: column; align-items: center; width: 150rpx; margin-right: 20rpx; }
.cart-item-img { position: relative; width: 140rpx; height: 120rpx; border-radius: 14rpx; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.cart-item-img-img { width: 100%; height: 100%; }
.cart-item-img-icon { font-size: 52rpx; }
.cart-item-del { position: absolute; top: -6rpx; right: -6rpx; width: 36rpx; height: 36rpx; border-radius: 50%; background: rgba(15, 23, 42, 0.7); color: #fff; font-size: 26rpx; display: flex; align-items: center; justify-content: center; }
.cart-item-name { font-size: 24rpx; color: #1F2937; margin-top: 8rpx; max-width: 140rpx; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.cart-item-price { font-size: 22rpx; color: #165DFF; margin-top: 4rpx; }
.cart-date-label { font-size: 24rpx; color: #64748B; flex-shrink: 0; }
.cart-date-end { font-size: 22rpx; color: #94A3B8; }
.scene-cart-row { display: flex; align-items: center; gap: 16rpx; }
.scene-cart-count { font-size: 26rpx; font-weight: 700; color: #1F2937; flex-shrink: 0; }
.scene-cart-days { display: flex; gap: 8rpx; flex: 1; flex-wrap: wrap; }
.day-chip { font-size: 22rpx; color: #475569; background: #F1F5F9; border-radius: 24rpx; padding: 6rpx 18rpx; }
.day-chip.active { background: #165DFF; color: #fff; }
.scene-cart-total { font-size: 32rpx; font-weight: 700; color: #165DFF; flex-shrink: 0; }
.scene-cart-actions { display: flex; gap: 16rpx; margin-top: 14rpx; }
.scene-cart-save { flex: 1; height: 80rpx; line-height: 80rpx; text-align: center; border-radius: 40rpx; border: 1px solid #CBD5E1; color: #475569; font-size: 28rpx; }
.scene-cart-submit { flex: 1; height: 80rpx; line-height: 80rpx; text-align: center; color: #fff; font-size: 30rpx; font-weight: 600; background: linear-gradient(135deg, #1E6FFF, #0EA5B7); border-radius: 40rpx; }
.plan-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.55); z-index: 1200; display: flex; align-items: center; justify-content: center; }
.plan-modal { width: 640rpx; max-height: 68vh; background: #fff; border-radius: 24rpx; padding: 30rpx 28rpx; box-sizing: border-box; display: flex; flex-direction: column; }
.plan-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20rpx; }
.plan-modal-title { font-size: 32rpx; font-weight: 700; color: #1F2937; }
.plan-modal-close { font-size: 30rpx; color: #94A3B8; padding: 6rpx 12rpx; }
.plan-modal-list { max-height: 50vh; flex: 1; }
.plan-item { display: flex; align-items: center; justify-content: space-between; padding: 18rpx 12rpx; border-bottom: 1rpx solid #F1F5F9; }
.plan-item-info { display: flex; flex-direction: column; gap: 6rpx; }
.plan-item-name { font-size: 26rpx; color: #1F2937; }
.plan-item-total { font-size: 24rpx; color: #165DFF; font-weight: 600; }
.plan-item-del { font-size: 24rpx; color: #EF4444; padding: 6rpx 16rpx; }
.plan-empty { text-align: center; color: #94A3B8; font-size: 26rpx; padding: 40rpx 0; }
.cart-date-label { font-size: 24rpx; color: #64748B; flex-shrink: 0; }
.plan-date-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.55); z-index: 1250; display: flex; align-items: center; justify-content: center; }
.plan-date-modal { width: 640rpx; background: #fff; border-radius: 24rpx; padding: 30rpx 28rpx; box-sizing: border-box; }
.plan-date-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20rpx; }
.plan-date-title { font-size: 30rpx; font-weight: 700; color: #1F2937; }
.plan-date-close { font-size: 30rpx; color: #94A3B8; padding: 6rpx 12rpx; }
.plan-date-pv { height: 400rpx; }
.plan-date-ok { margin-top: 24rpx; height: 84rpx; line-height: 84rpx; text-align: center; color: #fff; font-size: 30rpx; font-weight: 600; background: linear-gradient(135deg, #1E6FFF, #0EA5B7); border-radius: 42rpx; }
</style>

<!-- ★ 全局：隐藏滚动条（已去通配符 *——新版 WXSS 不支持 `*`/`*::before`，改枚举实际滚动容器 page/scroll-view/uni-scroll-view；本页 scroll-view 均已带 show-scrollbar="false"，此块为兜底） -->
<style>
::-webkit-scrollbar,
page::-webkit-scrollbar,
scroll-view::-webkit-scrollbar,
uni-scroll-view::-webkit-scrollbar,
uni-scroll-view .uni-scroll-view::-webkit-scrollbar,
.uni-scroll-view::-webkit-scrollbar {
	width: 0 !important;
	height: 0 !important;
	display: none !important;
}
page,
scroll-view,
uni-scroll-view,
.uni-scroll-view {
	scrollbar-width: none !important;
	-ms-overflow-style: none !important;
}
</style>