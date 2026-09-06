<template>
  <view class="detail-page">
    <!-- ===== 顶部导航 ===== -->
    <view class="nav-bar">
      <view class="nav-left" @tap="goBack">
        <text class="back-icon">‹</text>
        <text class="back-text">返回</text>
      </view>
      <view class="nav-right">
        <text class="nav-share">↗</text>
      </view>
    </view>

    <scroll-view class="scroll-content" scroll-y :show-scrollbar="false">
      <!-- ===== 1. Hero：价值主张 + 信任 + 关键数字 ===== -->
      <view class="hero">
        <view class="hero-top">
          <view class="hero-icon-wrap" :style="{ background: scheme.bgColor || '#E8F0FE' }">
            <text class="hero-icon">{{ scheme.icon || '🤖' }}</text>
          </view>
          <view class="hero-head">
            <text class="hero-title">{{ scheme.title }}</text>
            <text class="hero-consult" v-if="scheme.consultCount">{{ scheme.consultCount }} 人已咨询</text>
          </view>
        </view>
        <text class="hero-desc">{{ scheme.desc }}</text>

        <view class="hero-tags">
          <text class="hero-tag" v-for="(t, i) in scheme.tags" :key="i">{{ t }}</text>
        </view>

        <view class="hero-stats">
          <view class="stat-item">
            <text class="stat-num">{{ scheme.stats.amp }}</text>
            <text class="stat-label">效率提升</text>
          </view>
          <view class="stat-item">
            <text class="stat-num">{{ scheme.stats.roi }}</text>
            <text class="stat-label">平均回本</text>
          </view>
          <view class="stat-item">
            <text class="stat-num">{{ scheme.stats.scene }}</text>
            <text class="stat-label">适配场景</text>
          </view>
        </view>
      </view>

      <!-- ===== 2. 核心痛点 → 方案 ===== -->
      <view class="card">
        <view class="card-title-row">
          <text class="card-title">你的核心痛点</text>
          <text class="card-sub">遇到这些难题？我们这样解决</text>
        </view>
        <view class="pain-list">
          <view class="pain-item" v-for="(pain, idx) in scheme.pains" :key="idx">
            <view class="pain-left">
              <text class="pain-icon">{{ pain.icon }}</text>
              <text class="pain-text">{{ pain.text }}</text>
            </view>
            <view class="pain-solve">
              <text class="solve-icon">✓</text>
              <text class="solve-text">{{ pain.solve }}</text>
            </view>
          </view>
        </view>
      </view>

      <!-- ===== 3. 方案价值亮点 ===== -->
      <view class="card">
        <view class="card-title-row">
          <text class="card-title">方案亮点</text>
        </view>
        <view class="benefit-grid">
          <view class="benefit-item" v-for="(b, idx) in scheme.benefits" :key="idx">
            <view class="benefit-icon">{{ b.icon }}</view>
            <text class="benefit-title">{{ b.title }}</text>
            <text class="benefit-desc">{{ b.desc }}</text>
          </view>
        </view>
      </view>

      <!-- ===== 4. 典型场景预期收益 ===== -->
      <view class="card" v-if="scheme.scenes && scheme.scenes.length">
        <view class="card-title-row">
          <text class="card-title">典型场景预期收益</text>
        </view>
        <scroll-view class="scene-scroll" scroll-x :show-scrollbar="false">
          <view class="scene-card" v-for="(scene, idx) in scheme.scenes" :key="idx">
            <view class="scene-mark">
              <text class="scene-name">{{ scene.name }}</text>
            </view>
            <text class="scene-desc">{{ scene.desc }}</text>
            <view class="scene-data">
              <text class="scene-data-num">{{ scene.data }}</text>
            </view>
          </view>
        </scroll-view>
        <view class="data-note">*数据基于行业公开信息及实验室测试推算</view>
      </view>

      <!-- ===== 5. 本方案可用机器人 ===== -->
      <view class="card" v-if="mainRobots.length">
        <view class="card-title-row">
          <text class="card-title">本方案可用机器人</text>
        </view>
        <view class="product-grid">
          <view class="product-card" v-for="robot in mainRobots" :key="robot.id" @tap="onCardTap(robot)">
            <view class="product-img-wrap">
              <image v-if="robot.image" class="product-img" :src="robot.image" mode="aspectFill"></image>
              <text v-else class="product-emoji">🤖</text>
              <text v-if="robot.tag" class="product-tag" :class="robot.tag">{{ robot.tag }}</text>
            </view>
            <view class="product-info">
              <text class="product-name">{{ robot.title }}</text>
              <text class="product-desc">{{ robot.desc }}</text>
            </view>
            <view class="product-action">了解详情</view>
          </view>
        </view>
      </view>

      <!-- ===== 交付流程 ===== -->
      <view class="card">
        <view class="card-title-row">
          <text class="card-title">交付流程</text>
        </view>
        <view class="process">
          <view class="process-item" v-for="(p, idx) in processList" :key="idx">
            <view class="process-dot">
              <text class="process-num">{{ idx + 1 }}</text>
            </view>
            <text class="process-name">{{ p }}</text>
          </view>
        </view>
      </view>

      <view style="height: 180rpx;"></view>
    </scroll-view>

    <!-- ===== 底部吸底 CTA ===== -->
    <view class="bottom-bar">
      <button class="btn-cta" @tap="onConsult">
        <text>免费定制方案</text>
      </button>
    </view>
  </view>
