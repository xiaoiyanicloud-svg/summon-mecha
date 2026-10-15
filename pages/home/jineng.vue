<template>
	<view class="page-container">
		<!-- 1. 顶部导航 -->
		<nav-bar title="技能详情" variant="sticky">
			<template #right>
				<view class="icon-share" @click="handleShare">↗</view>
			</template>
		</nav-bar>

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
				<text class="price-current">￥{{ skillInfo.price }}</text>
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

				<!-- v3.4:写评价(复用 Bagisto ProductReview;title/comment/rating 必填) -->
				<view class="write-review" v-if="productId">
					<text class="wr-title">写评价</text>
					<view class="wr-stars">
						<text v-for="n in 5" :key="n" class="wr-star" :class="{ on: n <= reviewRating }" @click="reviewRating = n">★</text>
					</view>
					<textarea class="wr-input" v-model="reviewText" placeholder="说说使用感受" maxlength="200" />
					<view class="wr-btn" @click="submitReview">{{ reviewSubmitting ? '提交中…' : '提交评价' }}</view>
				</view>
			</view>
		</view>

		<!-- 5.5 兼容性校验(v3.4) -->
		<view class="compat-box">
			<text class="compat-title">兼容性校验</text>
			<view class="compat-devices" v-if="devices.length">
				<text
					v-for="d in devices"
					:key="d.id"
					class="compat-device"
					:class="{ on: pickedDeviceId === d.id }"
					@click="pickedDeviceId = d.id"
				>{{ d.sn }}</text>
			</view>
			<text v-else-if="deviceLoadError" class="compat-tip" style="color:#cf1322" @click="loadDevices">设备列表没加载出来，点此重试</text>
			<text v-else class="compat-tip">还没有登记设备，先去「我的设备」登记</text>

			<view class="compat-btn" @click="checkCompat">{{ compatLoading ? '校验中…' : '检查能否安装' }}</view>

			<view v-if="compat" class="compat-result" :class="{ ok: compat.compatible }">
				<text class="compat-verdict">{{ compat.compatible ? '✅ 兼容' : '⛔ 不兼容' }}</text>
				<text class="compat-reason">{{ compat.reason }}</text>
				<text v-if="compat.channels && compat.channels.length" class="compat-channels">可用通道：{{ compat.channels.join(' / ') }}</text>
				<text v-for="(n, i) in (compat.notes || [])" :key="i" class="compat-note">· {{ n }}</text>
			</view>
		</view>

		<!-- 5.6 风险告知(B-5 · 计划 §4.14):按 risk_level 分档展示 -->
		<view class="risk-box" v-if="risk">
			<view class="risk-head">
				<text class="risk-label" :class="'lv-' + (risk.risk_level || 'unknown')">{{ risk.label }}</text>
				<text class="risk-title">安全告知</text>
			</view>
			<text class="risk-summary">{{ risk.summary }}</text>
			<view class="risk-items" v-if="risk.items && risk.items.length">
				<text v-for="(it, i) in risk.items" :key="it.key" class="risk-item">· {{ it.text }}</text>
			</view>
		</view>

		<!-- 6. 底部操作栏 -->
		<view class="footer-bar">
			<view class="footer-left" @click="openCustomer">
				<text class="icon-service">🛎️</text>
				<text class="service-label">客服</text>
			</view>
			<view class="footer-actions">
				<button class="btn-price" @click="handlePrice">￥{{ skillInfo.price }}</button>
				<!-- 试用(B-3 · D4):仅当所选设备命中 agent 在线通道时出现。人工通道 20 分钟后收不回来,不给试用 -->
				<button v-if="trialAvailable" class="btn-trial" @click="startTrial">试用20分钟</button>
				<button class="btn-buy" @click="handleBuy">立即开通</button>
			</view>
		</view>

		<!-- 风险告知弹窗(B-5):high 风险逐条勾选后落库留痕 -->
		<view v-if="showRiskModal" class="risk-mask" @click="cancelRisk">
			<view class="risk-modal" @click.stop="noop">
				<text class="risk-modal-title">安全告知 · {{ risk.label }}</text>
				<text class="risk-modal-summary">{{ risk.summary }}</text>
				<view class="risk-modal-items">
					<view class="risk-check-row" v-for="it in risk.items" :key="it.key" @click="riskChecked[it.key] = !riskChecked[it.key]">
						<text class="risk-check-box" :class="{ on: riskChecked[it.key] }">{{ riskChecked[it.key] ? '✓' : '' }}</text>
						<text class="risk-check-text">{{ it.text }}</text>
					</view>
				</view>
				<view class="risk-modal-footer">
					<view class="risk-cancel" @click="cancelRisk">取消</view>
					<view class="risk-confirm" @click="confirmRisk">{{ riskSubmitting ? '提交中…' : '我已确认，继续开通' }}</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script>
	import { productApi, skillApi, deviceApi, reviewApi, riskApi } from '@/utils/api'
	import NavBar from '@/components/NavBar.vue'
	export default {
		components: { NavBar },
		data() {
			return {
				currentTab: 0,
				skillId: '',
				productId: null,
				// 兼容性校验(v3.4):选设备 → 后端判定(含"未知"降级提示)
				devices: [],
				deviceLoadError: '',
				pickedDeviceId: '',
				compat: null,
				compatLoading: false,
				// 风险告知(B-5):分档文案 + high 风险逐条勾选留痕
				risk: null,
				showRiskModal: false,
				riskChecked: {},
				riskSubmitting: false,
				// 写评价(v3.4)
				reviewRating: 5,
				reviewText: '',
				reviewSubmitting: false,
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
			this.productId = options.productId || null
			this.loadSkillData(this.skillId)
			this.loadDevices()
		},
		computed: {
			// 试用按钮(B-3):必须已选设备、兼容、且命中 agent 在线通道。
			// 判定口径与后端一致(后端还会再校验一遍,前端只是不展示注定被拒的按钮)。
			trialAvailable() {
				if (!this.pickedDeviceId || !this.compat || !this.compat.compatible) return false
				return (this.compat.channels || []).indexOf('agent') !== -1
			}
		},
		methods: {
			// v3.4:真实商品数据优先,失败回退 mock(不把 mock 当唯一数据源)
			async loadSkillData(id) {
				const pid = this.productId || id
				try {
					const res = await productApi.getProductDetail(pid)
					const p = (res && (res.data || res)) || null
					if (p && p.name) {
						this.productId = pid
						this.skillInfo = {
							name: p.name,
							rating: (p.rating || '') + '',
							price: p.price || 0,
							originalPrice: p.special_price || 0,
							desc: p.short_description || '',
							served: '',
							images: this.normalizeImages(p.images || []),
							features: p.description || p.short_description || '',
							comments: []
						}
						this.loadReviews(pid)
						this.loadRisk(pid)
						return
					}
				} catch (e) {
					// 回退到 mock —— 真实数据不可用时页面不能白屏。留 warn,免得连错都不知道。
					console.warn('[jineng] 技能详情接口失败,回退 mock', e)
				}
				const data = this.getMockData(id)
				if (data) this.skillInfo = data
			},
			normalizeImages(images) {
				return (images || []).map(i => (typeof i === 'string' ? i : (i && i.url) || '')).filter(Boolean)
			},
			// 评价:复用 Bagisto ProductReview 接口
			async loadReviews(pid) {
				try {
					const res = await reviewApi.list(pid)
					const rows = (res && res.data) || []
					this.skillInfo = {
						...this.skillInfo,
						comments: rows.map(r => ({
							avatar: '',
							nick: (r.name || '用户') + '',
							content: r.comment || '',
						})),
					}
				} catch (e) {
					// 评价拉不到只影响「用户评价」一栏,不阻断详情 —— 留 warn 不静默
					console.warn('[jineng] 评价加载失败', e)
				}
			},
			async loadDevices() {
				this.deviceLoadError = ''
				try {
					const res = await deviceApi.list()
					this.devices = (res && Array.isArray(res.data)) ? res.data : []
				} catch (e) {
					// ⚠ 故障 ≠「还没有登记设备」:原来把失败吞成空数组,页面就显示"还没有设备"。
					//   现在置错误标记,由模板的错误提示承载(可点重试)。
					console.warn('[jineng] 设备列表加载失败', e)
					this.deviceLoadError = (e && e.message) || '加载失败'
					this.devices = []
				}
			},
			// 提交评价:title/comment/rating 必填(用评论文本作 title 以满足契约)
			async submitReview() {
				if (!this.productId) return
				const text = (this.reviewText || '').trim()
				if (!text) { uni.showToast({ title: '请填写评价内容', icon: 'none' }); return }
				this.reviewSubmitting = true
				try {
					await reviewApi.create(this.productId, { title: text.slice(0, 40), comment: text, rating: this.reviewRating })
					uni.showToast({ title: '评价已提交', icon: 'success' })
					this.reviewText = ''
					this.loadReviews(this.productId)
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
				} finally {
					this.reviewSubmitting = false
				}
			},
			// 兼容性校验(核心新能力):选设备 → 后端判定
			async checkCompat() {
				if (!this.pickedDeviceId) { uni.showToast({ title: '请先选一台设备', icon: 'none' }); return }
				if (!this.productId) { uni.showToast({ title: '技能信息加载中', icon: 'none' }); return }
				this.compatLoading = true
				try {
					const res = await skillApi.compatibility(this.productId, this.pickedDeviceId)
					this.compat = (res && res.data) || null
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '校验失败', icon: 'none' })
				} finally {
					this.compatLoading = false
				}
			},
			// 风险告知(B-5):按 manifest.safety.risk_level 拉分档文案。拿不到就不展示 ——
			// 风险信息**不能编**(编一个"低风险"比不展示更坏)。
			async loadRisk(productId) {
				try {
					const res = await riskApi.profile(productId)
					this.risk = (res && res.data) || null
					this.riskChecked = {}
				} catch (e) {
					console.warn('[jineng] 风险告知加载失败', e)
					this.risk = null
				}
			},
			// 试用(B-3 · D4):发放 20 分钟在线授权并直接下发;到期由平台定时回收。
			async startTrial() {
				if (!this.pickedDeviceId) { uni.showToast({ title: '请先选一台设备', icon: 'none' }); return }
				if (!this.productId) { uni.showToast({ title: '技能信息加载中', icon: 'none' }); return }
				try {
					const res = await skillApi.trial(this.productId, this.pickedDeviceId)
					const d = (res && res.data) || {}
					uni.showModal({
						title: '试用已开通',
						content: '20 分钟试用已开始，正在下发到设备。到期后平台会自动回收，无需手动卸载。',
						showCancel: false,
						success: () => uni.navigateTo({ url: '/pages/mine/skills' })
					})
					// 进入"我的技能"能看到倒计时；这里不额外处理 d，避免与后端契约耦合
					return d
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '试用开通失败', icon: 'none' })
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
			handleShare() {
				uni.showToast({ title: '分享功能开发中', icon: 'none' })
			},
			openCustomer() {
				uni.showToast({ title: '联系客服：400-888-9999', icon: 'none' })
			},
			handlePrice() {
				uni.showModal({
					title: '价格',
					content: `「${this.skillInfo.name}」价格：￥${this.skillInfo.price}（一次买断），原价 ￥${this.skillInfo.originalPrice}\n点击「立即开通」即可购买并确认订单。`,
					showCancel: false
				})
			},
			handleBuy() {
				// 高风险技能(B-5):先弹分级告知,逐条勾选并留痕后才放行。
				// 勾选留痕是"告知"的凭据 —— 不能只弹个"确定"就当告知过了。
				if (this.risk && this.risk.require_checklist) {
					// 预先建好每一项的 key —— Vue2 对"运行时新增的对象属性"不响应,
					// 直接把 riskChecked 换成一份带全 key 的新对象才是可靠的
					const checked = {}
					;(this.risk.items || []).forEach((it) => { checked[it.key] = false })
					this.riskChecked = checked
					this.showRiskModal = true
					return
				}
				this.goCheckout()
			},
			cancelRisk() {
				this.showRiskModal = false
			},
			// 阻止弹窗内容点击冒泡到遮罩(点内容不该关掉弹窗)
			noop() {},
			async confirmRisk() {
				const items = (this.risk && this.risk.items) || []
				const missing = items.filter((it) => !this.riskChecked[it.key])
				if (missing.length) {
					uni.showToast({ title: '请逐条勾选确认后再继续', icon: 'none' })
					return
				}
				this.riskSubmitting = true
				try {
					await riskApi.acknowledge({
						product_id: this.productId,
						skill_version_id: this.risk.skill_version_id,
						channel: (this.compat && this.compat.channels && this.compat.channels[0]) || null,
						checked: items.map((it) => it.key),
					})
					this.showRiskModal = false
					this.goCheckout()
				} catch (e) {
					uni.showToast({ title: (e && e.message) || '提交失败', icon: 'none' })
				} finally {
					this.riskSubmitting = false
				}
			},
			goCheckout() {
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
	.icon-share { font-weight: 300; font-size: 28px; color: #333; }
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
	/* 试用按钮(B-3):与"立即开通"区分,用页面既有的橙色描边样式,不与主买断按钮抢视觉 */
	.btn-trial {
		flex: 0.9;
		height: 76rpx;
		line-height: 76rpx;
		border-radius: 40rpx;
		font-size: 28rpx;
		font-weight: 600;
		text-align: center;
		padding: 0;
		background: #fff;
		color: #ff6b00;
		border: 1rpx solid #ffd9b8;
	}
	.btn-trial:active { opacity: 0.7; }
	.btn-price:active { opacity: 0.7; }
	.btn-buy:active { opacity: 0.8; }

	/* 兼容性校验(v3.4) */
	.compat-box { margin: 20rpx 24rpx; padding: 24rpx; background: #fff; border-radius: 16rpx; }
	.compat-title { font-size: 28rpx; font-weight: 600; color: #222; }
	.compat-devices { display: flex; flex-wrap: wrap; margin-top: 16rpx; }
	.compat-device { margin: 0 16rpx 12rpx 0; padding: 10rpx 24rpx; border: 1rpx solid #ddd; border-radius: 32rpx; font-size: 24rpx; color: #555; }
	.compat-device.on { border-color: #ff6b00; color: #ff6b00; background: #fff5ee; }
	.compat-tip { display: block; margin-top: 16rpx; font-size: 24rpx; color: #999; }
	.compat-btn { margin-top: 16rpx; text-align: center; padding: 18rpx 0; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
	.compat-result { margin-top: 20rpx; padding: 20rpx; border-radius: 12rpx; background: #fdecec; }
	.compat-result.ok { background: #eaf7ee; }
	.compat-verdict { display: block; font-size: 28rpx; font-weight: 600; color: #333; }
	.compat-reason { display: block; margin-top: 8rpx; font-size: 24rpx; color: #666; }
	.compat-channels { display: block; margin-top: 8rpx; font-size: 24rpx; color: #ff6b00; }
	.compat-note { display: block; margin-top: 6rpx; font-size: 22rpx; color: #999; }

	/* 写评价(v3.4) */
	.write-review { margin-top: 28rpx; padding-top: 24rpx; border-top: 1rpx solid #eee; }
	.wr-title { font-size: 28rpx; font-weight: 600; color: #222; }
	.wr-stars { margin-top: 12rpx; }
	.wr-star { font-size: 44rpx; color: #ddd; margin-right: 8rpx; }
	.wr-star.on { color: #ffb400; }
	.wr-input { margin-top: 16rpx; width: 100%; min-height: 120rpx; padding: 16rpx; background: #f7f8fa; border-radius: 12rpx; font-size: 26rpx; box-sizing: border-box; }
	.wr-btn { margin-top: 16rpx; text-align: center; padding: 18rpx 0; background: #ff6b00; color: #fff; border-radius: 40rpx; font-size: 28rpx; }

	/* 风险告知(B-5) */
	.risk-box { margin: 20rpx 24rpx; padding: 24rpx; background: #fff; border-radius: 16rpx; }
	.risk-head { display: flex; align-items: center; }
	.risk-label { font-size: 24rpx; font-weight: 600; padding: 4rpx 16rpx; border-radius: 24rpx; margin-right: 12rpx; }
	.risk-label.lv-low { background: #eaf7ee; color: #1a9c50; }
	.risk-label.lv-medium { background: #fff5e6; color: #d48806; }
	.risk-label.lv-high { background: #fdecec; color: #cf1322; }
	.risk-label.lv-unknown { background: #f2f2f2; color: #888; }
	.risk-title { font-size: 28rpx; font-weight: 600; color: #222; }
	.risk-summary { display: block; margin-top: 12rpx; font-size: 24rpx; color: #666; line-height: 1.5; }
	.risk-items { margin-top: 10rpx; }
	.risk-item { display: block; margin-top: 4rpx; font-size: 22rpx; color: #999; }

	.risk-mask { position: fixed; left: 0; top: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 9999; display: flex; align-items: center; justify-content: center; }
	.risk-modal { width: 660rpx; background: #fff; border-radius: 24rpx; padding: 40rpx 32rpx 24rpx; box-sizing: border-box; }
	.risk-modal-title { display: block; font-size: 32rpx; font-weight: 600; color: #222; text-align: center; }
	.risk-modal-summary { display: block; margin-top: 16rpx; font-size: 26rpx; color: #666; line-height: 1.5; }
	.risk-modal-items { margin-top: 20rpx; }
	.risk-check-row { display: flex; align-items: flex-start; padding: 16rpx 0; }
	.risk-check-box { width: 40rpx; height: 40rpx; line-height: 40rpx; text-align: center; border: 1rpx solid #ccc; border-radius: 8rpx; font-size: 26rpx; color: #fff; flex-shrink: 0; margin-right: 16rpx; box-sizing: border-box; }
	.risk-check-box.on { background: #ff6b00; border-color: #ff6b00; }
	.risk-check-text { flex: 1; font-size: 26rpx; color: #333; line-height: 1.4; }
	.risk-modal-footer { display: flex; gap: 20rpx; margin-top: 28rpx; }
	.risk-cancel { flex: 1; text-align: center; padding: 20rpx 0; border: 1rpx solid #ddd; border-radius: 40rpx; font-size: 28rpx; color: #666; }
	.risk-confirm { flex: 2; text-align: center; padding: 20rpx 0; background: linear-gradient(135deg, #ff6b6b, #ee3f4d); color: #fff; border-radius: 40rpx; font-size: 28rpx; }
</style>