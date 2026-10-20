<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">{{ team ? team.name : '我的队伍' }}</text>
      <view class="nav-side"></view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :empty="!loading && !errorMessage && !team && teams.length === 0 && !competition"
      empty-icon="🤖"
      empty-text="暂无可参与的赛项"
      empty-hint="赛事赛项由主办方发布后才会出现在这里。"
      @retry="load"
    >
      <view class="page-body">
        <view v-if="competition && !team" class="card">
          <text class="card-title">{{ competition.edition.name }}</text>
          <text class="muted">{{ competition.tracks.length }} 个赛项</text>
          <view v-for="track in competition.tracks" :key="track.id" class="track-row">
            <view class="track-main">
              <text class="track-name">{{ track.name }}</text>
              <text class="muted">
                {{ track.min_team_size || '?' }}–{{ track.max_team_size || '?' }} 人
                · {{ track.allow_multi_team ? '允许一人多队' : '一人一队' }}
              </text>
              <text v-if="track.registration.closes_at" class="muted">
                报名截止 {{ formatTime(track.registration.closes_at) }}
              </text>
            </view>
            <button class="mini-btn" @click="selectCreateTrack(track)">建队</button>
          </view>
        </view>

        <view v-if="!team" class="card">
          <text class="card-title">加入队伍</text>
          <input v-model="joinCode" class="field" placeholder="粘贴队长发给你的邀请码" />
          <button class="primary-btn" :disabled="busy" @click="joinTeam">加入</button>
        </view>

        <view v-if="!team && teams.length" class="card">
          <text class="card-title">我的队伍</text>
          <view v-for="item in teams" :key="item.id" class="team-row" @click="openTeam(item.id)">
            <view>
              <text class="team-name">{{ item.name }}</text>
              <text class="muted">{{ item.track?.name }} · {{ item.my_role === 'captain' ? '队长' : '队员' }}</text>
            </view>
            <text class="arrow">›</text>
          </view>
        </view>

        <view v-if="!team" class="card">
          <text class="card-title">新建队伍</text>
          <picker v-if="competition" :range="competition.tracks" range-key="name" @change="onTrackPicked">
            <view class="field">{{ createForm.track_id ? trackName(createForm.track_id) : '选择赛项' }}</view>
          </picker>
          <input v-model="createForm.name" class="field" placeholder="队伍名称（2–120 字）" />
          <button class="primary-btn" :disabled="busy || !createForm.track_id" @click="createTeam">创建队伍</button>
        </view>

        <view v-if="team" class="card">
          <view class="team-head">
            <view>
              <text class="card-title">{{ team.name }}</text>
              <text class="muted">{{ team.track?.name }} · {{ team.is_captain ? '我是队长' : '我是队员' }}</text>
            </view>
            <text class="status-pill">{{ team.status === 'active' ? '有效' : '已解散' }}</text>
          </view>

          <view class="member-list">
            <view v-for="member in team.members" :key="member.customer_id + '-' + member.role" class="member-row">
              <text>{{ member.display_name || ('用户 #' + member.customer_id) }}</text>
              <text class="muted">{{ member.role === 'captain' ? '队长' : '队员' }} · {{ member.status === 'active' ? '已加入' : member.status }}</text>
            </view>
          </view>

          <view v-if="team.is_captain" class="action-block">
            <text class="section-label">邀请队员</text>
            <text class="muted">邀请码一次性、7 天有效，只显示一次。</text>
            <button class="mini-btn" :disabled="busy" @click="issueInvite">生成邀请码</button>
            <view v-if="inviteToken" class="token-box">
              <text selectable class="token">{{ inviteToken }}</text>
              <button class="mini-btn ghost" @click="copyToken">复制</button>
            </view>
          </view>

          <view v-if="team.entry" class="action-block">
            <text class="section-label">参赛条目</text>
            <text class="muted">
              {{ team.entry.entry_no }} · {{ entryStatusLabel(team.entry.status) }}
              · {{ team.entry.member_count }} 人
            </text>
            <text v-if="team.entry.frozen_at" class="muted">成员快照已冻结，换人需运营另行处理。</text>
            <button class="primary-btn" @click="openSubmission">作品提交</button>
          </view>

          <view v-else class="action-block">
            <text class="section-label">队伍报名</text>
            <text class="muted">
              报名归属队伍；提交后成员与机器人声明会冻结为当届快照。
            </text>
            <input v-model="registerForm.name" class="field" placeholder="联系人姓名" />
            <input v-model="registerForm.phone" class="field" placeholder="联系人手机号" />
            <input v-model="registerForm.city" class="field" placeholder="城市（可选）" />
            <input v-model="registerForm.robot_model" class="field" placeholder="机器人型号（规则要求时必填）" />
            <input v-model="registerForm.robot_asset" class="field" placeholder="机器人编号/资产号（规则要求时必填）" />
            <label class="consent">
              <checkbox :checked="registerForm.consent" @click="registerForm.consent = !registerForm.consent" />
              <text>我已阅读并同意赛事规则与隐私条款（版本 {{ registrationVersion }}）</text>
            </label>
            <button class="primary-btn" :disabled="busy || !registerForm.consent" @click="submitRegistration">
              提交队伍报名
            </button>
          </view>

          <view class="footer-actions">
            <button v-if="team.is_captain && team.members.length > 1" class="mini-btn ghost" @click="transferCaptain">
              移交队长
            </button>
            <button v-if="!team.is_captain" class="mini-btn ghost" @click="leaveTeam">退出队伍</button>
          </view>
        </view>

        <view v-if="competition && competition.schedule && competition.schedule.length" class="card">
          <text class="card-title">赛程</text>
          <view v-for="track in competition.schedule" :key="track.track_key">
            <text class="section-label">{{ track.name }}</text>
            <view v-for="stage in track.stages" :key="stage.stage_key" class="stage">
              <text class="stage-name">{{ stage.name }}</text>
              <view v-for="match in stage.matches" :key="match.match_key" class="match">
                <text>{{ match.home ? match.home.team_name : '待定' }} vs {{ match.away ? match.away.team_name : '待定' }}</text>
                <text class="muted">{{ formatTime(match.starts_at) }} · {{ match.venue || '场地待定' }}</text>
              </view>
            </view>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { competitionApi, competitionTeamApi } from '@/utils/api'

