import fs from 'node:fs'
import path from 'node:path'
import { createRequire } from 'node:module'

const qaDir=path.resolve(process.env.SCENE_QA_DIR||'.claude/backup/scene-sc0-20261020/qa')
const require=createRequire(path.join(qaDir,'package.json'))
const {parse,compileTemplate}=require('@vue/compiler-sfc')
const {parse:parseScript}=require('@babel/parser')
const {chromium}=require('playwright-core')
const out=path.resolve(process.env.SCENE_EVIDENCE_DIR||'docs/scenes/sc1-sc5-batch2-evidence');fs.mkdirSync(out,{recursive:true})
const files=['pages/scene/request.vue','pages/scene/requests.vue','pages/scene/detail.vue','pages/scene/project.vue','pages/scene/confirm.vue','pages/home/index.vue','pages/robot/index.vue','pages/robot/fangan.vue','pages/home/rmfangan.vue','pages/order/checkout.vue','pages/order/index.vue','pages/order/order-detail.vue','pages/ai/index.vue','pages/mine/index.vue','components/StateView.vue','components/SceneFactsForm.vue','components/SceneAttachments.vue']
const customTags=['view','text','image','scroll-view','swiper','swiper-item','input','textarea','button','picker','video','label']
function stripImports(source){const ast=parseScript(source,{sourceType:'module'});for(const n of [...ast.program.body].reverse()){if(n.type==='ImportDeclaration')source=source.slice(0,n.start)+source.slice(n.end)}return source}
const sources={}
for(const filename of files){
  const {descriptor,errors}=parse(fs.readFileSync(filename,'utf8'),{filename})
  if(errors.length)throw new Error(filename+':'+errors.join(';'))
  const template=compileTemplate({filename,source:descriptor.template.content,id:'scene-workflow-qa',compilerOptions:{isCustomElement:tag=>customTags.includes(tag),mode:'function'}})
  if(template.errors.length)throw new Error(filename+':'+template.errors.join(';'))
  new Function(stripImports(descriptor.script.content).replace('export default','return'))
  sources[filename]={script:stripImports(descriptor.script.content),render:template.code,styles:descriptor.styles.map(s=>s.content).join('\n')}
}
const routes=JSON.parse(fs.readFileSync('pages.json','utf8')).pages.map(p=>p.path)
if(routes[0]!=='pages/home/index'||new Set(routes).size!==routes.length)throw new Error('页面入口或唯一路由错误')
for(const p of ['request','requests','detail','project','confirm'])if(!routes.includes('pages/scene/'+p))throw new Error('缺页面路由 '+p)
const browser=await chromium.launch({executablePath:process.env.SCENE_CHROME||'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true})
const page=await browser.newPage({viewport:{width:390,height:844},deviceScaleFactor:1})
const pageErrors=[];page.on('pageerror',e=>pageErrors.push(e.message));await page.route('**/*',r=>r.abort())
const checks=[]
try{
  const shared=fs.readFileSync('pages/scene/scene.css','utf8').replace(/([\d.]+)rpx/g,(_,v)=>Number(v)/2+'px')
  await page.setContent('<style>body{margin:0;font-family:Arial,sans-serif}view,text,scroll-view{display:block}button{border:0}input,textarea{font-family:inherit}'+shared+'</style><div id="app"></div>')
  await page.addScriptTag({path:require.resolve('vue/dist/vue.global.prod.js')})
  await page.evaluate(({sources,helper,fields})=>{
    window.storage=new Map();window.nav=[];window.toasts=[];window.failCreate=false;window.createCalls=[]
    window.uni={getStorageSync:k=>window.storage.get(k),setStorageSync:(k,v)=>window.storage.set(k,structuredClone(v)),navigateTo:v=>window.nav.push(v),redirectTo:v=>window.nav.push(v),navigateBack:()=>{},showToast:v=>window.toasts.push(v)}
    const schema={version:1,category:'enterprise',fields:[{key:'city',label:'服务城市',type:'text',max:100,required:true},{key:'timezone',label:'服务地 IANA 时区',type:'text',max:80,required:true},{key:'indoor',label:'是否室内',type:'boolean',required:false}]}
    const api={capabilities:async()=>({data:{requests:true,quotes:true,projects:true,consent_text:'我同意平台为本次服务评估、沟通及交付联系我。'}}),schema:async()=>({data:schema}),access:async()=>({token:'a'.repeat(64)}),
      create:async(data,access,key)=>{window.createCalls.push({data,access,key});if(window.failCreate)throw new Error('提交超时，草稿已保留');return {success:true,data:{id:12,request_no:'SR-QA-12'}}},
      list:async()=>({data:[],next_cursor:null}),
      detail:async()=>({data:{id:12,request_no:'SR-QA-12',state:'submitted',row_version:1,revision_no:1,original_text:'展会迎宾互动，现场需人工核实。',facts:{city:'上海',timezone:'Asia/Shanghai'},schema,updated_at:'2026-10-20 10:00:00',quotes:[],projects:[],activities:[{id:1,kind:'submitted',occurred_at:'2026-10-20 10:00:00'}],attachments:[]}}),
      project:async()=>({data:{id:2,project_no:'SP-QA-2',request_id:12,state:'awaiting_acceptance',row_version:1,commercial_state:'confirmed_offline',payment_state:'unpaid',tasks:[{id:1,name:'现场服务',state:'done',occurred_at:'2026-10-20 10:00:00'}],acceptance_template:[{key:'greeting',label:'按冻结脚本完成迎宾',required:true}],acceptances:[],changes:[],tickets:[]}}),
      projectAction:async()=>{throw new Error('网络中断，请重试')},supplement:async()=>({data:{}}),accept:async()=>({data:{state:'accepted'}})}
    window.sceneApiFixture=api
    const helpers=new Function('sceneWorkflowApi','TOKEN_KEY','CUSTOMER_KEY',helper+'\nreturn {sceneAccountScope,sceneLocalState,sceneGuestAccess,sceneGuestReceipts,saveSceneDraft,clearSceneDraft,sceneDraftPayload,submitSceneRequest,submitSceneCommand,scenePendingCommands,sceneStatusLabel,sceneAttachmentPending,recoverSceneAttachment,submitSceneAttachment,openSceneAttachment}') (api,'token','customer')
    Object.assign(helpers,new Function(fields+'\nreturn {normalizeSceneFacts,sceneFactLabel}')())
    const state=sources['components/StateView.vue'];const StateView=new Function(state.script.replace('export default','return'))();StateView.render=new Function('Vue',state.render)(Vue)
    const factSource=sources['components/SceneFactsForm.vue'];const SceneFactsForm=new Function(factSource.script.replace('export default','return'))();SceneFactsForm.render=new Function('Vue',factSource.render)(Vue)
    const attachmentSource=sources['components/SceneAttachments.vue'];const SceneAttachments=new Function(...Object.keys(helpers),attachmentSource.script.replace('export default','return'))(...Object.values(helpers));SceneAttachments.render=new Function('Vue',attachmentSource.render)(Vue)
    window.compiledSceneSources=sources;window.sceneStatusForTests=helpers.sceneStatusLabel
    window.mountScene=(file,options={})=>{
      if(window.sceneApp)window.sceneApp.unmount()
      const entry=sources[file],names=['StateView','SceneFactsForm','SceneAttachments','sceneWorkflowApi','sceneApi',...Object.keys(helpers)]
      const component=new Function(...names,entry.script.replace('export default','return'))(StateView,SceneFactsForm,SceneAttachments,api,{getSceneDetail:async()=>({matched:true,name:'展会迎宾服务',category:'enterprise',scene_revision_id:5,requirement_schema:schema})},...Object.values(helpers))
      component.render=new Function('Vue',entry.render)(Vue)
      component.created=function(){component.onLoad.call(this,options);component.onShow.call(this)}
      const style=document.createElement('style');style.textContent=entry.styles.replace(/@import[^;]+;/g,'').replace(/([\d.]+)rpx/g,(_,v)=>Number(v)/2+'px');document.head.append(style)
      window.sceneApp=Vue.createApp(component);window.sceneVm=window.sceneApp.mount('#app')
    }
  },{sources,helper:stripImports(fs.readFileSync('utils/scene-request.js','utf8')).replace(/^export /gm,''),fields:fs.readFileSync('utils/scene-fields.js','utf8').replace(/^export /gm,'')})
  await page.evaluate(()=>window.mountScene('pages/scene/request.vue',{sceneId:'1',source:'scene'}));await page.waitForFunction(()=>window.sceneVm.loading===false)
  await page.getByText('展会迎宾服务',{exact:true}).waitFor();checks.push('真实 Vue 挂载需求动态表单')
  await page.evaluate(()=>{const v=window.sceneVm;v.schema.fields.push({key:'payload_kg',label:'载荷',type:'decimal',unit:'kg',max:1000},{key:'environment',label:'环境',type:'choice',options:[{value:'ordinary',label:'普通环境'},{value:'hazardous',label:'高危环境'}]});const component=v.$.subTree.children.find(()=>false);v.form.facts.payload_kg='1.250';v.form.facts.environment='ordinary';v.form.facts.indoor=false})
  await page.getByText('载荷（kg）',{exact:true}).waitFor();await page.getByText('普通环境 ›',{exact:true}).waitFor()
  if(!await page.evaluate(()=>window.sceneVm.payload().facts.indoor===false&&window.sceneVm.payload().facts.payload_kg==='1.250'))throw new Error('专业字段值被转换为默认值')
  checks.push('真实专业表单显示 choice 中文选项、数值单位并保留 false 与小数')
  await page.evaluate(()=>{const v=window.sceneVm;v.form.original_text='展会迎宾';v.form.contact.name='测试';v.form.contact.phone='13800000000';v.form.facts.city='上海';v.form.facts.timezone='Asia/Shanghai'})
  await page.evaluate(()=>window.sceneVm.submit());if(await page.evaluate(()=>window.createCalls.length)!==0)throw new Error('未授权联系却提交')
  checks.push('未确认联系授权禁止提交')
  await page.evaluate(()=>{window.sceneVm.consent=true;window.failCreate=true;return window.sceneVm.submit()})
  if(!await page.evaluate(()=>window.storage.get('scene_request_v1:guest').pending.form))throw new Error('超时未保留草稿')
  if(await page.evaluate(()=>window.nav.length)!==0)throw new Error('失败却跳成功')
  checks.push('提交超时保留草稿且无伪成功')
  await page.screenshot({path:path.join(out,'request-draft.png'),fullPage:true})
  await page.evaluate(()=>{window.failCreate=false;return window.sceneVm.submit()})
  if(!await page.evaluate(()=>window.nav[0].url==='/pages/scene/detail?id=12'&&window.createCalls[0].key===window.createCalls[1].key))throw new Error('回执或幂等恢复错误')
  checks.push('重试使用同一幂等键并凭真实回执进入详情')
  await page.evaluate(()=>window.mountScene('pages/scene/detail.vue',{id:'12'}));await page.waitForFunction(()=>window.sceneVm.loading===false)
  await page.getByText('SR-QA-12',{exact:true}).waitFor();await page.screenshot({path:path.join(out,'request-detail.png'),fullPage:true});checks.push('需求号、原文、现场信息与真实空报价显示')
  await page.evaluate(()=>{window.uploadCalls=[];window.uni.chooseFile=o=>o.success({tempFiles:[{path:'/isolated/original.pdf',size:128}]});window.sceneApiFixture.uploadAttachment=async(id,path,access,key)=>{window.uploadCalls.push({id,path,key});throw new Error('上传结果待确认')};window.sceneApiFixture.uploadReceipt=async()=>({data:{id:77,state:'clean',availability_state:'available'}})})
  await page.getByText('选择并扫描上传',{exact:true}).dispatchEvent('tap');await page.getByText('上次上传回执待确认，查询或重选原件会保留同一提交标识。',{exact:true}).waitFor()
  if(!await page.evaluate(()=>window.uploadCalls.length===1&&window.storage.get('scene_request_v1:guest').pending['upload:12']))throw new Error('上传失败未保留未知结果')
  await page.getByText('查询原上传回执',{exact:true}).dispatchEvent('tap');await page.waitForFunction(()=>!window.storage.get('scene_request_v1:guest').pending['upload:12'])
  checks.push('真实附件组件上传丢响应保留原标识，查询已提交回执后清理待确认状态')
  await page.evaluate(()=>window.mountScene('pages/scene/requests.vue'));await page.waitForFunction(()=>window.sceneVm.loading===false);await page.getByText('暂无已提交需求',{exact:true}).waitFor();checks.push('需求列表成功空态')
  await page.evaluate(()=>window.mountScene('pages/scene/project.vue',{id:'2'}));await page.waitForFunction(()=>window.sceneVm.loading===false)
  await page.getByText('按冻结脚本完成迎宾（必需）',{exact:false}).waitFor();await page.screenshot({path:path.join(out,'project-acceptance.png'),fullPage:true});checks.push('冻结验收标准和项目款项状态分离')
  await page.evaluate(()=>{
    window.sceneVm.project.acceptances=[{id:10,item_key:'greeting',attempt:1,result:'fail',method:'customer_offline_recorded',occurred_at:'2026-10-19 10:00:00',created_at:'2026-10-20 00:00:00',note:'初验未达到脚本要求'}]
    window.sceneVm.project.offline_confirmations=[{id:20,state:'pending_review',occurred_at:'2026-10-19 10:00:00',created_at:'2026-10-20 00:00:00'}]
  })
  await page.getByText('客户线下确认 · 平台代录并经独立复核',{exact:true}).waitFor()
  await page.getByText('线下确认凭证 #20 · 待独立复核，未生效',{exact:true}).waitFor()
  await page.getByText('实际确认：2026-10-19 10:00:00 UTC',{exact:true}).waitFor()
  await page.getByText('平台记录：2026-10-20 00:00:00 UTC',{exact:true}).waitFor()
  if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw new Error('线下凭证说明在窄屏出现横向溢出')
  await page.screenshot({path:path.join(out,'project-offline-confirmation.png'),fullPage:true})
  checks.push('客户区分线下验收来源、实际确认/平台记录时间和待复核未生效；390px 无横向溢出')
  await page.evaluate(()=>{window.sceneVm.project.acceptances[0].method='untrusted_source';window.sceneVm.project.acceptances[0].occurred_at=null;window.sceneVm.project.offline_confirmations[0].state='rejected';window.sceneVm.project.offline_confirmations[0].reviewed_at='2026-10-20 01:00:00'})
  await page.getByText('历史验收记录（来源待核实）',{exact:true}).waitFor()
  await page.getByText('实际确认：历史实际时间未记录',{exact:true}).waitFor()
  await page.getByText('线下确认凭证 #20 · 已退回，未生效',{exact:true}).waitFor()
  checks.push('未知历史来源与时间明确标记，退回凭证不显示验收通过')
  await page.evaluate(async()=>{
    window.projectData=(await window.sceneApiFixture.project()).data
    window.sceneApiFixture.project=async()=>({data:window.projectData})
    window.projectCalls=[]
    window.sceneApiFixture.projectAction=async(id,action,payload,access,key)=>{
      window.projectCalls.push({id,action,payload:structuredClone(payload),key})
      window.projectData.state='accepted';window.projectData.row_version=2
      if(window.projectCalls.length===1)throw new Error('响应丢失，验收回执待确认')
      return {data:{id,state:'accepted',row_version:2}}
    }
    window.sceneVm.acceptance.greeting=1;await window.sceneVm.submitAcceptance()
  })
  await page.getByText('操作回执待确认',{exact:true}).waitFor()
  await page.evaluate(()=>window.sceneVm.load())
  if(!await page.evaluate(()=>window.sceneVm.project.state==='accepted'&&window.sceneVm.pendingCommands.length===1))throw new Error('刷新后丢失验收待确认回执')
  await page.evaluate(()=>window.sceneVm.retryPending(window.sceneVm.pendingCommands[0]))
  await page.waitForFunction(()=>window.sceneVm.busy===false&&window.sceneVm.pendingCommands.length===0)
  if(!await page.evaluate(()=>window.projectCalls.length===2&&window.projectCalls[0].key===window.projectCalls[1].key&&window.projectCalls[1].payload.row_version===1))throw new Error('验收重试没有恢复原版本和幂等键')
  checks.push('验收提交成功但丢响应，刷新后按原载荷和原键恢复回执')
  await page.evaluate(()=>{
    window.projectData.state='delivering';window.projectData.row_version=13
    window.projectData.changes=[{id:7,kind:'change',state:'pending_customer_acceptance',reason:'QA 延长服务',price_delta_minor:30000,evaluation:JSON.stringify({summary:'延长服务并重新核验班次',schedule_delta:{starts_at:'2026-10-22 01:00:00',ends_at:'2026-10-22 10:00:00',timezone:'Asia/Shanghai'}})}]
    window.changeDecisionCalls=[]
    window.sceneApiFixture.projectChangeDecision=async(id,changeId,payload,access,key)=>{
      window.changeDecisionCalls.push({id,changeId,payload:structuredClone(payload),key})
      window.projectData.row_version=14;window.projectData.changes[0].state='applied'
      if(window.changeDecisionCalls.length===1)throw new Error('变更已提交，响应丢失')
      return {data:{id:changeId,state:'applied',effective:true}}
    }
    return window.sceneVm.load()
  })
  await page.getByText('延长服务并重新核验班次',{exact:true}).waitFor()
  await page.getByText('新服务时段：2026-10-22 01:00:00 至 2026-10-22 10:00:00 UTC · 服务地 Asia/Shanghai',{exact:true}).waitFor()
  await page.getByText('接受变更并应用',{exact:true}).dispatchEvent('tap')
  await page.getByText('按原内容重试变更确认',{exact:true}).waitFor()
  await page.evaluate(()=>window.mountScene('pages/scene/project.vue',{id:'2'}));await page.waitForFunction(()=>!window.sceneVm.loading)
  await page.getByText('按原内容重试变更确认',{exact:true}).dispatchEvent('tap')
  await page.waitForFunction(()=>!window.sceneVm.busy&&window.sceneVm.pendingCommands.length===0)
  if(!await page.evaluate(()=>window.changeDecisionCalls.length===2&&JSON.stringify(window.changeDecisionCalls[0])===JSON.stringify(window.changeDecisionCalls[1])&&window.changeDecisionCalls[1].payload.row_version===13))throw new Error('变更重试未恢复原接口、编号、载荷和幂等键')
  checks.push('P1 真实变更确认按钮展示新方案，丢响应重载后原接口、原编号、原版本和原键恢复')
  await page.evaluate(async()=>{
    const record=(await window.sceneApiFixture.detail()).data
    const quote={id:31,version:2,row_version:4,request_revision_no:1,state:'sent',currency:'CNY',total_minor:12721,deposit_minor:10000,discount_minor:0,tax_minor:720,expires_at:'2026-11-01 00:00:00',is_expired:false,terms_hash:'a'.repeat(64),content_hash:'b'.repeat(64),terms:{terms:{version:'test-v1',text:'隔离测试正式服务条款'}},schedule:{starts_at:'2026-11-02T10:00:00+08:00',ends_at:'2026-11-02T18:00:00+08:00',timezone:'Asia/Shanghai'},items:[{name:'人工现场服务',quantity:1,billing_units:1,unit:'day',line_minor:12721}]}
    quote.proposal={title:'受控迎宾实例方案',version:2,scope:'冻结脚本内的迎宾和现场讲解',exclusions:'不包含无人值守和自主跨楼层',components:[{key:'robot',label:'已核验机器人',quantity:1,required:true,selected:true}],acceptance:[{key:'greeting',label:'完成冻结脚本',required:true}]}
    quote.cancellation={version:'commercial-qa-v1',basis:'verified_paid_total',tiers:[{hours_before:48,penalty_bps:1000}],default_penalty_bps:2000}
    const old={...quote,id:30,version:1,state:'voided',total_minor:11449,discount_minor:1200,tax_minor:648,items:[{...quote.items[0],line_minor:11449}]}
    window.quoteData={...record,quotes:[quote,old]};window.sceneApiFixture.detail=async()=>({data:window.quoteData})
    window.comparisonFixture={left:old,right:quote,same_currency:true,amount_deltas:{total_minor:1272,deposit_minor:0},changes:{terms:false,request_revision:false,schedule:false,items:true}}
    window.sceneApiFixture.compareQuotes=async()=>({data:window.comparisonFixture})
    window.rejectCalls=[]
    window.sceneApiFixture.reject=async(id,payload,access,key)=>{
      window.rejectCalls.push({id,payload:structuredClone(payload),key})
      if(window.rejectCalls.length===1)throw new Error('拒绝响应超时')
      quote.state='rejected';quote.row_version=5;quote.customer_rejection={reason:payload.reason}
      return {data:{id,state:'rejected',row_version:5}}
    }
    window.mountScene('pages/scene/detail.vue',{id:'12'})
  })
  await page.waitForFunction(()=>window.sceneVm.loading===false)
  await page.getByText('交付范围：冻结脚本内的迎宾和现场讲解',{exact:true}).first().waitFor();await page.getByText('不含范围：不包含无人值守和自主跨楼层',{exact:true}).first().waitFor()
  checks.push('客户报价详情展示冻结方案、排除项、必选数量与验收标准')
  await page.getByText('取消政策 commercial-qa-v1 · 按已复核总收款计罚',{exact:true}).first().waitFor()
  await page.getByText('距开场至少 48 小时：扣罚 10%',{exact:true}).first().waitFor()
  await page.getByText('其他时间：扣罚 20%；已核准应退和实际退款不会重复计费。',{exact:true}).first().waitFor()
  checks.push('P1 客户接受前展示冻结取消政策版本、计算基数、档位与明确兜底费率')
  await page.evaluate(()=>window.sceneVm.compareQuotes());await page.getByText('服务总额变化 +12.72 CNY；押金变化 0.00',{exact:false}).waitFor()
  checks.push('已发送报价对比展示金额变化、条款/范围/时间差异且保留历史')
  await page.screenshot({path:path.join(out,'quote-comparison.png'),fullPage:true})
  await page.evaluate(async()=>{
    window.sceneApiFixture.compareQuotes=()=>new Promise(resolve=>{window.resolveComparison=resolve})
    window.pendingComparison=window.sceneVm.compareQuotes()
    window.sceneVm.selectCompare('leftIndex',{detail:{value:0}});window.sceneVm.selectCompare('rightIndex',{detail:{value:1}})
    window.resolveComparison({data:window.comparisonFixture});await window.pendingComparison
  })
  if(!await page.evaluate(()=>window.sceneVm.comparison===null))throw new Error('旧比较响应覆盖新选择')
  checks.push('比较过程中切换版本，旧响应不覆盖当前选择')
  await page.evaluate(()=>{window.sceneVm.rejectionReasons[31]='需要调整服务安排';return window.sceneVm.reject(window.sceneVm.record.quotes[0])})
  await page.waitForFunction(()=>window.sceneVm.busy===false&&window.sceneVm.pendingCommands.length===1)
  if(!await page.getByText('接受本版报价',{exact:true}).isDisabled())throw new Error('拒绝结果未知时仍可发送冲突的接受操作')
  await page.screenshot({path:path.join(out,'quote-rejection-pending.png'),fullPage:true})
  await page.evaluate(()=>window.sceneVm.retryPending(window.sceneVm.pendingCommands[0]))
  await page.waitForFunction(()=>window.sceneVm.busy===false&&window.sceneVm.pendingCommands.length===0)
  await page.getByText('客户已拒绝',{exact:true}).waitFor()
  if(!await page.evaluate(()=>window.rejectCalls.length===2&&window.rejectCalls[0].key===window.rejectCalls[1].key))throw new Error('报价拒绝重试未保留幂等键')
  checks.push('客户拒绝丢响应后原键重试，显示真实拒绝状态与原因')
  await page.evaluate(()=>{window.sceneApiFixture.compareQuotes=async()=>{throw new Error('比较服务暂时不可用')};return window.sceneVm.compareQuotes()})
  await page.getByText('比较服务暂时不可用',{exact:true}).waitFor()
  if(!await page.evaluate(()=>window.sceneVm.comparison===null))throw new Error('比较失败仍保留旧成功结果')
  checks.push('报价比较失败明确展示错误，不沿用旧结果')
  await page.evaluate(()=>{
    window.storage.set('token','sc3-one');window.storage.set('customer',{id:1})
    window.confirmationData={quote:{id:88,version:1,state:'accepted',currency:'CNY',total_minor:11449,deposit_minor:1000,terms_hash:'a'.repeat(64),content_hash:'b'.repeat(64),
      terms:{terms:{text:'隔离测试商务条款'}},cancellation:{version:'commercial-qa-v1',basis:'verified_paid_total',tiers:[{hours_before:48,penalty_bps:1000}],default_penalty_bps:2000},confirmation:{method:'offline_contract',required_minor:0,deadline:'2026-10-31 10:00:00'},
      schedule:{starts_at:'2026-11-01T10:00:00+08:00',ends_at:'2026-11-01T18:00:00+08:00',timezone:'Asia/Shanghai'},items:[{kind:'robot',charge_type:'fee'}],proposal:{scope:'按冻结迎宾脚本完成服务',exclusions:'不含无人值守与自动跨层'}},
      request_id:12,conversion_enabled:true,login_required:true,receipt:null,held_resources:[],hold_group:null,hold_expires_at:null,commercial:{contract_reviewed:false,paid_minor:0}}
    window.sceneApiFixture.confirmation=async()=>({data:structuredClone(window.confirmationData)})
    window.mountScene('pages/scene/confirm.vue',{quoteId:'88'})
  });await page.waitForFunction(()=>!window.sceneVm.loading)
  await page.getByText('取消政策 commercial-qa-v1 · 按已复核总收款计罚',{exact:true}).waitFor()
  await page.getByText('其他时间：扣罚 20%。实际退款仍须独立复核。',{exact:true}).waitFor()
  checks.push('P1 订单确认页再次显示同版取消政策，并区分应退责任与实际退款复核')
  await page.getByText('请登录后，在需求详情使用本机原凭证认领需求，再确认订单。',{exact:true}).waitFor()
  if(!await page.getByText('确认服务订单',{exact:true}).isDisabled())throw new Error('未登录仍可确认')
  await page.evaluate(()=>{window.sceneVm.consent=true;window.sceneVm.record.login_required=false})
  if(!await page.getByText('确认服务订单',{exact:true}).isDisabled())throw new Error('商务待复核仍可确认')
  await page.evaluate(()=>window.sceneVm.record.commercial.contract_reviewed=true)
  if(!await page.getByText('确认服务订单',{exact:true}).isDisabled())throw new Error('缺资源仍可确认')
  checks.push('订单确认区分登录认领、商务独立复核与完整资源，缺一项禁止提交')
  await page.evaluate(()=>{
    window.convertCalls=[]
    window.sceneVm.record.hold_group='00000000-0000-4000-8000-000000000088';window.sceneVm.record.hold_expires_at='2026-10-20 10:20:00'
    window.sceneVm.record.held_resources=[{kind:'robot',occupies_from:'2026-11-01 01:00:00',occupies_until:'2026-11-01 11:00:00'},{kind:'operator',occupies_from:'2026-11-01 00:30:00',occupies_until:'2026-11-01 11:30:00'}]
    window.sceneApiFixture.convert=async(id,payload,access,key)=>{
      window.convertCalls.push({id,payload:structuredClone(payload),key})
      if(window.convertCalls.length===1)throw new Error('确认结果待查询')
      return {data:{id:9,state:'confirmed',quote_id:88,order_id:7,increment_id:'SC-QA-7',project_id:9,project_no:'SP-QA-9',delivery_status:'confirmed',commercial_state:'confirmed',payment_state:'unpaid'}}
    }
  })
  await page.screenshot({path:path.join(out,'order-confirmation.png'),fullPage:true})
  await page.getByText('确认服务订单',{exact:true}).dispatchEvent('tap');await page.waitForFunction(()=>!window.sceneVm.busy&&!!window.sceneVm.pending)
  if(await page.getByText('服务已确认',{exact:true}).count())throw new Error('丢响应伪造成功')
  await page.getByText('查询原提交结果',{exact:true}).dispatchEvent('tap');await page.waitForFunction(()=>!window.sceneVm.busy&&!window.sceneVm.pending)
  await page.getByText('订单号 SC-QA-7',{exact:true}).waitFor()
  if(!await page.evaluate(()=>window.convertCalls.length===2&&window.convertCalls[0].key===window.convertCalls[1].key&&JSON.stringify(window.convertCalls[0].payload)===JSON.stringify(window.convertCalls[1].payload)))throw new Error('订单重试变更原提交')
  if(!await page.getByText('商务 已确认 · 款项 未收款',{exact:true}).count()) {
    if(!await page.evaluate(()=>window.sceneVm.record.receipt.payment_state==='unpaid'))throw new Error('确认服务被当成支付成功')
  }
  await page.screenshot({path:path.join(out,'order-confirmed-unpaid.png'),fullPage:true})
  checks.push('服务确认丢响应保留原提交，原键恢复同一订单，未收款与履约确认分开显示')
  await page.evaluate(()=>{window.confirmationData.login_required=false;window.confirmationData.commercial.contract_reviewed=true;window.confirmationData.hold_group='00000000-0000-4000-8000-000000000088';window.confirmationData.held_resources=[{kind:'robot'}];window.sceneApiFixture.convert=async()=>({data:{id:9,state:'confirmed'}});return window.sceneVm.load()})
  await page.evaluate(()=>{window.sceneVm.consent=true;return window.sceneVm.confirm()});await page.waitForFunction(()=>!window.sceneVm.busy&&!!window.sceneVm.pending)
  if(!await page.evaluate(()=>!window.sceneVm.record.receipt&&!!window.storage.get('scene_request_v1:customer:1').pending['quote:88:convert']))throw new Error('残缺回执丢失原提交或伪成功')
  checks.push('残缺订单回执保留原提交，不能只凭 success/state 展示确认成功')
  await page.evaluate(()=>{
    window.sceneApiFixture.confirmation=()=>new Promise(resolve=>window.resolveOldConfirmation=resolve)
    window.sceneVm.load()
  });await page.waitForFunction(()=>!!window.resolveOldConfirmation)
  await page.evaluate(()=>{
    window.storage.set('token','sc3-two');window.storage.set('customer',{id:2})
    window.sceneApiFixture.confirmation=async()=>{throw new Error('无权查看此报价')}
    window.sceneVm.$options.onShow.call(window.sceneVm)
    window.resolveOldConfirmation({data:structuredClone(window.confirmationData)})
  });await page.getByText('无权查看此报价',{exact:true}).waitFor()
  if(!await page.evaluate(()=>window.sceneVm.record===null&&window.sceneVm.pending===null))throw new Error('切换主体仍显示上一客户报价或待确认命令')
  checks.push('订单确认中切换账号清空私有资料，旧账号延迟响应不覆盖新主体')
  await page.evaluate(()=>window.mountScene('pages/scene/confirm.vue',{quoteId:'invalid'}));await page.getByText('报价编号无效',{exact:true}).waitFor()
  checks.push('直接进入无效报价链接显示明确错误')
  await page.evaluate(()=>{
    const source=window.compiledSceneSources['pages/order/index.vue']
    const component=new Function('CustomTabBar','StateView','orderApi','sceneStatusLabel',source.script.replace('export default','return'))({}, {}, {}, window.sceneStatusForTests)
    const mapped=component.methods.mapApiOrder({id:7,order_type:'plan',status:'pending',grand_total:'114.49',items:[{product_id:101,name:'设备租用费用',qty_ordered:1}],scene_workflow:{project_id:9,scene_id:1,state:'preparing',payment_state:'unpaid'}})
    if(mapped.status!=='scene'||!mapped.statusText.includes('未确认收款')||!mapped.statusText.includes('交付准备'))throw new Error('普通订单卡片把场景确认误作待付款或服务中')
    const before=window.nav.length;component.methods.viewDetail(mapped);component.methods.reorder(mapped)
    if(window.nav[before].url!=='/pages/scene/project?id=9'||!window.nav[before+1].url.startsWith('/pages/scene/request?'))throw new Error('场景旧订单入口绕回普通结算')
    const detailSource=window.compiledSceneSources['pages/order/order-detail.vue']
    const detail=new Function('StateView','NavBar',detailSource.script.replace('export default','return'))({}, {})
    const vm={order:{private:true},loadError:''}
    if(!detail.methods.routeSceneOrder.call(vm,{sceneWorkflow:mapped.sceneWorkflow})||vm.order!==null)throw new Error('旧缓存场景详情仍暴露普通取消/支付动作')
  })
  checks.push('普通订单卡片分离场景款项状态；详情、旧缓存与再次下单统一进入项目或新需求')
  if(pageErrors.length)throw new Error(pageErrors.join('\n'))
  fs.writeFileSync(path.join(out,'ui-verification.json'),JSON.stringify({environment:'Chrome headless + Vue 3.5.13，隔离 API 夹具；非 HBuilderX H5/小程序构建',compiledSfc:files,checks,pageErrors},null,2))
  process.stdout.write(JSON.stringify({compiledSfc:files.length,checks:checks.length,pageErrors},null,2)+'\n')
}finally{await browser.close()}
