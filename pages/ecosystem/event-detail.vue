<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">赛事详情</text>
      <view class="nav-actions">
        <view
          v-if="event"
          class="nav-fav"
          :class="{ on: event.favorited }"
          role="button"
          tabindex="0"
          @click="toggleFavorite"
        >{{ event.favorited ? '已收藏' : '收藏' }}</view>
        <!-- #ifdef H5 -->
        <view class="nav-share" role="button" tabindex="0" @click="copyLink">复制链接</view>
        <!-- #endif -->
      </view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :error-text="errorInfo ? errorInfo.title : ''"
      :error-icon="errorInfo ? errorInfo.icon : '📡'"
      :retry="errorInfo ? errorInfo.retryable : true"
      :empty="!loading && !errorMessage && !event"
      empty-icon="🏟️"
      empty-text="赛事不存在或未公开"
      empty-hint="请返回列表查看已发布的赛事。"
      @retry="load"
    >
      <view v-if="event" class="detail">
        <view class="hero">
          <image v-if="coverUrl" class="hero-img" :src="coverUrl" mode="aspectFill"></image>
          <view v-else class="hero-fallback">
            <text class="hero-icon">🏁</text>
            <text class="hero-kind">{{ kindLabel }}</text>
          </view>
          <text class="status-badge" :class="'st-' + status.code">{{ status.label }}</text>
        </view>

        <view class="detail-body">
          <view class="title-row">
            <text class="detail-kind">{{ kindLabel }}</text>
            <text class="detail-title">{{ event.name }}</text>
          </view>
          <text v-if="event.summary" class="detail-summary">{{ event.summary }}</text>

          <view class="info-grid">
            <view class="info-item">
              <text class="info-label">时间</text>
              <text class="info-value">{{ whenText }}</text>
              <text v-if="timezoneText" class="info-sub">{{ timezoneText }}</text>
            </view>
            <view class="info-item">
              <text class="info-label">地点</text>
              <text class="info-value">{{ placeText || '地点待定' }}</text>
            </view>
            <view class="info-item">
              <text class="info-label">参与方式</text>
              <text class="info-value">{{ participationText }}</text>
            </view>
            <view class="info-item">
              <text class="info-label">主办方</text>
              <text class="info-value">{{ event.organizer.name || '未提供' }}</text>
              <text v-if="organizerRoleText" class="info-sub">{{ organizerRoleText }}</text>
            </view>
          </view>

          <view class="reg-card">
            <text class="reg-title">报名与参与</text>
            <view
              v-if="registration.mode === 'register'"
              class="reg-btn"
              role="button"
              tabindex="0"
              @click="onRegister"
            >立即报名</view>
            <view
              v-else-if="registration.mode === 'login'"
              class="reg-btn"
              role="button"
              tabindex="0"
              @click="onRegister"
            >登录后报名</view>
            <view
              v-else-if="registration.mode === 'mine'"
              class="reg-btn"
              role="button"
              tabindex="0"
              @click="goMyRegistrations"
            >查看我的报名</view>
            <view
              v-else-if="registration.mode === 'external'"
              class="reg-btn"
              role="button"
              tabindex="0"
              @click="openExternal"
            >前往主办方报名</view>
            <text v-else class="reg-reason">{{ registration.reason }}</text>
          </view>

          <view v-if="bodyHtml" class="section">
            <text class="section-title">活动介绍</text>
            <rich-text class="rich-body" :nodes="bodyHtml"></rich-text>
          </view>

          <view v-if="competition" class="section">
            <text class="section-title">赛项与赛程</text>
            <view v-for="track in competition.tracks" :key="track.id" class="competition-track">
              <view class="competition-track-main">
                <text class="competition-track-name">{{ track.name }}</text>
                <text class="competition-track-meta">
                  {{ track.min_team_size || '?' }}–{{ track.max_team_size || '?' }} 人
                  · {{ track.allow_multi_team ? '允许一人多队' : '一人一队' }}
                  <text v-if="track.registration && track.registration.closes_at">
                    · 报名截止 {{ formatDateTime(track.registration.closes_at) }}
                  </text>
                </text>
              </view>
              <view class="competition-track-btn" role="button" tabindex="0" @click="openCompetitionTeam(track.id)">
                {{ hasMyTeam(track) ? '我的队伍' : '组队参赛' }}
              </view>
            </view>
            <view v-if="competition.tracks.length === 0" class="competition-empty">
              赛项尚未开放。
            </view>
            <view class="competition-actions">
              <view class="competition-track-btn" role="button" tabindex="0" @click="openCompetitionResults">
                成绩与展示
              </view>
              <view class="competition-track-btn" role="button" tabindex="0" @click="openCompetitionShowcase">
                展示授权
              </view>
            </view>
            <view v-if="competition.schedule && competition.schedule.length">
              <view v-for="track in competition.schedule" :key="'s-' + track.track_key" class="schedule-track">
                <text class="schedule-track-name">{{ track.name }}</text>
                <view v-for="stage in track.stages" :key="stage.stage_key" class="schedule-stage">
                  <text class="schedule-stage-name">{{ stage.name }}</text>
                  <view v-for="match in stage.matches" :key="match.match_key" class="schedule-match">
                    <text>{{ match.home ? match.home.team_name : '待定' }} vs {{ match.away ? match.away.team_name : '待定' }}</text>
                    <text class="schedule-time">{{ formatDateTime(match.starts_at) }} · {{ match.venue || '场地待定' }}</text>
                  </view>
                </view>
              </view>
            </view>
          </view>

          <view v-if="relatedGroups.length" class="section">
            <text class="section-title">相关内容</text>
            <view v-for="group in relatedGroups" :key="group.key" class="related-group">
              <text class="related-label">{{ group.label }}</text>
              <view class="related-items">
                <view
                  v-for="item in group.items"
                  :key="group.key + '-' + (item.id || item.slug || item.productId)"
                  class="related-item"
                  :class="{ linkable: group.key === 'events' || group.key === 'contents' }"
                  role="button"
                  tabindex="0"
                  @click="openRelated(group.key, item)"
                >{{ relatedTitle(group.key, item) }}</view>
              </view>
            </view>
          </view>

          <view v-if="event.source.name" class="section">
            <text class="section-title">信息来源</text>
            <text class="source-name">{{ event.source.name }}</text>
            <text v-if="event.source.url" class="source-url">{{ event.source.url }}</text>
          </view>

          <view v-if="publishedText" class="publish-line">公开时间：{{ publishedText }}</view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { eventApi, competitionApi, favoriteApi } from '@/utils/api'
