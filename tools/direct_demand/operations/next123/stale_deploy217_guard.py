"""Narrow proof for ONE never-started stale queued deploy, not a general exemption."""
import base64,hashlib
RUN=43095967891
HEAD='af6117fe8accc08424ad206e738853ebf366fd06'
PINS={'.github/workflows/deploy.yml':'055a2177916fdf3e0b2ca306b53644c3b6ed482d940f48c53b10cdebcf099bdc','tools/verify_deploy_main_provenance.sh':'6173be6ba86d2c28ee9a0cf594c53ef02694ddf30d88820bb199d27356cab7bb'}
def allowed(run,current_main,api):
 if not (run.get('id')==RUN and run.get('head_sha')==HEAD and run.get('workflow_id')==340734782 and run.get('path')=='.github/workflows/deploy.yml' and run.get('head_branch')=='main' and run.get('event')=='push' and run.get('status')=='queued' and run.get('run_attempt')==1 and run.get('conclusion') is None and current_main!=HEAD):return False
 fresh=api('max-search-bot','actions/runs/'+str(RUN))
 if any(fresh.get(k)!=run.get(k) for k in ['id','head_sha','workflow_id','path','head_branch','event','status','run_attempt','conclusion']):return False
 jobs=api('max-search-bot','actions/runs/'+str(RUN)+'/attempts/1/jobs?per_page=100')
 if jobs.get('total_count')!=0 or jobs.get('jobs')!=[]:return False
 if api('max-search-bot','git/ref/heads/main')['object']['sha']!=current_main:return False
 for path,digest in PINS.items():
  c=api('max-search-bot','contents/'+path+'?ref='+HEAD)
  if c.get('encoding')!='base64' or hashlib.sha256(base64.b64decode(c['content'])).hexdigest()!=digest:return False
 return True
