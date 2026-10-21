<template>
  <view class="scene-page">
    <view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">服务订单确认</text><text @tap="load">刷新</text></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-if="record && !loading && !error" class="body">
      <view class="hero"><text class="heading">{{ record.receipt ? '服务已确认' : '确认服务安排' }}</text><text>报价第 {{ record.quote.version }} 版</text><text>已接受的服务费 {{ money(record.quote.total_minor) }} {{ record.quote.currency }}</text><text>押金另列 {{ money(record.quote.deposit_minor) }}</text></view>
      <view v-if="pending" class="notice" role="status"><text>上次确认结果待查询。原提交已保留，请先按原内容重试。</text><button class="secondary divider" :disabled="busy" @tap="retry">查询原提交结果</button></view>
      <view v-if="record.receipt" class="card"><text class="section">订单与交付项目</text><text>订单号 {{ record.receipt.increment_id }}</text><text v-if="record.receipt.skill_order_id" class="muted">已关联独立技能订单，授权和设备绑定请在技能订单中查看。</text><text class="muted">商务 {{ status(record.receipt.commercial_state) }} · 款项 {{ status(record.receipt.payment_state) }}</text><text class="notice">本页记录线下商务与交付确认，实际收款以复核凭证为准。</text><button class="primary divider" @tap="project">查看交付安排</button></view>
      <view v-else>
        <view class="card"><text class="section">交付资源</text><text v-if="!record.held_resources.length" class="muted">尚无有效的临时资源预留。请联系服务人员核实完整设备、人员与配件安排。</text><view v-for="(resource,index) in record.held_resources" :key="index" class="resource"><text>{{ resourceKind(resource.kind) }}</text><text class="muted">含行程与维护占用：{{ resource.occupies_from }} 至 {{ resource.occupies_until }} UTC</text></view><text v-if="record.hold_expires_at" class="notice">临时预留截止 {{ record.hold_expires_at }} UTC。超过期限需重新核实资源。</text><text class="muted">服务时间 {{ record.quote.schedule.starts_at }} 至 {{ record.quote.schedule.ends_at }} · {{ record.quote.schedule.timezone }}</text></view>
        <view class="card"><text class="section">商务条件与款项</text><text>线下合同：{{ record.commercial.contract_reviewed ? '已独立复核' : '待复核或本版不要求' }}</text><text class="block">已独立复核实际收款：{{ money(record.commercial.paid_minor) }} {{ record.quote.currency }}</text><text v-if="record.quote.confirmation" class="muted">本版确认条件：{{ record.quote.confirmation.method === 'offline_contract' ? '线下合同或授信已独立复核' : '实际预收款至少 ' + money(record.quote.confirmation.required_minor) + ' 元' }}；确认截止 {{ record.quote.confirmation.deadline }} UTC。</text><text v-else class="notice">本版尚未明确商务与资源确认条件，请联系服务人员协商完整报价。</text><text class="muted">上传截图后仍需服务人员独立核对实际事实。</text></view>
        <view class="card"><text class="section">服务范围与条款</text><text v-if="record.quote.proposal" class="original">{{ record.quote.proposal.scope }}</text><text v-if="record.quote.proposal" class="original">不含范围：{{ record.quote.proposal.exclusions }}</text><text class="original">{{ record.quote.terms.terms.text }}</text>
          <text v-if="record.login_required" class="notice">请登录后，在需求详情使用本机原凭证认领需求，再确认订单。</text><text v-else-if="!record.conversion_enabled" class="notice">订单确认暂未开放，请联系服务人员继续处理。</text>
          <view class="consent" @tap="consent = !consent"><text>{{ consent ? '☑' : '☐' }} 我已核对本版安排、金额和条款，确认建立服务订单</text></view>
          <button class="primary" :disabled="busy || !canConfirm || !!pending" @tap="confirm">{{ busy ? '正在核实确认结果' : '确认服务订单' }}</button><text v-if="commandError" class="notice" role="alert">{{ commandError }}</text>
        </view>
      </view>
      <button class="secondary" @tap="request">返回需求详情</button>
    </view>
  </view>
