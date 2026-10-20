<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">作品展示授权</text>
      <view class="nav-side"></view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :empty="!loading && !errorMessage && showcases.length === 0"
      empty-icon="🏆"
      empty-text="暂无待授权的展示"
      empty-hint="运营创建展示草稿后，队长可在这里授权公开展示。"
      @retry="load"
    >
      <view class="page-body">
        <view v-for="showcase in showcases" :key="showcase.id" class="card">
          <view class="head-row">
            <text class="card-title">{{ showcase.title }}</text>
            <text class="status-pill">{{ statusLabel(showcase.status) }}</text>
          </view>
          <text class="muted">{{ showcase.team_name }} · {{ showcase.award_name || '赛季回顾' }}</text>
          <text class="showcase-summary">{{ showcase.summary }}</text>
          <text class="muted">作品版本：{{ showcase.submission_title || ('#' + showcase.submission_version_id) }}</text>
          <text class="muted">
            授权状态：{{ showcase.consent_valid ? '已授权且内容未变化' : '未授权/授权失效' }}
          </text>

          <view class="footer-actions">
            <button v-if="!showcase.consent_valid" class="primary-btn" :disabled="busy" @click="setConsent(showcase, true)">
              授权公开展示
            </button>
            <button v-else class="mini-btn ghost" :disabled="busy" @click="setConsent(showcase, false)">
              撤回授权
            </button>
          </view>

          <view v-if="showcase.status === 'published'" class="handoff">
            <text class="section-label">进入技能提审（可选）</text>
            <text class="muted">
              需要先完成开发者入驻并拥有自己的技能版本；提审仍走既有协议/包/审核闸门，
              不会自动上架、发 license 或下发真机。
            </text>
            <input v-model="skillVersionInputs[showcase.id]" class="field" placeholder="输入自己的技能版本 ID" />
            <button class="mini-btn" :disabled="busy" @click="requestSkillReview(showcase)">申请提审</button>
            <text v-if="showcase.skill_version_id" class="muted">
              已交接技能版本 #{{ showcase.skill_version_id }}
            </text>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { competitionShowcaseApi } from '@/utils/api'

export default {
  name: 'CompetitionShowcaseConsent',
  components: { StateView },
  data() {
    return {
      showcases: [],
      skillVersionInputs: {},
      loading: false,
      busy: false,
      errorMessage: '',
    }
  },
  onLoad() {
    this.load()
  },
  methods: {
    goBack() {
      uni.navigateBack({ delta: 1 })
    },
    statusLabel(status) {
      const labels = {
        draft: '待授权/待审', submitted: '待审核', approved: '已通过待发布',
        published: '已发布', rejected: '已驳回', withdrawn: '已撤回',
      }
      return labels[status] || status
    },
    async load() {
      this.loading = true
      this.errorMessage = ''
      try {
        const response = await competitionShowcaseApi.mine()
        this.showcases = response && response.data && Array.isArray(response.data.showcases)
          ? response.data.showcases
          : []
      } catch (error) {
        this.errorMessage = (error && error.message) || '加载失败'
      } finally {
        this.loading = false
      }
    },
    async setConsent(showcase, consent) {
      if (this.busy) return
      this.busy = true
      try {
        await competitionShowcaseApi.consent(showcase.id, {
          consent,
          expected_row_version: showcase.row_version,
        })
        uni.showToast({ title: consent ? '已授权' : '已撤回授权', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '操作失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    async requestSkillReview(showcase) {
      if (this.busy) return
      const raw = this.skillVersionInputs[showcase.id]
      const skillVersionId = Number(raw)
      if (!skillVersionId) {
        uni.showToast({ title: '请填写技能版本 ID', icon: 'none' })
        return
      }
      this.busy = true
      try {
        const response = await competitionShowcaseApi.requestSkillReview(showcase.id, {
          skill_version_id: skillVersionId,
        })
        const data = response && response.data ? response.data : {}
        uni.showToast({ title: data.message || '已提交提审', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '提审失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
  },
}
</script>

<style scoped>
.eco-page { min-height: 100vh; background: #f5f7fb; }
.eco-nav { display: flex; align-items: center; justify-content: space-between; padding: 24rpx 28rpx; background: #fff; }
.nav-back { width: 64rpx; font-size: 44rpx; color: #1f2937; }
.nav-title { font-size: 32rpx; font-weight: 600; color: #111827; }
.nav-side { width: 64rpx; }
.page-body { padding: 24rpx; }
.card { background: #fff; border-radius: 20rpx; padding: 24rpx; margin-bottom: 24rpx; }
.head-row { display: flex; justify-content: space-between; align-items: center; }
.card-title { font-size: 30rpx; font-weight: 600; color: #111827; }
.status-pill { font-size: 22rpx; color: #3730a3; background: #eef2ff; border-radius: 999rpx; padding: 6rpx 16rpx; }
.muted { display: block; margin-top: 8rpx; font-size: 24rpx; color: #6b7280; line-height: 1.6; }
.showcase-summary { display: block; margin-top: 16rpx; font-size: 26rpx; color: #4b5563; line-height: 1.7; }
.footer-actions { display: flex; gap: 16rpx; margin-top: 20rpx; }
.section-label { display: block; margin-top: 26rpx; font-size: 26rpx; font-weight: 600; color: #374151; }
.handoff { margin-top: 20rpx; padding-top: 20rpx; border-top: 1rpx solid #f0f1f5; }
.field { width: 100%; box-sizing: border-box; margin-top: 16rpx; padding: 18rpx 20rpx; border: 1rpx solid #e5e7eb; border-radius: 12rpx; font-size: 26rpx; }
.primary-btn { flex: 1; padding: 20rpx; border-radius: 12rpx; background: #2563eb; color: #fff; font-size: 26rpx; }
.mini-btn { margin-top: 12rpx; padding: 16rpx 24rpx; border-radius: 12rpx; background: #2563eb; color: #fff; font-size: 24rpx; }
.mini-btn.ghost { background: #eef2ff; color: #3730a3; }
</style>
