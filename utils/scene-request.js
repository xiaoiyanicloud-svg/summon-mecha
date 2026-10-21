import { sceneWorkflowApi } from './api'
import { TOKEN_KEY, CUSTOMER_KEY } from './config'

// 私有资料按登录主体分桶；退出登录不能展示前一账号的草稿/回执。
export function sceneAccountScope() {
  const token = uni.getStorageSync(TOKEN_KEY)
  const customer = uni.getStorageSync(CUSTOMER_KEY) || {}
  return token ? 'customer:' + (customer.id || customer.email || 'unresolved') : 'guest'
}
function storageKey() { return 'scene_request_v1:' + sceneAccountScope() }
export function sceneLocalState() {
  if (sceneAccountScope() === 'customer:unresolved') return { access: '', pending: {}, receipts: [] }
  const state = uni.getStorageSync(storageKey())
  return state && typeof state === 'object' && state.pending && Array.isArray(state.receipts) ? state : { access: '', pending: {}, receipts: [] }
}
export function sceneGuestAccess() {
  const state = uni.getStorageSync('scene_request_v1:guest')
  return state && state.access || ''
}
export function sceneGuestReceipts() {
  const state = uni.getStorageSync('scene_request_v1:guest')
  return state && Array.isArray(state.receipts) ? state.receipts : []
}
export function clearSceneDraft(slot) {
  const state = sceneLocalState(); delete state.pending[slot]; save(state, sceneAccountScope())
}
export function openSceneRequest(payload) {
  try { saveSceneDraft('form', payload) }
  catch (error) {
    uni.showToast({ title: error.message, icon: 'none' })
    const pending = sceneLocalState().pending.form
    if (!pending) return
    payload = pending.payload
  }
  uni.navigateTo({ url: '/pages/scene/request?source=' + encodeURIComponent(payload.source) + (payload.scene_id ? '&sceneId=' + payload.scene_id : '') })
}
function save(state, scope) {
  if (scope === 'customer:unresolved') throw new Error('登录资料不完整，请重新登录后保存需求')
  if (sceneAccountScope() !== scope) throw new Error('登录状态已变化，请重新打开需求页')
  uni.setStorageSync(storageKey(), state)
}
export function sceneCommandKey() {
  // 此值用于重复命令识别，不用于访问授权；访问能力由服务端 random_bytes 签发。
  return 'scene_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)
}
let accessPending = null
export async function ensureSceneAccess() {
  const scope = sceneAccountScope()
  const state = sceneLocalState()
  if (state.access) return state.access
  if (accessPending && accessPending.scope === scope) return accessPending.promise
  const promise = sceneWorkflowApi.access().then(result => {
    if (!result || !/^[a-f0-9]{64}$/.test(result.token)) throw new Error('无法取得需求访问凭证，请重试')
    const latest = sceneLocalState()
    latest.access = result.token
    save(latest, scope)
    return result.token
  }).finally(() => { if (accessPending && accessPending.promise === promise) accessPending = null })
  accessPending = { scope, promise }
  return promise
}
export function saveSceneDraft(slot, payload) {
  const state = sceneLocalState()
  const previous = state.pending[slot]
  const serialized = JSON.stringify(payload)
  if (previous && previous.state === 'awaiting_receipt' && previous.serialized !== serialized) {
    throw new Error('上一份提交结果待确认，请先按原内容重试或在“我的需求”中查询，再修改需求')
  }
  // 同内容保留幂等键；用户修改后才产生新命令。
  state.pending[slot] = previous && previous.serialized === serialized
    ? previous : { key: sceneCommandKey(), payload, serialized, savedAt: new Date().toISOString(), state: 'local_only' }
  save(state, sceneAccountScope())
  return state.pending[slot]
}
export async function submitSceneRequest(payload, slot = 'form') {
  const scope = sceneAccountScope()
  const draft = saveSceneDraft(slot, payload)
  const access = await ensureSceneAccess()
  if (sceneAccountScope() !== scope) throw new Error('登录状态已变化，请重新提交')
  const waiting = sceneLocalState()
  if (!waiting.pending[slot] || waiting.pending[slot].key !== draft.key) throw new Error('草稿已变化，请确认内容后重新提交')
  waiting.pending[slot].state = 'awaiting_receipt'
  save(waiting, scope)
  let response
  try { response = await sceneWorkflowApi.create(draft.payload, access, draft.key) }
  catch (error) {
    if (sceneAccountScope() === scope && error.statusCode >= 400 && error.statusCode < 500) {
      const rejected = sceneLocalState()
      if (rejected.pending[slot] && rejected.pending[slot].key === draft.key) { rejected.pending[slot].state = 'rejected'; save(rejected, scope) }
    }
    throw error
  }
  const receipt = response && response.data
  if (!response || response.success !== true || !receipt || !Number.isSafeInteger(receipt.id) || receipt.id < 1 || !receipt.request_no) {
    throw new Error('未收到有效需求回执，内容已保留在本机，请重试')
  }
  const latest = sceneLocalState()
  if (!latest.receipts.some(item => item.id === receipt.id)) latest.receipts.unshift(receipt)
  delete latest.pending[slot]
  save(latest, scope)
  return receipt
}

