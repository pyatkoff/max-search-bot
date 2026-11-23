"""Accepted ec342 runtime evidence, distinct from historical PRICE and CONTENT ownership."""
import json,hashlib
SHA='6e55932146d8545976d25a533e7d652954049db4969dbfc86e993c341810d65b'
RUNTIME='ec3420fc19d9328b0c4ce497325442af6d21d647'
H=lambda b:hashlib.sha256(b).hexdigest()
def need(x,m):
 if not x:raise ValueError(m)
def validate(config,root,api):
 raw=(root/'runtime-acceptance.json').read_bytes();need(H(raw)==SHA,'runtime_evidence_changed');d=json.loads(raw)
 need(config['runtime_sha']==d['runtime']==RUNTIME and config['accepted_runs']==d['runs'] and config['accepted_jobs']==d['jobs'],'runtime_scope')
 need(config['source_sha256'].get('runtime-acceptance.json')==SHA,'runtime_source_pin')
 for p,h in d['evidence_sha256'].items():need(H((root/p).read_bytes())==h and config['source_sha256'].get(p)==h,'runtime_evidence_pin')
 for x in d['runs']:
  r=api('max-search-bot','actions/runs/'+str(x['run_id']));need(r['id']==x['run_id'] and r['head_sha']==RUNTIME and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','runtime_run_not_success')
  if x['kind']=='native':need(r['path']==x['workflow_path'] and r['display_title']=='deploy-proof:v1:44276144830:1','tail_parent_binding')
 for x in d['jobs']:
  j=api('max-search-bot','actions/jobs/'+str(x['job_id']));need(j['id']==x['job_id'] and j['run_id']==x['run_id'] and j['status']=='completed' and j['conclusion']=='success','runtime_job_not_success')
 return {'runtime_accepted':RUNTIME,'content_window_handoff':False,'native_reader174':None}
