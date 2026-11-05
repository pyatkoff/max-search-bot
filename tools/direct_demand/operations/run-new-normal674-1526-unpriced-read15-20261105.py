import json,os,pathlib,subprocess
t=pathlib.Path(os.environ['RUNNER_TEMP']);assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
out=t/'price15-readback.private.json';assert not out.exists(),'PRICE15_ORIGINAL_NEW_WINDOW_OUTCOME_FIRST_NO_REPEAT'
ssh=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
with (t/'price15.php').open('rb') as src,out.open('wb') as dst:r=subprocess.run(ssh+['set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; php'],stdin=src,stdout=dst,stderr=subprocess.PIPE,timeout=300)
d=json.loads(out.read_text().splitlines()[-1]);s={k:d.get(k) for k in ['operation','ok','error','installed_sha','source_run','source_collect_job','source_partial_groups','max_age_seconds','readback_epoch_utc','db_writes','provider_calls','price_edits','candidate_filter']}
s.update(scoped_hotels=len(d.get('hotels',[])),hotels_with_admitted_candidates=sum(bool(h['observations']) for h in d.get('hotels',[])),admitted_candidate_rows=sum(len(h['observations']) for h in d.get('hotels',[])),candidate_native_ids=[int(h['local_tv_id']) for h in d.get('hotels',[]) if h['observations']])
(t/'price15-summary.json').write_text(json.dumps(s)+'\n');print(json.dumps(s))
assert r.returncode==0 and d['ok'] and d['db_writes']==d['provider_calls']==d['price_edits']==0