function requestKey(prefix) {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`
}

export default {
  name: 'CompetitionTeam',
  components: { StateView },
  data() {
    return {
      eventId: 0,
      trackId: 0,
      teamId: 0,
      competition: null,
      teams: [],
      team: null,
      loading: false,
      busy: false,
      errorMessage: '',
      joinCode: '',
      inviteToken: '',
      createForm: { track_id: 0, name: '' },
      registerForm: {
        name: '', phone: '', city: '', robot_model: '', robot_asset: '', consent: false, request_key: '',
      },
    }
  },
  computed: {
    registrationVersion() {
      const track = this.team && this.team.track
      return track && track.registration && track.registration.privacy_version
        ? track.registration.privacy_version
        : '未配置'
    },
  },
  onLoad(query) {
    this.eventId = Number(query && query.eventId) || 0
    this.trackId = Number(query && query.trackId) || 0
    this.teamId = Number(query && query.teamId) || 0
    this.createForm.track_id = this.trackId
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
    trackName(id) {
      if (!this.competition) return ''
      const track = this.competition.tracks.find((item) => Number(item.id) === Number(id))
      return track ? track.name : ''
    },
    entryStatusLabel(status) {
      const labels = {
        draft: '草稿', submitted: '待审核', confirmed: '已确认参赛',
        withdrawn: '已撤回', disqualified: '已取消资格',
      }
      return labels[status] || status
    },
    async load() {
      this.loading = true
      this.errorMessage = ''

      try {
        if (this.eventId) {
          const competition = await competitionApi.getByEvent(this.eventId)
          this.competition = competition && competition.data ? competition.data : null
        }

        const mine = await competitionTeamApi.mine()
        this.teams = mine && Array.isArray(mine.data) ? mine.data : []

        if (this.teamId) {
          const detail = await competitionTeamApi.get(this.teamId)
          this.team = detail && detail.data ? detail.data : null
        }
      } catch (error) {
        this.errorMessage = (error && error.message) || '赛务数据加载失败'
      } finally {
        this.loading = false
      }
    },
    selectCreateTrack(track) {
      this.createForm.track_id = Number(track.id)
    },
    onTrackPicked(event) {
      const index = Number(event.detail.value)
      const track = this.competition && this.competition.tracks[index]
      if (track) this.createForm.track_id = Number(track.id)
    },
    async createTeam() {
      if (!this.createForm.name.trim() || !this.createForm.track_id) return
      this.busy = true
      try {
        const response = await competitionTeamApi.create({
          track_id: this.createForm.track_id,
          name: this.createForm.name.trim(),
        })
        if (response && response.data) {
          this.team = response.data
          this.teamId = Number(response.data.id)
          this.createForm.name = ''
          uni.showToast({ title: '队伍已创建', icon: 'success' })
          this.load()
        }
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '创建失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    openTeam(id) {
      this.teamId = Number(id)
      this.load()
    },
    async joinTeam() {
      if (!this.joinCode.trim()) return
      this.busy = true
      try {
        const response = await competitionTeamApi.join({ token: this.joinCode.trim() })
        this.joinCode = ''
        uni.showToast({ title: '已加入队伍', icon: 'success' })
        const teamId = response && response.data && response.data.team_id
        if (teamId) {
          this.teamId = Number(teamId)
        }
        this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '加入失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    async issueInvite() {
      if (!this.team) return
      this.busy = true
      try {
        const response = await competitionTeamApi.invite(this.team.id, {
          expected_row_version: Number(this.team.row_version),
        })
        this.inviteToken = response && response.data ? response.data.token : ''
        if (!this.inviteToken) throw new Error('未返回邀请码')
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '生成失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    copyToken() {
      uni.setClipboardData({
        data: this.inviteToken,
        success: () => uni.showToast({ title: '已复制', icon: 'success' }),
        fail: () => uni.showToast({ title: '复制失败', icon: 'none' }),
      })
    },
    async transferCaptain() {
      if (!this.team) return
      const members = (this.team.members || []).filter(
        (member) => member.role !== 'captain' && member.status === 'active',
      )
      if (!members.length) {
        uni.showToast({ title: '没有可移交的有效队员', icon: 'none' })
        return
      }
      uni.showActionSheet({
        itemList: members.map((member) => member.display_name || `用户 #${member.customer_id}`),
        success: async (result) => {
          const target = members[result.tapIndex]
          if (!target) return
          this.busy = true
          try {
            await competitionTeamApi.transfer(this.team.id, {
              new_captain_customer_id: target.customer_id,
              expected_row_version: Number(this.team.row_version),
            })
            uni.showToast({ title: '已移交队长', icon: 'success' })
            this.load()
          } catch (error) {
            uni.showToast({ title: (error && error.message) || '移交失败', icon: 'none' })
          } finally {
            this.busy = false
          }
        },
      })
    },
    async leaveTeam() {
      if (!this.team) return
      this.busy = true
      try {
        await competitionTeamApi.leave(this.team.id, {
          expected_row_version: Number(this.team.row_version),
        })
        uni.showToast({ title: '已退出队伍', icon: 'success' })
        this.team = null
        this.teamId = 0
        this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '退出失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    async submitRegistration() {
      if (!this.team) return
      const track = this.team.track
      const registration = track && track.registration

      if (!registration || !registration.form_version || !registration.privacy_version) {
        uni.showToast({ title: '报名表版本未配置，暂不能报名', icon: 'none' })
        return
      }

      if (!this.registerForm.name.trim() || !this.registerForm.phone.trim()) {
        uni.showToast({ title: '请填写联系人与手机号', icon: 'none' })
        return
      }

      this.busy = true
      try {
        const robot = {}
        if (this.registerForm.robot_model.trim()) robot.model = this.registerForm.robot_model.trim()
        if (this.registerForm.robot_asset.trim()) robot.asset_no = this.registerForm.robot_asset.trim()

        const response = await competitionTeamApi.register(this.team.id, {
          request_key: this.registerForm.request_key || requestKey('team-reg'),
          form_version: Number(registration.form_version),
          privacy_version: String(registration.privacy_version),
          consent: true,
          name: this.registerForm.name.trim(),
          phone: this.registerForm.phone.trim(),
          city: this.registerForm.city.trim() || null,
          robot,
        })

        this.registerForm.request_key = ''
        uni.showToast({ title: '队伍报名已提交', icon: 'success' })
        if (response && response.data && response.data.entry) {
          this.team.entry = response.data.entry
        }
        this.load()
      } catch (error) {
        // 超时/网络失败时保留 request_key，重试不会重复创建。
        if (!this.registerForm.request_key) {
          this.registerForm.request_key = requestKey('team-reg')
        }
        uni.showToast({ title: (error && error.message) || '报名失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    openSubmission() {
      if (!this.team) return
      uni.navigateTo({
        url: `/pages/ecosystem/competition-submission?teamId=${this.team.id}`,
      })
    },
  },
}
</script>

<style scoped>
.eco-page { min-height: 100vh; background: #F5F7FB; padding-bottom: 40rpx; box-sizing: border-box; }
.eco-nav {
  display: flex; align-items: center; justify-content: space-between;
  padding: 88rpx 24rpx 22rpx;
  background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%);
  color: #FFFFFF;
}
.nav-back, .nav-side { width: 96rpx; height: 60rpx; line-height: 52rpx; font-size: 48rpx; }
.nav-title { flex: 1; text-align: center; font-size: 34rpx; font-weight: 600; }
.page-body { padding: 24rpx; }
.card { background: #FFFFFF; border-radius: 20rpx; padding: 28rpx; margin-bottom: 24rpx; box-shadow: 0 8rpx 24rpx rgba(16, 47, 110, 0.06); }
.card-title { display: block; font-size: 32rpx; font-weight: 600; color: #102F6E; margin-bottom: 12rpx; }
.muted { display: block; font-size: 24rpx; color: #7A8699; line-height: 1.6; }
.track-row, .team-row, .member-row { display: flex; align-items: center; justify-content: space-between; padding: 20rpx 0; border-bottom: 1rpx solid #EEF2F8; }
.track-row:last-child, .member-row:last-child, .team-row:last-child { border-bottom: none; }
.track-main { flex: 1; padding-right: 16rpx; }
.track-name, .team-name { display: block; font-size: 28rpx; color: #1F2D3D; margin-bottom: 6rpx; }
.mini-btn { background: #165DFF; color: #FFFFFF; border-radius: 12rpx; font-size: 24rpx; padding: 0 24rpx; line-height: 58rpx; }
.mini-btn.ghost { background: #EEF3FF; color: #165DFF; }
.primary-btn { margin-top: 20rpx; background: #165DFF; color: #FFFFFF; border-radius: 14rpx; font-size: 28rpx; line-height: 84rpx; }
.field { margin-top: 16rpx; background: #F5F7FB; border-radius: 12rpx; padding: 22rpx; font-size: 28rpx; color: #1F2D3D; }
.team-head { display: flex; align-items: flex-start; justify-content: space-between; }
.status-pill { background: #EEF3FF; color: #165DFF; padding: 6rpx 18rpx; border-radius: 999rpx; font-size: 22rpx; }
.member-list { margin-top: 12rpx; }
.action-block { margin-top: 24rpx; padding-top: 20rpx; border-top: 1rpx solid #EEF2F8; }
.section-label { display: block; font-size: 26rpx; font-weight: 600; color: #102F6E; margin-bottom: 10rpx; }
.token-box { display: flex; align-items: center; gap: 16rpx; margin-top: 16rpx; background: #F5F7FB; border-radius: 12rpx; padding: 18rpx; }
.token { flex: 1; font-size: 22rpx; color: #1F2D3D; word-break: break-all; }
.consent { display: flex; align-items: flex-start; gap: 12rpx; margin-top: 20rpx; font-size: 24rpx; color: #4E5B6E; line-height: 1.6; }
.footer-actions { display: flex; gap: 16rpx; margin-top: 24rpx; }
.stage { margin-top: 18rpx; }
.stage-name { display: block; font-size: 26rpx; color: #102F6E; font-weight: 600; }
.match { padding: 12rpx 0; border-bottom: 1rpx solid #EEF2F8; }
.arrow { color: #B7C0CE; font-size: 34rpx; }
</style>
