"""Exact historical PRICE handoff boundary; GET-only, never a native/write permit."""
import hashlib,json,pathlib,zipfile
DESCRIPTOR_SHA256='89993e4284990a801768243a6bb35cb40c3d7c9d478f1ceaa61aa7cf73a4ccee'
KIND='ORIGINAL_PRICE4294_RECOVERY_AND_HOOK_READBACK'
H=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def safe(root,name):
 p=pathlib.PurePosixPath(name);need(not p.is_absolute() and '..' not in p.parts,'unsafe_evidence_path')
 q=root/name;need(q.is_file() and not q.is_symlink(),'missing_or_symlink_evidence');return q

def validate_terminal(kind,t,d):
 x=next(x for x in d['runs'] if x['kind']==kind)
 need(t.get('ok') is True and t.get('timed_out') is False and t.get('returncode')==0,'terminal_not_success')
 need(t.get('run_id')==str(x['run_id']) and t.get('head')==x['head'],'terminal_identity')
 need(t.get('feed_shas')==d['feed_shas'],'terminal_feeds_changed')
 need(t.get('stderr_sha256')==H(b''),'terminal_stderr')
 if kind=='recovery':
  need(t.get('phase')=='completed' and t.get('global_pin')==d['global_pin'] and t.get('child_pin')==d['child_pin'],'recovery_not_completed')
  for k in ['provider_calls','provider_writes','new_content_edits']:need(type(t.get(k)) is int and t[k]==0,'unexpected_provider_write')
  need(t.get('raw_sha256')=='32c90d8cc9832c8327ffa9b43c95f04e208b09d7edc9b1889aa25b0ed07d27e7','original_recovery_raw_changed')
  need(all(t.get(k) is True for k in ['child_append_attempted','database_confirmation_attempted','feed_finish_attempted']),'recovery_steps_incomplete')
 else:
  need(t.get('phase')=='installed' and t.get('mode')=='install' and t.get('verification_complete') is True and t.get('inspect_new_ok') is True,'separate_hook_readback_missing')
  need(type(t.get('code_replacements')) is int and t['code_replacements']==1,'hook_count')
  for k in ['post_writes','feed_writes','journal_writes','consumer_code_writes','collector_runs','broad_passes']:need(type(t.get(k)) is int and t[k]==0,'unexpected_hook_side_effect')
  need(t.get('hook_sha256')==d['hook_sha256'] and t.get('journal_pin')==d['global_pin'],'hook_or_journal_changed')
  need(t.get('raw_sha256')=='72373ad8499655e3192fc5c30855c349626c5e4aaf050d5ec3c7517a9faf491e' and t.get('inspect_new_raw_sha256')=='c1200a7034a2bdbcac411165abfb75014a7f31d227f4aa27b3fadb02e6509142','hook_original_raw_changed')

def validate_handoff(c,root,api):
 root=pathlib.Path(root);a=c.get('price_terminal_handoff')
 need(type(a) is dict and a.get('kind')==KIND and a.get('descriptor_sha256')==DESCRIPTOR_SHA256,'price_handoff_config_unbound')
 rel=a.get('descriptor_path');need(type(rel) is str,'descriptor_path_missing');f=safe(root,rel);base=f.parent
 raw=f.read_bytes();need(H(raw)==DESCRIPTOR_SHA256,'price_descriptor_changed');d=json.loads(raw)
 need(c.get('runtime_sha')==d['runtime'],'price_runtime_mismatch')
 need(d['pending_at_verified_terminal'] is None and d['fresh_native_admission_required'] is True and d['native_execution_allowed'] is False,'historical_boundary_changed')
 for n,h in d['files'].items():need(H(safe(base,n).read_bytes())==h,'price_evidence_changed:'+n)
 for x in d['runs']:
  kind=x['kind'];b=safe(base,kind+'-original.zip').read_bytes();need(H(b)==x['zip_sha256'],'original_zip_changed')
  with zipfile.ZipFile(safe(base,kind+'-original.zip')) as z:
   need(z.namelist()==['terminal.json'] and z.getinfo('terminal.json').file_size<100000,'unexpected_artifact_members')
   t=z.read('terminal.json');need(H(t)==x['terminal_sha256'] and t==safe(base,kind+'-terminal.json').read_bytes(),'artifact_terminal_changed');validate_terminal(kind,json.loads(t),d)
  r=api(d['repository'],'actions/runs/'+str(x['run_id']))
  need(r.get('id')==x['run_id'] and r.get('head_sha')==x['head'] and r.get('run_attempt')==1 and r.get('status')=='completed' and r.get('conclusion')=='success' and r.get('event')=='push' and r.get('path')==x['path'] and r.get('head_branch')==x['head_branch'],'original_run_not_success')
  jobs=api(d['repository'],f"actions/runs/{x['run_id']}/jobs?per_page=100")
  need(type(jobs.get('jobs')) is list and jobs.get('total_count')==len(jobs['jobs']) and len(jobs['jobs'])<=100,'ambiguous_original_jobs')
  found=[j for j in jobs['jobs'] if j.get('id')==x['job_id']];need(len(found)==1 and found[0].get('run_id')==x['run_id'] and found[0].get('status')=='completed' and found[0].get('conclusion')=='success','original_job_not_success')
  arts=api(d['repository'],f"actions/runs/{x['run_id']}/artifacts?per_page=100")
  need(type(arts.get('artifacts')) is list and arts.get('total_count')==len(arts['artifacts']) and len(arts['artifacts'])<=100,'ambiguous_original_artifacts')
  found=[v for v in arts['artifacts'] if v.get('id')==x['artifact_id']];need(len(found)==1,'artifact_missing');v=found[0]
  need(v.get('name')==x['artifact_name'] and v.get('digest')=='sha256:'+x['zip_sha256'] and v.get('workflow_run',{}).get('id')==x['run_id'] and v['workflow_run'].get('head_sha')==x['head'],'artifact_binding_changed')
 h=d['handoff'];v=api(d['repository'],'issues/comments/'+str(h['id']))
 need(v.get('id')==h['id'] and v.get('user',{}).get('id')==h['author_id'] and H(v.get('body','').encode())==h['body_sha256'],'exclusive_price_handoff_changed')
 need(api(d['repository'],'git/ref/heads/audit/price4294-evidence-20261118')['object']['sha']==d['runs'][1]['head'],'price_carrier_moved')
 public=json.loads(safe(base,'public-readback.json').read_bytes())
 for n,count in [('telegram',1000),('max',1000),('all',2000)]:need(public[n]['sha256']==d['feed_shas'][n] and public[n]['count']==count,'public_baseline_mismatch')
 return {'kind':KIND,'historical_originals_verified':True,'pending_at_verified_terminal':None,'feed_pairs':1000,'hook_sha256':d['hook_sha256'],'journal_pin':d['global_pin'],'fresh_native_admission_required':True,'native_execution_allowed':False}
validate=validate_handoff
