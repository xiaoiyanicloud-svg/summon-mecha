<template>
  <view class="scene-page"><view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">需求详情</text><text @tap="list">需求列表</text></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-if="error && canClaim" class="body"><button class="primary" :disabled="busy" @tap="claim">使用本机原凭证认领到当前账号</button></view>
    <view v-if="record && !error && !loading" class="body">
      <view class="hero"><text class="heading">{{ status(record.state) }}</text><text>{{ record.request_no }}</text><text>需求第 {{ record.revision_no }} 次修订</text></view>
      <view class="card"><text class="section">需求原文</text><text class="original">{{ record.original_text }}</text><text class="muted">{{ record.updated_at }}</text></view>
      <view class="card"><text class="section">现场信息</text><view v-for="field in record.schema.fields" :key="field.key" class="row"><text>{{ field.label }}</text><text>{{ factLabel(record.facts[field.key]) }}</text></view></view>
      <view v-if="!['converted','closed','cancelled'].includes(record.state)" class="card"><text class="section">补充或修正需求</text><text class="muted">保存将形成新修订，已有评估、报价和临时预留需重新确认。</text><textarea v-model="supplementText" maxlength="20000" class="description" />
        <view v-for="field in record.schema.fields" :key="field.key"><label>{{ field.label }}</label><picker v-if="field.type === 'boolean'" :range="['待核实','是','否']" :value="booleanIndex(supplementFacts[field.key])" @change="setBoolean(field.key,$event)"><view class="picker">{{ ['待核实','是','否'][booleanIndex(supplementFacts[field.key])] }} ›</view></picker><input v-else v-model="supplementFacts[field.key]" :type="field.type === 'integer' ? 'number' : 'text'" :maxlength="field.type === 'integer' ? 10 : (field.max || 1000)" /></view>
        <button class="secondary divider" :disabled="busy" @tap="supplement">提交新修订</button>
      </view>
      <view class="card"><text class="section">正式报价</text><text v-if="!record.quotes.length" class="muted">尚未收到正式报价</text>
        <view v-for="quote in record.quotes" :key="quote.id" class="divider"><view class="row"><text>第 {{ quote.version }} 版</text><text class="state">{{ status(quote.state) }}</text></view><text class="price">{{ quote.currency }} {{ money(quote.total_minor) }}</text><text class="muted">押金另列 {{ money(quote.deposit_minor) }} · 有效期 {{ quote.expires_at }}</text>
          <view v-for="(item,index) in quote.items" :key="index" class="row"><text>{{ item.name }} × {{ item.quantity }} × {{ item.billing_units }} {{ item.unit }}</text><text>{{ money(item.line_minor) }}</text></view>
          <text class="muted">折扣 {{ money(quote.discount_minor) }} · 税额 {{ money(quote.tax_minor) }}</text><text class="original">{{ quote.terms.terms.text }}</text>
          <text class="muted">{{ quote.schedule.starts_at }} 至 {{ quote.schedule.ends_at }} · {{ quote.schedule.timezone }}</text>
          <view v-if="quote.state === 'sent'"><view class="consent" @tap="consents[quote.id] = !consents[quote.id]"><text>{{ consents[quote.id] ? '☑' : '☐' }} 我已阅读并确认本版服务范围、金额和条款</text></view><button class="primary" :disabled="busy" @tap="accept(quote)">接受本版报价</button></view>
          <view v-if="quote.state === 'accepted'" class="notice">报价接受已记录，资源、商务条件与项目仍待分别确认。</view>
        </view>
      </view>
      <view class="card"><text class="section">交付项目</text><text v-if="!record.projects.length" class="muted">暂无已确认项目</text><view v-for="project in record.projects" :key="project.id" class="divider" @tap="openProject(project.id)"><text>{{ project.project_no }}</text><text class="link">{{ status(project.state) }} · 查看交付和验收 ›</text><text class="muted">款项 {{ status(project.payment_state) }} · 商务 {{ status(project.commercial_state) }}</text></view></view>
      <view class="card"><text class="section">公开处理进展</text><view v-for="activity in record.activities" :key="activity.id" class="row"><text>{{ activityLabel(activity.kind) }}</text><text class="muted">{{ activity.occurred_at }}</text></view></view>
    </view>
  </view>
