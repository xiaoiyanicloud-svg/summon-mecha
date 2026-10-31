<template>
	<view class="mine-page">
		<!-- ============================================================ -->
		<!-- 主页面（我的） -->
		<!-- ============================================================ -->
		<view v-if="currentPage === 'main'" class="main-content">
			<!-- ===== 顶部用户信息（蓝白渐变） ===== -->
			<view class="user-header">
				<view class="user-row">
					<view class="user-left" @click="handleLogin">
						<view class="avatar-wrapper">
							<image class="avatar" src="/static/default-avatar.png" mode="aspectFill"></image>
						</view>
						<view class="user-info">
							<text class="user-name">{{ userName || '点击登录' }}</text>
							<text class="user-desc" v-if="userName">个人账户</text>
						</view>
					</view>
					<view class="user-right">
						<view class="vip-badge" @click="goVip">
							<text class="vip-icon">👑</text>
							<text class="vip-text">会员</text>
						</view>
					</view>
				</view>
			</view>

			<!-- ===== 第一模块：双卡片 ===== -->
			<view class="double-card">
				<view class="card-item" @click="goCoupon">
					<view class="card-icon">🎫</view>
					<view class="card-info">
						<text class="card-title">优惠券</text>
						<text class="card-count">暂未开放</text>
					</view>
					<text class="card-arrow">›</text>
				</view>
				<view class="card-item" @click="goInvoice">
					<view class="card-icon">🧾</view>
					<view class="card-info">
						<text class="card-title">开票中心</text>
						<text class="card-count">{{ invoiceCount }}笔</text>
					</view>
					<text class="card-arrow">›</text>
				</view>
			</view>

			<!-- ===== 第二模块：我的订单 ===== -->
			<view class="order-module">
				<view class="module-header">
					<text class="module-title">我的订单</text>
					<text class="module-more" @click="goAllOrders">查看全部 ›</text>
				</view>
				<view class="order-categories">
					<view class="order-item" @click="goOrderList('pending')">
						<text class="order-icon">⏳</text>
						<text class="order-label">待付款</text>
					</view>
					<view class="order-item" @click="goOrderList('confirm')">
						<text class="order-icon">📦</text>
						<text class="order-label">待发货</text>
					</view>
					<view class="order-item" @click="goOrderList('completed')">
						<text class="order-icon">✅</text>
						<text class="order-label">已完成</text>
					</view>
					<view class="order-item" @click="goAllOrders">
						<text class="order-icon">📋</text>
						<text class="order-label">全部</text>
					</view>
				</view>
			</view>

			<!-- ===== 第三模块：常用功能 ===== -->
			<view class="function-module">
				<view class="module-header">
					<text class="module-title">常用功能</text>
				</view>
				<view class="function-grid function-grid-primary">
					<view class="func-item" @click="goDevices">
						<text class="func-icon">🔧</text>
						<text class="func-label">我的设备</text>
					</view>
					<view class="func-item" @click="goSkills">
						<text class="func-icon">✨</text>
						<text class="func-label">我的技能</text>
					</view>
					<view class="func-item" @click="goSceneRequests">
						<text class="func-icon">📑</text>
						<text class="func-label">我的定制</text>
					</view>
					<view class="func-item" @click="goCustomerService">
						<text class="func-icon">💬</text>
						<text class="func-label">客服中心</text>
					</view>
					<view class="func-item" @click="goMyRegistrations">
						<text class="func-icon">🎟️</text>
						<text class="func-label">赛事</text>
					</view>
					<view class="func-item" @click="goAfterSales">
						<text class="func-icon">🧾</text>
						<text class="func-label">售后</text>
					</view>
					<view class="func-item" @click="goNotifications">
						<text class="func-icon">🔔</text>
						<text class="func-label">消息</text>
					</view>
					<view class="func-item" @click="goMyFavorites">
						<text class="func-icon">⭐</text>
						<text class="func-label">收藏</text>
					</view>
				</view>

				<view class="function-divider"></view>

				<view class="function-grid function-grid-partner">
					<view class="func-item" @click="goCooperate">
						<text class="func-icon">🤝</text>
						<text class="func-label">合作申请</text>
					</view>
					<view class="func-item" @click="goPartnerRecommend">
						<text class="func-icon">🌟</text>
						<text class="func-label">合伙人推荐</text>
					</view>
					<view class="func-item" @click="goSupplierCode">
						<text class="func-icon">🔑</text>
						<text class="func-label">供应商邀请</text>
					</view>
					<view class="func-item" @click="goDeveloperPortal">
						<text class="func-icon">🧑‍💻</text>
						<text class="func-label">开发者后台</text>
					</view>
					<view class="func-item" @click="goSettings">
						<text class="func-icon">⚙️</text>
						<text class="func-label">设置</text>
					</view>
				</view>
			</view>

			<!-- ===== 底部安全区 ===== -->
			<view class="bottom-safe"></view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：优惠券 -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'coupon'" class="sub-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">我的优惠券</text>
				<view class="nav-placeholder"></view>
			</view>
			<scroll-view class="sub-content" scroll-y="true" show-scrollbar="false">
				<view class="empty-state">
					<text class="empty-icon">🎫</text>
					<view class="empty-text">优惠券列表暂未开放</view>
					<view class="empty-hint">如持有优惠码，可在技能订单结算时填写。</view>
				</view>
				<view style="height: 40rpx;"></view>
			</scroll-view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：开票中心 -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'invoice'" class="sub-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">开票中心</text>
				<view class="nav-placeholder"></view>
			</view>
			<scroll-view class="sub-content" scroll-y="true" show-scrollbar="false">
				<view class="invoice-stats">
					<view class="stat-item">
						<text class="stat-num">{{ invoiceStats.total }}</text>
						<text class="stat-label">总发票</text>
					</view>
					<view class="stat-divider"></view>
					<view class="stat-item">
						<text class="stat-num" style="color:#f59e0b;">{{ invoiceStats.pending }}</text>
						<text class="stat-label">待开具</text>
					</view>
					<view class="stat-divider"></view>
					<view class="stat-item">
						<text class="stat-num" style="color:#22c55e;">{{ invoiceStats.done }}</text>
						<text class="stat-label">已开具</text>
					</view>
				</view>
				<view class="invoice-list">
					<!-- 真实开票登记(后端 /api/invoices)。此前这里是 5 张写死的 INV-2026-00x 假发票 -->
					<view class="invoice-card" v-for="item in invoiceList" :key="item.id">
						<view class="invoice-header">
							<text class="invoice-id">#{{ (item.order_numbers && item.order_numbers.length) ? item.order_numbers.join(' / ') : item.id }}</text>
							<text class="invoice-status" :class="item.status">{{ item.status_label }}</text>
						</view>
						<view class="invoice-body">
							<text class="invoice-amount">¥{{ item.amount }}</text>
							<text class="invoice-date">{{ item.created_at }}</text>
						</view>
						<view class="invoice-footer">
							<text class="invoice-type">{{ item.type_label }}{{ item.tax_no ? ' · ' + item.tax_no : '' }}</text>
							<view class="invoice-actions">
								<text class="action-btn" v-if="item.status === 'applied'" @tap="applyInvoice(item)">补开票信息</text>
								<text class="action-btn" v-else @tap="viewInvoice(item)">查看</text>
							</view>
						</view>
					</view>
					<view class="empty-state" v-if="!invoiceLoading && !invoiceError && invoiceList.length === 0">
						<text class="empty-icon">🧾</text>
						<text class="empty-text">暂无开票记录</text>
					</view>
					<view class="empty-state" v-if="invoiceLoading">
						<text class="empty-text">加载中…</text>
					</view>
					<view class="empty-state" v-if="invoiceError" @tap="loadInvoices">
						<text class="empty-text">加载失败：{{ invoiceError }}（点此重试）</text>
					</view>
				</view>
				<view class="btn-apply-invoice" @tap="openApplyInvoice">+ 申请开票</view>
				<view style="height: 40rpx;"></view>
			</scroll-view>

			<!-- 申请开票弹窗(B-15):选一笔已支付订单 + 填抬头/税号 -->
			<view v-if="showInvoiceModal" class="inv-mask" @tap="closeInvoiceModal">
				<view class="inv-modal" @tap.stop="noop">
					<text class="inv-modal-title">申请开票</text>
					<view class="inv-field">
						<text class="inv-field-label">选择订单</text>
						<picker mode="selector" :range="paidOrderLabels" @change="onPickOrder">
							<view class="inv-field-value">{{ pickedOrderLabel || (paidOrdersLoading ? '加载订单中…' : '请选择已支付订单') }}</view>
						</picker>
					</view>
					<view class="inv-field">
						<text class="inv-field-label">抬头类型</text>
						<view class="inv-type-row">
							<text class="inv-type" :class="{ on: invoiceForm.type === 'personal' }" @tap="invoiceForm.type = 'personal'">个人</text>
							<text class="inv-type" :class="{ on: invoiceForm.type === 'company' }" @tap="invoiceForm.type = 'company'">企业</text>
						</view>
					</view>
					<view class="inv-field">
						<text class="inv-field-label">发票抬头</text>
						<input class="inv-input" v-model="invoiceForm.title" placeholder="个人姓名 / 公司名称" />
					</view>
					<view class="inv-field" v-if="invoiceForm.type === 'company'">
						<text class="inv-field-label">税号</text>
						<input class="inv-input" v-model="invoiceForm.tax_no" placeholder="纳税人识别号" />
					</view>
					<view class="inv-modal-footer">
						<view class="inv-cancel" @tap="closeInvoiceModal">取消</view>
						<view class="inv-confirm" @tap="submitInvoice">{{ invoiceSubmitting ? '提交中…' : '提交' }}</view>
					</view>
				</view>
			</view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：地址管理 -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'address'" class="sub-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">地址管理</text>
				<view class="nav-placeholder"></view>
			</view>

			<view v-if="!showForm" class="address-list-page">
				<scroll-view class="sub-content" scroll-y="true" show-scrollbar="false">
					<!-- 三态:加载中 / 故障(带重试) / 真·暂无收货地址。故障不再伪装成「暂无地址」 -->
					<state-view
						:loading="addressLoading"
						:error="addressError"
						:empty="!addressList.length"
						empty-icon="📍"
						empty-text="暂无收货地址"
						empty-hint="点击下方按钮添加新地址"
						btn-color="#165DFF"
						@retry="loadAddressData"
					>
						<view class="address-card" v-for="(item, index) in addressList" :key="item.id">
							<view class="address-info">
								<view class="address-top">
									<text class="address-name">{{ item.name }}</text>
									<text class="address-phone">{{ item.phone }}</text>
									<text class="address-tag" v-if="item.isDefault">默认</text>
								</view>
								<text class="address-detail">{{ item.province }}{{ item.city }}{{ item.district }}{{ item.detail }}</text>
							</view>
							<view class="address-actions">
								<text class="action-edit" @tap="editAddress(item)">编辑</text>
								<text class="action-delete" @tap="deleteAddress(item.id)">删除</text>
							</view>
						</view>
					</state-view>
					<view style="height: 40rpx;"></view>
				</scroll-view>
				<view class="address-footer">
					<view class="btn-add-address" @tap="addAddress">+ 新增地址</view>
				</view>
			</view>

			<view v-else class="address-form-page">
				<scroll-view class="sub-content" scroll-y="true" show-scrollbar="false">
					<view class="location-btn-wrap" @tap="getLocationAndFill">
						<text class="location-icon">📍</text>
						<text class="location-text">{{ locating ? '定位中...' : '获取当前位置' }}</text>
					</view>
					<view class="form-group">
						<view class="form-item">
							<text class="form-label">收货人</text>
							<input class="form-input" type="text" v-model="formData.name" placeholder="请输入收货人姓名" />
						</view>
						<view class="form-item">
							<text class="form-label">手机号</text>
							<input class="form-input" type="text" v-model="formData.phone" placeholder="请输入手机号" />
						</view>
						<view class="form-item">
							<text class="form-label">省份</text>
							<input class="form-input" type="text" v-model="formData.province" placeholder="请输入省份" />
						</view>
						<view class="form-item">
							<text class="form-label">城市</text>
							<input class="form-input" type="text" v-model="formData.city" placeholder="请输入城市" />
						</view>
						<view class="form-item">
							<text class="form-label">区/县</text>
							<input class="form-input" type="text" v-model="formData.district" placeholder="请输入区/县" />
						</view>
						<view class="form-item">
							<text class="form-label">详细地址</text>
							<textarea class="form-textarea" v-model="formData.detail" placeholder="请输入详细地址" maxlength="100"></textarea>
						</view>
						<view class="form-item switch-item">
							<text class="form-label">设为默认</text>
							<switch :checked="formData.isDefault" @change="onSwitchChange" color="#4291F6" />
						</view>
					</view>
					<view class="form-actions">
						<view class="btn-cancel" @tap="cancelForm">取消</view>
						<view class="btn-save" @tap="saveAddress">保存</view>
					</view>
					<view style="height: 40rpx;"></view>
				</scroll-view>
			</view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：合作申请 -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'cooperate'" class="sub-page cooperate-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">合作申请</text>
				<view class="nav-placeholder"></view>
			</view>
			<scroll-view class="sub-content" scroll-y="true" show-scrollbar="false">
				<view class="cooperate-card" v-for="(item, index) in cooperateList" :key="index">
					<view class="card-header">
						<view class="header-left">
							<view class="avatar-icon" :style="{ background: item.avatarBg }">{{ item.avatar }}</view>
							<text class="cooperate-name">{{ item.name }}</text>
						</view>
						<view class="header-right">
							<view class="btn-apply" @tap="goApplyForm(item)">{{ item.btnText }}</view>
						</view>
					</view>
					<text class="cooperate-desc">{{ item.desc }}</text>
					<view class="benefit-list">
						<view class="benefit-item" v-for="(benefit, idx) in item.benefits" :key="idx">
							<text class="dot">•</text>
							<text class="benefit-text">{{ benefit }}</text>
						</view>
					</view>
				</view>

				<!-- 我的合作申请记录 -->
				<view class="coop-history-section">
					<view class="coop-history-header">
						<text class="coop-history-title">我的申请记录</text>
						<text class="coop-history-refresh" @tap="loadCooperations">刷新</text>
					</view>
					<view v-if="cooperateLoading" class="coop-history-empty">加载中...</view>
					<view v-else-if="cooperateApplications.length === 0" class="coop-history-empty">
						暂无合作申请记录，点击上方对应通道按钮即可提交申请
					</view>
					<view v-else class="coop-history-list">
						<view class="coop-record-card" v-for="app in cooperateApplications" :key="app.id">
							<view class="coop-record-top">
								<view class="coop-record-tags">
									<text class="coop-type-tag">{{ app.type_label }}</text>
									<text class="coop-dir-tag" v-if="app.coop_direction">{{ app.coop_direction }}</text>
									<text class="coop-verified-tag" v-if="app.invite_verified">✓ 已验码</text>
								</view>
								<text class="coop-status-badge" :class="'status-' + app.status">{{ app.status_label }}</text>
							</view>
							<text class="coop-record-org">{{ app.org_name }}</text>
							<view class="coop-record-meta">
								<text>单号：{{ app.application_no }}</text>
								<text>{{ app.created_at }}</text>
							</view>
							<view v-if="app.follower_name" class="coop-record-submeta">
								<text>跟进负责人：{{ app.follower_name }}{{ app.follower_contact ? '（' + app.follower_contact + '）' : '' }}</text>
							</view>
							<view v-if="app.issued_invite_code" class="coop-issued-code-box">
								<view class="coop-issued-code-main">
									<text class="coop-issued-label">🔑 平台已签发专属邀请码：</text>
									<text class="coop-issued-code">{{ app.issued_invite_code }}</text>
								</view>
								<view class="coop-issued-actions">
									<text class="coop-issued-btn" @tap="copyInviteCode(app.issued_invite_code)">复制邀请码</text>
									<text class="coop-issued-btn primary" @tap="goDeveloperPortalWithCode(app.issued_invite_code)">去门户激活 ›</text>
								</view>
							</view>
							<view v-if="app.admin_note" class="coop-record-note">
								<text class="coop-note-label">商务反馈：</text>
								<text class="coop-note-content">{{ app.admin_note }}</text>
							</view>
						</view>
					</view>
				</view>
				<view style="height: 40rpx;"></view>
			</scroll-view>

			<!-- 合作申请表单弹窗（本体厂商 / RaaS合伙人 / 网红达人） -->
			<view v-if="showCooperateModal && activeCoopItem" class="coop-modal-mask" @tap="closeCooperateModal">
				<view class="coop-modal-sheet" @tap.stop="noop">
					<view class="coop-modal-header">
						<view class="coop-modal-title-wrap">
							<text class="coop-modal-emoji">{{ activeCoopItem.avatar }}</text>
							<view>
								<text class="coop-modal-title">{{ activeCoopItem.name }} · 合作申请</text>
								<text class="coop-modal-subtitle">{{ activeCoopItem.desc }}</text>
							</view>
						</view>
						<text class="coop-modal-close" @tap="closeCooperateModal">✕</text>
					</view>

					<scroll-view class="coop-modal-body" scroll-y="true" show-scrollbar="false">
						<!-- RaaS合伙人快捷引导 -->
						<view v-if="activeCoopItem.key === 'raas'" class="coop-raas-banner" @tap="goDeveloperPortal">
							<view class="coop-raas-banner-left">
								<text class="coop-raas-banner-title">已有现成技能包？可直接入驻开发者门户</text>
								<text class="coop-raas-banner-desc">也可以填写下方表单，预约平台 RaaS 解决方案顾问对接</text>
							</view>
							<text class="coop-raas-banner-link">去门户 ›</text>
						</view>

						<!-- 1. 本体厂商专属字段 -->
						<template v-if="activeCoopItem.key === 'manufacturer'">
							<view class="coop-field">
								<text class="coop-label required">厂商/品牌名称</text>
								<input class="coop-input" type="text" v-model="cooperateForm.org_name" placeholder="请输入机器人本体企业或品牌名称" maxlength="120" />
							</view>
							<view class="coop-field">
								<text class="coop-label required">主营本体型号/产品线</text>
								<input class="coop-input" type="text" v-model="cooperateForm.robot_models" placeholder="如：双足人形机器人、四足机器狗、轮式服务机器人" maxlength="200" />
								<view class="quick-chip-row">
									<text class="quick-chip-label">快捷机型：</text>
									<text
										v-for="r in coopHotRobots"
										:key="r"
										class="quick-chip"
										@tap="appendCoopRobot(r)"
									>+ {{ r }}</text>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label required">合作方向</text>
								<view class="coop-chips">
									<view
										v-for="dir in activeCoopItem.directions"
										:key="dir"
										class="coop-chip"
										:class="{ active: cooperateForm.coop_direction === dir }"
										@tap="cooperateForm.coop_direction = dir"
									>{{ dir }}</view>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label">所在城市/地区</text>
								<input class="coop-input" type="text" v-model="cooperateForm.region" placeholder="例如：深圳 / 杭州 / 上海" maxlength="80" />
								<view class="quick-chip-row">
									<text class="quick-chip-label">热门城市：</text>
									<text
										v-for="c in coopHotCities"
										:key="c"
										class="quick-chip"
										@tap="fillCoopCity(c)"
									>+ {{ c }}</text>
								</view>
							</view>
						</template>

						<!-- 2. RaaS合伙人专属字段 -->
						<template v-else-if="activeCoopItem.key === 'raas'">
							<view class="coop-field">
								<text class="coop-label required">主体类型</text>
								<view class="coop-chips">
									<view
										class="coop-chip"
										:class="{ active: cooperateForm.entity_type === 'company' }"
										@tap="cooperateForm.entity_type = 'company'"
									>企业 / 工作室团队</view>
									<view
										class="coop-chip"
										:class="{ active: cooperateForm.entity_type === 'individual' }"
										@tap="cooperateForm.entity_type = 'individual'"
									>个人开发者</view>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label required">团队/主体名称</text>
								<input class="coop-input" type="text" v-model="cooperateForm.org_name" placeholder="请输入公司、工作室或开发者团队名称" maxlength="120" />
							</view>
							<view class="coop-field">
								<text class="coop-label required">擅长技术与合作方向</text>
								<view class="coop-chips">
									<view
										v-for="dir in activeCoopItem.directions"
										:key="dir"
										class="coop-chip"
										:class="{ active: cooperateForm.coop_direction === dir }"
										@tap="cooperateForm.coop_direction = dir"
									>{{ dir }}</view>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label required">适配目标机型</text>
								<input class="coop-input" type="text" v-model="cooperateForm.robot_models" placeholder="如：宇树 G1 / 宇树 Go2 / 智元远征 / 通用 ROS2" maxlength="200" />
								<view class="quick-chip-row">
									<text class="quick-chip-label">快捷机型：</text>
									<text
										v-for="r in coopHotRobots"
										:key="r"
										class="quick-chip"
										@tap="appendCoopRobot(r)"
									>+ {{ r }}</text>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label">代表作 / GitHub / 演示链接（选填）</text>
								<input class="coop-input" type="text" v-model="cooperateForm.portfolio_url" placeholder="https://github.com/... 或视频演示链接" maxlength="255" />
							</view>
							<view class="coop-field">
								<text class="coop-label">定向邀请码（选填）</text>
								<view class="coop-inline-verify">
									<input class="coop-input" type="text" v-model="cooperateForm.invite_code" placeholder="如有平台发放的邀请码请填写" maxlength="40" />
									<view class="coop-verify-btn" @tap="verifyModalInviteCode">核验</view>
								</view>
							</view>
						</template>

						<!-- 3. 网红达人专属字段 -->
						<template v-else-if="activeCoopItem.key === 'influencer'">
							<view class="coop-field">
								<text class="coop-label required">达人昵称 / MCN 机构</text>
								<input class="coop-input" type="text" v-model="cooperateForm.org_name" placeholder="请输入账号昵称或 MCN 机构名称" maxlength="120" />
							</view>
							<view class="coop-field">
								<text class="coop-label required">主营内容平台</text>
								<view class="coop-chips">
									<view
										v-for="plat in influencerPlatforms"
										:key="plat"
										class="coop-chip"
										:class="{ active: cooperateForm.platform === plat }"
										@tap="cooperateForm.platform = plat"
									>{{ plat }}</view>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label required">粉丝量级</text>
								<view class="coop-chips">
									<view
										v-for="tier in influencerFollowersTiers"
										:key="tier"
										class="coop-chip"
										:class="{ active: cooperateForm.followers_tier === tier }"
										@tap="cooperateForm.followers_tier = tier"
									>{{ tier }}</view>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label required">期望合作形式</text>
								<view class="coop-chips">
									<view
										v-for="dir in activeCoopItem.directions"
										:key="dir"
										class="coop-chip"
										:class="{ active: cooperateForm.coop_direction === dir }"
										@tap="cooperateForm.coop_direction = dir"
									>{{ dir }}</view>
								</view>
							</view>
							<view class="coop-field">
								<text class="coop-label">主页链接或平台账号 ID（选填）</text>
								<input class="coop-input" type="text" v-model="cooperateForm.account_url" placeholder="粘贴主页分享链接或平台号" maxlength="255" />
							</view>
							<view class="coop-field">
								<text class="coop-label">所在城市（选填）</text>
								<input class="coop-input" type="text" v-model="cooperateForm.region" placeholder="便于协调就近样机与拍摄场地" maxlength="80" />
							</view>
						</template>

						<!-- 公共联系方式与补充说明 -->
						<view class="coop-contact-header">
							<text class="coop-contact-title">商务联系人</text>
							<view class="btn-quick-autofill-sm" @tap="fillCurrentAccountToCoop">
								<text>⚡ 填入当前账号</text>
							</view>
						</view>

						<view class="coop-field-row">
							<view class="coop-field half">
								<text class="coop-label required">联系人姓名</text>
								<input class="coop-input" type="text" v-model="cooperateForm.contact_name" placeholder="您的称呼" maxlength="60" />
							</view>
							<view class="coop-field half">
								<text class="coop-label required">手机号码</text>
								<input class="coop-input font-mono" type="number" v-model="cooperateForm.contact_phone" placeholder="11 位手机号" maxlength="11" />
							</view>
						</view>

						<view class="coop-field">
							<text class="coop-label">微信号 / 联系邮箱（选填）</text>
							<input class="coop-input" type="text" v-model="cooperateForm.contact_wechat" placeholder="方便商务顾问添加微信或发送资料" maxlength="80" />
						</view>

						<view class="coop-field">
							<view class="coop-label-row">
								<text class="coop-label">合作意向与补充说明（选填）</text>
								<text class="char-count-pill">{{ (cooperateForm.summary || '').length }}/500</text>
							</view>
							<textarea
								class="coop-textarea"
								v-model="cooperateForm.summary"
								placeholder="请简述您的合作诉求、资源优势、期望档期或具体问题（上限 500 字）"
								maxlength="500"
							/>
						</view>
					</scroll-view>

					<view class="coop-modal-footer">
						<view class="coop-btn-cancel" @tap="closeCooperateModal">取消</view>
						<view class="coop-btn-submit" :class="{ disabled: cooperateSubmitting }" @tap="submitCooperateForm">
							{{ cooperateSubmitting ? '提交中...' : '提交合作申请' }}
						</view>
					</view>
				</view>
			</view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：合伙人推荐 -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'partner'" class="sub-page partner-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">用机器人就上召唤机甲</text>
				<view class="nav-placeholder"></view>
			</view>
			<scroll-view 
				class="sub-content" 
				scroll-y="true" 
				show-scrollbar="false"
				:style="{ paddingBottom: (safeBottom + 40) + 'px' }"
			>
				<view class="page-title">合伙人推荐</view>

				<view class="form-container">
					<!-- 被推荐的合伙人信息 -->
					<view class="form-section">
						<view class="section-title">被推荐的合伙人信息</view>

						<!-- 营业执照 -->
						<view class="form-item upload-item">
							<text class="form-label required">营业执照</text>
							<view class="upload-area" @tap="chooseLicense">
								<view class="upload-box">
									<text class="upload-icon">📁</text>
									<text class="upload-text">{{ licenseFileName || '选择文件上传' }}</text>
									<text class="upload-tip">支持 PNG / JPG / PDF，上限 10MB</text>
								</view>
							</view>
							<text class="upload-status" v-if="licenseFile">{{ licenseFile.name }} ({{ (licenseFile.size/1024).toFixed(1) }} KB)</text>
						</view>

						<!-- 企业名称 -->
						<view class="form-item">
							<text class="form-label required">被推荐人企业名称</text>
							<input class="form-input" type="text" v-model="partnerForm.companyName" placeholder="请输入企业名称" />
						</view>

						<!-- 意向签约级别 -->
						<view class="form-item">
							<text class="form-label required">意向签约级别</text>
							<picker mode="selector" :range="levelOptions" @change="onLevelChange">
								<view class="picker-input">{{ partnerForm.level || '请选择' }}</view>
							</picker>
						</view>

						<!-- 联系人姓名 -->
						<view class="form-item">
							<text class="form-label required">联系人姓名</text>
							<input class="form-input" type="text" v-model="partnerForm.contactName" placeholder="请输入联系人姓名" />
						</view>

						<!-- 联系人电话 -->
						<view class="form-item">
							<text class="form-label required">联系人电话</text>
							<input class="form-input" type="number" v-model="partnerForm.phone" placeholder="请输入被推荐企业的联系人手机号码" maxlength="11" />
						</view>

						<!-- 意向签约城市（输入框） -->
						<view class="form-item">
							<text class="form-label required">意向签约城市</text>
							<input class="form-input" type="text" v-model="partnerForm.region" placeholder="请输入省份-城市，如：北京市-北京市" />
							<view class="quick-chip-row" style="margin-top: 12rpx;">
								<text class="quick-chip-label">快捷城市：</text>
								<text
									v-for="c in partnerHotCities"
									:key="c"
									class="quick-chip"
									@tap="fillPartnerCity(c)"
								>+ {{ c }}</text>
							</view>
						</view>

						<!-- 情况简介 -->
						<view class="form-item textarea-item">
							<text class="form-label required">情况简介</text>
							<view class="textarea-wrapper">
								<textarea class="form-textarea" v-model="partnerForm.intro" placeholder="100字以内，可说明企业规模、主营业务" maxlength="100" @input="onIntroInput" />
								<view class="char-count">{{ partnerForm.intro.length }}/100</view>
							</view>
						</view>
					</view>

					<!-- 推荐人信息 -->
					<view class="form-section">
						<view class="section-title">推荐人信息</view>

						<!-- 推荐人类型 -->
						<view class="form-item">
							<text class="form-label required">推荐人类型</text>
							<radio-group class="radio-group" @change="onRecommenderTypeChange">
								<label class="radio-label">
									<radio value="sales" :checked="partnerForm.recommenderType === 'sales'" color="#4291F6" />
									我是销售合伙人
								</label>
								<label class="radio-label">
									<radio value="fulfill" :checked="partnerForm.recommenderType === 'fulfill'" color="#4291F6" />
									我是履约合伙人
								</label>
							</radio-group>
						</view>

						<!-- 邀请码 -->
						<view class="form-item">
							<text class="form-label required">{{ partnerForm.recommenderType === 'sales' ? '销售合伙人邀请码' : '履约合伙人邀请码' }}</text>
							<input class="form-input" type="text" v-model="partnerForm.inviteCode" :placeholder="partnerForm.recommenderType === 'sales' ? '请输入您的销售合伙人邀请码' : '请输入您的履约合伙人邀请码'" />
						</view>
					</view>

					<view class="form-actions">
						<view class="btn-submit" :class="{ disabled: !isFormValid }" @tap="submitForm">
							提交推荐
						</view>
					</view>
				</view>
			</scroll-view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：供应商邀请（供应链受邀入驻 & 邀请码核验闭环） -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'supplier'" class="sub-page supplier-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">供应链受邀入驻</text>
				<view class="nav-placeholder"></view>
			</view>
			<scroll-view 
				class="sub-content" 
				scroll-y="true" 
				show-scrollbar="false"
				:style="{ paddingBottom: (safeBottom + 40) + 'px' }"
			>
				<!-- 顶部供应链专属权益横幅 -->
				<view class="supplier-hero-card">
					<view class="supplier-hero-top">
						<view>
							<text class="supplier-hero-badge">SUPPLY CHAIN INVITATION</text>
							<text class="supplier-hero-title">召唤机甲 · 核心供应链邀约通道</text>
						</view>
						<text class="supplier-hero-icon">🔑</text>
					</view>
					<text class="supplier-hero-desc">
						面向机器人整机货源、核心零配件/机甲皮肤、城市驻场运维及 RaaS 技能供应商开放。持官方邀请码可即时核验归属并进入优先审核或直通门户激活；暂无邀请码亦可提交资质申请定向签发。
					</text>
					<view class="supplier-hero-tags">
						<text class="supplier-hero-tag">✓ 邀请码实时核验</text>
						<text class="supplier-hero-tag">✓ 专属采购与分成对接</text>
						<text class="supplier-hero-tag">✓ 审核通过在线签发邀请码</text>
					</view>
				</view>

				<!-- 模块 1：邀请码即时核验与直通激活器 -->
				<view class="supplier-section-card">
					<view class="supplier-section-header">
						<text class="supplier-section-title">1. 邀请码核验与直通激活</text>
						<text class="supplier-section-sub">支持 10 位平台定向邀请码实时校验</text>
					</view>
					<view class="supplier-verify-bar">
						<input
							class="supplier-verify-input"
							type="text"
							v-model="supplierVerifyCode"
							placeholder="请输入平台或商务发放的邀请码（如 8F3K9M2X7Q）"
							maxlength="40"
						/>
						<view class="supplier-verify-btn" @tap="verifySupplierInviteCode">立即核验</view>
					</view>

					<!-- 核验结果反馈卡 -->
					<view
						v-if="supplierVerifyResult"
						class="supplier-verify-result"
						:class="supplierVerifyResult.redeemable ? 'is-valid' : 'is-invalid'"
					>
						<view class="supplier-verify-result-top">
							<text class="supplier-verify-status">
								{{ supplierVerifyResult.redeemable ? '✓ 邀请码有效（可兑换）' : '✕ 邀请码不可用' }}
							</text>
							<text class="supplier-verify-code-pill">{{ supplierVerifyResult.code }}</text>
						</view>
						<template v-if="supplierVerifyResult.redeemable">
							<text class="supplier-verify-note">邀约备注：{{ supplierVerifyResult.note || '平台官方生态/供应链邀约' }}</text>
							<text class="supplier-verify-meta">
								剩余可用次数：{{ supplierVerifyResult.remaining_uses }} / {{ supplierVerifyResult.max_uses }}
								{{ supplierVerifyResult.expires_at ? (' · 有效期至 ' + supplierVerifyResult.expires_at.slice(0, 10)) : ' · 长期有效' }}
							</text>
							<view class="supplier-verify-actions">
								<view class="supplier-action-btn outline" @tap="useVerifiedCodeForSupplierForm">
									带入下方供应链入驻表
								</view>
								<view class="supplier-action-btn primary" @tap="goDeveloperPortalWithCode(supplierVerifyResult.code)">
									带码前往开发者门户 (/dev) ›
								</view>
							</view>
						</template>
						<template v-else>
							<text class="supplier-verify-reason">{{ supplierVerifyResult.reason }}</text>
							<text class="supplier-verify-fallback" @tap="switchSupplierEntryMode('apply_code')">
								没有可用邀请码？点击切换为「无码申请供应链邀约」由后台审核签发 ›
							</text>
						</template>
					</view>
				</view>

				<!-- 模块 2：供应链受邀入驻 / 申请邀约码表单 -->
				<view class="supplier-section-card">
					<view class="supplier-section-header">
						<view class="supplier-header-flex">
							<view>
								<text class="supplier-section-title">2. 供应链入驻建档与邀约申请</text>
								<text class="supplier-section-sub">提交后进入 Admin 运营台审核，通过后将在本页下发专属邀请码</text>
							</view>
							<view class="supplier-progress-badge" :class="{ ready: supplierProgress.isReady }">
								{{ supplierProgress.percent }}% 已完成
							</view>
						</view>
					</view>

					<!-- 实时填写进度提示条 -->
					<view class="supplier-progress-bar-wrap">
						<view class="supplier-progress-bar-track">
							<view class="supplier-progress-bar-fill" :style="{ width: supplierProgress.percent + '%' }"></view>
						</view>
						<text class="supplier-progress-hint">{{ supplierProgress.hint }}</text>
					</view>

					<!-- 模式切换 Tab -->
					<view class="supplier-mode-tabs">
						<view
							class="supplier-mode-tab"
							:class="{ active: supplierForm.entry_mode === 'with_code' }"
							@tap="switchSupplierEntryMode('with_code')"
						>
							<text class="mode-tab-title">持邀请码极速入驻</text>
							<text class="mode-tab-desc">已获商务/生态邀请码</text>
						</view>
						<view
							class="supplier-mode-tab"
							:class="{ active: supplierForm.entry_mode === 'apply_code' }"
							@tap="switchSupplierEntryMode('apply_code')"
						>
							<text class="mode-tab-title">无码申请供应链邀约</text>
							<text class="mode-tab-desc">提交资质由平台签发</text>
						</view>
					</view>

					<!-- 持码模式填写邀请码 -->
					<view class="coop-field form-focus-highlight" v-if="supplierForm.entry_mode === 'with_code'">
						<view class="coop-label-row">
							<text class="coop-label required">受邀入驻邀请码</text>
							<text class="coop-label-tip">输入 10 位邀请码验证有效性</text>
						</view>
						<view class="coop-inline-verify">
							<input
								class="coop-input font-mono"
								type="text"
								v-model="supplierForm.invite_code"
								placeholder="请输入 10 位邀请码（如 8F3K9M2X7Q）"
								maxlength="40"
							/>
							<view class="coop-verify-btn" @tap="verifyFormInviteCode">核验有效性</view>
						</view>
					</view>

					<!-- 分组一：企业主体与资质档案 -->
					<view class="form-fieldset-card">
						<view class="form-fieldset-header">
							<text class="form-fieldset-icon">🏢</text>
							<view>
								<text class="form-fieldset-title">企业主体与供应资质</text>
								<text class="form-fieldset-sub">请根据工商登记信息与核心经营范围如实填写</text>
							</view>
						</view>

						<!-- 供应品类方向 -->
						<view class="coop-field">
							<text class="coop-label required">核心供应品类</text>
							<view class="coop-chips">
								<view
									v-for="cat in supplierCategories"
									:key="cat"
									class="coop-chip"
									:class="{ active: supplierForm.coop_direction === cat }"
									@tap="supplierForm.coop_direction = cat"
								>{{ cat }}</view>
							</view>
						</view>

						<!-- 供应商企业名称 -->
						<view class="coop-field">
							<text class="coop-label required">供应商企业/机构全称</text>
							<input
								class="coop-input"
								type="text"
								v-model="supplierForm.org_name"
								placeholder="请输入营业执照上的公司全称，如：深圳灵巧智能科技有限公司"
								maxlength="120"
							/>
						</view>

						<!-- 统一社会信用代码 -->
						<view class="coop-field">
							<view class="coop-label-row">
								<text class="coop-label">统一社会信用代码（选填）</text>
								<text class="coop-label-tip">18 位大写字母与数字，有助于提速审核</text>
							</view>
							<input
								class="coop-input font-mono"
								type="text"
								v-model="supplierForm.credit_code"
								placeholder="如：91440300MA5XXXXXX1"
								maxlength="64"
								@input="supplierForm.credit_code = (supplierForm.credit_code || '').toUpperCase()"
							/>
						</view>

						<!-- 营业执照 / 资质证明凭证（优化支持上传选择） -->
						<view class="coop-field">
							<view class="coop-label-row">
								<text class="coop-label">营业执照 / 资质证明附件（选填）</text>
								<text class="coop-label-tip">支持上传营业执照或授权资质凭证</text>
							</view>
							<view class="supplier-license-uploader" @tap="chooseSupplierLicense">
								<view v-if="supplierLicenseFileName" class="supplier-license-picked">
									<text class="license-picked-icon">📄</text>
									<view class="license-picked-info">
										<text class="license-picked-name">{{ supplierLicenseFileName }}</text>
										<text class="license-picked-tip">已就绪，提交时随申请单留存</text>
									</view>
									<text class="license-picked-del" @tap.stop="removeSupplierLicense">移除</text>
								</view>
								<view v-else class="supplier-license-empty">
									<text class="license-upload-icon">📷</text>
									<text class="license-upload-text">点击拍摄或从相册选择营业执照/资质材料</text>
									<text class="license-upload-sub">支持 JPG / PNG 格式，仅用于企业入驻审核</text>
								</view>
							</view>
						</view>

						<!-- 仓储覆盖城市 + 快捷标签 -->
						<view class="coop-field">
							<text class="coop-label">仓储所在地 / 核心覆盖城市</text>
							<input
								class="coop-input"
								type="text"
								v-model="supplierForm.region"
								placeholder="如：深圳 / 苏州 / 杭州（支持全国发货）"
								maxlength="80"
							/>
							<view class="quick-chip-row">
								<text class="quick-chip-label">热门城市：</text>
								<text
									v-for="c in supplierHotCities"
									:key="c"
									class="quick-chip"
									@tap="fillSupplierCity(c)"
								>+ {{ c }}</text>
							</view>
						</view>
					</view>

					<!-- 分组二：供货产品、产能与履约质保 -->
					<view class="form-fieldset-card">
						<view class="form-fieldset-header">
							<text class="form-fieldset-icon">⚙️</text>
							<view>
								<text class="form-fieldset-title">供货清单与履约质保</text>
								<text class="form-fieldset-sub">明确核心优势机型、产能规模与售后支持标准</text>
							</view>
						</view>

						<!-- 主营供应产品与型号 + 快捷追加标签 -->
						<view class="coop-field">
							<text class="coop-label required">主营供应产品 / 适配机型 / 服务范围</text>
							<input
								class="coop-input"
								type="text"
								v-model="supplierForm.supply_items"
								placeholder="请输入主营产品，可点击下方常用项快捷填充"
								maxlength="200"
							/>
							<view class="quick-chip-block">
								<text class="quick-chip-label">快捷填充热门供应清单（点击加入）：</text>
								<view class="quick-chips-wrap">
									<text
										v-for="item in supplierQuickSupplyItems"
										:key="item"
										class="quick-chip"
										@tap="appendSupplierItem(item)"
									>+ {{ item }}</text>
								</view>
							</view>
						</view>

						<!-- 供货产能 / 覆盖量级 -->
						<view class="coop-field">
							<text class="coop-label required">月供货能力 / 服务覆盖量级</text>
							<view class="coop-chips">
								<view
									v-for="scale in supplierCapacityScales"
									:key="scale"
									class="coop-chip"
									:class="{ active: supplierForm.capacity_scale === scale }"
									@tap="supplierForm.capacity_scale = scale"
								>{{ scale }}</view>
							</view>
						</view>

						<!-- 质保与履约响应承诺 -->
						<view class="coop-field">
							<text class="coop-label required">履约与售后质保承诺</text>
							<view class="coop-chips">
								<view
									v-for="w in supplierWarrantyOptions"
									:key="w"
									class="coop-chip"
									:class="{ active: supplierForm.warranty_commitment === w }"
									@tap="supplierForm.warranty_commitment = w"
								>{{ w }}</view>
							</view>
						</view>

						<!-- 标杆案例与补充说明 -->
						<view class="coop-field">
							<view class="coop-label-row">
								<text class="coop-label">过往标杆案例与合作诉求（选填）</text>
								<text class="char-count-pill">{{ (supplierForm.summary || '').length }}/500</text>
							</view>
							<textarea
								class="coop-textarea"
								v-model="supplierForm.summary"
								placeholder="可简述合作品牌客户、账期结算支持、权威检测报告、专利资质或具体合作诉求（上限 500 字）"
								maxlength="500"
							/>
						</view>
					</view>

					<!-- 分组三：商务对接人联络 -->
					<view class="form-fieldset-card">
						<view class="form-fieldset-header justify-between">
							<view class="flex items-center gap-2">
								<text class="form-fieldset-icon">👤</text>
								<view>
									<text class="form-fieldset-title">商务对接负责人</text>
									<text class="form-fieldset-sub">采购对接人与签约顾问将优先通过此信息联络</text>
								</view>
							</view>
							<view class="btn-quick-autofill" @tap="fillCurrentAccountToSupplier">
								<text class="autofill-icon">⚡</text>
								<text>填入当前账号</text>
							</view>
						</view>

						<view class="coop-field-row">
							<view class="coop-field half">
								<text class="coop-label required">负责人姓名</text>
								<input
									class="coop-input"
									type="text"
									v-model="supplierForm.contact_name"
									placeholder="您的称呼（如：王总监）"
									maxlength="60"
								/>
							</view>
							<view class="coop-field half">
								<text class="coop-label required">联系手机号</text>
								<input
									class="coop-input font-mono"
									type="number"
									v-model="supplierForm.contact_phone"
									placeholder="11 位手机号码"
									maxlength="11"
								/>
							</view>
						</view>

						<view class="coop-field">
							<view class="coop-label-row">
								<text class="coop-label">微信号 / 商务邮箱（选填）</text>
								<text class="coop-label-tip">用于发送采购框架协议与产品询价单</text>
							</view>
							<input
								class="coop-input"
								type="text"
								v-model="supplierForm.contact_wechat"
								placeholder="便于供应链运营添加微信或发送采购合同"
								maxlength="80"
							/>
						</view>
					</view>

					<view class="supplier-submit-wrap">
						<view
							class="supplier-submit-btn"
							:class="{ disabled: supplierSubmitting || !supplierProgress.isReady }"
							@tap="submitSupplierForm"
						>
							<template v-if="supplierSubmitting">正在提交申请中...</template>
							<template v-else-if="supplierProgress.isReady">
								{{ supplierForm.entry_mode === 'with_code' ? '✓ 核验邀请码并提交供应链建档' : '✓ 提交资质并申请专属邀请码' }}
							</template>
							<template v-else>
								请完善必填信息（还差 {{ supplierProgress.missingCount }} 项未填）
							</template>
						</view>
						<text class="supplier-submit-privacy-tip">
							🔒 平台严格保护供应商企业及商业信息安全，仅用于本次生态供应链准入与商务对接。
						</text>
					</view>
				</view>

				<!-- 模块 3：我的供应链入驻与合作申请进度（含后台签发邀请码卡片） -->
				<view class="coop-history-section">
					<view class="coop-history-header">
						<text class="coop-history-title">我的供应链入驻与邀约进度</text>
						<text class="coop-history-refresh" @tap="loadCooperations">刷新状态</text>
					</view>
					<view v-if="cooperateLoading" class="coop-history-empty">加载中...</view>
					<view v-else-if="supplierApplications.length === 0" class="coop-history-empty">
						暂无供应链入驻记录。提交上方表单后，可在此实时追踪审核进度并领取平台签发的专属邀请码。
					</view>
					<view v-else class="coop-history-list">
						<view class="coop-record-card" v-for="app in supplierApplications" :key="app.id">
							<view class="coop-record-top">
								<view class="coop-record-tags">
									<text class="coop-type-tag">{{ app.type_label }}</text>
									<text class="coop-dir-tag" v-if="app.coop_direction">{{ app.coop_direction }}</text>
									<text class="coop-verified-tag" v-if="app.invite_verified">✓ 持有效码入驻</text>
								</view>
								<text class="coop-status-badge" :class="'status-' + app.status">{{ app.status_label }}</text>
							</view>
							<text class="coop-record-org">{{ app.org_name }}</text>
							<view class="coop-record-meta">
								<text>单号：{{ app.application_no }}</text>
								<text>{{ app.created_at }}</text>
							</view>
							<view v-if="app.follower_name" class="coop-record-submeta">
								<text>跟进负责人：{{ app.follower_name }}{{ app.follower_contact ? '（' + app.follower_contact + '）' : '' }}</text>
							</view>
							<view v-if="app.invite_code" class="coop-record-submeta">
								<text>申报邀请码：{{ app.invite_code }}（{{ app.invite_verified ? '已通过平台核验' : '待核验' }}）</text>
							</view>
							<!-- 后台运营签发的专属邀请码展示与一键激活 -->
							<view v-if="app.issued_invite_code" class="coop-issued-code-box">
								<view class="coop-issued-code-main">
									<text class="coop-issued-label">🎉 平台已为您签发专属邀请码：</text>
									<text class="coop-issued-code">{{ app.issued_invite_code }}</text>
									<text class="coop-issued-exp" v-if="app.issued_invite_expires_at">有效期：{{ app.issued_invite_expires_at }}</text>
								</view>
								<view class="coop-issued-actions">
									<text class="coop-issued-btn" @tap="copyInviteCode(app.issued_invite_code)">复制邀请码</text>
									<text class="coop-issued-btn primary" @tap="goDeveloperPortalWithCode(app.issued_invite_code)">去门户激活 ›</text>
								</view>
							</view>
							<view v-if="app.admin_note" class="coop-record-note">
								<text class="coop-note-label">供应链审核反馈：</text>
								<text class="coop-note-content">{{ app.admin_note }}</text>
							</view>
						</view>
					</view>
				</view>
				<view style="height: 40rpx;"></view>
			</scroll-view>
		</view>

		<!-- ============================================================ -->
		<!-- 子页面：设置 -->
		<!-- ============================================================ -->
		<view v-else-if="currentPage === 'settings'" class="sub-page settings-page">
			<view class="sub-nav">
				<view class="nav-back" @click="goBack">
					<text class="back-icon">‹</text>
					<text class="back-text">返回</text>
				</view>
				<text class="nav-title">设置</text>
				<view class="nav-placeholder"></view>
			</view>
			<scroll-view class="sub-content" scroll-y="true" show-scrollbar="false">
				<!-- 个人信息卡片 -->
				<view class="settings-card">
					<view class="settings-item" @click="editProfile">
						<view class="settings-left">
							<view class="settings-avatar">
								<image class="avatar-img" src="/static/default-avatar.png" mode="aspectFill"></image>
							</view>
							<view class="settings-user">
								<text class="settings-name">{{ userName || '点击登录' }}</text>
								<text class="settings-phone">{{ customerPhone }}</text>
							</view>
						</view>
						<view class="settings-right">
							<text class="settings-arrow">›</text>
						</view>
					</view>
					<view class="settings-divider"></view>
					<view class="settings-item" @click="editProfile">
						<view class="settings-left">
							<text class="settings-icon">👤</text>
							<text class="settings-label">个人信息</text>
						</view>
						<view class="settings-right">
							<text class="settings-arrow">›</text>
						</view>
					</view>
				</view>

				<!-- 条款 / 关于。⚠ 正文不在这几页里写死 ——
				     隐私政策与用户协议读的是平台后台 CMS,与网页版同源 -->
				<view class="settings-card">
					<view class="settings-item" @click="goPrivacyPolicy">
						<view class="settings-left">
							<text class="settings-icon">🔒</text>
							<text class="settings-label">隐私政策</text>
						</view>
						<view class="settings-right">
							<text class="settings-arrow">›</text>
						</view>
					</view>
					<view class="settings-divider"></view>
					<view class="settings-item" @click="goUserAgreement">
						<view class="settings-left">
							<text class="settings-icon">📄</text>
							<text class="settings-label">用户协议</text>
						</view>
						<view class="settings-right">
							<text class="settings-arrow">›</text>
						</view>
					</view>
					<view class="settings-divider"></view>
					<view class="settings-item" @click="goAbout">
						<view class="settings-left">
							<text class="settings-icon">ℹ️</text>
							<text class="settings-label">关于</text>
						</view>
						<view class="settings-right">
							<text class="settings-arrow">›</text>
						</view>
					</view>
				</view>

				<!-- 退出登录 -->
				<view class="settings-card">
					<view class="settings-item logout-item" @click="handleLogout">
						<view class="settings-left">
							<text class="settings-icon logout-icon">🚪</text>
							<text class="settings-label logout-label">退出登录</text>
						</view>
						<view class="settings-right">
							<text class="settings-arrow">›</text>
						</view>
					</view>
				</view>

				<!-- 版本信息 -->
				<view class="settings-version">{{ appVersion }}</view>

				<view style="height: 40rpx;"></view>
			</scroll-view>
		</view>

		<!-- ============================================================ -->
		<!-- 🔥 自定义底部导航栏（仅主页面显示，子页面隐藏） -->
		<!-- ============================================================ -->
		<CustomTabBar v-if="currentPage === 'main'" currentPage="/pages/mine/index" />

		<!-- ===== 登录/注册弹窗 ===== -->
		<view class="login-modal-mask" v-if="showLoginModal" @tap="closeLoginModal">
			<view class="login-modal" @tap.stop>
				<view class="login-modal-header">
					<text class="login-modal-title">{{ authMode === 'login' ? '登录 · 召唤机甲' : '注册 · 召唤机甲' }}</text>
					<text class="login-modal-sub">{{ authMode === 'login' ? '登录后可购买商品并查看订单' : '注册成功后将自动登录' }}</text>
				</view>
				<view class="auth-mode-tabs">
					<view class="auth-mode-tab" :class="{ active: authMode === 'login' }" @tap="switchAuthMode('login')">登录</view>
					<view class="auth-mode-tab" :class="{ active: authMode === 'register' }" @tap="switchAuthMode('register')">注册</view>
				</view>
				<!-- 登录表单 -->
				<view class="login-form" v-if="authMode === 'login'">
					<view class="login-input-wrap">
						<text class="login-input-icon">📧</text>
						<input class="login-input" type="text" v-model="loginForm.email" placeholder="请输入邮箱" />
					</view>
					<view class="login-input-wrap">
						<text class="login-input-icon">🔒</text>
						<input class="login-input" type="password" v-model="loginForm.password" placeholder="请输入密码" />
					</view>
				</view>
				<!-- 注册表单 -->
				<view class="login-form" v-if="authMode === 'register'">
					<view class="login-input-wrap">
						<text class="login-input-icon">👤</text>
						<input class="login-input" type="text" v-model="registerForm.name" placeholder="姓名 / 昵称" />
					</view>
					<view class="login-input-wrap">
						<text class="login-input-icon">📧</text>
						<input class="login-input" type="text" v-model="registerForm.email" placeholder="请输入邮箱" />
					</view>
					<view class="login-input-wrap">
						<text class="login-input-icon">🔒</text>
						<input class="login-input" type="password" v-model="registerForm.password" placeholder="设置密码（至少 6 位）" />
					</view>
					<view class="login-input-wrap">
						<text class="login-input-icon">🔒</text>
						<input class="login-input" type="password" v-model="registerForm.confirmPassword" placeholder="确认密码" />
					</view>
				</view>
				<view class="login-actions">
					<view class="login-btn-secondary" @tap="closeLoginModal">取消</view>
					<view class="login-btn-primary" @tap="authMode === 'login' ? submitLogin() : submitRegister()">{{ authMode === 'login' ? '登录' : '注册' }}</view>
				</view>
				<view class="login-tip-wrap">
					<text class="login-tip" @tap="switchAuthMode(authMode === 'login' ? 'register' : 'login')">{{ authMode === 'login' ? '还没有账号？去注册' : '已有账号？去登录' }}</text>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import CustomTabBar from '@/components/CustomTabBar.vue';
	import StateView from '@/components/StateView.vue';
	import { authApi, addressApi, invoiceApi, orderApi, cooperationApi } from '@/utils/api';
	import { TOKEN_KEY, CUSTOMER_KEY, APP_VERSION, BASE_URL } from '@/utils/config';
	import { serverToLocal, localToServerPayload } from '@/utils/address';

	export default {
		components: {
			CustomTabBar,
			StateView
		},
		data() {
			return {
				// ===== 主页面数据 =====
				currentPage: 'main',
				// 版本号只有一处出处(utils/config.js),设置页与「关于」页共用
				appVersion: APP_VERSION,
				userName: '',
				customerPhone: '未绑定手机',
				showLoginModal: false,
				authMode: 'login', // 'login' | 'register'
				loginForm: { email: '', password: '' },
				registerForm: { name: '', email: '', password: '', confirmPassword: '' },
				// invoiceCount 改为 computed(取自真实 invoiceList.length)——不再写死 "3笔"

				// ===== 开票中心数据(B-15:全部来自后端 /api/invoices,不再写死假发票) =====
				invoiceList: [],
				invoiceLoading: false,
				invoiceError: '',
				// 申请开票弹窗
				showInvoiceModal: false,
				invoiceSubmitting: false,
				invoiceForm: { order_id: '', type: 'personal', title: '', tax_no: '' },
				paidOrders: [],
				paidOrdersLoading: false,

				// ===== 地址管理数据 =====
				addressList: [],
				addressLoading: false,
				addressError: '',
				showForm: false,
				editingId: null,
				formData: {
					name: '',
					phone: '',
					province: '',
					city: '',
					district: '',
					detail: '',
					isDefault: false,
				},
				locating: false,
				mapKey: 'YOUR_TENCENT_MAP_KEY',

				// ===== 合作申请数据 =====
				cooperateList: [
					{
						key: 'manufacturer',
						name: '本体厂商',
						avatar: '🏭',
						avatarBg: '#E8F4FD',
						btnText: '洽谈合作',
						desc: '制造 / 渠道 / 技术协同',
						benefits: ['品牌联合推广', '渠道资源共享', '技术方案协同'],
						directions: ['品牌联合推广', '租赁与销售渠道共建', '技能SDK与生态适配', '赛事与展会联合冠名'],
						formPath: '/pages/cooperate/manufacturer'
					},
					{
						key: 'raas',
						name: 'RaaS合伙人',
						avatar: '🤝',
						avatarBg: '#E8F4FD',
						btnText: '立即加入',
						desc: '技能售卖 / 分成 / 技术社区',
						benefits: ['技能服务上架', '高额分成收益', '社区技术交流'],
						directions: ['动作捕捉与重定向', 'ROS2与运控算法', '视觉与多模态交互', '场景全栈解决方案'],
						formPath: '/pages/cooperate/raas'
					},
					{
						key: 'influencer',
						name: '网红达人',
						avatar: '📱',
						avatarBg: '#E8F4FD',
						btnText: '演出合作',
						desc: '免费用机 / 商单 / 流量扶持',
						benefits: ['免费用机体验', '品牌商单合作', '平台流量扶持'],
						directions: ['免费用机评测', '商业演出合拍', '赛事探馆直播', '带货分佣合作'],
						formPath: '/pages/cooperate/influencer'
					}
				],
				influencerPlatforms: ['抖音', '小红书', 'B站', '视频号', '快手'],
				influencerFollowersTiers: ['1万-10万', '10万-50万', '50万-100万', '100万+'],
				showCooperateModal: false,
				activeCoopItem: null,
				cooperateSubmitting: false,
				cooperateLoading: false,
				cooperateApplications: [],
				cooperateForm: {
					coop_type: '',
					org_name: '',
					contact_name: '',
					contact_phone: '',
					contact_wechat: '',
					region: '',
					coop_direction: '',
					summary: '',
					robot_models: '',
					entity_type: 'company',
					portfolio_url: '',
					invite_code: '',
					platform: '抖音',
					followers_tier: '10万-50万',
					account_url: '',
				},
				partnerSubmitting: false,

				// ===== 供应链受邀入驻数据 =====
				supplierVerifyCode: '',
				supplierVerifyResult: null,
				supplierSubmitting: false,
				supplierLicenseFile: null,
				supplierLicenseFileName: '',
				supplierHotCities: ['深圳', '苏州', '杭州', '上海', '东莞', '北京', '武汉', '成都'],
				supplierQuickSupplyItems: [
					'宇树 G1 关节模组与核心部件',
					'宇树 Go2 传感器与整机零备件',
					'智元远征系列适配结构件',
					'钛合金外骨骼与定制机甲外壳',
					'驻场工程师日常巡检与维护',
					'ROS2运控算法与动作包供给'
				],
				coopHotRobots: ['宇树 G1', '宇树 Go2', '智元远征 A1/A2', '开普勒人形', '四足机器狗', '通用 ROS2'],
				coopHotCities: ['深圳', '杭州', '上海', '北京', '苏州', '广州'],
				partnerHotCities: ['北京市', '上海市', '深圳市', '广州市', '杭州市', '苏州市', '成都市', '武汉市'],
				supplierCategories: [
					'整机与本体货源',
					'配件皮肤与核心部件',
					'城市交付与驻场运维',
					'RaaS技能与算法供给'
				],
				supplierCapacityScales: [
					'1-10台/月 (初创试跑)',
					'10-50台/月 (成熟供货)',
					'50-200台/月 (规模产能)',
					'200台+/月 或 全国多城覆盖'
				],
				supplierWarrantyOptions: [
					'7天换新 + 1年原厂质保',
					'2小时现场响应 + 24小时备件换修',
					'全流程原厂巡检与维保运维支持',
					'协议定制SLA服务保障'
				],
				supplierForm: {
					entry_mode: 'with_code', // 'with_code' | 'apply_code'
					invite_code: '',
					coop_direction: '整机与本体货源',
					org_name: '',
					credit_code: '',
					supply_items: '',
					capacity_scale: '10-50台/月 (成熟供货)',
					warranty_commitment: '7天换新 + 1年原厂质保',
					region: '',
					contact_name: '',
					contact_phone: '',
					contact_wechat: '',
					summary: '',
				},

				// ===== 合伙人推荐表单数据 =====
				partnerForm: {
					companyName: '',
					level: '金牌',
					contactName: '',
					phone: '',
					region: '',
					intro: '',
					recommenderType: 'sales',
					inviteCode: '',
				},
				levelOptions: ['金牌', '银牌', '铜牌', '普通'],
				licenseFile: null,
				licenseFileName: '',
				cacheKey: 'partnerFormCache',

				// 底部安全区高度（单位 px）
				safeBottom: 0,
			};
		},
		computed: {
			// 开票中心:B-15 —— 卡片上的"笔数"与统计都从真实列表算,不再写死
			invoiceCount() {
				return this.invoiceList.length;
			},
			invoiceStats() {
				const list = this.invoiceList || [];
				return {
					total: list.length,
					pending: list.filter(i => i.status === 'applied').length,
					done: list.filter(i => i.status === 'issued').length,
				};
			},
			paidOrderLabels() {
				return (this.paidOrders || []).map(o => `${o.increment_id || o.id}  ¥${o.grand_total}`);
			},
			pickedOrderLabel() {
				const id = this.invoiceForm.order_id;
				if (!id) return '';
				const o = (this.paidOrders || []).find(x => x.id === id);
				return o ? `${o.increment_id || o.id}  ¥${o.grand_total}` : '';
			},
			supplierApplications() {
				return (this.cooperateApplications || []).filter(app => app.coop_type === 'supplier_invite');
			},
			supplierProgress() {
				const f = this.supplierForm || {};
				const items = [
					{ name: '供应商企业全称', ok: !!(f.org_name && f.org_name.trim().length >= 2) },
					{ name: '供应产品明细', ok: !!(f.supply_items && f.supply_items.trim().length > 0) },
					{ name: '联系人姓名', ok: !!(f.contact_name && f.contact_name.trim().length >= 2) },
					{ name: '11位手机号', ok: !!(/^1\d{10}$/.test(String(f.contact_phone || '').trim())) },
				];
				if (f.entry_mode === 'with_code') {
					items.unshift({ name: '受邀邀请码', ok: !!(f.invite_code && f.invite_code.trim().length > 0) });
				}
				const total = items.length;
				const finished = items.filter(i => i.ok).length;
				const missing = items.filter(i => !i.ok).map(i => i.name);
				const percent = Math.round((finished / total) * 100);
				return {
					total,
					finished,
					percent,
					isReady: finished === total,
					missingCount: total - finished,
					missingNames: missing.join('、'),
					hint: finished === total ? '必填项已就绪，可直接提交' : `还差 ${total - finished} 项：${missing.join('、')}`
				};
			},
			isFormValid() {
				const f = this.partnerForm;
				const requiredFields = [f.companyName, f.level, f.contactName, f.phone, f.region, f.intro, f.inviteCode];
				const allFilled = requiredFields.every(v => v && v.trim().length > 0);
				const phoneValid = /^1\d{10}$/.test(f.phone);
				const licenseValid = !!this.licenseFile;
				return allFilled && phoneValid && licenseValid;
			}
		},
		onLoad() {
			this.loadAddressData();
			this.loadPartnerFormCache();
			const system = uni.getSystemInfoSync();
			this.safeBottom = system.safeAreaInsets ? system.safeAreaInsets.bottom : 0;
		},
		onShow() {
			// 还原登录态（登录后切回本页时显示用户名）
			const customer = uni.getStorageSync(CUSTOMER_KEY);
			if (customer && customer.name) {
				this.userName = customer.name;
				// 诚实显示:有真实手机号才脱敏展示,否则明确"未绑定"
				this.customerPhone = customer.phone ? String(customer.phone).replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2') : '未绑定手机';
			}
		},
		methods: {
			// ================================================================
			// 页面切换
			// ================================================================
			goBack() {
				if (this.currentPage === 'address' && this.showForm) {
					this.showForm = false;
				} else {
					this.currentPage = 'main';
				}
			},

			// ================================================================
			// 主页面 - 用户相关
			// ================================================================
			handleLogin() {
				if (this.userName) {
					uni.showToast({ title: '已登录', icon: 'none' });
				} else {
					this.showLoginModal = true;
				}
			},
			closeLoginModal() {
				this.showLoginModal = false;
			},
			submitLogin() {
				const { email, password } = this.loginForm;
				if (!email.trim()) { uni.showToast({ title: '请输入邮箱', icon: 'none' }); return; }
				if (!password) { uni.showToast({ title: '请输入密码', icon: 'none' }); return; }
				uni.showLoading({ title: '登录中...', mask: true });
				authApi.login(email.trim(), password)
					.then((res) => {
						uni.hideLoading();
						const data = (res && res.data) || {};
						if (data.token) {
							uni.setStorageSync(TOKEN_KEY, data.token);
							uni.setStorageSync(CUSTOMER_KEY, data.customer || { email: email.trim() });
							this.userName = (data.customer && data.customer.name) ? data.customer.name : email.trim().split('@')[0];
							this.customerPhone = (data.customer && data.customer.phone) ? String(data.customer.phone).replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2') : '未绑定手机';
						} else {
							// 诚实化:后端未返回凭证视为登录失败,不再制造本地假登录态(假登录会导致后续请求静默 401)
							uni.showToast({ title: '登录失败：服务未返回凭证，请稍后重试', icon: 'none' });
							return;
						}
						// 登录/注册后拉真实开票记录(B-15)——invoiceCount 现在是 computed
						this.loadInvoices();
						this.showLoginModal = false;
						this.loginForm = { email: '', password: '' };
						this.registerForm = { name: '', email: '', password: '', confirmPassword: '' };
						uni.showToast({ title: '登录成功', icon: 'success' });
					})
					.catch((err) => {
						uni.hideLoading();
						uni.showToast({ title: (err && err.message) || '登录失败', icon: 'none' });
					});
			},
			switchAuthMode(mode) {
				if (this.authMode === mode) return;
				this.authMode = mode;
				// 切换时清空输入，避免串表单
				this.loginForm = { email: '', password: '' };
				this.registerForm = { name: '', email: '', password: '', confirmPassword: '' };
			},
			submitRegister() {
				const f = this.registerForm;
				const email = f.email.trim();
				if (!f.name.trim()) { uni.showToast({ title: '请输入姓名', icon: 'none' }); return; }
				if (!email) { uni.showToast({ title: '请输入邮箱', icon: 'none' }); return; }
				if (!/^[\w.-]+@[\w-]+(\.[\w-]+)+$/.test(email)) { uni.showToast({ title: '请输入正确的邮箱', icon: 'none' }); return; }
				if (f.password.length < 6) { uni.showToast({ title: '密码至少 6 位', icon: 'none' }); return; }
				if (f.password !== f.confirmPassword) { uni.showToast({ title: '两次输入的密码不一致', icon: 'none' }); return; }

				// 姓名拆分（Bagisto 注册要求 first_name + last_name 均非空，显示名 = "姓 名"）
				const name = f.name.trim();
				const parts = name.split(/\s+/);
				let first_name, last_name;
				if (parts.length > 1) {
					// 空格分隔的英文名：首词为姓，其余为名
					first_name = parts[0];
					last_name = parts.slice(1).join(' ');
				} else if (name.length > 1) {
					// 中文名：首字为姓，其余为名
					first_name = name.charAt(0);
					last_name = name.slice(1);
				} else {
					// 单字名兜底
					first_name = name;
					last_name = '友';
				}

				uni.showLoading({ title: '注册中...', mask: true });
				authApi.register({ first_name, last_name, email, password: f.password })
					.then((res) => {
						uni.hideLoading();
						const data = (res && res.data) || {};
						if (data.token) {
							uni.setStorageSync(TOKEN_KEY, data.token);
							uni.setStorageSync(CUSTOMER_KEY, data.customer || { email });
							this.userName = (data.customer && data.customer.name) ? data.customer.name : `${first_name} ${last_name}`;
							this.customerPhone = (data.customer && data.customer.phone) ? String(data.customer.phone).replace(/^(\d{3})\d{4}(\d{4})$/, '$1****$2') : '未绑定手机';
						} else {
							// 诚实化:后端未返回凭证视为注册失败,引导用户直接登录
							uni.showToast({ title: '注册失败：服务未返回凭证，请直接登录或稍后重试', icon: 'none' });
							return;
						}
						// 登录/注册后拉真实开票记录(B-15)——invoiceCount 现在是 computed
						this.loadInvoices();
						this.showLoginModal = false;
						this.registerForm = { name: '', email: '', password: '', confirmPassword: '' };
						uni.showToast({ title: '注册成功', icon: 'success' });
					})
					.catch((err) => {
						uni.hideLoading();
						// 后端 422 时 errors 里带具体字段错误（如邮箱已注册）
						const errors = err && err.data && err.data.errors;
						const msg = errors
							? Object.keys(errors).map(k => errors[k]).flat().join('；')
							: (err && err.message) || '注册失败';
						uni.showToast({ title: msg, icon: 'none' });
					});
			},
			goVip() {
				uni.showToast({ title: '会员中心即将开放', icon: 'none' });
			},

			// ================================================================
			// 主页面 - 双卡片
			// ================================================================
			goCoupon() { this.currentPage = 'coupon'; },
			goInvoice() { this.currentPage = 'invoice'; this.loadInvoices(); },

			// ================================================================
			// 主页面 - 订单
			// ================================================================
			goAllOrders() {
				uni.setStorageSync('orderStatus', 'all');
				uni.switchTab({ url: '/pages/order/index' });
			},
			goOrderList(status) {
				uni.setStorageSync('orderStatus', status);
				uni.switchTab({ url: '/pages/order/index' });
			},

			// ================================================================
			// 主页面 - 常用功能
			// ================================================================
			goAddress() { this.currentPage = 'address'; },
			// 设备是独立页面(不是本页的内嵌子视图):它有列表 + 增删 + 技能绑定,体量放不下
			goDevices() { uni.navigateTo({ url: '/pages/mine/devices' }); },
			// 我的技能:技能下单后的「开通/绑定/下载」都在这里,是技能那条链路的落点
			goSkills() { uni.navigateTo({ url: '/pages/mine/skills' }); },
			// G2:赛事/活动报名与入场凭证
			goMyRegistrations() { uni.navigateTo({ url: '/pages/ecosystem/my-registrations' }); },
			// G4-02:活动/资讯收藏（跨设备持久）
			goMyFavorites() { uni.navigateTo({ url: '/pages/ecosystem/my-favorites' }); },
			// B-8:售后单列表只有入口才有意义 —— 此前 skillApi.afterSales() 封装好了却零消费方
			goAfterSales() { uni.navigateTo({ url: '/pages/mine/after-sales' }); },
			// 消息通知:审核/下发/试用到期/退款 都落这里(v3.4)
			goNotifications() { uni.navigateTo({ url: '/pages/mine/notifications' }); },
			goCooperate() {
				this.currentPage = 'cooperate';
				this.loadCooperations();
			},
			// 开发者后台(v3.4 步骤 9):独立 Blade 门户,跑在商城同域 /dev,与 C 端同一账号体系
			// (邮箱+密码登录 developer 会话)。H5 新开标签页;App/小程序没有同域 web-view 条件,
			// 复制链接让用户去浏览器打开 —— 与赛事报名外部链接的做法一致。
			goDeveloperPortal() {
				const url = String(BASE_URL).replace(/\/+$/, '') + '/dev';
				// #ifdef H5
				window.open(url, '_blank', 'noopener');
				// #endif
				// #ifndef H5
				uni.setClipboardData({
					data: url,
					success: () => uni.showToast({ title: '链接已复制，请在浏览器打开', icon: 'none' }),
					fail: () => uni.showToast({ title: '复制失败，请稍后重试', icon: 'none' }),
				});
				// #endif
			},
			goSupplierCode() {
				this.currentPage = 'supplier';
				const customer = uni.getStorageSync(CUSTOMER_KEY) || {};
				if (!this.supplierForm.contact_name) {
					this.supplierForm.contact_name = customer.name || this.userName || '';
				}
				if (!this.supplierForm.contact_phone && customer.phone && /^1d{10}$/.test(String(customer.phone))) {
					this.supplierForm.contact_phone = String(customer.phone);
				}
				if (!this.supplierForm.contact_wechat && customer.email) {
					this.supplierForm.contact_wechat = customer.email;
				}
				this.loadCooperations();
			},
			async verifySupplierInviteCode() {
				const code = String(this.supplierVerifyCode || '').trim();
				if (!code) {
					uni.showToast({ title: '请输入待核验的邀请码', icon: 'none' });
					return;
				}
				try {
					const res = await cooperationApi.verifyInvite(code);
					const data = (res && res.data) || {};
					this.supplierVerifyResult = data;
					if (data.redeemable) {
						uni.showToast({ title: '邀请码有效 ✓', icon: 'success' });
					} else {
						uni.showToast({ title: data.reason || '邀请码不可用', icon: 'none' });
					}
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '核验失败，请稍后重试', icon: 'none' });
				}
			},
			async verifyFormInviteCode() {
				const code = String(this.supplierForm.invite_code || '').trim();
				if (!code) {
					uni.showToast({ title: '请输入邀请码', icon: 'none' });
					return;
				}
				try {
					const res = await cooperationApi.verifyInvite(code);
					const data = (res && res.data) || {};
					if (data.redeemable) {
						uni.showToast({ title: '邀请码有效 ✓', icon: 'success' });
					} else {
						uni.showToast({ title: data.reason || '邀请码不可用', icon: 'none' });
					}
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '核验异常', icon: 'none' });
				}
			},
			async verifyModalInviteCode() {
				const code = String(this.cooperateForm.invite_code || '').trim();
				if (!code) {
					uni.showToast({ title: '请输入邀请码', icon: 'none' });
					return;
				}
				try {
					const res = await cooperationApi.verifyInvite(code);
					const data = (res && res.data) || {};
					if (data.redeemable) {
						uni.showToast({ title: '邀请码有效 ✓', icon: 'success' });
					} else {
						uni.showToast({ title: data.reason || '邀请码不可用', icon: 'none' });
					}
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '核验异常', icon: 'none' });
				}
			},
			chooseSupplierLicense() {
				uni.showActionSheet({
					itemList: ['拍照上传', '从手机相册选择'],
					success: (res) => {
						const sourceType = res.tapIndex === 0 ? ['camera'] : ['album'];
						uni.chooseImage({
							count: 1,
							sizeType: ['compressed'],
							sourceType,
							success: (imgRes) => {
								const file = imgRes.tempFiles && imgRes.tempFiles[0];
								if (file) {
									const sizeKb = file.size ? Math.round(file.size / 1024) : 0;
									this.supplierLicenseFile = file;
									this.supplierLicenseFileName = (file.name || 'license_cert_' + Date.now().toString(36) + '.jpg') + (sizeKb > 0 ? ` (${sizeKb} KB)` : '');
									uni.showToast({ title: '凭证已选取', icon: 'success' });
								}
							},
							fail: () => {}
						});
					}
				});
			},
			removeSupplierLicense() {
				this.supplierLicenseFile = null;
				this.supplierLicenseFileName = '';
				uni.showToast({ title: '已移除凭证', icon: 'none' });
			},
			appendSupplierItem(tag) {
				const cur = (this.supplierForm.supply_items || '').trim();
				if (!cur) {
					this.supplierForm.supply_items = tag;
				} else if (!cur.includes(tag)) {
					this.supplierForm.supply_items = cur + '、' + tag;
				}
				uni.showToast({ title: '已添加产品项', icon: 'none' });
			},
			fillSupplierCity(city) {
				this.supplierForm.region = city;
				uni.showToast({ title: '已填入城市：' + city, icon: 'none' });
			},
			fillCurrentAccountToSupplier() {
				const customer = uni.getStorageSync(CUSTOMER_KEY) || {};
				let changed = false;
				if (customer.name || this.userName) {
					this.supplierForm.contact_name = customer.name || this.userName;
					changed = true;
				}
				if (customer.phone && /^1\d{10}$/.test(String(customer.phone))) {
					this.supplierForm.contact_phone = String(customer.phone);
					changed = true;
				}
				if (customer.email) {
					this.supplierForm.contact_wechat = customer.email;
					changed = true;
				}
				uni.showToast({ title: changed ? '已一键填入当前账号联系信息' : '当前账号未完善手机号，请手动输入', icon: 'none' });
			},
			appendCoopRobot(tag) {
				const cur = (this.cooperateForm.robot_models || '').trim();
				if (!cur) {
					this.cooperateForm.robot_models = tag;
				} else if (!cur.includes(tag)) {
					this.cooperateForm.robot_models = cur + ' / ' + tag;
				}
				uni.showToast({ title: '已添加机型：' + tag, icon: 'none' });
			},
			fillCoopCity(city) {
				this.cooperateForm.region = city;
				uni.showToast({ title: '已填入城市：' + city, icon: 'none' });
			},
			fillCurrentAccountToCoop() {
				const customer = uni.getStorageSync(CUSTOMER_KEY) || {};
				let changed = false;
				if (customer.name || this.userName) {
					this.cooperateForm.contact_name = customer.name || this.userName;
					changed = true;
				}
				if (customer.phone && /^1\d{10}$/.test(String(customer.phone))) {
					this.cooperateForm.contact_phone = String(customer.phone);
					changed = true;
				}
				if (customer.email) {
					this.cooperateForm.contact_wechat = customer.email;
					changed = true;
				}
				uni.showToast({ title: changed ? '已填入账号联系人' : '请手动填写联系方式', icon: 'none' });
			},
			fillPartnerCity(city) {
				this.partnerForm.region = city;
				uni.showToast({ title: '已填入签约城市：' + city, icon: 'none' });
			},
			useVerifiedCodeForSupplierForm() {
				if (!this.supplierVerifyResult || !this.supplierVerifyResult.code) return;
				this.supplierForm.entry_mode = 'with_code';
				this.supplierForm.invite_code = this.supplierVerifyResult.code;
				uni.showToast({ title: '已带入供应链入驻表单', icon: 'success' });
			},
			switchSupplierEntryMode(mode) {
				this.supplierForm.entry_mode = mode;
				if (mode === 'apply_code') {
					this.supplierForm.invite_code = '';
				}
			},
			copyInviteCode(code) {
				if (!code) return;
				uni.setClipboardData({
					data: String(code),
					success: () => uni.showToast({ title: '邀请码已复制', icon: 'success' }),
					fail: () => uni.showToast({ title: '复制失败，请重试', icon: 'none' }),
				});
			},
			goDeveloperPortalWithCode(code) {
				const base = String(BASE_URL).replace(/\/+$/, '');
				const target = code ? `${base}/dev/join?code=${encodeURIComponent(code)}` : `${base}/dev`;
				// #ifdef H5
				window.open(target, '_blank', 'noopener');
				// #endif
				// #ifndef H5
				uni.setClipboardData({
					data: target,
					success: () => uni.showToast({ title: '受邀链接已复制，请在浏览器打开', icon: 'none' }),
					fail: () => uni.showToast({ title: '复制失败', icon: 'none' }),
				});
				// #endif
			},
			async submitSupplierForm() {
				if (this.supplierSubmitting) return;
				const f = this.supplierForm;

				if (f.entry_mode === 'with_code' && (!f.invite_code || !f.invite_code.trim())) {
					uni.showToast({ title: '持码入驻模式下请输入邀请码', icon: 'none' });
					return;
				}
				if (!f.coop_direction) {
					uni.showToast({ title: '请选择核心供应品类', icon: 'none' });
					return;
				}
				if (!f.org_name || f.org_name.trim().length < 2) {
					uni.showToast({ title: '请填写供应商企业全称', icon: 'none' });
					return;
				}
				if (!f.supply_items || !f.supply_items.trim()) {
					uni.showToast({ title: '请填写主营供应产品或明细', icon: 'none' });
					return;
				}
				if (!f.contact_name || f.contact_name.trim().length < 2) {
					uni.showToast({ title: '请填写联系人姓名', icon: 'none' });
					return;
				}
				if (!/^1\d{10}$/.test(String(f.contact_phone || '').trim())) {
					uni.showToast({ title: '请输入正确的 11 位手机号码', icon: 'none' });
					return;
				}

				this.supplierSubmitting = true;
				try {
					const gk = this.getOrCreateGuestKey();
					const meta = {
						entry_mode: f.entry_mode,
						supply_items: f.supply_items.trim(),
						capacity_scale: f.capacity_scale,
						warranty_commitment: f.warranty_commitment,
					};
					if (this.supplierLicenseFileName) {
						meta.license_file_name = this.supplierLicenseFileName;
					}
					if (f.credit_code && f.credit_code.trim()) {
						meta.credit_code = f.credit_code.trim();
					}
					if (f.entry_mode === 'with_code' && f.invite_code && f.invite_code.trim()) {
						meta.invite_code = f.invite_code.trim().toUpperCase();
					}

					const res = await cooperationApi.submit({
						coop_type: 'supplier_invite',
						org_name: f.org_name.trim(),
						contact_name: f.contact_name.trim(),
						contact_phone: String(f.contact_phone).trim(),
						contact_wechat: f.contact_wechat.trim() || null,
						region: f.region.trim() || null,
						coop_direction: f.coop_direction,
						summary: f.summary.trim() || null,
						guest_key: gk,
						meta,
					});

					uni.showToast({ title: (res && res.message) || '供应链申请已提交', icon: 'success' });
					await this.loadCooperations();
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '提交失败，请稍后重试', icon: 'none' });
				} finally {
					this.supplierSubmitting = false;
				}
			},
			goPartnerRecommend() {
				this.currentPage = 'partner';
				this.loadPartnerFormCache();
			},
			goCustomerService() { uni.navigateTo({ url: '/pages/mine/tickets' }); },
            goSceneRequests() { uni.navigateTo({ url: '/pages/scene/requests' }); },
			// 事故上报:技能在真实机器人上物理执行,出事需要可追溯的上报链(计划 §4.19)
			goIncidentReport() { uni.navigateTo({ url: '/pages/mine/incidents' }); },
			goSettings() { this.currentPage = 'settings'; },
			// 隐私政策 / 用户协议:正文读平台后台 CMS,**不前端写死** ——
			// 与网页端 /page/{slug} 同源,律师定稿后两边一起变,不会各说各话
			goPrivacyPolicy() { uni.navigateTo({ url: '/pages/mine/legal?doc=privacy-policy' }); },
			goUserAgreement() { uni.navigateTo({ url: '/pages/mine/legal?doc=terms-of-use' }); },
			goAbout() { uni.navigateTo({ url: '/pages/mine/about' }); },

			// ================================================================
			// 地址管理 - 数据持久化
			// ================================================================
			loadAddressData() {
				const token = uni.getStorageSync(TOKEN_KEY);
				if (token) {
					// 已登录:地址走后台接口(真实增删改查)
					this.addressLoading = true;
					this.addressError = '';
					addressApi.getAddresses()
						.then((res) => {
							this.addressList = ((res && res.data) || []).map(a => serverToLocal(a));
						})
						.catch((e) => {
							// ⚠ 故障 ≠「暂无收货地址」:置错误标记交给 StateView 的错误态(带重试)
							this.addressError = (e && e.message) || '加载失败';
							this.addressList = [];
						})
						.finally(() => {
							this.addressLoading = false;
						});
					return;
				}
				// 游客:本地存储,不再注入假数据
				this.addressLoading = false;
				this.addressError = '';
				const data = uni.getStorageSync('addressList');
				this.addressList = data ? JSON.parse(data) : [];
			},
			saveAddressData() {
				uni.setStorageSync('addressList', JSON.stringify(this.addressList));
			},
			generateId() {
				return Date.now() + Math.floor(Math.random() * 1000);
			},

			// ================================================================
			// 地址管理 - 列表操作
			// ================================================================
			addAddress() {
				this.editingId = null;
				this.formData = { name: '', phone: '', province: '', city: '', district: '', detail: '', isDefault: false };
				this.showForm = true;
			},
			editAddress(item) {
				this.editingId = item.id;
				this.formData = { ...item };
				this.showForm = true;
			},
			deleteAddress(id) {
				uni.showModal({
					title: '提示',
					content: '确认删除该地址吗？',
					success: (res) => {
						if (res.confirm) {
							// 已登录:走后台接口;游客:本地过滤
							if (uni.getStorageSync(TOKEN_KEY)) {
								addressApi.deleteAddress(id)
									.then(() => this.loadAddressData())
									.catch((err) => uni.showToast({ title: (err && err.message) || '删除失败', icon: 'none' }));
							} else {
								this.addressList = this.addressList.filter(item => item.id !== id);
								this.saveAddressData();
								uni.showToast({ title: '已删除', icon: 'success' });
							}
						}
					}
				});
			},
			cancelForm() {
				this.showForm = false;
			},

			// ================================================================
			// 地址管理 - 定位功能
			// ================================================================
			getLocationAndFill() {
				if (this.locating) return;
				uni.showLoading({ title: '定位中...' });
				this.locating = true;
				uni.getLocation({
					type: 'gcj02',
					success: (res) => {
						this.reverseGeocode(res.latitude, res.longitude);
					},
					fail: (err) => {
						uni.hideLoading();
						this.locating = false;
						uni.showModal({
							title: '定位失败',
							content: err.errMsg || '请检查定位权限并重试',
							showCancel: false
						});
					}
				});
			},
			reverseGeocode(lat, lng) {
				const url = `https://apis.map.qq.com/ws/geocoder/v1/?location=${lat},${lng}&key=${this.mapKey}&get_poi=0`;
				uni.request({
					url,
					success: (res) => {
						uni.hideLoading();
						this.locating = false;
						if (res.data.status === 0) {
							const addr = res.data.result.address_component;
							this.formData.province = addr.province || '';
							this.formData.city = addr.city || '';
							this.formData.district = addr.district || '';
							uni.showToast({ title: '定位成功', icon: 'success' });
						} else {
							uni.showToast({ title: '解析地址失败', icon: 'none' });
						}
					},
					fail: () => {
						uni.hideLoading();
						this.locating = false;
						uni.showToast({ title: '网络请求失败', icon: 'none' });
					}
				});
			},

			// ================================================================
			// 地址管理 - 表单保存
			// ================================================================
			onSwitchChange(e) {
				this.formData.isDefault = e.detail.value;
			},
			saveAddress() {
				const { name, phone, province, city, district, detail } = this.formData;
				if (!name.trim()) { uni.showToast({ title: '请输入收货人姓名', icon: 'none' }); return; }
				if (!phone.trim()) { uni.showToast({ title: '请输入手机号', icon: 'none' }); return; }
				if (!/^1\d{10}$/.test(phone.trim())) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return; }
				if (!province.trim()) { uni.showToast({ title: '请输入省份', icon: 'none' }); return; }
				if (!city.trim()) { uni.showToast({ title: '请输入城市', icon: 'none' }); return; }
				if (!district.trim()) { uni.showToast({ title: '请输入区/县', icon: 'none' }); return; }
				if (!detail.trim()) { uni.showToast({ title: '请输入详细地址', icon: 'none' }); return; }

				// 已登录:走后台接口(真实增改)
				if (uni.getStorageSync(TOKEN_KEY)) {
					const payload = localToServerPayload(this.formData, '');
					const req = this.editingId
						? addressApi.updateAddress(this.editingId, payload)
						: addressApi.createAddress(payload);
					req
						.then(() => {
							uni.showToast({ title: '保存成功', icon: 'success' });
							this.showForm = false;
							this.loadAddressData();
						})
						.catch((err) => {
							uni.showToast({ title: (err && err.message) || '保存失败', icon: 'none' });
						});
					return;
				}

				// 游客:本地存储
				let list = [...this.addressList];
				if (this.formData.isDefault) {
					list = list.map(item => ({ ...item, isDefault: false }));
				}
				if (this.editingId) {
					list = list.map(item => item.id === this.editingId ? { ...this.formData, id: item.id } : item);
					uni.showToast({ title: '修改成功', icon: 'success' });
				} else {
					list.push({ ...this.formData, id: this.generateId() });
					uni.showToast({ title: '添加成功', icon: 'success' });
				}
				this.addressList = list;
				this.saveAddressData();
				this.showForm = false;
			},

			// ================================================================
			// 合作申请 - 打开专属表单弹窗并对接 /api/cooperations
			// ================================================================
			getOrCreateGuestKey() {
				let gk = uni.getStorageSync('guest_key');
				if (!gk || String(gk).length < 6) {
					gk = 'gk_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
					uni.setStorageSync('guest_key', gk);
				}
				return String(gk);
			},
			async loadCooperations() {
				this.cooperateLoading = true;
				try {
					const gk = this.getOrCreateGuestKey();
					const res = await cooperationApi.list(gk);
					this.cooperateApplications = (res && Array.isArray(res.data)) ? res.data : [];
				} catch (e) {
					this.cooperateApplications = [];
				} finally {
					this.cooperateLoading = false;
				}
			},
			goApplyForm(item) {
				const customer = uni.getStorageSync(CUSTOMER_KEY) || {};
				const defaultDir = (item.directions && item.directions[0]) || '';
				this.activeCoopItem = item;
				this.cooperateForm = {
					coop_type: item.key,
					org_name: '',
					contact_name: customer.name || this.userName || '',
					contact_phone: (customer.phone && /^1\d{10}$/.test(String(customer.phone))) ? String(customer.phone) : '',
					contact_wechat: customer.email || '',
					region: '',
					coop_direction: defaultDir,
					summary: '',
					robot_models: '',
					entity_type: 'company',
					portfolio_url: '',
					invite_code: '',
					platform: '抖音',
					followers_tier: '10万-50万',
					account_url: '',
				};
				this.showCooperateModal = true;
			},
			closeCooperateModal() {
				this.showCooperateModal = false;
			},
			async submitCooperateForm() {
				if (this.cooperateSubmitting || !this.activeCoopItem) return;
				const f = this.cooperateForm;
				const key = this.activeCoopItem.key;

				if (!f.org_name || f.org_name.trim().length < 2) {
					uni.showToast({ title: key === 'influencer' ? '请填写达人昵称或MCN机构' : '请填写主体/企业名称', icon: 'none' });
					return;
				}
				if (!f.coop_direction) {
					uni.showToast({ title: '请选择合作方向', icon: 'none' });
					return;
				}
				if ((key === 'manufacturer' || key === 'raas') && !f.robot_models.trim()) {
					uni.showToast({ title: key === 'manufacturer' ? '请填写主营本体型号/产品线' : '请填写适配目标机型', icon: 'none' });
					return;
				}
				if (key === 'influencer' && (!f.platform || !f.followers_tier)) {
					uni.showToast({ title: '请选择主营平台与粉丝量级', icon: 'none' });
					return;
				}
				if (!f.contact_name || f.contact_name.trim().length < 2) {
					uni.showToast({ title: '请填写联系人姓名', icon: 'none' });
					return;
				}
				if (!/^1\d{10}$/.test(String(f.contact_phone || '').trim())) {
					uni.showToast({ title: '请输入正确的 11 位手机号码', icon: 'none' });
					return;
				}

				const meta = {};
				if (key === 'manufacturer') {
					meta.robot_models = f.robot_models.trim();
				} else if (key === 'raas') {
					meta.entity_type = f.entity_type || 'company';
					meta.robot_models = f.robot_models.trim();
					if (f.portfolio_url.trim()) meta.portfolio_url = f.portfolio_url.trim();
					if (f.invite_code.trim()) meta.invite_code = f.invite_code.trim();
				} else if (key === 'influencer') {
					meta.platform = f.platform;
					meta.followers_tier = f.followers_tier;
					if (f.account_url.trim()) meta.account_url = f.account_url.trim();
				}

				this.cooperateSubmitting = true;
				try {
					const gk = this.getOrCreateGuestKey();
					const res = await cooperationApi.submit({
						coop_type: key,
						org_name: f.org_name.trim(),
						contact_name: f.contact_name.trim(),
						contact_phone: String(f.contact_phone).trim(),
						contact_wechat: f.contact_wechat.trim() || null,
						region: f.region.trim() || null,
						coop_direction: f.coop_direction,
						summary: f.summary.trim() || null,
						guest_key: gk,
						meta,
					});
					this.showCooperateModal = false;
					uni.showToast({ title: (res && res.message) || '申请已提交', icon: 'success' });
					await this.loadCooperations();
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '提交失败，请稍后重试', icon: 'none' });
				} finally {
					this.cooperateSubmitting = false;
				}
			},

			// ================================================================
			// 开票中心 - 方法(B-15:接后端 /api/invoices)
			// ================================================================
			async loadInvoices() {
				this.invoiceLoading = true;
				this.invoiceError = '';
				try {
					const res = await invoiceApi.list();
					this.invoiceList = (res && Array.isArray(res.data)) ? res.data : [];
				} catch (e) {
					this.invoiceError = (e && e.message) || '加载失败';
					this.invoiceList = [];
				} finally {
					this.invoiceLoading = false;
				}
			},
			// 该笔订单的开票信息可补充(applied);点了直接带出该订单预填
			applyInvoice(item) {
				const oid = (item.order_ids && item.order_ids[0]) || '';
				this.invoiceForm = {
					order_id: oid,
					type: item.type || 'personal',
					title: item.title || '',
					tax_no: item.tax_no || '',
				};
				this.showInvoiceModal = true;
				if (!this.paidOrders.length) this.loadPaidOrders();
			},
			viewInvoice(item) {
				// 已开具的只读展示;真实发票文件未接入 → 不假装能下载
				uni.showModal({
					title: '发票信息',
					content: `状态：${item.status_label}\n抬头：${item.title}\n税号：${item.tax_no || '未填写'}\n金额：¥${item.amount}`,
					showCancel: false,
				});
			},
			openApplyInvoice() {
				this.invoiceForm = { order_id: '', type: 'personal', title: '', tax_no: '' };
				this.showInvoiceModal = true;
				this.loadPaidOrders();
			},
			closeInvoiceModal() {
				this.showInvoiceModal = false;
			},
			noop() {},
			async loadPaidOrders() {
				this.paidOrdersLoading = true;
				try {
					const res = await orderApi.getOrders();
					const rows = (res && Array.isArray(res.data)) ? res.data : [];
					// 只有已支付订单能开票(与后端判定一致)
					this.paidOrders = rows.filter(o => o.status === 'processing' || o.status === 'completed');
				} catch (e) {
					console.warn('[mine] 可开票订单加载失败', e);
					this.paidOrders = [];
				} finally {
					this.paidOrdersLoading = false;
				}
			},
			onPickOrder(e) {
				const idx = Number(e.detail.value);
				const o = this.paidOrders[idx];
				if (o) this.invoiceForm.order_id = o.id;
			},
			async submitInvoice() {
				const f = this.invoiceForm;
				if (!f.order_id) { uni.showToast({ title: '请选择要开票的订单', icon: 'none' }); return; }
				if (!f.title.trim()) { uni.showToast({ title: '请填写发票抬头', icon: 'none' }); return; }
				if (f.type === 'company' && !f.tax_no.trim()) { uni.showToast({ title: '企业抬头请填写税号', icon: 'none' }); return; }
				this.invoiceSubmitting = true;
				try {
					const res = await invoiceApi.apply({
						order_id: f.order_id,
						type: f.type,
						title: f.title.trim(),
						tax_no: f.type === 'company' ? f.tax_no.trim() : null,
					});
					uni.showToast({ title: (res && res.message) || '已提交', icon: 'success' });
					this.showInvoiceModal = false;
					await this.loadInvoices();
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' });
				} finally {
					this.invoiceSubmitting = false;
				}
			},

			// ================================================================
			// 合伙人推荐 - 表单缓存
			// ================================================================
			loadPartnerFormCache() {
				const cache = uni.getStorageSync(this.cacheKey);
				if (cache) {
					try {
						const data = JSON.parse(cache);
						Object.assign(this.partnerForm, data);
					} catch (e) {}
				}
			},
			savePartnerFormCache() {
				uni.setStorageSync(this.cacheKey, JSON.stringify(this.partnerForm));
			},

			// ================================================================
			// 合伙人推荐 - 表单事件
			// ================================================================
			onLevelChange(e) {
				const idx = e.detail.value;
				this.partnerForm.level = this.levelOptions[idx];
				this.savePartnerFormCache();
			},
			onIntroInput() {
				this.savePartnerFormCache();
			},
			onRecommenderTypeChange(e) {
				const val = e.detail.value;
				this.partnerForm.recommenderType = val;
				this.partnerForm.inviteCode = '';
				this.savePartnerFormCache();
			},

			// ================================================================
			// 合伙人推荐 - 文件上传（拍照/相册/文件）
			// ================================================================
			chooseLicense() {
				uni.showActionSheet({
					itemList: ['拍照', '从相册选择', '从文件选择'],
					success: (res) => {
						const index = res.tapIndex;
						if (index === 0) {
							uni.chooseImage({
								count: 1,
								sizeType: ['original', 'compressed'],
								sourceType: ['camera'],
								success: (res) => {
									this.handleFileSelected(res.tempFiles[0]);
								},
								fail: () => {
									uni.showToast({ title: '取消选择', icon: 'none' });
								}
							});
						} else if (index === 1) {
							uni.chooseImage({
								count: 1,
								sizeType: ['original', 'compressed'],
								sourceType: ['album'],
								success: (res) => {
									this.handleFileSelected(res.tempFiles[0]);
								},
								fail: () => {
									uni.showToast({ title: '取消选择', icon: 'none' });
								}
							});
						} else if (index === 2) {
							uni.chooseFile({
								count: 1,
								type: 'all',
								success: (res) => {
									this.handleFileSelected(res.tempFiles[0]);
								},
								fail: () => {
									uni.showToast({ title: '取消选择', icon: 'none' });
								}
							});
						}
					},
					fail: () => {}
				});
			},
			handleFileSelected(file) {
				if (file.size > 10 * 1024 * 1024) {
					uni.showModal({
						title: '文件过大',
						content: '文件大小不能超过 10MB',
						showCancel: false
					});
					return;
				}
				const name = file.name || '';
				const ext = name.split('.').pop().toLowerCase();
				const allowed = ['png', 'jpg', 'jpeg', 'pdf'];
				if (!allowed.includes(ext)) {
					uni.showModal({
						title: '文件格式不支持',
						content: '请上传 PNG / JPG / PDF 格式的文件',
						showCancel: false
					});
					return;
				}
				this.licenseFile = file;
				this.licenseFileName = file.name || '营业执照';
				uni.showToast({ title: '上传成功', icon: 'success' });
			},

			// ================================================================
			// 合伙人推荐 - 提交表单（对接后端 /api/cooperations）
			// ================================================================
			async submitForm() {
				if (!this.isFormValid) {
					uni.showToast({ title: '请完善所有必填项', icon: 'none' });
					return;
				}
				if (this.partnerSubmitting) return;
				this.partnerSubmitting = true;
				try {
					const gk = this.getOrCreateGuestKey();
					const pf = this.partnerForm;
					const res = await cooperationApi.submit({
						coop_type: 'partner_referral',
						org_name: pf.companyName.trim(),
						contact_name: pf.contactName.trim(),
						contact_phone: pf.phone.trim(),
						region: pf.region.trim(),
						coop_direction: (pf.level || '金牌') + '合伙人推荐',
						summary: pf.intro.trim(),
						guest_key: gk,
						meta: {
							level: pf.level || '金牌',
							recommender_type: pf.recommenderType || 'sales',
							invite_code: (pf.inviteCode || '').trim(),
							license_file_name: this.licenseFileName || '',
						},
					});
					uni.removeStorageSync(this.cacheKey);
					this.partnerForm = {
						companyName: '',
						level: '金牌',
						contactName: '',
						phone: '',
						region: '',
						intro: '',
						recommenderType: 'sales',
						inviteCode: '',
					};
					this.licenseFile = null;
					this.licenseFileName = '';
					uni.showToast({ title: (res && res.message) || '推荐已提交', icon: 'success' });
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '提交失败，请稍后重试', icon: 'none' });
				} finally {
					this.partnerSubmitting = false;
				}
			},

			// ================================================================
			// 设置页面 - 编辑个人信息
			// ================================================================
			editProfile() {
				uni.showToast({ title: '编辑个人信息', icon: 'none' });
			},

			// ================================================================
			// 设置页面 - 退出登录
			// ================================================================
			handleLogout() {
				uni.showModal({
					title: '提示',
					content: '确定要退出登录吗？',
					success: (res) => {
						if (res.confirm) {
							this.doLogout();
						}
					}
				});
			},
			async doLogout() {
				// 先通知后端撤销当前 token（失败也不影响本地登出）
				try {
					await authApi.logout();
				} catch (e) {
					// 后端不可达/token 已失效：仍清除本地登录态
				}
				this.userName = '';
				// 清除后端登录态（token & 用户信息）
				uni.removeStorageSync(TOKEN_KEY);
				uni.removeStorageSync(CUSTOMER_KEY);
				this.invoiceList = [];
				uni.showToast({ title: '已退出', icon: 'success' });
				this.currentPage = 'main';
			}
		}
	};
