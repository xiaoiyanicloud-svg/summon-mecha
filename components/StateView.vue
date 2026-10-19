<template>
	<!--
	  三态容器 —— loading / error / empty 分离渲染,数据就绪时才渲染默认插槽。

	  ⚠ 它存在的唯一理由:**故障不得伪装成「你没有数据」**。
	    把接口 500 / 断网 / token 失效渲染成「暂无工单」,是客服排障里最贵的误判 ——
	    用户说「没看到工单」,其实是服务端挂了。所以 error 与 empty 必须是两支,
	    且 error 支必须给出「重试」。

	  用法:
	    <state-view :loading="loading" :error="loadError" :empty="!list.length"
	                empty-icon="🛠️" empty-text="暂无工单" @retry="refresh">
	      <view v-for="t in list" :key="t.id">…</view>
	    </state-view>

	  优先级:loading > error > empty > 插槽内容。
	-->
	<view class="state-view" :class="{ compact: compact }">
		<view v-if="loading" class="sv-box">
			<text class="sv-icon">⏳</text>
			<text class="sv-text">{{ loadingText }}</text>
		</view>

		<view v-else-if="error" class="sv-box">
			<text class="sv-icon">{{ errorIcon }}</text>
			<text class="sv-text">{{ errorText || '数据没能加载' }}</text>
			<text v-if="errorDetail" class="sv-hint">{{ errorDetail }}</text>
			<view v-if="retry" class="sv-btn" role="button" tabindex="0" :style="{ background: btnColor }" @click="$emit('retry')">
				{{ retryText }}
			</view>
		</view>

		<view v-else-if="empty" class="sv-box">
			<text v-if="emptyIcon" class="sv-icon">{{ emptyIcon }}</text>
			<text class="sv-text">{{ emptyText }}</text>
			<text v-if="emptyHint" class="sv-hint">{{ emptyHint }}</text>
			<view v-if="emptyAction" class="sv-btn" role="button" tabindex="0" :style="{ background: btnColor }" @click="$emit('empty-action')">
				{{ emptyActionText }}
			</view>
		</view>

		<slot v-else />
	</view>
</template>

<script>
/**
 * 加载 / 故障 / 空 三态视图(uni-app Options API)。
 *
 * 设计取舍:
 * - 用**默认插槽**承载「有数据」的内容,调用方无需在外面再写 v-if/v-else ——
 *   否则每个页面都要把「三态判断」抄一遍,迟早抄歪。
 * - 配色默认走项目主色 #165DFF,但用 `btnColor` 暴露出去 ——
 *   mine/tickets、mine/incidents 两页是橙色系(#ff6b00),不能强行统一成蓝色。
 */
export default {
	name: 'StateView',
	props: {
		// 正在加载
		loading: { type: Boolean, default: false },
		// 故障消息(真值即进入 error 态)。这里是**故障**,不是「空」
		error: { type: [String, Boolean], default: '' },
		// 「故障」标题文案;不传则用默认
		errorText: { type: String, default: '' },
		// 故障图标;404/410/503 可用不同图标,避免只靠颜色/文案区分
		errorIcon: { type: String, default: '📡' },

		// 「真的没有数据」—— 只在**请求成功但结果为空**时为真
		empty: { type: Boolean, default: false },
		emptyText: { type: String, default: '暂无数据' },
		emptyIcon: { type: String, default: '📭' },
		emptyHint: { type: String, default: '' },
		// 空态是否带一个行动按钮(如「去逛逛」「新增地址」)
		emptyAction: { type: Boolean, default: false },
		emptyActionText: { type: String, default: '去逛逛' },

		// 加载文案 / 空态是否紧凑(列表区块内用)
		loadingText: { type: String, default: '加载中…' },
		compact: { type: Boolean, default: false },

		// 故障态是否给「重试」按钮
		retry: { type: Boolean, default: true },
		retryText: { type: String, default: '重试' },
		btnColor: { type: String, default: '#165DFF' },
	},
	computed: {
		// error 可能是 true(Boolean)也可能是一段消息(String)
		errorDetail() {
			return typeof this.error === 'string' ? this.error : ''
		},
	},
}
</script>

<style scoped>
.state-view { width: 100%; }
.sv-box {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 120rpx 40rpx;
}
/* 列表区块内的紧凑态 */
.state-view.compact .sv-box { padding: 48rpx 40rpx; }
.sv-icon { font-size: 76rpx; margin-bottom: 20rpx; }
.sv-text { font-size: 28rpx; color: #8A9AA8; text-align: center; }
.sv-hint {
	margin-top: 14rpx;
	padding: 0 30rpx;
	font-size: 24rpx;
	color: #B0BAC5;
	line-height: 1.6;
	text-align: center;
}
.sv-btn {
	margin-top: 32rpx;
	height: 76rpx;
	line-height: 76rpx;
	padding: 0 56rpx;
	background: #165DFF;
	color: #FFFFFF;
	font-size: 28rpx;
	font-weight: 600;
	border-radius: 38rpx;
	text-align: center;
}
</style>