</template>
<script>
import StateView from '@/components/StateView.vue'
import { sceneWorkflowApi } from '@/utils/api'
import { sceneAccountScope, sceneLocalState, sceneGuestAccess, saveSceneDraft, clearSceneDraft, sceneStatusLabel } from '@/utils/scene-request'
export default {
  components: { StateView },
  data() { return { id: 0, record: null, loading: false, error: '', busy: false, scope: '', generation: 0, canClaim: false, consents: {}, supplementText: '', supplementFacts: {} } },
  onLoad(options) { this.id = Number(options.id) },
  onShow() { if (this.scope !== sceneAccountScope()) { this.record = null; this.supplementText = ''; this.supplementFacts = {}; this.consents = {} }; this.scope = sceneAccountScope(); this.load() },
  onUnload() { this.generation++ },
  methods: {
    status: sceneStatusLabel, back() { uni.navigateBack() }, list() { uni.navigateTo({ url: '/pages/scene/requests' }) }, openProject(id) { uni.navigateTo({ url: '/pages/scene/project?id=' + id }) },
    money(value) { return (Number(value) / 100).toFixed(2) }, factLabel(v) { return v === true ? '是' : (v === false ? '否' : (v === null || v === undefined || v === '' ? '待补充' : String(v))) },
    activityLabel(kind) { return ({ submitted: '需求已提交', supplemented: '需求已补充' })[kind] || '服务进展已更新' },
    booleanIndex(v) { return v === true || v === 1 ? 1 : (v === false || v === 0 ? 2 : 0) }, setBoolean(key,e) { const i = Number(e.detail.value); this.supplementFacts[key] = i === 0 ? null : i === 1 },
    async load() {
      const version = ++this.generation, scope = sceneAccountScope()
      this.loading = true; this.error = ''; this.canClaim = scope !== 'guest' && !!sceneGuestAccess()
      try {
        if (!Number.isSafeInteger(this.id) || this.id < 1) throw new Error('需求编号无效')
        const response = await sceneWorkflowApi.detail(this.id, sceneLocalState().access)
        if (version !== this.generation || scope !== sceneAccountScope()) return
        if (!response.data || !response.data.schema || !Array.isArray(response.data.quotes)) throw new Error('需求详情暂不可用')
        this.record = response.data; this.supplementText = response.data.original_text; this.supplementFacts = { ...response.data.facts }
      } catch (e) { if (version === this.generation && scope === sceneAccountScope()) { this.record = null; this.error = e.message || '加载失败' } }
      finally { if (version === this.generation && scope === sceneAccountScope()) this.loading = false }
    },
    async command(action) {
      if (this.busy) return
      this.busy = true; const scope = sceneAccountScope()
      try { await action(); if (scope === sceneAccountScope()) await this.load() }
      catch (e) { if (scope === sceneAccountScope()) uni.showToast({ title: e.message || '操作失败，请重试', icon: 'none' }) }
      finally { this.busy = false }
    },
    claim() { this.command(() => sceneWorkflowApi.claim(this.id, sceneGuestAccess())) },
    supplement() {
      const facts = {}
      this.record.schema.fields.forEach(field => { const v = this.supplementFacts[field.key]; if (v !== undefined && v !== null && v !== '') facts[field.key] = field.type === 'integer' ? Number(v) : v })
      const payload = { original_text: this.supplementText, facts, row_version: this.record.row_version }
      const slot = 'supplement:' + this.id, draft = saveSceneDraft(slot, payload)
      this.command(async () => { await sceneWorkflowApi.supplement(this.id, payload, sceneLocalState().access, draft.key); clearSceneDraft(slot) })
    },
    accept(quote) {
      if (!this.consents[quote.id]) { uni.showToast({ title: '请先确认本版报价与条款', icon: 'none' }); return }
      const payload = { terms_hash: quote.terms_hash, content_hash: quote.content_hash }, slot = 'accept:' + quote.id
      const draft = saveSceneDraft(slot, payload)
      this.command(async () => { await sceneWorkflowApi.accept(quote.id, payload, sceneLocalState().access, draft.key); clearSceneDraft(slot) })
    }
  }
}
</script>
<style scoped>@import './scene.css';.original{display:block;white-space:pre-wrap;word-break:break-all;line-height:1.8;font-size:28rpx;margin:16rpx 0}.price{font-size:38rpx;font-weight:600;color:#102F6E}.description{height:240rpx}.picker{padding:18rpx;background:#f5f7fb;border-radius:12rpx}.consent{padding:24rpx 0;color:#165DFF;font-size:25rpx;line-height:1.8}.row text{flex:1;word-break:break-all}</style>