// 补充、报价决策和项目动作同样保留“已发送但回执未知”的原载荷及幂等键。
export async function submitSceneCommand(slot, payload, sender) {
  const scope = sceneAccountScope(), draft = saveSceneDraft(slot, payload)
  const waiting = sceneLocalState()
  waiting.pending[slot].state = 'awaiting_receipt'
  save(waiting, scope)
  let response
  try { response = await sender(draft.payload, waiting.access, draft.key) }
  catch (error) {
    if (scope === sceneAccountScope() && error.statusCode >= 400 && error.statusCode < 500) {
      const rejected = sceneLocalState()
      if (rejected.pending[slot] && rejected.pending[slot].key === draft.key) {
        rejected.pending[slot].state = 'rejected'; save(rejected, scope)
      }
    }
    throw error
  }
  if (scope !== sceneAccountScope()) throw new Error('登录状态已变化，请重新打开详情确认回执')
  const result = response && response.data
  if (!result || !Number.isSafeInteger(result.id) || result.id < 1 || typeof result.state !== 'string' || !result.state) {
    throw new Error('未收到有效操作回执，原内容已保留，请按原内容重试')
  }
  const latest = sceneLocalState()
  if (!latest.pending[slot] || latest.pending[slot].key !== draft.key) throw new Error('本机操作状态已变化，请刷新确认')
  delete latest.pending[slot]; save(latest, scope)
  return result
}

export function scenePendingCommands(prefix) {
  return Object.entries(sceneLocalState().pending)
    .filter(([slot, draft]) => slot.startsWith(prefix) && draft.state === 'awaiting_receipt')
    .map(([slot, draft]) => ({ slot, payload: draft.payload }))
}

export function sceneAttachmentPending(id) { return sceneLocalState().pending['upload:'+id] || null }
export async function recoverSceneAttachment(id) {
  const scope=sceneAccountScope(), pending=sceneAttachmentPending(id)
  if (!pending) return null
  const r=await sceneWorkflowApi.uploadReceipt(id,sceneLocalState().access,pending.key)
  if (scope!==sceneAccountScope()) throw new Error('登录状态已变化，请重新打开需求')
  if (r.data && Number.isSafeInteger(r.data.id) && r.data.availability_state) { clearSceneDraft('upload:'+id); return r.data }
  if (r.data?.state!=='not_confirmed') throw new Error('原上传回执暂不可用，请稍后查询')
  return null
}
export async function submitSceneAttachment(id,file) {
  const scope=sceneAccountScope(), slot='upload:'+id
  let pending=sceneAttachmentPending(id)
  if (pending) { const receipt=await recoverSceneAttachment(id); if (receipt) return receipt }
  const state=sceneLocalState()
  if (!pending) pending={key:sceneCommandKey(),payload:{},state:'awaiting_receipt',savedAt:new Date().toISOString()}
  const path=file?.path || pending.payload.path
  if (!path) throw new Error('请重新选择原文件，保留原上传标识核验')
  pending={...pending,payload:{path},state:'awaiting_receipt'}; state.pending[slot]=pending; save(state,scope)
  const response=await sceneWorkflowApi.uploadAttachment(id,path,state.access,pending.key)
  if (scope!==sceneAccountScope()) throw new Error('登录状态已变化，请重新打开需求查询回执')
  if (!Number.isSafeInteger(response?.data?.id) || !response.data.availability_state) throw new Error('上传结果待确认，请查询原回执')
  clearSceneDraft(slot); return response.data
}
export async function openSceneAttachment(id,attachmentId,mime='application/pdf') {
  const scope=sceneAccountScope()
  const file=await sceneWorkflowApi.downloadAttachment(id,attachmentId,sceneLocalState().access)
  if (scope!==sceneAccountScope()) throw new Error('登录状态已变化，已停止打开私有资料')
  // uni.openDocument 不支持 H5；只下载本次带鉴权头取得的本地 Blob，不直链受保护 API。
  if (!mime.startsWith('image/') && typeof window!=='undefined' && typeof document!=='undefined') {
    if (!file.filePath.startsWith('blob:')) throw new Error('浏览器未取得可用文件，请核对下载服务后重试')
    const link=document.createElement('a');link.href=file.filePath;link.download='scene-document-'+id+'-'+attachmentId+'.pdf';link.rel='noopener';document.body.appendChild(link);link.click();link.remove()
    return {state:'download_started'}
  }
  return new Promise((resolve,reject)=>{
    const callbacks={success:resolve,fail:()=>reject(new Error('当前设备不能预览此资料，请更换受支持的设备'))}
    if(mime.startsWith('image/'))uni.previewImage({urls:[file.filePath],showmenu:false,...callbacks})
    else uni.openDocument({filePath:file.filePath,showMenu:false,...callbacks})
  })
}
export function sceneDraftPayload({ source, sceneId, revisionId, category, text, facts = {}, contact }) {
  return { source, scene_id: sceneId || null, scene_revision_id: revisionId || null, category: category || 'enterprise',
    original_text: text || '希望沟通机器人场景服务，请联系我进一步确认需求。', facts,
    contact: { name: contact.name, phone: contact.phone, company: contact.company || '', wechat: contact.wechat || '' },
    consent_version: 'scene-contact-v1' }
}
export function sceneStatusLabel(state) {
  return ({ submitted: '待受理', triaged: '已分派', assessing: '评估中', feasible: '评估通过', accepted: '报价已接受',
    converted: '已建立项目', closed: '已关闭', cancelled: '已取消', sent: '待确认', voided: '已失效', rejected: '客户已拒绝', returned: '已退回重编',
    unpaid: '未确认收款', offline_verified: '线下收款已复核', offline_partial: '部分线下收款已复核',
    confirmed_offline: '线下商务已确认', unconfirmed: '待商务确认', preparing: '交付准备',
    delivering: '交付中', servicing: '服务中', awaiting_acceptance: '待验收', rectification: '整改中' })[state] || state
}
