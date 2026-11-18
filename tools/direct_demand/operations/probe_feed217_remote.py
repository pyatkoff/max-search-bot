"""Read-only remote probe. No file creation, provider access or generated writer execution."""
import base64,datetime,fcntl,hashlib,json,os,pathlib,re,subprocess,sys,urllib.request,xml.etree.ElementTree as ET
ROOT=pathlib.Path('/var/www/anytoour/data/www/app.anytoour.ru')
JOURNAL=pathlib.Path('/var/www/anytoour/data/channel-post-operations')
HOOK=pathlib.Path('/var/www/anytoour/data/www/anytoour.ru/data/monitor-price-hook.php')
HOOK_SHA='1ad41a8a3a8d10632e0e6127289481675f655c7f7f9a5bbe39b698c03e73e592'
NAMES=('hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml')
def need(v,msg):
 if not v:raise ValueError(msg)
def sha(b):return hashlib.sha256(b).hexdigest()
def safe(p):
 need(not p.is_symlink() and p.resolve()==p,'path_not_canonical');return p
def read(p,limit):
 safe(p)
 with p.open('rb') as f:b=f.read(limit+1)
 need(len(b)<=limit,'read_limit');return b
def monitor_check(journal):
 p=safe(journal/'monitor-price-after-collection-v1.jsonl')
 with p.open('rb') as f:
  fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
  try:
   size=os.fstat(f.fileno()).st_size;need(size>0,'empty_monitor_journal');length=min(size,2000001);f.seek(size-length);raw=f.read(length)
   need(len(raw)==length and raw.endswith(b'\n'),'incomplete_monitor_journal');tail=raw.rstrip(b'\n');idx=tail.rfind(b'\n');need(idx>=0 or length==size,'incomplete_monitor_row');last=tail[idx+1:];need(0<len(last)<2000000,'monitor_row_limit');row=json.loads(last);need('pending' in row and row['pending'] is None,'monitor_pending')
  finally:fcntl.flock(f,fcntl.LOCK_UN)
 return {'global_ex_nb_available':True,'pending_null':True,'lock_released':True}
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):raise ValueError('public_redirect_refused')
def get_public(name):
 url='https://app.anytoour.ru/feed/'+name
 with urllib.request.build_opener(NoRedirect()).open(urllib.request.Request(url,headers={'Cache-Control':'no-cache','Accept-Encoding':'identity'}),timeout=30) as r:
  need(r.status==200 and r.geturl()==url,'public_response_changed');b=r.read(4000001)
 need(len(b)<=4000000,'public_feed_limit');return b
