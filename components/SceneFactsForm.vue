<template>
  <view><view v-for="field in schema.fields" :key="field.key" class="scene-field">
    <label :for="prefix + field.key">{{ field.label }}{{ field.unit ? '（' + field.unit + '）' : '' }}</label>
    <picker v-if="field.type === 'boolean'" :range="['待核实','是','否']" :value="booleanIndex(facts[field.key])" @change="set(field.key,Number($event.detail.value) === 0 ? null : Number($event.detail.value) === 1)"><view class="scene-picker">{{ ['待核实','是','否'][booleanIndex(facts[field.key])] }} ›</view></picker>
    <picker v-else-if="field.type === 'choice'" :range="choiceLabels(field)" :value="choiceIndex(field)" @change="set(field.key, Number($event.detail.value) ? field.options[Number($event.detail.value)-1].value : null)"><view class="scene-picker">{{ choiceLabels(field)[choiceIndex(field)] }} ›</view></picker>
    <input v-else :id="prefix + field.key" :value="facts[field.key]" :type="field.type === 'integer' ? 'number' : (field.type === 'decimal' ? 'digit' : 'text')" :maxlength="['integer','decimal'].includes(field.type) ? 16 : (field.max || 2000)" :placeholder="placeholder(field)" @input="set(field.key,$event.detail.value)" />
    <text v-if="field.type === 'decimal'" class="scene-hint">最多三位小数；未核实可留空。</text>
  </view></view>
</template>
<script>
export default {
  props: { schema: { type: Object, required: true }, facts: { type: Object, required: true }, prefix: { type: String, default: 'scene-fact-' } },
  emits: ['change'],
  methods: {
    set(key,value) { this.$emit('change',{ ...this.facts, [key]: value }) },
    booleanIndex(v) { return v === true || v === 1 ? 1 : (v === false || v === 0 ? 2 : 0) },
    choiceLabels(field) { return ['待核实', ...field.options.map(option=>option.label)] },
    choiceIndex(field) { return field.options.findIndex(option=>option.value===this.facts[field.key])+1 },
    placeholder(field) { return field.key.endsWith('_at') ? '例：2026-11-01T10:00:00+08:00' : (field.key==='timezone'?'例：Asia/Shanghai':'暂不明确可留空') }
  }
}
</script>
<style scoped>.scene-field{margin-top:24rpx}.scene-picker{padding:20rpx;background:#f5f7fb;border-radius:12rpx;margin-top:12rpx}.scene-hint{font-size:23rpx;color:#7a8498}</style>
