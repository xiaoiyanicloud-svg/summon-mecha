<template>
	<view class="page-container">
		<!-- 1. 顶部导航 -->
		<view class="nav-bar">
			<view class="nav-left" @click="goBack">
				<text class="icon-back">‹</text>
			</view>
			<view class="nav-title">技能详情</view>
			<view class="nav-right" @click="handleShare">
				<text class="icon-share">↗</text>
			</view>
		</view>

		<!-- 2. 轮播图 -->
		<swiper class="swiper" indicator-dots autoplay interval="3000" circular>
			<swiper-item v-for="(img, idx) in skillInfo.images" :key="idx">
				<image class="swiper-image" :src="img" mode="aspectFill"></image>
			</swiper-item>
		</swiper>
		<view class="swiper-badge">🔥 已服务 {{ skillInfo.served }} 家庭</view>

		<!-- 3. 基础信息 -->
		<view class="info-card">
			<view class="info-row">
				<text class="skill-name">{{ skillInfo.name }}</text>
				<text class="skill-rating">⭐ {{ skillInfo.rating }}</text>
			</view>
			<view class="price-row">
				<text class="price-current">￥{{ skillInfo.price }}/年</text>
				<text class="price-original">￥{{ skillInfo.originalPrice }}</text>
			</view>
			<text class="skill-desc">{{ skillInfo.desc }}</text>
		</view>

		<!-- 4. Tab -->
		<view class="tab-bar">
			<view class="tab-item" :class="{ active: currentTab === 0 }" @click="currentTab = 0">功能说明</view>
			<view class="tab-item" :class="{ active: currentTab === 1 }" @click="currentTab = 1">用户评价 ({{ skillInfo.comments.length }})</view>
		</view>

		<!-- 5. Tab内容 -->
		<view class="tab-content">
			<view v-show="currentTab === 0" class="content-panel">
				<text class="feature-text">{{ skillInfo.features }}</text>
			</view>
			<view v-show="currentTab === 1" class="content-panel">
				<view class="comment-item" v-for="(item, idx) in skillInfo.comments" :key="idx">
					<image class="comment-avatar" :src="item.avatar" mode="aspectFill"></image>
					<view class="comment-body">
						<text class="comment-nick">{{ item.nick }}</text>
						<text class="comment-text">{{ item.content }}</text>
					</view>
				</view>
				<view v-if="skillInfo.comments.length === 0" class="empty-tip">暂无评价</view>
			</view>
		</view>

		<!-- 6. 底部操作栏 -->
		<view class="footer-bar">
			<view class="footer-left" @click="openCustomer">
				<text class="icon-service">🛎️</text>
				<text class="service-label">客服</text>
			</view>
			<view class="footer-actions">
				<button class="btn-price" @click="handlePrice">￥{{ skillInfo.price }}/年</button>
				<button class="btn-buy" @click="handleBuy">立即开通</button>
			</view>
		</view>
	</view>
</template>

