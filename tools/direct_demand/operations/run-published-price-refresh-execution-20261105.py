import json,os,pathlib,subprocess
temp=pathlib.Path(os.environ['RUNNER_TEMP']);assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
intent=json.loads((temp/'manual-refresh-intent.json').read_text())
assert intent['operation']=='commercial-price-source-refresh-20261105-pass1' and intent['max_new_searches']==7
assert not (temp/'manual-refresh-result.private.json').exists() and not (temp/'manual-refresh-attempt-started.json').exists(),'ORIGINAL_OUTCOME_MUST_RESOLVE_FIRST'
(temp/'manual-refresh-attempt-started.json').write_text(json.dumps({'operation':intent['operation'],'manifest_sha256':intent['manifest_sha256'],'automatic_retry':False})+'\n')
sha=os.environ['EXPECTED_PRODUCTION_SHA']
ssh=['ssh','-i',str(temp/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(temp/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
with (temp/'manual-refresh.php').open('rb') as script,(temp/'manual-refresh-result.private.json').open('wb') as result:
 r=subprocess.run(ssh+[remote],stdin=script,stdout=result,stderr=subprocess.PIPE,timeout=1400)
try:d=json.loads((temp/'manual-refresh-result.private.json').read_text().splitlines()[-1])
except Exception:raise AssertionError('UNKNOWN_ORIGINAL_MANUAL_SEARCH_OUTCOME_NO_RETRY')
safe={k:d.get(k) for k in ['operation','ok','error','complete','new_searches_attempted','http_attempts','observation_rows_written','post_edits','new_posts','installed_runtime_changes','automatic_retry']}
safe['group_outcomes']=[{k:g.get(k) for k in ['group_index','state','poll_requests','result_requests','accepted_native_hotels','observations_written']} for g in d.get('groups',[])]
(temp/'manual-refresh-summary.json').write_text(json.dumps(safe)+'\n');print(json.dumps(safe))
assert d['operation']==intent['operation'] and d['post_edits']==d['new_posts']==d['installed_runtime_changes']==0 and d['new_searches_attempted']<=7 and d['http_attempts']<=154
assert r.returncode==0 and d['ok'] is True,'ORIGINAL_MANUAL_OPERATION_STOPPED_RECONCILE_NO_AUTOMATIC_RETRY'