</template>

<script>
// ================================================================
// 机器人库（供各方案推荐引用）
// ================================================================
const ROBOTS = [
  { id: 1, title: '宇树 Go2',  desc: '四足巡检机器人', image: '/static/robot1.png', tag: '热卖' },
  { id: 2, title: '智元远征 A1', desc: '人形工业机器人', image: '/static/robot2.png', tag: '' },
  { id: 3, title: '大疆 M300', desc: '无人机巡检平台', image: '/static/robot3.png', tag: '新品' },
  { id: 4, title: '擎朗 T5',  desc: '物流配送机器人', image: '/static/robot4.png', tag: '' },
  { id: 5, title: '普渡欢乐送', desc: '智能配送机器人', image: '/static/robot5.png', tag: '' },
  { id: 6, title: '高仙 50', desc: '商用清洁机器人', image: '/static/robot6.png', tag: '' }
]

// ================================================================
// 通用兜底方案（无匹配 title 时使用）
// ================================================================
const DEFAULT_SCHEME = {
  desc: '以智能机器人替代人工，降本增效、安全可靠，一站式落地交付',
  icon: '🤖',
  consultCount: 520,
  bgColor: '#E8F0FE',
  tags: ['官方正品', '全国服务', '工程师上门', '全程质保'],
  stats: { amp: '+200%', roi: '8个月', scene: '50+' },
  pains: [
    { icon: '💸', text: '人工成本逐年上涨，招聘与培训压力大', solve: '机器人替代重复劳动，1台顶3个班次' },
    { icon: '⚠️', text: '高危/重复作业安全风险高', solve: '机器上岗，高危环节无人化' },
    { icon: '📉', text: '作业数据难采集，决策缺依据', solve: '数据实时回传，运营可视可析' }
  ],
  benefits: [
    { icon: '⏱️', title: '7×24 作业', desc: '全天候不间断，突破人工工时限制' },
    { icon: '🎯', title: '精准执行', desc: '视觉/传感器加持，作业准确一致' },
    { icon: '📊', title: '数据驱动', desc: '过程数据沉淀，助力科学决策' }
  ],
  scenes: [
    { name: '标准作业场景', desc: '方案稳定复现、快速部署上线', data: '效率 +200%' },
    { name: '定制化场景', desc: '按需灵活配置，随业务扩展', data: '随需扩展' }
  ],
  robots: [1, 2, 5]
}