import { BASE_URL, SSR_BASE_URL } from '@/utils/config'
import { buildSeo, applySeoToDocument } from '@/utils/ecosystem-seo'
import {
  normalizeEvent,
  errorState,
  eventStatus,
  eventKindLabel,
  formatEventWhen,
  formatDateTime,
  timezoneLabel,
  resolveMediaUrl,
  pickCover,
  renderRichHtml,
  registrationView,
  buildSharePath,
  copyShareLink,
  resolveShareBase,
} from '@/utils/ecosystem-public'

/**
 * 分享基址必须是**当前 H5 站点**，不是 API 的 BASE_URL。
 * 小程序没有可复制的网页地址，返回空串（分享仍走 buildSharePath）。
 */
function h5ShareBase() {
  // #ifdef H5
  return resolveShareBase(window.location)
  // #endif
  // #ifndef H5
  return ''
  // #endif
}

export default {
  name: 'EcosystemEventDetail',
  components: { StateView },
  data() {
    return {
      eventId: 0,
      event: null,
      competition: null,
      loading: false,
      errorMessage: '',
      errorInfo: null,
      requestSeq: 0,
    }
  },
  computed: {
    status() {
      return eventStatus(this.event)
    },
    kindLabel() {
      return this.event ? eventKindLabel(this.event.kind) : '活动'
    },
    whenText() {
      return this.event ? formatEventWhen(this.event) : ''
    },
    timezoneText() {
      if (!this.event) return ''
      return timezoneLabel(this.event.timezone)
    },
    placeText() {
      if (!this.event) return ''
      return [this.event.city, this.event.locationText].filter(Boolean).join(' · ')
    },
    participationText() {
      if (!this.event) return ''
      const labels = { offline: '线下', online: '线上', hybrid: '线上 + 线下' }
      return labels[this.event.participationMode] || '未提供'
    },
    organizerRoleText() {
      if (!this.event) return ''
      const labels = {
        organizer: '主办方',
        co_organizer: '联合主办',
        authorized_operator: '授权承办',
        directory_only: '仅收录',
      }
      return labels[this.event.organizer.platformRole] || ''
    },
    coverUrl() {
      if (!this.event) return ''
      const cover = pickCover(this.event.media)
      return cover ? resolveMediaUrl(cover.url, BASE_URL) : ''
    },
    bodyHtml() {
      if (!this.event) return ''
      return renderRichHtml(this.event.bodyHtml, this.event.media, BASE_URL)
    },
    registration() {
      return registrationView(this.event)
    },
    publishedText() {
      return this.event ? formatDateTime(this.event.publishedAt) : ''
    },
    sharePath() {
      return this.event ? buildSharePath('event', this.event.id) : ''
    },
    relatedGroups() {
      if (!this.event || !this.event.related) return []

      const labels = {
        events: '相关活动',
        contents: '相关资讯',
        device_models: '相关机型',
        skills: '相关技能',
        scenes: '相关场景',
      }

      return Object.keys(labels)
        .map((key) => ({ key, label: labels[key], items: this.event.related[key] || [] }))
        .filter((group) => group.items.length > 0)
    },
  },
  onLoad(query) {
    const id = Number(query && query.id)

    if (!Number.isInteger(id) || id <= 0) {
      this.applyError(errorState({ statusCode: 404 }))
      return
    }

    this.eventId = id
    this.load()
  },
  onPullDownRefresh() {
    this.load().finally(() => uni.stopPullDownRefresh())
  },
  onShareAppMessage() {
    // 只有已公开详情才分享真实路径；404/410 状态退回列表页。
    if (this.event && this.sharePath) {
      return { title: this.event.name, path: this.sharePath }
    }
    return { title: '召唤机甲 · 赛事活动', path: '/pages/ecosystem/events' }
  },
  methods: {
    goBack() {
      uni.navigateBack({
        delta: 1,
        fail: () => uni.switchTab({ url: '/pages/home/index' }),
      })
    },
    applyError(error) {
      const info = errorState(error)
      this.event = null
      this.competition = null
      this.loading = false
      this.errorMessage = info.message
      this.errorInfo = info
      // #ifdef H5
      applySeoToDocument(buildSeo({ type: 'event', entity: {}, baseUrl: SSR_BASE_URL }), document)
      // #endif
    },
    applySeo(event) {
      // #ifdef H5
      const cover = pickCover(event.media)
      const image = cover ? resolveMediaUrl(cover.url, BASE_URL) : undefined
      applySeoToDocument(
        buildSeo({ type: 'event', entity: event, baseUrl: SSR_BASE_URL, image }),
        document,
      )
      // #endif
    },
    async toggleFavorite() {
      if (!this.event) return

      const event = this.event

      try {
        if (event.favorited) {
          const response = await favoriteApi.remove('event', event.id)
          event.favorited = false
          uni.showToast({
            title: response && response.data && response.data.removed === false ? '原本就不在收藏中' : '已取消收藏',
            icon: 'none',
          })
        } else {
          const response = await favoriteApi.add('event', event.id)
          event.favorited = true
          uni.showToast({
            title: response && response.data && response.data.created === false ? '已在收藏中' : '已收藏',
            icon: response && response.data && response.data.created === false ? 'none' : 'success',
          })
        }
      } catch (error) {
        // 失败不改本地状态，也不提示成功。
        const message = errorState(error).message || '操作失败，请稍后重试'
        uni.showToast({ title: message, icon: 'none' })
      }
    },
    relatedTitle(key, item) {
      if (key === 'events') return item.name || '活动'
      if (key === 'contents') return item.title || '资讯'
      if (key === 'device_models') return [item.brand, item.name].filter(Boolean).join(' ') || '机型'
      return item.name || '相关内容'
    },
    openRelated(key, item) {
      if (key === 'events' && item.id) {
        uni.navigateTo({ url: `/pages/ecosystem/event-detail?id=${item.id}` })
      } else if (key === 'contents' && item.slug) {
        uni.navigateTo({ url: `/pages/ecosystem/content-detail?slug=${encodeURIComponent(item.slug)}` })
      }
      // 机型/技能/场景只展示事实，不提供误导性跳转或购买承诺。
    },
    async load() {
      if (!this.eventId) return

      const seq = ++this.requestSeq
      this.loading = true
      this.errorMessage = ''
      this.errorInfo = null

      try {
        // 详情只按 ID 重新请求：支持直达/刷新，不依赖列表内存对象。
        const response = await eventApi.getEvent(this.eventId)
        if (seq !== this.requestSeq) return

        const event = normalizeEvent(response && response.data)
        if (!event) throw new Error('赛事详情格式异常')

        this.event = event
        this.applySeo(event)

        // G3：赛事赛项/赛程是附加信息。拉取失败不能把整页详情打成故障，
        // 但也不能伪造赛项 —— competition 保持 null，页面不显示该区块。
        if (event.kind === 'competition') {
          try {
            const competition = await competitionApi.getByEvent(this.eventId)
            if (seq !== this.requestSeq) return
            this.competition = competition && competition.data ? competition.data : null
          } catch (competitionError) {
            this.competition = null
          }
        } else {
          this.competition = null
        }
      } catch (error) {
        if (seq !== this.requestSeq) return
        this.applyError(error)
      } finally {
        if (seq === this.requestSeq) this.loading = false
      }
    },
    copyLink() {
      const path = this.sharePath
      if (!path) {
        uni.showToast({ title: '内容尚未公开，无法分享', icon: 'none' })
        return
      }

      copyShareLink(path, h5ShareBase(), (text) => new Promise((resolve, reject) => {
        uni.setClipboardData({ data: text, success: resolve, fail: reject })
      })).then((result) => {
        // 只有写入成功才提示成功。
        uni.showToast({ title: result.message, icon: result.ok ? 'success' : 'none' })
      })
    },
    onRegister() {
      if (!this.event) return
      uni.navigateTo({ url: `/pages/ecosystem/event-register?id=${this.event.id}` })
    },
    goMyRegistrations() {
      uni.navigateTo({ url: '/pages/ecosystem/my-registrations' })
    },
    hasMyTeam(track) {
      if (!this.competition || !Array.isArray(this.competition.my_teams)) return false
      return this.competition.my_teams.some((team) => team.track_key === track.track_key)
    },
    openCompetitionTeam(trackId) {
      uni.navigateTo({
        url: `/pages/ecosystem/competition-team?eventId=${this.eventId}&trackId=${trackId}`,
      })
    },
    openCompetitionResults() {
      uni.navigateTo({
        url: `/pages/ecosystem/competition-results?eventId=${this.eventId}`,
      })
    },
    openCompetitionShowcase() {
      uni.navigateTo({
        url: '/pages/ecosystem/competition-showcase',
      })
    },
    openExternal() {
      const url = this.registration.url
      if (!url) return

      // #ifdef H5
      window.open(url, '_blank', 'noopener')
      // #endif

      // #ifndef H5
      uni.setClipboardData({
        data: url,
        success: () => uni.showToast({ title: '链接已复制，请在浏览器打开', icon: 'none' }),
        fail: () => uni.showToast({ title: '复制失败，请稍后重试', icon: 'none' }),
      })
      // #endif
    },
  },
}
</script>

