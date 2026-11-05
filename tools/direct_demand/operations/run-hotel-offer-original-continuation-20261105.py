import json,os,pathlib,subprocess
t=pathlib.Path(os.environ['RUNNER_TEMP']);assert os.environ['GITHUB_RUN_ATTEMPT']=='1';root=pathlib.Path((t/'offer-continuation-root.txt').read_text().strip());index=json.loads((root/'continuation-index.json').read_text());assert len(index)==41 and index[0]['hotel_id']==273
sha=os.environ['EXPECTED_PRODUCTION_SHA'];ssh=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
summaries=[]
for entry in index:
 folder=root/entry['folder'];out=folder/'continuation-result.private.json';assert not out.exists() and not (folder/'continuation-attempt-started.private.json').exists(),'ORIGINAL_CONTINUATION_OUTCOME_FIRST_NO_RETRY'
 (folder/'continuation-attempt-started.private.json').write_text(json.dumps({'operation':entry['operation'],'new_content_edit_limit':entry['expected_new_content_attempts'],'original_max273_must_not_repeat':entry['hotel_id']==273})+'\n')
 with (folder/'continuation.php').open('rb') as src,out.open('wb') as dst:r=subprocess.run(ssh+[remote],stdin=src,stdout=dst,stderr=subprocess.PIPE,timeout=240)
 try:d=json.loads(out.read_text().splitlines()[-1])
 except Exception:raise AssertionError('UNKNOWN_ORIGINAL_CONTINUATION_OUTCOME_NO_RETRY')
 safe={k:d.get(k) for k in ['operation','ok','error','installed_sha','update_kind','post_write_attempts','content_edit_attempts','price_block_updates','search_link_post_updates','search_link_only_post_updates','commercial_text_updates','button_only_updates','button_only_edit_attempts','new_posts_created']}
 safe.update(catalog_id=entry['hotel_id'],verified_existing_posts=len(d.get('posts',[])),original_max_already_confirmed=entry['hotel_id']==273)
 summaries.append(safe);(t/'offer-continuation-summary.json').write_text(json.dumps(summaries)+'\n');print(json.dumps(safe),flush=True)
 assert r.returncode==0 and d['ok'] and d['operation']==entry['operation'],'ORIGINAL_CONTINUATION_STOPPED_RECONCILE_NO_RETRY'
 assert d['post_write_attempts']==d['content_edit_attempts']==entry['expected_new_content_attempts'] and d['commercial_text_updates']==len(d['posts'])==2 and d['button_only_updates']==d['button_only_edit_attempts']==d['new_posts_created']==0
 assert d['price_block_updates']==(2 if entry['price_total_verified'] else 0) and d['search_link_post_updates']==(2 if entry['search_link_included'] else 0)
old=json.loads((t/'original-completed13-safe.json').read_text());allPairs=old+summaries;assert len(allPairs)==54 and len({x['catalog_id'] for x in allPairs})==54
final={'operation':'commercial-hotel-offer-update-20261105-manual1-pass1','complete':True,'confirmed_logical_hotel_pairs':54,'verified_existing_posts':sum(x['verified_existing_posts'] for x in allPairs),'actual_content_edit_attempts_combined':sum(x['content_edit_attempts'] for x in old)+1+sum(x['content_edit_attempts'] for x in summaries),'new_content_edits_this_continuation':sum(x['content_edit_attempts'] for x in summaries),'price_posts_updated':sum(x['price_block_updates'] for x in allPairs),'search_link_posts_updated':sum(x['search_link_post_updates'] for x in allPairs),'search_link_only_posts_updated':sum(x['search_link_only_post_updates'] for x in allPairs),'new_posts':0,'additional_gateway_calls':0,'original273_max_provider_write_repeated':False}
(t/'offer-continuation-complete-safe.json').write_text(json.dumps(final)+'\n');print(json.dumps(final))