<script>
	export default {
		data() {
			return {
				currentTab: 0,
				skillId: '',
				skillInfo: {
					name: '加载中...',
					rating: '',
					price: 0,
					originalPrice: 0,
					desc: '',
					served: '',
					images: [],
					features: '',
					comments: []
				}
			}
		},
		onLoad(options) {
			this.skillId = options.skillId || '20'
			this.loadSkillData(this.skillId)
		},
		methods: {
			loadSkillData(id) {
				const data = this.getMockData(id)
				if (data) {
					this.skillInfo = data
				} else {
					uni.showToast({
						title: '该技能详情开发中',
						icon: 'none',
						duration: 2000
					})
					setTimeout(() => {
						uni.navigateBack()
					}, 2000)
				}
			},
			getMockData(id) {
				const db = {
					'20': {
						name: '智能叠衣',
						rating: '4.9',
						price: 299,
						originalPrice: 599,
						desc: 'AI视觉识别棉麻丝绸，3分钟叠完一家三口衣物。',
						served: '1.2万+',
						images: [
							'https://picsum.photos/seed/dieyi/750/400',
							'https://picsum.photos/seed/clothes/750/400'
						],
						features: '✅ 支持棉、麻、丝绸、化纤等多种面料识别\n✅ 智能温控蒸汽除菌，除螨率99.9%\n✅ OTA空中升级，技能持续进化\n✅ 适配机甲X3 / X5 / X7系列',
						comments: [
							{ avatar: 'https://picsum.photos/seed/user1/100/100', nick: '张*妈', content: '解放双手，叠得比我还整齐！' }
						]
					},
					'21': {
						name: '全屋扫地',
						rating: '4.8',
						price: 199,
						originalPrice: 399,
						desc: '激光雷达导航，全屋无死角清扫，自动回充。',
						served: '5.6万+',
						images: [
							'https://picsum.photos/seed/sweep/750/400',
							'https://picsum.photos/seed/clean/750/400'
						],
						features: '✅ LDS激光导航，精准建图\n✅ 5000Pa大吸力，灰尘无处可逃\n✅ 自动回充续扫，省心省力\n✅ 支持地毯增压、悬崖防跌落',
						comments: [
							{ avatar: 'https://picsum.photos/seed/user2/100/100', nick: '李*生', content: '扫得很干净，床底都能进去！' },
							{ avatar: 'https://picsum.photos/seed/user4/100/100', nick: '赵*姐', content: '解放双手，每天干干净净' }
						]
					},
					'22': {
						name: '厨房助手',
						rating: '4.7',
						price: 399,
						originalPrice: 699,
						desc: '智能菜谱推荐，自动控温翻炒，做饭不再手忙脚乱。',
						served: '3.8万+',
						images: [
							'https://picsum.photos/seed/kitchen/750/400',
							'https://picsum.photos/seed/cook/750/400'
						],
						features: '✅ 内置200+道智能菜谱，天天不重样\n✅ 自动翻炒、精准控温，堪比大厨\n✅ 油烟自清洁，清洗超方便\n✅ 支持语音控制，解放双手',
						comments: [
							{ avatar: 'https://picsum.photos/seed/user3/100/100', nick: '王*姐', content: '做饭变得好简单，孩子都爱吃！' }
						]
					},
					'23': {
						name: '园艺修剪',
						rating: '4.6',
						price: 249,
						originalPrice: 499,
						desc: '智能识别植物种类，自动修剪、浇水，打造私家花园。',
						served: '0.8万+',
						images: [
							'https://picsum.photos/seed/garden/750/400',
							'https://picsum.photos/seed/plant/750/400'
						],
						features: '✅ AI识别200+种植物\n✅ 自动修剪、浇水、施肥提醒\n✅ 太阳能充电，节能环保\n✅ 手机远程监控花园状态',
						comments: [
							{ avatar: 'https://picsum.photos/seed/user5/100/100', nick: '刘*叔', content: '我的花园全靠它打理！' }
						]
					},
					'24': {
						name: '宠物管家',
						rating: '4.9',
						price: 349,
						originalPrice: 599,
						desc: '自动喂食、铲屎、陪玩，让宠物不再孤单。',
						served: '2.3万+',
						images: [
							'https://picsum.photos/seed/pet/750/400',
							'https://picsum.photos/seed/cat/750/400'
						],
						features: '✅ 定时定量自动喂食\n✅ 智能铲屎，除臭抗菌\n✅ 激光逗猫棒，自动陪玩\n✅ 高清摄像头，实时查看宠物动态',
						comments: [
							{ avatar: 'https://picsum.photos/seed/user6/100/100', nick: '陈*姐', content: '出差再也不用担心猫猫了！' },
							{ avatar: 'https://picsum.photos/seed/user7/100/100', nick: '周*哥', content: '铲屎功能太实用了！' }
						]
					},
					'25': {
						name: '窗户清洁',
						rating: '4.7',
						price: 259,
						originalPrice: 459,
						desc: '高层外窗自动擦洗，安全高效，玻璃光亮如新。',
						served: '0.6万+',
						images: [
							'https://picsum.photos/seed/window/750/400',
							'https://picsum.photos/seed/glass/750/400'
						],
						features: '✅ 双面擦窗，内外同时清洁\n✅ 智能探测边框，防跌落\n✅ 支持语音控制，一键启动\n✅ 续航长达90分钟',
						comments: [
							{ avatar: 'https://picsum.photos/seed/user8/100/100', nick: '孙*姐', content: '擦得真干净，再也不用请人擦窗了！' }
						]
					}
				}
				return db[id] || null
			},
			goBack() {
				uni.navigateBack()
			},
			handleShare() {
				uni.showToast({ title: '分享功能开发中', icon: 'none' })
			},
			openCustomer() {
				uni.showToast({ title: '联系客服：400-888-9999', icon: 'none' })
			},
			handlePrice() {
				uni.showModal({
					title: '价格',
					content: `「${this.skillInfo.name}」价格：￥${this.skillInfo.price}/年，原价 ￥${this.skillInfo.originalPrice}\n点击「立即开通」即可购买并确认订单。`,
					showCancel: false
				})
			},
			handleBuy() {
				// 开通 → 进入确认订单（复用商品购买下单逻辑）
				const skill = this.skillInfo
				const url =
					`/pages/order/checkout?productId=${this.skillId}` +
					`&name=${encodeURIComponent(skill.name)}` +
					`&price=${encodeURIComponent(String(skill.price || 0))}` +
					`&icon=${encodeURIComponent('🧠')}` +
					`&bg=${encodeURIComponent('#E8F0FE')}` +
					`&scene=${encodeURIComponent('技能服务')}&type=buy&qty=1`
				uni.navigateTo({ url })
			}
		}
	}
