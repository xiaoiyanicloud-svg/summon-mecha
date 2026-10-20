<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">成绩与展示</text>
      <view class="nav-side"></view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :empty="!loading && !errorMessage && tracks.length === 0"
      empty-icon="🏁"
      empty-text="成绩尚未公开"
      empty-hint="主办方公布成绩后才会出现在这里。"
      @retry="load"
    >
      <view class="page-body">
        <view v-for="track in tracks" :key="track.track_key" class="card">
          <text class="card-title">{{ track.name }}</text>
          <text class="muted">
            计分 {{ track.scoring.policy }} · 精度 {{ track.scoring.scale }} 位
            · 同分 {{ track.scoring.tie_policy }}
          </text>

          <view v-if="track.results.length === 0" class="muted">该赛项还没有公布成绩。</view>

          <view v-for="row in track.results" :key="row.result_version_id" class="result-row">
            <view class="result-main">
              <text class="result-team">{{ row.rank_no ? '#' + row.rank_no + ' ' : '' }}{{ row.team_name }}</text>
              <text class="muted">
                {{ row.entry_no }}
                <text v-if="row.total"> · 总分 {{ row.total }}</text>
                <text v-if="row.forfeit"> · {{ row.forfeit }}</text>
                <text v-if="row.under_review"> · 复核中（显示旧公布版本）</text>
              </text>
              <text v-if="row.appeal_deadline_at" class="muted">
                申诉截止 {{ formatTime(row.appeal_deadline_at) }}
              </text>
            </view>
            <button class="mini-btn" @click="startAppeal(row)">申诉</button>
          </view>

          <view v-if="track.awards && track.awards.length" class="award-block">
            <text class="section-label">奖项</text>
            <view v-for="award in track.awards" :key="award.award_key" class="award-row">
              <text>{{ award.name }} · {{ award.team_name }}</text>
            </view>
          </view>
        </view>

        <view v-if="appealTarget" class="card">
          <text class="card-title">提交申诉 · {{ appealTarget.team_name }}</text>
          <text class="muted">只有当前队长可以提交；窗口过期或旧成绩版本会被拒绝。</text>
          <textarea v-model="appealForm.reason" class="field textarea" placeholder="申诉理由（10~4000 字），请说明具体事实" />
          <view class="footer-actions">
            <button class="primary-btn" :disabled="busy" @click="submitAppeal">提交申诉</button>
            <button class="mini-btn ghost" @click="appealTarget = null">取消</button>
          </view>
        </view>

        <view v-if="appeals.length" class="card">
          <text class="card-title">我的申诉</text>
          <view v-for="appeal in appeals" :key="appeal.id" class="appeal-row">
            <view class="appeal-main">
              <text>{{ appeal.appeal_no }} · {{ statusLabel(appeal.status) }}</text>
              <text class="muted">{{ appeal.reason }}</text>
              <text v-if="appeal.decision_result" class="muted">
                裁决 {{ appeal.decision_result }}：{{ appeal.decision_reason }}
              </text>
            </view>
            <view class="appeal-actions">
              <button class="mini-btn ghost" @click="uploadEvidence(appeal)">补证据</button>
              <button
                v-if="!['decided','rejected','withdrawn'].includes(appeal.status)"
                class="mini-btn ghost"
                @click="withdrawAppeal(appeal)"
              >撤回</button>
            </view>
          </view>
        </view>

        <view v-if="showcaseTracks.length" class="card">
          <text class="card-title">作品展示</text>
          <view v-for="track in showcaseTracks" :key="'sh-' + track.track_key">
            <text class="section-label">{{ track.name }}</text>
            <view v-for="item in track.items" :key="item.title" class="showcase-row">
              <text class="showcase-title">{{ item.title }}</text>
              <text class="muted">{{ item.team_name }} · {{ item.award_name || item.showcase_type }}</text>
              <text class="showcase-summary">{{ item.summary }}</text>
            </view>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { competitionApi, competitionAppealApi } from '@/utils/api'

