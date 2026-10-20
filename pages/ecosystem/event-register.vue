<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">{{ reapplyMode ? '重新报名' : '活动报名' }}</text>
      <view class="nav-side"></view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '📡'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && !event"
      empty-icon="🏟️"
      empty-text="活动不存在或未公开"
      empty-hint="请返回列表查看已发布的活动。"
      @retry="load"
    >
      <view v-if="event" class="reg-page">
        <view class="event-head">
          <text class="event-kind">{{ kindLabel }}</text>
          <text class="event-title">{{ event.name }}</text>
          <text v-if="whenText" class="event-meta">{{ whenText }}</text>
          <text v-if="placeText" class="event-meta">{{ placeText }}</text>
        </view>

        <!-- 回执：只在真实提交成功后显示 -->
        <view v-if="receipt" class="panel receipt">
          <text class="panel-title">报名已提交</text>
          <view class="kv"><text class="k">报名编号</text><text class="v mono">{{ receipt.public_no }}</text></view>
          <view class="kv"><text class="k">当前状态</text><text class="v">{{ statusLabel(receipt.status) }}</text></view>
          <view class="kv"><text class="k">轮次</text><text class="v">#{{ receipt.attempt_no }}</text></view>
          <text v-if="receipt.status === 'submitted'" class="hint warn">待审核，不保证名额；批准后才占确认席位。</text>
          <text v-if="receipt.status === 'confirmed'" class="hint ok">已确认并获得席位。</text>
          <view class="actions">
            <view class="btn primary" role="button" tabindex="0" @click="goMine">查看我的报名</view>
          </view>
        </view>

        <!-- 未登录：内联登录，登录后重新拉取详情和版本，不自动提交 -->
        <view v-else-if="!hasToken" class="panel">
          <text class="panel-title">登录后继续报名</text>
          <text class="hint">登录只用于确认报名身份；不会自动提交表单。</text>
          <view class="field">
            <text class="label">邮箱</text>
            <input v-model="login.email" class="input" type="text" placeholder="请输入邮箱" />
          </view>
          <view class="field">
            <text class="label">密码</text>
            <input v-model="login.password" class="input" type="password" placeholder="请输入密码" />
          </view>
          <view class="actions">
            <view class="btn primary" :class="{ disabled: loggingIn }" role="button" tabindex="0" @click="doLogin">
              {{ loggingIn ? '登录中...' : '登录' }}
            </view>
          </view>
        </view>

        <!-- 已登录但服务端不允许报名 -->
        <view v-else-if="!canRegister" class="panel">
          <text class="panel-title">当前不可报名</text>
          <text class="hint">{{ reasonText }}</text>
          <view v-if="registration.mode === 'mine'" class="actions">
            <view class="btn primary" role="button" tabindex="0" @click="goMine">查看我的报名</view>
          </view>
          <view v-else class="actions">
            <view class="btn ghost" role="button" tabindex="0" @click="load">刷新状态</view>
          </view>
        </view>

        <!-- 报名表 -->
        <view v-else class="panel">
          <text class="panel-title">{{ reapplyMode ? '重新报名（新轮次）' : '填写报名信息' }}</text>
          <text class="hint">表单版本 v{{ formVersion }} · 隐私文本 {{ privacyVersion }}</text>
          <text v-if="reapplyMode" class="hint warn">重报会创建新轮次；旧回执、旧凭证仍保留在历史里，不会作用于新轮次。</text>

          <view class="field">
            <text class="label">联系人姓名 <text class="req">*</text></text>
            <input v-model="form.name" class="input" type="text" maxlength="50" placeholder="请输入姓名" />
            <text v-if="fieldErrors.name" class="error">{{ fieldErrors.name }}</text>
          </view>

          <view class="field">
            <text class="label">手机号 <text class="req">*</text></text>
            <input v-model="form.phone" class="input" type="number" maxlength="20" placeholder="请输入手机号" />
            <text v-if="fieldErrors.phone" class="error">{{ fieldErrors.phone }}</text>
          </view>

          <view class="field">
            <text class="label">城市</text>
            <input v-model="form.city" class="input" type="text" maxlength="100" placeholder="选填" />
          </view>

          <view class="field">
            <text class="label">备注</text>
            <textarea v-model="form.note" class="textarea" maxlength="500" placeholder="选填，最多 500 字" />
          </view>

          <view v-if="privacySummary" class="privacy">
            <text class="privacy-title">隐私与规则摘要</text>
            <text class="privacy-text">{{ privacySummary }}</text>
          </view>

          <view class="consent" @click="form.consent = !form.consent">
            <checkbox :checked="form.consent" />
            <text>我已阅读并同意当前规则与隐私文本（{{ privacyVersion }}）</text>
          </view>
          <text v-if="fieldErrors.consent" class="error">{{ fieldErrors.consent }}</text>

          <text v-if="submitHint" class="hint warn">{{ submitHint }}</text>

          <view class="actions">
            <view class="btn primary" :class="{ disabled: submitting }" role="button" tabindex="0" @click="submit">
              {{ submitting ? '提交中...' : (reapplyMode ? '提交重报' : '提交报名') }}
            </view>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { eventApi, eventRegistrationApi, authApi } from '@/utils/api'
