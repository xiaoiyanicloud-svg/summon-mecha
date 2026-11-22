<template>
	<view class="sm-loading" :class="[inline ? 'is-inline' : 'is-block', 'is-' + size]">
		<view class="sm-spinner">
			<view class="sm-ring-track"></view>
			<view class="sm-ring-arc"></view>
			<view v-if="!inline" class="sm-ring-inner"></view>
			<image
				v-if="!inline"
				class="sm-ring-core"
				src="/static/icons/mine/state-spark.png"
				mode="aspectFit"
			/>
		</view>
		<text v-if="text" class="sm-loading-text">{{ text }}</text>
	</view>
</template>

<script>
export default {
	name: 'SmLoading',
	props: {
		text: { type: String, default: '' },
		size: { type: String, default: 'md' },
		inline: { type: Boolean, default: false },
	},
}
</script>

<style scoped>
.sm-loading {
	display: flex;
	align-items: center;
	justify-content: center;
}

.sm-loading.is-block {
	flex-direction: column;
}

.sm-loading.is-inline {
	flex-direction: row;
	gap: 12rpx;
}

.sm-spinner {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 76rpx;
	height: 76rpx;
	flex-shrink: 0;
}

.sm-loading.is-lg .sm-spinner {
	width: 92rpx;
	height: 92rpx;
}

.sm-loading.is-sm .sm-spinner {
	width: 34rpx;
	height: 34rpx;
}

.sm-ring-track,
.sm-ring-arc {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	border-radius: 50%;
	box-sizing: border-box;
}

.sm-ring-track {
	border: 6rpx solid rgba(22, 93, 255, 0.14);
}

.sm-ring-arc {
	border: 6rpx solid transparent;
	border-top-color: #165DFF;
	border-right-color: #38BDF8;
	animation: smSpin 0.88s linear infinite;
}

.sm-loading.is-sm .sm-ring-track {
	border-width: 4rpx;
}

.sm-loading.is-sm .sm-ring-arc {
	border-width: 4rpx;
}

.sm-ring-inner {
	position: absolute;
	top: 12rpx;
	left: 12rpx;
	right: 12rpx;
	bottom: 12rpx;
	border-radius: 50%;
	box-sizing: border-box;
	border: 3rpx solid transparent;
	border-bottom-color: rgba(16, 47, 110, 0.48);
	animation: smSpinReverse 1.35s linear infinite;
}

.sm-loading.is-lg .sm-ring-inner {
	top: 14rpx;
	left: 14rpx;
	right: 14rpx;
	bottom: 14rpx;
}

.sm-ring-core {
	width: 34rpx;
	height: 34rpx;
	animation: smPulse 1.5s ease-in-out infinite;
}

.sm-loading.is-lg .sm-ring-core {
	width: 42rpx;
	height: 42rpx;
}

.sm-loading-text {
	font-size: 28rpx;
	color: #64748B;
	line-height: 1.5;
	text-align: center;
}

.sm-loading.is-block .sm-loading-text {
	margin-top: 20rpx;
}

.sm-loading.is-inline .sm-loading-text {
	font-size: 24rpx;
	color: #64748B;
	margin-top: 0;
}

@keyframes smSpin {
	0% {
		transform: rotate(0deg);
	}
	100% {
		transform: rotate(360deg);
	}
}

@keyframes smSpinReverse {
	0% {
		transform: rotate(360deg);
	}
	100% {
		transform: rotate(0deg);
	}
}

@keyframes smPulse {
	0%, 100% {
		transform: scale(0.92);
		opacity: 0.82;
	}
	50% {
		transform: scale(1.08);
		opacity: 1;
	}
}
</style>
