<template>
  <scroll-view class="product-area-wrapper" scroll-y="true">
    <SkillMarket ref="market" :active="active" @select="$emit('item-click', $event)" />
  </scroll-view>
</template>
<script>
import SkillMarket from '@/components/SkillMarket.vue'
export default {
  name: 'Jineng', components: { SkillMarket },
  props: { active: { type: Boolean, default: true } }, emits: ['item-click'],
  methods: { refresh() { return this.$refs.market && this.$refs.market.refresh() } },
}
</script>
<style scoped>
.product-area-wrapper { flex: 1; height: 100%; min-height: 0; background: #f5f7fc; }
</style>
