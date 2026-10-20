import { sceneApi } from '@/utils/api'
import { BASE_URL } from '@/utils/config'

// ================================================================
// 场景方案 · 统一数据源（唯一数据家）
// 从 changjing.vue 抽出为纯 .js 模块：微信小程序(uni-app mp-weixin)编译下，
// 从 .vue 文件 import 具名导出会失效(函数/常量绑定丢失 → "not defined")，
// 迁到 .js 后所有平台都能正确载入。changjing.vue 仅做转发(export ... from)保持兼容。
// ================================================================

import { createSceneCatalog, normalizeSceneImage } from '@/utils/scene-catalog'

const sceneCatalog = createSceneCatalog(() => sceneApi.getScenes(), {
  imageUrl: url => normalizeSceneImage(url, BASE_URL)
})
export const sceneProducts = sceneCatalog.rows
export const loadBackendScenes = options => sceneCatalog.load(options)

// 分类：key → {label}（与机器页 robot/index.vue 一致）
export const categoryMap = {
  business_event: { label: '商业活动' },
  enterprise: { label: '企业商用' },
  culture_tourism: { label: '文旅景区' },
  education: { label: '科普教育' },
  security: { label: '安全巡检' },
  warehouse_logistics: { label: '仓储物流' },
  industry: { label: '工业现场' },
  medical: { label: '医疗康养' },
  home: { label: '家庭个人' }
}

// 历史说明性示例，仅保留迁移参考；不用于公开列表、详情或交易。
export const SCENE_EXAMPLES = [
  { id: 1, name: '展会品牌活动', icon: '🎪', bg: '#FEF3C7', desc: '品牌展示、产品推介、互动体验', category: 'business_event', tags: ['展会', '品牌', '互动'] },
  { id: 2, name: '商场门店引流', icon: '🛍️', bg: '#E8F0FE', desc: '商场引流、导购服务', category: 'business_event', tags: ['商场', '引流', '导购'] },
  { id: 3, name: '婚礼宴会演出', icon: '💒', bg: '#FCE4EC', desc: '婚礼表演、迎宾互动', category: 'business_event', tags: ['婚礼', '宴会', '演出'] },
  { id: 4, name: '直播短视频拍摄', icon: '📹', bg: '#F0F4FA', desc: '直播辅助、短视频互动', category: 'business_event', tags: ['直播', '短视频', '拍摄'] },
  { id: 5, name: '节庆主题活动', icon: '🎉', bg: '#FFF3E0', desc: '节庆氛围、主题活动互动', category: 'business_event', tags: ['节庆', '活动', '互动'] },
  { id: 6, name: '展厅展馆讲解接待', icon: '🏛️', bg: '#E8F0FE', desc: '展厅讲解、导览服务', category: 'enterprise', tags: ['展厅', '讲解', '导览'] },
  { id: 7, name: '政务大厅迎宾导览', icon: '🏢', bg: '#E8F0FE', desc: '政务迎宾、导览指引', category: 'enterprise', tags: ['政务', '迎宾', '导览'] },
  { id: 8, name: '酒店大堂服务', icon: '🏨', bg: '#F0F4FA', desc: '酒店迎宾、行李服务', category: 'enterprise', tags: ['酒店', '迎宾', '服务'] },
  { id: 9, name: '企业前台接待', icon: '💼', bg: '#F0F4FA', desc: '前台接待、访客管理', category: 'enterprise', tags: ['前台', '接待', '门禁'] },
  { id: 10, name: '文旅景点短期表演', icon: '🎭', bg: '#FFF3E0', desc: '景区短期演出、活动互动', category: 'culture_tourism', tags: ['景区', '演出', '活动'] },
  { id: 11, name: '博物馆长期驻场运营', icon: '🏛️', bg: '#E8F0FE', desc: '博物馆驻场讲解、运营服务', category: 'culture_tourism', tags: ['博物馆', '驻场', '讲解'] },
  { id: 12, name: '景区场馆导览互动', icon: '🗺️', bg: '#E8F5E9', desc: '景区导览、互动体验', category: 'culture_tourism', tags: ['文旅', '导览', '互动'] },
  { id: 13, name: '科技馆科普展演', icon: '🔬', bg: '#E8F5E9', desc: '科普讲解、互动展演', category: 'education', tags: ['科技馆', '科普', '展演'] },
  { id: 14, name: '学校科创教学', icon: '📚', bg: '#FFF3E0', desc: '科创教学、编程实践', category: 'education', tags: ['学校', '科创', '教学'] },
  { id: 15, name: '研学活动体验', icon: '🎒', bg: '#F0F4FA', desc: '研学体验、科技实践', category: 'education', tags: ['研学', '体验', '实践'] },
  { id: 16, name: '机器人赛事活动', icon: '🏆', bg: '#FEF3C7', desc: '赛事组织、竞技互动', category: 'education', tags: ['赛事', '格斗', '竞速'] },
  { id: 17, name: '厂区园区巡逻', icon: '🚓', bg: '#E8F0FE', desc: '园区巡逻、安全监测', category: 'security', tags: ['巡逻', '园区', '安全'] },
  { id: 18, name: '仓库安防值守', icon: '🏚️', bg: '#F0F4FA', desc: '仓库值守、异常报警', category: 'security', tags: ['仓库', '安防', '值守'] },
  { id: 19, name: '矿区现场巡检', icon: '⛏️', bg: '#E8F0FE', desc: '矿区巡检、设备监测', category: 'security', tags: ['矿区', '巡检', '监测'] },
  { id: 20, name: '机房站点巡查', icon: '🖥️', bg: '#F0F4FA', desc: '机房巡查、环境监测', category: 'security', tags: ['机房', '巡查', '监测'] },
  { id: 21, name: '厂区物料转运', icon: '📦', bg: '#F0F4FA', desc: '物料转运、零部件配送', category: 'warehouse_logistics', tags: ['物料', '转运', '搬运'] },
  { id: 22, name: '仓库货物分拣搬运', icon: '📦', bg: '#E8F0FE', desc: '仓库货物分拣、搬运', category: 'warehouse_logistics', tags: ['仓储', '仓库', '分拣'] },
  { id: 23, name: '产线演示展示', icon: '🏭', bg: '#E8F0FE', desc: '产线演示、自动化展示', category: 'industry', tags: ['产线', '演示', '自动化'] },
  { id: 24, name: '现场安全核查', icon: '⚠️', bg: '#FEF3C7', desc: '安全检查、点位核查', category: 'industry', tags: ['安全', '核查', '现场'] },
  { id: 25, name: '医院导诊引导', icon: '🏥', bg: '#E8F0FE', desc: '导诊服务、科室导航', category: 'medical', tags: ['医院', '导诊', '导航'] },
  { id: 26, name: '康养中心陪护互动', icon: '💝', bg: '#FCE4EC', desc: '康养陪护、互动关怀', category: 'medical', tags: ['康养', '陪护', '互动'] },
  { id: 27, name: '家庭陪伴交互', icon: '🏠', bg: '#F0F4FA', desc: '家庭陪伴、儿童教育', category: 'home', tags: ['家庭', '陪伴', '交互'] },
  { id: 28, name: '个人爱好者体验', icon: '🧑‍💻', bg: '#F0F4FA', desc: '爱好者体验、开发调试', category: 'home', tags: ['爱好者', '体验', '开发'] }
]