import { TOKEN_KEY, CUSTOMER_KEY } from '@/utils/config'
import {
  normalizeEvent,
  errorState,
  eventKindLabel,
  formatEventWhen,
  registrationView,
} from '@/utils/ecosystem-public'

const DRAFT_KEY = 'event_registration_draft'

function newRequestKey() {
  const g = typeof globalThis !== 'undefined' ? globalThis : {}
  if (g.crypto && typeof g.crypto.getRandomValues === 'function') {
    const buf = new Uint8Array(16)
    g.crypto.getRandomValues(buf)
    return 'reg-' + Array.from(buf, b => b.toString(16).padStart(2, '0')).join('')
  }
  return 'reg-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12)
}

export default {
  name: 'EcosystemEventRegister',
  components: { StateView },
  data() {
    return {
      eventId: 0,
      publicNo: '',
      reapplyMode: false,
      event: null,
      mine: null,
      loading: false,
      errorMessage: '',
      errorInfo: null,
      hasToken: false,
      loggingIn: false,
      login: { email: '', password: '' },
      form: { name: '', phone: '', city: '', note: '', consent: false },
      fieldErrors: {},
      requestKey: '',
      submitting: false,
      submitHint: '',
      receipt: null,
    }
  },
  computed: {
    kindLabel() {
      return this.event ? eventKindLabel(this.event.kind) : '活动'
    },
    whenText() {
      return this.event ? formatEventWhen(this.event) : ''
    },
    placeText() {
      if (!this.event) return ''
      return [this.event.city, this.event.locationText].filter(Boolean).join(' · ')
    },
    registration() {
      return registrationView(this.event)
    },
    canRegister() {
      return this.event ? this.registration.mode === 'register' : false
    },
    reasonText() {
      return this.registration.reason || '暂不可报名。'
    },
    formVersion() {
      return this.event && this.event.registration.formVersion ? this.event.registration.formVersion : 0
    },
    privacyVersion() {
      return this.event && this.event.registration.privacyVersion ? this.event.registration.privacyVersion : ''
    },
    privacySummary() {
      return this.event && this.event.registration.privacySummary ? this.event.registration.privacySummary : ''
    },
    rulesVersionId() {
      return this.event && this.event.registration.rulesVersionId ? this.event.registration.rulesVersionId : null
    },
  },
  onLoad(query) {
    const id = Number(query && query.id)
    if (!Number.isInteger(id) || id <= 0) {
      this.applyError(errorState({ statusCode: 404 }))
      return
    }

    this.eventId = id
    this.publicNo = typeof query.public_no === 'string' ? query.public_no : ''
    this.reapplyMode = query.reapply === '1' || Boolean(this.publicNo)
    this.hasToken = Boolean(uni.getStorageSync(TOKEN_KEY))
    this.restoreDraft()
    this.load()
  },
  onPullDownRefresh() {
    this.load().finally(() => uni.stopPullDownRefresh())
  },
  methods: {
    goBack() {
      uni.navigateBack({ delta: 1, fail: () => uni.switchTab({ url: '/pages/home/index' }) })
    },
    goMine() {
      uni.navigateTo({ url: '/pages/ecosystem/my-registrations' })
    },
    statusLabel(status) {
      const labels = {
        submitted: '待审核（不保证名额）',
        confirmed: '已确认',
        checked_in: '已签到',
        rejected: '未通过',
        canceled: '已取消',
      }
      return labels[status] || status || '未知'
    },
    applyError(error) {
      const info = errorState(error)
      this.event = null
      this.loading = false
      this.errorMessage = info.message
      this.errorInfo = info
    },
    restoreDraft() {
      const draft = uni.getStorageSync(DRAFT_KEY)
      if (!draft || Number(draft.eventId) !== this.eventId) {
        this.requestKey = newRequestKey()
        return
      }
      this.requestKey = typeof draft.requestKey === 'string' && draft.requestKey ? draft.requestKey : newRequestKey()
    },
    persistDraft() {
      // 只持久化非敏感选项与请求键；姓名/手机号不落本地。
      uni.setStorageSync(DRAFT_KEY, {
        eventId: this.eventId,
        publicNo: this.publicNo,
        requestKey: this.requestKey,
        formVersion: this.formVersion,
        privacyVersion: this.privacyVersion,
      })
    },
    async load() {
      this.loading = true
      this.errorMessage = ''
      this.errorInfo = null

      try {
        const response = await eventApi.getEvent(this.eventId)
        const event = normalizeEvent(response && response.data)
        if (!event) throw new Error('活动详情格式异常')

        this.event = event
        this.hasToken = Boolean(uni.getStorageSync(TOKEN_KEY))

        if (this.publicNo && this.hasToken) {
          try {
            const mine = await eventRegistrationApi.get(this.publicNo)
            this.mine = mine && mine.data ? mine.data : null
          } catch (e) {
            // 重报入口拿不到本人记录时，不伪造；下面会展示服务端状态。
            this.mine = null
          }
        }

        // 登录后版本可能变化：旧同意必须失效，不自动提交。
        const draft = uni.getStorageSync(DRAFT_KEY)
        if (draft && Number(draft.eventId) === this.eventId
          && (Number(draft.formVersion) !== Number(this.formVersion)
            || String(draft.privacyVersion) !== String(this.privacyVersion))) {
          this.requestKey = newRequestKey()
          this.form.consent = false
          this.submitHint = '报名表或隐私文本已更新，请重新阅读并勾选。'
        }

        this.persistDraft()
      } catch (error) {
        this.applyError(error)
      } finally {
        this.loading = false
      }
    },
    async doLogin() {
      if (this.loggingIn) return
      const email = String(this.login.email || '').trim()
      const password = String(this.login.password || '')

      if (!email || !password) {
        uni.showToast({ title: '请输入邮箱和密码', icon: 'none' })
        return
      }

      this.loggingIn = true

      try {
        const res = await authApi.login(email, password)
        const data = (res && res.data) || {}
        if (!data.token) {
          uni.showToast({ title: '登录失败：服务未返回凭证', icon: 'none' })
          return
        }

        uni.setStorageSync(TOKEN_KEY, data.token)
        uni.setStorageSync(CUSTOMER_KEY, data.customer || { email })
        this.hasToken = true
        this.login.password = ''
        uni.showToast({ title: '登录成功', icon: 'success' })
        // 登录后重新拉取详情与版本；不自动提交。
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '登录失败', icon: 'none' })
      } finally {
        this.loggingIn = false
      }
    },
    validate() {
      const errors = {}
      if (!String(this.form.name || '').trim()) errors.name = '请填写姓名'
      if (!String(this.form.phone || '').trim()) errors.phone = '请填写手机号'
      if (!this.form.consent) errors.consent = '请先阅读并同意规则与隐私文本'
      if (!this.formVersion || !this.privacyVersion) errors.consent = '报名表版本缺失，请刷新后重试'
      this.fieldErrors = errors
      return Object.keys(errors).length === 0
    },
    async submit() {
      if (this.submitting || this.receipt) return
      if (!this.hasToken) {
        uni.showToast({ title: '请先登录', icon: 'none' })
        return
      }
      if (!this.canRegister) {
        uni.showToast({ title: this.reasonText, icon: 'none' })
        return
      }
      if (!this.validate()) return

      this.submitting = true
      this.submitHint = ''

      const payload = {
        request_key: this.requestKey,
        form_version: Number(this.formVersion),
        privacy_version: String(this.privacyVersion),
        rules_version_id: this.rulesVersionId || undefined,
        consent: true,
        name: String(this.form.name).trim(),
        phone: String(this.form.phone).trim(),
        city: String(this.form.city || '').trim(),
        note: String(this.form.note || '').trim(),
      }

      try {
        let response
        if (this.reapplyMode && this.publicNo) {
          if (!this.mine || !this.mine.attempt_id) {
            throw new Error('未找到当前轮次，请回到我的报名重试')
          }
          payload.expected_attempt_id = Number(this.mine.attempt_id)
          response = await eventRegistrationApi.reapply(this.publicNo, payload)
        } else {
          response = await eventApi.register(this.eventId, payload)
        }

        const data = response && response.data ? response.data : null
        if (!data || !data.public_no) throw new Error('服务未返回报名编号')

        this.receipt = data
        uni.removeStorageSync(DRAFT_KEY)
        uni.showToast({ title: '报名已提交', icon: 'success' })
      } catch (error) {
        const code = error && error.code
        const status = error && error.statusCode

        if (code === 'REGISTRATION_ALREADY_ACTIVE') {
          this.goMine()
          return
        }

        if (error && (error.isTimeout || status === 0)) {
          await this.recoverAfterTimeout()
          return
        }

        if (status === 409 && code === 'REGISTRATION_REQUEST_CONFLICT') {
          this.submitHint = '同一请求键对应了不同表单内容。请刷新页面后再试。'
        } else {
          this.submitHint = (error && error.message) || '提交失败，请稍后重试'
        }

        if (error && error.errors && typeof error.errors === 'object') {
          const mapped = {}
          Object.keys(error.errors).forEach((key) => {
            const value = error.errors[key]
            mapped[key] = Array.isArray(value) ? value[0] : value
          })
          this.fieldErrors = mapped
        }
      } finally {
        this.submitting = false
      }
    },
    async recoverAfterTimeout() {
      // 超时不等于失败：按同一 request_key 查回真实结果，不换 key 重报。
      try {
        const response = await eventRegistrationApi.requestStatus(this.requestKey, this.reapplyMode ? 'reapply' : 'submit')
        const data = response && response.data ? response.data : null

        if (data && data.completed && data.result) {
          this.receipt = data.result
          uni.removeStorageSync(DRAFT_KEY)
          uni.showToast({ title: '已查回报名结果', icon: 'success' })
          return
        }
      } catch (e) {
        // 查回失败不伪造成功。
      }

      this.submitHint = '提交结果尚未确认。请保持页面并重试；同一请求键不会重复报名。'
    },
  },
}
</script>

