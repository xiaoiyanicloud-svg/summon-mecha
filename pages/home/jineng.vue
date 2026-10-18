<template>
	<view class="page-container">
		<!-- 1. 顶部导航 -->
		<nav-bar title="技能详情" variant="sticky">
			<template #right>
				<view class="icon-share" @click="handleShare">↗</view>
			</template>
		</nav-bar>

		<!-- 加载失败:如实报错(B-16)。此前用 getMockData() 兜底,拿一段编造的技能
		     (名称/价格/配图)冒充真实商品 —— 用户会以为页面正常,点进去却买不到东西。 -->
		<view v-if="loadError" class="load-error">
			<text class="le-icon">😕</text>
			<text class="le-text">技能详情加载失败</text>
			<text class="le-sub">{{ loadError }}</text>
			<view class="le-btn" @click="loadSkillData(skillId)">重新加载</view>
		</view>

		<view v-if="detailLoading && !loadError" class="load-error">技能详情加载中…</view>
		<template v-if="!loadError && !detailLoading">
		<!-- 2. 轮播图 -->
		<swiper v-if="skillInfo.images.length" class="swiper" indicator-dots autoplay interval="3000" circular>
			<swiper-item v-for="(img, idx) in skillInfo.images" :key="idx">
				<image class="swiper-image" :src="img" mode="aspectFill" @error="skillInfo.images.splice(idx, 1)"></image>
			</swiper-item>
		</swiper>
		<view v-else class="skill-cover-placeholder"><text>🤖</text><text>暂无展示图</text></view>
		<view class="swiper-badge" v-if="skillInfo.served">🔥 已服务 {{ skillInfo.served }} 家庭</view>

		<!-- 3. 基础信息 -->
		<view class="info-card">
			<view class="info-row">
				<text class="skill-name">{{ skillInfo.name }}</text>
				<text v-if="skillInfo.rating" class="skill-rating">⭐ {{ skillInfo.rating }}</text>
			</view>
			<view class="price-row">
				<text class="price-current">￥{{ skillInfo.price }}</text>
				<text v-if="skillInfo.originalPrice > skillInfo.price" class="price-original">￥{{ skillInfo.originalPrice }}</text>
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
				<view v-if="reviewLoading" class="empty-tip">评价加载中…</view>
				<view v-else-if="reviewError" class="empty-tip" @click="loadReviews(productId)">评价加载失败，点此重试</view>
				<view v-else-if="skillInfo.comments.length === 0" class="empty-tip">暂无评价</view>

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
			<text v-else-if="deviceLoginRequired" class="compat-tip" @click="loginForDevices">登录后可选择已绑定设备，点此前往登录</text>
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
		<view v-if="riskLoading" class="risk-box">安全告知加载中…</view>
		<view v-else-if="riskError" class="risk-box" @click="loadRisk(productId)">安全告知加载失败，点此重试</view>
		<view class="risk-box" v-else-if="risk">
			<view class="risk-head">
				<text class="risk-label" :class="'lv-' + (risk.risk_level || 'unknown')">{{ risk.label }}</text>
				<text class="risk-title">安全告知</text>
			</view>
			<text class="risk-summary">{{ risk.summary }}</text>
			<view class="risk-items" v-if="risk.items && risk.items.length">
				<text v-for="(it, i) in risk.items" :key="it.key" class="risk-item">· {{ it.text }}</text>
			</view>
		</view>

		<!-- 5.65 版本历史 + 通道标识(B-16) -->
		<view class="version-box" v-if="versions.length">
			<view class="version-head">
				<text class="version-title">版本历史</text>
				<text class="chan-badge" v-if="currentVersion" :class="'chan-' + (currentVersion.channel || 'stable')">
					{{ currentVersion.channel_label }}
				</text>
			</view>
			<!-- 交付方式(与"发布通道"是两条不同的轴,见术语纪律) -->
			<view class="chan-row" v-if="currentVersion && currentVersion.delivery_channels.length">
				<text class="chan-title">交付方式</text>
				<text v-for="c in currentVersion.delivery_channels" :key="c" class="chan-chip">{{ channelLabel(c) }}</text>
			</view>
			<view class="version-item" v-for="v in versions" :key="v.id">
				<view class="vi-top">
					<text class="vi-ver">v{{ v.version }}</text>
					<text class="vi-badge" :class="'chan-' + (v.channel || 'stable')">{{ v.channel_label }}</text>
					<text class="vi-current" v-if="currentVersion && v.id === currentVersion.id">当前</text>
					<text class="vi-date">{{ (v.released_at || '').slice(0, 10) }}</text>
				</view>
				<text v-if="v.changelog" class="vi-log">{{ v.changelog }}</text>
			</view>
		</view>

		<!-- 5.7 举报入口(B-7):技能下发到真实机器后,这是用户能按的最后一个刹车。
		     后端不要求持有该技能 —— 举报对象恰恰不该是我买的东西,所以未购买也能举报。 -->
		<view class="report-entry" v-if="productId" @click="reportSkill">
			<text class="report-link">举报该技能</text>
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
		</template>
	</view>