// ================================================================
// 各热门方案专属内容（点谁显示谁；无匹配走上面的通用兜底）
// ================================================================
const SCHEME_DB = {
  '仓储分拣': {
    desc: '移动机器人替代人工搬运，分拣效率提升300%，错拣率低于0.1%',
    icon: '📦', bgColor: '#E8F0FE', consultCount: 386, tags: ['官方正品', '全国服务', '夜间可用'],
    stats: { amp: '+300%', roi: '8个月', scene: '50+' },
    pains: [
      { icon: '💸', text: '人工搬运成本逐年上涨，招工难留人难', solve: '机器人替代搬运，1台顶3个班次' },
      { icon: '🎯', text: '高峰期爆仓、分拣错发频发', solve: '视觉识别精准分拣，错拣率<0.1%' },
      { icon: '🌙', text: '夜间无人作业空缺，吞吐受限', solve: '7×24 无人分拣，吞吐翻倍' }
    ],
    benefits: [
      { icon: '⏱️', title: '24h 无人分拣', desc: '夜间照样作业，突破产能瓶颈' },
      { icon: '🎯', title: '精准分拣', desc: '视觉识别货品，错发近乎为零' },
      { icon: '💰', title: '轻资产运营', desc: '按单计费/租赁，无需重投入' }
    ],
    scenes: [
      { name: '电商仓配', desc: '订单波次自动分拣', data: '效率 +300%' },
      { name: '快递中转', desc: '识别分拣、装车对接', data: '错拣 <0.1%' },
      { name: '冷库低温', desc: '耐低温不间断搬运', data: '24h 可用' }
    ],
    robots: [4, 5]
  },
  '电力巡检': {
    desc: '四足机器人 + 无人机协同巡检，电力设施 7×24 安全监测',
    icon: '⚡', bgColor: '#E8F0FE', consultCount: 523, tags: ['官方正品', '高危替代', '数据上云'],
    stats: { amp: '-80%人力', roi: '6个月', scene: '100+' },
    pains: [
      { icon: '🧗', text: '人工登高巡检，高危且效率低', solve: '机器人/无人机替代登高，人不下高' },
      { icon: '🌑', text: '夜间、恶劣天气漏检盲区多', solve: '7×24 自主巡检，无盲区' },
      { icon: '📊', text: '设备状态无法实时回传', solve: '红外+视觉识别，数据实时上云' }
    ],
    benefits: [
      { icon: '🛡️', title: '高危替代', desc: '登高、带电作业无人化' },
      { icon: '👁️', title: '双模识别', desc: '红外测温 + 视觉异常识别' },
      { icon: '☁️', title: '数据上云', desc: '状态实时回传，缺陷可追溯' }
    ],
    scenes: [
      { name: '变电站', desc: '设备测温、表计识别', data: '准确率 98%+' },
      { name: '输电线路', desc: '无人机沿线巡查', data: '里程 3×' },
      { name: '配电房', desc: '日常表计、局放检测', data: '24h 覆盖' }
    ],
    robots: [1, 3]
  },
  '酒店服务': {
    desc: '配送 + 迎宾机器人，服务响应提速40%，客户满意度显著提升',
    icon: '🏨', bgColor: '#E8F0FE', consultCount: 297, tags: ['官方正品', '上门部署', '全天候'],
    stats: { amp: '+40%响应', roi: '5个月', scene: '30+' },
    pains: [
      { icon: '👣', text: '客房配送依赖人工，跑腿成本高', solve: '机器人 24h 无人配送' },
      { icon: '⏳', text: '服务响应慢，客人体验差', solve: '需求秒级响应，随叫随到' },
      { icon: '👥', text: '高峰期人手不足、服务质量不稳', solve: '低成本增援，服务标准统一' }
    ],
    benefits: [
      { icon: '🛎️', title: '24h 配送', desc: '物品、餐食随时送达' },
      { icon: '🤝', title: '礼宾迎宾', desc: '大堂引流、主动问候' },
      { icon: '💸', title: '降本增效', desc: '减少跑腿人力，省成本' }
    ],
    scenes: [
      { name: '客房服务', desc: '洗漱品、外卖即时送达', data: '响应 +40%' },
      { name: '大堂迎宾', desc: '引导、交互、口碑传播', data: '好评 +25%' },
      { name: '宴会/自助', desc: '传菜、收餐自动往返', data: '翻台 +20%' }
    ],
    robots: [5, 4]
  },
  '展会接待': {
    desc: 'AI 迎宾 + 互动机器人，让展台成为全场焦点，3倍引流',
    icon: '🎪', bgColor: '#E8F0FE', consultCount: 678, tags: ['可租用', '多城复用', '线索采集'],
    stats: { amp: '3×引流', roi: '活动期', scene: '多城复用' },
    pains: [
      { icon: '🚶', text: '展台冷清、人流看过就走', solve: '智能迎宾引流，成为打卡点' },
      { icon: '💬', text: '讲解人力贵、强度大', solve: 'AI 语音讲解，24h 不间断' },
      { icon: '🧾', text: '客商信息难留存、转化低', solve: '互动自动采集，线索可跟进' }
    ],
    benefits: [
      { icon: '📣', title: '智能引流', desc: '吸睛互动，主动引导关注' },
      { icon: '🗣️', title: '语音讲解', desc: '专业话术，多语言支持' },
      { icon: '💼', title: '线索采集', desc: '洽谈信息沉淀，二次触达' }
    ],
    scenes: [
      { name: '企业展台', desc: '引流打卡、互动体验', data: '人流 3×' },
      { name: '发布会', desc: '开场互动、产品展示', data: '热度 +80%' },
      { name: '全国巡展', desc: '多城复用，一套方案', data: '复用 10+城' }
    ],
    robots: [2, 5]
  },
  '工厂作业': {
    desc: '工业机器人自动化产线，降本35%，品质一致稳定',
    icon: '🏭', bgColor: '#E8F0FE', consultCount: 234, tags: ['官方正品', '产线升级', '节拍保障'],
    stats: { amp: '-35%成本', roi: '14个月', scene: '20+' },
    pains: [
      { icon: '🧑‍🏭', text: '招工难、用工贵、流动大', solve: '机器人稳定上岗，不请假不流失' },
      { icon: '📈', text: '产线产能瓶颈、扩展受限', solve: '柔性产线，产能随需提升' },
      { icon: '🧪', text: '人工一致性差、良率不稳', solve: '标准化作业，良率稳定' }
    ],
    benefits: [
      { icon: '⚙️', title: '高柔性', desc: '换产快，小批量多变' },
      { icon: '🎯', title: '质量稳定', desc: '节拍一致，良率可控' },
      { icon: '📇', title: '数据排产', desc: '工时数据反哺排产决策' }
    ],
    scenes: [
      { name: '装配线', desc: '上下料、锁付、检测', data: '节拍 +30%' },
      { name: '焊接/喷涂', desc: '路径精准、重复一致', data: '良率 99%+' },
      { name: '搬运码垛', desc: '重物自动码放', data: '人力 -50%' }
    ],
    robots: [2, 3]
  },
  '餐饮服务': {
    desc: '送餐 + 收餐机器人，翻台率提升25%，等餐体验更好',
    icon: '🍽️', bgColor: '#E8F0FE', consultCount: 345, tags: ['官方正品', '上手即用', '24h传菜'],
    stats: { amp: '+25%翻台', roi: '4个月', scene: '1000+' },
    pains: [
      { icon: '🧑‍🍳', text: '服务员难招、离职率高', solve: '传菜机器人顶班，全年稳定' },
      { icon: '🏃', text: '高峰传菜慢、上错菜', solve: '多桌精准配送、防撞' },
      { icon: '😐', text: '等餐久、体验差、差评', solve: '出餐即送，体验提升' }
    ],
    benefits: [
      { icon: '🍽️', title: '自动传菜', desc: '多桌精准、自动避障' },
      { icon: '🧹', title: '收餐归位', desc: '餐后自动回收餐盘' },
      { icon: '📈', title: '翻台提升', desc: '周转更快，营收增长' }
    ],
    scenes: [
      { name: '中餐厅', desc: '席间传菜、撤盘', data: '翻台 +25%' },
      { name: '火锅/自助', desc: '频繁上菜、收餐', data: '效率 +40%' },
      { name: '外卖打包', desc: '分拣、封签、出餐', data: '错单 -60%' }
    ],
    robots: [5, 4]
  },
  '养老陪护': {
    desc: '康养陪护机器人，7×24 守护长者，让家人更安心',
    icon: '👴', bgColor: '#E8F0FE', consultCount: 456, tags: ['官方正品', '紧急响应', '全天守护'],
    stats: { amp: '24h守护', roi: '7个月', scene: '60+' },
    pains: [
      { icon: '🧑‍⚕️', text: '护理人力紧缺、压力大', solve: '机器人分担看护，减轻负担' },
      { icon: '🌃', text: '夜间看护易失守', solve: '7×24 监测，夜间不缺席' },
      { icon: '📉', text: '长者健康变化难察觉', solve: '跌倒/异常监测，及时预警' }
    ],
    benefits: [
      { icon: '💊', title: '用药提醒', desc: '定时提醒，不漏服' },
      { icon: '🆘', title: '跌倒监测', desc: '异常即时上报家人' },
      { icon: '🗣️', title: '语音陪伴', desc: '陪聊、点播，缓解孤独' }
    ],
    scenes: [
      { name: '养老院', desc: '定时查房、用药提醒', data: '守护 24h' },
      { name: '居家养老', desc: '紧急呼叫、安全看护', data: '响应秒级' },
      { name: '康复训练', desc: '陪练、陪伴互动', data: '满意度 +30%' }
    ],
    robots: [1, 2]
  },
  '医院物资': {
    desc: '医院物流机器人，药品物资安全高效流转，降低院感风险',
    icon: '🏥', bgColor: '#E8F0FE', consultCount: 123, tags: ['官方正品', '无接触', '全程追溯'],
    stats: { amp: '-60%转运人力', roi: '9个月', scene: '80+' },
    pains: [
      { icon: '💊', text: '药品配送易错、难追溯', solve: '全流程可追踪，责任到件' },
      { icon: '🦠', text: '人工转运院感风险高', solve: '密闭无接触，降低交叉感染' },
      { icon: '🌙', text: '夜间紧急送药响应慢', solve: '24h 无人配送，随到随送' }
    ],
    benefits: [
      { icon: '📦', title: '密闭配送', desc: '无接触，降院感风险' },
      { icon: '🔍', title: '全程可溯', desc: '流转记录，责任清晰' },
      { icon: '🕑', title: '多科协同', desc: '药房-病区智能联动' }
    ],
    scenes: [
      { name: '药房→病区', desc: '药品按需配送', data: '出错 -70%' },
      { name: '手术室', desc: '无菌耗材定点送达', data: '准时 100%' },
      { name: '检验科', desc: '样本安全运输', data: '完整可溯' }
    ],
    robots: [4, 5]
  },
  '园区安防': {
    desc: '无人化安防巡检，园区安全无死角，异常秒级响应',
    icon: '🚓', bgColor: '#E8F0FE', consultCount: 289, tags: ['官方正品', '中控联动', '24h巡防'],
    stats: { amp: '24h现场', roi: '10个月', scene: '40+' },
    pains: [
      { icon: '💂', text: '保安人力成本高、缺口大', solve: '机器人巡防，替代反复巡视' },
      { icon: '🌙', text: '夜间巡逻缺位、盲区多', solve: '7×24 自主巡逻，不留死角' },
      { icon: '🚨', text: '异常事件响应慢', solve: 'AI 识别联动中控，秒级告警' }
    ],
    benefits: [
      { icon: '🛡️', title: '自主巡逻', desc: '路径自规划，全天候' },
      { icon: '👁️', title: '异常识别', desc: '陌生人/烟火 AI 告警' },
      { icon: '🔗', title: '中控联动', desc: '与视频监控一体调度' }
    ],
    scenes: [
      { name: '产业园', desc: '周界巡防、可疑识别', data: '巡防 24h' },
      { name: '厂区', desc: '重点区域值守', data: '覆盖率 100%' },
      { name: '仓库', desc: '防盗巡查、人员管理', data: '响应秒级' }
    ],
    robots: [1, 3]
  },
  '商超盘点': {
    desc: '智能盘点机器人，全品类存货实时精准，省90%人力',
    icon: '🛒', bgColor: '#E8F0FE', consultCount: 567, tags: ['官方正品', '夜间盘点', '数据精准'],
    stats: { amp: '-90%人力', roi: '6个月', scene: '500+' },
    pains: [
      { icon: '⏳', text: '人工盘点慢、易出错', solve: '夜间自动盘点，一次到位' },
      { icon: '🕐', text: '库存数据滞后、失真', solve: '实时货架数据，动态刷新' },
      { icon: '⚖️', text: '缺货与滞销难平衡', solve: '数据驱动补货，降库存' }
    ],
    benefits: [
      { icon: '🌙', title: '夜间盘点', desc: '闭店无人自动盘点' },
      { icon: '📡', title: '精准识别', desc: 'RFID+视觉，条条清晰' },
      { icon: '📊', title: '补货决策', desc: '缺货/滞销一目了然' }
    ],
    scenes: [
      { name: '大卖场', desc: '夜间全品类盘点', data: '人力 -90%' },
      { name: '便利店', desc: '高频快速清点', data: '效率 5×' },
      { name: '批发仓', desc: '批次、效期核对', data: '准确 99%+' }
    ],
    robots: [4, 6]
  },
  '应急勘察': {
    desc: '应急场景快速勘察，第一时间掌握现场，替代高危进入',
    icon: '🔍', bgColor: '#E8F0FE', consultCount: 432, tags: ['官方正品', '高危替代', '实时图传'],
    stats: { amp: '分钟级到场', roi: '定制', scene: '多灾种' },
    pains: [
      { icon: '🔥', text: '危险区域人员难以近身', solve: '机器人替代进入，人不涉险' },
      { icon: '⏱️', text: '现场信息获取慢', solve: '实时图传，指挥端即时可见' },
      { icon: '🧭', text: '救援指挥缺数据支撑', solve: '多机协同，热成像+回传' }
    ],
    benefits: [
      { icon: '🛡️', title: '高危替代', desc: '进入危险区，保全人员' },
      { icon: '📡', title: '实时图传', desc: '现场画面指挥端同步' },
      { icon: '🤝', title: '多机协同', desc: '空地一体，搜救增援' }
    ],
    scenes: [
      { name: '火灾', desc: '高温探测、搜救', data: '分钟级' },
      { name: '塌方', desc: '危险区勘察、定位', data: '安全进入' },
      { name: '水域搜救', desc: '热成像、夜间搜索', data: '覆盖广' }
    ],
    robots: [3, 1]
  },
  '农业巡检': {
    desc: '农业自动化巡检与监测，科学种植，产量提升20%',
    icon: '🌾', bgColor: '#E8F0FE', consultCount: 178, tags: ['官方正品', '精准农业', '24h值守'],
    stats: { amp: '+20%产量', roi: '12个月', scene: '200+' },
    pains: [
      { icon: '🌧️', text: '农田靠天吃饭、风险大', solve: '数据监测，科学预警' },
      { icon: '🐛', text: '病虫害发现晚、扩散快', solve: '早期识别，精准防治' },
      { icon: '👨‍🌾', text: '季节性劳动力缺口', solve: '机器人巡检，缓解用工' }
    ],
    benefits: [
      { icon: '🌿', title: '长势监测', desc: '多光谱看生长状况' },
      { icon: '🎯', title: '精准作业', desc: '变量施肥/喷药' },
      { icon: '🌙', title: '24h 值守', desc: '田间全天候巡查' }
    ],
    scenes: [
      { name: '大田', desc: '长势巡检、估产', data: '产量 +20%' },
      { name: '大棚', desc: '环境调控、补光', data: '温控精准' },
      { name: '果园', desc: '病虫害预警、挂果', data: '损失 -30%' }
    ],
    robots: [3, 1]
  },
  '科教实训': {
    desc: '机器人科教实训平台，产教融合，3倍教学效率',
    icon: '📚', bgColor: '#E8F0FE', consultCount: 201, tags: ['官方正品', '二次开发', '赛学结合'],
    stats: { amp: '3×教学', roi: '定制', scene: '高校' },
    pains: [
      { icon: '🔧', text: '实训设备单一、落后', solve: '多机种平台，贴近产业' },
      { icon: '✋', text: '学生动手机会少', solve: '开放接口，人人可实操' },
      { icon: '📖', text: '课程内容滞后产业', solve: '产教融合，案例即教材' }
    ],
    benefits: [
      { icon: '🤖', title: '多机种实训', desc: '人形/四足/机械臂齐备' },
      { icon: '🧩', title: '开放二次开发', desc: '接口开放，支持科研' },
      { icon: '🏆', title: '赛学结合', desc: '以赛促学，竞赛对接' }
    ],
    scenes: [
      { name: '高校竞赛', desc: '实训、备赛', data: '规模 3×' },
      { name: '职业院校', desc: '技能认证、实操', data: '上手快' },
      { name: '科普基地', desc: '体验、研学', data: '吸引力大增' }
    ],
    robots: [2, 1]
  },
  '航天探索': {
    desc: '航天场景机器人探索与作业，恶劣环境稳定可靠',
    icon: '🚀', bgColor: '#E8F0FE', consultCount: 89, tags: ['官方正品', '环境适应', '数据闭环'],
    stats: { amp: '精准验证', roi: '定制', scene: '前沿' },
    pains: [
      { icon: '🧪', text: '极端环境人工作业难', solve: '机器人适应恶劣环境' },
      { icon: '🛠️', text: '精密装配/排查风险高', solve: '高精度执行，减少误差' },
      { icon: '📁', text: '任务闭环依赖数据', solve: '过程数据完整，可复现' }
    ],
    benefits: [
      { icon: '🛰️', title: '环境适应', desc: '耐极端温度、辐射' },
      { icon: '🎯', title: '精密装配', desc: '高精度、一致性' },
      { icon: '🔁', title: '数据闭环', desc: '无人场景完整留痕' }
    ],
    scenes: [
      { name: '实验室', desc: '精密装配、验证', data: '精度 0.1mm' },
      { name: '发射场', desc: '环境保障、巡检', data: '可靠运行' },
      { name: '深空', desc: '自主探索、作业', data: '长航时' }
    ],
    robots: [2, 3]
  }
}