<style scoped>
.eco-page { min-height: 100vh; background: #f5f7fb; color: #102f6e; }
.eco-nav { display: flex; align-items: center; justify-content: space-between; padding: 24rpx 28rpx; background: #fff; border-bottom: 1rpx solid #e6ecf7; }
.nav-back { width: 64rpx; font-size: 44rpx; line-height: 1; color: #102f6e; }
.nav-title { font-size: 32rpx; font-weight: 700; }
.nav-side { width: 64rpx; }
.reg-page { padding: 28rpx; }
.event-head { background: linear-gradient(135deg, #102f6e, #165dff); color: #fff; border-radius: 22rpx; padding: 30rpx; display: flex; flex-direction: column; gap: 8rpx; }
.event-kind { font-size: 22rpx; opacity: 0.85; }
.event-title { font-size: 36rpx; font-weight: 700; line-height: 1.35; }
.event-meta { font-size: 24rpx; opacity: 0.9; }
.panel { margin-top: 24rpx; background: #fff; border-radius: 22rpx; padding: 30rpx; display: flex; flex-direction: column; gap: 20rpx; box-shadow: 0 8rpx 28rpx rgba(16, 47, 110, 0.06); }
.panel-title { font-size: 30rpx; font-weight: 700; color: #102f6e; }
.hint { font-size: 24rpx; color: #64748b; line-height: 1.5; }
.hint.warn { color: #b45309; }
.hint.ok { color: #15803d; }
.field { display: flex; flex-direction: column; gap: 10rpx; }
.label { font-size: 24rpx; color: #334155; }
.req { color: #dc2626; }
.input, .textarea { border: 1rpx solid #dbe4f3; border-radius: 14rpx; padding: 18rpx 20rpx; font-size: 28rpx; background: #fbfdff; }
.textarea { min-height: 140rpx; width: auto; }
.error { font-size: 22rpx; color: #dc2626; }
.privacy { background: #f2f6ff; border-radius: 14rpx; padding: 18rpx; display: flex; flex-direction: column; gap: 8rpx; }
.privacy-title { font-size: 24rpx; font-weight: 600; }
.privacy-text { font-size: 22rpx; color: #475569; line-height: 1.6; }
.consent { display: flex; align-items: flex-start; gap: 12rpx; font-size: 24rpx; color: #334155; line-height: 1.5; }
.actions { display: flex; gap: 16rpx; margin-top: 8rpx; }
.btn { flex: 1; text-align: center; border-radius: 999rpx; padding: 22rpx 24rpx; font-size: 28rpx; font-weight: 600; }
.btn.primary { background: #165dff; color: #fff; }
.btn.ghost { background: #eef4ff; color: #165dff; }
.btn.disabled { opacity: 0.55; }
.kv { display: flex; justify-content: space-between; font-size: 24rpx; color: #475569; }
.k { color: #94a3b8; }
.v { color: #102f6e; font-weight: 600; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
</style>