// 默认方案（方案定制视图的兜底内容）
export const DEFAULT_SCHEME = {
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

// 各方案专属内容（方案定制视图按 title 匹配取用；无匹配走 DEFAULT_SCHEME）
export const SCHEME_DB = {
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
      { icon: '🌾', title: '长势监测', desc: '多光谱看生长状况' },
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

// ================================================================
// 每场景专属方案内容（键 = scene id，覆盖 1-28）
// 「方案定制」视图按 sceneId 查这份；未覆盖场景回退 DEFAULT_SCHEME
// ================================================================
export const SCENE_SCHEMES = {
  1: { desc: '品牌展示、产品推介、互动体验，让展台成为全场焦点', icon: '🎪', bgColor: '#E8F0FE', consultCount: 678, tags: ['可租用', '多城复用', '线索采集'], stats: { amp: '3×引流', roi: '活动期', scene: '多城复用' }, pains: [ { icon: '🚶', text: '展台冷清、人流看过就走', solve: '智能迎宾引流，成为打卡点' }, { icon: '💬', text: '讲解人力贵、强度大', solve: 'AI 语音讲解，24h 不间断' }, { icon: '🧾', text: '客商信息难留存、转化低', solve: '互动自动采集，线索可跟进' } ], benefits: [ { icon: '📣', title: '智能引流', desc: '吸睛互动，主动引导关注' }, { icon: '🗣️', title: '语音讲解', desc: '专业话术，多语言支持' }, { icon: '💼', title: '线索采集', desc: '洽谈信息沉淀，二次触达' } ], scenes: [ { name: '企业展台', desc: '引流打卡、互动体验', data: '人流 3×' }, { name: '发布会', desc: '开场互动、产品展示', data: '热度 +80%' } ] },
  2: { desc: '商场门店引流、导购服务，把路过客流变成进店顾客', icon: '🛍️', bgColor: '#E8F0FE', consultCount: 512, tags: ['官方正品', '夜间可用', '数据可析'], stats: { amp: '+35%进店', roi: '5个月', scene: '连锁' }, pains: [ { icon: '👣', text: '门口客流大、进店转化低', solve: '互动迎宾+指引，拉动进店' }, { icon: '🧭', text: '顾客找不到货架/柜台', solve: '主动导购带路，精准引导' }, { icon: '📉', text: '促销活动反馈难追踪', solve: '互动数据实时回传，活动可析' } ], benefits: [ { icon: '🎯', title: '精准引流', desc: '识别路过客，主动引导入店' }, { icon: '🛎️', title: '导购服务', desc: '商品讲解、带路到位' }, { icon: '📊', title: '数据洞察', desc: '客流与偏好沉淀决策' } ], scenes: [ { name: '连锁商场', desc: '引流导购、活动互动', data: '进店 +35%' }, { name: '临街门店', desc: '路客拦截、迎宾入店', data: '客流 2×' } ] },
  3: { desc: '婚礼表演、迎宾互动，为宴会现场增添科技仪式感', icon: '💒', bgColor: '#FCE4EC', consultCount: 198, tags: ['可租用', '准时到场', '氛围担当'], stats: { amp: '现场记忆点', roi: '单次', scene: '婚宴' }, pains: [ { icon: '😐', text: '流程平淡、宾客记忆点少', solve: '机器人表演/迎宾制造惊喜' }, { icon: '👥', text: '迎宾人力缺口、布置费时', solve: '机器人主动迎宾、互动合影' }, { icon: '🎬', text: '现场影像素材重复', solve: '互动环节自动生成亮点影像' } ], benefits: [ { icon: '🎭', title: '氛围担当', desc: '表演、互动增添仪式感' }, { icon: '🤝', title: '智能迎宾', desc: '迎宾问好、合影留念' }, { icon: '📸', title: '取材亮点', desc: '互动片段成片引流' } ], scenes: [ { name: '婚礼仪式', desc: '迎宾表演、互动合影', data: '记忆点 UP' }, { name: '企业年会', desc: '开场助兴、互动抽奖', data: '气氛 UP' } ] },
  4: { desc: '直播辅助、短视频互动，稳定机位与趣味互动双提升', icon: '📹', bgColor: '#F0F4FA', consultCount: 342, tags: ['官方正品', '智能跟拍', '多平台'], stats: { amp: '效率 2×', roi: '3个月', scene: '内容' }, pains: [ { icon: '🎥', text: '直播机位不稳、跟拍难', solve: '机器人稳定跟拍、自动运镜' }, { icon: '⏰', text: '长时间直播人手累', solve: '机器支架+互动，辅助值守' }, { icon: '📦', text: '带货展示僵硬、转化低', solve: '互动展示+实物演示更生动' } ], benefits: [ { icon: '🎬', title: '稳定跟拍', desc: '自动运镜、画面平稳' }, { icon: '🤖', title: '趣味互动', desc: '弹幕互动、实物展示' }, { icon: '🔁', title: '多端复用', desc: '一个机位多平台开播' } ], scenes: [ { name: '品牌直播', desc: '带货展示、互动弹幕', data: '转化 +25%' }, { name: '短视频', desc: '创意运镜、自动成片', data: '产出 2×' } ] },
  5: { desc: '节庆氛围、主题活动互动，让现场热闹又有记忆感', icon: '🎉', bgColor: '#FFF3E0', consultCount: 176, tags: ['可租用', '节日专供', '氛围营造'], stats: { amp: '客流 2×', roi: '单次', scene: '节庆' }, pains: [ { icon: '🍂', text: '节庆同质化、现场冷清', solve: '互动装置引流、制造热度' }, { icon: '👋', text: '主持人/演员成本高', solve: '机器人轮班互动、全天候' }, { icon: '📸', text: '活动场景缺传播素材', solve: '互动自动成片、便于传播' } ], benefits: [ { icon: '🎈', title: '节庆吸睛', desc: '主题互动、氛围拉满' }, { icon: '🤖', title: '全天活力', desc: '轮班互动不停歇' }, { icon: '📹', title: '传播素材', desc: '互动影片自带话题' } ], scenes: [ { name: '商场节庆', desc: '主题巡游、互动打卡', data: '客流 2×' }, { name: '园区活动', desc: '庆典表演、互动游戏', data: '热度 UP' } ] },
  6: { desc: '展厅讲解、导览服务，让每件展品都会"开口讲话"', icon: '🏛️', bgColor: '#E8F0FE', consultCount: 458, tags: ['官方正品', '多语讲解', '长期驻场'], stats: { amp: '讲解 3×', roi: '6个月', scene: '展厅' }, pains: [ { icon: '🗣️', text: '讲解员成本高、难覆盖', solve: '机器人全天候多语讲解' }, { icon: '🚶', text: '观众走马观花、印象浅', solve: '互动讲解让展品被记住' }, { icon: '🌙', text: '夜间/闭馆无法接待', solve: '7×24 待命、可预约参观' } ], benefits: [ { icon: '😮', title: '立体讲解', desc: '语音+互动、引人入胜' }, { icon: '🌐', title: '多语言', desc: '中英等多语切换' }, { icon: '🔁', title: '长期驻场', desc: '稳定讲解、降本增效' } ], scenes: [ { name: '企业展厅', desc: '产品讲解、访客导览', data: '讲解 3×' }, { name: '行业展馆', desc: '专业解说、互动答疑', data: '好评 +30%' } ] },
  7: { desc: '政务迎宾、导览指引，让办事居民少跑一趟、更顺手', icon: '🏢', bgColor: '#E8F0FE', consultCount: 287, tags: ['官方正品', '引导分流', '24h值守'], stats: { amp: '分流 +40%', roi: '7个月', scene: '政务' }, pains: [ { icon: '🧩', text: '办事窗口多、流程难懂', solve: '智能引导分流、少排队' }, { icon: '🙎', text: '咨询台人力紧张、答复慢', solve: '机器人常驻解答常见问题' }, { icon: '⏱️', text: '高峰拥挤、体验差', solve: '分时引导、秩序井然' } ], benefits: [ { icon: '🔀', title: '智能导办', desc: '按事项引导到对应窗口' }, { icon: '💬', title: '常驻咨询', desc: '常见问题即问即答' }, { icon: '🧍', title: '秩序疏导', desc: '高峰分流、减少聚集' } ], scenes: [ { name: '服务大厅', desc: '导办分流、答疑指引', data: '分流 +40%' }, { name: '便民中心', desc: '自助咨询、叫号引导', data: '等候 -30%' } ] },
  8: { desc: '酒店迎宾、行李服务，让住客从进门就感到被照顾', icon: '🏨', bgColor: '#F0F4FA', consultCount: 297, tags: ['官方正品', '上门部署', '全天候'], stats: { amp: '+40%响应', roi: '5个月', scene: '酒店' }, pains: [ { icon: '👣', text: '客房配送依赖人工、跑腿贵', solve: '机器人 24h 无人配送' }, { icon: '⏳', text: '服务响应慢、客人体验差', solve: '随叫随到、秒级响应' }, { icon: '👥', text: '高峰期人手不足', solve: '低成本增援、标准统一' } ], benefits: [ { icon: '🛎️', title: '24h 配送', desc: '物品、餐食随时送达' }, { icon: '🤝', title: '礼宾迎宾', desc: '大堂引流、主动问候' }, { icon: '💸', title: '降本增效', desc: '减少跑腿人力' } ], scenes: [ { name: '客房服务', desc: '洗漱品、外卖即时送达', data: '响应 +40%' }, { name: '宴会传菜', desc: '传菜收餐自动往返', data: '翻台 +20%' } ] },
  9: { desc: '前台接待、访客管理，让企业门面更专业、来访更顺畅', icon: '💼', bgColor: '#F0F4FA', consultCount: 236, tags: ['官方正品', '访客登记', '公司形象'], stats: { amp: '接待 2×', roi: '6个月', scene: '企业' }, pains: [ { icon: '📝', text: '访客登记繁琐、身份核实慢', solve: '机器人自助登记、身份核验' }, { icon: '🛋️', text: '前台接待忙、来访等待久', solve: '主动问候引导、分流通知' }, { icon: '🏢', text: '公司形象、体验待提升', solve: '机器人门面提升品牌感' } ], benefits: [ { icon: '🧾', title: '访客登记', desc: '自助核验、自动通知' }, { icon: '🤖', title: '前台助手', desc: '问候、指引、答疑' }, { icon: '✨', title: '形象加持', desc: '科技门面增信任' } ], scenes: [ { name: '企业前台', desc: '访客登记、引导通知', data: '接待 2×' }, { name: '办公大厅', desc: '访客接引、礼宾接待', data: '体验 UP' } ] },
  10: { desc: '景区短期演出、活动互动，让游客玩得开心、记得住', icon: '🎭', bgColor: '#FFF3E0', consultCount: 208, tags: ['可租用', '季节短租', '文旅吸睛'], stats: { amp: '客流 2×', roi: '单次', scene: '景区' }, pains: [ { icon: '🍂', text: '淡季游客少、景区冷清', solve: '互动演出制造热点引流' }, { icon: '💃', text: '演员驻场成本高', solve: '机器人轮演、无人值守' }, { icon: '📸', text: '打卡素材少、传播弱', solve: '互动打卡自带传播' } ], benefits: [ { icon: '🎭', title: '互动演出', desc: '巡游、表演、互动' }, { icon: '💸', title: '低成本', desc: '无人驻演、随用随租' }, { icon: '📱', title: '传播力', desc: '打卡内容自发扩散' } ], scenes: [ { name: '文旅节庆', desc: '巡游互动、灯光配合', data: '客流 2×' }, { name: '景区演出', desc: '定点表演、游客互动', data: '好评 +25%' } ] },
  11: { desc: '博物馆驻场讲解、运营服务，让文物故事讲得更生动', icon: '🏛️', bgColor: '#E8F0FE', consultCount: 312, tags: ['官方正品', '长期驻场', '专业讲解'], stats: { amp: '讲解 3×', roi: '8个月', scene: '文博' }, pains: [ { icon: '🗣️', text: '专业讲解员稀缺、成本高', solve: '机器人标准化场馆讲解' }, { icon: '👦', text: '青少年观展走马观花', solve: '互动讲解加深理解兴趣' }, { icon: '🌙', text: '延时开放难以服务', solve: '7×24 待命、晚间专场' } ], benefits: [ { icon: '🏺', title: '专业讲解', desc: '知识准确、标准统一' }, { icon: '🎓', title: '研学友好', desc: '互动问答、寓教于乐' }, { icon: '🔁', title: '长期驻场', desc: '稳定服务、降本' } ], scenes: [ { name: '常设展厅', desc: '文物讲解、互动问答', data: '讲解 3×' }, { name: '研学专场', desc: '导览互动、知识闯关', data: '兴趣 UP' } ] },
  12: { desc: '景区导览、互动体验，把迷路和排队都交给机器人', icon: '🗺️', bgColor: '#E8F5E9', consultCount: 224, tags: ['官方正品', '导航导览', '多语服务'], stats: { amp: '导览 2×', roi: '7个月', scene: '景区' }, pains: [ { icon: '🧭', text: '景区大人多、游客易迷路', solve: '机器人带路、精准导览' }, { icon: '👥', text: '讲解排队、服务不均', solve: '机器人分流、人人可问' }, { icon: '🌐', text: '外宾接待语言障碍', solve: '多语讲解、无障碍服务' } ], benefits: [ { icon: '🧭', title: '精准导览', desc: '带路讲解、不走回头路' }, { icon: '🌐', title: '多语服务', desc: '中英多语随切' }, { icon: '🧍', title: '分流减负', desc: '人人可问、排队更少' } ], scenes: [ { name: '大型景区', desc: '带路导览、定点讲解', data: '导览 2×' }, { name: '主题公园', desc: '互动导览、打卡合影', data: '好评 UP' } ] },
  13: { desc: '科普讲解、互动展演，让科学"看得见、摸得着"', icon: '🔬', bgColor: '#E8F5E9', consultCount: 189, tags: ['官方正品', '科普教育', '亲子友好'], stats: { amp: '互动 3×', roi: '7个月', scene: '科技馆' }, pains: [ { icon: '🧐', text: '科普枯燥、孩子没兴趣', solve: '互动展演让科学好玩' }, { icon: '👨‍👩‍👧', text: '家长陪玩累、讲解有限', solve: '机器人自主讲解互动' }, { icon: '📉', text: '科普展品利用率低', solve: '机器人带活展品、持续互动' } ], benefits: [ { icon: '🤖', title: '互动展演', desc: '科学演示、动手体验' }, { icon: '🧒', title: '亲子友好', desc: '孩子乐学、家长省心' }, { icon: '📈', title: '展品盘活', desc: '让静态展品动起来' } ], scenes: [ { name: '科技馆', desc: '科普讲解、互动实验', data: '互动 3×' }, { name: '科普进校园', desc: '机器人科普、展演互动', data: '兴趣 UP' } ] },
  14: { desc: '科创教学、编程实践，让课堂的机器人真正"跑起来"', icon: '📚', bgColor: '#FFF3E0', consultCount: 201, tags: ['官方正品', '二次开发', '赛学结合'], stats: { amp: '3×教学', roi: '定制', scene: '校园' }, pains: [ { icon: '🧰', text: '器材单一、学生动手少', solve: '多机种平台、人人可实操' }, { icon: '✋', text: '课程滞后于产业', solve: '产教融合、案例即教材' }, { icon: '🏆', text: '缺竞赛对标', solve: '赛学结合、以赛促学' } ], benefits: [ { icon: '🤖', title: '多机种实训', desc: '人形/四足/机械臂齐备' }, { icon: '🧩', title: '开放开发', desc: '接口开放、支持科研' }, { icon: '🏆', title: '赛学结合', desc: '对接竞赛、技能认证' } ], scenes: [ { name: '高校实训', desc: '机器人教学、开发实践', data: '效率 3×' }, { name: '中学科创', desc: '编程实践、兴趣激发', data: '受众 2×' } ] },
  15: { desc: '研学体验、科技实践，让每一次研学都"不虚此行"', icon: '🎒', bgColor: '#F0F4FA', consultCount: 178, tags: ['官方正品', '研学定制', '安全可控'], stats: { amp: '体验 2×', roi: '单次', scene: '研学' }, pains: [ { icon: '🎒', text: '研学流于走马观花', solve: '机器人互动让体验深刻' }, { icon: '🧒', text: '孩子注意力难集中', solve: '趣味互动、动手参与' }, { icon: '🛡️', text: '安全与组织压力大', solve: '机器人协同、秩序可控' } ], benefits: [ { icon: '🧭', title: '主题研学', desc: '围绕场景定制路线' }, { icon: '🤖', title: '深度互动', desc: '动手体验、寓教于乐' }, { icon: '🛡️', title: '安全可控', desc: '协同调度、有序进行' } ], scenes: [ { name: '科技研学', desc: '机器人互动、动手实践', data: '体验 2×' }, { name: '工业研学', desc: '产线参观、智能演示', data: '认知 UP' } ] },
  16: { desc: '赛事组织、竞技互动，让比赛现场更燃、转播更有料', icon: '🏆', bgColor: '#FEF3C7', consultCount: 265, tags: ['可租用', '直转播', '现场互动'], stats: { amp: '热度 3×', roi: '单次', scene: '赛事' }, pains: [ { icon: '😑', text: '赛事现场气氛难调动', solve: '机器人互动暖场、加油' }, { icon: '🎥', text: '转播机位有限、精彩难捕捉', solve: '机器人跟拍、多角度呈现' }, { icon: '📱', text: '观众参与感弱', solve: '现场互动+线上联动' } ], benefits: [ { icon: '🔥', title: '现场燃动', desc: '互动暖场、气氛拉满' }, { icon: '🎥', title: '多机跟拍', desc: '精彩瞬间多角度' }, { icon: '📲', title: '全民参与', desc: '线上线下联动互动' } ], scenes: [ { name: '机器人赛事', desc: '现场互动、实时转播', data: '热度 3×' }, { name: '体育嘉年华', desc: '表演互动、氛围营造', data: '参与 UP' } ] },
  17: { desc: '园区巡逻、安全监测，让园区安全无死角、异常秒响应', icon: '🚓', bgColor: '#E8F0FE', consultCount: 289, tags: ['官方正品', '中控联动', '24h巡防'], stats: { amp: '24h现场', roi: '10个月', scene: '园区' }, pains: [ { icon: '💂', text: '保安人力成本高、缺口大', solve: '机器人巡防替代反复巡视' }, { icon: '🌙', text: '夜间巡逻缺位、盲区多', solve: '7×24 自主巡逻不留死角' }, { icon: '🚨', text: '异常响应慢', solve: 'AI 识别联动中控秒级告警' } ], benefits: [ { icon: '🛡️', title: '自主巡逻', desc: '路径自规划、全天候' }, { icon: '👁️', title: '异常识别', desc: '陌生人/烟火 AI 告警' }, { icon: '🔗', title: '中控联动', desc: '与监控一体调度' } ], scenes: [ { name: '产业园', desc: '周界巡防、可疑识别', data: '巡防 24h' }, { name: '厂区', desc: '重点区域值守', data: '覆盖 100%' } ] },
  18: { desc: '仓库值守、异常报警，把库房安全交给"不打烊"的机器人', icon: '🏚️', bgColor: '#F0F4FA', consultCount: 231, tags: ['官方正品', '异常告警', '24h值守'], stats: { amp: '值守 24h', roi: '9个月', scene: '仓储' }, pains: [ { icon: '🌃', text: '夜间仓库无人值守、风险高', solve: '机器人 7×24 巡查值守' }, { icon: '🚨', text: '异常发现晚、损失大', solve: 'AI 识别即时告警' }, { icon: '📦', text: '盘点靠人工、易错漏', solve: '巡逻联动、数据可溯' } ], benefits: [ { icon: '🛡️', title: '全天值守', desc: '夜间也能安心库房' }, { icon: '🚨', title: '即时告警', desc: '异常秒级发现上报' }, { icon: '📋', title: '巡查留痕', desc: '巡逻轨迹清晰可查' } ], scenes: [ { name: '成品仓库', desc: '夜间值守、异常告警', data: '值守 24h' }, { name: '物流库', desc: '周界巡查、人员核验', data: '异常 -50%' } ] },
  19: { desc: '矿区巡检、设备监测，把高危环境交给机器人守护', icon: '⛏️', bgColor: '#E8F0FE', consultCount: 156, tags: ['官方正品', '高危替代', '防爆适应'], stats: { amp: '-70%人力', roi: '12个月', scene: '矿区' }, pains: [ { icon: '⛰️', text: '矿区作业高危、人难进', solve: '机器人替代进入高危区' }, { icon: '📉', text: '设备状态监测不到位', solve: '自主巡检、数据实时回传' }, { icon: '⏱️', text: '巡检耗时长、覆盖难', solve: '机器人连续巡检、全覆盖' } ], benefits: [ { icon: '🛡️', title: '高危替代', desc: '高危环境无人化' }, { icon: '📡', title: '实时监测', desc: '设备状态上云可析' }, { icon: '🔁', title: '连续作业', desc: '不停机巡检、全覆盖' } ], scenes: [ { name: '采矿区', desc: '高位巡检、设备监测', data: '人力 -70%' }, { name: '洗选厂', desc: '产线巡检、安全排查', data: '覆盖 100%' } ] },
  20: { desc: '机房巡查、环境监测，让数据中心隐患无处遁形', icon: '🖥️', bgColor: '#F0F4FA', consultCount: 143, tags: ['官方正品', '环境监测', '7×24'], stats: { amp: '巡检 3×', roi: '10个月', scene: '机房' }, pains: [ { icon: '🌡️', text: '温湿度漂移难察觉', solve: '机器人巡检实时监测' }, { icon: '🔌', text: '线缆/设备状态抽查难', solve: '视觉逐个点位核查' }, { icon: '🌃', text: '夜间巡查依赖人工', solve: '7×24 机器人值守' } ], benefits: [ { icon: '🌡️', title: '环境监测', desc: '温湿度、烟雾实时' }, { icon: '👁️', title: '点位核查', desc: '设备/线缆异常识别' }, { icon: '🔁', title: '全天值守', desc: '7×24 不间断巡查' } ], scenes: [ { name: '数据中心', desc: '机房巡检、环境监测', data: '巡检 3×' }, { name: '通信机房', desc: '设备核查、告警联动', data: '隐患 -40%' } ] },
  21: { desc: '物料转运、零部件配送，让产线之间"不断供"', icon: '📦', bgColor: '#F0F4FA', consultCount: 218, tags: ['官方正品', '自动配送', '柔性调度'], stats: { amp: '转运 2×', roi: '8个月', scene: '制造' }, pains: [ { icon: '🚚', text: '人工转运慢、易积压', solve: '机器人定时定量配送' }, { icon: '🔄', text: '多线配送调度乱', solve: '智能调度、按需响应' }, { icon: '🧑‍🏭', text: '搬运占用人力', solve: '机器替代、人专注高价值' } ], benefits: [ { icon: '📦', title: '自动转运', desc: '线边配送、不断供' }, { icon: '🧭', title: '智能调度', desc: '任务按时按需派发' }, { icon: '💪', title: '解放人力', desc: '替代重复搬运' } ], scenes: [ { name: '车间线边', desc: '物料转运、补货配送', data: '转运 2×' }, { name: '立体仓', desc: '零部件配送、接驳', data: '准时 100%' } ] },
  22: { desc: '仓库货物分拣、搬运，让分拣效率提升、错发归零', icon: '📦', bgColor: '#E8F0FE', consultCount: 386, tags: ['官方正品', '全国服务', '夜间可用'], stats: { amp: '+300%', roi: '8个月', scene: '仓储' }, pains: [ { icon: '💸', text: '人工搬运成本上涨、留人难', solve: '机器人替代搬运，1台顶3班' }, { icon: '🎯', text: '高峰爆仓、分拣错发', solve: '视觉精准分拣、错拣近零' }, { icon: '🌙', text: '夜间无人作业、吞吐受限', solve: '7×24 无人分拣、吞吐翻倍' } ], benefits: [ { icon: '⏱️', title: '无人分拣', desc: '夜间照样作业' }, { icon: '🎯', title: '精准分拣', desc: '视觉识别、错发近零' }, { icon: '💰', title: '轻资产', desc: '按单计费/租赁' } ], scenes: [ { name: '电商仓配', desc: '订单波次自动分拣', data: '效率 +300%' }, { name: '快递中转', desc: '识别分拣、装车对接', data: '错拣 <0.1%' } ] },
  23: { desc: '产线演示、自动化展示，让智能工厂"看得见"', icon: '🏭', bgColor: '#E8F0FE', consultCount: 234, tags: ['官方正品', '产线升级', '节拍保障'], stats: { amp: '-35%成本', roi: '14个月', scene: '工业' }, pains: [ { icon: '🧑‍🏭', text: '招工难、用工贵、流动大', solve: '机器人稳定上岗' }, { icon: '📈', text: '产线产能瓶颈难扩', solve: '柔性产线、随需提升' }, { icon: '🧪', text: '人工一致性差、良率不稳', solve: '标准化作业、良率稳定' } ], benefits: [ { icon: '⚙️', title: '高柔性', desc: '换产快、小批多变' }, { icon: '🎯', title: '质量稳定', desc: '节拍一致、良率可控' }, { icon: '📇', title: '数据排产', desc: '工时数据反哺决策' } ], scenes: [ { name: '装配线', desc: '上下料、锁付、检测', data: '节拍 +30%' }, { name: '智能产线', desc: '自动化演示、参观', data: '展示 3×' } ] },
  24: { desc: '安全检查、点位核查，让现场隐患被"提前看见"', icon: '⚠️', bgColor: '#FEF3C7', consultCount: 167, tags: ['官方正品', '隐患识别', '巡查留痕'], stats: { amp: '筛查 2×', roi: '9个月', scene: '现场' }, pains: [ { icon: '🔦', text: '安全隐患靠人查、易漏', solve: '机器人点位普查、识别' }, { icon: '📋', text: '检查记录碎片化', solve: '巡查自动留痕、可追溯' }, { icon: '⏰', text: '巡检频次难保障', solve: '机器人定时定点不缺席' } ], benefits: [ { icon: '👁️', title: '隐患识别', desc: '异常点位自动发现' }, { icon: '📋', title: '留痕可溯', desc: '巡查记录清晰完整' }, { icon: '🕑', title: '高频巡检', desc: '定时定点、不遗漏' } ], scenes: [ { name: '车间安全', desc: '点位核查、隐患上报', data: '筛查 2×' }, { name: '仓库现场', desc: '安全巡查、留痕', data: '隐患 -40%' } ] },
  25: { desc: '导诊服务、科室导航，让患者一到医院就不再"迷路"', icon: '🏥', bgColor: '#E8F0FE', consultCount: 123, tags: ['官方正品', '无接触', '全程追溯'], stats: { amp: '-60%转运人力', roi: '9个月', scene: '医院' }, pains: [ { icon: '🧭', text: '患者就医流程不熟、易乱', solve: '机器人导诊带路、分流' }, { icon: '⏳', text: '咨询窗口排队久', solve: '常驻问诊、即问即答' }, { icon: '🦠', text: '人流密集交叉风险', solve: '无接触引导、降院感' } ], benefits: [ { icon: '🧭', title: '智能导诊', desc: '分诊带路、少跑腿' }, { icon: '💬', title: '常驻咨询', desc: '常见问题即时解答' }, { icon: '🛡️', title: '无接触', desc: '降低交叉感染风险' } ], scenes: [ { name: '门诊大厅', desc: '导诊带路、科室导航', data: '跑腿 -40%' }, { name: '住院部', desc: '指引探视、物资配送', data: '效率 2×' } ] },
  26: { desc: '康养陪护、互动关怀，让长者被"全天候"陪伴', icon: '💝', bgColor: '#FCE4EC', consultCount: 456, tags: ['官方正品', '紧急响应', '全天守护'], stats: { amp: '24h守护', roi: '7个月', scene: '康养' }, pains: [ { icon: '🧑‍⚕️', text: '护理人力紧缺、压力大', solve: '机器人分担看护减负' }, { icon: '🌃', text: '夜间看护易失守', solve: '7×24 监测夜间不缺席' }, { icon: '📉', text: '长者健康变化难察觉', solve: '跌倒/异常监测及时预警' } ], benefits: [ { icon: '💊', title: '用药提醒', desc: '定时提醒、不漏服' }, { icon: '🆘', title: '跌倒监测', desc: '异常即时上报' }, { icon: '🗣️', title: '语音陪伴', desc: '陪聊点播、缓解孤独' } ], scenes: [ { name: '养老院', desc: '查房、用药提醒', data: '守护 24h' }, { name: '康养中心', desc: '陪护互动、安全监测', data: '满意度 +30%' } ] },
  27: { desc: '家庭陪伴、儿童教育，把智能陪伴带回家', icon: '🏠', bgColor: '#F0F4FA', consultCount: 289, tags: ['官方正品', '亲子互动', '语音交互'], stats: { amp: '陪伴 24h', roi: '4个月', scene: '家庭' }, pains: [ { icon: '👦', text: '家长忙、孩子陪伴少', solve: '机器人陪伴互动、讲故事' }, { icon: '📚', text: '启蒙教育方式单一', solve: '互动问答、寓教于乐' }, { icon: '🧓', text: '老人居家孤独、照护难', solve: '语音陪伴、便捷呼叫' } ], benefits: [ { icon: '🗣️', title: '语音互动', desc: '问答、点播、讲故事' }, { icon: '🎓', title: '启蒙教育', desc: '趣味问答、习惯养成' }, { icon: '🏠', title: '居家守护', desc: '陪伴、提醒、呼叫' } ], scenes: [ { name: '亲子陪伴', desc: '互动、讲故事、科普', data: '陪伴 24h' }, { name: '老人居家', desc: '语音陪伴、紧急呼叫', data: '安心 UP' } ] },
  28: { desc: '爱好者体验、开发调试，让每个人都能上手机器人', icon: '🧑‍💻', bgColor: '#F0F4FA', consultCount: 98, tags: ['可租用', '开放接口', '开发调试'], stats: { amp: '上手快', roi: '单次', scene: '极客' }, pains: [ { icon: '🧩', text: '开发门槛高、上手难', solve: '开放接口、快速上手' }, { icon: '🛠️', text: '调试环境搭建耗时', solve: '多机型可租、即租即测' }, { icon: '🧑‍🤝‍🧑', text: '缺交流、问题难解', solve: '社区资源、学习支撑' } ], benefits: [ { icon: '🔌', title: '开放开发', desc: '接口开放、易于调试' }, { icon: '🎛️', title: '多机型', desc: '人形/四足/机械臂' }, { icon: '💡', title: '快速验证', desc: '即租即测、迭代快' } ], scenes: [ { name: '开发者', desc: '二次开发、调试验证', data: '上手快' }, { name: '创客空间', desc: '体验、实验、比赛', data: '创意 UP' } ] }
}
