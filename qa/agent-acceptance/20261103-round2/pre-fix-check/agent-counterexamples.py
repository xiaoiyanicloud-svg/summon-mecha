"""当前原样业务源码的隔离反例；只用临时文件、模拟外部设备，无真实动作。"""
import json, pathlib, sys, tempfile, time
from unittest.mock import Mock
root=pathlib.Path('D:/CodexDeploy/summon-mecha'); out=pathlib.Path(__file__).resolve().parent
sys.path.insert(0,str(root/'agent')); tempfile.tempdir=str(out/'tmp')
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.constants import *
from zhjj_agent.crypto import canonical_v2, sign_data, jcs_canonicalize
key=rsa.generate_private_key(public_exponent=65537,key_size=2048)
priv=key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()).decode()
pub=key.public_key().public_bytes(serialization.Encoding.PEM,serialization.PublicFormat.SubjectPublicKeyInfo).decode()
class Client:
 did='device-A'
 def __init__(self):self.events=[]
 def send_event(self,**kw):self.events.append(kw);return True,{'success':True}
 def send_ack(self,*a,**kw):return True,{}
 def fetch_commands(self):return True,{'data':[]}
def setup():
 d=pathlib.Path(tempfile.mkdtemp(prefix='new-probe-'));s=Storage(str(d));a=MockAdapter(app_dir=str(d));c=Client();m=TaskManager(str(d),s,c,a,pub)
 p=d/'skills/101/v1.0.0';p.mkdir(parents=True);(p/'payload').write_text('known-good')
 a.install('101','1.0.0',str(p),{});s.save_installed_skill('101','1.0.0',None,'installed',True,str(p),package_sha256='a'*64)
 return d,s,a,c,m,p
def cmd(task,op,**extra):
 s={'protocol_version':PROTOCOL_V2,'signature_algorithm':'RSA-SHA256','key_id':'test','task_id':task,'command_seq':1,'did':'device-A','operation':op,'skill_id':'101','version':'1.0.0','task_expires_at':int(time.time())+60,'revocation_epoch':0,'issued_at':int(time.time())};s.update(extra)
 return {'protocol_version':PROTOCOL_V2,'task_id':task,'operation':op,'skill_id':'101','version':'1.0.0','signed':s,'signature':sign_data(canonical_v2(s),priv)}
results={}
d,s,a,c,m,p=setup(); x=cmd('t-param',OP_EXECUTE,execution_id='E',execution_params={});x['execution_params']={'speed':99,'sdk_method':'unsafe'}
accepted=m.process_command(x);results['unsigned_params_fallback']={'accepted':accepted,'adapter_params':a._executions.get('E',{}).get('params')}
d,s,a,c,m,p=setup();x=cmd('t-sparse',OP_ENABLE)
for field in ['task_expires_at','issued_at','command_seq','key_id','signature_algorithm']:x['signed'].pop(field,None)
x['signature']=sign_data(canonical_v2(x['signed']),priv)
results['missing_required_signed_fields']={'accepted':m.process_command(x)}
d,s,a,c,m,p=setup();s.record_task_durable('interrupted',1,c.did,OP_INSTALL,'101','2','2.0.0',{}, {},STATE_DOWNLOADING)
m.resume_recovery=Mock(wraps=m.resume_recovery);m.poll_once()
results['recovery_not_connected_to_poll']={'recovery_calls':m.resume_recovery.call_count,'journal_status':s.get_task('interrupted')['status']}
d,s,a,c,m,p=setup();m.process_command(cmd('run-before-restart',OP_EXECUTE,execution_id='E-restart'))
new_adapter=MockAdapter(app_dir=str(d));new_adapter.install('101','1.0.0',str(p),{});new_adapter.execute_skill=Mock(wraps=new_adapter.execute_skill)
new_m=TaskManager(str(d),Storage(str(d)),c,new_adapter,pub)
accepted=new_m.process_command(cmd('run-after-restart',OP_EXECUTE,execution_id='E-restart'))
results['same_execution_after_restart']={'first_journal':s.get_task('run-before-restart')['status'],'second_accepted':accepted,'new_adapter_start_calls':new_adapter.execute_skill.call_count}
class NeverStopped(MockAdapter):
 def cancel_execution(self,e):return True
 def observe_execution(self,e):return {'status':'RUNNING','is_stopped':False}
 def disable(self,k):return True
 def uninstall(self,k,p):return False
d,s,a,c,m,p=setup();na=NeverStopped();na.install('101','1.0.0',str(p),{});m=TaskManager(str(d),s,c,na,pub);s.acquire_execution_lease('still-running','101','motion_control',60)
accepted=m.process_command(cmd('reclaim-unconfirmed',OP_RECLAIM,revocation_epoch=5))
results['reclaim_before_stop_confirmation']={'accepted':accepted,'observed':na.observe_execution('still-running'),'lease':s.get_execution_lease('still-running')['status'],'payload_directory_exists':p.exists(),'local_status':s.get_installed_skill('101')['status'],'last_event':c.events[-1]}
d,s,a,c,m,p=setup();p2=d/'skills/101/v2.0.0';p2.mkdir();(p/'payload').write_text('tampered-not-good')
a.install('101','2.0.0',str(p2),{});s.save_installed_skill('101','2.0.0','1.0.0','updated',True,str(p2))
accepted=m.process_command(cmd('rollback-corrupt',OP_ROLLBACK,restore_version='1.0.0',restore_asset_id='approved-v1',restore_sha256='b'*64,task_expires_at=int(time.time())-3600))
results['expired_rollback_to_wrong_bytes']={'accepted':accepted,'actual_version':s.get_installed_skill('101')['current_version'],'actual_payload':(p/'payload').read_text(),'signed_sha':'b'*64}
d,s,a,c,m,p=setup();g=UnitreeG1Adapter(app_dir=str(d));g._sdk_available=True
(p/'action.py').write_text('def run(context=None):\n    return {"status":"FAILED","stopped":False}\n')
g.install('101','1.0.0',str(p),{'entrypoint':'action.py'});g.execute_skill('returned-failed','101',None,{})
g._active_threads['returned-failed'].join(timeout=2)
results['g1_ignores_entrypoint_failure_result']=g.observe_execution('returned-failed')
d,s,a,c,m,p=setup();g=UnitreeG1Adapter(app_dir=str(d));g._sdk_available=True
outside=p.parent/'outside.py';marker=d/'escaped-entry-ran'
outside.write_text('from pathlib import Path\ndef run(context=None):\n    Path('+repr(str(marker))+').write_text("ran")\n')
manifest={'entrypoint':'../outside.py'};prepared=g.prepare('101','1.0.0',str(p),manifest);g.install('101','1.0.0',str(p),manifest);g.execute_skill('escape','101',None,{})
g._active_threads['escape'].join(timeout=2)
results['entrypoint_escapes_package']={'prepare_accepted':prepared,'outside_entry_executed':marker.exists()}
results['jcs_examples']={str(v):jcs_canonicalize({'x':v}).decode() for v in [1e-6,1e20,333333333.33333329]}
(out/'agent-counterexamples.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(results,ensure_ascii=False,indent=2))
