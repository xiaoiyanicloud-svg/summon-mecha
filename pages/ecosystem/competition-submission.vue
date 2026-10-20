<template>
  <view class="eco-page">
    <view class="eco-nav">
      <view class="nav-back" role="button" tabindex="0" aria-label="返回" @click="goBack">‹</view>
      <text class="nav-title">作品提交</text>
      <view class="nav-side"></view>
    </view>

    <state-view
      :loading="loading"
      :error="errorMessage"
      :empty="!loading && !errorMessage && !submission"
      empty-icon="📄"
      empty-text="参赛条目不存在"
      empty-hint="请先在队伍页完成报名并等待确认。"
      @retry="load"
    >
      <view class="page-body">
        <view class="card">
          <view class="head-row">
            <text class="card-title">当前状态</text>
            <text class="status-pill">{{ statusLabel }}</text>
          </view>
          <text v-if="submission.deadline_at" class="muted">截止 {{ formatTime(submission.deadline_at) }}</text>
          <text v-else class="muted warn">赛项未配置截止时间，不能提交。</text>
        </view>

        <view class="card">
          <text class="card-title">{{ editingVersion ? '编辑草稿 v' + editingVersion.version_no : '新建作品版本' }}</text>
          <input v-model="form.title" class="field" placeholder="作品标题（必填，160 字内）" />
          <textarea v-model="form.summary" class="field textarea" placeholder="一句话摘要（可选）" />
          <textarea v-model="form.description" class="field textarea tall" placeholder="作品描述（纯文本）" />
          <input v-model="form.repo_url" class="field" placeholder="代码仓库链接（https://，可选）" />
          <input v-model="form.video_url" class="field" placeholder="演示视频链接（https://，可选）" />
          <button class="primary-btn" :disabled="busy" @click="saveDraft">保存草稿</button>
        </view>

        <view v-if="editingVersion" class="card">
          <text class="card-title">附件</text>
          <text class="muted">私有存储，只有本队有效成员可以下载；提交后不可修改。</text>
          <view v-for="item in editingVersion.attachments" :key="item.id" class="attachment-row">
            <view class="attachment-main">
              <text class="attachment-name">{{ item.original_name }}</text>
              <text class="muted">{{ formatSize(item.byte_size) }} · {{ item.mime }}</text>
            </view>
            <button class="mini-btn ghost" @click="download(item)">下载</button>
          </view>
          <button class="mini-btn" :disabled="busy" @click="chooseAndUpload">上传附件</button>
        </view>

        <view class="card">
          <text class="card-title">提交当前草稿</text>
          <text class="muted">提交后版本冻结；需要修改请保存新的补正版本。</text>
          <button class="primary-btn submit" :disabled="busy || !hasDraft" @click="submitVersion">提交作品</button>
        </view>

        <view class="card">
          <text class="card-title">版本记录</text>
          <view v-for="version in versions" :key="version.id" class="version-row">
            <view>
              <text class="version-title">v{{ version.version_no }} · {{ version.title }}</text>
              <text class="muted">
                {{ version.status === 'submitted' ? '已提交' : '草稿' }}
                <text v-if="version.submitted_at"> · {{ formatTime(version.submitted_at) }}</text>
              </text>
            </view>
            <button v-if="version.status === 'draft'" class="mini-btn ghost" @click="editVersion(version)">编辑</button>
          </view>
        </view>
      </view>
    </state-view>
  </view>
</template>

<script>
import StateView from '@/components/StateView.vue'
import { competitionSubmissionApi } from '@/utils/api'
import { BASE_URL, TOKEN_KEY } from '@/utils/config'