export default {
  data() {
    return {
      robots: ROBOTS,
      scheme: {
        ...DEFAULT_SCHEME,
        title: '',
        icon: DEFAULT_SCHEME.icon,
        consultCount: DEFAULT_SCHEME.consultCount,
        bgColor: DEFAULT_SCHEME.bgColor
      },
      processList: ['需求诊断', '方案定制', '上门部署', '全程售后']
    }
  },

  computed: {
    pageTitle() {
      return this.scheme.title || '方案定制'
    },
    mainRobots() {
      const ids = Array.isArray(this.scheme.robots) ? this.scheme.robots : []
      const list = ids.map(id => this.robots.find(r => r.id === id)).filter(Boolean)
      // 在「本方案」推荐基础上，从机器人库再补 2 台（自动去重），合计多 2 台
      const extras = this.robots.filter(r => !ids.includes(r.id)).slice(0, 2)
      return list.concat(extras)
    }
  },

  onLoad(options) {
    const title = options.title ? decodeURIComponent(options.title) : ''
    const icon = options.icon ? decodeURIComponent(options.icon) : ''
    const consultCount = options.consultCount ? decodeURIComponent(options.consultCount) : ''
    const bgColor = options.bgColor ? decodeURIComponent(options.bgColor) : ''

    const base = SCHEME_DB[title] || DEFAULT_SCHEME
    this.scheme = {
      ...base,
      // 点谁显示谁的方案名/图标/咨询数（无对应 content 时走通用兜底）
      title: title || base.title || '场景方案',
      icon: icon || base.icon,
      consultCount: consultCount || base.consultCount,
      bgColor: bgColor || base.bgColor
    }
  },

  methods: {
    goBack() {
      uni.navigateBack()
    },
    onConsult() {
      uni.showModal({
        title: '免费定制方案',
        content: `方案专家将在 1 小时内联系您\n为您定制「${this.scheme.title}」专属方案`,
        confirmText: '好的',
        showCancel: false
      })
    },
    onCardTap(robot) {
      uni.showToast({ title: `「${robot.title}」工程师将为您讲解`, icon: 'none' })
    }
  }
}
</script>

