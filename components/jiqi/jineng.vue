<template>
  <scroll-view class="product-area-wrapper" scroll-y="true" :enhanced="true" :show-scrollbar="false">
    <SkillMarket
      ref="market"
      :active="active"
      :show-search="showSearch"
      :external-query="externalQuery"
      @update:external-query="$emit('update:external-query', $event)"
      @select="$emit('item-click', $event)"
    />
  </scroll-view>
</template>
<script>
import SkillMarket from '@/components/SkillMarket.vue'
export default {
  name: 'Jineng',
  components: { SkillMarket },
  props: {
    active: { type: Boolean, default: true },
    showSearch: { type: Boolean, default: true },
    externalQuery: { type: String, default: undefined },
  },
  emits: ['item-click', 'update:external-query'],
  methods: {
    refresh() { return this.$refs.market && this.$refs.market.refresh() },
    onExternalInput(val) { return this.$refs.market && this.$refs.market.setExternalQuery(val) },
    confirmSearch(val) { return this.$refs.market && this.$refs.market.confirmExternalSearch(val) },
    clearSearch() { return this.$refs.market && this.$refs.market.clearSearch() },
  },
}
</script>
<style scoped>
.product-area-wrapper {
  flex: 1;
  height: 100%;
  min-height: 0;
  background: #f5f7fc;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.product-area-wrapper::-webkit-scrollbar {
  display: none;
  width: 0;
  height: 0;
}
</style>
