import json,os,pathlib,subprocess
temp=pathlib.Path(os.environ['RUNNER_TEMP']);root=temp/'price-normal674-pass1'
assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
index=json.loads((root/'index.json').read_text());assert 0<len(index)<=7 and {x['hotel_id'] for x in index}<={157359,1291,1582,27316,3405,1422,78251}
host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER'];sha=os.environ['EXPECTED_PRODUCTION_SHA']
assert sha==os.environ['EXPECTED_PRODUCTION_SHA']
ssh=['ssh','-i',str(temp/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(temp/'channel_known_hosts'),user+'@'+host]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
summaries=[]
for entry in index:
 folder=root/entry['folder']
 assert not (folder/'quote-result.json').exists(),'ORIGINAL_OUTCOME_MUST_BE_RESOLVED_NO_RETRY'
 (folder/'quote-attempt-started.json').write_text(json.dumps({'operation':entry['quote_operation'],'gateway_retries_allowed':False})+'\n')
 with (folder/'quote.php').open('rb') as script,(folder/'quote-result.json').open('wb') as out:
  r=subprocess.run(ssh+[remote],stdin=script,stdout=out,stderr=subprocess.PIPE,timeout=140)
 try:d=json.loads((folder/'quote-result.json').read_text().splitlines()[-1])
 except Exception:raise AssertionError('UNKNOWN_QUOTE_TRANSPORT_OUTCOME_NO_RETRY')
 assert d['operation']==entry['quote_operation'] and d['db_writes']==d['message_sends']==d['price_block_updates']==0 and d['gateway_requests_attempted']<=1
 safe={k:d.get(k) for k in ['operation','ok','error','catalog_id','gateway_requests_attempted','quote_state','http_status','response_bytes','request_started_epoch_utc','response_received_epoch_utc','automatic_retry_allowed']}
 q=d.get('gateway_response')
 safe['declared_gateway_error']=bool(q.get('error')) if isinstance(q,dict) else None
 safe['retained_flight_options']=len(q.get('flights',[])) if isinstance(q,dict) and isinstance(q.get('flights'),list) else 0
 summaries.append(safe);(root/'summary.json').write_text(json.dumps(summaries)+'\n');print(json.dumps(safe),flush=True)
 assert r.returncode==0 and d['ok'] is True,'EXACT_QUOTE_GATE_FAILED_STOP'
 assert d.get('quote_state')!='GATEWAY_ATTEMPT_OUTCOME_UNKNOWN_DO_NOT_REPEAT','UNKNOWN_ORIGINAL_OUTCOME_STOP_REMAINING_PASS'
print(json.dumps({'operation':'commercial-price-monitor-20261105-normal674-quote-pass1','complete':True,'processed_hotels':len(summaries),'gateway_calls':sum(x['gateway_requests_attempted'] for x in summaries),'new_posts':0,'price_edits':0}))
