"""Fresh GET-only one-shot admission; exact reviewed configuration is mandatory."""
import datetime,hashlib,json,os,pathlib,re,subprocess,sys
REVIEWED_CONFIG_SHA256='e31fc317b12ea4ce5d2ad8c15bdf34f120004576d10d6f076c463349c0308bfd'
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def api(repo,path):return json.loads(subprocess.check_output(['gh','api','--method','GET','repos/pyatkoff/'+repo+'/'+path],timeout=40))
def validate_route_attestation(c,now):
 a=c.get('external_route_coordination',{})
 need(a.get('kind')=='root_connector_read_only_coordination' and a.get('repository')=='pyatkoff/tour-max' and a.get('branch')=='audit/anytour-hotel-feed-routing-20261104','external_route_attestation_missing')
 need(a.get('claim_id')==c['claim_id'] and a.get('claim_body_sha256')==c['claim_body_sha256'],'external_route_claim_changed')
 need(re.fullmatch('[a-f0-9]{40}',a.get('route_head_sha','')) is not None,'external_route_head_missing')
 need(a.get('nonterminal_counts')=={s:0 for s in ['in_progress','queued','waiting','pending','requested']},'external_route_not_idle')
 try:observed=datetime.datetime.fromisoformat(a['observed_at'].replace('Z','+00:00'))
 except (KeyError,ValueError,TypeError):raise ValueError('external_route_timestamp_invalid')
 need(observed.tzinfo is not None,'external_route_timestamp_timezone_missing')
 age=(now-observed).total_seconds();need(0<=age<=600,'external_route_attestation_expired_or_future')
 return a
def load_config(root):
 need(type(REVIEWED_CONFIG_SHA256) is str and re.fullmatch('[a-f0-9]{64}',REVIEWED_CONFIG_SHA256),'CLOSED_NEXT123_CONFIG')
 raw=(root/'admission-config.json').read_bytes();need(sha(raw)==REVIEWED_CONFIG_SHA256,'config_changed');c=json.loads(raw)
 need(type(c['claim_id']) is int and c['claim_id']==6967624483,'next123_owner_claim')
 need('admit123.py' not in c['source_sha256'],'circular_admit_pin')
 need('price_readback_handoff.py' in c['source_sha256'],'price_validator_source_pin_missing')
 need(sha((root/'price_readback_handoff.py').read_bytes())==c['source_sha256']['price_readback_handoff.py'],'price_validator_changed')
 from price_readback_handoff import validate_handoff
 validate_handoff(c,root,api)
 need('content_handoff.py' in c['source_sha256'],'content_validator_sourcepin_missing')
 need(sha((root/'content_handoff.py').read_bytes())==c['source_sha256']['content_handoff.py'],'content_validator_changed')
 from content_handoff import validate
 validate(c,root,api)
 return c
def main():
 root=pathlib.Path(sys.argv[1]);output=pathlib.Path(sys.argv[2]);c=load_config(root);raw=(root/'intent.json').read_bytes();intent=json.loads(raw)
 need(c['schema_version']==1 and c['runtime_sha']==intent['expected_installed_sha'],'config_runtime')
 need(re.fullmatch('[a-f0-9]{40}',c['parent_sha']) and re.fullmatch('[a-f0-9]{64}',c['claim_body_sha256']),'unfilled_review_config')
 need(os.environ['GITHUB_REPOSITORY']=='pyatkoff/max-search-bot' and os.environ['GITHUB_ACTOR']=='pyatkoff' and os.environ['GITHUB_EVENT_NAME']=='push' and os.environ['GITHUB_RUN_ATTEMPT']=='1','event_scope')
 event=json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_bytes());need(event['before']==c['parent_sha'],'exact_parent_changed')
 head=os.environ['GITHUB_SHA'];need(subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip()==c['parent_sha'],'checkout_parent')
 carrier='audit/price660-27316-resume-readiness-20261117';need(api('max-search-bot','git/ref/heads/'+carrier)['object']['sha']==head,'carrier_moved')
 need(api('max-search-bot','git/ref/heads/main')['object']['sha']==c['runtime_sha'],'accepted_main_moved')
 claim=api('max-search-bot','issues/comments/'+str(c['claim_id']));need(claim['user']['id']==226193297 and sha(claim['body'].encode())==c['claim_body_sha256'],'owner_claim_changed')
 prior=c['prior_completed_feed'];need(prior=={'run_id':44224540179,'head_sha':'1a055d8d5cd94fe0a57a2ee03e41905beff274f9'},'prior877_identity')
 r=api('max-search-bot','actions/runs/'+str(prior['run_id']));need(r['head_sha']==prior['head_sha'] and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','prior877_not_complete')
 need(len(c['accepted_runs'])>=2 and any(x['kind']=='deployment' for x in c['accepted_runs']) and any(x['kind']=='native' for x in c['accepted_runs']),'source_acceptance_missing')
 for expected in c['accepted_runs']:
  r=api('max-search-bot','actions/runs/'+str(expected['run_id']));need(r['head_sha']==expected['head_sha']==c['runtime_sha'] and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','source_run_not_accepted')
 for expected in c['accepted_jobs']:
  j=api('max-search-bot','actions/jobs/'+str(expected['job_id']));need(j['run_id']==expected['run_id'] and j['status']=='completed' and j['conclusion']=='success','source_job_not_accepted')
 need(sha(raw)==c['intent_sha256'],'intent_changed')
 for name,digest in c['source_sha256'].items():
  need(re.fullmatch(r'[a-zA-Z0-9_./-]+',name) and '..' not in pathlib.PurePath(name).parts and not name.startswith('/'),'source_path')
  need(sha((root/name).read_bytes())==digest,'source_changed:'+name)
 need({'run123.py','namespace-provenance.json','intent.json'}<=set(c['source_sha256']),'runner_guard_missing')
 for g in intent['groups']:need(sha((root/('p%02d'%g['batch'])/'read.php').read_bytes())==g['reader_sha256'],'reader_changed')
 route_attestation=validate_route_attestation(c,datetime.datetime.now(datetime.timezone.utc))
 checks=[]
 for status in ['in_progress','queued','waiting','pending','requested']:
  for repo,path,self_allowed in [('max-search-bot','actions/runs?branch=audit%2Fprice660-27316-resume-readiness-20261117',True),('max-search-bot','actions/runs?branch=audit%2Fcontent100-bot-publication-20261101',False),('max-search-bot','actions/runs?head_sha='+c['runtime_sha'],False),('poisk-turov-test','actions/workflows/346454799/runs?',False)]:
   sep='' if path.endswith('?') else '&';d=api(repo,path+sep+'status='+status+'&per_page=100')
   need(len(d['workflow_runs'])==d['total_count'] and d['total_count']<=100,'ambiguous_active_list')
   other=[r for r in d['workflow_runs'] if not(self_allowed and str(r['id'])==os.environ['GITHUB_RUN_ID'] and r['head_sha']==head)]
   need(not other,'competing_current_run:'+repo+':'+status);checks.append({'repository':repo,'path':path,'status':status,'other_count':0})
 validate_route_attestation(c,datetime.datetime.now(datetime.timezone.utc))
 output.write_text(json.dumps({'state':'FRESH_READ_ONLY_ADMISSION','intent_sha256':sha(raw),'expected_installed_sha':c['runtime_sha'],'source_acceptance_verified':True,'owner_and_concurrency_verified':True,'observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'checks':checks,'external_route_coordination':route_attestation})+'\n')
if __name__=='__main__':main()