export default {
  name: 'CompetitionResults',
  components: { StateView },
  data() {
    return {
      eventId: 0,
      tracks: [],
      showcaseTracks: [],
      appeals: [],
      appealTarget: null,
      appealForm: { result_id: 0, result_version_id: 0, reason: '' },
      loading: false,
      busy: false,
      errorMessage: '',
    }
  },
  onLoad(query) {
    this.eventId = Number(query && query.eventId) || 0
    this.load()
  },
  methods: {
    goBack() {
      uni.navigateBack({ delta: 1 })
    },
    formatTime(value) {
      if (!value) return ''
      const date = new Date(value)
      if (Number.isNaN(date.getTime())) return value
      const pad = (n) => String(n).padStart(2, '0')
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
    },
    statusLabel(status) {
      const labels = {
        submitted: '待受理', accepted: '已受理', investigating: '调查中',
        decided: '已裁决', rejected: '未支持/不予受理', withdrawn: '已撤回',
      }
      return labels[status] || status
    },
    async load() {
      this.loading = true
      this.errorMessage = ''
      try {
        try {
          const response = await competitionApi.getResults(this.eventId)
          this.tracks = response && response.data && Array.isArray(response.data.results)
            ? response.data.results
            : []
        } catch (error) {
          this.tracks = []
        }

        try {
          const response = await competitionApi.getShowcases(this.eventId)
          const data = response && response.data ? response.data.showcases || {} : {}
          this.showcaseTracks = Object.keys(data).map((key) => ({
            track_key: key,
            name: (this.tracks.find((track) => track.track_key === key) || {}).name || key,
            items: data[key] || [],
          }))
        } catch (error) {
          this.showcaseTracks = []
        }

        try {
          const response = await competitionAppealApi.mine()
          this.appeals = response && response.data && Array.isArray(response.data.appeals)
            ? response.data.appeals
            : []
        } catch (error) {
          this.appeals = []
        }
      } catch (error) {
        this.errorMessage = (error && error.message) || '加载失败'
      } finally {
        this.loading = false
      }
    },
    startAppeal(row) {
      this.appealTarget = row
      this.appealForm = {
        result_id: row.result_id,
        result_version_id: row.result_version_id,
        reason: '',
      }
    },
    async submitAppeal() {
      if (this.busy) return
      if (this.appealForm.reason.trim().length < 10) {
        uni.showToast({ title: '请填写至少 10 字申诉理由', icon: 'none' })
        return
      }
      this.busy = true
      try {
        const response = await competitionAppealApi.create(this.appealForm)
        const appeal = response && response.data ? response.data : null
        uni.showToast({ title: '申诉已提交', icon: 'success' })
        this.appealTarget = null
        await this.load()
        if (appeal && appeal.id) {
          await this.chooseEvidence(appeal.id)
        }
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '提交失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    uploadEvidence(appeal) {
      this.chooseEvidence(appeal.id)
    },
    chooseEvidence(appealId) {
      return new Promise((resolve) => {
        uni.chooseImage({
          count: 1,
          success: async (res) => {
            const path = res.tempFilePaths && res.tempFilePaths[0]
            if (!path) { resolve(); return }
            try {
              await competitionAppealApi.uploadEvidence(appealId, path)
              uni.showToast({ title: '证据已上传', icon: 'success' })
              await this.load()
            } catch (error) {
              uni.showToast({ title: (error && error.message) || '上传失败', icon: 'none' })
            }
            resolve()
          },
          fail: () => resolve(),
        })
      })
    },
    async withdrawAppeal(appeal) {
      try {
        await competitionAppealApi.withdraw(appeal.id, {
          expected_row_version: appeal.row_version,
          reason: '用户主动撤回',
        })
        uni.showToast({ title: '已撤回', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '撤回失败', icon: 'none' })
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
.card-title { display: block; font-size: 30rpx; font-weight: 600; color: #111827; margin-bottom: 12rpx; }
.muted { display: block; font-size: 24rpx; color: #6b7280; line-height: 1.6; }
.result-row { display: flex; justify-content: space-between; align-items: center; padding: 18rpx 0; border-top: 1rpx solid #f0f1f5; }
.result-main { flex: 1; }
.result-team { display: block; font-size: 28rpx; color: #111827; }
.section-label { display: block; margin-top: 20rpx; font-size: 26rpx; font-weight: 600; color: #374151; }
.award-row { padding: 10rpx 0; font-size: 26rpx; color: #374151; }
.appeal-row { display: flex; justify-content: space-between; gap: 16rpx; padding: 16rpx 0; border-top: 1rpx solid #f0f1f5; }
.appeal-main { flex: 1; font-size: 26rpx; color: #111827; }
.appeal-actions { display: flex; flex-direction: column; gap: 8rpx; }
.showcase-row { padding: 16rpx 0; border-top: 1rpx solid #f0f1f5; }
.showcase-title { display: block; font-size: 28rpx; font-weight: 600; color: #111827; }
.showcase-summary { display: block; margin-top: 8rpx; font-size: 24rpx; color: #4b5563; line-height: 1.6; }
.field { width: 100%; box-sizing: border-box; margin-top: 16rpx; padding: 18rpx 20rpx; border: 1rpx solid #e5e7eb; border-radius: 12rpx; font-size: 26rpx; background: #fff; }
.textarea { min-height: 180rpx; }
.footer-actions { display: flex; gap: 16rpx; margin-top: 20rpx; }
.primary-btn { flex: 1; padding: 20rpx; border-radius: 12rpx; background: #2563eb; color: #fff; font-size: 26rpx; }
.mini-btn { padding: 14rpx 24rpx; border-radius: 12rpx; background: #2563eb; color: #fff; font-size: 24rpx; }
.mini-btn.ghost { background: #eef2ff; color: #3730a3; }
</style>