</template>
<script>
import StateView from '@/components/StateView.vue'
import { sceneWorkflowApi } from '@/utils/api'
import { sceneAccountScope, sceneLocalState, scenePendingCommands, submitSceneCommand, clearSceneDraft, sceneStatusLabel } from '@/utils/scene-request'
export default {
  components: { StateView },
  data() { return { id: 0, record: null, loading: false, error: '', busy: false, consent: false, commandError: '', scope: '', generation: 0, pending: null } },
  computed: {
    slot() { return 'quote:' + this.id + ':convert' },
    canConfirm() {
      const r = this.record
      if (!r || r.receipt || r.login_required || !r.conversion_enabled || r.quote.state !== 'accepted' || !r.quote.confirmation || !this.consent) return false
      const c = r.quote.confirmation
      if (c.method === 'offline_contract' ? !r.commercial.contract_reviewed : r.commercial.paid_minor < c.required_minor) return false
      const needs = r.quote.items.some(i => i.charge_type !== 'deposit' && ['robot','operator','accessory'].includes(i.kind))
      return !needs || !!r.hold_group
    }
  },
  onLoad(options) { this.id = Number(options.quoteId) },
  onShow() { const scope = sceneAccountScope(); if (scope !== this.scope) { this.record = null; this.consent = false; this.pending = null; this.commandError = '' }; this.busy = false; this.scope = scope; this.load() },
  onHide() { this.generation++ }, onUnload() { this.generation++ },
  methods: {
    status: sceneStatusLabel, money(v) { return (Number(v) / 100).toFixed(2) },
    resourceKind(kind) { return ({robot:'机器人',operator:'服务人员',accessory:'配件'})[kind] || '履约资源' },
    back() { uni.navigateBack() }, request() { uni.navigateTo({url:'/pages/scene/detail?id='+this.record.request_id}) },
    project() { uni.navigateTo({url:'/pages/scene/project?id='+this.record.receipt.project_id}) },
    async load() {
      const generation = ++this.generation, scope = sceneAccountScope()
      this.loading = true; this.error = ''; this.pending = scenePendingCommands(this.slot)[0] || null
      try {
        if (!Number.isSafeInteger(this.id) || this.id < 1) throw new Error('报价编号无效')
        const response = await sceneWorkflowApi.confirmation(this.id, sceneLocalState().access)
        if (generation !== this.generation || scope !== sceneAccountScope()) return
        if (!response.data?.quote || !Array.isArray(response.data.held_resources) || !response.data.commercial) throw new Error('未收到有效确认信息')
        this.record = response.data
        if (this.record.receipt) { clearSceneDraft(this.slot); this.pending = null }
      } catch (e) { if (generation === this.generation && scope === sceneAccountScope()) { this.record = null; this.error = e.message || '确认信息加载失败' } }
      finally { if (generation === this.generation && scope === sceneAccountScope()) this.loading = false }
    },
    confirm() {
      if (!this.canConfirm || this.pending || this.busy) return
      this.send({hold_group:this.record.hold_group || null, terms_hash:this.record.quote.terms_hash, content_hash:this.record.quote.content_hash})
    },
    retry() { if (this.pending && !this.busy) this.send(this.pending.payload) },
    async send(payload) {
      const generation = this.generation, scope = sceneAccountScope()
      this.busy = true; this.commandError = ''
      try {
        const result = await submitSceneCommand(this.slot, payload, async (data, access, key) => {
          const response = await sceneWorkflowApi.convert(this.id, data, access, key)
          if (!Number.isSafeInteger(response?.data?.order_id) || !Number.isSafeInteger(response?.data?.project_id) || response.data.delivery_status !== 'confirmed') throw new Error('订单确认回执不完整，请刷新查询')
          return response
        })
        if (generation !== this.generation || scope !== sceneAccountScope()) return
        if (!Number.isSafeInteger(result.order_id) || !Number.isSafeInteger(result.project_id) || result.delivery_status !== 'confirmed') throw new Error('订单确认回执不完整，请刷新查询')
        this.record.receipt = result; this.pending = null; this.consent = false
      } catch (e) { if (generation === this.generation && scope === sceneAccountScope()) this.commandError = e.message || '确认结果待查询，请按原提交重试' }
      finally { if (generation === this.generation && scope === sceneAccountScope()) { this.busy = false; this.pending = scenePendingCommands(this.slot)[0] || null } }
    }
  }
}
</script>
<style scoped>@import './scene.css';.original{display:block;white-space:pre-wrap;overflow-wrap:anywhere;margin:12rpx 0}.block,.card>text,.resource text{display:block}.card>text+text{margin-top:12rpx}.resource{padding:16rpx 0;border-bottom:1rpx solid #eef1f5}.consent{padding:24rpx 0;color:#165DFF;font-size:26rpx;line-height:1.8}button[disabled]{opacity:.5}.card{overflow-wrap:anywhere}</style>