</script>

<style scoped>
	/* ================================================================ */
	/* 全局 - 页面固定高度 */
	/* ================================================================ */
	.mine-page {
		height: 100vh;
		background: #f5f8fc;
		overflow: hidden;
		display: flex;
		flex-direction: column;
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
	}

	/* ================================================================ */
	/* 主页面内容（可滚动） */
	/* ================================================================ */
	.main-content {
		flex: 1;
		padding: 0 24rpx 0;
		overflow-y: auto;
		box-sizing: border-box;
		padding-bottom: 180rpx;
	}

	/* ===== 顶部用户信息 ===== */
	.user-header {
		background: linear-gradient(135deg, #7AB8F7, #D4EAFF);
		border-radius: 0 0 48rpx 48rpx;
		margin: 0 -24rpx 24rpx;
		padding: 88rpx 32rpx 40rpx;
		position: relative;
		flex-shrink: 0;
	}
	.user-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
	}
	.user-left {
		display: flex;
		align-items: center;
		cursor: pointer;
	}
	.avatar-wrapper {
		width: 96rpx;
		height: 96rpx;
		border-radius: 50%;
		overflow: hidden;
		border: 4rpx solid rgba(255, 255, 255, 0.6);
		background: #ffffff;
		flex-shrink: 0;
	}
	.avatar {
		width: 100%;
		height: 100%;
		display: block;
	}
	.user-info {
		margin-left: 20rpx;
	}
	.user-name {
		font-size: 34rpx;
		font-weight: 700;
		color: #1A2A3A;
		display: block;
		line-height: 1.2;
	}
	.user-desc {
		font-size: 22rpx;
		color: rgba(26, 42, 58, 0.6);
		display: block;
		margin-top: 4rpx;
	}
	.user-right {
		display: flex;
		align-items: center;
	}
	.vip-badge {
		background: rgba(255, 215, 0, 0.25);
		border: 2rpx solid #FFD700;
		border-radius: 30rpx;
		padding: 4rpx 18rpx 4rpx 12rpx;
		display: flex;
		align-items: center;
		cursor: pointer;
	}
	.vip-icon {
		font-size: 28rpx;
		margin-right: 4rpx;
	}
	.vip-text {
		font-size: 20rpx;
		color: #1A2A3A;
		font-weight: 600;
	}

	/* ===== 双卡片 ===== */
	.double-card {
		display: flex;
		gap: 16rpx;
		margin-bottom: 24rpx;
	}
	.card-item {
		flex: 1;
		background: #FFFFFF;
		border-radius: 24rpx;
		padding: 24rpx 20rpx;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.08);
		display: flex;
		align-items: center;
		cursor: pointer;
		transition: all 0.2s;
	}
	.card-item:active {
		background: #f0f7ff;
		transform: scale(0.98);
	}
	.card-icon {
		font-size: 40rpx;
		width: 56rpx;
		height: 56rpx;
		border-radius: 16rpx;
		background: #E8F4FD;
		display: flex;
		align-items: center;
		justify-content: center;
		margin-right: 16rpx;
		flex-shrink: 0;
	}
	.card-info {
		flex: 1;
	}
	.card-title {
		font-size: 26rpx;
		font-weight: 600;
		color: #1A2A3A;
		display: block;
	}
	.card-count {
		font-size: 20rpx;
		color: #6B7A8A;
		display: block;
		margin-top: 2rpx;
	}
	.card-arrow {
		font-size: 32rpx;
		color: #C0C8D4;
	}

	/* ===== 订单模块 ===== */
	.order-module,
	.function-module {
		background: #FFFFFF;
		border-radius: 24rpx;
		padding: 20rpx 24rpx;
		margin-bottom: 24rpx;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.06);
	}
	.module-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16rpx;
	}
	.module-title {
		font-size: 28rpx;
		font-weight: 700;
		color: #1A2A3A;
	}
	.module-more {
		font-size: 22rpx;
		color: #4291F6;
		cursor: pointer;
	}
	.order-categories {
		display: flex;
		justify-content: space-around;
	}
	.order-item {
		display: flex;
		flex-direction: column;
		align-items: center;
		cursor: pointer;
		padding: 4rpx 0;
	}
	.order-item:active {
		opacity: 0.6;
	}
	.order-icon {
		font-size: 40rpx;
		margin-bottom: 6rpx;
	}
	.order-label {
		font-size: 22rpx;
		color: #4A5A6A;
	}

	/* ===== 常用功能 ===== */
	.function-grid {
		display: grid;
		grid-template-columns: repeat(4, 1fr);
		gap: 20rpx 0;
		margin-top: 4rpx;
	}
	.function-divider {
		height: 1rpx;
		background: #EEF2F7;
		margin: 18rpx 0 22rpx;
	}
	.function-grid-partner {
		margin-top: 0;
	}
	.func-item {
		display: flex;
		flex-direction: column;
		align-items: center;
		cursor: pointer;
		padding: 8rpx 0;
	}
	.func-item:active {
		opacity: 0.6;
	}
	.func-icon {
		font-size: 40rpx;
		width: 60rpx;
		height: 60rpx;
		border-radius: 16rpx;
		background: #F0F7FD;
		display: flex;
		align-items: center;
		justify-content: center;
		margin-bottom: 8rpx;
	}
	.func-label {
		font-size: 22rpx;
		color: #4A5A6A;
		text-align: center;
		line-height: 1.2;
	}

	/* ===== 底部安全区 ===== */
	.bottom-safe {
		height: 20rpx;
	}

	/* ================================================================ */
	/* 子页面通用导航栏 */
	/* ================================================================ */
	.sub-page {
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: #ffffff;
		z-index: 100;
		display: flex;
		flex-direction: column;
	}
	.sub-nav {
		display: flex;
		align-items: center;
		background: #ffffff;
		padding: 88rpx 24rpx 20rpx;
		height: auto;
		flex-shrink: 0;
		border-bottom: 1rpx solid #f1f5f9;
	}
	.nav-back {
		display: flex;
		align-items: center;
		gap: 4rpx;
		cursor: pointer;
		padding: 8rpx 16rpx 8rpx 8rpx;
		border-radius: 8rpx;
	}
	.nav-back:active {
		background: #f1f5f9;
	}
	.back-icon {
		font-size: 48rpx;
		font-weight: 300;
		color: #1A2A3A;
		line-height: 1;
	}
	.back-text {
		font-size: 28rpx;
		color: #1A2A3A;
		font-weight: 500;
	}
	.nav-title {
		flex: 1;
		text-align: center;
		font-size: 34rpx;
		font-weight: 700;
		color: #1A2A3A;
		letter-spacing: 2rpx;
	}
	.nav-placeholder {
		width: 120rpx;
		flex-shrink: 0;
	}
	.sub-content {
		flex: 1;
		padding: 0 24rpx 0;
		box-sizing: border-box;
	}

	/* ================================================================ */
	/* 优惠券子页面 */
	/* ================================================================ */
	.coupon-tabs {
		display: flex;
		gap: 8rpx;
		background: #f5f8fc;
		border-radius: 16rpx;
		padding: 8rpx 12rpx;
		margin: 20rpx 0 16rpx;
	}
	.coupon-tabs .tab-item {
		flex: 1;
		text-align: center;
		padding: 12rpx 0;
		font-size: 24rpx;
		color: #6B7A8A;
		border-radius: 12rpx;
		cursor: pointer;
	}
	.coupon-tabs .tab-item.active {
		background: #4291F6;
		color: #ffffff;
	}
	.coupon-card {
		background: #ffffff;
		border-radius: 16rpx;
		display: flex;
		margin-bottom: 16rpx;
		overflow: hidden;
		border-left: 6rpx solid #4291F6;
	}
	.coupon-left {
		width: 160rpx;
		padding: 20rpx 16rpx;
		background: #F8FBFF;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		flex-shrink: 0;
	}
	.coupon-amount {
		font-size: 40rpx;
		font-weight: 700;
		color: #4291F6;
	}
	.coupon-condition {
		font-size: 20rpx;
		color: #6B7A8A;
		margin-top: 4rpx;
	}
	.coupon-right {
		flex: 1;
		padding: 20rpx 16rpx;
		display: flex;
		flex-direction: column;
		justify-content: space-between;
	}
	.coupon-name {
		font-size: 26rpx;
		font-weight: 600;
		color: #1A2A3A;
	}
	.coupon-expire {
		font-size: 20rpx;
		color: #6B7A8A;
	}
	.coupon-status {
		font-size: 20rpx;
		padding: 4rpx 20rpx;
		border-radius: 20rpx;
		text-align: center;
		align-self: flex-end;
		margin-top: 6rpx;
	}
	.coupon-status.available {
		background: #E8F4FD;
		color: #4291F6;
	}
	.coupon-status.used {
		background: #f1f5f9;
		color: #6B7A8A;
	}
	.coupon-status.expired {
		background: #f1f5f9;
		color: #6B7A8A;
	}

	/* ================================================================ */
	/* 开票中心子页面 */
	/* ================================================================ */
	.invoice-stats {
		display: flex;
		background: #ffffff;
		border-radius: 16rpx;
		padding: 24rpx 0;
		margin: 20rpx 0 16rpx;
	}
	.invoice-stats .stat-item {
		flex: 1;
		text-align: center;
	}
	.invoice-stats .stat-num {
		font-size: 36rpx;
		font-weight: 700;
		color: #1A2A3A;
		display: block;
	}
	.invoice-stats .stat-label {
		font-size: 20rpx;
		color: #6B7A8A;
	}
	.stat-divider {
		width: 2rpx;
		height: 48rpx;
		background: #E8EDF3;
		align-self: center;
	}
	.invoice-card {
		background: #ffffff;
		border-radius: 16rpx;
		padding: 20rpx;
		margin-bottom: 16rpx;
	}
	.invoice-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding-bottom: 12rpx;
		border-bottom: 1px solid #f1f5f9;
	}
	.invoice-id {
		font-size: 24rpx;
		font-weight: 600;
		color: #1A2A3A;
	}
	.invoice-status {
		font-size: 20rpx;
		padding: 2rpx 14rpx;
		border-radius: 12rpx;
	}
	.invoice-status.applied {
		background: #fef3c7;
		color: #d97706;
	}
	.invoice-status.issued {
		background: #d1fae5;
		color: #065f46;
	}
	.invoice-body {
		display: flex;
		justify-content: space-between;
		padding: 12rpx 0;
	}
	.invoice-amount {
		font-size: 28rpx;
		font-weight: 700;
		color: #ef4444;
	}
	.invoice-date {
		font-size: 22rpx;
		color: #6B7A8A;
	}
	.invoice-footer {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding-top: 12rpx;
		border-top: 1px solid #f1f5f9;
	}
	.invoice-type {
		font-size: 20rpx;
		color: #6B7A8A;
	}
	.invoice-actions {
		display: flex;
		gap: 12rpx;
	}
	.invoice-actions .action-btn {
		font-size: 20rpx;
		padding: 4rpx 20rpx;
		border-radius: 20rpx;
		background: #4291F6;
		color: #ffffff;
		cursor: pointer;
	}
	.invoice-actions .action-btn:active {
		opacity: 0.7;
	}
	.btn-apply-invoice {
		background: #4291F6;
		border-radius: 20rpx;
		padding: 24rpx;
		text-align: center;
		color: #ffffff;
		font-size: 28rpx;
		font-weight: 600;
		cursor: pointer;
		margin-top: 8rpx;
	}
	.btn-apply-invoice:active {
		opacity: 0.8;
	}

	/* ===== 申请开票弹窗(B-15) ===== */
	.inv-mask {
		position: fixed;
		left: 0;
		top: 0;
		right: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.45);
		z-index: 9999;
		display: flex;
		align-items: center;
		justify-content: center;
	}
	.inv-modal {
		width: 640rpx;
		background: #ffffff;
		border-radius: 24rpx;
		padding: 40rpx 32rpx 24rpx;
		box-sizing: border-box;
	}
	.inv-modal-title {
		display: block;
		font-size: 32rpx;
		font-weight: 600;
		color: #1A2A3A;
		text-align: center;
		margin-bottom: 24rpx;
	}
	.inv-field {
		margin-bottom: 22rpx;
	}
	.inv-field-label {
		display: block;
		font-size: 24rpx;
		color: #6B7A8A;
		margin-bottom: 10rpx;
	}
	.inv-field-value {
		padding: 20rpx 24rpx;
		background: #f5f8fc;
		border-radius: 12rpx;
		font-size: 26rpx;
		color: #1A2A3A;
	}
	.inv-input {
		width: 100%;
		padding: 20rpx 24rpx;
		background: #f5f8fc;
		border-radius: 12rpx;
		font-size: 26rpx;
		box-sizing: border-box;
	}
	.inv-type-row {
		display: flex;
		gap: 20rpx;
	}
	.inv-type {
		flex: 1;
		text-align: center;
		padding: 18rpx 0;
		border: 1rpx solid #e2e8f0;
		border-radius: 12rpx;
		font-size: 26rpx;
		color: #6B7A8A;
	}
	.inv-type.on {
		border-color: #4291F6;
		color: #4291F6;
		background: #eef5ff;
	}
	.inv-modal-footer {
		display: flex;
		gap: 20rpx;
		margin-top: 28rpx;
	}
	.inv-cancel {
		flex: 1;
		text-align: center;
		padding: 22rpx 0;
		border: 1rpx solid #e2e8f0;
		border-radius: 40rpx;
		font-size: 28rpx;
		color: #6B7A8A;
	}
	.inv-confirm {
		flex: 2;
		text-align: center;
		padding: 22rpx 0;
		background: #4291F6;
		color: #ffffff;
		border-radius: 40rpx;
		font-size: 28rpx;
		font-weight: 600;
	}

	/* ================================================================ */
	/* 地址管理子页面 */
	/* ================================================================ */
	.address-list-page {
		flex: 1;
		display: flex;
		flex-direction: column;
	}
	.address-list-page .sub-content {
		flex: 1;
		padding: 20rpx 24rpx 0;
	}
	.address-footer {
		background: #ffffff;
		padding: 16rpx 24rpx;
		border-top: 1rpx solid #f1f5f9;
		flex-shrink: 0;
	}
	.btn-add-address {
		background: #4291F6;
		border-radius: 24rpx;
		padding: 24rpx;
		text-align: center;
		color: #ffffff;
		font-size: 28rpx;
		font-weight: 600;
		cursor: pointer;
	}
	.btn-add-address:active {
		opacity: 0.8;
	}
	.address-card {
		background: #ffffff;
		border-radius: 16rpx;
		padding: 20rpx;
		margin-bottom: 16rpx;
		box-shadow: 0 2rpx 8rpx rgba(0, 0, 0, 0.04);
	}
	.address-info {
		margin-bottom: 12rpx;
	}
	.address-top {
		display: flex;
		align-items: center;
		gap: 12rpx;
		margin-bottom: 4rpx;
	}
	.address-name {
		font-size: 26rpx;
		font-weight: 600;
		color: #1A2A3A;
	}
	.address-phone {
		font-size: 22rpx;
		color: #6B7A8A;
	}
	.address-tag {
		font-size: 18rpx;
		color: #4291F6;
		background: #E8F4FD;
		padding: 2rpx 12rpx;
		border-radius: 12rpx;
		font-weight: 500;
	}
	.address-detail {
		font-size: 22rpx;
		color: #4A5A6A;
		display: block;
		line-height: 1.4;
	}
	.address-actions {
		display: flex;
		gap: 16rpx;
		justify-content: flex-end;
		padding-top: 12rpx;
		border-top: 1rpx solid #f1f5f9;
	}
	.action-edit,
	.action-delete {
		font-size: 20rpx;
		padding: 4rpx 16rpx;
		border-radius: 16rpx;
		cursor: pointer;
	}
	.action-edit {
		background: #E8F4FD;
		color: #4291F6;
	}
	.action-delete {
		background: #fee2e2;
		color: #ef4444;
	}
	.action-edit:active,
	.action-delete:active {
		opacity: 0.6;
	}

	/* 地址表单 */
	.address-form-page {
		flex: 1;
	}
	.address-form-page .sub-content {
		padding: 20rpx 24rpx 0;
	}
	.location-btn-wrap {
		display: flex;
		align-items: center;
		justify-content: center;
		gap: 8rpx;
		background: #F0F7FD;
		border-radius: 40rpx;
		padding: 16rpx 0;
		margin-bottom: 16rpx;
		border: 1rpx solid #E8F4FD;
		cursor: pointer;
	}
	.location-btn-wrap:active {
		background: #DBEAFE;
	}
	.location-icon {
		font-size: 32rpx;
	}
	.location-text {
		font-size: 26rpx;
		color: #4291F6;
		font-weight: 500;
	}
	.form-group {
		background: #ffffff;
		border-radius: 16rpx;
		padding: 0 20rpx;
		margin-bottom: 20rpx;
	}
	.form-item {
		display: flex;
		align-items: center;
		padding: 20rpx 0;
		border-bottom: 1rpx solid #f1f5f9;
	}
	.form-item:last-child {
		border-bottom: none;
	}
	.form-label {
		width: 140rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		flex-shrink: 0;
	}
	.form-input {
		flex: 1;
		font-size: 26rpx;
		color: #1A2A3A;
	}
	.form-textarea {
		flex: 1;
		height: 120rpx;
		font-size: 26rpx;
		color: #1A2A3A;
	}
	.switch-item {
		justify-content: space-between;
	}
	.switch-item .form-label {
		width: auto;
	}
	.form-actions {
		display: flex;
		gap: 16rpx;
		margin: 20rpx 0;
	}
	.btn-cancel,
	.btn-save {
		flex: 1;
		padding: 24rpx;
		border-radius: 24rpx;
		text-align: center;
		font-size: 28rpx;
		font-weight: 600;
		cursor: pointer;
	}
	.btn-cancel {
		background: #f1f5f9;
		color: #6B7A8A;
	}
	.btn-save {
		background: #4291F6;
		color: #ffffff;
	}
	.btn-cancel:active,
	.btn-save:active {
		opacity: 0.8;
	}

	/* ================================================================ */
	/* 合作申请卡片样式 */
	/* ================================================================ */
	.cooperate-page .sub-content {
		padding-top: 30rpx;
	}
	.cooperate-card {
		background: #ffffff;
		border-radius: 24rpx;
		margin: 30rpx 0;
		padding: 32rpx;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.06);
		border: 1rpx solid #f0f4f8;
	}
	.card-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16rpx;
	}
	.header-left {
		display: flex;
		align-items: center;
		gap: 16rpx;
	}
	.avatar-icon {
		width: 64rpx;
		height: 64rpx;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 36rpx;
		flex-shrink: 0;
	}
	.cooperate-name {
		font-size: 30rpx;
		font-weight: 600;
		color: #1A2A3A;
	}
	.btn-apply {
		background: #4291F6;
		color: #ffffff;
		font-size: 24rpx;
		font-weight: 500;
		padding: 10rpx 28rpx;
		border-radius: 40rpx;
		cursor: pointer;
		white-space: nowrap;
	}
	.btn-apply:active {
		opacity: 0.7;
	}
	.cooperate-desc {
		display: block;
		font-size: 24rpx;
		color: #6B7A8A;
		margin-bottom: 16rpx;
		line-height: 1.5;
	}
	.benefit-list {
		display: flex;
		flex-direction: column;
		gap: 10rpx;
	}
	.benefit-item {
		display: flex;
		align-items: center;
		gap: 12rpx;
	}
	.benefit-item .dot {
		font-size: 24rpx;
		color: #4291F6;
		font-weight: 700;
	}
	.benefit-text {
		font-size: 24rpx;
		color: #4A5A6A;
		line-height: 1.4;
	}
	/* 合作申请记录列表 */
	.coop-history-section {
		margin-top: 36rpx;
		background: #ffffff;
		border-radius: 24rpx;
		padding: 28rpx;
		border: 1rpx solid #f0f4f8;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.05);
	}
	.coop-history-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 20rpx;
	}
	.coop-history-title {
		font-size: 28rpx;
		font-weight: 700;
		color: #1A2A3A;
	}
	.coop-history-refresh {
		font-size: 24rpx;
		color: #4291F6;
		cursor: pointer;
	}
	.coop-history-empty {
		font-size: 24rpx;
		color: #8C9AA8;
		text-align: center;
		padding: 32rpx 0;
	}
	.coop-history-list {
		display: flex;
		flex-direction: column;
		gap: 20rpx;
	}
	.coop-record-card {
		background: #F8FAFC;
		border-radius: 16rpx;
		padding: 22rpx 24rpx;
		border: 1rpx solid #EEF2F7;
	}
	.coop-record-top {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 10rpx;
	}
	.coop-record-tags {
		display: flex;
		align-items: center;
		gap: 10rpx;
		flex-wrap: wrap;
	}
	.coop-type-tag {
		font-size: 22rpx;
		font-weight: 600;
		color: #4291F6;
		background: #E8F4FD;
		padding: 4rpx 14rpx;
		border-radius: 10rpx;
	}
	.coop-dir-tag {
		font-size: 22rpx;
		color: #4A5A6A;
		background: #EEF2F7;
		padding: 4rpx 14rpx;
		border-radius: 10rpx;
	}
	.coop-status-badge {
		font-size: 22rpx;
		font-weight: 600;
		padding: 4rpx 16rpx;
		border-radius: 20rpx;
	}
	.coop-status-badge.status-pending {
		background: #FEF3C7;
		color: #D97706;
	}
	.coop-status-badge.status-contacting {
		background: #DBEAFE;
		color: #2563EB;
	}
	.coop-status-badge.status-approved {
		background: #D1FAE5;
		color: #059669;
	}
	.coop-status-badge.status-rejected {
		background: #FEE2E2;
		color: #DC2626;
	}
	.coop-record-org {
		display: block;
		font-size: 28rpx;
		font-weight: 600;
		color: #1A2A3A;
		margin-bottom: 8rpx;
	}
	.coop-record-meta {
		display: flex;
		justify-content: space-between;
		font-size: 22rpx;
		color: #8C9AA8;
	}
	.coop-record-note {
		margin-top: 14rpx;
		padding-top: 12rpx;
		border-top: 1rpx dashed #E2E8F0;
		font-size: 24rpx;
		line-height: 1.5;
	}
	.coop-note-label {
		font-weight: 600;
		color: #4291F6;
	}
	.coop-note-content {
		color: #334155;
	}
	/* 合作申请专属表单弹窗 */
	.coop-modal-mask {
		position: fixed;
		left: 0;
		right: 0;
		top: 0;
		bottom: 0;
		background: rgba(15, 23, 42, 0.55);
		z-index: 999;
		display: flex;
		align-items: flex-end;
		justify-content: center;
	}
	.coop-modal-sheet {
		width: 100%;
		max-height: 86vh;
		background: #FFFFFF;
		border-top-left-radius: 32rpx;
		border-top-right-radius: 32rpx;
		display: flex;
		flex-direction: column;
		overflow: hidden;
	}
	.coop-modal-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: 28rpx 32rpx 22rpx;
		border-bottom: 1rpx solid #EEF2F7;
	}
	.coop-modal-title-wrap {
		display: flex;
		align-items: center;
		gap: 16rpx;
	}
	.coop-modal-emoji {
		font-size: 44rpx;
	}
	.coop-modal-title {
		display: block;
		font-size: 32rpx;
		font-weight: 700;
		color: #1A2A3A;
	}
	.coop-modal-subtitle {
		display: block;
		font-size: 22rpx;
		color: #6B7A8A;
		margin-top: 4rpx;
	}
	.coop-modal-close {
		font-size: 36rpx;
		color: #8C9AA8;
		padding: 8rpx 12rpx;
		cursor: pointer;
	}
	.coop-modal-body {
		padding: 24rpx 32rpx;
		max-height: 64vh;
		box-sizing: border-box;
	}
	.coop-raas-banner {
		display: flex;
		justify-content: space-between;
		align-items: center;
		background: #F0F7FF;
		border: 1rpx solid #BFDBFE;
		border-radius: 16rpx;
		padding: 18rpx 22rpx;
		margin-bottom: 24rpx;
		cursor: pointer;
	}
	.coop-raas-banner-title {
		display: block;
		font-size: 24rpx;
		font-weight: 600;
		color: #1D4ED8;
	}
	.coop-raas-banner-desc {
		display: block;
		font-size: 20rpx;
		color: #3B82F6;
		margin-top: 4rpx;
	}
	.coop-raas-banner-link {
		font-size: 24rpx;
		font-weight: 600;
		color: #2563EB;
		white-space: nowrap;
		margin-left: 16rpx;
	}
	.coop-field {
		margin-bottom: 24rpx;
	}
	.coop-field-row {
		display: flex;
		gap: 20rpx;
	}
	.coop-field.half {
		flex: 1;
	}
	.coop-label {
		display: block;
		font-size: 24rpx;
		font-weight: 600;
		color: #334155;
		margin-bottom: 10rpx;
	}
	.coop-label.required::after {
		content: ' *';
		color: #EF4444;
	}
	.coop-input {
		width: 100%;
		height: 76rpx;
		background: #F8FAFC;
		border: 1rpx solid #E2E8F0;
		border-radius: 14rpx;
		padding: 0 22rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		box-sizing: border-box;
	}
	.coop-textarea {
		width: 100%;
		height: 150rpx;
		background: #F8FAFC;
		border: 1rpx solid #E2E8F0;
		border-radius: 14rpx;
		padding: 16rpx 22rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		box-sizing: border-box;
	}
	.coop-chips {
		display: flex;
		flex-wrap: wrap;
		gap: 14rpx;
	}
	.coop-chip {
		font-size: 24rpx;
		color: #475569;
		background: #F1F5F9;
		border: 1rpx solid #E2E8F0;
		padding: 10rpx 22rpx;
		border-radius: 30rpx;
		cursor: pointer;
	}
	.coop-chip.active {
		background: #E8F4FD;
		color: #2563EB;
		border-color: #60A5FA;
		font-weight: 600;
	}
	.coop-modal-footer {
		display: flex;
		gap: 20rpx;
		padding: 20rpx 32rpx 36rpx;
		border-top: 1rpx solid #EEF2F7;
		background: #FFFFFF;
	}
	.coop-btn-cancel {
		flex: 1;
		height: 80rpx;
		line-height: 80rpx;
		text-align: center;
		background: #F1F5F9;
		color: #64748B;
		border-radius: 40rpx;
		font-size: 28rpx;
		font-weight: 600;
		cursor: pointer;
	}
	.coop-btn-submit {
		flex: 2;
		height: 80rpx;
		line-height: 80rpx;
		text-align: center;
		background: linear-gradient(135deg, #4291F6, #2563EB);
		color: #FFFFFF;
		border-radius: 40rpx;
		font-size: 28rpx;
		font-weight: 600;
		cursor: pointer;
	}
	.coop-btn-submit.disabled {
		opacity: 0.6;
	}

	/* ================================================================ */
	/* 合伙人推荐页面专属样式 */
	/* ================================================================ */
	.partner-page .sub-content {
		padding-top: 20rpx;
	}
	.page-title {
		font-size: 40rpx;
		font-weight: 700;
		color: #1A2A3A;
		margin-bottom: 30rpx;
		letter-spacing: 2rpx;
	}
	.form-container {
		background: #ffffff;
		border-radius: 24rpx;
		padding: 30rpx 24rpx 10rpx;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.05);
	}
	.form-section {
		margin-bottom: 32rpx;
	}
	.section-title {
		font-size: 28rpx;
		font-weight: 600;
		color: #1A2A3A;
		margin-bottom: 20rpx;
		padding-bottom: 12rpx;
		border-bottom: 2rpx solid #E8EDF3;
		position: relative;
	}
	.section-title::after {
		content: '';
		position: absolute;
		bottom: -2rpx;
		left: 0;
		width: 60rpx;
		height: 4rpx;
		background: #4291F6;
		border-radius: 4rpx;
	}
	.partner-page .form-item {
		display: flex;
		align-items: center;
		margin-bottom: 18rpx;
	}
	.partner-page .form-label {
		width: 180rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		font-weight: 500;
		flex-shrink: 0;
		margin-bottom: 0;
	}
	.partner-page .form-label.required::after {
		content: ' *';
		color: #ef4444;
	}
	.partner-page .form-input,
	.partner-page .picker-input {
		flex: 1;
		padding: 16rpx 20rpx;
		background: #f8fafc;
		border-radius: 16rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		border: 1rpx solid #e2e8f0;
		min-height: 56rpx;
	}
	.partner-page .form-input:focus,
	.partner-page .picker-input:focus {
		border-color: #4291F6;
	}
	.partner-page .picker-input:active {
		background: #edf2f7;
	}
	/* 上传和文本域特殊处理 */
	.partner-page .upload-item,
	.partner-page .textarea-item {
		flex-direction: column;
		align-items: stretch;
	}
	.partner-page .upload-item .form-label,
	.partner-page .textarea-item .form-label {
		width: auto;
		margin-bottom: 8rpx;
	}
	.partner-page .upload-area {
		width: 100%;
		border: 2rpx dashed #cbd5e1;
		border-radius: 16rpx;
		padding: 26rpx 0;
		background: #fafcff;
		text-align: center;
		cursor: pointer;
	}
	.partner-page .upload-area:active {
		background: #f0f4f8;
		border-color: #4291F6;
	}
	.partner-page .upload-box {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 6rpx;
	}
	.partner-page .upload-icon {
		font-size: 44rpx;
		color: #4291F6;
	}
	.partner-page .upload-text {
		font-size: 26rpx;
		color: #1A2A3A;
	}
	.partner-page .upload-tip {
		font-size: 20rpx;
		color: #94a3b8;
	}
	.partner-page .upload-status {
		font-size: 22rpx;
		color: #22c55e;
		margin-top: 6rpx;
	}
	.partner-page .textarea-wrapper {
		position: relative;
	}
	.partner-page .form-textarea {
		width: 100%;
		height: 140rpx;
		padding: 16rpx 20rpx;
		background: #f8fafc;
		border-radius: 16rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		border: 1rpx solid #e2e8f0;
		resize: none;
		box-sizing: border-box;
	}
	.partner-page .form-textarea:focus {
		border-color: #4291F6;
	}
	.partner-page .char-count {
		text-align: right;
		font-size: 20rpx;
		color: #94a3b8;
		margin-top: 4rpx;
	}
	.partner-page .radio-group {
		display: flex;
		gap: 30rpx;
	}
	.partner-page .radio-label {
		font-size: 26rpx;
		color: #1A2A3A;
		display: flex;
		align-items: center;
		gap: 8rpx;
	}
	.partner-page .radio-label radio {
		transform: scale(0.8);
	}
	.partner-page .form-actions {
		padding: 24rpx 0 10rpx;
	}
	.partner-page .btn-submit {
		width: 100%;
		padding: 24rpx 0;
		background: #4291F6;
		border-radius: 40rpx;
		text-align: center;
		font-size: 32rpx;
		font-weight: 600;
		color: #ffffff;
		cursor: pointer;
	}
	.partner-page .btn-submit.disabled {
		background: #b0c4de;
		pointer-events: none;
	}
	.partner-page .btn-submit:active {
		background: #3373c7;
	}

	/* 合作与供应链专属标签与签发码样式 */
	.coop-verified-tag {
		font-size: 20rpx;
		color: #059669;
		background: #ECFDF5;
		border: 1rpx solid #A7F3D0;
		padding: 2rpx 12rpx;
		border-radius: 8rpx;
		font-weight: 500;
	}
	.coop-issued-code-box {
		margin-top: 14rpx;
		padding: 14rpx 18rpx;
		background: #F0FDF4;
		border: 1rpx solid #86EFAC;
		border-radius: 14rpx;
	}
	.coop-issued-code-main {
		display: flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 8rpx;
	}
	.coop-issued-label {
		font-size: 22rpx;
		color: #166534;
		font-weight: 600;
	}
	.coop-issued-code {
		font-size: 26rpx;
		font-family: monospace;
		font-weight: 700;
		color: #15803D;
		background: #DCFCE7;
		padding: 2rpx 10rpx;
		border-radius: 6rpx;
		letter-spacing: 2rpx;
	}
	.coop-issued-exp {
		font-size: 20rpx;
		color: #15803D;
		margin-left: 10rpx;
	}
	.coop-issued-actions {
		display: flex;
		justify-content: flex-end;
		gap: 16rpx;
		margin-top: 12rpx;
	}
	.coop-issued-btn {
		font-size: 22rpx;
		padding: 6rpx 18rpx;
		border-radius: 20rpx;
		background: #FFFFFF;
		color: #166534;
		border: 1rpx solid #86EFAC;
		cursor: pointer;
	}
	.coop-issued-btn.primary {
		background: #16A34A;
		color: #FFFFFF;
		border: none;
		font-weight: 600;
	}
	.coop-inline-verify {
		display: flex;
		align-items: center;
		gap: 12rpx;
	}
	.coop-verify-btn {
		height: 76rpx;
		line-height: 76rpx;
		padding: 0 24rpx;
		background: #4291F6;
		color: #FFFFFF;
		border-radius: 14rpx;
		font-size: 24rpx;
		font-weight: 600;
		cursor: pointer;
		white-space: nowrap;
	}

	/* 表单字段集卡片与快捷标签通用样式 */
	.supplier-header-flex {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
	}
	.supplier-progress-badge {
		font-size: 20rpx;
		font-weight: 700;
		color: #2563EB;
		background: #EFF6FF;
		border: 1rpx solid #BFDBFE;
		padding: 4rpx 14rpx;
		border-radius: 20rpx;
		white-space: nowrap;
	}
	.supplier-progress-badge.ready {
		color: #15803D;
		background: #ECFDF5;
		border-color: #86EFAC;
	}
	.supplier-progress-bar-wrap {
		margin-bottom: 24rpx;
	}
	.supplier-progress-bar-track {
		height: 8rpx;
		background: #F1F5F9;
		border-radius: 4rpx;
		overflow: hidden;
	}
	.supplier-progress-bar-fill {
		height: 100%;
		background: linear-gradient(90deg, #3B82F6, #10B981);
		border-radius: 4rpx;
		transition: width 0.3s ease;
	}
	.supplier-progress-hint {
		display: block;
		font-size: 20rpx;
		color: #64748B;
		margin-top: 6rpx;
	}
	.mode-tab-title {
		display: block;
		font-size: 26rpx;
		font-weight: 700;
	}
	.mode-tab-desc {
		display: block;
		font-size: 18rpx;
		opacity: 0.8;
		margin-top: 2rpx;
	}
	.form-fieldset-card {
		background: #F8FAFC;
		border: 1rpx solid #E2E8F0;
		border-radius: 18rpx;
		padding: 24rpx 20rpx 12rpx;
		margin-bottom: 24rpx;
	}
	.form-fieldset-header {
		display: flex;
		align-items: center;
		gap: 12rpx;
		padding-bottom: 16rpx;
		margin-bottom: 20rpx;
		border-bottom: 1rpx solid #E2E8F0;
	}
	.form-fieldset-header.justify-between {
		justify-content: space-between;
	}
	.form-fieldset-icon {
		font-size: 32rpx;
	}
	.form-fieldset-title {
		display: block;
		font-size: 28rpx;
		font-weight: 700;
		color: #1E293B;
	}
	.form-fieldset-sub {
		display: block;
		font-size: 20rpx;
		color: #64748B;
		margin-top: 2rpx;
	}
	.coop-label-row {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 10rpx;
	}
	.coop-label-tip {
		font-size: 20rpx;
		color: #64748B;
	}
	.char-count-pill {
		font-size: 20rpx;
		color: #94A3B8;
		font-family: monospace;
	}
	.btn-quick-autofill {
		display: flex;
		align-items: center;
		gap: 6rpx;
		font-size: 22rpx;
		font-weight: 600;
		color: #2563EB;
		background: #EFF6FF;
		border: 1rpx solid #BFDBFE;
		padding: 6rpx 16rpx;
		border-radius: 20rpx;
		cursor: pointer;
	}
	.btn-quick-autofill-sm {
		font-size: 20rpx;
		font-weight: 600;
		color: #2563EB;
		background: #EFF6FF;
		border: 1rpx solid #BFDBFE;
		padding: 4rpx 14rpx;
		border-radius: 16rpx;
		cursor: pointer;
	}
	.coop-contact-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 16rpx;
		padding-top: 10rpx;
		border-top: 1rpx dashed #E2E8F0;
	}
	.coop-contact-title {
		font-size: 26rpx;
		font-weight: 700;
		color: #1E293B;
	}
	.quick-chip-row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 10rpx;
		margin-top: 10rpx;
	}
	.quick-chip-block {
		margin-top: 10rpx;
	}
	.quick-chips-wrap {
		display: flex;
		flex-wrap: wrap;
		gap: 10rpx;
		margin-top: 8rpx;
	}
	.quick-chip-label {
		font-size: 20rpx;
		color: #64748B;
	}
	.quick-chip {
		font-size: 20rpx;
		color: #2563EB;
		background: #EFF6FF;
		border: 1rpx solid #DBEAFE;
		padding: 4rpx 14rpx;
		border-radius: 8rpx;
		cursor: pointer;
	}
	.quick-chip:active {
		background: #BFDBFE;
	}
	.supplier-license-uploader {
		background: #FFFFFF;
		border: 1rpx dashed #CBD5E1;
		border-radius: 16rpx;
		padding: 24rpx;
		cursor: pointer;
	}
	.supplier-license-empty {
		display: flex;
		flex-direction: column;
		align-items: center;
		text-align: center;
	}
	.license-upload-icon {
		font-size: 40rpx;
		margin-bottom: 6rpx;
	}
	.license-upload-text {
		font-size: 24rpx;
		font-weight: 600;
		color: #334155;
	}
	.license-upload-sub {
		font-size: 20rpx;
		color: #94A3B8;
		margin-top: 4rpx;
	}
	.supplier-license-picked {
		display: flex;
		align-items: center;
		gap: 14rpx;
	}
	.license-picked-icon {
		font-size: 36rpx;
	}
	.license-picked-info {
		flex: 1;
		overflow: hidden;
	}
	.license-picked-name {
		display: block;
		font-size: 24rpx;
		font-weight: 600;
		color: #0F172A;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.license-picked-tip {
		display: block;
		font-size: 20rpx;
		color: #16A34A;
		margin-top: 2rpx;
	}
	.license-picked-del {
		font-size: 22rpx;
		color: #EF4444;
		padding: 8rpx 16rpx;
		background: #FEF2F2;
		border-radius: 10rpx;
	}
	.supplier-submit-privacy-tip {
		display: block;
		text-align: center;
		font-size: 20rpx;
		color: #94A3B8;
		margin-top: 14rpx;
		line-height: 1.4;
	}
	.font-mono {
		font-family: monospace;
	}

	/* ================================================================ */
	/* 供应链受邀入驻页面专属样式 */
	/* ================================================================ */
	.supplier-page .sub-content {
		padding: 20rpx;
		box-sizing: border-box;
	}
	.supplier-hero-card {
		background: linear-gradient(135deg, #0F172A 0%, #1E3A8A 100%);
		border-radius: 24rpx;
		padding: 32rpx 28rpx;
		color: #FFFFFF;
		margin-bottom: 24rpx;
		box-shadow: 0 8rpx 24rpx rgba(15, 23, 42, 0.2);
	}
	.supplier-hero-top {
		display: flex;
		justify-content: space-between;
		align-items: flex-start;
	}
	.supplier-hero-badge {
		display: inline-block;
		font-size: 18rpx;
		font-weight: 700;
		letter-spacing: 1.5rpx;
		background: rgba(96, 165, 250, 0.25);
		color: #93C5FD;
		border: 1rpx solid rgba(147, 197, 253, 0.4);
		padding: 4rpx 12rpx;
		border-radius: 6rpx;
		margin-bottom: 8rpx;
	}
	.supplier-hero-title {
		display: block;
		font-size: 34rpx;
		font-weight: 700;
		color: #FFFFFF;
	}
	.supplier-hero-icon {
		font-size: 48rpx;
	}
	.supplier-hero-desc {
		display: block;
		font-size: 22rpx;
		line-height: 1.6;
		color: #CBD5E1;
		margin-top: 14rpx;
	}
	.supplier-hero-tags {
		display: flex;
		flex-wrap: wrap;
		gap: 16rpx;
		margin-top: 18rpx;
		padding-top: 16rpx;
		border-top: 1rpx solid rgba(255, 255, 255, 0.15);
	}
	.supplier-hero-tag {
		font-size: 20rpx;
		color: #BFDBFE;
	}
	.supplier-section-card {
		background: #FFFFFF;
		border-radius: 24rpx;
		padding: 28rpx 24rpx;
		margin-bottom: 24rpx;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.05);
	}
	.supplier-section-header {
		margin-bottom: 20rpx;
	}
	.supplier-section-title {
		display: block;
		font-size: 30rpx;
		font-weight: 700;
		color: #1A2A3A;
	}
	.supplier-section-sub {
		display: block;
		font-size: 22rpx;
		color: #8C9AA8;
		margin-top: 4rpx;
	}
	.supplier-verify-bar {
		display: flex;
		gap: 16rpx;
		align-items: center;
	}
	.supplier-verify-input {
		flex: 1;
		height: 80rpx;
		background: #F8FAFC;
		border: 1rpx solid #CBD5E1;
		border-radius: 16rpx;
		padding: 0 20rpx;
		font-size: 26rpx;
		color: #1A2A3A;
		font-family: monospace;
		box-sizing: border-box;
	}
	.supplier-verify-btn {
		height: 80rpx;
		line-height: 80rpx;
		padding: 0 32rpx;
		background: linear-gradient(135deg, #2563EB, #1D4ED8);
		color: #FFFFFF;
		font-size: 26rpx;
		font-weight: 600;
		border-radius: 16rpx;
		cursor: pointer;
		white-space: nowrap;
	}
	.supplier-verify-result {
		margin-top: 18rpx;
		padding: 20rpx;
		border-radius: 16rpx;
	}
	.supplier-verify-result.is-valid {
		background: #F0FDF4;
		border: 1rpx solid #86EFAC;
	}
	.supplier-verify-result.is-invalid {
		background: #FEF2F2;
		border: 1rpx solid #FECACA;
	}
	.supplier-verify-result-top {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}
	.supplier-verify-status {
		font-size: 26rpx;
		font-weight: 700;
	}
	.supplier-verify-result.is-valid .supplier-verify-status {
		color: #15803D;
	}
	.supplier-verify-result.is-invalid .supplier-verify-status {
		color: #DC2626;
	}
	.supplier-verify-code-pill {
		font-family: monospace;
		font-size: 22rpx;
		background: rgba(0, 0, 0, 0.05);
		padding: 4rpx 12rpx;
		border-radius: 6rpx;
		color: #334155;
	}
	.supplier-verify-note {
		display: block;
		font-size: 24rpx;
		color: #166534;
		margin-top: 8rpx;
	}
	.supplier-verify-meta {
		display: block;
		font-size: 22rpx;
		color: #4B5563;
		margin-top: 4rpx;
	}
	.supplier-verify-reason {
		display: block;
		font-size: 24rpx;
		color: #991B1B;
		margin-top: 8rpx;
	}
	.supplier-verify-fallback {
		display: block;
		font-size: 22rpx;
		color: #2563EB;
		margin-top: 8rpx;
		cursor: pointer;
		text-decoration: underline;
	}
	.supplier-verify-actions {
		display: flex;
		gap: 16rpx;
		margin-top: 16rpx;
	}
	.supplier-action-btn {
		flex: 1;
		height: 68rpx;
		line-height: 68rpx;
		text-align: center;
		font-size: 24rpx;
		font-weight: 600;
		border-radius: 12rpx;
		cursor: pointer;
	}
	.supplier-action-btn.outline {
		border: 1rpx solid #2563EB;
		color: #2563EB;
		background: #FFFFFF;
	}
	.supplier-action-btn.primary {
		background: #2563EB;
		color: #FFFFFF;
	}
	.supplier-mode-tabs {
		display: flex;
		background: #F1F5F9;
		border-radius: 16rpx;
		padding: 6rpx;
		margin-bottom: 24rpx;
	}
	.supplier-mode-tab {
		flex: 1;
		text-align: center;
		padding: 16rpx 0;
		font-size: 26rpx;
		font-weight: 500;
		color: #64748B;
		border-radius: 12rpx;
		cursor: pointer;
	}
	.supplier-mode-tab.active {
		background: #2563EB;
		color: #FFFFFF;
		font-weight: 700;
		box-shadow: 0 4rpx 10rpx rgba(37, 99, 235, 0.25);
	}
	.supplier-submit-wrap {
		margin-top: 32rpx;
	}
	.supplier-submit-btn {
		width: 100%;
		height: 88rpx;
		line-height: 88rpx;
		text-align: center;
		background: linear-gradient(135deg, #2563EB, #1D4ED8);
		color: #FFFFFF;
		font-size: 30rpx;
		font-weight: 700;
		border-radius: 44rpx;
		cursor: pointer;
		box-shadow: 0 6rpx 18rpx rgba(37, 99, 235, 0.3);
	}
	.supplier-submit-btn.disabled {
		opacity: 0.6;
		pointer-events: none;
	}
	.coop-record-submeta {
		font-size: 22rpx;
		color: #2563EB;
		margin-top: 4rpx;
	}

	/* ================================================================ */
	/* 设置页面专属样式 */
	/* ================================================================ */
	.settings-page .sub-content {
		padding-top: 20rpx;
	}
	.settings-card {
		background: #ffffff;
		border-radius: 24rpx;
		margin-bottom: 20rpx;
		padding: 0 20rpx;
		box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.05);
	}
	.settings-item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 20rpx 0;
		cursor: pointer;
	}
	.settings-item:active {
		opacity: 0.6;
	}
	.settings-divider {
		height: 1rpx;
		background: #f1f5f9;
	}
	.settings-left {
		display: flex;
		align-items: center;
		gap: 16rpx;
	}
	.settings-avatar {
		width: 72rpx;
		height: 72rpx;
		border-radius: 50%;
		overflow: hidden;
		background: #E8EDF3;
		flex-shrink: 0;
	}
	.avatar-img {
		width: 100%;
		height: 100%;
		display: block;
	}
	.settings-user {
		display: flex;
		flex-direction: column;
	}
	.settings-name {
		font-size: 28rpx;
		font-weight: 600;
		color: #1A2A3A;
	}
	.settings-phone {
		font-size: 22rpx;
		color: #94a3b8;
		margin-top: 2rpx;
	}
	.settings-icon {
		font-size: 28rpx;
		color: #1A2A3A;
	}
	.settings-label {
		font-size: 26rpx;
		color: #1A2A3A;
	}
	.settings-arrow {
		font-size: 32rpx;
		color: #d1d5db;
	}
	.logout-item .settings-left {
		gap: 16rpx;
	}
	.logout-icon {
		font-size: 28rpx;
	}
	.logout-label {
		font-size: 26rpx;
		color: #ef4444;
	}
	.settings-version {
		text-align: center;
		font-size: 22rpx;
		color: #94a3b8;
		margin-top: 20rpx;
	}

	/* ================================================================ */
	/* 空状态通用 */
	/* ================================================================ */
	.empty-state {
		text-align: center;
		padding: 80rpx 0;
	}
	.empty-icon {
		font-size: 72rpx;
		display: block;
	}
	.empty-text {
		font-size: 28rpx;
		color: #4A5A6A;
		margin-top: 16rpx;
	}
	.empty-hint {
		font-size: 22rpx;
		color: #9CA3AF;
		margin-top: 8rpx;
	}

	/* ================================================================ */
	/* 登录弹窗 */
	/* ================================================================ */
	.login-modal-mask {
		position: fixed;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background: rgba(0, 0, 0, 0.5);
		z-index: 999;
		display: flex;
		align-items: center;
		justify-content: center;
		animation: loginFadeIn 0.25s ease;
	}
	.login-modal {
		width: 85%;
		background: #ffffff;
		border-radius: 32rpx;
		padding: 36rpx 32rpx 28rpx;
		position: relative;
		animation: loginScaleIn 0.3s ease;
		max-height: 80vh;
		overflow-y: auto;
	}
	.login-modal-header {
		text-align: center;
		margin-bottom: 24rpx;
	}
	.login-modal-title {
		font-size: 36rpx;
		font-weight: 700;
		color: #1A2A3A;
		display: block;
	}
	.login-modal-sub {
		font-size: 24rpx;
		color: #8A9AA8;
		display: block;
		margin-top: 8rpx;
	}
	/* 登录/注册 切换 */
	.auth-mode-tabs {
		display: flex;
		background: #F1F5F9;
		border-radius: 16rpx;
		padding: 6rpx;
		margin-bottom: 24rpx;
	}
	.auth-mode-tab {
		flex: 1;
		text-align: center;
		padding: 14rpx 0;
		font-size: 26rpx;
		color: #6B7A8A;
		border-radius: 12rpx;
		cursor: pointer;
		font-weight: 500;
	}
	.auth-mode-tab.active {
		background: #4291F6;
		color: #FFFFFF;
		font-weight: 600;
	}
	.login-form {
		display: flex;
		flex-direction: column;
		gap: 20rpx;
		margin-bottom: 28rpx;
	}
	.login-input-wrap {
		display: flex;
		align-items: center;
		background: #F5F7FA;
		border: 2rpx solid #E8EDF3;
		border-radius: 16rpx;
		padding: 0 20rpx;
		height: 84rpx;
	}
	.login-input-wrap:focus-within,
	.login-input-wrap:focus {
		border-color: #4291F6;
	}
	.login-input-icon {
		font-size: 28rpx;
		margin-right: 12rpx;
	}
	.login-input {
		flex: 1;
		font-size: 28rpx;
		color: #1A2A3A;
	}
	.login-actions {
		display: flex;
		gap: 16rpx;
	}
	.login-btn-secondary,
	.login-btn-primary {
		flex: 1;
		height: 84rpx;
		border-radius: 40rpx;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 30rpx;
		font-weight: 600;
	}
	.login-btn-secondary {
		background: #F1F5F9;
		color: #6B7A8A;
	}
	.login-btn-primary {
		background: linear-gradient(135deg, #4291F6, #6EB5FF);
		color: #FFFFFF;
		box-shadow: 0 6rpx 18rpx rgba(66, 145, 246, 0.25);
	}
	.login-btn-secondary:active,
	.login-btn-primary:active {
		opacity: 0.85;
	}
	.login-tip-wrap {
		display: flex;
		justify-content: center;
		margin-top: 20rpx;
	}
	.login-tip {
		font-size: 22rpx;
		color: #4291F6;
		cursor: pointer;
	}
	@keyframes loginFadeIn {
		from { opacity: 0; }
		to { opacity: 1; }
	}
	@keyframes loginScaleIn {
		from { transform: scale(0.9); opacity: 0; }
		to { transform: scale(1); opacity: 1; }
	}
</style>

<!-- 全局样式：隐藏滚动条（仅保留 webkit 伪类，删除 * 选择器） -->
<style>
	::-webkit-scrollbar {
		width: 0;
		height: 0;
		display: none;
	}
	/* 已移除 * 选择器及 scrollbar-width、-ms-overflow-style 等不支持的属性 */
</style>