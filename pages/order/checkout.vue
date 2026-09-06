<template>
  <view class="checkout-page">
    <!-- ===== 顶部导航 ===== -->
    <view class="sub-nav">
      <view class="nav-back" @tap="goBack">
        <text class="back-icon">‹</text>
        <text class="back-text">返回</text>
      </view>
      <text class="nav-title">确认订单</text>
      <view class="nav-placeholder"></view>
    </view>

    <scroll-view class="checkout-scroll" scroll-y="true" show-scrollbar="false">
      <!-- ===== 商品信息卡 ===== -->
      <view class="product-card">
        <view class="product-image" :style="{ backgroundColor: product.bg }">
          <image v-if="product.image" class="product-img" :src="product.image" mode="aspectFill"></image>
          <text v-else class="product-icon">{{ product.icon }}</text>
        </view>
        <view class="product-info">
          <text class="product-name">{{ product.name }}</text>
          <text class="product-sub">{{ product.scene || '商品购买' }}</text>
          <view class="product-price-row">
            <text class="product-price">¥{{ product.price }}</text>
            <text class="product-unit" v-if="product.type === 'rent'">/天</text>
          </view>
        </view>
      </view>

      <!-- ===== 租用信息（产品类型/租期/服务时间）仅租用显示 ===== -->
      <view class="info-card" v-if="product.type === 'rent'">
        <view class="rent-info-row">
          <text class="rent-info-label">产品类型</text>
          <text class="rent-info-value">{{ productTypeLabel }}</text>
        </view>
        <view class="rent-info-row">
          <text class="rent-info-label">租期</text>
          <text class="rent-info-value">{{ product.rentPeriod || '—' }}</text>
        </view>
        <view class="rent-info-row" v-if="product.startDate">
          <text class="rent-info-label">服务时间</text>
          <text class="rent-info-value">{{ product.startDate }} ~ {{ product.endDate || '—' }}</text>
        </view>
      </view>

      <!-- ===== 数量选择 ===== -->
      <view class="qty-section">
        <text class="qty-label">购买数量</text>
        <view class="stepper">
          <view class="step-btn" @tap="decreaseQty">−</view>
          <text class="step-num">{{ qty }}</text>
          <view class="step-btn" @tap="increaseQty">＋</view>
        </view>
      </view>

      <!-- ===== 收货信息（与「我的-地址管理」互通 addressList 存储） ===== -->
      <view class="info-card">
        <view class="card-title-row">
          <text class="card-title">收货信息</text>
          <text class="card-manage" @tap="manageAddress">管理我的地址</text>
        </view>

        <!-- 我的地址：选择列表 -->
        <view class="addr-select-list" v-if="addressList.length">
          <view class="addr-select-item"
                v-for="addr in addressList"
                :key="addr.id"
                :class="{ active: selectedAddressId === addr.id }"
                @tap="selectAddress(addr)">
            <view class="addr-radio" :class="{ checked: selectedAddressId === addr.id }">
              <view class="addr-radio-dot" v-if="selectedAddressId === addr.id"></view>
            </view>
            <view class="addr-info">
              <view class="addr-top">
                <text class="addr-name">{{ addr.name }}</text>
                <text class="addr-phone">{{ addr.phone }}</text>
                <text class="addr-default" v-if="addr.isDefault">默认</text>
              </view>
              <text class="addr-detail">{{ fullAddress(addr) }}</text>
            </view>
            <view class="addr-edit" @tap.stop="editAddress(addr)">编辑</view>
          </view>
        </view>

        <!-- 空状态 -->
        <view class="addr-empty" v-else>
          <text class="addr-empty-icon">📭</text>
          <text class="addr-empty-text">还没有收货地址，请先新增</text>
        </view>

        <view class="btn-add-address" @tap="openAddForm">+ 新增收货地址</view>

        <!-- 新增 / 编辑地址弹窗 -->
        <view class="addr-form-mask" v-if="showAddrForm" @tap="closeAddrForm">
          <view class="addr-form" @tap.stop>
            <view class="addr-form-header">
              <text class="addr-form-title">{{ editingAddrId === null ? '新增地址' : '编辑地址' }}</text>
              <text class="addr-form-close" @tap="closeAddrForm">✕</text>
            </view>
            <scroll-view class="addr-form-body" scroll-y="true" show-scrollbar="false">
              <view class="addr-form-item">
                <text class="addr-form-label">收货人</text>
                <input class="addr-form-input" type="text" v-model="addrForm.name" placeholder="请输入姓名" />
              </view>
              <view class="addr-form-item">
                <text class="addr-form-label">手机号</text>
                <input class="addr-form-input" type="number" v-model="addrForm.phone" placeholder="请输入手机号" maxlength="11" />
              </view>
              <view class="addr-form-item">
                <text class="addr-form-label">省份</text>
                <input class="addr-form-input" type="text" v-model="addrForm.province" placeholder="如：北京市" />
              </view>
              <view class="addr-form-item">
                <text class="addr-form-label">城市</text>
                <input class="addr-form-input" type="text" v-model="addrForm.city" placeholder="如：北京市" />
              </view>
              <view class="addr-form-item">
                <text class="addr-form-label">区/县</text>
                <input class="addr-form-input" type="text" v-model="addrForm.district" placeholder="如：朝阳区" />
              </view>
              <view class="addr-form-item">
                <text class="addr-form-label">详细地址</text>
                <textarea class="addr-form-textarea" v-model="addrForm.detail" placeholder="街道、门牌号等" maxlength="100"></textarea>
              </view>
              <view class="addr-form-item addr-switch-item">
                <text class="addr-form-label">设为默认地址</text>
                <switch :checked="addrForm.isDefault" @change="onAddrSwitch" color="#4291F6" />
              </view>
            </scroll-view>
            <view class="addr-form-actions">
              <view class="addr-form-btn del" v-if="editingAddrId !== null" @tap="deleteAddr">删除</view>
              <view class="addr-form-btn cancel" @tap="closeAddrForm">取消</view>
              <view class="addr-form-btn save" @tap="saveAddr">保存</view>
            </view>
          </view>
        </view>
      </view>

      <!-- ===== 订单摘要 ===== -->
      <view class="summary-card">
        <view class="card-title">订单摘要</view>
        <view class="summary-row">
          <text class="summary-label">商品金额</text>
          <text class="summary-value">¥{{ formatMoney(productTotal) }}</text>
        </view>
        <view class="summary-row">
          <text class="summary-label">运费</text>
          <text class="summary-value">以实际配送为准</text>
        </view>
        <view class="summary-row total">
          <text class="summary-label">合计</text>
          <text class="summary-price">¥{{ formatMoney(productTotal) }}</text>
        </view>
      </view>

      <view style="height: 40rpx;"></view>
    </scroll-view>

    <!-- ===== 提交订单 ===== -->
    <view class="submit-bar">
      <view class="submit-left">
        <text class="submit-total-label">应付</text>
        <text class="submit-total">¥{{ formatMoney(productTotal) }}</text>
      </view>
      <view class="submit-btn" @tap="submitOrder" :class="{ disabled: submitting }">
        {{ submitLabel }}
      </view>
    </view>
  </view>
