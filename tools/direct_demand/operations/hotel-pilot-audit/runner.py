"""Single checked SSH stdin invocation. Never persist raw stdout/stderr or retry."""
import pathlib,json,os,subprocess,sys,re,datetime
from admit import load,need
from safe_output import validate,safe_failure
def main():
 root=pathlib.Path(sys.argv[1]);dest=pathlib.Path(sys.argv[2]);ad=json.loads(pathlib.Path(sys.argv[3]).read_bytes());c,digest=load(root)
 need(ad['state']=='SELECT_ONLY_ANALYTICS_ADMITTED' and ad['config_sha256']==digest and ad['head_sha']==os.environ['GITHUB_SHA'] and str(ad['run_id'])==os.environ['GITHUB_RUN_ID'],'admission_binding')
 age=(datetime.datetime.now(datetime.timezone.utc)-datetime.datetime.fromisoformat(ad['observed_at'])).total_seconds();need(0<=age<=120,'admission_age')
 temp=pathlib.Path(os.environ['RUNNER_TEMP']);host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER'];need(re.fullmatch(r'[A-Za-z0-9_.:-]+',host) and not host.startswith('-') and re.fullmatch(r'[A-Za-z0-9_-]+',user),'destination_invalid')
 cmd=['ssh','-i',str(temp/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(temp/'channel_known_hosts'),user+'@'+host,"unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php"]
 result=subprocess.run(cmd,input=(root/'program.php').read_bytes(),capture_output=True,timeout=35)
 need(len(result.stdout)<=2097152,'native_output_bound');parsed=json.loads(result.stdout)
 if result.returncode!=0:
  safe=safe_failure(parsed);dest.mkdir(exist_ok=False,mode=0o700);(dest/'stop.json').write_text(json.dumps(safe));print('ANALYTICS_SAFE_STOP:'+safe['error'],file=sys.stderr);raise SystemExit(1)
 safe=validate(parsed,c)
 dest.mkdir(exist_ok=False,mode=0o700);(dest/'aggregate.json').write_text(json.dumps(safe,ensure_ascii=False,separators=(',',':')));(dest/'provenance.json').write_text(json.dumps({'run_id':int(os.environ['GITHUB_RUN_ID']),'head_sha':os.environ['GITHUB_SHA'],'config_sha256':digest,'source_sha256':c['source_sha256'],'native_calls':1,'automatic_retries':0}))
if __name__=='__main__':
 try:main()
 except Exception:print('ANALYTICS_READ_STOP',file=sys.stderr);sys.exit(1)
