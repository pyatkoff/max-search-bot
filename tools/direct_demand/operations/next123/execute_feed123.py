"""Reviewed same-run artifact materializer, read refresh, compiler and finite serial executor.
Closed until independently reviewed config literal is set. No retries or new publisher.
"""
import base64,datetime,hashlib,io,json,os,pathlib,re,subprocess,sys,zipfile
import compiler_bridge123 as compiler
import probe_feed123_remote as probe
import stale_deploy217_guard as stale_deploy
REVIEWED_CONFIG_SHA256='PIN_ONLY_AFTER_EXACT_ACCEPTED_READER_PACKET_AND_ROUTE_ARTIFACT_REVIEW'
ORDER=list(range(28,42))
BRANCH='audit/price660-27316-resume-readiness-20261117'
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def now():return datetime.datetime.now(datetime.timezone.utc).isoformat()
def save(p,x):p.write_bytes(json.dumps(x,ensure_ascii=False,separators=(',',':')).encode())
def pin(path,digest):
 raw=path.read_bytes();need(sha(raw)==digest,'pinned_file_changed');return raw
def api(repo,path,raw=False):
 p=subprocess.run(['gh','api','--method','GET','repos/'+repo+'/'+path],capture_output=True,timeout=90);need(p.returncode==0,'github_read_failed');return p.stdout if raw else json.loads(p.stdout)
def safe(root,name):
 p=root/name;need(not pathlib.Path(name).is_absolute() and root.resolve() in p.resolve().parents and not p.is_symlink(),'unsafe_artifact_path');return p

def origin(c):
 need(os.environ.get('GITHUB_REPOSITORY')=='pyatkoff/max-search-bot' and os.environ.get('GITHUB_ACTOR')=='pyatkoff' and os.environ.get('GITHUB_EVENT_NAME')=='push' and os.environ.get('GITHUB_RUN_ATTEMPT')=='1','one_shot_origin_required')
 event=json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_bytes());head=os.environ['GITHUB_SHA'];run=int(os.environ['GITHUB_RUN_ID']);need(run>0 and event['before']==c['parent_sha'],'event_parent_changed')
 need(subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()==head and subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip()==c['parent_sha'],'checkout_lineage_changed')
 need(api('pyatkoff/max-search-bot','git/ref/heads/'+BRANCH)['object']['sha']==head,'carrier_head_changed')
 actual=api('pyatkoff/max-search-bot','actions/runs/'+str(run));need(actual['head_sha']==head and actual['run_attempt']==1 and actual['event']=='push','authenticated_run_changed')
 claim=api('pyatkoff/max-search-bot','issues/comments/'+str(c['claim_id']));need(claim['user']['id']==226193297 and sha(claim['body'].encode())==c['claim_body_sha256'],'claim_changed')
 for name,h in c['source_sha256'].items():pin(safe(pathlib.Path.cwd(),name),h)
 required={'compiler_bridge123.py','probe_feed123_remote.py','run_feed123_probe.py','stale_deploy217_guard.py'};need(required<=set(pathlib.Path(x).name for x in c['source_sha256']),'source_pins_missing')
 pin(pathlib.Path(__file__).with_name('stale_deploy217_guard.py'),'5fe5944207a863e6d7bc7fb2ffd506777ab6b9e8521a2afc2043ffb87526c4d9')
 need(api('pyatkoff/max-search-bot','git/ref/heads/main')['object']['sha']==c['runtime_acceptance']['head_sha'],'runtime_main_changed')
 accepted_run('pyatkoff/max-search-bot',c['runtime_acceptance']['deploy_run_id'],c['runtime_acceptance']['head_sha'])
 return {'repository':'pyatkoff/max-search-bot','run_id':run,'run_attempt':1,'head_sha':head}
def accepted_run(repo,run,head):
 r=api(repo,'actions/runs/'+str(run));need(r['run_attempt']==1 and r['head_sha']==head and r['status']=='completed' and r['conclusion']=='success','artifact_run_not_accepted');return r

