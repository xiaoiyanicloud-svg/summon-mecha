import test from 'node:test'
import assert from 'node:assert/strict'
import vm from 'node:vm'
import { readFile } from 'node:fs/promises'

const source = await readFile(new URL('../../utils/scene-request.js',import.meta.url),'utf8')
function harness(api = {}) {
  const storage = new Map(), navigation = []
  const context = vm.createContext({ TOKEN_KEY:'token', CUSTOMER_KEY:'customer',
    sceneWorkflowApi: { access: async()=>({ token:'a'.repeat(64) }),...api },
    uni: { getStorageSync:k=>structuredClone(storage.get(k)), setStorageSync:(k,v)=>storage.set(k,structuredClone(v)), navigateTo:r=>navigation.push(r) } })
  vm.runInContext(source.replace(/^import .*$/gm,'').replace(/^export /gm,''),context)
  return { context, storage, navigation }
}
const payload = { source:'home', original_text:'需求', contact:{name:'测试',phone:'13800000000'}, facts:{} }
test('提交成功响应丢失后重试复用幂等键与访问能力；无回执不冒充成功',async()=>{
  const calls=[];let count=0
  const {context:c,storage}=harness({ create:async(data,access,key)=>{calls.push({data,access,key});if(++count===1)throw new Error('timeout');return { success:true,data:{id:1,request_no:'SR-1'} }} })
  await assert.rejects(c.submitSceneRequest(payload),/timeout/)
  assert.equal(storage.get('scene_request_v1:guest').receipts.length,0)
  assert.equal(storage.get('scene_request_v1:guest').pending.form.state,'awaiting_receipt')
  assert.throws(()=>c.saveSceneDraft('form',{...payload,original_text:'改动后的需求'}),/待确认/)
  await c.submitSceneRequest(payload)
  assert.equal(calls[0].key,calls[1].key);assert.equal(calls[0].access,calls[1].access)
  assert.equal(storage.get('scene_request_v1:guest').receipts.length,1)
  assert.equal(storage.get('scene_request_v1:guest').pending.form,undefined)
})
test('无 order_id 的需求回执有效，但缺需求号/编号/确认一律拒绝',async()=>{
  for(const result of [{},{success:true},{success:true,data:{id:1}},{success:true,data:{id:'1',request_no:'SR-X'}}]){
    const {context:c}=harness({create:async()=>result});await assert.rejects(c.submitSceneRequest(payload),/回执/)
  }
})
test('请求中切换账号不把旧账号的凭证、需求内容和回执写给新账号',async()=>{
  let resolve
  const pending=new Promise(r=>resolve=r)
  const {context:c,storage}=harness({create:()=>pending})
  const submission=c.submitSceneRequest(payload)
  await new Promise(r=>setImmediate(r))
  storage.set('token','new-token');storage.set('customer',{id:2})
  resolve({success:true,data:{id:1,request_no:'SR-1'}})
  await assert.rejects(submission,/登录状态已变化/)
  assert.equal(storage.has('scene_request_v1:customer:2'),false)
  assert.equal(storage.get('scene_request_v1:guest').receipts.length,0)
})
test('访问能力必须由服务端强随机签发；同账号初始化合并请求',async()=>{
  let count=0
  const {context:c}=harness({access:async()=>{count++;return {token:'b'.repeat(64)}}})
  const tokens=await Promise.all([c.ensureSceneAccess(),c.ensureSceneAccess()]);assert.equal(count,1);assert.equal(tokens[0],tokens[1])
  const bad=harness({access:async()=>({token:'Math-random-token'})});await assert.rejects(bad.context.ensureSceneAccess(),/访问凭证/)
})
test('草稿与账号隔离；改变载荷生成新命令；访问能力不进入导航 URL',()=>{
  const {context:c,storage,navigation}=harness()
  const first=c.saveSceneDraft('form',payload),same=c.saveSceneDraft('form',payload)
  assert.equal(first.key,same.key)
  assert.notEqual(first.key,c.saveSceneDraft('form',{...payload,original_text:'changed'}).key)
  storage.set('token','t');storage.set('customer',{id:3})
  assert.equal(Object.keys(c.sceneLocalState().pending).length,0)
  c.openSceneRequest({ ...payload,scene_id:3 })
  assert.match(navigation[0].url,/sceneId=3/);assert.doesNotMatch(navigation[0].url,/13800000000|access|token|contact/)
})
