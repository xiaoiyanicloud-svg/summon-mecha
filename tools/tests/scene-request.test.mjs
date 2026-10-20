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
  for(const result of [{},{success:true},{success:true,data:{id:1}},{success:true,data:{id:'1',request_no:'SR-X'}},{success:true,data:{id:0,request_no:'SR-X'}}]){
    const {context:c}=harness({create:async()=>result});await assert.rejects(c.submitSceneRequest(payload),/回执/)
  }
})

test('报价及项目动作超时冻结原载荷，重试同键，收到有效状态回执后才清除',async()=>{
  const {context:c,storage}=harness(), calls=[]
  let fail=true
  const sender=async(data,access,key)=>{calls.push({data,access,key});if(fail)throw new Error('timeout');return {data:{id:12,state:'rejected'}}}
  const original={reason:'需要调整',row_version:4},slot='request:1:reject:12'
  await assert.rejects(c.submitSceneCommand(slot,original,sender),/timeout/)
  assert.equal(c.scenePendingCommands('request:1:').length,1)
  assert.equal(c.scenePendingCommands('request:2:').length,0)
  await assert.rejects(c.submitSceneCommand(slot,{...original,reason:'换了原因'},sender),/待确认/)
  fail=false;await c.submitSceneCommand(slot,original,sender)
  assert.equal(calls.length,2);assert.equal(calls[0].key,calls[1].key)
  assert.equal(storage.get('scene_request_v1:guest').pending[slot],undefined)
})

test('报价/项目响应格式不完整时保留待确认记录，不以 HTTP 成功清理',async()=>{
  for(const response of [{},{data:{id:1}},{data:{id:0,state:'accepted'}},{data:{id:1,state:''}}]){
    const {context:c}=harness()
    await assert.rejects(c.submitSceneCommand('project:1:acceptance',{items:[]},async()=>response),/有效操作回执/)
    assert.equal(c.scenePendingCommands('project:1:').length,1)
  }
})

test('明确 4xx 拒绝才允许修正动作；5xx 和网络失败保留同一未知结果',async()=>{
  for(const statusCode of [409,422,500,undefined]){
    const {context:c}=harness(),slot='request:1:supplement'
    await assert.rejects(c.submitSceneCommand(slot,{text:'原文'},async()=>{throw Object.assign(new Error('failed'),{statusCode})}),/failed/)
    if(statusCode<500){
      assert.equal(c.scenePendingCommands('request:1:').length,0)
      assert.equal(c.saveSceneDraft(slot,{text:'修正文'}).state,'local_only')
    }else{
      assert.equal(c.scenePendingCommands('request:1:').length,1)
      assert.throws(()=>c.saveSceneDraft(slot,{text:'修正文'}),/待确认/)
    }
  }
})