</template>

<script>
	import { productApi, skillApi, deviceApi, reviewApi, riskApi, skillCatalogApi } from '@/utils/api'
	import { productSkillPrice } from '@/utils/skill-market'
	import { submitSkillReport } from '@/utils/report'
	import { TOKEN_KEY } from '@/utils/config'
	import NavBar from '@/components/NavBar.vue'
	export default {
		components: { NavBar },
		data() {
			return {
				currentTab: 0,
				skillId: '',
				productId: null,
				// B-16:加载失败如实报错(不再用 mock 兜底冒充真实技能)
				loadError: '',
				detailLoading: true,
				detailSequence: 0,
				// B-16:发布历史(版本/发布通道/交付通道/变更日志)
				versions: [],
				// 兼容性校验(v3.4):选设备 → 后端判定(含"未知"降级提示)
				devices: [],
				deviceLoadError: '',
				deviceLoginRequired: false,
				pickedDeviceId: '',
				compat: null,
				compatLoading: false,
				// 风险告知(B-5):分档文案 + high 风险逐条勾选留痕
				risk: null,
				riskLoading: false,
				riskError: '',
				riskSequence: 0,
				buyChecking: false,
				showRiskModal: false,
				riskChecked: {},
				riskSubmitting: false,
				// 写评价(v3.4)
				reviewRating: 5,
				reviewText: '',
				reviewSubmitting: false,
				reviewLoading: false,
				reviewError: '',
				reviewSequence: 0,
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
			this.skillId = options.productId || options.skillId || ''
			this.productId = options.productId || null
			this.loadSkillData(this.skillId)
			this.loadDevices()
		},
		onUnload() { this.detailSequence++; this.reviewSequence++; this.riskSequence++ },
		onShow() { if (this.deviceLoginRequired && uni.getStorageSync(TOKEN_KEY)) this.loadDevices() },
		computed: {
			// 试用按钮(B-3):必须已选设备、兼容、且命中 agent 在线通道。
			// 判定口径与后端一致(后端还会再校验一遍,前端只是不展示注定被拒的按钮)。
			trialAvailable() {
				if (!this.pickedDeviceId || !this.compat || !this.compat.compatible) return false
				return (this.compat.channels || []).indexOf('agent') !== -1
			},
			// B-16:当前版本 = 最新一条已发布版本(与后端 RiskDisclosureService 同口径)
			currentVersion() {
				return this.versions.find(v => v.status === 'published') || null
			}
		},
		methods: {
			// B-16:真实商品数据优先,**失败如实报错**。
			//   此前失败回退 getMockData() —— 拿一段编造的技能(名称/价格/配图/评价)
			//   冒充真实商品:用户会以为页面正常,点「立即开通」却买不到东西(甚至用假 id 下单)。
			//   现在与「我的设备」列表同一做法:置错误态 + 可重试,不拿假数据遮住故障。
			async loadSkillData(id) {
				const pid = this.productId || id
				const sequence = ++this.detailSequence
				this.loadError = ''; this.detailLoading = true; this.versions = []; this.risk = null
				if (!/^[1-9]\d*$/.test(String(pid || ''))) { this.loadError = '缺少有效的技能标识'; this.detailLoading = false; return }
				try {
					const [res, versionRes] = await Promise.all([productApi.getProductDetail(pid), skillCatalogApi.versions(pid)])
					if (sequence !== this.detailSequence) return
					const versions = versionRes && versionRes.data
					if (!Array.isArray(versions) || !versions.some(v => v.status === 'published')) throw new Error('该技能没有可购买的发布版本')
					this.versions = versions
					const p = (res && (res.data || res)) || null
					if (!p || !p.name) throw new Error('该技能不存在或已下架')
					const price = productSkillPrice(p)
					if (price === null) throw new Error('技能价格暂不可用，请稍后重试')
					this.productId = pid
					this.skillInfo = {
						name: p.name,
						rating: Number(p.ratings?.total) > 0 ? String(p.ratings.average) : '',
						price,
						originalPrice: 0,
						desc: p.short_description || '',
						served: '',
						images: this.normalizeImages(p.images || []),
						features: p.description || p.short_description || '',
						comments: []
					}
					this.loadReviews(pid)
					this.loadRisk(pid)
				} catch (e) {
					console.warn('[jineng] 技能详情加载失败', e)
					if (sequence === this.detailSequence) this.loadError = (e && e.message) || '加载失败'
				} finally { if (sequence === this.detailSequence) this.detailLoading = false }
			},
			normalizeImages(images) {
				return (images || []).map(i => (typeof i === 'string' ? i : (i && i.url) || '')).filter(Boolean)
			},
			// B-16:发布历史 + 通道标识。拿不到就不展示 —— 版本信息**不能编**。

			// 交付通道 → 人话(与 D2 三通道一致)
			channelLabel(c) {
				if (c === 'agent') return '自动下发'
				if (c === 'manual') return '人工安装'
				if (typeof c === 'string' && c.indexOf('vendor:') === 0) return '厂商云·' + c.slice(7)
				return c
			},
			// 评价:复用 Bagisto ProductReview 接口
			async loadReviews(pid) {
				const sequence = ++this.reviewSequence
				this.reviewLoading = true; this.reviewError = ''
				try {
					const res = await reviewApi.list(pid)
					if (sequence !== this.reviewSequence || String(pid) !== String(this.productId)) return
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
					if (sequence === this.reviewSequence) this.reviewError = (e && e.message) || '评价加载失败'
				} finally { if (sequence === this.reviewSequence) this.reviewLoading = false }
			},
			async loadDevices() {
				this.deviceLoadError = ''
				this.deviceLoginRequired = !uni.getStorageSync(TOKEN_KEY)
				if (this.deviceLoginRequired) { this.devices = []; return }
				try {
					const res = await deviceApi.list()
					this.devices = (res && Array.isArray(res.data)) ? res.data : []
				} catch (e) {
					// ⚠ 故障 ≠「还没有登记设备」:原来把失败吞成空数组,页面就显示"还没有设备"。
					//   现在置错误标记,由模板的错误提示承载(可点重试)。
					console.warn('[jineng] 设备列表加载失败', e)
					this.deviceLoadError = (e && e.message) || '加载失败'
					this.deviceLoginRequired = !uni.getStorageSync(TOKEN_KEY)
					this.devices = []
				}
			},
			loginForDevices() { uni.switchTab({ url: '/pages/mine/index' }) },
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
				const sequence = ++this.riskSequence
				this.riskLoading = true; this.riskError = ''
				try {
					const res = await riskApi.profile(productId)
					if (sequence !== this.riskSequence || String(productId) !== String(this.productId)) return
					this.risk = (res && res.data) || null
					if (!this.risk) throw new Error('安全告知暂不可用')
					this.riskChecked = {}
				} catch (e) {
					console.warn('[jineng] 风险告知加载失败', e)
					if (sequence === this.riskSequence) { this.risk = null; this.riskError = (e && e.message) || '安全告知加载失败' }
				} finally { if (sequence === this.riskSequence) this.riskLoading = false }
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
			handleShare() {
				uni.showToast({ title: '分享功能开发中', icon: 'none' })
			},
			// B-7:举报该技能(target_id = products.id)。选理由 → 可选补充 → 提交。
			reportSkill() {
				if (!this.productId) { uni.showToast({ title: '技能信息加载中', icon: 'none' }); return }
				submitSkillReport({ targetType: 'skill', targetId: this.productId, name: this.skillInfo.name })
			},
			openCustomer() {
				// B-20:此前只弹一个硬编码的 400-888-9999 —— 那是**假动作**,
				// 平台没有任何真实客服通道接到它。现在跳真实客服工单页
				// (support_ticket 有后台处理链路),用户提交的问题真的有人看。
				if (!uni.getStorageSync(TOKEN_KEY)) {
					uni.showModal({
						title: '联系客服',
						content: '客服工单需要登录后提交。前往「我的」登录？',
						confirmText: '去登录',
						success: (r) => { if (r.confirm) uni.switchTab({ url: '/pages/mine/index' }) },
					})
					return
				}
				uni.navigateTo({ url: '/pages/mine/tickets' })
			},
			handlePrice() {
				uni.showModal({
					title: '价格',
					content: `「${this.skillInfo.name}」价格：￥${this.skillInfo.price}（一次买断）\n点击「立即开通」即可购买并确认订单。`,
					showCancel: false
				})
			},
			async handleBuy() {
				if (this.detailLoading || this.loadError || !this.currentVersion || this.buyChecking) return
				this.buyChecking = true
				try { await this.loadRisk(this.productId) } finally { this.buyChecking = false }
				if (!this.risk) { uni.showToast({ title: '使用与风险说明暂不可用，请稍后重试', icon: 'none' }); return }
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
				if (this.detailLoading || this.loadError || !this.currentVersion) return
				// 开通 → 进入确认订单（复用商品购买下单逻辑）
				const skill = this.skillInfo
				const url =
					`/pages/order/checkout?productId=${this.productId}` +
					`&name=${encodeURIComponent(skill.name)}` +
					`&price=${encodeURIComponent(String(skill.price || 0))}` +
					`&icon=${encodeURIComponent('🧠')}` +
					`&bg=${encodeURIComponent('#E8F0FE')}` +
					`&scene=${encodeURIComponent('技能服务')}&type=skill&qty=1`
				uni.navigateTo({ url })
			}
		}
	}
</script>

<style lang="scss" scoped>
	.skill-cover-placeholder { height: 240rpx; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 14rpx; background: #eef3ff; color: #64748b; font-size: 24rpx; }
	.skill-cover-placeholder text:first-child { font-size: 64rpx; }
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
	/* 举报入口(B-7):低干扰的小字入口,放在页尾、底部操作栏之上 */
	.report-entry {
		display: flex;
		justify-content: center;
		padding: 10rpx 0 30rpx;
	}
	.report-link {
		font-size: 24rpx;
		color: #9CA3AF;
		text-decoration: underline;
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

	/* 版本历史 + 通道标识(B-16) */
	.version-box { margin: 20rpx 24rpx; padding: 24rpx; background: #fff; border-radius: 16rpx; }
	.version-head { display: flex; align-items: center; justify-content: space-between; }
	.version-title { font-size: 28rpx; font-weight: 600; color: #222; }
	.chan-badge { font-size: 22rpx; font-weight: 600; padding: 4rpx 16rpx; border-radius: 24rpx; }
	.chan-badge.chan-stable { background: #eaf7ee; color: #1a9c50; }
	.chan-badge.chan-beta { background: #fff5e6; color: #d48806; }
	.chan-row { display: flex; align-items: center; flex-wrap: wrap; margin-top: 14rpx; }
	.chan-title { font-size: 22rpx; color: #999; margin-right: 12rpx; }
	.chan-chip { font-size: 22rpx; color: #165DFF; background: #F0F7FF; border: 1rpx solid #cfe0ff; border-radius: 24rpx; padding: 4rpx 18rpx; margin: 0 12rpx 8rpx 0; }
	.version-item { margin-top: 18rpx; padding-top: 16rpx; border-top: 1rpx solid #f2f2f2; }
	.vi-top { display: flex; align-items: center; }
	.vi-ver { font-size: 26rpx; font-weight: 600; color: #333; }
	.vi-badge { font-size: 20rpx; font-weight: 600; padding: 2rpx 12rpx; border-radius: 20rpx; margin-left: 12rpx; }
	.vi-badge.chan-stable { background: #eaf7ee; color: #1a9c50; }
	.vi-badge.chan-beta { background: #fff5e6; color: #d48806; }
	.vi-current { font-size: 20rpx; color: #fff; background: #165DFF; border-radius: 20rpx; padding: 2rpx 12rpx; margin-left: 12rpx; }
	.vi-date { margin-left: auto; font-size: 22rpx; color: #bbb; }
	.vi-log { display: block; margin-top: 8rpx; font-size: 24rpx; color: #666; line-height: 1.5; }

	/* 加载失败态(B-16) */
	.load-error { display: flex; flex-direction: column; align-items: center; padding: 160rpx 60rpx; }
	.le-icon { font-size: 90rpx; }
	.le-text { margin-top: 24rpx; font-size: 30rpx; font-weight: 600; color: #333; }
	.le-sub { margin-top: 12rpx; font-size: 24rpx; color: #999; text-align: center; }
	.le-btn { margin-top: 40rpx; padding: 18rpx 70rpx; background: #165DFF; color: #fff; border-radius: 40rpx; font-size: 28rpx; }
</style>
