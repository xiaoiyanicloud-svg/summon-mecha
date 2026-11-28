<template>
  <view class="eco-page">
    <nav-bar title="我的报名" fallback-tab="/pages/mine/index" />

    <view v-if="hasToken" class="comp-bar">
      <view class="comp-pill" role="button" tabindex="0" @click="goSub('/pages/ecosystem/competition-team')">组队参赛</view>
      <view class="comp-pill" role="button" tabindex="0" @click="goSub('/pages/ecosystem/competition-submission')">作品提交</view>
      <view class="comp-pill" role="button" tabindex="0" @click="goSub('/pages/ecosystem/competition-showcase')">展示授权</view>
      <view class="comp-pill" role="button" tabindex="0" @click="goSub('/pages/ecosystem/events')">赛事大厅</view>
    </view>

    <view v-if="!hasToken" class="panel login-needed">
      <text class="panel-title">请先登录</text>
      <text class="hint">登录后可查看本人报名、回执、取消/重报、变更确认与入场凭证。</text>
      <view class="actions">
        <view class="btn primary" role="button" tabindex="0" @click="goLogin">去登录</view>
      </view>
    </view>

    <state-view
      v-else
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '/static/icons/mine/state-error.png'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && items.length === 0"
      empty-icon="/static/icons/mine/empty-event.png"
      empty-text="还没有报名记录"
      empty-hint="到赛事活动页查看可报名的活动。"
      @retry="load"
    >
      <view class="list">
        <view v-for="item in items" :key="item.public_no" class="card">
          <view class="card-head">
            <text class="event-name">{{ item.event && item.event.name ? item.event.name : '活动 #' + item.event_id }}</text>
            <text class="status" :class="'st-' + item.status">{{ statusLabel(item.status) }}</text>
          </view>

          <view class="kv"><text class="k">报名编号</text><text class="v mono">{{ item.public_no }}</text></view>
          <view class="kv"><text class="k">轮次</text><text class="v">#{{ item.attempt_no }}</text></view>
          <view class="kv"><text class="k">联系人</text><text class="v">{{ item.contact.name }} · {{ item.contact.phone }}</text></view>
          <view class="kv"><text class="k">提交时间</text><text class="v">{{ formatTime(item.submitted_at) }}</text></view>
          <text v-if="item.status === 'submitted'" class="hint warn">待审核，不保证名额；批准后才占确认席位。</text>
          <text v-if="item.status === 'checked_in'" class="hint ok">已签到；签到仍占确认名额。</text>
          <text v-if="item.status === 'rejected' && item.decision_reason_code" class="hint">原因码：{{ item.decision_reason_code }}</text>

          <view v-if="item.changes && item.changes.length" class="changes">
            <text class="changes-title">待确认变更</text>
            <view v-for="change in item.changes" :key="change.id" class="change-row">
              <text class="change-text">{{ change.summary }}</text>
              <text class="hint">截止：{{ formatTime(change.ack_deadline) }}</text>
              <view class="actions compact">
                <view class="btn small primary" role="button" tabindex="0" @click="ack(item, change, 'accepted')">接受</view>
                <view class="btn small ghost" role="button" tabindex="0" @click="ack(item, change, 'declined')">拒绝</view>
              </view>
            </view>
          </view>

          <view v-if="credentialFor === item.public_no && credential" class="credential">
            <text class="changes-title">入场凭证</text>
            <text class="hint">有效期至 {{ formatTime(credential.expires_at) }}。每次重发都会让旧凭证失效。</text>
            <text class="qr mono">{{ credential.qr_payload }}</text>
            <view class="actions compact">
              <view class="btn small primary" role="button" tabindex="0" @click="copyCredential">复制凭证内容</view>
            </view>
          </view>

          <view class="actions">
            <view v-if="item.can_cancel" class="btn small ghost" role="button" tabindex="0" @click="cancel(item)">取消报名</view>
            <view v-if="item.can_reapply" class="btn small primary" role="button" tabindex="0" @click="reapply(item)">重新报名</view>
            <view v-if="item.status === 'confirmed' || item.status === 'checked_in'" class="btn small primary" role="button" tabindex="0" @click="loadCredential(item)">入场凭证</view>
            <view class="btn small ghost" role="button" tabindex="0" @click="loadChanges(item)">查看变更</view>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { eventRegistrationApi } from '@/utils/api'