</script>

<style lang="scss" scoped>
	.page-container {
		display: flex;
		flex-direction: column;
		min-height: 100vh;
		background-color: #f8f8f8;
		padding-bottom: 100px;
	}
	.nav-bar {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 12px 20px;
		background-color: #ffffff;
		position: sticky;
		top: 0;
		z-index: 10;
		border-bottom: 1px solid #eee;
	}
	.nav-left, .nav-right {
		width: 60px;
		font-size: 28px;
		color: #333;
	}
	.nav-left { text-align: left; }
	.nav-right { text-align: right; }
	.nav-title {
		font-size: 18px;
		font-weight: 600;
		color: #222;
	}
	.icon-back { font-weight: 300; }
	.icon-share { font-weight: 300; }
	.swiper {
		width: 100%;
		height: 400rpx;
		position: relative;
	}
	.swiper-image {
		width: 100%;
		height: 103%;
	}
	.swiper-badge {
		position: absolute;
		top: 20rpx;
		right: 20rpx;
		background: rgba(0,0,0,0.6);
		color: #fff;
		padding: 6rpx 20rpx;
		border-radius: 30rpx;
		font-size: 24rpx;
		z-index: 2;
		margin-top: 10px;
	}
	.info-card {
		background: #fff;
		margin: 20rpx 30rpx;
		padding: 30rpx;
		border-radius: 20rpx;
		box-shadow: 0 2rpx 12rpx rgba(0,0,0,0.04);
	}
	.info-row {
		display: flex;
		justify-content: space-between;
		align-items: center;
	}
	.skill-name {
		font-size: 36rpx;
		font-weight: 600;
		color: #1a1a1a;
	}
	.skill-rating {
		font-size: 28rpx;
		color: #f5a623;
	}
	.price-row {
		margin: 16rpx 0 12rpx;
	}
	.price-current {
		font-size: 40rpx;
		font-weight: 700;
		color: #ff4d4f;
		margin-right: 16rpx;
	}
	.price-original {
		font-size: 28rpx;
		color: #999;
		text-decoration: line-through;
	}
	.skill-desc {
		font-size: 28rpx;
		color: #666;
		line-height: 1.6;
		display: block;
		margin-top: 8rpx;
	}
	.tab-bar {
		display: flex;
		background: #fff;
		margin: 0 30rpx;
		border-radius: 20rpx 20rpx 0 0;
		border-bottom: 2rpx solid #f0f0f0;
	}
	.tab-item {
		flex: 1;
		text-align: center;
		padding: 28rpx 0 20rpx;
		font-size: 28rpx;
		color: #888;
		position: relative;
		transition: color 0.2s;
	}
	.tab-item.active {
		color: #ff4d4f;
		font-weight: 600;
	}
	.tab-item.active::after {
		content: '';
		position: absolute;
		bottom: 0;
		left: 50%;
		transform: translateX(-50%);
		width: 50rpx;
		height: 6rpx;
		background: #ff4d4f;
		border-radius: 6rpx;
	}
	.tab-content {
		background: #fff;
		margin: 0 30rpx 30rpx;
		padding: 30rpx;
		border-radius: 0 0 20rpx 20rpx;
		flex: 1;
		min-height: 300rpx;
	}
	.feature-text {
		font-size: 28rpx;
		color: #333;
		line-height: 1.8;
		white-space: pre-line;
	}
	.comment-item {
		display: flex;
		align-items: flex-start;
		padding: 20rpx 0;
		border-bottom: 1rpx solid #f5f5f5;
	}
	.comment-item:last-child {
		border-bottom: none;
	}
	.comment-avatar {
		width: 60rpx;
		height: 60rpx;
		border-radius: 50%;
		margin-right: 20rpx;
		flex-shrink: 0;
		background: #eee;
	}
	.comment-body {
		flex: 1;
		display: flex;
		flex-direction: column;
	}
	.comment-nick {
		font-size: 26rpx;
		color: #888;
		margin-bottom: 6rpx;
	}
	.comment-text {
		font-size: 28rpx;
		color: #333;
		line-height: 1.5;
	}
	.empty-tip {
		text-align: center;
		color: #bbb;
		padding: 60rpx 0;
	}
	.footer-bar {
		position: fixed;
		bottom: 0;
		left: 0;
		right: 0;
		background: #ffffff;
		padding: 20rpx 30rpx;
		padding-bottom: calc(20rpx + env(safe-area-inset-bottom));
		display: flex;
		align-items: center;
		justify-content: space-between;
		box-shadow: 0 -4rpx 20rpx rgba(0,0,0,0.06);
		z-index: 20;
	}
	.footer-left {
		display: flex;
		flex-direction: column;
		align-items: center;
		margin-right: 20rpx;
	}
	.icon-service {
		font-size: 40rpx;
	}
	.service-label {
		font-size: 22rpx;
		color: #666;
		margin-top: 4rpx;
	}
	.footer-actions {
		display: flex;
		flex: 1;
		gap: 20rpx;
	}
	.btn-price, .btn-buy {
		flex: 1;
		height: 76rpx;
		line-height: 76rpx;
		border-radius: 40rpx;
		font-size: 28rpx;
		font-weight: 500;
		border: none;
		text-align: center;
		padding: 0;
	}
	.btn-price {
		background: #fff7f7;
		color: #ff4d4f;
		border: 1rpx solid #ffd6d6;
		font-weight: 600;
	}
	.btn-buy {
		background: linear-gradient(135deg, #ff6b6b, #ee3f4d);
		color: #fff;
		box-shadow: 0 4rpx 12rpx rgba(238, 63, 77, 0.3);
	}
	.btn-price:active { opacity: 0.7; }
	.btn-buy:active { opacity: 0.8; }
</style>