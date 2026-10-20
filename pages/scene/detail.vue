<template>
  <view class="scene-page"><view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">需求详情</text><text @tap="list">需求列表</text></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-if="error && canClaim" class="body"><button class="primary" :disabled="busy" @tap="claim">使用本机原凭证认领到当前账号</button></view>
    <view v-if="pendingCommands.length" class="body"><view class="card"><text class="section">操作回执待确认</text><text class="muted">上次请求可能已送达。原内容和提交标识已保留，重试前请勿重复修改。</text><button v-for="pending in pendingCommands" :key="pending.slot" class="secondary divider" :disabled="busy" @tap="retryPending(pending)">按原内容重试{{ pendingLabel(pending.slot) }}</button></view></view>
    <view v-if="record && !error && !loading" class="body">
      <view class="hero"><text class="heading">{{ status(record.state) }}</text><text>{{ record.request_no }}</text><text>需求第 {{ record.revision_no }} 次修订</text></view>
      <view class="card"><text class="section">需求原文</text><text class="original">{{ record.original_text }}</text><text class="muted">{{ record.updated_at }}</text></view>
      <SceneAttachments :key="scope + ':' + id" :request-id="id" :files="record.attachments || []" @changed="load" />
      <view class="card"><text class="section">现场信息</text><view v-for="field in record.schema.fields" :key="field.key" class="row"><text>{{ field.label }}</text><text>{{ fieldValue(field,record.facts[field.key]) }}</text></view></view>
      <view v-if="record.service_clock" class="card"><text class="section">受理响应</text><text>{{ record.service_clock.notice }}</text><text v-if="record.service_clock.due_at">首次联系截止：{{ record.service_clock.due_at }} UTC · {{ record.service_clock.timezone }}</text><text v-if="record.service_clock.contacted_at">实际联系：{{ record.service_clock.contacted_at }} UTC</text></view>
      <view v-if="!['converted','closed','cancelled'].includes(record.state)" class="card"><text class="section">补充或修正需求</text><text class="muted">保存将形成新修订，已有评估、报价和临时预留需重新确认。</text><textarea v-model="supplementText" maxlength="20000" class="description" />
        <button v-if="record.schema.version < 2 && !upgradeSchema" class="secondary" :disabled="busy" @tap="prepareUpgrade">补充新版专业信息</button><text v-if="upgradeSchema" class="notice">已载入新版字段。提交后才会形成第 2 版表单修订，未知项保留待核实。</text>
        <SceneFactsForm :schema="editingSchema" :facts="supplementFacts" prefix="supplement-" @change="supplementFacts = $event" />
        <button class="secondary divider" :disabled="busy || pendingCommands.length > 0" @tap="supplement">提交新修订</button>
      </view>
      <view class="card"><text class="section">正式报价</text><text v-if="!record.quotes.length" class="muted">尚未收到正式报价</text>
        <view v-if="record.quotes.length >= 2" class="divider"><text class="section">比较已发送版本</text><label>原版本<picker :range="quoteLabels" :value="leftIndex" @change="selectCompare('leftIndex',$event)"><view class="picker">第 {{ record.quotes[leftIndex].version }} 版 ›</view></picker></label><label>对比版本<picker :range="quoteLabels" :value="rightIndex" @change="selectCompare('rightIndex',$event)"><view class="picker">第 {{ record.quotes[rightIndex].version }} 版 ›</view></picker></label><button class="secondary divider" :disabled="comparing || leftIndex === rightIndex" @tap="compareQuotes">{{ comparing ? '正在比较' : '查看差异' }}</button><text v-if="comparisonError" role="alert" class="notice">{{ comparisonError }}</text>
          <view v-if="comparison"><text class="muted">第 {{ comparison.left.version }} 版 → 第 {{ comparison.right.version }} 版</text><text v-if="comparison.same_currency">服务总额变化 {{ signedMoney(comparison.amount_deltas.total_minor) }} {{ comparison.right.currency }}；押金变化 {{ signedMoney(comparison.amount_deltas.deposit_minor) }}</text><text v-else>报价币种不同，不直接计算金额差。</text><text v-for="key in ['terms','request_revision','schedule','items','proposal']" :key="key">{{ compareLabel(key) }}：{{ comparison.changes[key] ? '有变化，请逐项核对两版详情' : '一致' }}</text><text class="muted">历史版本仅供查看；接受操作始终核验当前有效报价。</text></view>
        </view>
        <view v-for="quote in record.quotes" :key="quote.id" class="divider"><view class="row"><text>第 {{ quote.version }} 版</text><text class="state">{{ status(quote.state) }}</text></view><text class="price">{{ quote.currency }} {{ money(quote.total_minor) }}</text><text class="muted">押金另列 {{ money(quote.deposit_minor) }} · 有效期 {{ quote.expires_at }}</text>
          <view v-for="(item,index) in quote.items" :key="index" class="row"><text>{{ item.name }} × {{ item.quantity }} × {{ item.billing_units }} {{ item.unit }}</text><text>{{ money(item.line_minor) }}</text></view>
          <text class="muted">折扣 {{ money(quote.discount_minor) }} · 税额 {{ money(quote.tax_minor) }}</text><text class="original">{{ quote.terms.terms.text }}</text>
          <view v-if="quote.proposal" class="card"><text class="section">{{ quote.proposal.title }} · 方案 v{{ quote.proposal.version }}</text><text class="original">交付范围：{{ quote.proposal.scope }}</text><text class="original">不含范围：{{ quote.proposal.exclusions }}</text><view v-for="part in quote.proposal.components" :key="part.key" class="row"><text>{{ part.label }} · {{ part.selected ? '选用' : '未选' }} · {{ part.required ? '必选' : '选配' }}</text><text>数量 {{ part.quantity }} {{ componentUnit(part.unit) }}</text></view><view v-for="item in quote.proposal.acceptance" :key="item.key"><text>{{ item.label }}{{ item.required ? '（必需）' : '' }}</text></view></view>
          <text class="muted">{{ quote.schedule.starts_at }} 至 {{ quote.schedule.ends_at }} · {{ quote.schedule.timezone }}</text>
          <view v-if="quote.state === 'sent'"><view v-if="!quote.is_expired"><view class="consent" @tap="consents[quote.id] = !consents[quote.id]"><text>{{ consents[quote.id] ? '☑' : '☐' }} 我已阅读并确认本版服务范围、金额和条款</text></view><button class="primary" :disabled="busy || pendingCommands.length > 0" @tap="accept(quote)">接受本版报价</button></view><text v-else class="notice">本版已过有效期，请联系服务人员重新出具报价。</text><label>拒绝本版的原因<textarea v-model="rejectionReasons[quote.id]" maxlength="2000" placeholder="请说明需要重新调整的范围、安排或费用" /></label><button class="secondary divider" :disabled="busy || pendingCommands.length > 0" @tap="reject(quote)">拒绝本版并请求重新沟通</button></view>
          <view v-if="quote.state === 'accepted'" class="notice">报价接受已记录，资源、商务条件与项目仍待分别确认。</view>
          <text v-if="quote.customer_rejection" class="notice">本次拒绝原因：{{ quote.customer_rejection.reason }}</text>
        </view>
      </view>
      <view class="card"><text class="section">交付项目</text><text v-if="!record.projects.length" class="muted">暂无已确认项目</text><view v-for="project in record.projects" :key="project.id" class="divider" @tap="openProject(project.id)"><text>{{ project.project_no }}</text><text class="link">{{ status(project.state) }} · 查看交付和验收 ›</text><text class="muted">款项 {{ status(project.payment_state) }} · 商务 {{ status(project.commercial_state) }}</text></view></view>
      <view class="card"><text class="section">公开处理进展</text><view v-for="activity in record.activities" :key="activity.id" class="row"><text>{{ activityLabel(activity.kind) }}</text><text class="muted">{{ activity.occurred_at }}</text></view></view>
    </view>
  </view>
