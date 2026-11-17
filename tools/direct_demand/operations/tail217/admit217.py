"""Fresh GET-only one-shot admission; exact reviewed configuration is mandatory."""
import datetime,hashlib,json,os,pathlib,re,subprocess,sys
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def api(repo,path):return json.loads(subprocess.check_output(['gh','api','--method','GET','repos/pyatkoff/'+repo+'/'+path],timeout=40))
def main():
 root=pathlib.Path(sys.argv[1]);output=pathlib.Path(sys.argv[2]);c=json.loads((root/'admission-config.json').read_bytes());raw=(root/'intent.json').read_bytes();intent=json.loads(raw)
 need(c['schema_version']==1 and c['runtime_sha']==intent['expected_installed_sha'],'config_runtime')
 need(re.fullmatch('[a-f0-9]{40}',c['parent_sha']) and re.fullmatch('[a-f0-9]{64}',c['claim_body_sha256']),'unfilled_review_config')
 need(os.environ['GITHUB_REPOSITORY']=='pyatkoff/max-search-bot' and os.environ['GITHUB_ACTOR']=='pyatkoff' and os.environ['GITHUB_EVENT_NAME']=='push' and os.environ['GITHUB_RUN_ATTEMPT']=='1','event_scope')
 event=json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_bytes());need(event['before']==c['parent_sha'],'exact_parent_changed')
 head=os.environ['GITHUB_SHA'];need(subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip()==c['parent_sha'],'checkout_parent')
 carrier='audit/price660-27316-resume-readiness-20261117';need(api('max-search-bot','git/ref/heads/'+carrier)['object']['sha']==head,'carrier_moved')
 need(api('max-search-bot','git/ref/heads/main')['object']['sha']==c['runtime_sha'],'accepted_main_moved')
 claim=api('max-search-bot','issues/comments/'+str(c['claim_id']));need(claim['user']['id']==226193297 and sha(claim['body'].encode())==c['claim_body_sha256'],'owner_claim_changed')
 takeover=api('max-search-bot','issues/comments/'+str(c['takeover_claim_id']));need(takeover['user']['id']==226193297 and sha(takeover['body'].encode())==c['takeover_claim_body_sha256'],'takeover_claim_changed')
 need(len(c['accepted_runs'])>=2 and any(x['kind']=='deployment' for x in c['accepted_runs']) and any(x['kind']=='native' for x in c['accepted_runs']),'source_acceptance_missing')
 for expected in c['accepted_runs']:
  r=api('max-search-bot','actions/runs/'+str(expected['run_id']));need(r['head_sha']==expected['head_sha']==c['runtime_sha'] and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','source_run_not_accepted')
 for expected in c['accepted_jobs']:
  j=api('max-search-bot','actions/jobs/'+str(expected['job_id']));need(j['run_id']==expected['run_id'] and j['status']=='completed' and j['conclusion']=='success','source_job_not_accepted')
 need(sha(raw)==c['intent_sha256'],'intent_changed')
 for name,digest in c['source_sha256'].items():
  need(re.fullmatch(r'[a-zA-Z0-9_./-]+',name) and '..' not in pathlib.PurePath(name).parts and not name.startswith('/'),'source_path')
  need(sha((root/name).read_bytes())==digest,'source_changed:'+name)
 need({'run217.py','admit217.py'}<=set(c['source_sha256']),'runner_guard_missing')
 for g in intent['groups']:need(sha((root/('p%02d'%g['batch'])/'read.php').read_bytes())==g['reader_sha256'],'reader_changed')
 checks=[]
 for status in ['in_progress','queued','waiting','pending','requested']:
  for repo,path,self_allowed in [('max-search-bot','actions/runs?branch=audit%2Fprice660-27316-resume-readiness-20261117',True),('max-search-bot','actions/runs?branch=audit%2Fcontent100-bot-publication-20261101',False),('tour-max','actions/runs?branch=audit%2Fanytour-hotel-feed-routing-20261104',False),('max-search-bot','actions/runs?head_sha='+c['runtime_sha'],False),('poisk-turov-test','actions/workflows/346454799/runs?',False)]:
   sep='' if path.endswith('?') else '&';d=api(repo,path+sep+'status='+status+'&per_page=100')
   need(len(d['workflow_runs'])==d['total_count'] and d['total_count']<=100,'ambiguous_active_list')
   other=[r for r in d['workflow_runs'] if not(self_allowed and str(r['id'])==os.environ['GITHUB_RUN_ID'] and r['head_sha']==head)]
   need(not other,'competing_current_run:'+repo+':'+status);checks.append({'repository':repo,'path':path,'status':status,'other_count':0})
 output.write_text(json.dumps({'state':'FRESH_READ_ONLY_ADMISSION','intent_sha256':sha(raw),'expected_installed_sha':c['runtime_sha'],'source_acceptance_verified':True,'owner_and_concurrency_verified':True,'observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'checks':checks})+'\n')
if __name__=='__main__':main()
