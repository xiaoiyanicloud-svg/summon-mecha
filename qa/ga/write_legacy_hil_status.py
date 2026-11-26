import hashlib,json,pathlib
root=pathlib.Path(r'D:\main')
report=root/'qa'/'hil-verification'/'hil_safety_report.json'
source=root/'qa'/'hil-verification'/'verify_hil_safety_interlock.py'
def h(p): return hashlib.sha256(p.read_bytes()).hexdigest()
out={'evidence_status':'INVALID_NON_HIL','report_path':str(report.relative_to(root)).replace('\\','/'),'report_sha256':h(report),'source_path':str(source.relative_to(root)).replace('\\','/'),'source_sha256':h(source),'reason':'Implementation is MockHardwareSafetyInterlock with hard-coded 12.5ms; no independent sensor log, hardware serial, firmware, operator, or replayable physical observation.','must_not_be_used_for':['HIL','L1','Production Ready','five-model GA'],'superseding_audit':'qa/ga/claim_audit_result.json'}
p=root/'qa'/'hil-verification'/'EVIDENCE_STATUS.json'; p.write_text(json.dumps(out,ensure_ascii=False,indent=2)+'\n',encoding='utf-8'); print(json.dumps(out,ensure_ascii=False,indent=2))