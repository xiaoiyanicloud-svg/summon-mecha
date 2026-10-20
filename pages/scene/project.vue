<template>
  <view class="scene-page"><view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">服务项目</text><text></text></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-if="pendingCommands.length" class="body"><view class="card"><text class="section">操作回执待确认</text><text class="muted">原验收或申请内容已保留。重试使用原提交标识，不会按当前页面重新编写结果。</text><button v-for="pending in pendingCommands" :key="pending.slot" class="secondary divider" :disabled="busy" @tap="retryPending(pending)">按原内容重试{{ pendingLabel(pending.slot) }}</button></view></view>
    <view v-if="project && !loading && !error" class="body"><view class="hero"><text class="heading">{{ status(project.state) }}</text><text>{{ project.project_no }}</text><text>商务 {{ status(project.commercial_state) }} · 款项 {{ status(project.payment_state) }}</text></view>
      <view class="card"><text class="section">交付进度</text><view v-for="task in project.tasks" :key="task.id" class="row"><text>{{ task.name }}</text><text>{{ task.state === 'done' ? '已记录完成' : '待处理' }} {{ task.occurred_at || '' }}</text></view></view>
      <view v-if="['awaiting_acceptance','rectification'].includes(project.state)" class="card"><text class="section">按冻结标准逐项验收</text><view v-for="item in project.acceptance_template" :key="item.key" class="divider"><text>{{ item.label }}{{ item.required ? '（必需）' : '' }}</text><picker :range="['请选择','通过','未通过']" :value="acceptance[item.key] || 0" @change="acceptance[item.key] = Number($event.detail.value)"><view class="picker">{{ ['请选择','通过','未通过'][acceptance[item.key] || 0] }} ›</view></picker></view><button class="primary divider" :disabled="busy" @tap="submitAcceptance">提交本次验收</button><text class="muted">未回复不会默认通过。未通过项进入整改，原始验收记录保留。</text></view>
      <view class="card"><text class="section">验收记录</text><view v-for="item in project.acceptances" :key="item.id" class="divider"><text>{{ item.item_key }} / 第 {{ item.attempt }} 次：{{ item.result === 'pass' ? '通过' : '未通过' }}</text><text class="muted">{{ item.created_at }} · {{ item.method === 'customer_online' ? '客户在线确认' : item.method }}</text></view><text v-if="!project.acceptances.length" class="muted">暂无验收记录</text></view>
      <view class="card"><text class="section">变更、取消与服务售后</text><picker :range="actionLabels" :value="actionIndex" @change="actionIndex = Number($event.detail.value)"><view class="picker">{{ actionLabels[actionIndex] }} ›</view></picker><textarea v-model="reason" maxlength="10000" placeholder="请说明需要调整的内容或遇到的问题" /><button class="secondary divider" :disabled="busy" @tap="requestAction">提交申请</button><text class="muted">申请提交后需人工评估；不会立即改期、释放资源或标记退款。技能售后仍按既有授权与售后规则处理。</text></view>
      <view class="card"><text class="section">申请进展</text><view v-for="change in project.changes" :key="'c'+change.id" class="divider"><text>{{ change.kind === 'cancel' ? '取消' : '变更' }} · {{ change.state }}</text><text class="muted">{{ change.reason }}</text></view><view v-for="ticket in project.tickets" :key="'t'+ticket.id" class="divider"><text>服务售后 · {{ ticket.state }}</text><text class="muted">{{ ticket.description }}</text><text>{{ ticket.resolution || '等待服务人员处理' }}</text></view></view>
    </view>
  </view>
</template>
<script>
import StateView from '@/components/StateView.vue'
import { sceneWorkflowApi } from '@/utils/api'
import { sceneAccountScope, sceneLocalState, submitSceneCommand, scenePendingCommands, sceneStatusLabel } from '@/utils/scene-request'
export default {
  components: { StateView },
  data() { return { id: 0, project: null, loading: false, error: '', busy: false, scope: '', generation: 0, acceptance: {}, pendingCommands: [], actionLabels: ['变更服务内容或改期','申请取消','服务售后'], actionIndex: 0, reason: '' } },
  onLoad(options) { this.id = Number(options.id) },
  onShow() { if (this.scope !== sceneAccountScope()) { this.project = null; this.acceptance = {}; this.reason = '' }; this.scope = sceneAccountScope(); this.load() },
  onUnload() { this.generation++ },
  methods: {
    status(value) { return value === 'accepted' ? '验收已通过' : sceneStatusLabel(value) }, back() { uni.navigateBack() },
    async load() {
      const generation = ++this.generation, scope = sceneAccountScope()
      this.loading = true; this.error = ''
      this.pendingCommands = scenePendingCommands('project:' + this.id + ':')
      try {
        if (!Number.isSafeInteger(this.id) || this.id < 1) throw new Error('项目编号无效')
        const response = await sceneWorkflowApi.project(this.id, sceneLocalState().access)
        if (generation !== this.generation || scope !== sceneAccountScope()) return
        if (!response.data || !Array.isArray(response.data.tasks)) throw new Error('项目详情暂不可用')
        this.project = response.data
      } catch (e) { if (generation === this.generation && scope === sceneAccountScope()) { this.project = null; this.error = e.message || '加载失败' } }
      finally { if (generation === this.generation && scope === sceneAccountScope()) this.loading = false }
    },
    pendingLabel(slot) { return ({ acceptance: '项目验收', change: '服务变更', cancel: '取消申请', after_sale: '服务售后' })[slot.split(':').pop()] || '项目操作' },
    retryPending(pending) { this.send(pending.slot.split(':').pop(), null, pending) },
    async send(action, data, pending = null) {
      if (this.busy) return
      if (!pending && this.pendingCommands.length) { uni.showToast({ title: '请先按原内容确认上一条操作回执', icon: 'none' }); return }
      this.busy = true; const scope = sceneAccountScope(), slot = 'project:' + this.id + ':' + action
      try {
        const payload = pending ? pending.payload : { ...data, row_version: this.project.row_version }
        await submitSceneCommand(slot, payload, (body, access, key) => sceneWorkflowApi.projectAction(this.id, action, body, access, key))
        if (scope !== sceneAccountScope()) return
        this.reason = ''; this.acceptance = {}; await this.load()
      } catch (e) { if (scope === sceneAccountScope()) uni.showToast({ title: e.message || '提交失败，请重试', icon: 'none' }) }
      finally { if (scope === sceneAccountScope()) this.pendingCommands = scenePendingCommands('project:' + this.id + ':'); this.busy = false }
    },
    submitAcceptance() {
      const items = this.project.acceptance_template.filter(item => this.acceptance[item.key]).map(item => ({ key: item.key, result: this.acceptance[item.key] === 1 ? 'pass' : 'fail', evidence_ids: [] }))
      if (!items.length) { uni.showToast({ title: '请逐项选择验收结果', icon: 'none' }); return }
      this.send('acceptance', { items })
    },
    requestAction() { if (!this.reason.trim()) { uni.showToast({ title: '请填写申请原因', icon: 'none' }); return }; this.send(['change','cancel','after_sale'][this.actionIndex], { reason: this.reason }) }
  }
}
</script>
<style scoped>@import './scene.css';.picker{padding:20rpx;background:#f5f7fb;border-radius:12rpx}.card textarea{height:240rpx}</style>
