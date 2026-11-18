"""One original push, isolated analytics only. No inherited publisher or FEED claims."""
import pathlib,json,hashlib,os,re,subprocess,sys,datetime
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(x,msg):
 if not x:raise ValueError(msg)
def api(path):return json.loads(subprocess.check_output(['gh','api','--method','GET','repos/pyatkoff/max-search-bot/'+path],timeout=20))
def validate_event(c,e,env,parent,head):
 need(env['GITHUB_REPOSITORY']=='pyatkoff/max-search-bot' and env['GITHUB_ACTOR']=='pyatkoff' and env['GITHUB_EVENT_NAME']=='push' and env['GITHUB_RUN_ATTEMPT']=='1','event_scope')
 need(e.get('before')=='0'*40 and e.get('created') is True and e.get('deleted') is False,'original_new_branch_only')
 need(e.get('after')==head==env['GITHUB_SHA'] and e.get('ref')=='refs/heads/'+c['carrier'],'event_head_or_carrier')
 need(parent==c['parent_sha']==c['runtime_sha'],'checkout_parent')
def load(root):
 raw=(root/'config.json').read_bytes();pin=os.environ.get('REVIEWED_ANALYTICS_CONFIG_SHA256');need(type(pin)is str and re.fullmatch('[a-f0-9]{64}',pin) and sha(raw)==pin,'closed_config')
 c=json.loads(raw);need(c['state']=='REVIEWED_SELECT_ONLY_ANALYTICS' and c['native_execution_allowed'] is True,'closed_gate')
 need(c['carrier']=='audit/hotel-pilot-entries-20261119' and c['runtime_sha']=='bd8e248f7138ea75cb9fda2dcf44beb631c633a5','scope')
 need(set(c['source_sha256'])=={'admit.py','runner.py','safe_output.py','program.php'},'source_closure')
 for name,digest in c['source_sha256'].items():need(sha((root/name).read_bytes())==digest,'source_changed')
 need(c['window']=={'from_utc':'2026-11-11 21:00:00','until_utc_exclusive':'2026-11-18 21:00:00'},'window_changed')
 return c,sha(raw)
def main():
 root=pathlib.Path(sys.argv[1]);c,digest=load(root);head=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip();parent=subprocess.check_output(['git','rev-parse','HEAD^'],text=True).strip();e=json.loads(pathlib.Path(os.environ['GITHUB_EVENT_PATH']).read_bytes());validate_event(c,e,os.environ,parent,head)
 need(api('git/ref/heads/'+c['carrier'])['object']['sha']==head,'carrier_moved');need(api('git/ref/heads/main')['object']['sha']==c['runtime_sha'],'main_moved')
 run=api('actions/runs/'+os.environ['GITHUB_RUN_ID']);need(run['head_sha']==head and run['run_attempt']==1 and run['event']=='push' and run['head_branch']==c['carrier'],'original_run_identity')
 subprocess.run(['php','-n','-l',str(root/'program.php')],check=True,timeout=10,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 pathlib.Path(sys.argv[2]).write_text(json.dumps({'state':'SELECT_ONLY_ANALYTICS_ADMITTED','config_sha256':digest,'head_sha':head,'run_id':int(os.environ['GITHUB_RUN_ID']),'observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}))
if __name__=='__main__':
 try:main()
 except Exception:print('ANALYTICS_ADMISSION_STOP',file=sys.stderr);sys.exit(1)
