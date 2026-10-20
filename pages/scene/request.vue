<template>
  <view class="scene-page">
    <view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">提交服务需求</text><text @tap="mine">我的需求</text></view>
    <view v-if="draftState === 'awaiting_receipt'" class="body"><view class="notice">上次提交结果待确认。重试会使用原内容和原幂等键，避免重复建立需求。<button class="secondary" :disabled="submitting" @tap="retryPending">重试获取上次提交回执</button></view></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-else class="body">
      <view class="hero"><text class="heading">{{ sceneName || '机器人场景服务' }}</text><text>需求提交后进入人工受理、现场评估与正式报价。交付安排以项目确认为准。</text></view>
      <view v-if="!capabilities.requests" class="notice">在线受理暂未开放，您可以将草稿保存在本机。</view>
      <view class="card"><text class="section">需求描述</text><textarea v-model="form.original_text" maxlength="20000" placeholder="请描述任务目标、期望效果和现场限制" class="description" /><text class="muted">最多 20000 字，原文完整保存。</text></view>
      <view class="card"><text class="section">联系方式</text><label>联系人<input v-model="form.contact.name" maxlength="100" placeholder="请输入姓名" /></label><label>联系电话<input v-model="form.contact.phone" maxlength="25" placeholder="请输入联系电话" /></label><label>公司（选填）<input v-model="form.contact.company" maxlength="255" /></label><label>微信（选填）<input v-model="form.contact.wechat" maxlength="100" /></label></view>
      <view class="card"><text class="section">现场信息</text><text class="muted">暂不明确的条件可留空，服务人员会继续核实。</text>
        <view v-for="field in schema.fields" :key="field.key" class="field"><text>{{ field.label }}</text>
          <picker v-if="field.type === 'boolean'" :range="['待核实','是','否']" :value="booleanIndex(form.facts[field.key])" @change="setBoolean(field.key,$event)"><view class="picker">{{ ['待核实','是','否'][booleanIndex(form.facts[field.key])] }} ›</view></picker>
          <input v-else v-model="form.facts[field.key]" :type="field.type === 'integer' ? 'number' : 'text'" :maxlength="field.type === 'integer' ? 10 : (field.max || 1000)" :placeholder="timeHint(field.key)" />
        </view>
      </view>
      <view class="consent" @tap="consent = !consent"><text class="box">{{ consent ? '☑' : '☐' }}</text><text>{{ capabilities.consent_text || '我同意平台为本次服务评估、沟通及交付联系我。' }}</text></view>
      <text v-if="savedAt" class="muted">{{ draftState === 'awaiting_receipt' ? '提交结果待确认，请按原内容重试获取回执。' : '草稿保存在本机，尚未取得提交成功回执。' }} 保存于 {{ savedAt }}</text>
      <text v-if="draftState === 'awaiting_receipt'" class="link" @tap="restorePending">恢复上次提交内容</text>
      <view class="actions"><button class="secondary" :disabled="submitting" @tap="saveDraft">保存草稿</button><button class="primary" :disabled="submitting || !capabilities.requests" @tap="submit">{{ submitting ? '提交中…' : '提交需求' }}</button></view>
      <view class="privacy">访问凭证保存在本机，请勿分享。提交后可在登录状态下认领需求，便于跨设备查询。</view>
    </view>
  </view>
</template>
<script>
import StateView from '@/components/StateView.vue'
import { sceneApi, sceneWorkflowApi } from '@/utils/api'
import { sceneAccountScope, sceneLocalState, saveSceneDraft, sceneDraftPayload, submitSceneRequest } from '@/utils/scene-request'