def exclusive(c):
 origin(c)
 current=int(os.environ['GITHUB_RUN_ID'])
 for status in ['in_progress','queued','waiting','pending','requested']:
  for repo,path in [('pyatkoff/tour-max','actions/runs?'),('pyatkoff/max-search-bot','actions/runs?'),('pyatkoff/poisk-turov-test','actions/workflows/346454799/runs?')]:
   d=api(repo,path+'status='+status+'&per_page=100');need(d['total_count']==len(d['workflow_runs']) and d['total_count']<=100,'ambiguous_competitor_list');need(not [r for r in d['workflow_runs'] if not(repo=='pyatkoff/max-search-bot' and (r['id']==current or stale_deploy.allowed(r,c['runtime_acceptance']['head_sha'],lambda short,path:api('pyatkoff/'+short,path))))],'competing_writer_or_collector')
 # The carrier concurrency group serializes known publication jobs; require no alternate active carrier.
 d=api('pyatkoff/max-search-bot','actions/runs?branch='+BRANCH+'&per_page=100');need(all(r['id']==current or r['status']=='completed' for r in d['workflow_runs']),'competing_publication_carrier')

def materialize_artifact(desc,out):
 accepted_run(desc['repository'],desc['run_id'],desc['head_sha']);a=api(desc['repository'],'actions/artifacts/'+str(desc['artifact_id']));need(a['expired'] is False and a['workflow_run']['id']==desc['run_id'] and a['digest']=='sha256:'+desc['archive_sha256'],'artifact_metadata_changed')
 raw=api(desc['repository'],'actions/artifacts/'+str(desc['artifact_id'])+'/zip',True);need(len(raw)<100000000 and sha(raw)==desc['archive_sha256'],'artifact_archive_changed')
 out.mkdir(mode=0o700,parents=True,exist_ok=False)
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  need(len(z.namelist())==len(set(z.namelist())),'duplicate_zip_entries')
  for member,item in desc['members'].items():
   info=z.getinfo(member);need(not info.is_dir() and info.file_size<=20000000 and not ((info.external_attr>>16)&0o170000)==0o120000,'artifact_member_invalid');b=z.read(member);need(sha(b)==item['sha256'],'artifact_member_changed');p=safe(out,item['path']);p.parent.mkdir(mode=0o700,parents=True,exist_ok=True);need(not p.exists(),'duplicate_output');p.write_bytes(b)
 return out

def pinned_checkout_reader(p):
 files=p.get('files',{});names={'intent.json','run123.py','admission-config.json','namespace-provenance.json'}|{f'p{b:02d}/read.php' for b in ORDER}
 need(set(files)==names and not p.get('artifact'),'local_reader_exact28_scope')
 need(re.fullmatch('[a-f0-9]{40}',p.get('source_commit','')) is not None,'immutable_reader_source_missing')
 root=pathlib.Path.cwd();blobs={}
 for name,item in files.items():
  rel=item['source_path'];need(rel=='tools/direct_demand/operations/next123/tail123/'+name,'local_reader_source_path_changed')
  parts=pathlib.PurePosixPath(rel).parts;need('..' not in parts and not pathlib.PurePosixPath(rel).is_absolute(),'local_reader_path_escape')
  cursor=root
  for part in parts:
   cursor=cursor/part;need(not cursor.is_symlink(),'local_reader_symlink')
  need(cursor.is_file() and cursor.stat().st_size<=2000000,'local_reader_missing_or_oversize')
  need(re.fullmatch('[a-f0-9]{64}',item.get('sha256','')) is not None,'local_reader_digest_missing')
  blobs[name]=pin(cursor,item['sha256'])
 return blobs