</template>

<script>
import { cartApi, tobOrderApi } from '@/utils/api'

export default {
  data() {
    return {
      product: {
        id: 0,
        name: '',
        price: '0',
        icon: '🤖',
        bg: '#F0F4FA',
        scene: '',
        type: 'buy',
      },
      qty: 1,
      intentMode: false,
      submitting: false,
      form: {
        name: '',
        phone: '',
        city: '',
        address: '',
        email: '',
        postcode: '',
      },
      // ===== 地址管理（与「我的-地址管理」互通） =====
      addressList: [],
      selectedAddressId: null,
      showAddrForm: false,
      editingAddrId: null,
      addrForm: {
        name: '',
        phone: '',
        province: '',
        city: '',
        district: '',
        detail: '',
        isDefault: false,
      },
    }
  },

  computed: {
    productTotal() {
      const price = parseFloat(String(this.product.price).replace(/,/g, '')) || 0
      return price * this.qty
    },
    productTypeLabel() {
      const map = { humanoid: '人形', quadruped: '四足', delivery: '配送', cleaning: '清洁', reception: '迎宾', inspection: '巡检', arm: '机械臂', companion: '陪伴' }
      return map[this.product.productType] || this.product.productType || '人形'
    },
    submitLabel() {
      if (this.submitting) return '提交中...'
      // 租用走「意向」，购买（含机器人/配件/技能）一律显示「提交订单」
      return this.product.type === 'rent' ? '提交租用意向' : '提交订单'
    },
  },

  onLoad(options) {
    const q = options || {}
    this.product = {
      id: Number(q.productId) || 0,
      name: q.name ? decodeURIComponent(q.name) : '机器人',
      price: q.price ? decodeURIComponent(q.price) : '0',
      icon: q.icon ? decodeURIComponent(q.icon) : '🤖',
      bg: q.bg ? decodeURIComponent(q.bg) : '#F0F4FA',
      scene: q.scene ? decodeURIComponent(q.scene) : '',
      type: q.type || 'buy',
      sku: q.sku ? decodeURIComponent(q.sku) : '',
      image: q.image ? decodeURIComponent(q.image) : '',
    }
    if (q.qty) this.qty = Number(q.qty) || 1

    // 租用信息（产品类型 + 租期 + 起止日期）
    this.product.productType = q.productType ? decodeURIComponent(q.productType) : ''
    this.product.rentPeriod = q.rentPeriod ? decodeURIComponent(q.rentPeriod) : ''
    this.product.startDate = q.startDate ? decodeURIComponent(q.startDate) : ''
    if (this.product.rentPeriod && this.product.startDate) {
      this.product.endDate = this.addDays(this.product.startDate, parseInt(this.product.rentPeriod) || 0)
    }

    // ===== 再次下单：读取订单同步过来的数据（?data=<JSON> 或 reorderData 缓存） =====
    const reorderData = q.data ? this.safeParse(q.data) : (uni.getStorageSync('reorderData') || null)
    if (reorderData) {
      uni.removeStorageSync('reorderData')
      if (reorderData.productId) this.product.id = Number(reorderData.productId) || this.product.id
      this.product.name = reorderData.robotName || reorderData.name || this.product.name
      this.product.price = reorderData.amount || reorderData.rentPrice || reorderData.price || this.product.price
      this.product.scene = reorderData.scene || this.product.scene
      this.product.type = reorderData.type || this.product.type
      this.product.productType = reorderData.productType || this.product.productType
      this.product.rentPeriod = reorderData.rentPeriod || this.product.rentPeriod
      if (reorderData.quantity) this.qty = Number(reorderData.quantity) || this.qty
    }

    // 若有账号且保存过地址，自动回填
    this.tryPrefillFromLocal()
  },

  methods: {
    // ================ 数量 ================
    increaseQty() {
      if (this.qty < 99) this.qty += 1
    },
    decreaseQty() {
      if (this.qty > 1) this.qty -= 1
    },

    // ================ 回填 ================
    // 与「我的-地址管理」共用同一个本地存储 addressList，避免死数据
    tryPrefillFromLocal() {
      const customer = uni.getStorageSync('customer')
      if (customer && customer.email) {
        this.form.email = customer.email
      }
      this.loadAddressList()
    },

    // 读取「我的-地址管理」地址列表（兼容数组 / JSON字符串 两种存储形式）
    readAddressList() {
      const raw = uni.getStorageSync('addressList')
      if (Array.isArray(raw)) return raw
      if (typeof raw === 'string' && raw) {
        try { return JSON.parse(raw) } catch (e) { /* 忽略解析错误 */ }
      }
      return []
    },

    // 加载地址列表，自动选中默认（或第一条）地址
    loadAddressList() {
      const list = this.readAddressList()
      this.addressList = list
      this.selectAddress(list.find(a => a.isDefault) || list[0] || null)
    },

    // 选中一个地址 → 回填到订单表单
    selectAddress(addr) {
      if (!addr) {
        this.selectedAddressId = null
        this.form.name = ''
        this.form.phone = ''
        this.form.city = ''
        this.form.address = ''
        return
      }
      this.selectedAddressId = addr.id
      this.form.name = addr.name || ''
      this.form.phone = addr.phone || ''
      const parts = [addr.province, addr.city, addr.district].filter(Boolean)
      this.form.city = [...new Set(parts)].join(' ') || ''
      this.form.address = addr.detail || ''
    },

    // 完整地址拼接（省 + 市 + 区 + 详细地址）
    fullAddress(addr) {
      const parts = [addr.province, addr.city, addr.district, addr.detail].filter(Boolean)
      return [...new Set(parts)].join(' ')
    },

    // ================ 收货地址 - 新增 / 编辑 / 删除（与「我的」地址管理互通） ================
    manageAddress() {
      uni.showToast({ title: '地址已与「我的-地址管理」互通，可在此直接管理', icon: 'none' })
    },
    openAddForm() {
      this.editingAddrId = null
      this.addrForm = { name: '', phone: '', province: '', city: '', district: '', detail: '', isDefault: false }
      this.showAddrForm = true
    },
    editAddress(addr) {
      this.editingAddrId = addr.id
      this.addrForm = { ...addr }
      this.showAddrForm = true
    },
    closeAddrForm() {
      this.showAddrForm = false
    },
    onAddrSwitch(e) {
      this.addrForm.isDefault = !!e.detail.value
    },
    saveAddr() {
      const f = this.addrForm
      if (!f.name.trim()) { uni.showToast({ title: '请输入收货人姓名', icon: 'none' }); return }
      if (!/^1\d{10}$/.test(f.phone.trim())) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return }
      if (!f.province.trim()) { uni.showToast({ title: '请输入省份', icon: 'none' }); return }
      if (!f.city.trim()) { uni.showToast({ title: '请输入城市', icon: 'none' }); return }
      if (!f.district.trim()) { uni.showToast({ title: '请输入区/县', icon: 'none' }); return }
      if (!f.detail.trim()) { uni.showToast({ title: '请输入详细地址', icon: 'none' }); return }

      const payload = {
        name: f.name.trim(),
        phone: f.phone.trim(),
        province: f.province.trim(),
        city: f.city.trim(),
        district: f.district.trim(),
        detail: f.detail.trim(),
        isDefault: !!f.isDefault,
      }
      let list = this.readAddressList()
      if (payload.isDefault) {
        list = list.map(a => ({ ...a, isDefault: false }))
      }
      if (this.editingAddrId !== null) {
        list = list.map(a => (a.id === this.editingAddrId ? { ...a, ...payload } : a))
      } else {
        if (!list.length) payload.isDefault = true
        payload.id = Date.now() + Math.floor(Math.random() * 1000)
        list.unshift(payload)
      }
      uni.setStorageSync('addressList', JSON.stringify(list))
      this.addressList = list
      this.showAddrForm = false
      this.editingAddrId = null
      this.selectAddress(list.find(a => a.isDefault) || list[0] || null)
      uni.showToast({ title: '保存成功', icon: 'success' })
    },
    deleteAddr() {
      uni.showModal({
        title: '提示',
        content: '确认删除该地址吗？',
        success: (res) => {
          if (!res.confirm) return
          const id = this.editingAddrId
          let list = this.readAddressList().filter(a => a.id !== id)
          if (!list.find(a => a.isDefault) && list.length) list[0].isDefault = true
          uni.setStorageSync('addressList', JSON.stringify(list))
          this.addressList = list
          this.showAddrForm = false
          this.editingAddrId = null
          this.selectAddress(list.find(a => a.isDefault) || list[0] || null)
          uni.showToast({ title: '已删除', icon: 'success' })
        }
      })
    },

    // 下单（含意向）成功后，把本次收货地址同步回「我的-地址管理」，保持两端一致
    syncAddressToMine() {
      const v = this.form
      if (!v.name.trim() || !v.phone.trim() || !v.city.trim()) return
      const list = this.readAddressList()
      const detail = v.address.trim()
      const exists = list.find(a => a.name === v.name.trim() && a.phone === v.phone.trim() && (a.detail || '') === detail)
      if (exists) return
      const parts = v.city.split(' ').filter(Boolean)
      list.unshift({
        id: Date.now() + Math.floor(Math.random() * 1000),
        name: v.name.trim(),
        phone: v.phone.trim(),
        province: parts[0] || '',
        city: parts[1] || parts[0] || '',
        district: parts[2] || '',
        detail,
        isDefault: list.length === 0,
      })
      uni.setStorageSync('addressList', JSON.stringify(list))
    },

    // ================ 确保入购物车 ================
    async ensureInCart() {
      try {
        const res = await cartApi.getCart()
        const cart = res && (res.data || null)
        const items = (cart && cart.items) ? cart.items : []
        const exists = items.find(i => i.name === this.product.name)
        if (!exists) {
          await cartApi.addToCart({ product_id: this.product.id, quantity: this.qty, is_buy_now: 1 })
        } else if (Number(exists.quantity) !== this.qty) {
          await cartApi.updateCart({ qty: { [exists.id]: this.qty } })
        }
      } catch (e) {
        // 后端无法入购物车（商品 id 未同步 / 服务未启动）→ 转意向下单模式
        this.intentMode = true
        this._cartError = (e && e.message) || ''
      }
    },

    // ================ 提交订单（完整购买路径） ================
    async submitOrder() {
      if (this.submitting) return

      const v = this.form
      if (!v.name.trim()) { uni.showToast({ title: '请输入收货人姓名', icon: 'none' }); return }
      if (!v.phone.trim() || !/^1\d{10}$/.test(v.phone.trim())) { uni.showToast({ title: '请输入正确的手机号', icon: 'none' }); return }
      if (!v.city.trim()) { uni.showToast({ title: '请输入所在城市', icon: 'none' }); return }
      if (!v.address.trim()) { uni.showToast({ title: '请输入详细地址', icon: 'none' }); return }

      this.submitting = true
      uni.showLoading({ title: '提交订单中...', mask: true })

      // 租/买/配件/技能统一走 TOB 统一下单:直接创建真实后台订单(不依赖购物车/配送/支付/登录)
      try {
        const payload = {
          product_id: this.product.id || 0,
          sku: this.product.sku || '',
          name: v.name,
          product_name: this.product.name,
          price: parseFloat(String(this.product.price).replace(/,/g, '')) || 0,
          qty: this.qty,
          type: this.product.type === 'rent' ? 'rent' : (this.product.type === 'plan' ? 'plan' : 'buy'),
          phone: v.phone,
          city: v.city,
          address: v.address,
          email: v.email || '',
          rent_days: this.product.type === 'rent' ? (parseInt(this.product.rentPeriod) || 1) : 1,
          rentPeriod: this.product.type === 'rent' ? this.product.rentPeriod : '',
          startDate: this.product.type === 'rent' ? this.product.startDate : '',
          endDate: this.product.type === 'rent' ? this.product.endDate : '',
        }
        const res = await tobOrderApi.create(payload)
        this.syncAddressToMine()
        uni.hideLoading()
        this.submitting = false
        // 游客也记一条本地订单,保证「订单」页可见;真实订单已落后台(后台订单处可见)
        if (!this.hasToken()) this.recordOrderLocally()
        uni.setStorageSync('orderNeedRefresh', Date.now())
        uni.showToast({ title: (res && res.success) ? '下单成功' : '订单已提交', icon: 'success' })
        setTimeout(() => { uni.switchTab({ url: '/pages/order/index' }) }, 800)
      } catch (e) {
        // 后端下单异常 → 如实转为意向单,避免死路弹窗(意向单会出现在「订单」页)
        this.submitIntentWithError((e && e.message) ? `下单遇到问题：${e.message}` : '后端未能创建订单，已为您保存为意向单')
      }
    },

    // 从 storeAddress 返回的配送费率中挑选第一个可用方法（形如 flatrate_flatrate）
    // 注意：Bagisto 返回的 shippingMethods 是「按承运商编码为键的对象」(如 {flatrate:{rates:[...]}})，需转成数组再遍历
    pickShippingMethod(data) {
      const sm = data && data.shippingMethods
      if (!sm) return null
      const groups = Array.isArray(sm) ? sm : Object.values(sm)
      for (const g of groups) {
        const rates = Array.isArray(g) ? g : (g && g.rates)
        if (Array.isArray(rates)) {
          for (const r of rates) {
            if (r && r.method) return r.method
          }
        }
      }
      return null
    },

    // 从 storeShipping 返回的支付方式中挑选第一个（兼容纯数组 / {data:[...]} / {payment_methods:[...]} 三种结构）
    pickPayment(methods) {
      const list = Array.isArray(methods)
        ? methods
        : (methods
          ? (Array.isArray(methods.data) ? methods.data : (Array.isArray(methods.payment_methods) ? methods.payment_methods : []))
          : [])
      for (const p of list) {
        if (p && p.method) return p.method
      }
      return null
    },

    // 意向订单：本地保存 + 成功提示（后端未配置商品时的兜底）
    submitIntentWithError(message) {
      // 记录失败原因,由 submitIntent 如实提示(而不是假装"下单成功")
      this._intentFallbackMsg = message || '后端下单未完成，已转为意向单'
      this.submitIntent()
    },

    submitIntent() {
      const fallbackMsg = this._intentFallbackMsg
      this._intentFallbackMsg = ''
      const list = uni.getStorageSync('intentOrders') || []
      list.unshift({
        id: Date.now(),
        productId: this.product.id,
        name: this.product.name,
        price: this.product.price,
        qty: this.qty,
        icon: this.product.icon || '🤖',
        bg: this.product.bg || '#F0F4FA',
        scene: this.product.scene || '',
        productType: this.product.productType || '',
        rentPeriod: this.product.rentPeriod || '',
        startDate: this.product.startDate || '',
        endDate: this.product.endDate || '',
        name2: this.form.name,
        phone: this.form.phone,
        city: this.form.city,
        address: this.form.address,
        email: this.form.email,
        type: this.product.type,
        time: new Date().toLocaleString('zh-CN', { hour12: false }),
      })
      uni.setStorageSync('intentOrders', list)
      // 同步收货地址到「我的-地址管理」
      this.syncAddressToMine()
      uni.hideLoading()
      this.submitting = false
      // 与订单页数据互联：订单页 onShow 会读取 intentOrders 展示本次下单
      uni.setStorageSync('orderNeedRefresh', Date.now())
      // 如实提示:租用本就是意向;购买若走此路径说明后端下单失败
      let msg, icon
      if (this.product.type === 'rent') {
        msg = '租用意向已提交，客服将尽快联系您'
        icon = 'success'
      } else {
        msg = fallbackMsg || '订单已保存为意向单（未生成正式订单）'
        icon = 'none'
      }
      uni.showToast({ title: msg, icon })
      setTimeout(() => {
        uni.switchTab({ url: '/pages/order/index' })
      }, 800)
    },

    // 游客下单后记一条本地订单,保证「订单」页可看到(真实订单已落后台)
    recordOrderLocally() {
      const list = uni.getStorageSync('intentOrders') || []
      list.unshift({
        id: Date.now(),
        productId: this.product.id,
        name: this.product.name,
        price: this.product.price,
        qty: this.qty,
        icon: this.product.icon || '🤖',
        bg: this.product.bg || '#F0F4FA',
        scene: this.product.scene || '',
        productType: this.product.productType || '',
        rentPeriod: this.product.rentPeriod || '',
        startDate: this.product.startDate || '',
        endDate: this.product.endDate || '',
        name2: this.form.name,
        phone: this.form.phone,
        city: this.form.city,
        address: this.form.address,
        email: this.form.email,
        type: this.product.type,
        time: new Date().toLocaleString('zh-CN', { hour12: false }),
        synced: true,
      })
      uni.setStorageSync('intentOrders', list)
    },

    // ================ 工具 ================
    hasToken() {
      return !!uni.getStorageSync('token')
    },
    formatMoney(value) {
      return Number(value || 0).toLocaleString('en-US')
    },

    // ================ 工具 ================
    addDays(dateStr, days) {
      if (!dateStr) return ''
      const d = new Date(dateStr)
      d.setDate(d.getDate() + (days || 0))
      return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    },

    safeParse(str) {
      if (!str) return null
      for (const s of [String(str), decodeURIComponent(String(str))]) {
        try { return JSON.parse(s) } catch (e) {}
      }
      return null
    },

    goBack() {
      uni.navigateBack({ fail: () => uni.switchTab({ url: '/pages/robot/index' }) })
    },
  },
}
</script>

