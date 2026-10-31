import fs from 'node:fs'
import path from 'node:path'
import net from 'node:net'
import {spawn, spawnSync} from 'node:child_process'
import {randomBytes,createHash} from 'node:crypto'

// 完整 HTTP/EAV/Admin/worker 演练；只创建本次独立 MySQL 实例，不接受已有数据库。
const options={}
for(let i=2;i<process.argv.length;i+=2){const name=process.argv[i];if(!['--mysql-bin','--php-bin','--h5-build','--evidence-dir'].includes(name)||!process.argv[i+1]||options[name])throw new Error('参数无效：'+name);options[name]=process.argv[i+1]}
const root=process.cwd(),tools=path.join(root,'tools/tests/scene-sc3-integration')
const mysql=path.resolve(options['--mysql-bin']||process.env.SCENE_MYSQL_BIN||'')
if(!fs.existsSync(mysql)||!fs.statSync(mysql).isFile())throw new Error('请显式提供 --mysql-bin <mysqld绝对路径>；不能复用现有业务实例')
const probe=spawnSync(options['--php-bin']||'php',['-r','echo json_encode(["binary"=>PHP_BINARY,"extensions"=>get_loaded_extensions()]);'],{encoding:'utf8',windowsHide:true})
if(probe.status!==0)throw new Error('PHP 运行时不可用')
const phpInfo=JSON.parse(probe.stdout),php=phpInfo.binary,phpArgs=['-d','max_execution_time=300']
for(const extension of ['pdo_mysql','intl'])if(!phpInfo.extensions.includes(extension))phpArgs.push('-d','extension='+extension)
const serverArgs=[...phpArgs]
if(process.platform==='win32'&&!phpInfo.extensions.includes('Zend OPcache'))serverArgs.push('-d','zend_extension=opcache','-d','opcache.enable_cli=1')
const h5=path.resolve(options['--h5-build']||'')
if(!fs.existsSync(path.join(h5,'index.html')))throw new Error('请显式提供 --h5-build <已成功完整编译的H5目录>')
const build=JSON.parse(fs.readFileSync(path.join(h5,'../build-verification.json'),'utf8'))
if(!build.results.some(r=>r.platform==='h5'&&r.complete))throw new Error('缺少完整 H5 构建成功证据')
const hash=f=>createHash('sha256').update(fs.readFileSync(f)).digest('hex')
for(const [file,expected]of Object.entries(build.sourceHashes))if(hash(path.join(root,file))!==expected)throw new Error('H5 构建输入已变化，请重新编译：'+file)
const freePort=()=>new Promise((resolve,reject)=>{const s=net.createServer();s.on('error',reject);s.listen(0,'127.0.0.1',()=>{const port=s.address().port;s.close(()=>port>=20000?resolve(port):reject(new Error('QA 端口必须不小于20000')))})})
const [dbPort,httpPort,h5Port]=await Promise.all([freePort(),freePort(),freePort()])
if(new Set([dbPort,httpPort,h5Port]).size!==3)throw new Error('QA 端口冲突，请重试')
const run=randomBytes(10).toString('hex'),dir=path.join(root,'.claude/runtime/sc3-http-'+run),data=path.join(dir,'mysql')
fs.mkdirSync(data,{recursive:true});fs.writeFileSync(path.join(dir,'owner'),run)
const env=Object.fromEntries(Object.keys(process.env).filter(k=>['SYSTEMROOT','WINDIR','PATH','TEMP','TMP','COMSPEC','PATHEXT'].includes(k.toUpperCase())).map(k=>[k,process.env[k]]))
Object.assign(env,{APP_ENV:'testing',APP_DEBUG:'false',DEBUGBAR_ENABLED:'false',APP_KEY:'base64:'+randomBytes(32).toString('base64'),APP_URL:'http://127.0.0.1:'+httpPort,APP_CURRENCY:'CNY',DB_CONNECTION:'mysql',DB_HOST:'127.0.0.1',DB_PORT:String(dbPort),DB_DATABASE:'summon_mecha_scenes_test',DB_USERNAME:'root',DB_PASSWORD:'',CACHE_STORE:'file',SESSION_DRIVER:'file',SESSION_SECURE_COOKIE:'false',MAIL_MAILER:'array',QUEUE_CONNECTION:'database',BROADCAST_CONNECTION:'log',LOG_CHANNEL:'single',COLUMNS:'120',LINES:'40',TERM:'dumb',NO_COLOR:'1',SCENE_SC3_INTEGRATION_RUN:run,SCENE_SC3_INTEGRATION_DIR:dir,SCENE_H5_BUILD:h5,SCENE_H5_PORT:String(h5Port),LARAVEL_STORAGE_PATH:path.join(dir,'storage'),VIEW_COMPILED_PATH:path.join(dir,'storage/framework/views')})
for(const [key,name]of Object.entries({APP_CONFIG_CACHE:'config',APP_SERVICES_CACHE:'services',APP_PACKAGES_CACHE:'packages',APP_ROUTES_CACHE:'routes',APP_EVENTS_CACHE:'events'}))env[key]=path.join(dir,'storage/cache/'+name+'.php')
const flags=['publication_v2','requests_enabled','quotes_enabled','reservations_enabled','project_actions_enabled','operations_enabled','order_conversion_enabled']
fs.writeFileSync(path.join(dir,'flags.json'),JSON.stringify(Object.fromEntries(flags.map(k=>[k,true]))))
for(const flag of flags)env['SCENE_'+flag.toUpperCase()]='true'
if(process.env.SCENE_QA_DIR)env.SCENE_QA_DIR=process.env.SCENE_QA_DIR
env.SCENE_SC3_PHP_ARGS=JSON.stringify(phpArgs)
const stages=[],children=new Set()
function start(file,args,log){const fd=fs.openSync(path.join(dir,log),'w'),p=spawn(file,args,{cwd:root,env,windowsHide:true,stdio:['ignore',fd,fd]});children.add(p);p.on('error',()=>{});p.on('close',()=>{children.delete(p);fs.closeSync(fd)});return p}
const wait=p=>new Promise(resolve=>{if(p.exitCode!==null)return resolve(p.exitCode);p.once('error',()=>resolve(-1));p.once('close',resolve)})
async function execute(name,file,args,timeout=300000){console.log('执行',name);const p=start(file,args,name+'.log'),timer=setTimeout(()=>p.kill(),timeout);const code=await wait(p);clearTimeout(timer);stages.push({name,exit_code:code});if(code!==0)throw new Error(name+' 失败，详见 '+path.join(dir,name+'.log'));return fs.readFileSync(path.join(dir,name+'.log'),'utf8')}
const phpRun=(name,file,...args)=>execute(name,php,[...phpArgs,path.join(tools,file),...args])
async function stop(p){if(!p||p.exitCode!==null)return;p.kill();await Promise.race([wait(p),new Promise(resolve=>setTimeout(resolve,5000))])}
const read=name=>JSON.parse(fs.readFileSync(path.join(dir,name),'utf8'))
let databaseCreated=false,mysqlProcess,httpProcess,cleanup,error
console.log('隔离运行目录',dir)
try{
  await execute('mysql-initialize',mysql,['--no-defaults','--initialize-insecure','--datadir='+data])
  mysqlProcess=start(mysql,['--no-defaults','--datadir='+data,'--port='+dbPort,'--bind-address=127.0.0.1','--skip-log-bin','--console'],'mysql-server.log')
  const deadline=Date.now()+45000
  while(true){try{await new Promise((resolve,reject)=>{const s=net.connect({host:'127.0.0.1',port:dbPort});s.once('connect',()=>{s.destroy();resolve()});s.once('error',reject)});break}catch(e){if(Date.now()>deadline||mysqlProcess.exitCode!==null)throw e;await new Promise(r=>setTimeout(r,200))}}
  await phpRun('database-create','database.php','create');databaseCreated=true
  await phpRun('migrate','console.php','migrate');const migrations=read('migrations.json');if(migrations.pending.length||migrations.executed!==migrations.registered)throw new Error('迁移结果不完整')
  await phpRun('seed','seed.php');if(!read('fixtures.json').seed_ok)throw new Error('完整 EAV 夹具未生成')
  const preflight=path.join(root,'my-bagisto-store/qa/scenes/preflight.php')
  await execute('preflight',php,[...phpArgs,preflight,'--environment-path='+dir]);const preflightReport=JSON.parse(fs.readFileSync(path.join(dir,'preflight.log'),'utf8'));if(!preflightReport.ok||!preflightReport.database_identity_verified)throw new Error('正向预检未通过')
  httpProcess=start(php,[...serverArgs,'-S','127.0.0.1:'+httpPort,'-t',path.join(root,'my-bagisto-store/public'),path.join(tools,'router.php')],'http-server.log')
  const until=Date.now()+15000;while(true){try{const s=net.connect({host:'127.0.0.1',port:httpPort});await new Promise((resolve,reject)=>{s.once('connect',resolve);s.once('error',reject)});s.destroy();break}catch(e){if(Date.now()>until)throw e;await new Promise(r=>setTimeout(r,100))}}
  await execute('http',process.execPath,[path.join(tools,'http.mjs')]);if(read('http-checks.json').checks.some(c=>!c.pass))throw new Error('HTTP 用例失败')
  await phpRun('recovery','recovery.php');if(read('worker-checks.json').checks.some(c=>!c.pass))throw new Error('Worker 用例失败')
  await phpRun('runtime','runtime-checks.php');if(read('runtime-checks.json').checks.some(c=>!c.pass))throw new Error('完整环境事实检查失败')
  await execute('h5',process.execPath,[path.join(tools,'h5.mjs')]);if(read('h5-checks.json').pageErrors.length)throw new Error('H5 出现页面错误')
}catch(e){error=e;console.error(e.message)}finally{
  await stop(httpProcess)
  if(databaseCreated){try{const output=await phpRun('cleanup','database.php','cleanup');cleanup=JSON.parse(output)}catch(e){error??=e;console.error('清理被守卫拒绝，请保留运行目录核查：'+e.message)}}
  await stop(mysqlProcess)
  for(const p of children)await stop(p)
}
const report={generated_at:new Date().toISOString(),runtime:dir,environment:'Complete Laravel/Bagisto providers + real EAV, sessions, CSRF, Sanctum, actual HTTP and MySQL',ok:!error,stages,cleanup,business_database_touched:false,production_flags_changed:false,external_notification_or_device_calls:false}
if(!error)Object.assign(report,{migrations:read('migrations.json').executed,http_checks:read('http-checks.json').checks.length,worker_checks:read('worker-checks.json').checks.length,runtime_checks:read('runtime-checks.json').checks.length,h5_checks:read('h5-checks.json').checks.length,preflight_ok:true})
fs.writeFileSync(path.join(dir,'integration-verification.json'),JSON.stringify(report,null,2)+'\n')
if(options['--evidence-dir']){
  const dest=path.resolve(options['--evidence-dir']);fs.mkdirSync(dest,{recursive:true})
  // 不复制 fixtures.json、进程环境、Cookie、密码、token 或 APP_KEY。
  for(const name of ['integration-verification.json','migrations.json','http-checks.json','worker-checks.json','runtime-checks.json','h5-checks.json','h5-confirmation.png','h5-project.png'])if(fs.existsSync(path.join(dir,name)))fs.copyFileSync(path.join(dir,name),path.join(dest,name))
  if(fs.existsSync(path.join(dir,'preflight.log')))fs.copyFileSync(path.join(dir,'preflight.log'),path.join(dest,'full-preflight.json'))
}
console.log(JSON.stringify(report,null,2));process.exitCode=error?1:0
