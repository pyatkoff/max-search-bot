"""Accepted 67aa runtime evidence, distinct from historical PRICE and CONTENT ownership."""
import json,hashlib
SHA='5dbaa002a732feff8628d95ce27429f372c490dd0ae2b9a27ad583ead8c4c725'
RUNTIME='67aa3aef0debacfea01e845029b38a0db2f20b0d'
H=lambda b:hashlib.sha256(b).hexdigest()
def need(x,m):
 if not x:raise ValueError(m)
def validate(config,root,api):
 raw=(root/'runtime-acceptance67.json').read_bytes();need(H(raw)==SHA,'runtime_evidence_changed');d=json.loads(raw)
 need(config['runtime_sha']==d['runtime']==RUNTIME and config['accepted_runs']==d['runs'] and config['accepted_jobs']==d['jobs'],'runtime_scope')
 need(config['source_sha256'].get('runtime-acceptance67.json')==SHA,'runtime_source_pin')
 for p,h in d['evidence_sha256'].items():need(H((root/p).read_bytes())==h and config['source_sha256'].get(p)==h,'runtime_evidence_pin')
 for x in d['runs']:
  r=api('max-search-bot','actions/runs/'+str(x['run_id']));need(r['id']==x['run_id'] and r['head_sha']==RUNTIME and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','runtime_run_not_success')
  if x['kind']=='native':need(r['path']==x['workflow_path'] and r['display_title']=='deploy-proof:v1:44286564852:1','tail_parent_binding')
 for x in d['jobs']:
  j=api('max-search-bot','actions/jobs/'+str(x['job_id']));need(j['id']==x['job_id'] and j['run_id']==x['run_id'] and j['status']=='completed' and j['conclusion']=='success','runtime_job_not_success')
 return {'runtime_accepted':RUNTIME,'content_window_handoff':False,'native_reader174':None}
