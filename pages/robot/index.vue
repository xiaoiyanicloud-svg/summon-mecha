<template>
  <view class="rental-page">
    <!-- ===== 顶部主 Tab（四个）：租售 | 场景 | 技能 | 配件 ===== -->
    <view class="top-tabs" v-if="!customPlanMode">
      <text class="tab-item" :class="{ active: currentTab === 'tob' }" @tap="switchTab('tob')">租售</text>
      <text class="tab-item" :class="{ active: currentTab === 'scene' }" @tap="switchTab('scene')">场景</text>
      <text class="tab-item" :class="{ active: currentTab === 'skill' }" @tap="switchTab('skill')">技能</text>
      <text class="tab-item" :class="{ active: currentTab === 'accessory' }" @tap="switchTab('accessory')">配件</text>
    </view>

    <!-- ===== 自定义方案顶栏 ===== -->
    <view class="custom-plan-header" v-if="customPlanMode"></view>

    <!-- ===== 类别选择：原弹窗已改为内联向下展开（见 .type-bar 下方 .type-inline） ===== -->

    <!-- ===== 场景分类滚动条 - 已删除 ===== -->

    <!-- ===== 搜索框(租售 / 场景) ===== -->
    <view class="search-bar" v-show="currentTab === 'tob'">
      <view class="search-row">
        <view class="search-glass"><view class="search-glass-circle"></view><view class="search-glass-handle"></view></view>
        <input class="search-input" v-model="searchQ" placeholder="搜索机型 / 品牌 / SKU / 形态" confirm-type="search" />
        <text class="search-clear" v-if="searchQ" @tap="searchQ = ''">✕</text>
      </view>
    </view>
    <view class="search-bar" v-show="currentTab === 'scene'">
      <view class="search-row">
        <view class="search-glass"><view class="search-glass-circle"></view><view class="search-glass-handle"></view></view>
        <input class="search-input" v-model="sceneSearchQ" placeholder="搜索场景名称 / 描述 / 标签" confirm-type="search" />
        <text class="search-clear" v-if="sceneSearchQ" @tap="sceneSearchQ = ''">✕</text>
      </view>
    </view>

    <!-- ===== 主体 ===== -->
    <view class="main-body">
      <!-- 租售：动态品牌侧边栏（后台品牌管理驱动，未入库兼容兜底） -->
      <scroll-view class="brand-sidebar" scroll-y="true" show-scrollbar="false" v-show="currentTab === 'tob'" @scroll="onPanelScroll('brand')">
        <view class="brand-item" :class="{ active: currentBrand === 'all' }" @tap="selectBrand('all')">
          <text class="brand-name">全部品牌</text>
        </view>
        <view class="brand-item" v-for="b in brandList" :key="b.code" :class="{ active: currentBrand === b.code }" @tap="selectBrand(b.code)">
          <image v-if="b.logoUrl" class="brand-logo" :src="b.logoUrl" mode="aspectFit" />
          <view class="brand-label-row">
            <text class="brand-name">{{ b.name }}</text>
            <text v-if="b.isHot" class="brand-hot-dot"></text>
          </view>
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

        <!-- 租售子模式与多机方案工具条（顶层分栏：分段切换 + 方案入口） -->
        <view class="tob-toolbar-head" v-if="currentTab === 'tob' && !customPlanMode">
          <view class="tob-subbar">
            <text class="tob-sub-pill" :class="{ active: tobSubTab === 'all' }" @tap="setTobSubTab('all')">全部</text>
            <text class="tob-sub-pill" :class="{ active: tobSubTab === 'rent' }" @tap="setTobSubTab('rent')">租赁</text>
            <text class="tob-sub-pill" :class="{ active: tobSubTab === 'buy' }" @tap="setTobSubTab('buy')">购买</text>
          </view>
          <text class="type-item custom-plan-fixed" v-if="tobSubTab !== 'buy'" @tap="enterCustomPlanMode">+方案</text>
        </view>

        <view class="type-bar" v-if="currentTab === 'tob'">
          <!-- 形态筛选（默认「全部形态」，点开在下方就地展开） -->
          <view class="cat-group">
            <view class="cat-picker" :class="{ active: showTypePicker || currentType !== 'all' }" @tap="openTypePicker">
              <text class="cat-text">{{ currentTypeLabel }}</text>
              <text class="cat-arrow">{{ showTypePicker ? '▴' : '▾' }}</text>
            </view>
          </view>
          <!-- 租售共用：综合排序下拉 -->
          <view class="sort-picker-wrap">
            <view class="sort-picker" :class="{ open: showSortPanel, filtered: currentSort !== 'default' }" @tap.stop="toggleSortPanel">
              <text class="sort-picker-text">{{ sortLabel }}</text>
              <text class="sort-picker-arrow">{{ showSortPanel ? '▴' : '▾' }}</text>
            </view>

            <view v-if="showSortPanel" class="sort-panel" @tap.stop>
              <view
                v-for="option in sortOptions"
                :key="option.value"
                class="sort-option"
                :class="{ active: currentSort === option.value }"
                @tap="pickSort(option.value)"
              >
                <text>{{ option.label }}</text>
                <text v-if="currentSort === option.value" class="sort-option-check">✓</text>
              </view>
            </view>
          </view>
          <text class="bar-reset" v-if="hasActiveTobFilters" @tap="resetTobFilters">重置</text>
        </view>

        <!-- 形态：点「全部形态」后在下方就地展开（非弹窗），再点一次收起 -->
        <view class="type-inline" v-if="showTypePicker && currentTab === 'tob'">
          <view class="type-opt" :class="{ active: currentType === 'all' }" @tap="selectType('all'); showTypePicker = false">全部形态</view>
          <view class="type-opt" :class="{ active: currentType === t.code }" v-for="t in rentTypeOptions" :key="t.code" @tap="selectType(t.code); showTypePicker = false">{{ t.label }}</view>
        </view>

        <!-- 租售（统一目录：支持 全部 / 租赁 / 购买 及 +方案 多机合单） -->
        <view class="product-area-wrapper" v-show="currentTab === 'tob'">
        <scroll-view class="product-scroll" scroll-y="true" show-scrollbar="false" enable-flex @scroll="onPanelScroll('product')">
          <view class="product-flex-wrapper">
            <view class="active-filters-bar" v-if="hasActiveTobFilters">
              <view class="af-chip" v-if="currentBrand !== 'all'" @tap="selectBrand('all')">
                <text>{{ currentBrandLabel }}</text><text class="af-close">×</text>
              </view>
              <view class="af-chip" v-if="currentType !== 'all'" @tap="selectType('all')">
                <text>{{ currentTypeLabel }}</text><text class="af-close">×</text>
              </view>
              <view class="af-chip" v-if="searchQ.trim()" @tap="searchQ = ''">
                <text>关键词：{{ searchQ.trim() }}</text><text class="af-close">×</text>
              </view>
              <text class="af-reset" @tap="resetTobFilters">清空筛选</text>
            </view>
            <view v-if="currentBrand === 'all'" class="enterprise-banner" @tap="goEnterprise">
              <view class="banner-left">
                <text class="banner-badge">企业部署</text>
                <text class="banner-title">多机联调与驻场咨询</text>
              </view>
              <view class="banner-right">
                <view class="banner-btn">立即咨询</view>
              </view>
            </view>
            <view
              class="product-card"
              :class="{ selected: customPlanMode && item.catalogKind === 'rent' && isPlanSelected(item) }"
              v-for="item in filteredTobList"
              :key="item.uniqueKey || item.id"
              @tap="(customPlanMode && item.catalogKind === 'rent') ? togglePlanItem(item) : goDetail(item)"
            >
              <view class="product-image" :style="{ backgroundColor: item.bg }">
                <image class="product-img" :src="item.image" mode="aspectFill" @error="imageFailed(item)"></image>
              </view>
              <view v-if="customPlanMode && item.catalogKind === 'rent'" class="product-select-btn" :class="{ on: isPlanSelected(item) }" @tap.stop="togglePlanItem(item)">{{ isPlanSelected(item) ? '✓' : '＋' }}</view>
              <view class="product-info">
                <text class="product-name">{{ item.name }}</text>
                <view class="card-tags-row" v-if="tobSubTab === 'all' || item.typeLabel || item.raasBadge">
                  <text class="mode-tag" :class="item.catalogKind === 'buy' ? 'buy' : 'rent'" v-if="tobSubTab === 'all'">{{ item.catalogKind === 'buy' ? '购买' : '租赁' }}</text>
                  <text class="scene-tag" v-if="item.typeLabel">{{ item.typeLabel }}</text>
                  <text class="raas-tag" v-if="item.raasBadge">{{ item.raasBadge }}</text>
                </view>
                <view class="product-bottom">
                  <view class="product-price-wrap">
                    <text class="product-price"><text class="price-badge">到手价</text><text class="price-cur">¥</text>{{ item.bagistoPrice || item.price }}<text class="price-unit" v-if="item.catalogKind === 'rent'">/天</text></text>
                    <text class="product-original" v-if="item.originalPrice">¥{{ item.originalPrice }}</text>
                  </view>
                </view>
              </view>
            </view>
            <view class="scroll-bottom-placeholder" v-if="filteredTobList.length > 0"></view>
          </view>
          <view class="empty-state" v-if="filteredTobList.length === 0 && !catalogLoading && !catalogError">
            <SmIcon name="empty-inbox" size="88rpx" />
            <text class="empty-text">没有找到匹配的机器人</text>
          </view>
          <state-view
            v-else-if="filteredTobList.length === 0"
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
                <image class="scene-list-img" :src="sceneImage(scene)" mode="aspectFill" @error="sceneImageFailed(scene)"></image>
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
      <Peijian v-show="currentTab === 'accessory'" :active="currentTab === 'accessory'" @item-click="onAccessory" />
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
                <image v-if="typeof img === 'string'" class="swiper-img" :src="img" :data-src="img" mode="widthFix" @load="onDetailImgLoad"></image>
                <SmIcon v-else-if="!selectedProduct.icon || selectedProduct.icon === '🤖'" name="empty-robot" size="96rpx" />
                <SmIcon v-else-if="selectedProduct.icon === '🧩'" name="empty-accessory" size="96rpx" />
                <text v-else class="swiper-icon">{{ selectedProduct.icon }}</text>
              </view>
            </swiper-item>
          </swiper>
          <view class="detail-info-area">
            <view class="detail-price-row">
              <text class="detail-price"><text class="detail-badge">{{ detailPriceBadge }}</text><text class="detail-cur">¥</text>{{ detailPrice }}</text>
              <text class="detail-day" v-if="actionType === 'rent'">/天起</text>
              <text class="detail-original" v-if="selectedProduct.originalPrice">¥{{ selectedProduct.originalPrice }}</text>
            </view>
            <text class="detail-name">{{ selectedProduct.name }}</text>
            <view class="sibling-switch-card" v-if="siblingTobProduct" @tap="switchToSiblingTobProduct">
              <text class="sibling-switch-tag">{{ siblingTobProduct.catalogKind === 'buy' ? '支持购买' : '支持租赁' }}</text>
              <text class="sibling-switch-text">该机型另有{{ siblingTobProduct.catalogKind === 'buy' ? '买断采购' : '日租方案' }}（¥{{ siblingTobProduct.bagistoPrice || siblingTobProduct.price }}{{ siblingTobProduct.catalogKind === 'rent' ? '/天' : '' }}）</text>
              <text class="sibling-switch-arrow">切换 ›</text>
            </view>
            <!-- [LINK_TAG] 已隐藏"已对接官方商品"状态提示(原: ●已对接官方商品 / ●未对接官方商品) -->
          </view>
          <!-- 配件定制：只给报价入口，不显示固定总价 -->
          <view class="quote-note" v-if="actionType === 'accessory' && selectedProduct.quoteRequired">
            <text class="quote-title">定制款需先报价</text>
            <text class="quote-text">请提供机型、图案/材质与期望工期，运营确认后给出报价与交期。</text>
            <text class="quote-link" @click="requestAccessoryQuote">提交定制需求获取报价 →</text>
          </view>
          <!-- 配件适配矩阵：来自 accessory_fitments，按直装/需转接/需定制分级 -->
          <view class="adapt-card" v-if="actionType === 'accessory'">
            <text class="adapt-title">适配机型</text>
            <view class="adapt-list" v-if="selectedProduct.accessoryFitments && selectedProduct.accessoryFitments.length">
              <view class="adapt-row" v-for="f in selectedProduct.accessoryFitments" :key="f.model_code">
                <view class="adapt-row-head">
                  <text class="adapt-name">{{ f.model_name }}</text>
                  <text class="adapt-level" :class="'lv-' + f.fit_level">{{ f.label }}</text>
                </view>
                <text v-if="f.note" class="adapt-detail">{{ f.note }}</text>
                <text v-if="f.adapter_sku" class="adapt-detail">需转接件：{{ f.adapter_sku }}</text>
              </view>
            </view>
            <text v-else class="adapt-note">适配信息待补充，购买前请咨询客服确认机型与接口。</text>
            <text v-if="selectedProduct.compatibilityNote" class="adapt-note">{{ selectedProduct.compatibilityNote }}</text>
          </view>
          <!-- 技能适配机型：后台关联商品 -->
          <view class="adapt-card" v-else-if="relatedList.length">
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
          <view class="service-area" :class="{ 'service-area-cover': actionType === 'rent' || actionType === 'buy' }">
            <image
              v-if="actionType === 'rent' || actionType === 'buy'"
              class="service-cover-img"
              src="/static/baoxian.png"
              mode="widthFix"
              aria-label="全程护航保障"
            ></image>
            <template v-else>
              <text class="service-title">{{ serviceInfo.title }}</text>
              <view class="service-item" v-for="(svc, idx) in serviceInfo.items" :key="idx">
                <text class="service-label" v-if="svc.label">{{ svc.label }}</text>
                <text class="service-value">{{ svc.value }}</text>
              </view>
            </template>
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
          <view style="height: 214rpx;"></view>
        </scroll-view>
        <view class="detail-bottom-bar">
          <view class="bottom-assist">
            <view class="assist-btn" role="button" tabindex="0" aria-label="联系在线客服" @tap="callService">
              <view class="assist-icon service-icon">
                <view class="service-band"></view>
                <view class="service-pad service-pad-left"></view>
                <view class="service-pad service-pad-right"></view>
                <view class="service-mic"></view>
              </view>
              <text class="assist-label">客服</text>
            </view>
          </view>
          <view class="bottom-right-btns" :class="{ 'commerce-actions': actionType === 'rent' || actionType === 'buy' || actionType === 'accessory' }">
            <view class="bottom-btn secondary favorite-btn" :class="{ active: isFavorited, disabled: wishlistLoading }" role="button" tabindex="0" @tap="toggleFavorite">
              <text class="action-label">{{ favoriteActionLabel }}</text>
            </view>
            <view class="bottom-btn primary" :class="{ disabled: primaryActionDisabled }" role="button" tabindex="0" @tap="confirmAction(actionType)">
              <text class="action-label">{{ primaryActionLabel }}</text>
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
            <text class="sheet-origin" v-if="selectedProduct.originalPrice">¥{{ selectedProduct.originalPrice }}</text>
          </view>
          <text class="sheet-close" @tap="cancelRentModal">✕</text>
        </view>

        <!-- 商品：缩略图 + 名称 + 数量 -->
        <view class="sheet-product">
          <view class="sheet-thumb" :style="{ backgroundColor: selectedProduct.bg }">
            <SmIcon v-if="!selectedProduct.icon || selectedProduct.icon === '🤖'" name="empty-robot" size="64rpx" />
            <SmIcon v-else-if="selectedProduct.icon === '🧩'" name="empty-accessory" size="64rpx" />
            <text v-else class="sheet-thumb-icon">{{ selectedProduct.icon }}</text>
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
            <text class="sheet-value-text">{{ selectedProduct.name }}（1天起单台每日价格）{{ selectedProduct.typeLabel ? '【' + selectedProduct.typeLabel + '·租赁】' : '【标准租赁】' }}</text>
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
          <view class="sheet-min-tip" v-if="selectedProductMinDays > 1">
            <text class="sheet-min-tip-text">该设备最低起租 {{ selectedProductMinDays }} 天</text>
          </view>
          <view class="days-chips">
            <view class="days-chip" v-for="(op, i) in rentPeriodOptions" :key="op"
                  :class="{ active: rentForm.daysIndex === i, disabled: parseInt(op) < selectedProductMinDays }"
                  @tap="selectRentPeriod(i, op)">{{ op }}</view>
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
            <SmIcon v-else-if="!m.icon || m.icon === '🤖'" name="empty-robot" size="48rpx" />
            <SmIcon v-else-if="m.icon === '🧩'" name="empty-accessory" size="48rpx" />
            <text v-else class="cart-item-img-icon">{{ m.icon }}</text>
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
        <text class="scene-cart-total">已选 <text class="cart-num">{{ selectedPlanItems.length }}</text> 台 · 共 <text class="cart-num">{{ planDays }}</text> 天<text class="cart-min-note" v-if="planMinDaysRequired > 1">（最低{{ planMinDaysRequired }}天起租）</text><text class="cart-sum">¥{{ planTotal() }}</text></text>
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
import { productApi, categoryApi, brandApi, catalogApi, accessoryCatalogApi, wishlistApi } from '@/utils/api'
import { openSceneRequest, sceneDraftPayload } from '@/utils/scene-request'
import { sceneProducts, categoryMap, loadBackendScenes } from '@/components/jiqi/changjing-data.js'
import { normalizeProductPricing, pickBaseImage, pickGalleryImages, catalogFallbackImage } from '@/utils/product-catalog'
import { BASE_URL, TOKEN_KEY } from '@/utils/config'
import SmIcon from '@/components/SmIcon.vue'

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
  title: '平台核验・履约可追溯',
  items: [
    { label: '交付', value: '交期与配送方案以商品页标注及商户确认为准' },
    { label: '质保', value: '按原厂或授权商户质保条款执行' },
    { label: '售后', value: '支持平台维保协调与合规争议处理' },
  ],
}
const serviceConfigs = {
  rent: {
    title: '租售・平台保障',
    items: [
      { label: '交付', value: '支持物流配送或工程师现场交付（以商品与合同约定为准）' },
      { label: '服务区域', value: '覆盖区域及档期以提交订单后商户承接确认为准' },
    ],
  },
  buy: BUY_SERVICE,
  // 配件不承诺“官方正品”:只有 official=true 的商品才是官方自营,其余是平台严选/第三方。
  // 安装、质保、交期都以商品页的实际标注为准,没有标注就如实说“以商品页为准”。
  accessory: {
    title: '平台严选・服务可追溯',
    items: [
      { label: '配送', value: '以商品页标注的交期为准' },
      { label: '安装', value: '支持预约工程师,是否可上门以详情页标注为准' },
      { label: '售后', value: '按商品页标注的质保与退换规则执行' },
    ],
  },
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

const FALLBACK_BRANDS = [
  { code: 'yushu', name: '宇树', name_en: 'Unitree', aliases: ['宇树', 'Unitree'], is_hot: true },
  { code: 'zhiyuan', name: '智元', name_en: 'Agibot', aliases: ['智元', 'Agibot'], is_hot: true },
  { code: 'youbixuan', name: '优必选', name_en: 'UBTECH', aliases: ['优必选', 'UBTECH', 'Walker'], is_hot: true },
  { code: 'zhongqing', name: '众擎', name_en: 'EngineAI', aliases: ['众擎', 'EngineAI'], is_hot: true },
  { code: 'leju', name: '乐聚', name_en: 'Leju', aliases: ['乐聚', 'Kuavo'], is_hot: true },
  { code: 'galaxy', name: '银河通用', name_en: 'Galbot', aliases: ['银河', '银河通用', 'Galbot'], is_hot: true },
  { code: 'engine', name: '加速进化', name_en: 'Booster Robotics', aliases: ['加速进化', 'Booster'], is_hot: true },
  { code: 'fourier', name: '傅利叶', name_en: 'Fourier', aliases: ['傅利叶', 'Fourier'], is_hot: true },
  { code: 'xiaomi', name: '小米', name_en: 'Xiaomi', aliases: ['小米', 'CyberOne', 'CyberDog'], is_hot: true },
  { code: 'figure', name: 'Figure AI', name_en: 'Figure AI', aliases: ['Figure'], is_hot: true },
  { code: 'dajiang', name: '大疆', name_en: 'DJI', aliases: ['大疆', 'DJI'], is_hot: true },
  { code: 'qinglang', name: '擎朗', name_en: 'KEENON', aliases: ['擎朗', 'KEENON'], is_hot: true },
  { code: 'pudu', name: '普渡', name_en: 'Pudu', aliases: ['普渡', 'Pudu'], is_hot: true },
  { code: 'gaoxian', name: '高仙', name_en: 'Gaussian', aliases: ['高仙', 'Gaussian'], is_hot: true },
  { code: 'yunjii', name: '云迹', name_en: 'Yunji', aliases: ['云迹', 'Yunji'], is_hot: true },
  { code: 'estun', name: '埃斯顿', name_en: 'ESTUN', aliases: ['埃斯顿', 'ESTUN'], is_hot: true },
  { code: 'inovance', name: '汇川', name_en: 'Inovance', aliases: ['汇川', 'Inovance'], is_hot: true },
  { code: 'siasun', name: '新松', name_en: 'SIASUN', aliases: ['新松', 'SIASUN'], is_hot: true },
  { code: 'efort', name: '埃夫特', name_en: 'EFORT', aliases: ['埃夫特', 'EFORT'], is_hot: true },
  { code: 'jaka', name: '节卡', name_en: 'JAKA', aliases: ['节卡', 'JAKA'], is_hot: true },
]

// ===== 品牌/形态回退识别：仅在后端未返回结构化属性时根据别名/关键词识别；未识别返回 '' 防止跨品牌串台 =====
function detectBrandKey(name, brands = FALLBACK_BRANDS) {
  const n = String(name || '').toLowerCase()
  if (!n) return ''
  for (const b of brands || FALLBACK_BRANDS) {
    const kws = [b.name, b.name_en, ...(Array.isArray(b.aliases) ? b.aliases : [])]
    for (const kw of kws) {
      if (kw && n.includes(String(kw).toLowerCase())) return b.code
    }
  }
  return ''
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
  return ''
}

// ===== 目录缓存(模块级,60 秒 TTL):减少频繁 onShow 重拉，同时保障后台品牌/目录调整快速生效 =====
const CATALOG_TTL = 60 * 1000
let _catalogRawCache = null // {rent:[],buy:[],accessory:[],skill:[]} 原始商品数组;命中后经 applyCatalog 重新映射,mapCatalogProduct 为纯函数无共享可变风险
let _catalogCachedAt = 0

// 适配机型请求的竞态 token:详情浮层可以在上一个请求在途时被替换成另一个商品,
// 回来时比对一下,过期的响应直接丢弃(项目的 request.js 不返回 task,没法 abort)
let _relatedSeq = 0

// 配件详情请求的竞态 token（P1）：卡片先展示，完整适配/档案由详情接口补齐
let _accessoryDetailSeq = 0

// 详情顶部图没有真实图片时（走图标占位）的固定框架高度（rpx）。
// 所有带图商品的框架高度都由图片原始宽高比算出，不共用这个值。
const DETAIL_IMG_DEFAULT_HEIGHT = 777.6

export default {
  components: { Jineng, Peijian, StateView, SmIcon },
  data() {
    return {
      currentTab: 'tob',
      tobSubTab: 'all',
      backendBrands: null,
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
      showSortPanel: false,
      sortOptions: [
        { value: 'default', label: '综合排序' },
        { value: 'rating_desc', label: '好评优先' },
        { value: 'sales_desc', label: '销量优先' },
        { value: 'lowest', label: '最低价格' },
        { value: 'highest', label: '最高价格' },
      ],
      currentSceneCategory: 'all',
      sceneVersion: 0,
      scenesLoading: true, scenesError: '', planSubmitting: false,

      showDetail: false,
      detailImgHeight: DETAIL_IMG_DEFAULT_HEIGHT,
      selectedProduct: null,
      actionType: 'rent',
      detailTab: 'desc',
      wishlistProductIds: [],
      wishlistLoading: false,
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
      brokenSceneImages: {},
      faqList: [
        { q: '如何租用或批量下单？', a: '可在商品详情选择起租日期与租期提交租赁订单，也可点击「+方案」多选机型合并下单。' },
        { q: '下单后多久安排交付与档期？', a: '现货与服务排期以商品页标注及商户承接确认为准，大宗或定制项目以正式合同约定为准。' },
        { q: '是否提供技术支持与适配指导？', a: '支持平台及授权商户提供对接调试、操作培训与售后维保协调，具体以商品与机型参数为准。' },
        { q: '付款与对公结算有哪些方式？', a: '支持平台在线支付方式及企业对公结算，具体结算与开票条款可在订单确认时查看。' }
      ],
      // 分类映射 categoryMap 已迁至 components/jiqi/changjing.vue（见 import）
      currentPage: '/pages/robot/index',
      bottomTabs: [
        { pagePath: '/pages/home/index', text: '首页', iconEmoji: '/static/shouye.png', iconActive: '/static/shouye1.png' },
        { pagePath: '/pages/robot/index', text: '分类', iconEmoji: '/static/jiqi.png', iconActive: '/static/jiqi1.png' },
        { pagePath: '/pages/ai/index', text: 'AI召唤', iconEmoji: '/static/aizh.png' },
        { pagePath: '/pages/order/index', text: '订单', iconEmoji: '/static/dingdan.png', iconActive: '/static/dingdan1.png' },
        { pagePath: '/pages/mine/index', text: '我的', iconEmoji: '/static/wode.png', iconActive: '/static/wode1.png' }
      ],
      searchQ: '',
      sceneSearchQ: '',
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
    brandList() {
      const raw = this.backendBrands !== null ? this.backendBrands : FALLBACK_BRANDS
      return raw
        .filter(b => b && b.code && b.status !== false)
        .map(b => ({
          code: b.code,
          name: b.name || b.code,
          nameEn: b.name_en || '',
          aliases: Array.isArray(b.aliases) ? b.aliases : [],
          logoUrl: b.logo_url ? this.normalizeImageUrl(b.logo_url) : '',
          isHot: !!b.is_hot,
          modelsCount: Number(b.models_count || 0),
        }))
    },
    hasActiveTobFilters() {
      return this.currentBrand !== 'all' || this.currentType !== 'all' || Boolean((this.searchQ || '').trim())
    },
    currentBrandLabel() {
      if (this.currentBrand === 'all') return '全部品牌'
      const hit = (this.brandList || []).find(b => b.code === this.currentBrand)
      return hit ? hit.name : this.currentBrand
    },
    selectedProductMinDays() {
      if (this.actionType !== 'rent' || !this.selectedProduct) return 1
      return Math.max(1, Number((this.selectedProduct.raasMeta && this.selectedProduct.raasMeta.min_rent_days) || 1))
    },
    planMinDaysRequired() {
      if (!Array.isArray(this.selectedPlanItems) || !this.selectedPlanItems.length) return 1
      return this.selectedPlanItems.reduce((mx, item) => {
        const d = Number((item && item.raasMeta && item.raasMeta.min_rent_days) || 1)
        return Math.max(mx, Number.isFinite(d) && d > 0 ? d : 1)
      }, 1)
    },
    siblingTobProduct() {
      const p = this.selectedProduct
      if (!p || (this.actionType !== 'rent' && this.actionType !== 'buy')) return null
      const pool = this.actionType === 'rent' ? this.buyCatalog : this.rentCatalog
      if (!Array.isArray(pool) || !pool.length) return null
      const normSku = String(p.sku || '').toUpperCase().replace(/[-_](RENT|BUY)$/i, '')
      if (normSku) {
        const skuMatch = pool.find(item => String(item.sku || '').toUpperCase().replace(/[-_](RENT|BUY)$/i, '') === normSku)
        if (skuMatch) return skuMatch
      }
      const normName = s => String(s || '').replace(/[\s(（【]*(租赁版|租用版|购买版|售卖版|租赁|租用|购买)[】）)\s]*/g, '').trim().toLowerCase()
      const targetName = normName(p.name)
      if (!targetName) return null
      return pool.find(item => normName(item.name) === targetName && (!p.brand || !item.brand || item.brand === p.brand)) || null
    },
    // 形态筛选标题：默认「全部形态」，选中后显示「人形形态」等
    currentTypeLabel() {
      if (this.currentType === 'all') return '全部形态'
      const hit = (this.rentTypeOptions || []).find(o => o.code === this.currentType)
      return (hit ? hit.label : this.currentType) + '形态'
    },
    sortLabel() {
      const hit = this.sortOptions.find(option => option.value === this.currentSort)
      return hit ? hit.label : '综合排序'
    },
    filteredRentList() {
      return this.sortList(this.applyTobFilters(this.rentCatalog))
    },
    filteredBuyList() {
      return this.sortList(this.applyTobFilters(this.buyCatalog))
    },
    filteredTobList() {
      let pool = []
      if (this.tobSubTab === 'rent') pool = this.rentCatalog
      else if (this.tobSubTab === 'buy') pool = this.buyCatalog
      else pool = [...this.rentCatalog, ...this.buyCatalog]
      return this.sortList(this.applyTobFilters(pool))
    },
    filteredSceneList() {
      // 依赖响应式 sceneVersion，使 loadBackendScenes 合并后端场景后强制重算（sceneProducts 是非响应数组）
      this.sceneVersion
      const q = (this.sceneSearchQ || '').trim().toLowerCase()
      let list = this.currentSceneCategory === 'all'
        ? [...sceneProducts].sort((a, b) => ((b.sort ?? 0) - (a.sort ?? 0)) || (a.id - b.id))
        : sceneProducts.filter(item => item.category === this.currentSceneCategory)
      if (!q) return list
      return list.filter(s => {
        const text = [s.name, s.desc, ...(Array.isArray(s.tags) ? s.tags : [])].join(' ').toLowerCase()
        return text.includes(q)
      })
    },
    currentCategoryLabel() {
      if (this.currentSceneCategory === 'all') return '全部场景'
      return categoryMap[this.currentSceneCategory]?.label || ''
    },
    // 详情轮播：优先使用商品图片，否则回退占位
    detailImageList() {
      const p = this.selectedProduct
      if (p) {
        // 过滤 Bagisto 的 product-placeholder 占位图；没有真实图就走图标占位
        const gallery = (Array.isArray(p.images) ? p.images : [])
          .map(url => this.normalizeImageUrl(url))
          .filter(url => url && !url.includes('product-placeholder'))
        if (gallery.length) return gallery
        const cover = this.normalizeImageUrl(p.image)
        if (cover && !cover.includes('product-placeholder')) return [cover]
      }
      return this.detailImages
    },
    // 详情页价格:优先用后端真价(bagistoPrice),否则回退 mock 价
    detailPrice() {
      const p = this.selectedProduct
      const v = (p && p.bagistoPrice != null) ? p.bagistoPrice : (p ? p.price : '0')
      return String(v || '0').replace(/[^\d.]/g, '')
    },
    // 配件没有真实划线价时显示「价格」，不把普通售价写成「到手价」
    detailPriceBadge() {
      if (this.actionType === 'accessory') {
        return this.selectedProduct && this.selectedProduct.originalPrice ? '到手价' : '价格'
      }
      return '到手价'
    },
    // 详情页「产品参数」:配件优先用详情接口组装好的 specs(含档案字段),
    // 其余商品继续读后端透出的自定义属性。
    detailSpecs() {
      if (this.actionType === 'accessory') {
        const specs = this.selectedProduct && this.selectedProduct.accessorySpecs
        if (Array.isArray(specs) && specs.length) return specs
      }
      const attrs = this.selectedProduct && this.selectedProduct.bagistoAttributes
      const skip = new Set(['sku', 'name', 'url_key', 'description', 'short_description', 'price',
        'special_price', 'cost', 'weight', 'status', 'new', 'featured', 'visible_individually',
        'guest_checkout', 'manage_stock', 'tax_category_id', 'product_number'])
      const baseSpecs = attrs && typeof attrs === 'object'
        ? Object.keys(attrs)
          .filter(code => !code.startsWith('meta_') && !skip.has(code) && attrs[code] && attrs[code].value != null)
          .map(code => ({ name: (attrs[code].name || code), value: String(attrs[code].value).trim() }))
          .filter(s => s.value !== '')
        : []
      const seenNames = new Set(baseSpecs.map(s => s.name))
      const raasSpecs = []
      const p = this.selectedProduct || {}
      if (p.brandName && !seenNames.has('品牌') && !seenNames.has('Brand')) {
        raasSpecs.push({ name: '品牌', value: p.brandName })
      }
      if (p.typeLabel && !seenNames.has('机器人形态')) {
        raasSpecs.push({ name: '机器人形态', value: p.typeLabel })
      }
      const rm = p.raasMeta || null
      if (rm && rm.models_count > 0) {
        raasSpecs.push({ name: '平台机型库兼容', value: (rm.active_models || rm.models_count) + ' 款机型' })
      }
      if (rm && Array.isArray(rm.comm_channels) && rm.comm_channels.length) {
        raasSpecs.push({ name: '支持指令通道', value: rm.comm_channels.join(' / ') })
      }
      if (rm && rm.min_rent_days && p.catalogKind === 'rent') {
        raasSpecs.push({ name: '最低起租天数', value: rm.min_rent_days + ' 天' })
      }
      if (rm && rm.deposit_yuan && p.catalogKind === 'rent') {
        raasSpecs.push({ name: '参考押金', value: '¥' + rm.deposit_yuan })
      }
      if (rm && Array.isArray(rm.compatible_model_names) && rm.compatible_model_names.length && !seenNames.has('适配机型')) {
        raasSpecs.push({ name: '适配机型', value: rm.compatible_model_names.join('、') })
      }
      if (rm && Array.isArray(rm.rental_plans) && rm.rental_plans.length && p.catalogKind === 'rent') {
        raasSpecs.push({ name: '租期方案', value: rm.rental_plans.map(rp => rp.name).join(' / ') })
      }
      if (rm && Array.isArray(rm.param_specs) && rm.param_specs.length) {
        for (const ps of rm.param_specs) {
          if (ps && ps.name && ps.value && !seenNames.has(ps.name)) {
            seenNames.add(ps.name)
            raasSpecs.push({ name: ps.name, value: ps.value })
          }
        }
      }
      return [...raasSpecs, ...baseSpecs]
    },
    // 适配机型:只展示机型名。不用 mapCatalogProduct —— 那套是为了取图片/价格的,
    // 这里只需要 name,直接映射更简单
    relatedList() {
      return this.relatedProducts.map(p => ({ id: p.id, name: p.name || '商品' }))
    },
    // 详情「服务/售后」区按板块取内容(租赁/购买/配件/技能);未知类型兜底用「购买」
    serviceInfo() {
      if (this.actionType === 'accessory') {
        const product = this.selectedProduct || {}
        const profile = product.accessoryProfile || null
        const official = !!product.official
        const items = []

        if (profile && profile.install_label) {
          items.push({ label: '安装', value: profile.install_label })
        }
        if (profile && profile.warranty_label) {
          items.push({ label: '质保', value: profile.warranty_label })
        }
        if (profile && profile.lead_time_label) {
          items.push({ label: '交期', value: profile.lead_time_label })
        }
        if (!items.length) {
          // 档案未补齐时不编造承诺，沿用“以商品页标注为准”的兜底
          return official
            ? { ...serviceConfigs.accessory, title: '官方自营・平台保障' }
            : serviceConfigs.accessory
        }

        return {
          title: official ? '官方自营・平台保障' : '平台严选・服务可追溯',
          items,
        }
      }
      return serviceConfigs[this.actionType] || serviceConfigs.buy
    },
    // 详情底部主按钮文案：定制件走咨询，不可售不伪装成可下单
    primaryActionLabel() {
      if (this.actionType === 'accessory') {
        if (this.selectedProduct && this.selectedProduct.quoteRequired) return '咨询定制'
        if (this.selectedProduct && this.selectedProduct.orderable === false) return '暂不可在线购买'
        return '立即购买'
      }
      if (this.actionType === 'rent') return '立即租用'
      if (this.actionType === 'skill') return '立即开通'
      return '立即购买'
    },
    primaryActionDisabled() {
      if (!this.selectedProduct) return true
      if (this.actionType !== 'accessory') return false
      return this.selectedProduct.orderable === false && !this.selectedProduct.quoteRequired
    },
    isFavorited() {
      const product = this.selectedProduct
      const productId = Number(product && (product.bagistoId || product.id))
      return productId > 0 && this.wishlistProductIds.includes(productId)
    },
    favoriteActionLabel() {
      return this.isFavorited ? '已收藏' : '收藏'
    },
  },
  watch: {
    // 没有真实图片、走图标占位的商品用固定框架高度。
    // 不处理的话会沿用上一件商品的图片比例 —— 同一个 🧩 占位框时大时小，看起来像 bug。
    detailImageList(list) {
      const hasRealImage = (list || []).some(item => typeof item === 'string' && item)
      if (!hasRealImage) this.detailImgHeight = DETAIL_IMG_DEFAULT_HEIGHT
    },
  },
  methods: {
    async refreshScenes() {
      this.scenesLoading = true; this.scenesError = '';
      try { await loadBackendScenes({ force: true }) }
      catch (e) { this.scenesError = e.message || '场景加载失败，请重试' }
      finally { this.sceneVersion++; this.scenesLoading = false }
    },
    applyTobFilters(list) {
      const q = (this.searchQ || '').trim().toLowerCase()
      return (list || []).filter(item => {
        if (!item) return false
        const matchType = this.currentType === 'all' || item.type === this.currentType
        const matchBrand = this.currentBrand === 'all' || item.brand === this.currentBrand
        if (!matchType || !matchBrand) return false
        if (!q) return true
        const haystack = [
          item.name,
          item.brandName,
          item.brandNameEn,
          item.brand,
          ...(Array.isArray(item.brandAliases) ? item.brandAliases : []),
          item.sku,
          item.typeLabel,
          item.plainDescription,
        ].filter(Boolean).join(' ').toLowerCase()
        return haystack.includes(q)
      })
    },
    setTobSubTab(sub) {
      if (!['all', 'rent', 'buy'].includes(sub)) return
      this.tobSubTab = sub
      this.showTypePicker = false
      this.showSortPanel = false
    },
    switchTab(tab) {
      this.showTypePicker = false   // 切 Tab 收起类别展开面板
      this.showSortPanel = false    // 切 Tab 收起排序菜单
      if (tab === 'rent' || tab === 'buy') {
        this.currentTab = 'tob'
        this.tobSubTab = tab
        this.currentType = 'all'
        this.currentBrand = 'all'
        this.currentSort = 'default'
        return
      }
      this.currentTab = tab
      if (tab === 'tob') {
        this.currentType = 'all'
        this.currentBrand = 'all'
        this.currentSort = 'default'
      } else if (tab === 'scene') {
        this.currentSceneCategory = 'all'
      }
    },
    selectType(type) { this.currentType = type },
    toggleSortPanel() {
      this.showSortPanel = !this.showSortPanel
      this.showTypePicker = false
    },
    pickSort(kind) {
      this.showSortPanel = false
      this.currentSort = kind
    },
    sortList(list) {
      const s = this.currentSort
      if (s === 'lowest') return [...list].sort((a, b) => this.priceNum(a) - this.priceNum(b))
      if (s === 'highest') return [...list].sort((a, b) => this.priceNum(b) - this.priceNum(a))
      if (s === 'rating_desc') {
        return [...list].sort((a, b) => ((b.rating || 0) - (a.rating || 0))
          || ((b.reviewCount || 0) - (a.reviewCount || 0)))
      }
      if (s === 'sales_desc') {
        return [...list].sort((a, b) => (b.salesCount || 0) - (a.salesCount || 0))
      }
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
    resetTobFilters() {
      this.currentBrand = 'all'
      this.currentType = 'all'
      this.searchQ = ''
      this.currentSort = 'default'
    },
    switchToSiblingTobProduct() {
      const sibling = this.siblingTobProduct
      if (!sibling) return
      if (sibling.catalogKind === 'rent' || sibling.catalogKind === 'buy') {
        this.tobSubTab = sibling.catalogKind
      }
      this.goDetail(sibling)
    },
    selectSceneCategory(category) { this.currentSceneCategory = category },

    // ===== 自定义方案：机器页多选 + 方案框 + 下单/保存 =====
    enterCustomPlanMode() {
      this.customPlanMode = true
      this.currentTab = 'tob'
      this.tobSubTab = 'rent'
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
      if (this.planMinDaysRequired > this.planDays) {
        this.planDays = this.planMinDaysRequired
      }
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
        const minPlanDays = current.reduce((mx, m) => Math.max(mx, Number((m.raasMeta && m.raasMeta.min_rent_days) || 1)), 1)
        if (this.planDays < minPlanDays) {
          this.planDays = minPlanDays
          uni.showToast({ title: '所选机型最低起租 ' + minPlanDays + ' 天，已自动调整租期', icon: 'none' })
          return
        }
        this.showCustomPlanModal = false
        // 多机合单直接进「确认订单」,不再走"提交需求"表单 —— 用户点的是"提交订单",
        // 落点就必须是标准多机租用下单页（不携带 scene/from=custom_plan 参数，避免被当成场景报价请求拦截）。
        const items = current.map(m => ({
          product_id: m.bagistoId || m.id,
          name: m.name,
          price: m.price,
          scene: m.scene || '机器租用',
          icon: m.icon || '🤖',
          bg: m.bg || '#F0F4FA',
          image: m.image || '',
        }))
        let url =
          `/pages/order/checkout?items=${encodeURIComponent(JSON.stringify(items))}` +
          `&rent_days=${encodeURIComponent(String(this.planDays))}` +
          `&type=rent` +
          `&rentPeriod=${encodeURIComponent(this.planDays + '天')}` +
          `&name=${encodeURIComponent('自定义方案（' + items.length + '台）')}` +
          `&price=${encodeURIComponent(String(this.planPerDay()))}`
        if (this.planStartDate) url += `&startDate=${encodeURIComponent(this.planStartDate)}`
        uni.navigateTo({ url })
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
      this.actionType = (item && (item.catalogKind === 'rent' || item.catalogKind === 'buy'))
        ? item.catalogKind
        : (this.currentTab === 'tob' ? (this.tobSubTab === 'buy' ? 'buy' : 'rent') : this.currentTab)
      this.detailTab = 'desc'
      this.showDetail = true
      this.loadRelatedProducts()
    },

    // 技能的「适配机型」:读后台关联商品关系(GET /api/products/{id}/related)。
    // 配件改用 accessory_fitments 的适配矩阵，不再走这里。
    // request.js 失败一律 reject 且不返回 task(没法 abort),所以用 _relatedSeq 比对来丢弃过期响应。
    async loadRelatedProducts() {
      const seq = ++_relatedSeq
      this.relatedProducts = []
      const item = this.selectedProduct
      if (this.actionType !== 'skill') return
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
        ['tob', 'rent', this.rentCatalog, 'detail'],
        ['tob', 'buy', this.buyCatalog, 'detail'],
        ['skill', null, this.skillCatalog, 'skill'],
        ['accessory', null, this.accessoryCatalog, 'accessory'],
      ]
      for (const [tab, subTab, list, kind] of buckets) {
        const hit = (list || []).find(x => Number(x.bagistoId || x.id) === Number(req.id))
        if (!hit) continue
        this.currentTab = tab
        if (subTab) this.tobSubTab = subTab
        this.fromAi = true   // 关闭详情时切回 AI 召唤页
        if (kind === 'detail') this.goDetail(hit)
        else if (kind === 'skill') this.onSkill(hit, false)
        else this.onAccessory(hit, false)
        return
      }
    },
    // 后端图片可能是 localhost / 相对路径；统一改写到 BASE_URL，真机与 H5 都能加载
    // ⚠ 必须留在 methods：写进 computed 会被当成无参 getter，this.normalizeImageUrl 变成
    //   非函数值，detailImageList 计算属性直接取不到值 → 详情轮播整块不渲染（2026-10-29 事故）。
    normalizeImageUrl(url) {
      let value = String(url || '')
      if (!value) return ''
      if (value.startsWith('/')) return BASE_URL + value
      // Bagisto 生成的 /cache/{size}/... 在部分部署下没有可访问路由；
      // 原图在 /storage/...，优先回退到原图，避免详情图裂。
      value = value.replace(/^https?:\/\/[^/]+\/cache\/(?:small|medium|large|original)\//, BASE_URL + '/storage/')
      return value.replace(/^https?:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?(?=\/)/, BASE_URL)
    },
    imageFailed(item) {
      if (!item) return
      const fallback = catalogFallbackImage(item.catalogKind || 'rent', item.id)
      if (item.image !== fallback) item.image = fallback
    },
    sceneImage(scene) {
      if (!scene) return catalogFallbackImage('scene', 0)
      if (this.brokenSceneImages[scene.id]) return catalogFallbackImage('scene', scene.id)
      return this.normalizeImageUrl(scene.banner) || catalogFallbackImage('scene', scene.id)
    },
    sceneImageFailed(scene) {
      if (!scene || this.brokenSceneImages[scene.id]) return
      this.$set(this.brokenSceneImages, scene.id, true)
    },
    // 详情顶部图：框架高度只按「图片原始宽高比」算（750rpx 宽 → 750 * 高/宽），
    // 整图完整显示，不裁切、不留白。
    // ⚠ 不要再用 boundingClientRect 回测渲染高度：图片是 mode="widthFix"，它自己的
    //   盒子高度本来就由宽高比决定，回测等于拿结果反推输入 —— 一旦 CSS 的 height
    //   真的生效（百分比/缩放），每次都按同一比例再乘一遍，连开几次就滚雪球。
    //   （2026-10-29 收口；同日事故是 normalizeImageUrl 写进 computed 导致轮播整块不渲染）
    onDetailImgLoad(e) {
      const src = (e && e.currentTarget && e.currentTarget.dataset && e.currentTarget.dataset.src) || ''
      // 只认当前商品图集里的图：详情换商品后，上一张图的迟到 load 不许再改框架高度
      if (src && this.detailImageList.indexOf(src) < 0) return

      const applySize = (width, height) => {
        if (!(width > 0) || !(height > 0)) return
        const rpx = Math.round(750 * height / width)
        // 夹紧兜底：脏数据（0 宽、极端长图/超宽图）既不能把框架压没，也不能撑爆一屏
        this.detailImgHeight = Math.min(Math.max(rpx, 240), 1500)
      }

      // 1) 小程序与 H5 的 load 事件都带原图宽高，最优先
      const detail = (e && e.detail) || {}
      if (detail.width && detail.height) return applySize(detail.width, detail.height)

      // 2) H5 兜底：直接读 <img> 的 natural 尺寸
      const el = e && e.target
      const native = el && el.tagName === 'IMG' ? el : (el && el.querySelector ? el.querySelector('img') : null)
      if (native && native.naturalWidth && native.naturalHeight) {
        return applySize(native.naturalWidth, native.naturalHeight)
      }

      // 3) 最后兜底：让运行时去取图片信息（异步，回来前先沿用当前高度）
      if (src) {
        uni.getImageInfo({
          src,
          success: info => {
            if (this.detailImageList.indexOf(src) < 0) return
            applySize(info.width, info.height)
          },
        })
      }
    },

    onAccessory(item, fromHome = false) {
      // 只允许真实后台商品进入配件详情/下单。没有 bagistoId 的数据（历史 mock、
      // 旧缓存、后台已删除商品）一律拦下，避免在结算页生成关联错误商品的订单。
      if (!item || !item.bagistoId) {
        uni.showToast({ title: '该配件暂不可在线购买', icon: 'none' })
        return
      }
      const accessoryDetail = {
        ...item,
        scene: item.categoryLabel || accessoryCategoryMap[item.category] || item.category || '配件',
        icon: item.icon || '🧩',
        bg: '#F0F4FA',
        accessoryFitments: [],
        accessoryProfile: null,
        accessorySpecs: [],
        compatibilityNote: '',
        orderable: item.orderable !== false,
        quoteRequired: !!item.custom_service,
      }
      this.selectedProduct = accessoryDetail
      this.actionType = 'accessory'
      this.detailTab = 'desc'
      this.showDetail = true
      this.fromHome = fromHome
      // 卡片数据先上屏，再用详情接口补齐适配矩阵 / 安装 / 质保 / 交期
      this.loadAccessoryDetail(Number(item.bagistoId))
    },

    // 配件详情：/api/accessory-catalog/{id}
    async loadAccessoryDetail(id) {
      const seq = ++_accessoryDetailSeq
      try {
        const res = await accessoryCatalogApi.detail(id)
        const d = res && res.data
        if (seq !== _accessoryDetailSeq || !d) return
        const current = this.selectedProduct
        // 在途期间详情已被换成别的商品 → 丢弃
        if (!current || Number(current.bagistoId) !== Number(id)) return

        this.selectedProduct = {
          ...current,
          id: d.id,
          bagistoId: d.id,
          name: d.name || current.name,
          price: d.price != null ? String(d.price) : current.price,
          bagistoPrice: d.price != null ? String(d.price) : current.bagistoPrice,
          originalPrice: d.compare_at_price ? String(d.compare_at_price) : '',
          image: Array.isArray(d.images) && d.images[0] ? this.normalizeImageUrl(d.images[0]) : current.image,
          images: Array.isArray(d.images) ? d.images.map(url => this.normalizeImageUrl(url)) : [],
          bagistoDescription: fixDescUrls(d.description_html || current.bagistoDescription || ''),
          category: d.category ? d.category.code : current.category,
          categoryLabel: d.category ? d.category.name : current.categoryLabel,
          scene: d.category ? d.category.name : current.scene,
          accessoryFitments: Array.isArray(d.fitments) ? d.fitments : [],
          accessoryProfile: d.profile || null,
          accessorySpecs: Array.isArray(d.specs) ? d.specs : [],
          compatibilityNote: d.compatibility_note || '',
          orderable: d.orderable !== false,
          quoteRequired: !!d.quote_required,
          official: !!d.official,
        }
      } catch (e) {
        // 静默降级：保留卡片已有信息，适配区块会提示“待补充”，不打断用户
      }
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
      // 清掉适配机型,并作废在途请求(关了浮层还可能有一个 related/accessory 请求在路上)
      this.relatedProducts = []
      _relatedSeq++
      _accessoryDetailSeq++
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
        // 定制件只走需求/报价，不在线建单；不可售商品也不伪装成可下单
        if (product.quoteRequired) {
          this.requestAccessoryQuote()
          return
        }
        if (product.orderable === false) {
          uni.showToast({ title: '该配件暂不可在线购买', icon: 'none' })
          return
        }
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
      // 配件必须有真实后台商品 id 才能结算：否则 mock/旧缓存的 id 会撞到真实商品，
      // 生成关联错误商品的订单。宁可不卖，也不能下错单。
      if (type === 'accessory' && (!product || !product.bagistoId)) {
        uni.showToast({ title: '该配件暂不可在线购买', icon: 'none' })
        return
      }
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
      const minDays = this.selectedProductMinDays
      const matchedDayIdx = this.rentPeriodOptions.findIndex(op => parseInt(op) >= minDays)
      this.rentForm.daysIndex = matchedDayIdx >= 0 ? matchedDayIdx : (this.rentPeriodOptions.length - 1)
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
    selectRentPeriod(i, op) {
      const days = parseInt(op) || 1
      if (days < this.selectedProductMinDays) {
        uni.showToast({ title: '该设备最低起租 ' + this.selectedProductMinDays + ' 天', icon: 'none' })
        return
      }
      this.rentForm.daysIndex = i
    },
    cancelRentModal() { this.showRentModal = false },
    confirmRent() {
      if (this.rentDays() < this.selectedProductMinDays) {
        uni.showToast({ title: '该设备最低起租 ' + this.selectedProductMinDays + ' 天', icon: 'none' })
        return
      }
      const product = this.selectedProduct
      const opts = {
        productType: (product && product.type) ? product.type : '',
        rentPeriod: this.rentPeriodOptions[this.rentForm.daysIndex],
        qty: this.rentForm.qty,
        startDate: this.rentForm.startDate,
      }
      this.showRentModal = false
      this.closeDetail()
      this.rentNow(product, opts)
    },

    refreshWishlist() {
      if (!uni.getStorageSync(TOKEN_KEY)) {
        this.wishlistProductIds = []
        return
      }
      wishlistApi.list()
        .then((res) => {
          const rows = res && Array.isArray(res.data) ? res.data : []
          this.wishlistProductIds = rows
            .map(row => Number(row && row.product && row.product.id))
            .filter(id => Number.isInteger(id) && id > 0)
        })
        .catch(() => {
          this.wishlistProductIds = []
        })
    },

    async toggleFavorite() {
      const product = this.selectedProduct
      if (!product || this.wishlistLoading) return
      if (!uni.getStorageSync(TOKEN_KEY)) {
        uni.showModal({
          title: '登录后收藏',
          content: '收藏会与账号绑定，登录后可查看已收藏的商品。',
          confirmText: '去登录',
          success: (res) => { if (res.confirm) uni.switchTab({ url: '/pages/mine/index' }) },
        })
        return
      }
      const productId = Number(product.bagistoId || product.id)
      if (!productId) {
        uni.showToast({ title: '该商品暂不可收藏', icon: 'none' })
        return
      }
      const wasFavorited = this.isFavorited
      this.wishlistLoading = true
      try {
        const res = await wishlistApi.toggle(productId)
        await this.refreshWishlist()
        uni.showToast({
          title: (res && res.message) || (wasFavorited ? '已取消收藏' : '已收藏'),
          icon: 'none',
        })
      } catch (e) {
        uni.showToast({ title: (e && e.message) || '收藏操作失败', icon: 'none' })
      } finally {
        this.wishlistLoading = false
      }
    },

    callService() {
      // 登录判断交给客服中心自己处理：游客也应先进入客服页，看到登录引导，
      // 不能在本页弹窗里把入口拦住。
      const product = this.selectedProduct || {}
      const typeText = { rent: '租赁', buy: '购买', accessory: '配件', skill: '技能' }[this.actionType] || '商品'
      const content = '咨询' + typeText + '「' + (product.name || '') + '」：'
      const price = product.bagistoPrice != null ? product.bagistoPrice : (product.price || '')
      const rawImage = product.image || (Array.isArray(product.images) && product.images[0]) || ''
      const productImage = /^(https?:\/\/|\/storage\/)/i.test(rawImage) ? rawImage : ''
      uni.setStorageSync('supportProductContext', {
        type: 'product',
        product_id: Number(product.bagistoId || product.id) || undefined,
        name: product.name || typeText,
        sku: product.sku || '',
        price: String(price || ''),
        image: productImage,
        kind: this.actionType,
      })
      uni.navigateTo({
        url: '/pages/mine/tickets?category=other&content=' + encodeURIComponent(content) + '&productContext=1',
      })
    },

    // 定制皮肤：复用页面已有的「企业需求」留资弹窗，由运营报价；不伪造固定价/固定交期
    requestAccessoryQuote() {
      const product = this.selectedProduct || {}
      const models = (product.accessoryFitments || [])
        .map(f => f.model_name)
        .filter(Boolean)
        .join('、')
      this.enterpriseForm = {
        mode: 'buy',
        identity: 'enterprise',
        name: '',
        phone: '',
        wechat: '',
        company: '',
        demand: `配件定制：${product.name || '配件'}${models ? '；目标机型：' + models : ''}；请确认图案/材质、工期与报价。`,
      }
      this.showEnterpriseModal = true
    },

    goSceneDetail(scene) {
      // 带上 category,供场景详情页判断是否为 TOB(企业商用)场景去拉后端真实图+描述
      uni.navigateTo({ url: `/pages/robot/fangan?sceneId=${scene.id}&category=${scene.category}` })
    },
    goEnterprise() {
      this.enterpriseForm = {
        mode: (this.currentTab === 'tob' && this.tobSubTab === 'buy') ? 'buy' : 'rent',
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
          _catalogRawCache = {
            rent: d.rent,
            buy: d.buy,
            accessory: d.accessory,
            skill: d.skill,
            brands: Array.isArray(d.brands) ? d.brands : null,
            robot_types: Array.isArray(d.robot_types) ? d.robot_types : null,
          }
          _catalogCachedAt = Date.now()
          this.applyCatalog(_catalogRawCache)
          this.catalogLoading = false
          return
        }
      } catch (e) { aggFailed = true /* 聚合端点不可用 → 回退旧多请求逻辑 */ }
      // 按分类区分:rent=租赁,buy=购买,accessory=配件,skill=技能(找不到则对应为空)
      const catIds = { rent: null, buy: null, accessory: null, skill: null }
      let catFailed = false
      try {
        const catRes = await categoryApi.getCategories({ limit: 200 })
        const cats = (catRes && Array.isArray(catRes.data)) ? catRes.data : []
        const pick = (slug, names) => {
          const list = Array.isArray(names) ? names : [names]
          const c = cats.find(x => x.slug === slug || list.includes(x.name))
          return c ? c.id : null
        }
        catIds.rent = pick('rent', ['租赁', '租用'])
        catIds.buy = pick('buy', '购买')
        catIds.accessory = pick('accessory', '配件')
        catIds.skill = pick('skill', '技能')
      } catch (e) { catFailed = true /* 分类拉取失败则全空 */ }

      const f = async (id) => id ? this.fetchProductsByCat(id) : Promise.resolve([])
      const brandPromise = (typeof brandApi !== 'undefined' && brandApi.list)
        ? brandApi.list().catch(() => null)
        : Promise.resolve(null)
      const [rentItems, buyItems, accItems, sklItems, brandRes] = await Promise.all([
        f(catIds.rent), f(catIds.buy), f(catIds.accessory), f(catIds.skill), brandPromise,
      ])
      const brandData = brandRes && brandRes.data ? brandRes.data : null
      _catalogRawCache = {
        rent: rentItems,
        buy: buyItems,
        accessory: accItems,
        skill: sklItems,
        brands: brandData && Array.isArray(brandData.brands) ? brandData.brands : null,
        robot_types: brandData && Array.isArray(brandData.robot_types) ? brandData.robot_types : null,
      }
      _catalogCachedAt = Date.now()
      this.applyCatalog(_catalogRawCache)
      this.catalogLoading = false
      // 两条来源都失败 = 服务不可用 —— 置错误态,不让空目录伪装成「没有找到匹配的机器人」
      if (aggFailed && catFailed) {
        this.catalogError = '目录加载失败'
      }
    },
    // 错误态「重试」:先作废缓存,再重拉 —— 否则 TTL 内会直接命中失败前的空缓存
    reloadCatalog() {
      _catalogRawCache = null
      _catalogCachedAt = 0
      return this.loadCatalog()
    },
    // 原始商品数组 → 四个目录 + 品牌/形态元数据
    applyCatalog(raw) {
      if (Array.isArray(raw.brands)) {
        this.backendBrands = raw.brands
      }
      if (Array.isArray(raw.robot_types) && raw.robot_types.length) {
        this.rentTypeOptions = raw.robot_types
      }
      this.rentCatalog = (raw.rent || []).map(p => this.mapCatalogProduct(p, 'rent'))
      this.buyCatalog = (raw.buy || []).map(p => this.mapCatalogProduct(p, 'buy'))
      this.accessoryCatalog = (raw.accessory || []).map(p => this.mapCatalogProduct(p, 'accessory'))
      this.skillCatalog = (raw.skill || []).map(p => this.mapCatalogProduct(p, 'skill'))
    },
    async fetchProductsByCat(categoryId) {
      try {
        const res = await productApi.getProducts({ limit: 200, page: 1, category_id: categoryId })
        return (res && Array.isArray(res.data)) ? res.data : []
      } catch (e) {
        return []
      }
    },
    mapCatalogProduct(p, catalogKind = 'rent') {
      const pricing = normalizeProductPricing(p)
      const images = pickGalleryImages(p).map(url => this.normalizeImageUrl(url)).filter(Boolean)
      const cover = this.normalizeImageUrl(pickBaseImage(p)) || images[0] || catalogFallbackImage(catalogKind, p.id)
      const iconMap = { rent: '🤖', buy: '🤖', accessory: '🧩', skill: '💡' }
      const resolvedBrand = p.brand && p.brand.code ? p.brand : null
      const activeBrands = this.brandList && this.brandList.length ? this.brandList : FALLBACK_BRANDS
      const brandCode = resolvedBrand ? resolvedBrand.code : detectBrandKey(p.name, activeBrands)
      const resolvedType = p.robot_type && p.robot_type.code ? p.robot_type : null
      const typeCode = resolvedType ? resolvedType.code : detectTypeKey(p.name)
      const typeOption = (this.rentTypeOptions || []).find(o => o.code === typeCode)
      const typeLabel = resolvedType ? resolvedType.label : (typeOption ? typeOption.label : '')
      const raasMeta = p.raas_meta && typeof p.raas_meta === 'object' ? p.raas_meta : null
      let raasBadge = ''
      if (raasMeta) {
        if (raasMeta.models_count > 0) {
          raasBadge = (raasMeta.active_models || raasMeta.models_count) + '款机型'
        } else if (Array.isArray(raasMeta.comm_channels) && raasMeta.comm_channels.length) {
          raasBadge = raasMeta.comm_channels.slice(0, 2).join('/')
        } else if (raasMeta.min_rent_days > 1 && catalogKind === 'rent') {
          raasBadge = raasMeta.min_rent_days + '天起租'
        }
      }
      const plainDescription = String(p.description || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()
      return {
        id: p.id,
        uniqueKey: catalogKind + '-' + p.id,
        name: p.name || '商品',
        price: pricing.price,
        // 只有真实 regular > final 才有原价;缺价时不再用现价 ×1.2 编造折扣
        originalPrice: pricing.originalPrice,
        icon: iconMap[catalogKind] || '🤖',
        bg: '#F0F4FA',
        scene: catalogKind === 'accessory' ? '配件' : (catalogKind === 'skill' ? '技能' : typeLabel),
        sku: p.sku || '',
        image: cover,
        images: images.length ? images : [cover],
        catalogKind,
        bagistoId: p.id,
        bagistoPrice: pricing.price,
        bagistoDescription: fixDescUrls(p.description || ''),
        bagistoAttributes: p.custom_attributes || null,
        plainDescription,
        // 后台 `official` 属性(boolean)透出为“是/否”；只有明确为“是”才按官方自营表述
        official: !!(p.custom_attributes && p.custom_attributes.official && String(p.custom_attributes.official.value) === '是'),
        brand: brandCode,
        brandName: resolvedBrand ? resolvedBrand.name : '',
        brandNameEn: resolvedBrand ? (resolvedBrand.name_en || '') : '',
        brandAliases: resolvedBrand && Array.isArray(resolvedBrand.aliases) ? resolvedBrand.aliases : [],
        type: typeCode,
        typeLabel,
        raasMeta,
        raasBadge,
        rating: Number((p.ratings && p.ratings.average) || 0),
        reviewCount: Number((p.reviews && p.reviews.total) || 0),
        salesCount: Number((p.sales && p.sales.total) || 0),
      }
    }
  },

  onShow() {
    this.refreshWishlist()
    this.$nextTick(() => { if (this.currentTab === 'skill' && this.$refs.skillMarket) this.$refs.skillMarket.refresh() })
    const tab = uni.getStorageSync('robotTab')
    if (tab && ['tob', 'rent', 'scene', 'buy', 'skill', 'accessory'].includes(tab)) {
      if (tab === 'rent' || tab === 'buy') {
        this.currentTab = 'tob'
        this.tobSubTab = tab
      } else if (tab === 'tob') {
        this.currentTab = 'tob'
        this.tobSubTab = 'all'
      } else {
        this.currentTab = tab
      }
      uni.removeStorageSync('robotTab')
    }

    const pendingBrand = uni.getStorageSync('robotBrand')
    if (pendingBrand) {
      this.currentTab = 'tob'
      this.currentBrand = String(pendingBrand)
      uni.removeStorageSync('robotBrand')
    }
    const pendingType = uni.getStorageSync('robotType')
    if (pendingType) {
      this.currentTab = 'tob'
      this.currentType = String(pendingType)
      uni.removeStorageSync('robotType')
    }

    const wishlistProduct = uni.getStorageSync('wishlistProductDetail')
    if (wishlistProduct && wishlistProduct.product) {
      uni.removeStorageSync('wishlistProductDetail')
      const kind = ['rent', 'buy', 'accessory', 'skill'].includes(wishlistProduct.catalog_type)
        ? wishlistProduct.catalog_type
        : 'buy'
      const mapped = this.mapCatalogProduct(wishlistProduct.product, kind)
      if (kind === 'rent' || kind === 'buy') {
        this.currentTab = 'tob'
        this.tobSubTab = kind
      } else {
        this.currentTab = kind
      }
      if (kind === 'accessory') this.onAccessory(mapped, false)
      else if (kind === 'skill') this.onSkill(mapped)
      else this.goDetail(mapped)
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
/* ===== 搜索框(租/购/场景) ===== */
.search-bar {
  padding: 12rpx 20rpx 10rpx;
  background: #ffffff;
  border-bottom: 1rpx solid #F1F5F9;
  flex-shrink: 0;
}
.search-row {
  display: flex;
  align-items: center;
  gap: 12rpx;
  height: 68rpx;
  background: #F4F7FB;
  border: 1rpx solid #E5ECF6;
  border-radius: 999rpx;
  padding: 0 22rpx;
  box-sizing: border-box;
}
.search-glass {
  position: relative;
  width: 26rpx;
  height: 26rpx;
  flex-shrink: 0;
}
.search-glass-circle {
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid #94A3B8;
  border-radius: 50%;
  box-sizing: border-box;
}
.search-glass-handle {
  position: absolute;
  right: 1rpx;
  bottom: 2rpx;
  width: 9rpx;
  height: 3rpx;
  background: #94A3B8;
  border-radius: 2rpx;
  transform: rotate(45deg);
}
.search-input {
  flex: 1;
  min-width: 0;
  height: 64rpx;
  background: transparent;
  border: none;
  outline: none;
  padding: 0;
  font-size: 25rpx;
  color: #1E293B;
}
.search-clear {
  font-size: 22rpx;
  color: #94A3B8;
  padding: 6rpx 8rpx;
  flex-shrink: 0;
}

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
/* ===== 顶部 Tab（四个主Tab：租售 | 场景 | 技能 | 配件） ===== */
.top-tabs {
  display: flex;
  background: #EEF4FF;
  padding: 88rpx 16rpx 0;
  flex-shrink: 0;
  /* 刻意不写 border-bottom：选中方块要与下方白色内容区连成一体，有线就割开了 */
}
.top-tabs .tab-item {
  position: relative;
  flex: 1;
  height: 86rpx;
  line-height: 86rpx;
  text-align: center;
  font-size: 29rpx;
  font-weight: 600;
  color: #475569;
  letter-spacing: 0.5rpx;
  white-space: nowrap;
  border-radius: 16rpx 16rpx 0 0;
  transition: background 0.2s ease, color 0.2s ease;
}
/* 激活态：整格铺白 + 底部主题蓝指示线 */
.top-tabs .tab-item.active {
  background: #FFFFFF;
  color: #102F6E;
  font-weight: 700;
}
.top-tabs .tab-item.active::after {
  content: '';
  position: absolute;
  left: 50%;
  bottom: 6rpx;
  transform: translateX(-50%);
  width: 44rpx;
  height: 6rpx;
  border-radius: 999rpx;
  background: #165DFF;
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
/* 分类选择：统一下拉芯片形态 */
.cat-group {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.cat-label {
  font-size: 24rpx;
  color: #6B7280;
  white-space: nowrap;
}
.cat-picker {
  display: inline-flex;
  align-items: center;
  gap: 8rpx;
  height: 52rpx;
  padding: 0 16rpx;
  background: #F8FAFC;
  border: 1rpx solid #E2E8F0;
  border-radius: 12rpx;
  font-size: 22rpx;
  color: #334155;
  box-sizing: border-box;
}
.cat-picker.active {
  background: #EEF4FF;
  border-color: #93C5FD;
  color: #165DFF;
  font-weight: 600;
}
.cat-text {
  max-width: 150rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.cat-arrow {
  font-size: 18rpx;
  color: #94A3B8;
}
.cat-picker.active .cat-arrow {
  color: #165DFF;
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
/* 租用 / 购买：综合排序下拉 */
.sort-picker-wrap {
  position: relative;
  flex-shrink: 0;
  z-index: 40;
}
.sort-picker {
  display: inline-flex;
  align-items: center;
  gap: 8rpx;
  height: 52rpx;
  padding: 0 16rpx;
  background: #F8FAFC;
  border: 1rpx solid #E2E8F0;
  border-radius: 12rpx;
  font-size: 22rpx;
  color: #334155;
  box-sizing: border-box;
}
.sort-picker.open,
.sort-picker.filtered {
  background: #EEF4FF;
  border-color: #93C5FD;
  color: #165DFF;
  font-weight: 600;
}
.sort-picker-text {
  max-width: 150rpx;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sort-picker-arrow { font-size: 18rpx; color: #94A3B8; }
.sort-picker.open .sort-picker-arrow,
.sort-picker.filtered .sort-picker-arrow { color: #165DFF; }
.sort-panel {
  position: absolute;
  top: calc(100% + 8rpx);
  left: 0;
  width: 220rpx;
  background: #FFFFFF;
  border: 1rpx solid #E7EAF0;
  border-radius: 16rpx;
  box-shadow: 0 12rpx 30rpx rgba(15, 23, 42, 0.14);
  overflow: hidden;
}
.sort-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
  padding: 20rpx 22rpx;
  font-size: 24rpx;
  color: #475569;
  border-bottom: 1rpx solid #F1F5F9;
}
.sort-option:last-child { border-bottom: 0; }
.sort-option.active { background: #EAF1FF; color: #165DFF; font-weight: 700; }
.sort-option-check { color: #165DFF; font-size: 22rpx; font-weight: 700; }

/* ===== 主体 ===== */
.main-body {
  display: flex;
  flex: 1;
  min-height: 0;
  overflow: hidden;
  z-index: 1;
  position: relative;
  /* 顶栏与内容之间只保留内容区自身的一条留白，不再额外叠加外边距 */
  margin-top: 0;
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
  width: 152rpx;
  background: #F7F9FC;
  border-right: 1rpx solid #EEF2F7;
  padding: 6rpx 0;
  flex-shrink: 0;
  height: 100%;
  box-sizing: border-box;
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
  padding: 20rpx 10rpx;
  border-left: 6rpx solid transparent;
  flex-shrink: 0;
  box-sizing: border-box;
}
.brand-item.active {
  background: #ffffff;
  border-left-color: #165DFF;
}
.brand-item .brand-name {
  font-size: 24rpx;
  line-height: 1.3;
  color: #475569;
  text-align: center;
  font-weight: 500;
  word-break: break-word;
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
  padding: 14rpx 14rpx 0 14rpx;
  box-sizing: border-box;
  transform: translateZ(0);
  will-change: scroll-position;
}
.product-flex-wrapper {
  display: flex;
  flex-wrap: wrap;
  gap: 14rpx;
  align-items: stretch;
}

/* ===== 租用/购买 卡片 ===== */
.product-card {
  width: calc(50% - 7rpx);
  margin-bottom: 0;
  background: #ffffff;
  border: 1rpx solid #EDF1F7;
  border-radius: 16rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  height: auto;
  box-sizing: border-box;
  box-shadow: 0 2rpx 10rpx rgba(15, 23, 42, 0.04);
}
.product-image {
  width: 100%;
  aspect-ratio: 1 / 1;
  background: #F8FAFC;
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
  padding: 12rpx 12rpx 14rpx;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  gap: 8rpx;
}
.product-name {
  font-size: 25rpx;
  font-weight: 600;
  line-height: 1.35;
  color: #1E293B;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}
.scene-tag {
  font-size: 18rpx;
  color: #475569;
  background: #F1F5F9;
  padding: 3rpx 10rpx;
  border-radius: 6rpx;
  line-height: 1.3;
  display: inline-block;
}
.product-bottom {
  margin-top: auto;
  padding-top: 4rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.product-price-wrap {
  display: flex;
  align-items: baseline;
  gap: 6rpx;
  flex-wrap: wrap;
}
.product-price {
  font-size: 30rpx;
  font-weight: 700;
  color: #FF6B00;
  line-height: 1.2;
}
.product-price .price-badge {
  font-size: 18rpx;
  font-weight: 600;
  color: #FF6B00;
  background: #FFF2E8;
  padding: 2rpx 6rpx;
  border-radius: 6rpx;
  margin-right: 4rpx;
}
.product-price .price-cur {
  font-size: 20rpx;
  font-weight: 600;
  color: #FF6B00;
}
.product-original {
  font-size: 19rpx;
  color: #94A3B8;
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
  width: 100%;
  background: linear-gradient(135deg, #F0F6FF 0%, #E6F0FF 100%);
  border-radius: 14rpx;
  padding: 14rpx 18rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border: 1rpx solid #C7DCFF;
  box-sizing: border-box;
  margin-bottom: 2rpx;
  flex-shrink: 0;
  min-height: 68rpx;
}
.banner-left {
  display: flex;
  align-items: center;
  gap: 10rpx;
  min-width: 0;
}
.banner-badge {
  font-size: 18rpx;
  font-weight: 600;
  color: #165DFF;
  background: #DCE8FF;
  padding: 3rpx 10rpx;
  border-radius: 6rpx;
  flex-shrink: 0;
}
.banner-title {
  font-size: 22rpx;
  font-weight: 600;
  color: #102F6E;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.banner-right {
  display: flex;
  align-items: center;
  flex-shrink: 0;
  margin-left: 12rpx;
}
.banner-phone {
  font-size: 18rpx;
  color: #6B7280;
}
.banner-btn {
  background: #165DFF;
  color: #fff;
  padding: 8rpx 18rpx;
  border-radius: 999rpx;
  font-size: 20rpx;
  font-weight: 600;
  text-align: center;
  line-height: 1.2;
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
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16rpx;
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
  /* 框架高度已按图片原始宽高比算好，这里只要铺满；不要写 103% 之类的放大值 ——
     H5 下 widthFix 的内联高度会覆盖它，小程序下却可能生效，造成上下各裁一刀 */
  height: 100%;
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
.adapt-note {
  display: block;
  margin-top: 12rpx;
  font-size: 22rpx;
  line-height: 1.6;
  color: #8A9AA8;
}
/* 配件适配矩阵：一行一个机型，等级用小标签 */
.adapt-row { width: 100%; margin-bottom: 14rpx; }
.adapt-row:last-child { margin-bottom: 0; }
.adapt-row-head { display: flex; align-items: center; gap: 10rpx; }
.adapt-row .adapt-name { margin: 0; }
.adapt-level {
  font-size: 20rpx;
  padding: 2rpx 12rpx;
  border-radius: 16rpx;
  background: #EEF2F6;
  color: #52607A;
}
.adapt-level.lv-direct { background: #E8F7F0; color: #0E7C5A; }
.adapt-level.lv-adapter { background: #FFF4E5; color: #B45309; }
.adapt-level.lv-custom { background: #F3E8FF; color: #7E22CE; }
.adapt-level.lv-unsupported { background: #FDECEC; color: #B91C1C; }
.adapt-detail { display: block; margin-top: 6rpx; font-size: 21rpx; line-height: 1.5; color: #8A9AA8; }
/* 定制款报价提示 */
.quote-note {
  margin: 0 30rpx 20rpx;
  background: #F8F5FF;
  border: 1rpx solid #E9DDFB;
  border-radius: 16rpx;
  padding: 20rpx 24rpx;
}
.quote-title { display: block; font-size: 26rpx; font-weight: 700; color: #6D28D9; margin-bottom: 8rpx; }
.quote-text { display: block; font-size: 22rpx; line-height: 1.6; color: #6B7280; }
.quote-link { display: block; margin-top: 10rpx; font-size: 24rpx; font-weight: 600; color: #6D28D9; }
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
.service-area-cover {
  padding: 0;
  background: transparent;
  overflow: hidden;
}
.service-cover-img {
  display: block;
  width: 100%;
  height: auto;
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
  padding: 18rpx 20rpx;
  /* #ifdef H5 */
  padding-bottom: 34rpx;
  /* #endif */
  /* #ifndef H5 */
  padding-bottom: calc(12rpx + env(safe-area-inset-bottom));
  /* #endif */
  border-top: 1rpx solid #E8EEF7;
  box-shadow: 0 -12rpx 34rpx rgba(16, 47, 110, 0.07);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18rpx;
  z-index: 10;
}
.bottom-assist {
  display: flex;
  align-items: center;
  gap: 16rpx;
  flex-shrink: 0;
}
.assist-btn {
  position: relative;
  width: 66rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 7rpx;
}
.assist-btn:active { opacity: 0.62; }
.assist-icon {
  position: relative;
  width: 42rpx;
  height: 42rpx;
  color: #165DFF;
}
.assist-label {
  font-size: 19rpx;
  line-height: 1.2;
  color: #516078;
  white-space: nowrap;
}
.service-band {
  position: absolute;
  left: 6rpx;
  top: 4rpx;
  width: 30rpx;
  height: 26rpx;
  border: 3rpx solid #165DFF;
  border-bottom: 0;
  border-radius: 18rpx 18rpx 0 0;
  box-sizing: border-box;
}
.service-band::after {
  content: '';
  position: absolute;
  left: 12rpx;
  bottom: -8rpx;
  width: 4rpx;
  height: 10rpx;
  border-radius: 4rpx;
  background: #165DFF;
}
.service-pad {
  position: absolute;
  top: 22rpx;
  width: 9rpx;
  height: 16rpx;
  border-radius: 5rpx;
  background: #165DFF;
}
.service-pad-left { left: 2rpx; }
.service-pad-right { right: 2rpx; }
.service-mic {
  position: absolute;
  left: 17rpx;
  bottom: 1rpx;
  width: 15rpx;
  height: 7rpx;
  border: 3rpx solid #165DFF;
  border-top: 0;
  border-radius: 0 0 10rpx 10rpx;
  box-sizing: border-box;
}
.bottom-right-btns {
  display: flex;
  flex: 1;
  justify-content: flex-end;
}
.bottom-btn {
  width: 220rpx;
  height: 112rpx;
  padding: 0 18rpx;
  border-radius: 20rpx;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  text-align: center;
  box-shadow: 0 10rpx 24rpx rgba(16, 47, 110, 0.09);
}
.bottom-btn.primary {
  background: linear-gradient(145deg, #165DFF 0%, #2E7BFF 100%);
  color: #fff;
  box-shadow: 0 12rpx 26rpx rgba(22, 93, 255, 0.26);
}
.bottom-btn.secondary {
  background: #F1F5FB;
  color: #102F6E;
  border: 1rpx solid #E1E9F6;
}
.favorite-btn.active {
  background: #FFF1F2;
  color: #E5484D;
  border-color: #FFD5D9;
}
.bottom-btn.disabled { opacity: 0.45; }
.bottom-btn:active { transform: scale(0.97); opacity: 0.78; }
.action-label {
  display: block;
  font-size: 30rpx;
  line-height: 1.2;
  letter-spacing: 0.2rpx;
  white-space: nowrap;
}
.commerce-actions {
  gap: 16rpx;
}
.commerce-actions .bottom-btn {
  flex: 1;
  width: auto;
  height: 96rpx;
  padding: 0 12rpx;
  border-radius: 14rpx;
}
.commerce-actions .bottom-btn.secondary {
  color: #165DFF;
  border-color: #D6E4FF;
  background: #F5F9FF;
}
.commerce-actions .favorite-btn.active {
  background: #FFF1F2;
  color: #E5484D;
  border-color: #FFD5D9;
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
.type-bar {
  display: flex;
  align-items: center;
  gap: 12rpx;
  padding: 8rpx 16rpx 12rpx;
  background: #FFFFFF;
  border-bottom: 1rpx solid #EEF2F7;
  position: relative;
  z-index: 40;
  flex-shrink: 0;
}
.type-bar .type-scroll { flex: 1; }
.custom-plan-fixed {
  flex-shrink: 0;
  margin-left: auto;
  margin-right: 0;
  height: 52rpx;
  padding: 0 20rpx;
  border-radius: 999rpx;
  background: #EFF6FF;
  border: 1rpx solid #BFDBFE;
  color: #165DFF;
  font-size: 22rpx;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}
.bar-reset {
  margin-left: auto;
  font-size: 22rpx;
  color: #64748B;
  padding: 6rpx 10rpx;
  flex-shrink: 0;
}
.type-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(15, 23, 42, 0.55); z-index: 1200; display: flex; align-items: center; justify-content: center; }
.type-modal { width: 640rpx; max-height: 70vh; background: #fff; border-radius: 24rpx; padding: 30rpx 28rpx; box-sizing: border-box; }
.type-modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24rpx; }
.type-modal-title { font-size: 32rpx; font-weight: 700; color: #1F2937; }
.type-modal-close { font-size: 30rpx; color: #94A3B8; padding: 6rpx 12rpx; }
.type-grid { display: flex; flex-wrap: wrap; gap: 20rpx; }
/* 类别内联展开：点「形态」后在筛选栏下方就地展开，把产品列表往下推 */
.type-inline {
  display: flex;
  flex-wrap: wrap;
  gap: 12rpx;
  margin: 10rpx 14rpx 6rpx;
  padding: 14rpx;
  background: #F8FAFC;
  border: 1rpx solid #E2E8F0;
  border-radius: 14rpx;
  box-sizing: border-box;
  flex-shrink: 0;
}
.type-inline .type-opt {
  width: calc(33.333% - 8rpx);
  padding: 12rpx 6rpx;
  font-size: 23rpx;
  border-radius: 10rpx;
  background: #FFFFFF;
  border: 1rpx solid #E8EDF5;
}
.type-opt { width: calc(33.33% - 14rpx); text-align: center; padding: 18rpx 0; background: #F1F5F9; border-radius: 14rpx; color: #475569; font-size: 28rpx; box-sizing: border-box; }
.type-opt.active { background: #EEF4FF; color: #165DFF; border-color: #93C5FD; font-weight: 600; }
.product-card { position: relative; }
.product-card.selected { border-color: #165DFF; box-shadow: 0 0 0 2rpx rgba(22, 93, 255, 0.35); }
/* 加号按钮:未选为淡灰底(不再用整片蓝抢视觉);选中才亮蓝,留状态反馈 */
.product-select-btn { position: absolute; top: 12rpx; right: 12rpx; width: 52rpx; height: 52rpx; border-radius: 50%; background: #E9EDF3; color: #6B7280; box-shadow: 0 2rpx 8rpx rgba(17, 24, 39, 0.08); display: flex; align-items: center; justify-content: center; font-size: 34rpx; line-height: 1; z-index: 2; }
.product-select-btn.on { background: #165DFF; color: #fff; box-shadow: none; }
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
.cart-item-price { font-size: 22rpx; color: #FF7820; margin-top: 4rpx; }
.cart-date-label { font-size: 24rpx; color: #64748B; flex-shrink: 0; }
.cart-date-end { font-size: 22rpx; color: #94A3B8; }
.scene-cart-row { display: flex; align-items: center; gap: 16rpx; }
.scene-cart-count { font-size: 26rpx; font-weight: 700; color: #1F2937; flex-shrink: 0; }
.scene-cart-days { display: flex; gap: 8rpx; flex: 1; flex-wrap: wrap; }
.day-chip { font-size: 22rpx; color: #475569; background: #F1F5F9; border-radius: 24rpx; padding: 6rpx 18rpx; }
.day-chip.active { background: #165DFF; color: #fff; }
/* 方案合计:说明文字退成中性灰,台数/天数/金额用价格橙,一眼能扫到数 */
.scene-cart-total { flex-shrink: 0; font-size: 26rpx; font-weight: 500; color: #64748B; }
.scene-cart-total .cart-num { font-size: 32rpx; font-weight: 700; color: #FF7820; }
.scene-cart-total .cart-sum { margin-left: 12rpx; font-size: 36rpx; font-weight: 700; color: #FF7820; }
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
.plan-item-total { font-size: 24rpx; color: #FF7820; font-weight: 600; }
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

/* ===== RaaS 品牌图标 / 租售子切换栏 / 卡片标签 ===== */
.brand-logo {
  width: 48rpx;
  height: 48rpx;
  border-radius: 10rpx;
  background: #FFFFFF;
  border: 1rpx solid #E2E8F0;
  margin-bottom: 6rpx;
}
.brand-label-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4rpx;
}
.brand-hot-dot {
  width: 10rpx;
  height: 10rpx;
  border-radius: 50%;
  background: #F59E0B;
  flex-shrink: 0;
}
.tob-toolbar-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12rpx;
  padding: 12rpx 16rpx 6rpx;
  background: #FFFFFF;
  flex-shrink: 0;
}
.tob-subbar {
  display: inline-flex;
  align-items: center;
  gap: 4rpx;
  padding: 4rpx;
  background: #F1F5F9;
  border-radius: 14rpx;
  flex-shrink: 0;
}
.tob-sub-pill {
  padding: 8rpx 20rpx;
  font-size: 23rpx;
  font-weight: 500;
  color: #475569;
  background: transparent;
  border-radius: 10rpx;
  line-height: 1.25;
}
.tob-sub-pill.active {
  color: #165DFF;
  background: #FFFFFF;
  font-weight: 700;
  box-shadow: 0 2rpx 6rpx rgba(15, 23, 42, 0.08);
}
.card-tags-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6rpx;
  margin-top: 0;
}
.mode-tag {
  font-size: 18rpx;
  font-weight: 600;
  padding: 3rpx 10rpx;
  border-radius: 6rpx;
  line-height: 1.3;
}
.mode-tag.rent {
  color: #165DFF;
  background: #EFF6FF;
}
.mode-tag.buy {
  color: #059669;
  background: #ECFDF5;
}
.raas-tag {
  font-size: 18rpx;
  color: #4338CA;
  background: #EEF2FF;
  padding: 3rpx 10rpx;
  border-radius: 6rpx;
  line-height: 1.3;
}
.price-unit {
  font-size: 19rpx;
  font-weight: 500;
  color: #6B7280;
  margin-left: 2rpx;
}
.active-filters-bar {
  width: 100%;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8rpx;
  margin-bottom: 2rpx;
}
.af-chip {
  display: inline-flex;
  align-items: center;
  gap: 6rpx;
  padding: 5rpx 14rpx;
  background: #EFF6FF;
  border: 1rpx solid #BFDBFE;
  border-radius: 999rpx;
  font-size: 21rpx;
  color: #1D4ED8;
}
.af-close { font-weight: 700; }
.af-reset {
  font-size: 21rpx;
  color: #64748B;
  margin-left: auto;
  padding: 4rpx 8rpx;
}
.sibling-switch-card {
  margin-top: 14rpx;
  padding: 14rpx 18rpx;
  background: #F8FAFC;
  border: 1rpx solid #E2E8F0;
  border-radius: 14rpx;
  display: flex;
  align-items: center;
  gap: 10rpx;
}
.sibling-switch-tag {
  font-size: 20rpx;
  font-weight: 600;
  color: #165DFF;
  background: #E8F0FE;
  padding: 4rpx 12rpx;
  border-radius: 999rpx;
  flex-shrink: 0;
}
.sibling-switch-text {
  flex: 1;
  font-size: 23rpx;
  color: #334155;
}
.sibling-switch-arrow {
  font-size: 24rpx;
  font-weight: 600;
  color: #165DFF;
  flex-shrink: 0;
}
.sheet-min-tip {
  margin: 6rpx 0 8rpx;
}
.sheet-min-tip-text {
  font-size: 22rpx;
  color: #D97706;
}
.days-chip.disabled {
  opacity: 0.42;
  background: #F1F5F9;
  color: #94A3B8;
}
.cart-min-note {
  font-size: 20rpx;
  color: #D97706;
  margin-left: 6rpx;
}
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
