/**
 * 技能举报 —— 前端共用的提交流程(B-7 · 加固计划 §四 A1)。
 *
 * 为什么抽成一个小模块:技能详情页与「我的技能」两处都要这个入口,而流程
 * 完全一样(选理由 → 可选补充 → 提交)。抄两份迟早只改一份 —— 举报理由
 * 是与后端 SkillReport::REASON_LABELS 对齐的**契约**,更不能有两个出处。
 *
 * ⚠ 补充说明是**可选**的(后端 detail 可空):能指出"哪里不对"已够立案,
 *   强制写正文会劝退真实举报。所以这里不做必填校验。
 */
import { reportApi } from './api'

// 与后端 SkillReport::REASON_LABELS 对齐(值即契约;顺序 = 选择面板顺序)。
const REASONS = [
  { value: 'illegal', label: '违法违规' },
  { value: 'safety', label: '安全风险（可能伤人/损坏设备）' },
  { value: 'infringement', label: '侵权（盗用他人作品/形象）' },
  { value: 'fraud', label: '虚假宣传/欺诈' },
  { value: 'privacy', label: '侵犯隐私（未告知采集人脸/录音）' },
  { value: 'other', label: '其他' },
]

/**
 * 弹「选理由 → 补说明 → 提交」。已提交返回 true,取消/失败返回 false。
 *
 * @param {{targetType?:string, targetId:number, name?:string}} opts
 *        targetType: 'skill'(默认)| 'review';targetId: 对应 products.id / skill_review.id
 */
export function submitSkillReport(opts) {
  const { targetType = 'skill', targetId, name = '' } = opts || {}

  return new Promise((resolve) => {
    // 没有对象 id 就没法举报(技能信息还没加载出来),如实提示而不是发一个空请求
    if (!targetId) {
      uni.showToast({ title: '技能信息加载中，请稍后重试', icon: 'none' })
      resolve(false)
      return
    }

    uni.showActionSheet({
      itemList: REASONS.map((r) => r.label),
      success: (res) => {
        const reason = REASONS[res.tapIndex]
        if (!reason) { resolve(false); return }

        uni.showModal({
          title: `举报${name ? '「' + name + '」' : ''}`,
          editable: true,
          placeholderText: '补充说明（可选）',
          success: async (m) => {
            if (!m.confirm) { resolve(false); return }
            const detail = (m.content || '').trim()
            try {
              const r = await reportApi.create({
                target_type: targetType,
                target_id: targetId,
                reason_category: reason.value,
                // 空字符串会被后端当作"写了空正文",不发这个字段更干净
                ...(detail ? { detail } : {}),
              })
              // 后端对"已有一条未处置举报"返回 duplicate 文案;照原样展示,不自己编
              uni.showToast({ title: (r && r.message) || '举报已受理', icon: 'none' })
              resolve(true)
            } catch (e) {
              uni.showToast({ title: (e && e.message) || '举报提交失败', icon: 'none' })
              resolve(false)
            }
          },
          fail: () => resolve(false),
        })
      },
      // 用户点「取消」也走 fail,不是异常
      fail: () => resolve(false),
    })
  })
}