def reader_packet(c,out):
 p=c['reader_packet'];mode=p.get('source_mode','IMMUTABLE_GITHUB_CONTENTS')
 need(mode in ('IMMUTABLE_GITHUB_CONTENTS','PINNED_CHECKOUT_IDENTICAL_TO_ACCEPTED_READER'),'unknown_reader_source_mode')
 accepted_run('pyatkoff/max-search-bot',p['accepted_run_id'],p['source_commit']);need(p['runtime_sha']==c['runtime_acceptance']['head_sha'],'reader_runtime_mismatch_no_rebind')
 if mode=='PINNED_CHECKOUT_IDENTICAL_TO_ACCEPTED_READER':
  blobs=pinned_checkout_reader(p);out.mkdir(mode=0o700)
  for name,raw in blobs.items():
   dest=safe(out,name);dest.parent.mkdir(parents=True,exist_ok=True,mode=0o700);dest.write_bytes(raw)
 elif p.get('artifact'):materialize_artifact(p['artifact'],out)
 else:
  out.mkdir(mode=0o700)
  for path,item in p['files'].items():
   need(re.fullmatch('[a-f0-9]{40}',p['source_commit']) is not None,'immutable_reader_source_missing');j=api('pyatkoff/max-search-bot','contents/'+item['source_path']+'?ref='+p['source_commit']);raw=base64.b64decode(j['content']);need(j['type']=='file' and sha(raw)==item['sha256'],'accepted_reader_source_changed');dest=safe(out,path);dest.parent.mkdir(parents=True,exist_ok=True,mode=0o700);dest.write_bytes(raw)
 packet_config=json.loads(pin(out/'admission-config.json',p['admission_config_sha256']));namespace_raw=pin(out/'namespace-provenance.json',p['namespace_provenance_sha256']);need(packet_config['source_sha256']['namespace-provenance.json']==sha(namespace_raw),'packet_namespace_pin_changed')
 intent=json.loads(pin(out/'intent.json',p['intent_sha256']));pin(out/'run123.py',p['runner_sha256']);need(intent['expected_installed_sha']==p['runtime_sha'] and [g['batch'] for g in intent['groups']]==ORDER,'reader_intent_changed')
 for g in intent['groups']:pin(out/f"p{g['batch']:02d}"/'read.php',g['reader_sha256'])
 return intent

def refresh_read(c,root,execution):
 packet=root/'accepted-reader';intent=reader_packet(c,packet);exclusive(c)
 ad={'state':'FRESH_READ_ONLY_ADMISSION','intent_sha256':sha((packet/'intent.json').read_bytes()),'expected_installed_sha':c['runtime_acceptance']['head_sha'],'source_acceptance_verified':True,'owner_and_concurrency_verified':True,'observed_at':now()};save(root/'reader-admission.json',ad)
 started=now();out=root/'fresh-current123';p=subprocess.run([sys.executable,str(packet/'run123.py'),str(packet),str(out),str(root/'reader-admission.json')],capture_output=True,timeout=2400);need(p.returncode==0,'reader_refresh_failed_no_retry')
 summary=json.loads((out/'summary.json').read_bytes());need(summary.get('ok') is True and summary.get('hotels')==123 and len(summary.get('groups',[]))==14 and all(summary.get(k)==0 for k in ['writes','provider_calls','sender_replays']),'reader_refresh_incomplete');compiler.fresh(started)
 # Unchanged reader does not timestamp each result. This receipt bounds EVERY read by the
 # observed runner start and finish; raw bytes remain separately preserved and hash-bound.
 proof={'started_at':started,'finished_at':now(),'runner_sha256':c['reader_packet']['runner_sha256'],'groups':[],'execution':execution}
 normalized=root/'current123-normalized';normalized.mkdir(mode=0o700)
 for g in intent['groups']:
  raw=(out/f"p{g['batch']:02d}.stdout.json").read_bytes();r=json.loads(raw);need(r.get('installed_sha')==c['runtime_acceptance']['head_sha'] and len(r.get('current_registry_receipts',[]))==2*len(g['hotel_ids']),'registry_receipt_count_changed');need(all(x.get('current_registry_receipt_verified') is True and x.get('provider_read_performed') is False for x in r['current_registry_receipts']),'registry_receipt_not_verified');need([x['hotel_id'] for x in r['rows']]==g['hotel_ids'],'reader_namespace_changed')
  r['read_at_utc']=started;r['read_timestamp_provenance']={'kind':'authenticated_runner_start_lower_bound','original_raw_sha256':sha(raw)};dst=normalized/f"p{g['batch']:02d}.json";save(dst,r);proof['groups'].append({'batch':g['batch'],'original_raw_sha256':sha(raw),'normalized_sha256':sha(dst.read_bytes())})
 namespace_raw=(out/'namespace-proof.json').read_bytes();namespace=json.loads(namespace_raw);wanted=[hid for g in intent['groups'] for hid in g['hotel_ids']]
 need(namespace.get('ok') is True and namespace.get('provider_calls')==0 and namespace.get('additional_sql_queries')==0 and namespace.get('legacy_anytour_ids_inferred') is False,'namespace_boundary_changed')
 need([x['native_catalog_id'] for x in namespace.get('rows',[])]==wanted and len(set(wanted))==123,'namespace_scope_changed')
 hashes={g['batch']:g['original_raw_sha256'] for g in proof['groups']}
 for x in namespace['rows']:need(x['catalog_table']=='catalog_hotels' and x['local_tv_id']==x['native_catalog_id'] and x['source_result_sha256']==hashes[x['batch']],'namespace_identity_changed')
 proof['namespace_proof_sha256']=sha(namespace_raw)
 save(root/'read-refresh-proof.json',proof);return normalized,proof

