<template>
	<!--
	  统一导航栏 —— 收敛原先散落在 12+ 页面里、逐字雷同的 `.nav-bar`。

	  ⚠ 设计取舍（有意为之,别"顺手统一"掉）:

	  1. **不合并视觉皮肤**。原页面出现 3 类皮肤,差异是真实存在的,不是笔误:
	     - `default`(9 页,高度 88rpx 白底):about / agent-setup / deploy /
	       incident-detail / incidents / legal / notifications / tickets / ticket-detail
	     - `safe`(devices / skills):顶部 `calc(80rpx + env(safe-area-inset-top))`,
	       刘海屏下导航栏不会被状态栏压住。**删掉这段 padding 就是真事故**,
	       所以两页保留自己的皮肤。
	     - `pill`(order-detail):返回键是 72rpx 圆形浅底按钮,标题 flex:1 居中。
	     - `sticky`(jineng):sticky 吸顶、px 单位、带 border-bottom。
	     - `frost`(rmfangan):半透明 + backdrop-blur 磨砂底、左侧带"返回"文字。
	   把它们强行合成一种皮肤 = 一次无截图为证的视觉回归。故按 variant 透出。

	  2. **返回行为已统一**:14 个页面原本都只是 `uni.navigateBack`。组件默认即此,
	     页面无需再定义 `goBack`。个别页面的**空状态"返回"按钮**仍调用各自的
	     `goBack`(那是页面级交互,不是导航栏),故这些页保留 goBack 方法。

	  3. **右侧用插槽**:jineng 的分享、rmfangan 的装饰图标是页面自己的内容,
	     `#right` 插槽交还给页面(插槽命中的是父作用域样式,所以分享图标样式留在页面里)。

	  用法:
	    <nav-bar title="我的技能" />
	    <nav-bar :title="docTitle" variant="safe" />
	    <nav-bar title="技能详情" variant="sticky"><template #right>…</template></nav-bar>
	-->
	<view class="nav-bar" :class="'nav-variant-' + variant">
		<view class="nav-back" @click="onBack">
			<text class="back-icon">‹</text>
			<text v-if="backText" class="back-text">{{ backText }}</text>
		</view>
		<text class="nav-title">{{ title }}</text>
		<view class="nav-right">
			<slot name="right" />
		</view>
	</view>
</template>

<script>
/**
 * 统一导航栏（uni-app Options API）。
 *
 * - 返回键默认 `uni.navigateBack({ delta: 1 })`；同时 `$emit('back')` 供页面接管
 *   （若父组件绑定了 `@back`，则不再走默认返回，避免"处理了又被弹一次"）。
 */
export default {
	name: 'NavBar',
	props: {
		// 居中标题。留空则不显示（如 rmfangan 无标题）
		title: { type: String, default: '' },
		// 视觉皮肤：default | safe | pill | sticky | frost（见模板注释）
		variant: { type: String, default: 'default' },
		// 返回键旁的文字（仅 rmfangan 用："返回"）
		backText: { type: String, default: '' },
	},
	methods: {
		onBack() {
			// uni-app 没有稳定的"是否绑定了监听器"判断，这里用 $attrs 上的 onBack 探测；
			// 探测不到就按默认返回上一页。
			const handled = !!(this.$attrs && this.$attrs.onBack)
			this.$emit('back')
			if (!handled) {
				uni.navigateBack({ delta: 1 })
			}
		},
	},
}
</script>

<style scoped>
/* ===== 结构（所有皮肤共用） ===== */
.nav-bar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	box-sizing: border-box;
}
.nav-title {
	font-weight: 600;
}

/* ===== default：普通白底导航栏（高度 88rpx） ===== */
.nav-variant-default {
	height: 88rpx;
	padding: 0 24rpx;
	background: #fff;
}
.nav-variant-default .nav-back { width: 60rpx; }
.nav-variant-default .back-icon { font-size: 48rpx; color: #333; }
.nav-variant-default .nav-title { font-size: 32rpx; font-weight: 600; color: #222; }
.nav-variant-default .nav-right { width: 60rpx; }

/* ===== safe：顶到安全区下方（刘海屏不被状态栏压住） ===== */
.nav-variant-safe {
	padding: calc(80rpx + env(safe-area-inset-top)) 30rpx 20rpx;
	background: #FFFFFF;
}
.nav-variant-safe .nav-back { width: 60rpx; }
.nav-variant-safe .back-icon { font-size: 48rpx; color: #1A2A3A; line-height: 1; }
.nav-variant-safe .nav-title { font-size: 34rpx; font-weight: 600; color: #1A2A3A; }
.nav-variant-safe .nav-right { width: 60rpx; }

/* ===== pill：圆形浅底返回键（order-detail） ===== */
.nav-variant-pill {
	padding: 88rpx 24rpx 20rpx;
	background-color: #ffffff;
	border-bottom: 1rpx solid #f1f5f9;
	flex-shrink: 0;
}
.nav-variant-pill .nav-back {
	width: 72rpx;
	height: 72rpx;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	background: #f8faff;
}
.nav-variant-pill .nav-back:active { background: #e8edf3; }
.nav-variant-pill .back-icon { font-size: 48rpx; font-weight: 300; color: #1e293b; }
.nav-variant-pill .nav-title { flex: 1; text-align: center; font-size: 34rpx; font-weight: 600; color: #1e293b; }
.nav-variant-pill .nav-right { width: 72rpx; }

/* ===== sticky：吸顶（jineng，原样式用 px） ===== */
.nav-variant-sticky {
	padding: 12px 20px;
	background-color: #ffffff;
	position: sticky;
	top: 0;
	z-index: 10;
	border-bottom: 1px solid #eee;
}
.nav-variant-sticky .nav-back { width: 60px; font-size: 28px; color: #333; text-align: left; }
.nav-variant-sticky .back-icon { font-weight: 300; }
.nav-variant-sticky .nav-title { font-size: 18px; font-weight: 600; color: #222; }
.nav-variant-sticky .nav-right { width: 60px; font-size: 28px; color: #333; text-align: right; }

/* ===== frost：半透明磨砂底 + 左侧"返回"文字（rmfangan） ===== */
.nav-variant-frost {
	flex-shrink: 0;
	height: 88rpx;
	padding: 0 20rpx;
	background: rgba(255, 255, 255, 0.9);
	backdrop-filter: blur(10rpx);
	box-shadow: 0 2rpx 12rpx rgba(15, 23, 42, 0.04);
}
.nav-variant-frost .nav-back { width: 76rpx; display: flex; align-items: center; }
/* 原 rmfangan 里 .nav-back 规则无对应元素（死规则），返回箭头实际走默认字号；
   此处按现状保留，不做"修正"。 */
.nav-variant-frost .back-icon { font-weight: 300; color: #0F172A; }
.nav-variant-frost .back-text { font-size: 26rpx; color: #334155; margin-left: 4rpx; }
.nav-variant-frost .nav-title { flex: 1; text-align: center; font-size: 32rpx; font-weight: 600; color: #0F172A; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.nav-variant-frost .nav-right { width: 76rpx; display: flex; align-items: center; justify-content: flex-end; }
</style>