import { TOKEN_KEY } from '@/utils/config'
import { errorState } from '@/utils/ecosystem-public'

function newKey(prefix) {
  const g = typeof globalThis !== 'undefined' ? globalThis : {}
  if (g.crypto && typeof g.crypto.getRandomValues === 'function') {
    const buf = new Uint8Array(12)
    g.crypto.getRandomValues(buf)
    return prefix + '-' + Array.from(buf, b => b.toString(16).padStart(2, '0')).join('')
  }
  return prefix + '-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10)
}

export default {
  name: 'EcosystemMyRegistrations',
  components: { NavBar, StateView },
  data() {
    return {
      hasToken: false,
      loading: false,
      errorMessage: '',
      errorInfo: null,
      items: [],
      credentialFor: '',
      credential: null,
    }
  },
  onShow() {
    this.hasToken = Boolean(uni.getStorageSync(TOKEN_KEY))
    if (this.hasToken) this.load()
  },
  onPullDownRefresh() {
    this.load().finally(() => uni.stopPullDownRefresh())
  },
  methods: {
    goBack() {
      uni.navigateBack({ delta: 1, fail: () => uni.switchTab({ url: '/pages/mine/index' }) })
    },
    goLogin() {
      uni.switchTab({ url: '/pages/mine/index' })
    },
    goSub(url) {
      uni.navigateTo({ url })
    },
    statusLabel(status) {
      const labels = {
        submitted: '待审核',
        confirmed: '已确认',
        checked_in: '已签到',
        rejected: '未通过',
        canceled: '已取消',
      }
      return labels[status] || status || '未知'
    },
    formatTime(value) {
      if (typeof value !== 'string' || !value) return '—'
      // 服务端已按活动/平台时区输出带偏移的 ISO；这里只做可读化，不二次换算时区。
      return value.replace('T', ' ').replace(/\.\d+/, '').replace(/([+-]\d{2}:\d{2}|Z)$/, '')
    },
    applyError(error) {
      const info = errorState(error)
      this.items = []
      this.errorMessage = info.message
      this.errorInfo = info
    },
    async load() {
      this.hasToken = Boolean(uni.getStorageSync(TOKEN_KEY))
      if (!this.hasToken) return

      this.loading = true
      this.errorMessage = ''
      this.errorInfo = null

      try {
        const response = await eventRegistrationApi.mine({ page: 1, per_page: 50 })
        const rows = response && Array.isArray(response.data) ? response.data : []
        this.items = rows
      } catch (error) {
        if (error && error.statusCode === 401) {
          this.hasToken = false
          this.items = []
          return
        }
        this.applyError(error)
      } finally {
        this.loading = false
      }
    },
    async cancel(item) {
      const confirmed = await new Promise((resolve) => {
        uni.showModal({
          title: '取消报名',
          content: item.status === 'confirmed'
            ? '取消后会释放确认席位；若已截止则可能不允许自助取消。'
            : '取消后本轮报名进入终态，可按规则重新报名。',
          success: (res) => resolve(Boolean(res.confirm)),
          fail: () => resolve(false),
        })
      })

      if (!confirmed) return

      try {
        await eventRegistrationApi.cancel(item.public_no, {
          request_key: newKey('cancel'),
          expected_attempt_id: Number(item.attempt_id),
        })
        uni.showToast({ title: '已取消', icon: 'success' })
        this.credential = null
        this.credentialFor = ''
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '取消失败', icon: 'none' })
      }
    },
    reapply(item) {
      if (!item.event || !item.event.id) {
        uni.showToast({ title: '活动信息缺失', icon: 'none' })
        return
      }
      uni.navigateTo({
        url: `/pages/ecosystem/event-register?id=${item.event.id}&public_no=${encodeURIComponent(item.public_no)}&reapply=1`,
      })
    },
    async loadCredential(item) {
      try {
        const response = await eventRegistrationApi.credential(item.public_no)
        this.credential = response && response.data ? response.data : null
        this.credentialFor = item.public_no
        if (!this.credential) throw new Error('凭证格式异常')
        uni.showToast({ title: '凭证已签发', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '签发失败', icon: 'none' })
      }
    },
    copyCredential() {
      if (!this.credential || !this.credential.qr_payload) {
        uni.showToast({ title: '暂无凭证', icon: 'none' })
        return
      }
      uni.setClipboardData({
        data: this.credential.qr_payload,
        success: () => uni.showToast({ title: '凭证内容已复制', icon: 'success' }),
        fail: () => uni.showToast({ title: '复制失败', icon: 'none' }),
      })
    },
    async loadChanges(item) {
      try {
        const response = await eventRegistrationApi.changes(item.public_no)
        const rows = response && Array.isArray(response.data) ? response.data : []
        // Vue 2 不会追踪后加的普通属性；用 $set 保证列表即时更新。
        if (typeof this.$set === 'function') this.$set(item, 'changes', rows)
        else item.changes = rows
        if (!rows.length) uni.showToast({ title: '暂无待确认变更', icon: 'none' })
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '加载失败', icon: 'none' })
      }
    },
    async ack(item, change, response) {
      try {
        await eventRegistrationApi.ackChange(item.public_no, change.id, {
          request_key: newKey('ack'),
          expected_attempt_id: Number(item.attempt_id),
          response,
        })
        uni.showToast({ title: '已记录', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '确认失败', icon: 'none' })
      }
    },
  },
}
</script>