</template>
<script>
import StateView from '@/components/StateView.vue'
import SceneFactsForm from '@/components/SceneFactsForm.vue'
import SceneAttachments from '@/components/SceneAttachments.vue'
import { normalizeSceneFacts, sceneFactLabel } from '@/utils/scene-fields'
import { sceneWorkflowApi } from '@/utils/api'
import { sceneAccountScope, sceneLocalState, sceneGuestAccess, submitSceneCommand, scenePendingCommands, sceneStatusLabel } from '@/utils/scene-request'
export default {
  components: { StateView, SceneFactsForm, SceneAttachments },
  computed: { quoteLabels() { return this.record ? this.record.quotes.map(q => '第 ' + q.version + ' 版') : [] }, editingSchema() { return this.upgradeSchema || this.record.schema } },
  data() { return { id: 0, record: null, upgradeSchema: null, loading: false, error: '', busy: false, scope: '', generation: 0, canClaim: false, consents: {}, supplementText: '', supplementFacts: {}, rejectionReasons: {}, pendingCommands: [], leftIndex: 1, rightIndex: 0, comparison: null, comparing: false, comparisonError: '', compareGeneration: 0 } },
  onLoad(options) { this.id = Number(options.id) },
  onShow() { if (this.scope !== sceneAccountScope()) { this.record = null; this.supplementText = ''; this.supplementFacts = {}; this.consents = {}; this.rejectionReasons = {}; this.comparison = null; this.comparisonError = ''; this.comparing = false; this.compareGeneration++ }; this.scope = sceneAccountScope(); this.load() },
  onUnload() { this.generation++ },
  methods: {
    fieldValue: sceneFactLabel,
    componentUnit(unit) { return ({device:'台',person:'人',item:'件',license:'份',project:'项'})[unit] || '' },
    async prepareUpgrade() {
      const scope=sceneAccountScope(), generation=this.generation
      try { const r=await sceneWorkflowApi.schema(this.record.schema.category); if (scope!==sceneAccountScope() || generation!==this.generation) return; this.upgradeSchema=r.data; const keys=new Set(r.data.fields.map(f=>f.key)); this.supplementFacts=Object.fromEntries(Object.entries(this.supplementFacts).filter(([key])=>keys.has(key))) }
      catch(e) { if (scope===sceneAccountScope()) uni.showToast({title:e.message||'新版表单加载失败',icon:'none'}) }
    },
    status: sceneStatusLabel, back() { uni.navigateBack() }, list() { uni.navigateTo({ url: '/pages/scene/requests' }) }, openProject(id) { uni.navigateTo({ url: '/pages/scene/project?id=' + id }) },
    money(value) { return (Number(value) / 100).toFixed(2) }, factLabel(v) { return v === true ? '是' : (v === false ? '否' : (v === null || v === undefined || v === '' ? '待补充' : String(v))) },
    activityLabel(kind) { return ({ submitted: '需求已提交', supplemented: '需求已补充' })[kind] || '服务进展已更新' },
    signedMoney(v) { return (v > 0 ? '+' : '') + this.money(v) }, compareLabel(key) { return ({ terms: '正式条款', request_revision: '需求修订', schedule: '服务时段与缓冲', items: '服务明细', proposal: '方案范围、组件与验收' })[key] },
    pendingLabel(slot) { return slot.includes(':accept:') ? '报价接受' : (slot.includes(':reject:') ? '报价拒绝' : '需求补充') },
    selectCompare(key, event) { this[key] = Number(event.detail.value); this.comparison = null; this.comparisonError = ''; this.comparing = false; this.compareGeneration++ },
    async compareQuotes() {
      const left = this.record.quotes[this.leftIndex], right = this.record.quotes[this.rightIndex]
      if (!left || !right || left.id === right.id) return
      const generation = this.generation, comparisonVersion = ++this.compareGeneration, scope = sceneAccountScope()
      this.comparing = true; this.comparison = null; this.comparisonError = ''
      try {
        const response = await sceneWorkflowApi.compareQuotes(this.id, left.id, right.id, sceneLocalState().access)
        if (generation !== this.generation || comparisonVersion !== this.compareGeneration || scope !== sceneAccountScope()) return
        if (!response.data || !response.data.left || !response.data.right || !response.data.changes || !response.data.amount_deltas) throw new Error('未收到有效比较结果')
        this.comparison = response.data
      } catch (e) { if (generation === this.generation && comparisonVersion === this.compareGeneration && scope === sceneAccountScope()) this.comparisonError = e.message || '比较失败，请重试' }
      finally { if (generation === this.generation && comparisonVersion === this.compareGeneration && scope === sceneAccountScope()) this.comparing = false }
    },
    booleanIndex(v) { return v === true || v === 1 ? 1 : (v === false || v === 0 ? 2 : 0) }, setBoolean(key,e) { const i = Number(e.detail.value); this.supplementFacts[key] = i === 0 ? null : i === 1 },
    async load() {
      const version = ++this.generation, scope = sceneAccountScope()
      this.loading = true; this.error = ''; this.canClaim = scope !== 'guest' && !!sceneGuestAccess()
      this.pendingCommands = scenePendingCommands('request:' + this.id + ':'); this.comparison = null; this.comparisonError = ''; this.comparing = false; this.compareGeneration++
      try {
        if (!Number.isSafeInteger(this.id) || this.id < 1) throw new Error('需求编号无效')
        const response = await sceneWorkflowApi.detail(this.id, sceneLocalState().access)
        if (version !== this.generation || scope !== sceneAccountScope()) return
        if (!response.data || !response.data.schema || !Array.isArray(response.data.quotes)) throw new Error('需求详情暂不可用')
        this.record = response.data; this.upgradeSchema = null; this.supplementText = response.data.original_text; this.supplementFacts = { ...response.data.facts }
        this.leftIndex = this.record.quotes.length >= 2 ? 1 : 0; this.rightIndex = 0
      } catch (e) { if (version === this.generation && scope === sceneAccountScope()) { this.record = null; this.error = e.message || '加载失败' } }
      finally { if (version === this.generation && scope === sceneAccountScope()) this.loading = false }
    },
    async command(action) {
      if (this.busy) return
      this.busy = true; const scope = sceneAccountScope()
      try { await action(); if (scope === sceneAccountScope()) await this.load() }
      catch (e) { if (scope === sceneAccountScope()) uni.showToast({ title: e.message || '操作失败，请重试', icon: 'none' }) }
      finally { if (scope === sceneAccountScope()) this.pendingCommands = scenePendingCommands('request:' + this.id + ':'); this.busy = false }
    },
    claim() { this.command(() => sceneWorkflowApi.claim(this.id, sceneGuestAccess())) },
    canStartCommand() { if (!this.pendingCommands.length) return true; uni.showToast({ title: '请先按原内容确认上一条操作回执', icon: 'none' }); return false },
    supplement() {
      if (!this.canStartCommand()) return
      let facts
      try { facts=normalizeSceneFacts(this.editingSchema,this.supplementFacts) } catch(e) { uni.showToast({title:e.message,icon:'none'}); return }
      const payload = { original_text: this.supplementText, facts, row_version: this.record.row_version, ...(this.upgradeSchema ? { upgrade_schema_version: 2 } : {}) }
      const slot = 'request:' + this.id + ':supplement'
      this.command(() => submitSceneCommand(slot, payload, (data, access, key) => sceneWorkflowApi.supplement(this.id, data, access, key)))
    },
    accept(quote) {
      if (!this.canStartCommand()) return
      if (!this.consents[quote.id]) { uni.showToast({ title: '请先确认本版报价与条款', icon: 'none' }); return }
      const payload = { terms_hash: quote.terms_hash, content_hash: quote.content_hash }, slot = 'request:' + this.id + ':accept:' + quote.id
      this.command(() => submitSceneCommand(slot, payload, (data, access, key) => sceneWorkflowApi.accept(quote.id, data, access, key)))
    },
    reject(quote) {
      if (!this.canStartCommand()) return
      const reason = (this.rejectionReasons[quote.id] || '').trim()
      if (reason.length < 3) { uni.showToast({ title: '请填写至少三个字的拒绝原因', icon: 'none' }); return }
      const payload = { reason, row_version: quote.row_version, terms_hash: quote.terms_hash, content_hash: quote.content_hash }, slot = 'request:' + this.id + ':reject:' + quote.id
      this.command(() => submitSceneCommand(slot, payload, (data, access, key) => sceneWorkflowApi.reject(quote.id, data, access, key)))
    },
    retryPending(pending) {
      const suffix = pending.slot.slice(('request:' + this.id + ':').length), [action, id] = suffix.split(':')
      if (!['supplement','accept','reject'].includes(action)) return
      this.command(() => submitSceneCommand(pending.slot, pending.payload, (data, access, key) => action === 'supplement'
        ? sceneWorkflowApi.supplement(this.id, data, access, key) : sceneWorkflowApi[action](Number(id), data, access, key)))
    }
  }
}
</script>
<style scoped>@import './scene.css';.original{display:block;white-space:pre-wrap;word-break:break-all;line-height:1.8;font-size:28rpx;margin:16rpx 0}.price{font-size:38rpx;font-weight:600;color:#102F6E}.description{height:240rpx}.picker{padding:18rpx;background:#f5f7fb;border-radius:12rpx}.consent{padding:24rpx 0;color:#165DFF;font-size:25rpx;line-height:1.8}.row text{flex:1;word-break:break-all}</style>
