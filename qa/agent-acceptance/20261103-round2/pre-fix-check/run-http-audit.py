import json, os, pathlib, shutil, socket, subprocess, sys, tempfile, time, unittest
from urllib.parse import urlparse
root=pathlib.Path('D:/CodexDeploy/summon-mecha'); out=pathlib.Path(__file__).resolve().parent;php='D:/java/php/php.exe'
sys.path.insert(0,str(root/'agent'));tempfile.tempdir=str(out/'tmp')
from zhjj_agent.client import AgentClient
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.storage import Storage
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.package_manager import PackageManager
env=os.environ.copy();env.update(APP_ENV='testing',DB_DATABASE='summon_mecha_step10_test',APP_CONFIG_CACHE=str(out/'no-cache.php'),AGENT_KEYS_DIR='audit-478a036-'+str(int(time.time()))+'/keys',TEMP=str(out/'tmp'),TMP=str(out/'tmp'))
env['PATH']='D:/java/php;'+env.get('PATH','')
with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
env['AUDIT_BASE_URL']=f'http://127.0.0.1:{port}'
def helper(mode):
 r=subprocess.run([php,str(out/'http-fixtures.php'),mode],env=env,cwd=root/'my-bagisto-store',capture_output=True,text=True,encoding='utf-8',errors='replace')
 if r.returncode:raise RuntimeError('Fixture helper failed, exit '+str(r.returncode))
 return r.stdout
def lastjson(text):
 return json.loads([s for s in text.splitlines()if s.startswith('{')][-1])
setup=False;server=None;results={}
try:
 data=lastjson(helper('setup'));setup=True
 server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t','public'],env=env,cwd=root/'my-bagisto-store',stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
 client=AgentClient(env['AUDIT_BASE_URL'],data['did'],device_secret=data['secret'])
 for _ in range(40):
  ok,_=client.heartbeat('0.2.0')
  if ok:break
  time.sleep(.1)
 else:raise RuntimeError('Isolated HTTP server failed readiness')
 ok,first=client.fetch_commands();ok2,second=client.fetch_commands()
 results['delivered_response_lost_no_ack']={'first_command_count':len(first.get('data')or[]),'second_command_count_without_durable_ack':len(second.get('data')or[])}
 command=(first.get('data')or[data['command']])[0]
 url=command['package_url'];target=out/'authorized-download.skill'
 headers=client._build_auth_headers('GET',urlparse(url).path)
 sha=PackageManager.download_package(url,str(target),headers)
 results['authenticated_download_control']={'hash_matches':sha==command['signed']['package_sha256']}
 device=pathlib.Path(tempfile.mkdtemp(prefix='http-device-'));storage=Storage(str(device));adapter=MockAdapter(app_dir=str(device));manager=TaskManager(str(device),storage,client,adapter,data['public_key'])
 accepted=manager.process_command(command)
 task=storage.get_task(command['task_id'])
 results['real_http_install']={'accepted':accepted,'journal_status':task['status'] if task else None,'error_code':task.get('error_code')if task else None,'error_msg':task.get('error_msg')if task else None,'installed_count':len(storage.get_all_installed_skills())}
 # 原交付四个HTTP测试原样运行；额外用环境和前置实际库名断言隔离。
 os.environ.update(env)
 suite=unittest.defaultTestLoader.discover(str(root/'agent/tests'),pattern='test_live_http_integration.py')
 with (out/'python-original-live-http.txt').open('w',encoding='utf-8') as f:rr=unittest.TextTestRunner(stream=f,verbosity=2).run(suite)
 results['original_live_suite']={'run':rr.testsRun,'failures':len(rr.failures),'errors':len(rr.errors),'skips':len(rr.skipped)}
finally:
 if server:
  server.terminate()
  try:server.wait(timeout=4)
  except subprocess.TimeoutExpired:server.kill();server.wait()
 if setup:
  results['cleanup']=lastjson(helper('cleanup'))
 (out/'http-counterexamples.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(results,ensure_ascii=False,indent=2))
