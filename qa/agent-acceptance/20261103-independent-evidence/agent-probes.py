"""隔离诊断：只在副本补齐两个缺失类型导入，业务源码保持原样。无设备/外网。"""
import json, os, pathlib, shutil, sys, tempfile, time
from unittest.mock import patch, Mock
ROOT = pathlib.Path('D:/CodexDeploy/summon-mecha')
OUT = pathlib.Path(__file__).resolve().parent
COPY = OUT / 'diagnostic-agent'
shutil.copytree(ROOT/'agent', COPY, dirs_exist_ok=True)
fixes = [('zhjj_agent/executor.py', 'from typing import Any, Dict, Optional', 'from typing import Any, Dict, Optional, Tuple'),
         ('zhjj_agent/package_manager.py', 'from typing import Dict, List, Optional, Tuple', 'from typing import Any, Dict, List, Optional, Tuple')]
for rel, before, after in fixes:
    p = COPY/rel
    p.write_text(p.read_text(encoding='utf-8').replace(before, after), encoding='utf-8')
sys.path.insert(0, str(COPY))
tempfile.tempdir = str(OUT/'tmp')
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from zhjj_agent.crypto import canonical_v2, sign_data
from zhjj_agent.storage import Storage
from zhjj_agent.task_manager import TaskManager
from zhjj_agent.adapters.mock import MockAdapter
from zhjj_agent.adapters.unitree_g1 import UnitreeG1Adapter
from zhjj_agent.constants import *
key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
priv = key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()).decode()
pub = key.public_key().public_bytes(serialization.Encoding.PEM, serialization.PublicFormat.SubjectPublicKeyInfo).decode()
class Client:
    did = 'device-A'
    def __init__(self): self.events=[]; self.reject=False
    def send_event(self, **kw):
        self.events.append(kw)
        return (False, {'code':'TASK_TRANSITION_INVALID'}) if self.reject else (True, {'success':True})
    def send_ack(self, *a, **kw): return True, {}
def env():
    d=tempfile.mkdtemp(prefix='probe-'); s=Storage(d); a=MockAdapter(); c=Client()
    m=TaskManager(d,s,c,a,pub)
    path=pathlib.Path(d)/'skills/101/v1.0.0'; path.mkdir(parents=True)
    a.install('101','1.0.0',str(path),{})
    s.save_installed_skill('101','1.0.0',None,'installed',True,str(path),package_sha256='a'*64,manifest={'entrypoint':'action.py'})
    return m,s,a,c,path
def command(task, op, **extra):
    signed={'protocol_version':PROTOCOL_V2, 'task_id':task,'command_seq':1,'did':'device-A','operation':op,'skill_id':'101','version':'1.0.0','task_expires_at':int(time.time())+60,'revocation_epoch':0}
    signed.update(extra)
    return {'task_id':task,'operation':op,'skill_id':'101','version':'1.0.0','command_seq':1,'protocol_version':PROTOCOL_V2,'signed':signed,'signature':sign_data(canonical_v2(signed),priv)}
