<template>
  <view class="search-row">
    <view class="search-icon" aria-hidden="true">
      <view class="search-icon-ring"></view>
      <view class="search-icon-handle"></view>
    </view>
    <input
      class="search-input"
      :value="innerValue"
      :placeholder="placeholder"
      placeholder-style="color: #94A3B8"
      confirm-type="search"
      @input="onInput"
      @confirm="$emit('confirm', innerValue)"
    />
    <text v-if="innerValue" class="search-clear" @tap.stop="onClear">✕</text>
  </view>
</template>

<script>
export default {
  name: 'SearchInput',
  props: {
    modelValue: { type: String, default: undefined },
    value: { type: String, default: '' },
    placeholder: { type: String, default: '搜索' },
  },
  emits: ['update:modelValue', 'input', 'confirm', 'clear'],
  computed: {
    innerValue() {
      return this.modelValue !== undefined ? this.modelValue : this.value
    },
  },
  methods: {
    onInput(e) {
      const val = e && e.detail ? String(e.detail.value ?? '') : String(e ?? '')
      this.$emit('update:modelValue', val)
      this.$emit('input', val)
    },
    onClear() {
      this.$emit('update:modelValue', '')
      this.$emit('input', '')
      this.$emit('clear')
    },
  },
}
</script>

<style scoped>
.search-row {
  display: flex;
  align-items: center;
  width: 100%;
  height: 72rpx;
  padding: 0 14rpx 0 22rpx;
  background: #FFFFFF;
  border: 1rpx solid #DCE5F2;
  border-radius: 18rpx;
  box-shadow: 0 4rpx 14rpx rgba(16, 47, 110, 0.05);
  box-sizing: border-box;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.search-row:focus-within {
  border-color: #94B8FF;
  box-shadow: 0 4rpx 16rpx rgba(22, 93, 255, 0.10);
}
.search-icon {
  position: relative;
  width: 26rpx;
  height: 26rpx;
  flex: 0 0 26rpx;
}
.search-icon-ring {
  position: absolute;
  left: 0;
  top: 0;
  width: 18rpx;
  height: 18rpx;
  border: 3rpx solid #6E8AB8;
  border-radius: 50%;
  box-sizing: border-box;
}
.search-icon-handle {
  position: absolute;
  left: 16rpx;
  top: 16rpx;
  width: 10rpx;
  height: 3rpx;
  border-radius: 2rpx;
  background: #6E8AB8;
  transform: rotate(45deg);
  transform-origin: left center;
}
.search-input {
  flex: 1;
  min-width: 0;
  height: 100%;
  padding: 0 12rpx;
  background: transparent;
  border: 0;
  font-size: 25rpx;
  color: #1F2937;
  box-sizing: border-box;
}
.search-clear {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40rpx;
  height: 40rpx;
  flex: 0 0 40rpx;
  border-radius: 50%;
  background: #F1F5F9;
  font-size: 22rpx;
  color: #64748B;
}
.search-clear:active {
  background: #E2E8F0;
}
</style>
