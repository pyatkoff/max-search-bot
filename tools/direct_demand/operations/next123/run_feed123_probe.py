"""Single read-only SSH invocation, private result artifact, sanitized console output."""
import base64,hashlib,json,os,pathlib,re,shlex,subprocess,sys
from compiler_bridge123 import INVENTORY_SHA, inventory_projection

def need(v,m):
 if not v:raise ValueError(m)
def sha(b):return hashlib.sha256(b).hexdigest()
def main():
 os.umask(0o077)
 config,config_pin,inventory,remote_source,source_pin,out=map(str,sys.argv[1:])
 raw=pathlib.Path(config).read_bytes();need(re.fullmatch('[a-f0-9]{64}',config_pin) is not None and sha(raw)==config_pin,'reviewed_probe_config_pin_required');cfg=json.loads(raw)
 invraw=pathlib.Path(inventory).read_bytes();need(sha(invraw)==INVENTORY_SHA,'original_inventory_changed');inv=inventory_projection(pathlib.Path(inventory).parent)
 wanted=[{k:o[k] for k in ('batch','operation','summary_line_sha256')} for o in inv['operations']]
 need(cfg.get('originals')==wanted and cfg.get('inventory_sha256')==INVENTORY_SHA,'original_summary_scope_changed')
 need(cfg.get('owner_and_concurrency_verified') is True and cfg.get('historical_operation_use_reviewed') is True,'coordination_review_missing')
 need(cfg.get('probe_repository')==os.environ.get('GITHUB_REPOSITORY')=='pyatkoff/max-search-bot' and cfg.get('probe_run_id')==int(os.environ.get('GITHUB_RUN_ID','0')) and cfg.get('probe_run_attempt')==int(os.environ.get('GITHUB_RUN_ATTEMPT','0'))==1,'probe_workflow_scope_changed')
 source=pathlib.Path(remote_source).read_bytes();need(re.fullmatch('[a-f0-9]{64}',source_pin) is not None and sha(source)==source_pin,'reviewed_probe_source_pin_required')
 dest=pathlib.Path(out);need(not dest.exists(),'output_exists_stop');dest.mkdir(mode=0o700,parents=True)
 host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER'];need(re.fullmatch('[A-Za-z0-9_.:-]+',host) is not None and not host.startswith('-') and re.fullmatch('[A-Za-z0-9_-]+',user) is not None,'connection_scope_invalid');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
 # No remote tempfile, upload, retry or shell-sourced configuration. stdout never reaches logs.
 bootstrap="import base64;exec(compile(base64.b64decode("+repr(base64.b64encode(source).decode())+"),'readonly_feed123_probe','exec'))"
 cmd=['ssh','-i',str(temp/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(temp/'channel_known_hosts'),user+'@'+host,'python3 -c '+shlex.quote(bootstrap)]
 p=subprocess.run(cmd,input=raw,capture_output=True,timeout=240)
 if p.returncode:
  # Avoid persisting arbitrary SSH diagnostics or accidental private data.
  (dest/'failure.json').write_text(json.dumps({'ok':False,'error':'read_only_probe_failed','returncode':p.returncode}));raise ValueError('read_only_probe_failed')
 result=json.loads(p.stdout);need(result.get('ok') is True and result.get('installed_sha')==cfg['expected_installed_sha'] and len(result.get('original_summaries',[]))==14,'probe_result_scope_changed')
 need(all(result.get(k)==0 for k in ('production_writes','provider_calls','message_sends','writer_runs')),'probe_boundary_changed')
 feeds=result.pop('files');need([f['name'] for f in feeds]==['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml'],'probe_file_scope_changed')
 for f in feeds:
  b=base64.b64decode(f.pop('content_base64'),validate=True);need(sha(b)==f['sha256'] and len(b)==f['bytes'],'probe_feed_digest_changed');(dest/f['name']).write_bytes(b)
 result.update(files=feeds,probe_config_sha256=config_pin,probe_source_sha256=source_pin,inventory_sha256=INVENTORY_SHA)
 (dest/'probe-result.json').write_text(json.dumps(result,separators=(',',':'))+'\n')
 print('READONLY_FEED123_PROBE_COMPLETE private_artifact_only production_writes=0 writer_runs=0')
if __name__=='__main__':
 try:main()
 except Exception:print('READONLY_FEED123_PROBE_STOP');sys.exit(2)
