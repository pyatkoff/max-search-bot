import json,os,pathlib,subprocess
t=pathlib.Path(os.environ['RUNNER_TEMP']);assert os.environ['GITHUB_RUN_ATTEMPT']=='1';out=t/'original157359-reconcile-result.private.json';assert not out.exists()
sha=os.environ['EXPECTED_PRODUCTION_SHA'];ssh=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
with (t/'reconcile157359.php').open('rb') as src,out.open('wb') as dst:r=subprocess.run(ssh+[remote],stdin=src,stdout=dst,stderr=subprocess.PIPE,timeout=180)
d=json.loads(out.read_text().splitlines()[-1]);safe={k:v for k,v in d.items() if k!='original_owned_snapshot_private'};print(json.dumps(safe));(t/'original157359-reconcile-summary.json').write_text(json.dumps(safe)+'\n')
assert r.returncode==0 and d['ok'] and d['max_original_write_confirmed'] and d['telegram_not_attempted'] and d['provider_write_requests']==d['gateway_calls']==d['new_posts']==d['other_publications_touched']==0 and d['provider_read_requests']<=1