export default {
  name: 'CompetitionSubmission',
  components: { StateView },
  data() {
    return {
      teamId: 0,
      submission: null,
      versions: [],
      form: { title: '', summary: '', description: '', repo_url: '', video_url: '' },
      loading: false,
      busy: false,
      errorMessage: '',
    }
  },
  computed: {
    statusLabel() {
      const labels = {
        draft: '草稿', submitted: '已提交', correction_required: '待补正',
        locked: '已锁定', withdrawn: '已撤回',
      }
      return labels[this.submission && this.submission.status] || '未知'
    },
    editingVersion() {
      return this.versions.find((version) => version.status === 'draft') || null
    },
    hasDraft() {
      return !!this.editingVersion
    },
  },
  onLoad(query) {
    this.teamId = Number(query && query.teamId) || 0
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
    formatSize(bytes) {
      const value = Number(bytes) || 0
      if (value < 1024) return `${value} B`
      if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
      return `${(value / 1024 / 1024).toFixed(1)} MB`
    },
    async load() {
      if (!this.teamId) {
        this.errorMessage = '缺少队伍参数'
        return
      }

      this.loading = true
      this.errorMessage = ''
      try {
        const response = await competitionSubmissionApi.workspace(this.teamId)
        this.submission = response && response.data ? response.data : null
        this.versions = this.submission && Array.isArray(this.submission.versions)
          ? this.submission.versions
          : []
        const draft = this.editingVersion
        if (draft) {
          this.form = {
            title: draft.title || '',
            summary: draft.summary || '',
            description: draft.description || '',
            repo_url: draft.repo_url || '',
            video_url: draft.video_url || '',
          }
        }
      } catch (error) {
        this.errorMessage = (error && error.message) || '作品数据加载失败'
      } finally {
        this.loading = false
      }
    },
    editVersion(version) {
      this.form = {
        title: version.title || '',
        summary: version.summary || '',
        description: version.description || '',
        repo_url: version.repo_url || '',
        video_url: version.video_url || '',
      }
      uni.pageScrollTo({ scrollTop: 0, duration: 200 })
    },
    async saveDraft() {
      if (!this.form.title.trim()) {
        uni.showToast({ title: '请填写作品标题', icon: 'none' })
        return
      }

      this.busy = true
      try {
        await competitionSubmissionApi.saveDraft(this.teamId, {
          title: this.form.title.trim(),
          summary: this.form.summary.trim() || null,
          description: this.form.description.trim() || null,
          repo_url: this.form.repo_url.trim() || null,
          video_url: this.form.video_url.trim() || null,
          expected_row_version: this.submission ? Number(this.submission.row_version) : null,
        })
        uni.showToast({ title: '草稿已保存', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '保存失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    async submitVersion() {
      if (!this.hasDraft) return
      this.busy = true
      try {
        await competitionSubmissionApi.submit(this.teamId, {
          expected_row_version: this.submission ? Number(this.submission.row_version) : null,
        })
        uni.showToast({ title: '作品已提交', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '提交失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    chooseAndUpload() {
      if (!this.editingVersion) {
        uni.showToast({ title: '请先保存草稿', icon: 'none' })
        return
      }

      const onPicked = (file) => {
        if (file) this.uploadAttachment(file.path)
      }

      // #ifdef H5
      uni.chooseFile({
        count: 1,
        type: 'all',
        success: (result) => onPicked(result.tempFiles && result.tempFiles[0]),
        fail: () => uni.showToast({ title: '未选择文件', icon: 'none' }),
      })
      // #endif

      // #ifndef H5
      uni.chooseMessageFile({
        count: 1,
        type: 'file',
        success: (result) => onPicked(result.tempFiles && result.tempFiles[0]),
        fail: () => uni.showToast({ title: '未选择文件', icon: 'none' }),
      })
      // #endif
    },
    async uploadAttachment(filePath) {
      this.busy = true
      try {
        await competitionSubmissionApi.uploadAttachment(
          this.teamId,
          filePath,
          Number(this.editingVersion.id),
        )
        uni.showToast({ title: '附件已上传', icon: 'success' })
        await this.load()
      } catch (error) {
        uni.showToast({ title: (error && error.message) || '上传失败', icon: 'none' })
      } finally {
        this.busy = false
      }
    },
    download(item) {
      const token = uni.getStorageSync(TOKEN_KEY) || ''
      const url = BASE_URL + item.download_url

      uni.downloadFile({
        url,
        header: token ? { Authorization: `Bearer ${token}` } : {},
        success: (result) => {
          if (result.statusCode !== 200) {
            uni.showToast({ title: '下载失败或无权访问', icon: 'none' })
            return
          }
          // #ifdef H5
          window.open(result.tempFilePath, '_blank')
          // #endif
          // #ifndef H5
          uni.openDocument({
            filePath: result.tempFilePath,
            showMenu: true,
            fail: () => uni.showToast({ title: '无法打开该文件', icon: 'none' }),
          })
          // #endif
        },
        fail: () => uni.showToast({ title: '下载失败', icon: 'none' }),
      })
    },
  },
}
</script>

<style scoped>
.eco-page { min-height: 100vh; background: #F5F7FB; padding-bottom: 40rpx; box-sizing: border-box; }
.eco-nav { display: flex; align-items: center; justify-content: space-between; padding: 88rpx 24rpx 22rpx; background: linear-gradient(135deg, #102F6E 0%, #165DFF 100%); color: #FFFFFF; }
.nav-back, .nav-side { width: 96rpx; height: 60rpx; line-height: 52rpx; font-size: 48rpx; }
.nav-title { flex: 1; text-align: center; font-size: 34rpx; font-weight: 600; }
.page-body { padding: 24rpx; }
.card { background: #FFFFFF; border-radius: 20rpx; padding: 28rpx; margin-bottom: 24rpx; box-shadow: 0 8rpx 24rpx rgba(16, 47, 110, 0.06); }
.card-title { display: block; font-size: 32rpx; font-weight: 600; color: #102F6E; margin-bottom: 12rpx; }
.muted { display: block; font-size: 24rpx; color: #7A8699; line-height: 1.6; }
.muted.warn { color: #C2410C; }
.head-row { display: flex; align-items: center; justify-content: space-between; }
.status-pill { background: #EEF3FF; color: #165DFF; padding: 6rpx 18rpx; border-radius: 999rpx; font-size: 22rpx; }
.field { margin-top: 16rpx; background: #F5F7FB; border-radius: 12rpx; padding: 22rpx; font-size: 28rpx; color: #1F2D3D; }
.textarea { min-height: 120rpx; }
.textarea.tall { min-height: 260rpx; }
.primary-btn { margin-top: 20rpx; background: #165DFF; color: #FFFFFF; border-radius: 14rpx; font-size: 28rpx; line-height: 84rpx; }
.primary-btn.submit { background: #0F9D58; }
.mini-btn { margin-top: 16rpx; background: #165DFF; color: #FFFFFF; border-radius: 12rpx; font-size: 24rpx; padding: 0 24rpx; line-height: 58rpx; }
.mini-btn.ghost { background: #EEF3FF; color: #165DFF; }
.attachment-row, .version-row { display: flex; align-items: center; justify-content: space-between; padding: 18rpx 0; border-bottom: 1rpx solid #EEF2F8; }
.attachment-row:last-child, .version-row:last-child { border-bottom: none; }
.attachment-main { flex: 1; padding-right: 16rpx; }
.attachment-name, .version-title { display: block; font-size: 26rpx; color: #1F2D3D; }
</style>