results={'diagnostic_only_import_fixes':[x[0] for x in fixes]}
m,s,a,c,path=env(); cmd=command('tamper-top',OP_DISABLE); cmd['operation']=OP_ENABLE
a.disable('101'); s.save_installed_skill('101','1.0.0',None,'disabled',False,str(path))
ok=m.process_command(cmd)
results['unsigned_operation_tamper']={'signed_operation':cmd['signed']['operation'],'executed_operation':cmd['operation'],'accepted':ok,'enabled_after':s.get_installed_skill('101')['enabled']}
m,s,a,c,path=env(); cmd=command('wrong-device',OP_DISABLE,did='device-B'); ok=m.process_command(cmd)
results['wrong_signed_device']={'client_did':c.did,'signed_did':cmd['signed']['did'],'accepted':ok,'enabled_after':s.get_installed_skill('101')['enabled']}
m,s,a,c,path=env(); a.disable('101'); s.save_installed_skill('101','1.0.0',None,'disabled',False,str(path))
cmd=command('expired-auth',OP_ENABLE,authorization_expires_at=int(time.time())-3600); ok=m.process_command(cmd)
results['expired_authorization']={'accepted':ok,'enabled_after':s.get_installed_skill('101')['enabled']}
m,s,a,c,path=env()
s.record_task_durable('interrupted-v2',1,c.did,OP_INSTALL,'101','2','2.0.0',{}, {},STATE_DOWNLOADING)
m.resume_recovery()
results['old_version_misreported_as_new_success']={'requested_version':'2.0.0','adapter_actual_version':a.observe('101')['version'],'journal_status':s.get_task('interrupted-v2')['status'],'events':c.events}
m,s,a,c,path=env(); c.reject=True; m.emit_event('late-done',PHASE_DONE)
results['rejected_fact_dropped']={'server_response':'TASK_TRANSITION_INVALID','unacked_after':len(s.get_unacked_events())}
m,s,a,c,path=env(); a.execute_skill('running','101',None,{})
cmd=command('disable-while-running',OP_DISABLE,revocation_epoch=5); m.process_command(cmd)
results['disable_not_cancel_or_persist_revocation']={'execution_state_record':a._executions['running'],'stored_epoch':s.get_revocation_epoch('skill:101'),'enabled_after':s.get_installed_skill('101')['enabled']}
m,s,a,c,path=env(); s.acquire_execution_lease('uncertain','101','motion_control',1)
with s._connection() as con: con.execute("UPDATE execution_leases SET expires_at = ? WHERE execution_id='uncertain'",(int(time.time())-1,))
results['expired_unknown_lease_reused']={'new_lease_accepted_without_stop_observation':s.acquire_execution_lease('next','101','motion_control',60)}
g1=UnitreeG1Adapter(); g1.install('101','1.0.0','nonexistent-path',{})
accepted=g1.execute_skill('g1-no-sdk','101',None,{})
with patch('zhjj_agent.adapters.unitree_g1.time.time',return_value=time.time()+1): obs=g1.observe_execution('g1-no-sdk')
results['g1_no_sdk_no_entrypoint_success']={'health':g1.health(),'accepted':accepted,'observation':obs}
m,s,a,c,path=env(); newadapter=MockAdapter()
results['restart_adapter_state_missing']={'sqlite_enabled':s.get_installed_skill('101')['enabled'],'fresh_adapter_observation':newadapter.observe('101')}
m,s,a,c,path=env(); path2=path.parent/'v2.0.0';path2.mkdir()
a.install('101','2.0.0',str(path2),{})
s.save_installed_skill('101','2.0.0','1.0.0','updated',True,str(path2))
cmd=command('rollback-upgrade',OP_ROLLBACK,restore_asset_id='good-v1',restore_sha256='a'*64);cmd['version']='2.0.0';cmd['signed']['version']='2.0.0';cmd['signature']=sign_data(canonical_v2(cmd['signed']),priv)
m.process_command(cmd)
results['rollback_with_previous_version']={'previous_good_files_exist':path.exists(),'actual_version':s.get_installed_skill('101')['current_version'],'enabled':s.get_installed_skill('101')['enabled'],'event':c.events[-1]}
m,s,a,c,path=env()
a.execute_skill=Mock(wraps=a.execute_skill)
for task_id in ('task-first','task-second'):
    cmd=command(task_id,OP_EXECUTE,execution_id='same-execution');cmd['execution_id']='same-execution'
    m.process_command(cmd)
results['same_execution_id_different_tasks']={'adapter_execute_calls':a.execute_skill.call_count,'completed_tasks':[s.get_task(t)['status'] for t in ('task-first','task-second')],'done_events':sum(1 for e in c.events if e['phase']=='done')}
(OUT/'agent-probes.json').write_text(json.dumps(results,ensure_ascii=False,indent=2),encoding='utf-8')
print(json.dumps(results,ensure_ascii=False,indent=2))