<style scoped>
.eco-page {
  min-height: 100vh;
  background: #F5F7FB;
  padding-bottom: 40rpx;
  box-sizing: border-box;
}
.eco-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 88rpx 24rpx 22rpx;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
  color: #FFFFFF;
}
.nav-back,
.nav-side {
  width: 96rpx;
  height: 60rpx;
  line-height: 52rpx;
  font-size: 48rpx;
  font-weight: 300;
}
.nav-actions {
  display: flex;
  align-items: center;
  gap: 18rpx;
  min-width: 96rpx;
  justify-content: flex-end;
}
.nav-fav {
  padding: 6rpx 18rpx;
  border-radius: 24rpx;
  border: 1rpx solid rgba(255, 255, 255, 0.55);
  color: #FFFFFF;
  font-size: 22rpx;
}
.nav-fav.on {
  background: #FFFFFF;
  color: #102F6E;
  font-weight: 600;
}
.nav-back:focus-visible,
.nav-share:focus-visible,
.nav-fav:focus-visible {
  outline: 3rpx solid #FFFFFF;
  border-radius: 8rpx;
}
.nav-title {
  flex: 1;
  text-align: center;
  font-size: 34rpx;
  font-weight: 600;
  letter-spacing: 2rpx;
}
.nav-share {
  width: 96rpx;
  text-align: right;
  font-size: 24rpx;
  color: #FFFFFF;
}
.detail {
  padding: 24rpx;
}
.hero {
  position: relative;
  height: 360rpx;
  border-radius: 20rpx;
  overflow: hidden;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
  display: flex;
  align-items: center;
  justify-content: center;
}
.hero-img { width: 100%; height: 100%; }
.hero-fallback {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.hero-icon { font-size: 120rpx; }
.hero-kind {
  margin-top: 12rpx;
  font-size: 26rpx;
  color: rgba(255, 255, 255, 0.85);
  letter-spacing: 4rpx;
}
.status-badge {
  position: absolute;
  top: 20rpx;
  right: 20rpx;
  padding: 8rpx 22rpx;
  border-radius: 28rpx;
  font-size: 23rpx;
  font-weight: 600;
  color: #FFFFFF;
  background: #475569;
}
.st-ongoing { background: #165DFF; }
.st-upcoming { background: #0E7C5A; }
.st-ended,
.st-canceled { background: #52607A; }
.st-pending { background: #92400E; }
.detail-body {
  margin-top: 24rpx;
  background: #FFFFFF;
  border: 1rpx solid #E5EAF3;
  border-radius: 20rpx;
  padding: 28rpx 26rpx 34rpx;
}
.title-row {
  display: flex;
  flex-direction: column;
}
.detail-kind {
  align-self: flex-start;
  padding: 4rpx 16rpx;
  margin-bottom: 12rpx;
  border-radius: 8rpx;
  background: #EEF4FF;
  color: #102F6E;
  font-size: 22rpx;
  font-weight: 600;
}
.detail-title {
  font-size: 40rpx;
  font-weight: 700;
  line-height: 1.35;
  color: #102F6E;
}
.detail-summary {
  display: block;
  margin-top: 14rpx;
  font-size: 26rpx;
  line-height: 1.7;
  color: #475569;
}
.info-grid {
  margin-top: 24rpx;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18rpx 20rpx;
  padding: 20rpx 22rpx;
  border-radius: 16rpx;
  background: #F8FAFF;
  border: 1rpx solid #E5EAF3;
}
.info-item {
  display: flex;
  flex-direction: column;
}
.info-label {
  font-size: 22rpx;
  color: #7A8AA3;
}
.info-value {
  margin-top: 4rpx;
  font-size: 26rpx;
  font-weight: 600;
  line-height: 1.5;
  color: #102F6E;
  word-break: break-all;
}
.info-sub {
  margin-top: 2rpx;
  font-size: 21rpx;
  color: #8A9AA8;
}
.reg-card {
  margin-top: 24rpx;
  padding: 24rpx;
  border-radius: 16rpx;
  border: 1rpx solid #D7E2F7;
  background: #F4F8FF;
}
.reg-title {
  display: block;
  font-size: 26rpx;
  font-weight: 700;
  color: #102F6E;
  margin-bottom: 14rpx;
}
.reg-btn {
  display: inline-block;
  padding: 18rpx 48rpx;
  border-radius: 40rpx;
  background: #165DFF;
  color: #FFFFFF;
  font-size: 27rpx;
  font-weight: 600;
  text-align: center;
}
.reg-btn:focus-visible {
  outline: 3rpx solid #102F6E;
  outline-offset: 2rpx;
}
.reg-reason {
  font-size: 25rpx;
  line-height: 1.6;
  color: #5B6B85;
}
.section {
  margin-top: 30rpx;
}
.section-title {
  display: block;
  margin-bottom: 14rpx;
  padding-left: 16rpx;
  border-left: 6rpx solid #165DFF;
  font-size: 29rpx;
  font-weight: 700;
  color: #102F6E;
}
.rich-body {
  font-size: 27rpx;
  line-height: 1.8;
  color: #334155;
  word-break: break-word;
}
.related-group {
  margin-bottom: 16rpx;
}
.related-label {
  display: block;
  margin-bottom: 8rpx;
  font-size: 23rpx;
  font-weight: 600;
  color: #475569;
}
.related-items {
  display: flex;
  flex-wrap: wrap;
  gap: 10rpx;
}
.related-item {
  padding: 8rpx 18rpx;
  border-radius: 24rpx;
  background: #F1F5FD;
  border: 1rpx solid #DCE5F5;
  font-size: 23rpx;
  color: #334155;
}
.related-item.linkable {
  color: #165DFF;
}
.related-item.linkable:focus-visible {
  outline: 3rpx solid #165DFF;
}
.source-name {
  font-size: 26rpx;
  font-weight: 600;
  color: #102F6E;
}
.source-url {
  display: block;
  margin-top: 6rpx;
  font-size: 23rpx;
  color: #5B6B85;
  word-break: break-all;
}
.publish-line {
  margin-top: 30rpx;
  padding-top: 18rpx;
  border-top: 1rpx solid #E5EAF3;
  font-size: 22rpx;
  color: #8A9AA8;
}
.competition-track {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20rpx 0;
  border-bottom: 1rpx solid #EEF2F8;
}
.competition-track-main {
  flex: 1;
  padding-right: 16rpx;
}
.competition-track-name {
  display: block;
  font-size: 28rpx;
  font-weight: 600;
  color: #1F2D3D;
  margin-bottom: 6rpx;
}
.competition-track-meta {
  font-size: 23rpx;
  color: #7A8699;
  line-height: 1.6;
}
.competition-track-btn {
  background: #165DFF;
  color: #FFFFFF;
  border-radius: 12rpx;
  font-size: 24rpx;
  padding: 14rpx 24rpx;
  white-space: nowrap;
}
.competition-actions {
  display: flex;
  gap: 16rpx;
  margin-top: 18rpx;
}
.competition-actions .competition-track-btn {
  background: #EEF2FF;
  color: #3730A3;
}
.competition-empty {
  padding: 18rpx 0;
  font-size: 24rpx;
  color: #7A8699;
}
.schedule-track {
  margin-top: 22rpx;
}
.schedule-track-name {
  display: block;
  font-size: 26rpx;
  font-weight: 600;
  color: #102F6E;
  margin-bottom: 8rpx;
}
.schedule-stage {
  margin-top: 14rpx;
  padding-left: 12rpx;
  border-left: 4rpx solid #DCE5F5;
}
.schedule-stage-name {
  display: block;
  font-size: 24rpx;
  color: #165DFF;
  margin-bottom: 6rpx;
}
.schedule-match {
  padding: 10rpx 0;
  font-size: 25rpx;
  color: #334155;
}
.schedule-time {
  display: block;
  font-size: 22rpx;
  color: #7A8699;
  margin-top: 4rpx;
}
</style>