<style scoped>
/* ===== 冷科技蓝 · 企业服务感 ===== */
page {
  background: #F2F5FA;
  height: 100%;
}
.detail-page {
  display: flex;
  flex-direction: column;
  height: 100vh;
  background: linear-gradient(180deg, #EAF1FF 0%, #F2F5FA 220rpx);
  color: #0F172A;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
}

/* ===== 顶部导航 ===== */
.nav-bar {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 88rpx;
  padding: 0 20rpx;
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(10rpx);
  box-shadow: 0 2rpx 12rpx rgba(15, 23, 42, 0.04);
}
.nav-left, .nav-right {
  width: 76rpx;
  display: flex;
  align-items: center;
}
.nav-back { font-size: 44rpx; font-weight: 300; color: #0F172A; }
.back-text { font-size: 26rpx; color: #334155; margin-left: 4rpx; }
.nav-title {
  flex: 1;
  text-align: center;
  font-size: 32rpx;
  font-weight: 600;
  color: #0F172A;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.nav-right { justify-content: flex-end; }
.nav-share { font-size: 34rpx; color: #2563EB; }

/* ===== 滚动区 ===== */
.scroll-content { flex: 1; min-height: 0; }

/* ===== Hero 大卡 ===== */
.hero {
  margin: 20rpx 20rpx 0;
  padding: 40rpx 32rpx 36rpx;
  border-radius: 28rpx;
  background: linear-gradient(150deg, #D3E3FF 0%, #FFFFFF 72%);
  box-shadow: 0 20rpx 50rpx rgba(37, 99, 235, 0.12);
  color: #0F172A;
}
.hero-top { display: flex; align-items: center; gap: 20rpx; }
.hero-icon-wrap {
  width: 96rpx;
  height: 96rpx;
  border-radius: 24rpx;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  background: rgba(255, 255, 255, 0.55);
}
.hero-icon { font-size: 56rpx; }
.hero-head { flex: 1; min-width: 0; }
.hero-title {
  font-size: 40rpx;
  font-weight: 700;
  line-height: 1.2;
  display: block;
}
.hero-consult {
  display: inline-block;
  margin-top: 10rpx;
  font-size: 22rpx;
  color: #1D4ED8;
  background: rgba(37, 99, 235, 0.10);
  padding: 4rpx 18rpx;
  border-radius: 30rpx;
}
.hero-desc {
  margin-top: 20rpx;
  font-size: 28rpx;
  line-height: 1.6;
  color: #334155;
}
.hero-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 14rpx;
  margin-top: 24rpx;
}
.hero-tag {
  font-size: 22rpx;
  color: #1D4ED8;
  background: rgba(37, 99, 235, 0.10);
  border: 1rpx solid rgba(37, 99, 235, 0.20);
  padding: 6rpx 20rpx;
  border-radius: 30rpx;
}
.hero-stats {
  display: flex;
  margin-top: 30rpx;
  padding-top: 26rpx;
  border-top: 1rpx solid rgba(37, 99, 235, 0.15);
}
.stat-item { flex: 1; text-align: center; }
.stat-item + .stat-item { border-left: 1rpx solid rgba(37, 99, 235, 0.15); }
.stat-num { font-size: 40rpx; font-weight: 700; display: block; }
.stat-label { font-size: 22rpx; color: #475569; margin-top: 6rpx; display: block; }

/* ===== 通用卡片 ===== */
.card {
  background: #fff;
  border-radius: 24rpx;
  margin: 20rpx 20rpx 0;
  padding: 30rpx 28rpx;
  box-shadow: 0 6rpx 24rpx rgba(15, 23, 42, 0.05);
}
.card-title-row { display: flex; flex-direction: column; margin-bottom: 24rpx; }
.card-title { font-size: 32rpx; font-weight: 700; color: #0F172A; }
.card-sub { font-size: 24rpx; color: #64748B; margin-top: 6rpx; }

/* ===== 痛点 → 方案 ===== */
.pain-list { display: flex; flex-direction: column; gap: 20rpx; }
.pain-item {
  background: #F6F9FE;
  border-radius: 18rpx;
  padding: 22rpx 20rpx;
  border-left: 6rpx solid #2563EB;
}
.pain-left { display: flex; align-items: flex-start; gap: 12rpx; }
.pain-icon { font-size: 32rpx; }
.pain-text { font-size: 27rpx; color: #1E293B; font-weight: 500; line-height: 1.5; flex: 1; }
.pain-solve {
  display: flex;
  align-items: center;
  gap: 10rpx;
  margin-top: 16rpx;
  padding-top: 16rpx;
  border-top: 1rpx solid rgba(37, 99, 235, 0.14);
}
.solve-icon {
  width: 32rpx; height: 32rpx; line-height: 32rpx; text-align: center;
  border-radius: 50%;
  background: #2563EB; color: #fff; font-size: 20rpx; font-weight: 700;
  flex-shrink: 0;
}
.solve-text { font-size: 26rpx; color: #1D4ED8; font-weight: 500; flex: 1; line-height: 1.5; }

/* ===== 方案亮点（白色卡片 · 三列） ===== */
.benefit-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14rpx; }
.benefit-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 26rpx 12rpx;
  background: #FAFCFF;
  border: 1rpx solid #EDF1F7;
  border-radius: 16rpx;
}
.benefit-icon {
  width: 64rpx; height: 64rpx; border-radius: 18rpx;
  display: flex; align-items: center; justify-content: center;
  background: #E4EEFF; font-size: 34rpx; margin-bottom: 12rpx;
}
.benefit-title { font-size: 26rpx; font-weight: 600; color: #1E293B; }
.benefit-desc {
  font-size: 22rpx; color: #7A8BA6; margin-top: 6rpx; line-height: 1.4;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}

/* ===== 场景横滑 ===== */
.scene-scroll { white-space: nowrap; padding-bottom: 8rpx; }
.scene-card {
  display: inline-block;
  width: 300rpx;
  margin-right: 16rpx;
  background: #F6F9FE;
  border-radius: 18rpx;
  overflow: hidden;
  border: 1rpx solid #E6EEFF;
  vertical-align: top;
}
.scene-mark {
  padding: 22rpx 20rpx 18rpx;
  background: linear-gradient(135deg, #2563EB, #0EA5B7);
}
.scene-name { font-size: 27rpx; font-weight: 600; color: #fff; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.scene-desc { display: block; padding: 16rpx 20rpx 4rpx; font-size: 24rpx; color: #475569; line-height: 1.5; white-space: normal; min-height: 76rpx; }
.scene-data { padding: 0 20rpx 20rpx; }
.scene-data-num { display: inline-block; font-size: 24rpx; font-weight: 700; color: #2563EB; }
.data-note { font-size: 20rpx; color: #94A3B8; margin-top: 16rpx; padding-top: 16rpx; border-top: 1rpx solid #EEF2F7; }

/* ===== 产品（机器人）双列 · 一行两个 ===== */
.product-grid { display: flex; flex-wrap: wrap; justify-content: space-between; }
.product-card {
  width: 48%;
  background: #fff;
  border: 1rpx solid #EDF1F7;
  border-radius: 18rpx;
  overflow: hidden;
  margin-bottom: 16rpx;
}
.product-img-wrap { position: relative; width: 100%; aspect-ratio: 1 / 1; background: #F0F4FA; display: flex; align-items: center; justify-content: center; }
.product-img { width: 100%; height: 100%; }
.product-emoji { font-size: 72rpx; }
.product-tag {
  position: absolute; top: 12rpx; left: 12rpx;
  font-size: 18rpx; padding: 4rpx 14rpx; border-radius: 20rpx; color: #fff;
  background: #FF6B00;
}
.product-tag.新品 { background: #2563EB; }
.product-info { padding: 16rpx 18rpx 10rpx; }
.product-name { font-size: 28rpx; font-weight: 600; color: #0F172A; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.product-desc { font-size: 22rpx; color: #64748B; margin-top: 6rpx; display: block; }
.product-action {
  margin: 10rpx 18rpx 18rpx;
  text-align: center;
  font-size: 24rpx;
  font-weight: 600;
  color: #2563EB;
  padding: 14rpx 0;
  border-radius: 40rpx;
  border: 1rpx solid #BBD2FF;
  background: #F3F8FF;
}

/* ===== 交付流程 ===== */
.process { display: flex; align-items: flex-start; justify-content: space-between; }
.process-item { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 12rpx; }
.process-dot {
  width: 56rpx; height: 56rpx; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: #E4EEFF; border: 1rpx solid #BBD2FF;
}
.process-num { font-size: 28rpx; font-weight: 700; color: #2563EB; }
.process-name { font-size: 22rpx; color: #475569; text-align: center; }

/* ===== 底部吸底 CTA（悬浮胶囊条） ===== */
.bottom-bar {
  position: fixed;
  bottom: calc(28rpx + env(safe-area-inset-bottom));
  left: 24rpx; right: 24rpx;
  display: flex;
  align-items: center;
  gap: 20rpx;
  padding: 18rpx 24rpx;
  background: #fff;
  border: 1rpx solid #EDF1F7;
  border-radius: 48rpx;
  box-shadow: 0 10rpx 30rpx rgba(15, 23, 42, 0.10);
  z-index: 50;
}
.btn-cta {
  flex: 1;
  height: 88rpx;
  border: none;
  border-radius: 44rpx;
  background: linear-gradient(135deg, #1E6FFF, #0EA5B7);
  color: #fff;
  font-size: 30rpx;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 10rpx 24rpx rgba(30, 111, 255, 0.3);
}
.btn-cta:active { opacity: 0.85; }
</style>