def inspect(cfg,root=ROOT,journal=JOURNAL,hook=HOOK,fetch=get_public):
 need(cfg.get('state')=='REVIEWED_READONLY_FEED217_PROBE','probe_not_admitted')
 expected=cfg.get('expected_installed_sha','');need(re.fullmatch('[a-f0-9]{40}',expected) is not None,'runtime_pin_missing')
 acceptance=cfg.get('runtime_acceptance',{});need(acceptance.get('state')=='DEPLOYED_AND_NATIVE_ACCEPTED' and acceptance.get('head_sha')==expected and isinstance(acceptance.get('deploy_run_id'),int) and acceptance['deploy_run_id']>0 and re.fullmatch('[a-f0-9]{64}',acceptance.get('native_acceptance_sha256','')) is not None,'runtime_not_accepted')
 safe(root);safe(journal);safe(root/'feed');env=os.environ.copy()
 for k in list(env):
  if k.startswith('GIT_'):env.pop(k)
 env['GIT_OPTIONAL_LOCKS']='0'
 def git(*args):return subprocess.run(['git','-C',str(root),*args],env=env,capture_output=True,check=True,timeout=15).stdout
 head=git('rev-parse','HEAD').decode().strip();need(head==expected,'installed_source_changed');need(git('status','--porcelain','--untracked-files=no')==b'','tracked_tree_dirty')
 need(sha(read(hook,2000000))==HOOK_SHA,'live_hook_changed')
 # Release EX before any subsequent subprocess, original SH lock or public GET.
 before=monitor_check(journal)
 ops=cfg['originals'];need(len(ops)==24 and len({o['batch'] for o in ops})==24,'original_scope_count')
 summaries=[]
 for op in ops:
  name=op['operation'];need(re.fullmatch('[a-z0-9-]{1,160}',name) is not None,'invalid_original_operation');folder=safe(journal/name);lock=safe(folder/'three-hotels.lock')
  with lock.open('rb') as f:
   fcntl.flock(f,fcntl.LOCK_SH|fcntl.LOCK_NB)
   try:
    raw=read(folder/'publication-summary.json',4000000);need(sha(raw)==op['summary_line_sha256'],'original_summary_exact_bytes_changed');need(not raw.endswith(b'\n'),'original_summary_unexpected_lf');parsed=json.loads(raw);need(parsed.get('ok') is True and parsed.get('operation')==name and parsed.get('mode')=='publish_approved_commercial_batch','original_summary_incomplete')
   finally:fcntl.flock(f,fcntl.LOCK_UN)
  summaries.append({'batch':op['batch'],'sha256':sha(raw),'exact_without_lf':True,'sh_nb_available':True,'lock_released':True})
 date=cfg['feed_date'];need(re.fullmatch('[0-9]{8}',date) is not None,'feed_date_invalid')
 execution=cfg.get('execution',{});need(execution.get('repository')=='pyatkoff/max-search-bot' and type(execution.get('run_id')) is int and execution['run_id']>0 and execution.get('run_attempt')==1,'execution_run_scope_missing')
 operations=['commercial-channel-hotel-feeds-'+date+'-update'+str(execution['run_id']*100+o['batch']) for o in ops]
 # Native writer has no persistent ledger. Do not infer historical non-use from absent files.
 for operation in operations:
  need(not os.path.lexists(journal/operation),'operation_directory_present_reconcile')
  for name in NAMES:need(not os.path.lexists(root/'feed'/('.'+operation+'-'+name+'.tmp')),'operation_tmp_present_reconcile')
 need(not list((root/'feed').glob('.commercial-channel-hotel-feeds-*.tmp')),'other_feed_tmp_present_reconcile')
 pairs=cfg.get('previous_pairs',660);need(type(pairs) is int and 660<=pairs<=877,'probe_pair_scope_invalid')
 feeds=[]
 for name in NAMES:
  local=read(root/'feed'/name,4000000);public=fetch(name);need(public==local,'public_local_feed_bytes_differ');need(not cfg.get('expected_feed_sha256') or sha(local)==cfg['expected_feed_sha256'][name],'admitted_feed_preimage_changed');need(b'<!DOCTYPE' not in local and b'<!ENTITY' not in local,'unsafe_xml');tree=ET.fromstring(local);offers=tree.findall('./shop/offers/offer');count=2*pairs if name==NAMES[2] else pairs;need(len(offers)==count and len({o.attrib.get('id') for o in offers})==count,'feed_count_or_ids_changed');feeds.append({'name':name,'sha256':sha(local),'bytes':len(local),'offers':count,'public_local_identical':True,'content_base64':base64.b64encode(local).decode()})
 # A price cycle may run while GETs occur: reject any baseline drift and pending state afterward.
 for f in feeds:need(sha(read(root/'feed'/f['name'],4000000))==f['sha256'],'feed_changed_during_probe')
 after=monitor_check(journal);need(git('rev-parse','HEAD').decode().strip()==head and git('status','--porcelain','--untracked-files=no')==b'' and sha(read(hook,2000000))==HOOK_SHA,'runtime_changed_during_probe')
 return {'state':'READONLY_PREIMAGE_PROBE_COMPLETE_NOT_EXECUTION_ADMISSION','ok':True,'installed_sha':head,'tracked_tree_clean':True,'installed_hook_sha256':HOOK_SHA,'observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'monitor_before':before,'monitor_after':after,'original_summaries':summaries,'proposed_operations':operations,'native_writer_persistent_ledger_exists':False,'historical_operation_reuse_not_proven_by_filesystem':True,'operation_tmp_absent':True,'operation_directory_absent':True,'local_public_verified':True,'files':feeds,'production_writes':0,'provider_calls':0,'message_sends':0,'writer_runs':0}
def main():
 try:print(json.dumps(inspect(json.load(sys.stdin)),separators=(',',':')))
 except Exception as e:
  code=str(e) if isinstance(e,ValueError) and re.fullmatch('[a-z0-9_]{1,100}',str(e)) else type(e).__name__
  print(json.dumps({'ok':False,'error':code,'production_writes':0,'provider_calls':0,'writer_runs':0}));sys.exit(2)
if __name__=='__main__':main()
