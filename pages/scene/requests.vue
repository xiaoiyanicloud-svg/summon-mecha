<template>
  <view class="scene-page"><view class="scene-nav"><text @tap="back">‹ 返回</text><text class="title">我的服务需求</text><text @tap="create">新需求</text></view>
    <StateView v-if="loading || error" :loading="loading" :error="error" @retry="load" />
    <view v-else class="body"><view class="notice">游客需求依赖本机访问凭证。登录后打开原需求进行认领，认领后可跨设备查询。</view>
      <view v-for="item in rows" :key="item.id" class="card" @tap="open(item.id)"><text class="section">{{ item.request_no }}</text><text class="state">{{ status(item.state) }}</text><text class="muted">更新于 {{ item.updated_at }}</text><text class="link">查看需求、报价与项目 ›</text></view>
      <view v-if="!rows.length" class="empty">暂无已提交需求</view><button v-if="cursor" class="secondary" :disabled="moreLoading" @tap="more">{{ moreLoading ? '加载中…' : '加载更多' }}</button>
      <view v-if="hasDraft" class="card"><text>本机有尚未提交的草稿</text><text class="link" @tap="create">继续填写 ›</text></view>
      <view v-if="guestReceipts.length" class="card"><text class="section">本机游客需求</text><text class="muted">打开后可凭原访问能力认领到当前账号。</text><text v-for="item in guestReceipts" :key="item.id" class="link" @tap="open(item.id)">{{ item.request_no }} ›</text></view>
    </view>
  </view>
</template>
<script>
import StateView from '@/components/StateView.vue'
import { sceneWorkflowApi } from '@/utils/api'
import { sceneAccountScope, sceneLocalState, sceneGuestReceipts, sceneStatusLabel } from '@/utils/scene-request'
export default {
  components: { StateView },
  data() { return { rows: [], loading: false, moreLoading: false, error: '', cursor: null, hasDraft: false, generation: 0, scope: '', guestReceipts: [] } },
  onShow() { this.rows = []; this.scope = sceneAccountScope(); this.load() },
  onUnload() { this.generation++ },
  methods: {
    status: sceneStatusLabel, back() { uni.navigateBack() }, create() { const draft = sceneLocalState().pending.form; uni.navigateTo({ url: '/pages/scene/request' + (draft && draft.payload.scene_id ? '?sceneId=' + draft.payload.scene_id + '&source=scene' : '') }) }, open(id) { uni.navigateTo({ url: '/pages/scene/detail?id=' + id }) },
    async load() {
      const version = ++this.generation, scope = sceneAccountScope()
      this.loading = true; this.error = ''; this.hasDraft = !!sceneLocalState().pending.form
      this.guestReceipts = scope === 'guest' ? [] : sceneGuestReceipts()
      try {
        const local = sceneLocalState()
        if (scope === 'guest' && !local.access) { this.rows = []; this.cursor = null; return }
        const response = await sceneWorkflowApi.list(local.access)
        if (version !== this.generation || scope !== sceneAccountScope()) return
        if (!Array.isArray(response.data)) throw new Error('需求列表暂不可用')
        this.rows = response.data; this.cursor = response.next_cursor
      } catch (e) { if (version === this.generation && scope === sceneAccountScope()) { this.rows = []; this.error = e.message || '加载失败' } }
      finally { if (version === this.generation && scope === sceneAccountScope()) this.loading = false }
    },
    async more() {
      if (this.moreLoading || !this.cursor) return
      const scope = sceneAccountScope(), version = this.generation
      this.moreLoading = true
      try {
        const response = await sceneWorkflowApi.list(sceneLocalState().access, this.cursor)
        if (scope !== sceneAccountScope() || version !== this.generation) return
        if (!Array.isArray(response.data)) throw new Error('需求列表暂不可用')
        this.rows.push(...response.data.filter(item => !this.rows.some(row => row.id === item.id))); this.cursor = response.next_cursor
      } catch (e) { if (scope === sceneAccountScope()) uni.showToast({ title: e.message || '加载失败', icon: 'none' }) }
      finally { this.moreLoading = false }
    }
  }
}
</script>
<style scoped>@import './scene.css';</style>
