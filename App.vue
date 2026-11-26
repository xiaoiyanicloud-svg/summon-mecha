<script>
	export default {
		onLaunch: function() {
			console.log('App Launch')
		},
		onShow: function() {
			console.log('App Show')
		},
		onHide: function() {
			console.log('App Hide')
		}
	}
</script>

<style>
	/* 召唤机甲 · 平台级克制工业蓝白设计令牌（去 AI 化人类友好基线） */
	page {
		--sm-navy: #102F6E;
		--sm-navy-deep: #0A1E46;
		--sm-blue: #165DFF;
		--sm-blue-hover: #0E42D2;
		--sm-blue-soft: #EEF4FF;
		--sm-bg: #F4F7FB;
		--sm-surface: #FFFFFF;
		--sm-surface-muted: #F8FAFC;
		--sm-border: #E5EAF2;
		--sm-text-primary: #0F172A;
		--sm-text-secondary: #475569;
		--sm-text-muted: #94A3B8;
		--sm-success: #059669;
		--sm-success-soft: #ECFDF5;
		--sm-warning: #D97706;
		--sm-warning-soft: #FFFBEB;
		--sm-danger: #DC2626;
		--sm-danger-soft: #FEF2F2;
		background-color: #F4F7FB;
		color: #0F172A;
		font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
		-webkit-font-smoothing: antialiased;
	}

	.sm-mono {
		font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
		font-variant-numeric: tabular-nums;
	}
</style>
