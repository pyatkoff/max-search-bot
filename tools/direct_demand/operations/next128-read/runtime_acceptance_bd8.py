"""Accepted bd8e runtime evidence, distinct from historical PRICE and CONTENT ownership."""
import json,hashlib
SHA='b6d67392c565897f43a90da1b1560db0c301cc63fe3eb21f6b66e1c2cd60a556'
RUNTIME='bd8e248f7138ea75cb9fda2dcf44beb631c633a5'
H=lambda b:hashlib.sha256(b).hexdigest()
def need(x,m):
 if not x:raise ValueError(m)
def validate(config,root,api):
 raw=(root/'runtime-acceptance-bd8.json').read_bytes();need(H(raw)==SHA,'runtime_evidence_changed');d=json.loads(raw)
 need(config['runtime_sha']==d['runtime']==RUNTIME and config['accepted_runs']==d['runs'] and config['accepted_jobs']==d['jobs'],'runtime_scope')
 need(config['source_sha256'].get('runtime-acceptance-bd8.json')==SHA,'runtime_source_pin')
 for p,h in d['evidence_sha256'].items():need(H((root/p).read_bytes())==h and config['source_sha256'].get(p)==h,'runtime_evidence_pin')
 for x in d['runs']:
  r=api('max-search-bot','actions/runs/'+str(x['run_id']));need(r['id']==x['run_id'] and r['head_sha']==RUNTIME and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','runtime_run_not_success')
  if x['kind']=='native':need(r['path']==x['workflow_path'] and r['display_title']=='deploy-proof:v1:44329290180:1','tail_parent_binding')
 for x in d['jobs']:
  j=api('max-search-bot','actions/jobs/'+str(x['job_id']));need(j['id']==x['job_id'] and j['run_id']==x['run_id'] and j['status']=='completed' and j['conclusion']=='success','runtime_job_not_success')
 return {'runtime_accepted':RUNTIME,'content_window_handoff':False,'native_reader174':None}
