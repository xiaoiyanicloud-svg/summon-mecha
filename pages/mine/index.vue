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
				<view class="function-grid">
					<view class="func-item" @click="goAddress">
						<text class="func-icon">📍</text>
						<text class="func-label">我的地址</text>
					</view>
					<view class="func-item" @click="goDevices">
						<text class="func-icon">🔧</text>
						<text class="func-label">我的设备</text>
					</view>
					<view class="func-item" @click="goSkills">
						<text class="func-icon">✨</text>
						<text class="func-label">我的技能</text>
					</view>
					<view class="func-item" @click="goAfterSales">
						<text class="func-icon">🧾</text>
						<text class="func-label">售后记录</text>
					</view>
					<view class="func-item" @click="goNotifications">
						<text class="func-icon">🔔</text>
						<text class="func-label">消息通知</text>
					</view>
					<view class="func-item" @click="goCooperate">
						<text class="func-icon">🤝</text>
						<text class="func-label">合作申请</text>
					</view>
					<view class="func-item" @click="goSupplierCode">
						<text class="func-icon">🔑</text>
						<text class="func-label">供应商邀请码</text>
					</view>
					<view class="func-item" @click="goPartnerRecommend">
						<text class="func-icon">🌟</text>
						<text class="func-label">合伙人推荐</text>
					</view>
					<view class="func-item" @click="goCustomerService">
						<text class="func-icon">💬</text>
						<text class="func-label">客服</text>
					</view>
					<view class="func-item" @click="goIncidentReport">
						<text class="func-icon">🚨</text>
						<text class="func-label">事故上报</text>
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
				<view style="height: 40rpx;"></view>
			</scroll-view>
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
	import { authApi, addressApi, invoiceApi, orderApi } from '@/utils/api';
	import { TOKEN_KEY, CUSTOMER_KEY, APP_VERSION } from '@/utils/config';
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
						name: '本体厂商',
						avatar: '🏭',
						avatarBg: '#E8F4FD',
						btnText: '洽谈合作',
						desc: '制造 / 渠道 / 技术协同',
						benefits: ['品牌联合推广', '渠道资源共享', '技术方案协同'],
						formPath: '/pages/cooperate/manufacturer'
					},
					{
						name: 'RaaS合伙人',
						avatar: '🤝',
						avatarBg: '#E8F4FD',
						btnText: '立即加入',
						desc: '技能售卖 / 分成 / 技术社区',
						benefits: ['技能服务上架', '高额分成收益', '社区技术交流'],
						formPath: '/pages/cooperate/raas'
					},
					{
						name: '网红达人',
						avatar: '📱',
						avatarBg: '#E8F4FD',
						btnText: '演出合作',
						desc: '免费用机 / 商单 / 流量扶持',
						benefits: ['免费用机体验', '品牌商单合作', '平台流量扶持'],
						formPath: '/pages/cooperate/influencer'
					}
				],

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
			// B-8:售后单列表只有入口才有意义 —— 此前 skillApi.afterSales() 封装好了却零消费方
			goAfterSales() { uni.navigateTo({ url: '/pages/mine/after-sales' }); },
			// 消息通知:审核/下发/试用到期/退款 都落这里(v3.4)
			goNotifications() { uni.navigateTo({ url: '/pages/mine/notifications' }); },
			goCooperate() { this.currentPage = 'cooperate'; },
			goSupplierCode() { uni.showToast({ title: '供应商邀请码：即将开放', icon: 'none' }); },
			goPartnerRecommend() {
				this.currentPage = 'partner';
				this.loadPartnerFormCache();
			},
			goCustomerService() { uni.navigateTo({ url: '/pages/mine/tickets' }); },
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
			// 合作申请 - 跳转表单（预留接口）
			// ================================================================
			goApplyForm(item) {
				// 诚实化:表单页未开发,不再假装跳转
				uni.showToast({ title: `「${item.name}」申请通道即将开放`, icon: 'none' });
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
			// 合伙人推荐 - 提交表单
			// ================================================================
			submitForm() {
				if (!this.isFormValid) {
					uni.showToast({ title: '请完善所有必填项', icon: 'none' });
					return;
				}
				// 诚实化:提交通道未接后端,不再假装提交成功;草稿仍本地保留便于日后一键提交
				uni.showToast({ title: '合伙人计划通道即将开放，资料已暂存草稿', icon: 'none' });
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
		grid-template-columns: repeat(3, 1fr);
		gap: 20rpx 0;
		margin-top: 4rpx;
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