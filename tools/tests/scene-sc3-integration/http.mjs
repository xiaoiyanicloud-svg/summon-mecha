import fs from 'node:fs'
import {randomUUID} from 'node:crypto'
const dir=process.env.SCENE_SC3_INTEGRATION_DIR,f=JSON.parse(fs.readFileSync(dir+'/fixtures.json','utf8')),base=process.env.APP_URL
const checks=[]
function check(name,condition,detail){if(!condition)throw new Error(name+' '+JSON.stringify(detail));checks.push({name,pass:true});console.log('PASS',name)}
function client(){const cookies=new Map();return async(url,options={})=>{const headers=new Headers(options.headers);if(cookies.size)headers.set('Cookie',[...cookies].map(([k,v])=>k+'='+v).join('; '));const r=await fetch(base+url,{...options,headers,redirect:'manual',signal:AbortSignal.timeout(180000)});for(const entry of (r.headers.get('set-cookie')||'').split(/,(?=\s*[^; ,=]+=)/)){const pair=entry.trim().split(';')[0],i=pair.indexOf('=');if(i>0)cookies.set(pair.slice(0,i),pair.slice(i+1))}const text=await r.text();let data;try{data=JSON.parse(text)}catch{}return {status:r.status,text,data,headers:r.headers}}}
async function login(name){const c=client(),get=await c('/admin/login'),token=get.text.match(/name="_token"\s+value="([^"]+)"/)?.[1]||get.text.match(/name="csrf-token"\s+content="([^"]+)"/)?.[1];check(name+' 登录页真实渲染及 CSRF token',get.status===200&&!!token,{status:get.status,preview:get.text.slice(0,100)});const r=await c('/admin/login',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({_token:token,email:f.admins[name].email,password:f.pass})});check(name+' 真实会话登录',r.status===302,{status:r.status,preview:r.text.slice(0,120)});return {c,token}}
const health=await fetch(base+'/up');check('完整应用健康检查',health.status===200,health.status)
const a=await login('author');const resources=await a.c('/admin/catalog/scene-workflow/resources');fs.writeFileSync(dir+'/resource-page.html',resources.text);check('资源后台完整布局渲染',resources.status===200,{status:resources.status,preview:resources.text.slice(0,140)})
const prefix='/admin/catalog/scene-workflow',roles={author:a,reviewer:await login('reviewer')}
const key=()=> 'sc3-http-'+randomUUID().replaceAll('-','')
const iso=(ms=Date.now())=>new Date(ms).toISOString().replace(/\.\d{3}Z$/,'Z')
const asia=ms=>new Date(ms+8*3600000).toISOString().replace(/\.\d{3}Z$/,'+08:00')
async function post(actor,url,data={},csrf=true){return actor.c(prefix+url,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json',...(csrf?{'X-CSRF-TOKEN':actor.token}:{}),'Idempotency-Key':key()},body:JSON.stringify(data)})}
const guest=await client()(prefix+'/resources');check('未登录不能读取后台资源',guest.status===302,guest.status)
const csrf=await post(a,'/supply/resources',{},false);check('真实 HTTP 缺 CSRF token 拒绝写入',csrf.status===419,csrf)
for(const name of ['viewer','supply','supply_review','reserve','finance','finance_review','none'])roles[name]=await login(name)
const matrix=[['supply','/supply/resources'],['supply_review','/resources/999999/verify'],['supply','/sku-mappings'],['supply_review','/sku-mappings/999999/approve'],['reserve',`/quotes/${f.quote_id}/reservations/hold`],['reserve',`/quotes/${f.quote_id}/reservations/release`],['reserve','/resources/999999/pause'],['finance',`/quotes/${f.quote_id}/commercial/record`],['finance_review',`/quotes/${f.quote_id}/commercial/999999/review`]]
for(const name of ['viewer','supply','supply_review','reserve','finance','finance_review','none']){
  for(const [permission,url]of matrix){const r=await post(roles[name],url);check(`ACL ${name} → ${permission} ${url}`,name===permission?[400,404,409,422].includes(r.status):[401,403].includes(r.status),{status:r.status,data:r.data})}
}
const until=asia(Date.now()+60*86400000),from=asia(Date.now()-86400000)
let resource,r
{r=await post(a,'/supply/resources',{resource_no:'SC3-HTTP-ROBOT',kind:'robot',product_id:f.products.robot,binding_id:f.binding,identity_reference:'SC3-HTTP-ROBOT-SOURCE',external_reference:'独立 QA 资源原始资料',city:'上海',timezone:'Asia/Shanghai',verification_expires_at:until});check('HTTP 资源登记为待核验',r.status===200&&r.data.data.state==='unverified',r);resource=r.data.data.id}
const verify={row_version:1,verification_reference:'独立 QA 资源复核',verification_expires_at:until,buffer_before_minutes:60,buffer_after_minutes:60,availability:[{starts_at:from,ends_at:until}]}
r=await post(a,`/resources/${resource}/verify`,verify);check('资源登记人自审被拒',r.status===403&&r.data.code==='SCENE_SELF_APPROVAL',r)
r=await post(roles.reviewer,`/resources/${resource}/verify`,verify);check('另一 Admin 核验资源成功',r.status===200&&r.data.data.state==='ready',r)
for(const kind of ['robot','transport']){r=await post(a,'/sku-mappings',{price_item_id:f.prices[kind],channel_id:1,product_id:f.products[kind==='robot'?'service':'transport'],mode:'service',reason:'独立 QA 真实 EAV 服务 SKU 映射'});check(kind+' HTTP SKU 登记',r.status===200,r);const id=r.data.data.id;r=await post(a,`/sku-mappings/${id}/approve`,{row_version:1});check(kind+' 映射不能自审',r.status===403,r);r=await post(roles.reviewer,`/sku-mappings/${id}/approve`,{row_version:1});check(kind+' EAV 商品映射独立批准',r.status===200,r)}
const api=async(customer,url,data,submitKey)=>{const headers={Accept:'application/json',Authorization:'Bearer '+f.customers[customer].token};if(data)Object.assign(headers,{'Content-Type':'application/json','Idempotency-Key':submitKey||key()});const r=await fetch(base+'/api'+url,{method:data?'POST':'GET',headers,body:data?JSON.stringify(data):undefined,signal:AbortSignal.timeout(180000)});return {status:r.status,data:await r.json()}}
r=await api(1,`/scene-quotes/${f.quote_id}/confirmation`);check('真实 Sanctum 跨客户读取报价拒绝',r.status===404,r)
r=await api(0,`/scene-quotes/${f.quote_id}/accept`,f.quote);check('客户通过真实 API 接受报价',r.status===200,r)
r=await api(0,`/scene-quotes/${f.quote_id}/convert`,{terms_hash:f.quote.terms_hash,content_hash:f.quote.content_hash});check('未满足商务条件不能转单',r.status===409&&r.data.code==='SCENE_COMMERCIAL_UNCONFIRMED',r)
r=await post(a,`/quotes/${f.quote_id}/commercial/record`,{kind:'offline_contract',amount_minor:0,reference:'SC3-HTTP-CONTRACT',occurred_at:iso(Date.now()-60000),evidence_ids:[f.proof_id]});check('真实 HTTP 商务凭证登记',r.status===200,r);const commercial=r.data.data.id
r=await post(a,`/quotes/${f.quote_id}/commercial/${commercial}/review`,{decision:'approve',reason:'独立 QA 自审验证'});check('商务凭证录入人不能复核',r.status===403,r)
r=await post(roles.reviewer,`/quotes/${f.quote_id}/commercial/${commercial}/review`,{decision:'approve',reason:'独立 QA 原件及条款复核'});check('商务凭证独立复核',r.status===200,r)
r=await post(roles.reviewer,`/quotes/${f.quote_id}/reservations/hold`,{resource_ids:[resource]});check('真实 HTTP 占用完整资源组',r.status===200,r);const payload={terms_hash:f.quote.terms_hash,content_hash:f.quote.content_hash,hold_group:r.data.data.hold_group},submitKey=key()
r=await api(0,`/scene-quotes/${f.quote_id}/convert`,payload,submitKey);check('真实 EAV + 控制器 + 监听器转单',r.status===200&&r.data.data.order_id>0&&r.data.data.project_id>0,r);const receipt=r.data.data
r=await api(0,`/scene-quotes/${f.quote_id}/convert`,payload,submitKey);check('原键重试同单',r.status===200&&r.data.data.order_id===receipt.order_id,r)
r=await api(0,`/scene-quotes/${f.quote_id}/convert`,payload);check('跨键丢响应恢复同单',r.status===200&&r.data.data.project_id===receipt.project_id,r)
r=await api(0,`/scene-projects/${receipt.project_id}`);check('实际项目与冻结资源可读',r.status===200&&r.data.data.payment_state==='unpaid',r)
const requestPage=await a.c(prefix+`/requests/${f.request_id}`);fs.writeFileSync(dir+'/request-page.html',requestPage.text);check('报价/交易/项目后台完整页面渲染',requestPage.status===200,{status:requestPage.status,preview:requestPage.text.slice(0,120)})
const flags=JSON.parse(fs.readFileSync(dir+'/flags.json','utf8'));fs.writeFileSync(dir+'/flags.json',JSON.stringify({...flags,order_conversion_enabled:false,reservations_enabled:false}));r=await api(0,`/scene-quotes/${f.quote_id}/confirmation`);check('停写后实际 HTTP 可恢复原单',r.status===200&&r.data.data.receipt.order_id===receipt.order_id,r);fs.writeFileSync(dir+'/flags.json',JSON.stringify(flags))
fs.writeFileSync(dir+'/receipt.json',JSON.stringify(receipt,null,2))
fs.writeFileSync(dir+'/http-checks.json',JSON.stringify({checks},null,2))