<style scoped>
.eco-page { min-height: 100vh; background: #F4F7FB; color: #0F172A; }
.comp-bar { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12rpx; padding: 18rpx 24rpx 0; }
.comp-pill { text-align: center; padding: 14rpx 8rpx; border-radius: 12rpx; background: #FFFFFF; border: 1rpx solid #DCE5F2; font-size: 24rpx; font-weight: 500; color: #334155; }
.list { padding: 20rpx 24rpx 28rpx; display: flex; flex-direction: column; gap: 16rpx; }
.card, .panel { background: #FFFFFF; border: 1rpx solid #E5EAF2; border-radius: 18rpx; padding: 26rpx; display: flex; flex-direction: column; gap: 16rpx; }
.login-needed { margin: 24rpx; }
.card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16rpx; }
.event-name { font-size: 30rpx; font-weight: 600; color: #0F172A; line-height: 1.4; }
.status { font-size: 22rpx; padding: 6rpx 14rpx; border-radius: 999rpx; background: #EEF4FF; color: #165DFF; white-space: nowrap; font-weight: 500; }
.st-submitted { background: #FFFBEB; color: #B45309; }
.st-confirmed { background: #ECFDF5; color: #059669; }
.st-checked_in { background: #EEF4FF; color: #165DFF; }
.st-rejected, .st-canceled { background: #F1F5F9; color: #64748B; }
.kv { display: flex; justify-content: space-between; font-size: 24rpx; color: #475569; gap: 20rpx; }
.k { color: #64748B; white-space: nowrap; }
.v { color: #0F172A; text-align: right; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.hint { font-size: 23rpx; color: #64748B; line-height: 1.6; }
.hint.warn { color: #B45309; }
.hint.ok { color: #059669; }
.actions { display: flex; flex-wrap: wrap; gap: 14rpx; margin-top: 6rpx; }
.actions.compact { margin-top: 2rpx; }
.btn { text-align: center; border-radius: 999rpx; padding: 18rpx 26rpx; font-size: 26rpx; font-weight: 600; }
.btn.primary { background: #165DFF; color: #FFFFFF; }
.btn.ghost { background: #F1F5F9; color: #334155; }
.btn.small { padding: 14rpx 22rpx; font-size: 24rpx; }
.changes, .credential { background: #F8FAFC; border: 1rpx solid #E5EAF2; border-radius: 14rpx; padding: 18rpx; display: flex; flex-direction: column; gap: 10rpx; }
.changes-title { font-size: 24rpx; font-weight: 600; color: #0F172A; }
.change-row { display: flex; flex-direction: column; gap: 6rpx; padding-top: 8rpx; border-top: 1rpx solid #E5EAF2; }
.change-text { font-size: 24rpx; color: #334155; }
.qr { font-size: 22rpx; color: #0F172A; word-break: break-all; background: #FFFFFF; border: 1rpx solid #E5EAF2; padding: 14rpx; border-radius: 12rpx; }
</style>
