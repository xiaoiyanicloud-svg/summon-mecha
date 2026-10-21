import fs from 'node:fs'
import path from 'node:path'
import {createHash} from 'node:crypto'
import {execFileSync} from 'node:child_process'

// --write 核验源码语法/格式并整理实际成功证据；默认只读，不重写通过结果。
const dir=path.resolve('docs/scenes/sc3-closeout-evidence'),args=process.argv.slice(2),write=args.includes('--write')
const hash=file=>createHash('sha256').update(fs.readFileSync(file)).digest('hex')
// 保留原始字节哈希，并允许 Git 仅转换 UTF-8 文本的 CRLF/LF；二进制仍逐字节核对。
const textHash=file=>{
  const bytes=fs.readFileSync(file),text=bytes.toString('utf8')
  const canonical=!text.includes('\0')&&Buffer.from(text).equals(bytes)?Buffer.from(text.replace(/\r\n/g,'\n')):bytes
  return createHash('sha256').update(canonical).digest('hex')
}
const json=file=>JSON.parse(fs.readFileSync(path.join(dir,file),'utf8'))
const git=(...v)=>execFileSync('git',['-c','safe.directory='+process.cwd().replaceAll('\\','/'),...v],{encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim()
const previous=fs.existsSync(path.join(dir,'verification.json'))?json('verification.json'):{}
const matchesHash=(file,expected)=>hash(file)===expected||textHash(file)===previous.text_lf_hashes?.[path.relative(process.cwd(),file).replaceAll('\\','/')]
const changed=write?[...new Set([...git('diff','--name-only').split(/\r?\n/),...git('ls-files','--others','--exclude-standard').split(/\r?\n/)])].filter(Boolean):[]
const source=[...new Set([...Object.keys(previous.source_hashes||{}),...changed])].filter(f=>fs.existsSync(f)&&/\.(php|vue|js|mjs|cjs|json)$/.test(f)&&/^(my-bagisto-store\/|pages\/|tools\/tests\/|utils\/|pages\.json$)/.test(f))
function assert(ok,message){if(!ok)throw new Error(message)}
const suites=[['mysql.json',40,'passed'],['sqlite.json',35,'passed'],['regression.json',91,'passed'],['order-transactions.json',30,'passed'],['sc0.json',11,'groups'],['controllers.json',17,'passed'],['blade.json',10,'compiled']]
const checks=suites.map(([file,count,key])=>{const r=json(file);assert(r.failed===0&&r[key]===count,'未通过：'+file);return {file,count,failed:0}})
const phpPaths=source.filter(f=>f.endsWith('.php')),phpFiles=write?phpPaths.length:previous.php_syntax_files
if(write){
  const php=process.env.SCENE_PHP||'php'
  const lint=execFileSync(php,['-l',...phpPaths],{encoding:'utf8',timeout:180000,maxBuffer:2*1024*1024})
  const pint=execFileSync(php,['my-bagisto-store/vendor/bin/pint','--test',...phpPaths],{encoding:'utf8',timeout:240000,maxBuffer:2*1024*1024})
  fs.writeFileSync(path.join(dir,'php-lint.log'),lint);fs.writeFileSync(path.join(dir,'pint.log'),pint)
}
assert((fs.readFileSync(path.join(dir,'php-lint.log'),'utf8').match(/No syntax errors detected/g)||[]).length===phpFiles,'PHP 语法证据不完整')
assert(/\bPASS\b/.test(fs.readFileSync(path.join(dir,'pint.log'),'utf8')),'Pint 检查证据不完整')
const tap=fs.readFileSync(path.join(dir,'node.tap'),'utf8');assert(/# pass 34\b/.test(tap)&&/# fail 0\b/.test(tap),'Node 证据未通过')
const ui=json('ui-verification.json');assert(ui.compiledSfc.length===17&&ui.checks.length===23&&ui.pageErrors.length===0,'组件浏览器证据未通过')
const integration=json('integration/integration-verification.json')
assert(integration.ok&&integration.migrations>=217&&integration.http_checks===107&&integration.worker_checks===8&&integration.runtime_checks===10&&integration.h5_checks===6&&integration.preflight_ok,'完整集成证据未通过')
assert(integration.stages.every(s=>s.exit_code===0)&&integration.cleanup.own_database_removed&&integration.cleanup.own_instance_shutdown,'集成阶段或清理失败')
for(const name of ['http-checks','worker-checks','runtime-checks'])assert(json('integration/'+name+'.json').checks.every(c=>c.pass),'集成子项失败：'+name)
const h5=json('integration/h5-checks.json');assert(h5.pageErrors.length===0&&h5.checks.length===6,'实际 H5 联调失败')
const preflight=json('integration/full-preflight.json');assert(preflight.ok&&preflight.database_identity_verified&&!preflight.writes_performed&&preflight.pending_migrations.length===0,'完整正向预检失败')
let build
if(write){
  const index=args.indexOf('--build-dir');assert(index>=0&&args[index+1],'--write 须指定 --build-dir <本次真实构建目录>')
  const buildDir=path.resolve(args[index+1]);build=JSON.parse(fs.readFileSync(path.join(buildDir,'build-verification.json'),'utf8'))
  assert(build.results.length===2&&build.results.every(r=>r.complete&&r.exitCode===0&&!r.timedOut),'完整构建未通过')
  for(const [file,expected]of Object.entries(build.sourceHashes))assert(matchesHash(file,expected),'构建输入已变化：'+file)
  for(const result of build.results){
    fs.writeFileSync(path.join(dir,result.platform+'-build.log'),result.log)
    result.artifact_count=result.artifacts.length
    result.artifact_hashes=Object.fromEntries(result.artifacts.filter(f=>/\.(js|wxml|json|html|css|wxss)$/.test(f)).map(f=>[f,hash(path.join(result.output,f))]))
    delete result.log
  }
  fs.writeFileSync(path.join(dir,'build-verification.json'),JSON.stringify(build,null,2)+'\n')
}else build=json('build-verification.json')
assert(build.results.length===2&&build.results.every(r=>r.complete&&r.exitCode===0),'归档构建失败')
for(const [file,expected]of Object.entries(build.sourceHashes))assert(matchesHash(file,expected),'归档构建输入已变化：'+file)
if(write){
  const ownedDocs=['docs/scenes/SC3-Closeout-20261021.md','docs/scenes/SC3-Integration-Runbook.md','docs/scenes/Scene-Backlog-Acceptance.md','docs/scenes/Scene-Engineering-Plan.md','docs/scenes/Scene-Workflow-Contract-v1.md','docs/scenes/Repository-Handoff.md','my-bagisto-store/qa/scenes/README.md']
  const docs=ownedDocs.filter(f=>fs.existsSync(f))
  const evidence=[];const collect=folder=>{for(const entry of fs.readdirSync(folder,{withFileTypes:true})){const file=path.join(folder,entry.name);if(entry.isDirectory())collect(file);else if(file!==path.join(dir,'verification.json'))evidence.push(file)}};collect(dir)
  const report={date:'2026-10-21',timezone:'Asia/Shanghai',generated_at:new Date().toISOString(),baseline:git('rev-parse','HEAD'),scope:'SC3-01–SC3-10 development and complete local integration closeout',test_suites:checks,node:{passed:34,failed:0},browser:{compiled_sfc:17,checks:23,page_errors:[]},complete_integration:{migrations:integration.migrations,http_checks:107,worker_checks:8,runtime_checks:10,h5_checks:6,preflight_ok:true,own_database_removed:true,own_instance_shutdown:true},builds:build.results.map(r=>({platform:r.platform,exit_code:r.exitCode,complete:r.complete,artifact_count:r.artifact_count})),php_syntax_files:phpFiles,pint:'passed',business_database_touched:false,production_deployed:false,production_flags_changed:false,order_conversion_default:false,git_commit_created:false,pushed:false,remaining_external_acceptance:['target host/address/connection and actual deployment facts','real supply/commercial evidence and operational signoff','Weixin device execution and production resident processes'],source_hashes:Object.fromEntries(source.sort().map(f=>[f,hash(f)])),documentation_hashes:Object.fromEntries(docs.sort().map(f=>[f,hash(f)])),evidence_hashes:Object.fromEntries(evidence.sort().map(f=>[path.relative(dir,f).replaceAll('\\','/'),hash(f)]))}
  const hashedFiles=[...new Set([...source,...docs,...evidence,...Object.keys(build.sourceHashes)].map(file=>path.relative(process.cwd(),path.resolve(file)).replaceAll('\\','/')))]
  report.text_lf_hashes=Object.fromEntries(hashedFiles.sort().map(file=>[file,textHash(file)]))
  fs.writeFileSync(path.join(dir,'verification.json'),JSON.stringify(report,null,2)+'\n')
}
const report=json('verification.json')
const matchesReport=(file,expected)=>hash(file)===expected||textHash(file)===report.text_lf_hashes?.[path.relative(process.cwd(),file).replaceAll('\\','/')]
for(const [file,expected]of Object.entries(report.source_hashes))assert(matchesReport(file,expected),'源文件证据已过期：'+file)
for(const [file,expected]of Object.entries(report.documentation_hashes||{}))assert(matchesReport(file,expected),'文档证据已过期：'+file)
for(const [file,expected]of Object.entries(report.evidence_hashes||{}))assert(matchesReport(path.join(dir,file),expected),'证据文件已变化：'+file)
console.log(JSON.stringify({ok:true,source_files:Object.keys(report.source_hashes).length,php_files:report.php_syntax_files,node_passed:34,complete_integration:report.complete_integration,builds:report.builds},null,2))
