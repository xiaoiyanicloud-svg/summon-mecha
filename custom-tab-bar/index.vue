<template>
  <!-- 空占位自定义 tabBar：pages.json tabBar 设 custom:true 后，微信端不再渲染原生 tabBar。
    这里渲染 0 高度透明元素，不占位、不显示，真正底部导航由各页面内部的悬浮导航承担。 -->
  <view class="ctb-root"></view>
</template>

<script>
export default {
  name: 'CustomTabBar',
  data() {
    return {
      selected: 0,
      list: []
    }
  }
}
</script>

<style>
.ctb-root {
  width: 100%;
  height: 0;
  padding: 0;
  margin: 0;
  background: transparent;
  overflow: hidden;
  box-sizing: border-box;
}
</style>