<style scoped>
.checkout-page {
  height: 100vh;
  background: #f5f7fa;
  display: flex;
  flex-direction: column;
}

/* ===== 顶部导航 ===== */
.sub-nav {
  display: flex;
  align-items: center;
  background: #ffffff;
  padding: 88rpx 24rpx 20rpx;
  flex-shrink: 0;
  border-bottom: 1rpx solid #f1f5f9;
}
.nav-back {
  display: flex;
  align-items: center;
  gap: 4rpx;
  padding: 8rpx 16rpx 8rpx 8rpx;
}
.nav-back:active { background: #f1f5f9; }
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
.nav-placeholder { width: 130rpx; }

/* ===== 滚动区 ===== */
.checkout-scroll {
  flex: 1;
  min-height: 0;
  padding: 20rpx 24rpx 0;
  box-sizing: border-box;
}

/* ===== 商品卡 ===== */
.product-card {
  background: #ffffff;
  border-radius: 24rpx;
  padding: 20rpx;
  display: flex;
  align-items: center;
  margin-bottom: 20rpx;
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.06);
}
.product-image {
  width: 140rpx;
  height: 140rpx;
  border-radius: 16rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  margin-right: 20rpx;
}
.product-icon { font-size: 72rpx; }
.product-img {
  width: 100%;
  height: 100%;
  border-radius: 16rpx;
  display: block;
  object-fit: cover;
}
.product-info { flex: 1; min-width: 0; }
.product-name {
  font-size: 30rpx;
  font-weight: 700;
  color: #1A2A3A;
}
.product-sub {
  font-size: 22rpx;
  color: #6B7A8A;
  margin-top: 6rpx;
  display: block;
}
.product-price-row {
  margin-top: 10rpx;
  display: flex;
  align-items: baseline;
}
.product-price {
  font-size: 34rpx;
  font-weight: 700;
  color: #FF7820;
}
.product-unit {
  font-size: 22rpx;
  color: #9CA3AF;
  margin-left: 4rpx;
}

