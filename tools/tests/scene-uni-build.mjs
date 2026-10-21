import fs from 'node:fs'
import path from 'node:path'
import {spawn} from 'node:child_process'
import {createHash} from 'node:crypto'

// 使用本机已安装的 HBuilderX Vue3 编译器；只编译到本仓库忽略目录，不启动 IDE 或发布。
const root=process.cwd(), home=path.resolve(process.argv[2]||process.env.HBUILDERX_HOME||'')
const compiler=path.join(home,'plugins/uniapp-cli-vite/node_modules/@dcloudio/vite-plugin-uni/bin/uni.js')
if(!fs.existsSync(compiler))throw new Error('请通过参数或 HBUILDERX_HOME 提供已安装的 HBuilderX 路径')
// 使用调用脚本的 CLI Node；本机 Node 18.20.8 已验证，也可显式选择兼容运行时。
const node=process.env.SCENE_BUILD_NODE||process.execPath
const output=path.join(root,'.claude','sc3-builds',String(Date.now()));fs.mkdirSync(output,{recursive:true})
// 独立 CLI 目录复用已安装编译器依赖，避免把后台/vendor/备份纳入前端扫描，也不依赖 IDE 的进程通信。
const input=path.join(output,'source');fs.mkdirSync(input)
const sourceHashes={}
for(const name of ['App.vue','main.js','manifest.json','pages.json','uni.scss','components','pages','utils','store','static','uni_modules','assets','common','custom-tab-bar']){
  const source=path.join(root,name);if(!fs.existsSync(source))continue
  fs.cpSync(source,path.join(input,name),{recursive:true})
  const hashFiles=p=>{if(fs.statSync(p).isDirectory()){for(const child of fs.readdirSync(p))hashFiles(path.join(p,child))}else sourceHashes[path.relative(root,p).replaceAll('\\','/')]=createHash('sha256').update(fs.readFileSync(p)).digest('hex')};hashFiles(source)
}
const modules=path.join(output,'node_modules');fs.mkdirSync(modules)
const compilerModules=path.join(home,'plugins/uniapp-cli-vite/node_modules')
for(const item of fs.readdirSync(compilerModules,{withFileTypes:true})){
  if(item.isDirectory()&&!item.name.startsWith('.'))fs.symlinkSync(path.join(compilerModules,item.name),path.join(modules,item.name),process.platform==='win32'?'junction':'dir')
}
// HBuilderX 将 Sass 放在独立插件；只在 QA 目录增加只读引用，不写入 IDE 的 node_modules。
if(!fs.existsSync(path.join(modules,'sass')))fs.symlinkSync(path.join(home,'plugins/compile-dart-sass/node_modules/sass'),path.join(modules,'sass'),process.platform==='win32'?'junction':'dir')
const packageInfo=JSON.parse(fs.readFileSync(path.join(home,'plugins/uniapp-cli-vite/package.json'),'utf8'))
// 平台插件按依赖清单发现；缺失清单会只打包 App，不能视为 uni-app 完整构建。
fs.writeFileSync(path.join(output,'package.json'),JSON.stringify({name:'scene-sc3-build-qa',version:'0.0.0',private:true,dependencies:packageInfo.dependencies,devDependencies:packageInfo.devDependencies}))
fs.writeFileSync(path.join(output,'index.html'),'<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><div id="app"></div><script type="module" src="/source/main.js"></script></body></html>')
const results=[]
for(const platform of ['h5','mp-weixin']){
  const target=path.join(output,platform)
  const env={...process.env,UNI_PLATFORM:platform,UNI_INPUT_DIR:input,UNI_OUTPUT_DIR:target,NODE_ENV:'production',NODE_PATH:[path.join(home,'plugins/uniapp-cli-vite/node_modules'),path.join(home,'plugins/compile-dart-sass/node_modules')].join(path.delimiter)}
  for(const key of ['HX_APP_ROOT','HX_Version','RUN_BY_HBUILDERX','UNI_HBUILDERX_PLUGINS'])delete env[key]
  const result=await new Promise(resolve=>{
    const p=spawn(node,[path.join(root,'tools/tests/scene-uni-build-worker.cjs'),home,platform],{cwd:output,windowsHide:true,env})
    let log='';const logFile=path.join(output,platform+'.log');const add=v=>{log+=v;fs.appendFileSync(logFile,v)}
    let timedOut=false;const timeout=setTimeout(()=>{timedOut=true;p.kill()},180000)
    p.stdout.on('data',add);p.stderr.on('data',add);p.on('error',e=>{clearTimeout(timeout);resolve({platform,exitCode:-1,log:e.message})});p.on('close',exitCode=>{clearTimeout(timeout);resolve({platform,exitCode,timedOut,log})})
  })
  fs.writeFileSync(path.join(output,platform+'.log'),result.log)
  const artifacts=[]
  const collect=p=>{if(!fs.existsSync(p))return;for(const item of fs.readdirSync(p,{withFileTypes:true})){const file=path.join(p,item.name);if(item.isDirectory())collect(file);else artifacts.push(path.relative(target,file).replaceAll('\\','/'))}};collect(target)
  const required=platform==='h5'?['index.html']:['app.json','app.js','pages/scene/confirm.js','pages/scene/confirm.wxml','pages/scene/project.js','pages/order/index.js']
  const missing=required.filter(f=>!artifacts.includes(f))
  if(platform==='h5'&&!artifacts.some(f=>/scene-confirm.*\.js$/.test(f)))missing.push('scene confirmation page chunk')
  results.push({...result,output:target,artifacts,missing,complete:result.exitCode===0&&missing.length===0})
  console.log(platform,result.exitCode,result.log.slice(-2400))
}
fs.writeFileSync(path.join(output,'build-verification.json'),JSON.stringify({compiler:packageInfo.version,node,mode:'HBuilderX bundled Vue3 compiler in isolated CLI directory, no publish',sourceHashes,results},null,2))
console.log('证据目录',output)
process.exitCode=results.some(r=>!r.complete)?1:0
