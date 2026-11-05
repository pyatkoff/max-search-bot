import json,os,pathlib,subprocess
t=pathlib.Path(os.environ['RUNNER_TEMP']);assert os.environ['GITHUB_RUN_ATTEMPT']=='1';root=pathlib.Path((t/'link-execution-root.txt').read_text().strip());index=json.loads((root/'update-index.json').read_text());assert len(index)==10 and [x['hotel_id'] for x in index]==[265,269,272,275,282,287,315,328,358,382]
sha=os.environ['EXPECTED_PRODUCTION_SHA'];ssh=['ssh','-i',str(t/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(t/'channel_known_hosts'),os.environ['DEPLOY_USER']+'@'+os.environ['DEPLOY_HOST']]
remote="set -e; cd /var/www/anytoour/data/www/app.anytoour.ru; test \"$(git rev-parse HEAD)\" = '"+sha+"'; test -z \"$(git status --porcelain --untracked-files=no)\"; php"
summaries=[]
for entry in index:
 folder=root/entry['folder'];out=folder/'update-result.private.json';assert not out.exists() and not (folder/'update-attempt-started.private.json').exists(),'ORIGINAL_UPDATE_OUTCOME_MUST_RESOLVE_FIRST_NO_RETRY'
 (folder/'update-attempt-started.private.json').write_text(json.dumps({'operation':entry['operation'],'automatic_retry':False})+'\n')
 with (folder/'execution.php').open('rb') as src,out.open('wb') as dst:r=subprocess.run(ssh+[remote],stdin=src,stdout=dst,stderr=subprocess.PIPE,timeout=240)
 try:d=json.loads(out.read_text().splitlines()[-1])
 except Exception:raise AssertionError('UNKNOWN_ORIGINAL_EDIT_OUTCOME_NO_RETRY')
 safe={k:d.get(k) for k in ['operation','ok','error','installed_sha','update_kind','post_write_attempts','content_edit_attempts','price_block_updates','search_link_post_updates','search_link_only_post_updates','commercial_text_updates','button_only_updates','button_only_edit_attempts','new_posts_created']}
 safe['catalog_id']=entry['hotel_id'];safe['verified_existing_posts']=len(d.get('posts',[]));summaries.append(safe);(t/'link-execution-summary.json').write_text(json.dumps(summaries)+'\n');print(json.dumps(safe),flush=True)
 assert d['operation']==entry['operation'] and r.returncode==0 and d['ok'] is True,'ORIGINAL_OFFER_UPDATE_STOPPED_RECONCILE_NO_AUTOMATIC_RETRY'
 assert d['post_write_attempts']==d['content_edit_attempts']==d['commercial_text_updates']==2 and len(d['posts'])==2 and d['button_only_updates']==d['button_only_edit_attempts']==d['new_posts_created']==0
 assert d['price_block_updates']==(2 if entry['price_total_verified'] else 0) and d['search_link_post_updates']==(2 if entry['search_link_included'] else 0)
final={'operation':'commercial-hotel-search-links-20261105-ten2-pass1','complete':True,'processed_hotels':len(summaries),'content_edit_attempts':sum(x['content_edit_attempts'] for x in summaries),'verified_existing_posts':sum(x['verified_existing_posts'] for x in summaries),'price_posts_updated':sum(x['price_block_updates'] for x in summaries),'search_link_posts_updated':sum(x['search_link_post_updates'] for x in summaries),'search_link_only_posts_updated':sum(x['search_link_only_post_updates'] for x in summaries),'new_posts':0,'additional_gateway_calls':0,'source_searches':0,'quote_state':'NOQUOTE','new_link_hotel_pairs':len(summaries)}
(t/'link-execution-complete-safe.json').write_text(json.dumps(final)+'\n');print(json.dumps(final))
