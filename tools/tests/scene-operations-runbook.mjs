import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import {spawn, spawnSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';

// 复用完整应用隔离入口。只新建本次 MySQL 实例，不接受业务连接或已有 datadir。
const options = {};
for (let i=2;i<process.argv.length;i+=2) {
  if (!['--mysql-bin','--evidence-dir'].includes(process.argv[i]) || !process.argv[i+1] || options[process.argv[i]]) throw new Error('参数无效');
  options[process.argv[i]]=process.argv[i+1];
}
const root=process.cwd(), tools=path.join(root,'tools/tests/scene-sc3-integration'), mysql=path.resolve(options['--mysql-bin']||'');
if (!fs.existsSync(mysql)||!fs.statSync(mysql).isFile()) throw new Error('须显式提供 --mysql-bin <mysqld绝对路径>');
const probe=spawnSync('php',['-r','echo json_encode(["binary"=>PHP_BINARY,"extensions"=>get_loaded_extensions()]);'],{encoding:'utf8',windowsHide:true});
if(probe.status!==0)throw new Error('PHP 不可用');
const info=JSON.parse(probe.stdout),args=['-d','max_execution_time=300'];
for(const extension of ['pdo_mysql','intl'])if(!info.extensions.includes(extension))args.push('-d','extension='+extension);
const port=await new Promise((resolve,reject)=>{const server=net.createServer();server.on('error',reject);server.listen(0,'127.0.0.1',()=>{const p=server.address().port;server.close(()=>p>=20000?resolve(p):reject(new Error('QA 端口过低')));});});
const run=randomBytes(10).toString('hex'),dir=path.join(root,'.claude/runtime/sc3-http-'+run),data=path.join(dir,'mysql');
fs.mkdirSync(data,{recursive:true});fs.writeFileSync(path.join(dir,'owner'),run);
const env=Object.fromEntries(Object.keys(process.env).filter(k=>['SYSTEMROOT','WINDIR','PATH','TEMP','TMP','COMSPEC','PATHEXT'].includes(k.toUpperCase())).map(k=>[k,process.env[k]]));
Object.assign(env,{APP_ENV:'testing',APP_DEBUG:'false',DEBUGBAR_ENABLED:'false',APP_KEY:'base64:'+randomBytes(32).toString('base64'),APP_URL:'http://127.0.0.1:29999',APP_CURRENCY:'CNY',DB_CONNECTION:'mysql',DB_HOST:'127.0.0.1',DB_PORT:String(port),DB_DATABASE:'summon_mecha_scenes_test',DB_USERNAME:'root',DB_PASSWORD:'',CACHE_STORE:'file',SESSION_DRIVER:'file',MAIL_MAILER:'array',QUEUE_CONNECTION:'database',BROADCAST_CONNECTION:'log',LOG_CHANNEL:'single',COLUMNS:'120',LINES:'40',TERM:'dumb',NO_COLOR:'1',SCENE_SC3_INTEGRATION_RUN:run,SCENE_SC3_INTEGRATION_DIR:dir,LARAVEL_STORAGE_PATH:path.join(dir,'storage'),VIEW_COMPILED_PATH:path.join(dir,'storage/framework/views')});
for(const [key,name]of Object.entries({APP_CONFIG_CACHE:'config',APP_SERVICES_CACHE:'services',APP_PACKAGES_CACHE:'packages',APP_ROUTES_CACHE:'routes',APP_EVENTS_CACHE:'events'}))env[key]=path.join(dir,'storage/cache/'+name+'.php');
const flags=['publication_v2','requests_enabled','quotes_enabled','reservations_enabled','project_actions_enabled','operations_enabled','order_conversion_enabled'];
fs.writeFileSync(path.join(dir,'flags.json'),JSON.stringify(Object.fromEntries(flags.map(k=>[k,true]))));
for(const flag of flags)env['SCENE_'+flag.toUpperCase()]='true';
const children=new Set(), stages=[];
function start(file,a,name){const fd=fs.openSync(path.join(dir,name+'.log'),'w'),p=spawn(file,a,{cwd:root,env,windowsHide:true,stdio:['ignore',fd,fd]});children.add(p);p.on('error',()=>{});p.on('close',()=>{children.delete(p);fs.closeSync(fd)});return p;}
const wait=p=>new Promise(resolve=>{if(p.exitCode!==null)return resolve(p.exitCode);p.once('error',()=>resolve(-1));p.once('close',resolve)});
async function execute(name,file,a){console.log('执行',name);const p=start(file,a,name),timer=setTimeout(()=>p.kill(),300000),code=await wait(p);clearTimeout(timer);stages.push({name,exit_code:code});if(code!==0)throw new Error(name+' 失败：'+path.join(dir,name+'.log'));}
const php=(name,file,...rest)=>execute(name,info.binary,[...args,path.join(tools,file),...rest]);
let instance,created=false,error,cleanup;
console.log('隔离运行目录',dir);
try{
  await execute('mysql-initialize',mysql,['--no-defaults','--initialize-insecure','--datadir='+data]);
  instance=start(mysql,['--no-defaults','--datadir='+data,'--port='+port,'--bind-address=127.0.0.1','--skip-log-bin','--console'],'mysql-server');
  await php('database-create','database.php','create');created=true;
  await php('migrate','console.php','migrate');
  await php('seed','seed.php');
  await php('runbook','runbook-checks.php');
}catch(e){error=e;console.error(e.message);}finally{
  if(created){try{await php('cleanup','database.php','cleanup');cleanup=JSON.parse(fs.readFileSync(path.join(dir,'cleanup.log'),'utf8'));}catch(e){error??=e;console.error(e.message);}}
  for(const p of children){if(p.exitCode===null){p.kill();await Promise.race([wait(p),new Promise(resolve=>setTimeout(resolve,5000))]);}}
}
const report={generated_at:new Date().toISOString(),ok:!error,stages,cleanup,business_database_touched:false,production_flags_changed:false,external_calls:false};
if(!error)report.runbook=JSON.parse(fs.readFileSync(path.join(dir,'runbook-checks.json'),'utf8'));
fs.writeFileSync(path.join(dir,'runbook-verification.json'),JSON.stringify(report,null,2)+'\n');
if(options['--evidence-dir']){const dest=path.resolve(options['--evidence-dir']);fs.mkdirSync(dest,{recursive:true});for(const name of ['runbook-verification.json','runbook-checks.json','migrations.json'])if(fs.existsSync(path.join(dir,name)))fs.copyFileSync(path.join(dir,name),path.join(dest,name));}
console.log(JSON.stringify(report,null,2));process.exitCode=error?1:0;