def ssh_php(program):
 t=pathlib.Path(os.environ['RUNNER_TEMP']);host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER'];need(re.fullmatch('[A-Za-z0-9_.:-]+',host) is not None and not host.startswith('-') and re.fullmatch('[A-Za-z0-9_-]+',user) is not None,'ssh_destination_invalid')
 cmd=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),user+'@'+host,'unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php']
 return subprocess.run(cmd,input=program,capture_output=True,timeout=120)

def validate_writer(receipt,g):
 need(receipt.get('ok') is True and receipt.get('operation')==g['operation'] and receipt.get('mode')=='publish_three_static_public_direct_yml_feeds','native_writer_not_complete')
 need(all(receipt.get(k)==0 for k in ['db_writes','provider_calls','message_sends']),'native_writer_boundary_changed')
 got=receipt.get('files',[]);need([f['name'] for f in got]==list(compiler.NAMES),'native_writer_file_scope')
 for f,w in zip(got,g['files']):need(all(f.get(k)==w[k] for k in ['name','sha256','bytes','rows']) and f.get('write_action')=='replace','native_writer_file_mismatch')

def run_probe(c,root,inv,execution,serial,pairs,expected=None):
 cfg={'state':'REVIEWED_READONLY_FEED123_PROBE','inventory_sha256':compiler.INVENTORY_SHA,'expected_installed_sha':c['runtime_acceptance']['head_sha'],'runtime_acceptance':c['runtime_acceptance'],'feed_date':c['feed_date'],'execution':execution,'originals':[{k:o[k] for k in ['batch','operation','summary_line_sha256']} for o in inv['operations']],'owner_and_concurrency_verified':True,'historical_operation_use_reviewed':True,'probe_repository':execution['repository'],'probe_run_id':execution['run_id'],'probe_run_attempt':1,'previous_pairs':pairs}
 need(expected is not None,'exact877_preimage_descriptor_required');cfg['expected_feed_sha256']=expected
 pending=[];offset=877
 for op in inv['operations']:
  offset+=len(op['hotel_ids'])
  if offset>pairs:pending.extend(op['hotel_ids'])
 cfg['absent_offer_ids']=[prefix+str(h) for h in pending for prefix in ('tg_','max_')]

 config=root/f'probe-{serial}-config.json';save(config,cfg);folder=root/f'probe-{serial}';source=pathlib.Path(__file__).with_name('probe_feed123_remote.py');runner=source.with_name('run_feed123_probe.py')
 p=subprocess.run([sys.executable,str(runner),str(config),sha(config.read_bytes()),str(root/'originals'/'recovered-inventory-next123.json'),str(source),sha(source.read_bytes()),str(folder)],capture_output=True,timeout=260);need(p.returncode==0,'read_only_probe_failed_stop_no_retry');result=json.loads((folder/'probe-result.json').read_bytes());need(result['ok'] is True,'probe_not_complete');return folder,result

def local_evidence(execution,raw,stamp):return {**execution,'source':'THIS_AUTHENTICATED_WORKFLOW_RUN','conclusion':'success','local_result_sha256':sha(raw),'observed_at':stamp}
def main():
 raise ValueError('USE_REVIEWED_FINITE123_ONLY')
if __name__=='__main__':main()
