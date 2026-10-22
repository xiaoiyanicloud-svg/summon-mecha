<template>
  <view class="scene-page"><view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">服务项目</text><text></text></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-if="pendingCommands.length" class="body"><view class="card"><text class="section">操作回执待确认</text><text class="muted">原验收或申请内容已保留。重试使用原提交标识，不会按当前页面重新编写结果。</text><button v-for="pending in pendingCommands" :key="pending.slot" class="secondary divider" :disabled="busy" @tap="retryPending(pending)">按原内容重试{{ pendingLabel(pending.slot) }}</button></view></view>
    <view v-if="project && !loading && !error" class="body"><view class="hero"><text class="heading">{{ status(project.state) }}</text><text>{{ project.project_no }}</text><text>商务 {{ status(project.commercial_state) }} · 款项 {{ status(project.payment_state) }}</text></view>
      <view v-if="project.schedule || (project.resources || []).length" class="card"><text class="section">确认时的服务安排</text><text v-if="project.schedule" class="muted">{{ project.schedule.starts_at }} 至 {{ project.schedule.ends_at }} · {{ project.schedule.timezone }}</text><view v-for="resource in (project.resources || [])" :key="resource.resource_id" class="divider"><text>{{ ({robot:'机器人',operator:'服务人员',accessory:'配件'})[resource.kind] || '履约资源' }} #{{ resource.resource_id }}</text><text class="muted">含行程及维护占用 {{ resource.occupies_from }} 至 {{ resource.occupies_until }} UTC</text></view><text v-if="project.resource_attention" class="notice">资源安排出现待复核事项，请联系服务人员协商后续处理；原确认清单保留可查。</text><text v-for="order in (project.orders || [])" :key="order.id" class="muted">{{ order.kind === 'skill' ? '独立技能订单' : '服务订单' }} #{{ order.id }}</text></view>
      <view class="card"><text class="section">交付进度</text><view v-for="task in project.tasks" :key="task.id" class="row"><text>{{ task.name }}</text><text>{{ task.state === 'done' ? '已记录完成' : '待处理' }} {{ task.occurred_at || '' }}</text></view></view>
      <button v-if="(project.orders || []).some(order => order.kind === 'skill')" class="secondary" @tap="openSkills">查看我的技能授权与设备绑定</button>
      <view v-if="['awaiting_acceptance','rectification'].includes(project.state)" class="card"><text class="section">按冻结标准逐项验收</text><view v-for="item in project.acceptance_template" :key="item.key" class="divider"><text>{{ item.label }}{{ item.required ? '（必需）' : '' }}</text><picker :range="['请选择','通过','未通过']" :value="acceptance[item.key] || 0" @change="acceptance[item.key] = Number($event.detail.value)"><view class="picker">{{ ['请选择','通过','未通过'][acceptance[item.key] || 0] }} ›</view></picker></view><button class="primary divider" :disabled="busy" @tap="submitAcceptance">提交本次验收</button><text class="muted">未回复不会默认通过。未通过项进入整改，原始验收记录保留。</text></view>
      <view class="card"><text class="section">验收记录</text><view v-for="item in project.acceptances" :key="item.id" class="divider"><text>{{ item.item_key }} / 第 {{ item.attempt }} 次：{{ item.result === 'pass' ? '通过' : '未通过' }}</text><text class="muted">{{ acceptanceMethod(item.method) }}</text><text class="muted">实际确认：{{ item.occurred_at ? item.occurred_at + ' UTC' : '历史实际时间未记录' }}</text><text class="muted">平台记录：{{ item.created_at }} UTC</text><text v-if="item.note" class="muted">{{ item.note }}</text></view><text v-if="!project.acceptances.length" class="muted">暂无验收记录</text>
        <view v-for="confirmation in (project.offline_confirmations || [])" :key="'offline'+confirmation.id" class="divider"><text>线下确认凭证 #{{ confirmation.id }} · {{ offlineStatus(confirmation.state) }}</text><text class="muted">客户确认：{{ confirmation.occurred_at }} UTC</text><text class="muted">平台代录：{{ confirmation.created_at }} UTC</text><text v-if="confirmation.state === 'pending_review'" class="muted">凭证待独立复核，尚未计入验收结果。您仍可在线按实际情况逐项确认。</text><text v-else-if="confirmation.reviewed_at" class="muted">复核记录：{{ confirmation.reviewed_at }} UTC</text></view>
      </view>
      <view class="card"><text class="section">变更、取消与服务售后</text><picker :range="actionLabels" :value="actionIndex" @change="actionIndex = Number($event.detail.value)"><view class="picker">{{ actionLabels[actionIndex] }} ›</view></picker><textarea v-model="reason" maxlength="10000" placeholder="请说明需要调整的内容或遇到的问题" /><button class="secondary divider" :disabled="busy" @tap="requestAction">提交申请</button><text class="muted">申请提交后需人工评估；不会立即改期、释放资源或标记退款。技能售后仍按既有授权与售后规则处理。</text></view>
      <view class="card"><text class="section">申请进展</text>
        <view v-for="change in project.changes" :key="'c'+change.id" class="divider">
          <text>{{ change.kind === 'cancel' ? '取消' : '变更' }} · {{ change.state }}</text>
          <text class="muted">{{ change.reason }}</text>
          <view v-if="change.state === 'pending_customer_acceptance'">
            <text class="notice">方案已核定，调价差额：{{ ((change.price_delta_minor || 0)/100).toFixed(2) }} 元。请确认：</text>
            <text v-if="changeEvaluation(change).summary" class="muted">{{ changeEvaluation(change).summary }}</text>
            <text v-if="changeEvaluation(change).schedule_delta" class="muted">新服务时段：{{ changeEvaluation(change).schedule_delta.starts_at }} 至 {{ changeEvaluation(change).schedule_delta.ends_at }} UTC · 服务地 {{ changeEvaluation(change).schedule_delta.timezone }}</text>
            <button class="primary divider" :disabled="busy" @tap="decideChange(change.id, 'accept')">接受变更并应用</button>
            <button class="secondary divider" :disabled="busy" @tap="decideChange(change.id, 'decline')">拒绝本次变更</button>
          </view>
        </view>
        <view v-for="ticket in project.tickets" :key="'t'+ticket.id" class="divider"><text>服务售后 · {{ ticket.state }}</text><text class="muted">{{ ticket.description }}</text><text>{{ ticket.resolution || '等待服务人员处理' }}</text></view>
      </view>
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
    openSkills() { uni.navigateTo({url:'/pages/mine/skills'}) },
    acceptanceMethod(value) { return ({ customer_online: '客户在线确认', customer_offline_recorded: '客户线下确认 · 平台代录并经独立复核' })[value] || '历史验收记录（来源待核实）' },
    offlineStatus(value) { return ({ pending_review: '待独立复核，未生效', approved: '已复核，逐项结果已记录', rejected: '已退回，未生效' })[value] || '状态待核实' },
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
    changeEvaluation(change) {
      if (change.evaluation && typeof change.evaluation === 'object') return change.evaluation
      try { return JSON.parse(change.evaluation || '{}') || {} } catch (_) { return {} }
    },
    pendingLabel(slot) { return slot.includes(':change-decision:') ? '变更确认' : ({ acceptance: '项目验收', change: '服务变更', cancel: '取消申请', after_sale: '服务售后' })[slot.split(':').pop()] || '项目操作' },
    retryPending(pending) {
      const prefix = 'project:' + this.id + ':', suffix = pending.slot.startsWith(prefix) ? pending.slot.slice(prefix.length) : ''
      if (['acceptance', 'change', 'cancel', 'after_sale'].includes(suffix)) return this.send(suffix, null, pending)
      const match = /^change-decision:([1-9][0-9]*)$/.exec(suffix)
      if (match && Number.isSafeInteger(Number(match[1]))) return this.decideChange(Number(match[1]), null, pending)
      uni.showToast({ title: '操作类型无法识别，请联系服务人员核实原回执', icon: 'none' })
    },
    async send(action, data, pending = null) {
      if (this.busy) return
      if (!pending && scenePendingCommands('project:' + this.id + ':').length) { uni.showToast({ title: '请先按原内容确认上一条操作回执', icon: 'none' }); return }
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
    async decideChange(changeId, decision, pending = null) {
      if (this.busy) return
      if (!Number.isSafeInteger(changeId) || changeId < 1) return
      if (!pending && scenePendingCommands('project:' + this.id + ':').length) { uni.showToast({ title: '请先按原内容确认上一条操作回执', icon: 'none' }); return }
      this.busy = true; const scope = sceneAccountScope(), slot = 'project:' + this.id + ':change-decision:' + changeId
      try {
        if (pending && pending.slot !== slot) throw new Error('原操作与变更编号不一致，请核实回执')
        const payload = pending ? pending.payload : { decision, row_version: this.project.row_version }
        await submitSceneCommand(slot, payload, (body, access, key) => sceneWorkflowApi.projectChangeDecision(this.id, changeId, body, access, key))
        if (scope !== sceneAccountScope()) return
        await this.load()
      } catch (e) {
        if (scope === sceneAccountScope()) {
          uni.showToast({ title: e.message || '操作失败，请重试', icon: 'none' })
          if (e.statusCode === 409) await this.load()
        }
      } finally {
        if (scope === sceneAccountScope()) this.pendingCommands = scenePendingCommands('project:' + this.id + ':')
        this.busy = false
      }
    },
    requestAction() { if (!this.reason.trim()) { uni.showToast({ title: '请填写申请原因', icon: 'none' }); return }; this.send(['change','cancel','after_sale'][this.actionIndex], { reason: this.reason }) }
  }
}
</script>
<style scoped>@import './scene.css';.picker{padding:20rpx;background:#f5f7fb;border-radius:12rpx}.card textarea{height:240rpx}</style>
