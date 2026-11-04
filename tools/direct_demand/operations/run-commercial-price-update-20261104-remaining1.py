import json,os,pathlib,subprocess
temp=pathlib.Path(os.environ['RUNNER_TEMP']);root=temp/'remaining1'
assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
index=json.loads((root/'update-index.json').read_text());assert len(index)<=8
sha=os.environ['EXPECTED_PRODUCTION_SHA'];assert sha=='d13f5cf94960bef206fe47b872c88f034c76c04f'
ssh=['ssh','-i',str(temp/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(temp/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
summaries=[]
for entry in index:
 folder=root/entry['folder']
 assert not (folder/'update-result.json').exists(),'ORIGINAL_UPDATE_OUTCOME_MUST_RESOLVE_NO_RETRY'
 (folder/'update-attempt-started.json').write_text(json.dumps({'operation':entry['operation'],'automatic_retries':False})+'\n')
 with (folder/'update.php').open('rb') as script,(folder/'update-result.json').open('wb') as out:
  r=subprocess.run(ssh+[remote],stdin=script,stdout=out,stderr=subprocess.PIPE,timeout=240)
 try:d=json.loads((folder/'update-result.json').read_text().splitlines()[-1])
 except Exception:raise AssertionError('UNKNOWN_ORIGINAL_UPDATE_OUTCOME_STOP_NO_RETRY')
 s={k:d.get(k) for k in ['operation','ok','error','post_write_attempts','content_edit_attempts','price_block_updates','commercial_text_updates','button_only_updates','new_posts_created']}
 s['catalog_id']=entry['hotel_id'];s['catalog_name']=entry['catalog_name'];s['checked_epoch_utc']=entry['quote_checked_epoch_utc'];s['verified_existing_posts']=sum(p.get('body_photo_button_verified') is True for p in d.get('posts',[]));s['additional_gateway_calls']=0
 summaries.append(s);(root/'update-summary.json').write_text(json.dumps(summaries)+'\n');print(json.dumps(s),flush=True)
 assert r.returncode==0 and d['operation']==entry['operation'] and d['ok'] is True,'ORIGINAL_EDIT_NOT_FULLY_CONFIRMED_STOP'
 assert d['new_posts_created']==0 and d['price_block_updates']==d['commercial_text_updates']==d['content_edit_attempts']==s['verified_existing_posts']==2
 assert {p['platform'] for p in d['posts']}=={'telegram','max'}
print(json.dumps({'operation':'commercial-price-update-20261104-remaining1','complete':True,'updated_hotels':len(summaries),'verified_existing_edits':sum(x['verified_existing_posts'] for x in summaries),'gateway_calls':0,'new_posts':0}))