const blank = () => ({ original_text: '', contact: { name: '', phone: '', company: '', wechat: '' }, facts: {} })
export default {
  components: { StateView },
  data() { return { sceneId: null, revisionId: null, category: 'enterprise', source: 'home', sceneName: '', schema: { fields: [] }, form: blank(), loading: false, error: '', submitting: false, consent: false, savedAt: '', draftState: '', capabilities: {}, scope: '', generation: 0 } },
  onLoad(options) { this.sceneId = /^\d+$/.test(options.sceneId || '') ? Number(options.sceneId) : null; this.source = ['home','scene','robot','ai'].includes(options.source) ? options.source : 'home' },
  onShow() { if (this.scope !== sceneAccountScope()) { this.scope = sceneAccountScope(); this.form = blank(); this.consent = false; const pending = sceneLocalState().pending.form; this.draftState = pending ? pending.state : ''; this.savedAt = pending ? pending.savedAt : ''; this.load() } },
  onUnload() { this.generation++ },
  methods: {
    back() { uni.navigateBack() }, mine() { uni.navigateTo({ url: '/pages/scene/requests' }) },
    async load() {
      const version = ++this.generation, scope = sceneAccountScope()
      this.loading = true; this.error = ''
      try {
        const caps = await sceneWorkflowApi.capabilities()
        let schema, info = null
        if (this.sceneId) { info = await sceneApi.getSceneDetail(this.sceneId); schema = info.requirement_schema }
        if (!schema) schema = (await sceneWorkflowApi.schema(info ? info.category : this.category)).data
        if (version !== this.generation || scope !== sceneAccountScope()) return
        if (!schema || !Array.isArray(schema.fields)) throw new Error('需求表单加载失败')
        this.capabilities = caps.data || {}; this.schema = schema
        if (info) { this.sceneName = info.name; this.revisionId = info.scene_revision_id || null; this.category = info.category }
        const local = sceneLocalState().pending.form
        if (local && (!local.payload.scene_id || Number(local.payload.scene_id) === this.sceneId)) {
          this.form = { original_text: local.payload.original_text, contact: { ...local.payload.contact }, facts: { ...local.payload.facts } }
          this.savedAt = local.savedAt
          this.draftState = local.state
        }
      } catch (e) { if (version === this.generation && scope === sceneAccountScope()) this.error = e.message || '加载失败，请重试' }
      finally { if (version === this.generation && scope === sceneAccountScope()) this.loading = false }
    },
    booleanIndex(value) { return value === true || value === 1 ? 1 : (value === false || value === 0 ? 2 : 0) },
    setBoolean(key, event) { const i = Number(event.detail.value); this.form.facts[key] = i === 0 ? null : i === 1 },
    timeHint(key) { return key.endsWith('_at') ? '例：2026-11-01T10:00:00+08:00' : (key === 'timezone' ? '例：Asia/Shanghai' : '暂不明确可留空') },
    payload() {
      const facts = {}
      this.schema.fields.forEach(field => { const v = this.form.facts[field.key]; if (v !== undefined && v !== null && v !== '') facts[field.key] = field.type === 'integer' ? Number(v) : v })
      return sceneDraftPayload({ source: this.source, sceneId: this.sceneId, revisionId: this.revisionId, category: this.category, text: this.form.original_text, facts, contact: this.form.contact })
    },
    saveDraft() {
      try { const saved = saveSceneDraft('form', this.payload()); this.savedAt = saved.savedAt; this.draftState = saved.state; uni.showToast({ title: '草稿已保存到本机', icon: 'none' }) }
      catch (e) { uni.showToast({ title: e.message, icon: 'none' }) }
    },
    restorePending() { const draft = sceneLocalState().pending.form; if (draft) this.form = { original_text: draft.payload.original_text, facts: { ...draft.payload.facts }, contact: { ...draft.payload.contact } } },
    async retryPending() {
      const draft = sceneLocalState().pending.form
      if (!draft || this.submitting) return
      const scope = sceneAccountScope(); this.submitting = true
      try { const receipt = await submitSceneRequest(draft.payload); if (scope === sceneAccountScope()) uni.redirectTo({ url: '/pages/scene/detail?id=' + receipt.id }) }
      catch (e) { if (scope === sceneAccountScope()) { const pending = sceneLocalState().pending.form; this.draftState = pending ? pending.state : ''; uni.showToast({ title: e.message || '提交结果仍待确认', icon: 'none' }) } }
      finally { this.submitting = false }
    },
    async submit() {
      if (this.submitting) return
      if (!this.consent) { uni.showToast({ title: '请先确认联系授权', icon: 'none' }); return }
      if (!this.form.original_text.trim() || !this.form.contact.name.trim() || !/^\+?[0-9 -]{7,25}$/.test(this.form.contact.phone)) { uni.showToast({ title: '请填写需求、联系人及有效电话', icon: 'none' }); return }
      this.submitting = true
      const scope = sceneAccountScope()
      try {
        const receipt = await submitSceneRequest(this.payload())
        if (scope !== sceneAccountScope()) return
        uni.redirectTo({ url: '/pages/scene/detail?id=' + receipt.id })
      } catch (e) { if (scope === sceneAccountScope()) { const draft = sceneLocalState().pending.form; if (draft) { this.savedAt = draft.savedAt; this.draftState = draft.state }; uni.showToast({ title: e.message || '提交未确认，草稿已保留', icon: 'none' }) } }
      finally { this.submitting = false }
    }
  }
}
</script>
<style scoped>
@import './scene.css';
.description{width:100%;height:260rpx;font-size:28rpx;line-height:1.7;box-sizing:border-box}.consent{display:flex;gap:18rpx;font-size:24rpx;line-height:1.7;padding:12rpx}.box{font-size:36rpx;color:#165DFF}.field{margin-top:24rpx}.picker{padding:20rpx;background:#f5f7fb;border-radius:12rpx;margin-top:12rpx}.privacy{font-size:23rpx;color:#7a8498;line-height:1.7;margin:24rpx 0}
</style>