/* ===== 数量 ===== */
.qty-section {
  background: #ffffff;
  border-radius: 24rpx;
  padding: 24rpx;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20rpx;
}
.qty-label { font-size: 28rpx; font-weight: 600; color: #1A2A3A; }
.stepper { display: flex; align-items: center; gap: 12rpx; }
.step-btn {
  width: 60rpx;
  height: 60rpx;
  border-radius: 16rpx;
  background: #E8F4FD;
  color: #4291F6;
  font-size: 36rpx;
  display: flex;
  align-items: center;
  justify-content: center;
}
.step-btn:active { opacity: 0.7; }
.step-num {
  min-width: 64rpx;
  text-align: center;
  font-size: 32rpx;
  font-weight: 600;
  color: #1A2A3A;
}

/* ===== 租用信息 ===== */
.rent-info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14rpx 0;
  border-bottom: 1rpx solid #f1f5f9;
}
.rent-info-row:last-child { border-bottom: none; }
.rent-info-label { font-size: 24rpx; color: #6b7a8a; }
.rent-info-value { font-size: 24rpx; color: #1a2a3a; font-weight: 500; }

/* ===== 信息卡 ===== */
.info-card, .summary-card {
  background: #ffffff;
  border-radius: 24rpx;
  padding: 24rpx;
  margin-bottom: 20rpx;
  box-shadow: 0 4rpx 16rpx rgba(66, 145, 246, 0.06);
}
.card-title {
  font-size: 28rpx;
  font-weight: 700;
  color: #1A2A3A;
  margin-bottom: 8rpx;
}
.form-group { }
.form-item {
  display: flex;
  align-items: center;
  padding: 20rpx 0;
  border-bottom: 2rpx solid #f1f5f9;
}
.form-item:last-child { border-bottom: none; }
.form-label {
  width: 200rpx;
  font-size: 26rpx;
  color: #1A2A3A;
  flex-shrink: 0;
}
.required { color: #ef4444; }
.optional { color: #9aaab8; font-weight: 400; font-size: 22rpx; }
.form-input { flex: 1; font-size: 26rpx; color: #1A2A3A; }

/* ===== 收货地址 - 选择列表 ===== */
.card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8rpx;
}
.card-manage {
  font-size: 24rpx;
  color: #4291F6;
  font-weight: 500;
}
.addr-select-list {
  display: flex;
  flex-direction: column;
  gap: 16rpx;
}
.addr-select-item {
  display: flex;
  align-items: center;
  gap: 16rpx;
  background: #F8FAFD;
  border: 2rpx solid #EEF2F7;
  border-radius: 16rpx;
  padding: 18rpx 16rpx;
  transition: all 0.2s;
}
.addr-select-item.active {
  background: #E8F4FD;
  border-color: #4291F6;
}
.addr-radio {
  width: 36rpx;
  height: 36rpx;
  border-radius: 50%;
  border: 2rpx solid #C4D0DE;
  background: #ffffff;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}
.addr-radio.checked {
  border-color: #4291F6;
}
.addr-radio-dot {
  width: 18rpx;
  height: 18rpx;
  border-radius: 50%;
  background: #4291F6;
}
.addr-info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.addr-top {
  display: flex;
  align-items: center;
  gap: 12rpx;
  margin-bottom: 4rpx;
}
.addr-name {
  font-size: 28rpx;
  font-weight: 600;
  color: #1A2A3A;
}
.addr-phone {
  font-size: 24rpx;
  color: #6B7A8A;
}
.addr-default {
  font-size: 18rpx;
  color: #4291F6;
  background: #E8F4FD;
  border: 1rpx solid #B3D5FF;
  padding: 2rpx 12rpx;
  border-radius: 12rpx;
  font-weight: 500;
}
.addr-detail {
  font-size: 22rpx;
  color: #4A5A6A;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.addr-edit {
  font-size: 22rpx;
  color: #4291F6;
  padding: 4rpx 16rpx;
  border-radius: 16rpx;
  background: #E8F4FD;
  flex-shrink: 0;
}
.addr-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40rpx 0 20rpx;
}
.addr-empty-icon { font-size: 48rpx; }
.addr-empty-text {
  font-size: 24rpx;
  color: #9CA3AF;
  margin-top: 12rpx;
}
.btn-add-address {
  margin-top: 20rpx;
  background: #F0F7FD;
  border: 2rpx dashed #9CC7F5;
  border-radius: 16rpx;
  padding: 20rpx 0;
  text-align: center;
  color: #4291F6;
  font-size: 26rpx;
  font-weight: 500;
}
.btn-add-address:active {
  opacity: 0.7;
}

/* ===== 收货地址 - 新增/编辑弹窗 ===== */
.addr-form-mask {
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
}
.addr-form {
  width: 620rpx;
  max-height: 80vh;
  background: #ffffff;
  border-radius: 24rpx;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.addr-form-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 24rpx 30rpx 16rpx;
  border-bottom: 1rpx solid #f1f5f9;
  flex-shrink: 0;
}
.addr-form-title {
  font-size: 32rpx;
  font-weight: 700;
  color: #1A2A3A;
}
.addr-form-close {
  font-size: 36rpx;
  color: #9CA3AF;
  padding: 4rpx 8rpx;
}
.addr-form-body {
  flex: 1;
  min-height: 0;
  padding: 0 30rpx;
  box-sizing: border-box;
}
.addr-form-item {
  display: flex;
  align-items: center;
  padding: 22rpx 0;
  border-bottom: 1rpx solid #f1f5f9;
}
.addr-form-item:last-child { border-bottom: none; }
.addr-form-label {
  width: 150rpx;
  font-size: 26rpx;
  color: #1A2A3A;
  font-weight: 500;
  flex-shrink: 0;
}
.addr-form-input {
  flex: 1;
  font-size: 26rpx;
  color: #1A2A3A;
}
.addr-form-textarea {
  flex: 1;
  height: 96rpx;
  font-size: 26rpx;
  color: #1A2A3A;
}
.addr-switch-item {
  justify-content: space-between;
}
.addr-switch-item .addr-form-label { width: auto; }
.addr-form-actions {
  display: flex;
  gap: 16rpx;
  padding: 20rpx 30rpx calc(20rpx + env(safe-area-inset-bottom));
  border-top: 1rpx solid #f1f5f9;
  flex-shrink: 0;
}
.addr-form-btn {
  flex: 1;
  padding: 22rpx 0;
  border-radius: 20rpx;
  text-align: center;
  font-size: 28rpx;
  font-weight: 600;
}
.addr-form-btn.cancel {
  background: #f1f5f9;
  color: #6B7A8A;
}
.addr-form-btn.save {
  background: #4291F6;
  color: #ffffff;
}
.addr-form-btn.del {
  background: #fee2e2;
  color: #ef4444;
  flex: 0 0 auto;
  padding: 22rpx 32rpx;
}
.addr-form-btn:active {
  opacity: 0.8;
}

/* ===== 订单摘要 ===== */
.summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14rpx 0;
}
.summary-label { font-size: 24rpx; color: #6B7A8A; }
.summary-value { font-size: 24rpx; color: #1A2A3A; font-weight: 500; }
.summary-row.total { border-top: 2rpx solid #f1f5f9; margin-top: 6rpx; padding-top: 20rpx; }
.summary-price {
  font-size: 36rpx;
  font-weight: 700;
  color: #FF7820;
}

/* ===== 提交栏 ===== */
.submit-bar {
  flex-shrink: 0;
  background: #ffffff;
  padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 2rpx solid #f1f5f9;
}
.submit-left { display: flex; align-items: baseline; gap: 8rpx; }
.submit-total-label { font-size: 24rpx; color: #6B7A8A; }
.submit-total {
  font-size: 40rpx;
  font-weight: 700;
  color: #FF7820;
}
.submit-btn {
  background: linear-gradient(135deg, #4291F6, #6EB5FF);
  color: #fff;
  padding: 24rpx 64rpx;
  border-radius: 48rpx;
  font-size: 30rpx;
  font-weight: 600;
  box-shadow: 0 6rpx 20rpx rgba(66, 145, 246, 0.3);
}
.submit-btn:active { opacity: 0.85; }
.submit-btn.disabled { opacity: 0.6; pointer-events: none; }
</style>