<template>
	<view class="sm-icon-wrap" :style="wrapStyle" aria-hidden="true">
		<image class="sm-icon-img" :src="iconSrc" mode="aspectFit" />
	</view>
</template>

<script>
/**
 * 召唤机甲统一品牌图标组件（兼容 H5 与微信小程序）。
 *
 * 将 /static/icons/mine/ 下的统一品牌双色矢量切图以等比 <image> 渲染，
 * 避免系统 Emoji 在不同终端基线、配色与风格上的割裂感。
 */
export default {
	name: 'SmIcon',
	props: {
		name: {
			type: String,
			required: true,
		},
		size: {
			type: [Number, String],
			default: 40,
		},
	},
	computed: {
		iconSrc() {
			const clean = String(this.name || '').replace(/[^a-zA-Z0-9_-]/g, '');
			return `/static/icons/mine/${clean}.png`;
		},
		wrapStyle() {
			const raw = String(this.size || 40).trim();
			const dim = /^\d+(\.\d+)?$/.test(raw) ? `${raw}rpx` : raw;
			return {
				width: dim,
				height: dim,
			};
		},
	},
};
</script>

<style scoped>
.sm-icon-wrap {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	vertical-align: middle;
	line-height: 1;
}

.sm-icon-img {
	width: 100%;
	height: 100%;
	display: block;
}
</style>