test('项目动作回包前切换账号不清理旧回执，也不写入新账号',async()=>{
  const {context:c,storage}=harness()
  let resolve
  const promise=c.submitSceneCommand('project:1:acceptance',{items:[]},()=>new Promise(r=>{resolve=r}))
  storage.set('token','new-token');storage.set('customer',{id:7})
  resolve({data:{id:1,state:'accepted'}})
  await assert.rejects(promise,/登录状态已变化/)
  assert.equal(storage.has('scene_request_v1:customer:7'),false)
  assert.equal(storage.get('scene_request_v1:guest').pending['project:1:acceptance'].state,'awaiting_receipt')
  assert.equal(c.scenePendingCommands('project:1:').length,0)
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

test('私有上传丢响应只按原标识恢复回执，不重新生成提交或跨账号清理',async()=>{
  const calls=[]
  const {context:c,storage}=harness({uploadAttachment:async(id,path,access,key)=>{calls.push(key);throw new Error('response lost')},uploadReceipt:async()=>({data:{id:7,state:'clean',availability_state:'available'}})})
  await assert.rejects(c.submitSceneAttachment(3,{path:'/private/original.pdf'}),/response lost/)
  const pending=storage.get('scene_request_v1:guest').pending['upload:3'];assert.equal(pending.state,'awaiting_receipt')
  const receipt=await c.submitSceneAttachment(3,{path:'/private/changed.pdf'})
  assert.equal(receipt.id,7);assert.equal(calls.length,1);assert.equal(storage.get('scene_request_v1:guest').pending['upload:3'],undefined)
})
test('原上传未确认时重选文件仍复用原键；错误回包和内容冲突不能清除待确认状态',async()=>{
  const keys=[]
  const {context:c,storage}=harness({uploadAttachment:async(id,path,access,key)=>{keys.push(key);throw Object.assign(new Error('conflict'),{statusCode:409})},uploadReceipt:async()=>({data:{state:'not_confirmed'}})})
  await assert.rejects(c.submitSceneAttachment(3,{path:'/first.pdf'}),/conflict/)
  await assert.rejects(c.submitSceneAttachment(3,{path:'/reselected.pdf'}),/conflict/)
  assert.equal(keys[0],keys[1]);assert.equal(storage.get('scene_request_v1:guest').pending['upload:3'].state,'awaiting_receipt')
})
test('上传或下载回包前切号不把旧回执写入新主体、不打开私有文件',async()=>{
  let resolve,opened=false
  const pending=new Promise(r=>resolve=r)
  const {context:c,storage}=harness({downloadAttachment:()=>pending})
  c.uni.openDocument=()=>{opened=true}
  const download=c.openSceneAttachment(3,7)
  storage.set('token','new-token');storage.set('customer',{id:8});resolve({filePath:'/temp/private.pdf'})
  await assert.rejects(download,/登录状态已变化/);assert.equal(opened,false);assert.equal(storage.has('scene_request_v1:customer:8'),false)
})
test('专业字段未知不变零，choice 使用公开标签，非法小数不能进入提交',async()=>{
  const fields=await readFile(new URL('../../utils/scene-fields.js',import.meta.url),'utf8'),c=vm.createContext({})
  vm.runInContext(fields.replace(/^export /gm,''),c)
  const schema={fields:[{key:'payload',type:'decimal',label:'载荷',unit:'kg'},{key:'count',type:'integer',label:'数量'},{key:'kind',type:'choice',options:[{value:'ordinary',label:'普通环境'}]}]}
  const result=c.normalizeSceneFacts(schema,{payload:'',count:0,kind:'ordinary'})
  assert.equal(result.payload,null);assert.equal(result.count,0);assert.equal(c.sceneFactLabel(schema.fields[2],'ordinary'),'普通环境')
  assert.throws(()=>c.normalizeSceneFacts(schema,{payload:'1e6'}),/三位小数/);assert.throws(()=>c.normalizeSceneFacts(schema,{count:'1.2'}),/整数/)
})
test('H5 PDF 使用鉴权下载后的 Blob，不能直接打开受保护 API 或误调用 openDocument',async()=>{
  let clicked=false, opened=false
  const {context:c}=harness({downloadAttachment:async()=>({filePath:'blob:isolated-private-document'})})
  const anchor={click(){clicked=true},remove(){},href:'',download:''}
  c.window={};c.document={createElement:()=>anchor,body:{appendChild(){}}};c.uni.openDocument=()=>{opened=true}
  const result=await c.openSceneAttachment(1,7)
  assert.equal(result.state,'download_started');assert.equal(clicked,true);assert.equal(opened,false);assert.equal(anchor.href,'blob:isolated-private-document');assert.equal(anchor.download,'scene-document-1-7.pdf')
})
test('文件选择组件遵守 H5 和微信不同 API/type/后缀协议',async()=>{
  const text=await readFile(new URL('../../components/SceneAttachments.vue',import.meta.url),'utf8')
  const script=text.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/^import .*$/gm,'').replace('export default','globalThis.component =')
  for(const platform of ['h5','wechat']){
    let options
    const c=vm.createContext({sceneAccountScope:()=> 'guest',uni:{chooseFile:o=>options=o}})
    if(platform==='wechat')c.wx={chooseMessageFile:o=>options=o}
    vm.runInContext(script,c);c.component.methods.choose.call({message:''})
    assert.equal(options.count,1)
    if(platform==='h5'){assert.equal(options.type,undefined);assert.equal(options.extension[0],'.pdf')}
    else{assert.equal(options.type,'file');assert.equal(options.extension[0],'pdf')}
  }
})
