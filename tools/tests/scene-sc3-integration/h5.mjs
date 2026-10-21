import fs from 'node:fs'
import path from 'node:path'
import http from 'node:http'
import {createRequire} from 'node:module'
const dir=path.resolve(process.env.SCENE_SC3_INTEGRATION_DIR),f=JSON.parse(fs.readFileSync(dir+'/fixtures.json','utf8')),receipt=JSON.parse(fs.readFileSync(dir+'/receipt.json','utf8'))
const root=path.resolve(process.env.SCENE_H5_BUILD),front='http://127.0.0.1:'+process.env.SCENE_H5_PORT,back=process.env.APP_URL
const req=createRequire(path.resolve(process.env.SCENE_QA_DIR||'.claude/backup/scene-sc0-20261020/qa','package.json')),{chromium}=req('playwright-core')
const mime={'.html':'text/html; charset=utf-8','.js':'application/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.json':'application/json','.png':'image/png','.jpg':'image/jpeg'}
const server=http.createServer((request,response)=>{const url=new URL(request.url,front),p=path.resolve(root,'.'+decodeURIComponent(url.pathname==='/'?'/index.html':url.pathname));if(!p.startsWith(root+path.sep)||!fs.existsSync(p)||!fs.statSync(p).isFile()){response.writeHead(404);response.end();return}response.writeHead(200,{'Content-Type':mime[path.extname(p)]||'application/octet-stream'});fs.createReadStream(p).pipe(response)})
await new Promise(resolve=>server.listen(Number(process.env.SCENE_H5_PORT),'127.0.0.1',resolve))
const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true}),page=await browser.newPage({viewport:{width:390,height:844},deviceScaleFactor:1})
const checks=[],errors=[],calls=[];page.on('pageerror',e=>errors.push(e.message))
await page.route('**/*',async route=>{const request=route.request(),url=new URL(request.url());if(url.origin===front)return route.continue();if((url.origin==='http://127.0.0.1:8008'||url.origin==='http://127.0.0.1:8000')&&url.pathname.startsWith('/api/')){const headers=await request.allHeaders();delete headers.host;delete headers.origin;const response=await fetch(back+url.pathname+url.search,{method:request.method(),headers,body:['GET','HEAD'].includes(request.method())?undefined:request.postDataBuffer(),signal:AbortSignal.timeout(180000)});calls.push({path:url.pathname,status:response.status});return route.fulfill({status:response.status,headers:{'Content-Type':response.headers.get('content-type')||'application/json','Access-Control-Allow-Origin':'*'},body:Buffer.from(await response.arrayBuffer())})}return route.abort()})
await page.addInitScript(({token,id})=>{if(!localStorage.getItem('token')){localStorage.setItem('token',token);localStorage.setItem('customer',JSON.stringify({type:'object',data:{id}}))}},{token:f.customers[0].token,id:f.customers[0].id})
try{
  await page.goto(front+'/#/pages/scene/confirm?quoteId='+f.quote_id,{waitUntil:'domcontentloaded'})
  await page.getByText('服务已确认',{exact:true}).waitFor({timeout:45000});checks.push('完整 HBuilderX H5 产物读取真实后端确认回执')
  if(!(await page.locator('body').innerText()).includes(receipt.increment_id))throw new Error('实际单号未显示')
  checks.push('页面显示实际订单编号、商务确认与未收款事实')
  const separated=await page.evaluate(()=>{const card=[...document.querySelectorAll('.card')].find(el=>el.textContent.includes('订单与交付项目'));const children=[...card.children],state=children.find(el=>el.textContent.startsWith('商务')),notice=children.find(el=>el.textContent.startsWith('本页记录'));return !!state&&!!notice&&notice.getBoundingClientRect().top>=state.getBoundingClientRect().bottom+4})
  if(!separated)throw new Error('收款状态与提示文字遮挡');checks.push('实际收款状态与提示文本分行且不遮挡')
  await page.screenshot({path:dir+'/h5-confirmation.png',fullPage:true})
  await page.getByText('查看交付安排',{exact:true}).click()
  await page.getByText('确认时的服务安排',{exact:true}).waitFor({timeout:45000});checks.push('实际 H5 从确认页进入同一交付项目并读取冻结资源')
  const content=await page.locator('body').innerText();fs.writeFileSync(dir+'/h5-project-text.txt',content);if(!content.includes('未确认收款'))throw new Error('未收款状态不可见')
  await page.screenshot({path:dir+'/h5-project.png',fullPage:true})
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth+2);if(overflow)throw new Error('390px 页面横向溢出');checks.push('真实编译 CSS 在 390px 下无横向溢出')
  await page.evaluate(({token,id})=>{localStorage.setItem('token',token);localStorage.setItem('customer',JSON.stringify({type:'object',data:{id}}))},{token:f.customers[1].token,id:f.customers[1].id})
  await page.goto(front+'/#/pages/scene/confirm?quoteId='+f.quote_id,{waitUntil:'domcontentloaded'})
  await page.getByText('记录不存在或无权访问。',{exact:false}).waitFor({timeout:45000})
  if((await page.locator('body').innerText()).includes(receipt.increment_id))throw new Error('切换账号残留旧单号');checks.push('真实 H5 切换账号后跨客户拒绝且不残留原单号')
  if(errors.length)throw new Error(errors.join('\n'))
  fs.writeFileSync(dir+'/h5-checks.json',JSON.stringify({environment:'Actual HBuilderX compiled H5 + actual Laravel HTTP/Sanctum + isolated MySQL; proxy changes destination only',checks,pageErrors:errors,apiCalls:calls},null,2));console.log('H5 checks',checks.length)
}finally{await browser.close();server.closeAllConnections();await new Promise(resolve=>server.close(resolve))}
