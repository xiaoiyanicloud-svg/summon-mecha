<template>
  <view class="legacy-scene-page">
    <NavBar variant="frost" back-text="返回" />
    <StateView :loading="loading" :error="error" :empty="!loading && !error" empty-text="该方案链接已失效" empty-hint="请从已发布场景中重新选择" :empty-action="true" empty-action-text="查看场景" @empty-action="openScenes" @retry="resolveScene" />
  </view>
</template>
<script>
import NavBar from '@/components/NavBar.vue'
import StateView from '@/components/StateView.vue'
import { sceneId } from '@/utils/scene-catalog'
import { loadBackendScenes, sceneProducts } from '@/components/jiqi/changjing-data'
export default {
  components: { NavBar, StateView },
  data() { return { loading: true, error: '', legacyId: null, legacyTitle: '' } },
  onLoad(options) {
    this.legacyId = sceneId(options.sceneId)
    try { this.legacyTitle = decodeURIComponent(options.title || '') } catch (e) { this.legacyTitle = '' }
    this.resolveScene()
  },
  methods: {
    async resolveScene() {
      this.loading = true; this.error = ''
      try {
        await loadBackendScenes({ force: true })
        const matches = sceneProducts.filter(s => this.legacyId ? s.id === this.legacyId : s.name === this.legacyTitle)
        if (matches.length === 1) uni.redirectTo({ url: '/pages/robot/fangan?sceneId=' + matches[0].id })
      } catch (e) { this.error = e.message || '场景加载失败，请重试' }
      finally { this.loading = false }
    },
    openScenes() { uni.setStorageSync('robotTab', 'scene'); uni.switchTab({ url: '/pages/robot/index' }) }
  }
}
</script>
<style scoped>
.legacy-scene-page { min-height: 100vh; background: #f5f7fa; padding-top: 100rpx; }
</style>
