"""Sequential existing SSH transport, read-only PHP stdin. Requires reviewed live admission."""
import hashlib,json,os,pathlib,subprocess,sys
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def validate(result,g):
 ids=g['hotel_ids'];n=len(ids)
 need(result.get('ok') is True,'bounded_reader_failed')
 need(result.get('operation')==g['operation'],'read_operation_changed')
 need(all(result.get(k)==0 for k in ['db_writes','provider_calls','message_sends','deferred_count']),'read_boundary')
 need(all(result.get(k)==n for k in ['scope_hotels','feed_rows','private_binding_count']),'read_scope')
 need([r['hotel_id'] for r in result['rows']]==ids and [r['hotel_id'] for r in result['private_max_bindings']]==ids,'read_identity')
def main():
 os.umask(0o077);root=pathlib.Path(sys.argv[1]);dest=pathlib.Path(sys.argv[2]);ad=json.loads(pathlib.Path(sys.argv[3]).read_bytes());raw=(root/'intent.json').read_bytes();intent=json.loads(raw)
 need(intent['hotels']==174 and intent['groups_count']==23 and [g['batch'] for g in intent['groups']]==list(range(1,24)) and [len(g['hotel_ids']) for g in intent['groups']]==[10,10,8,10,10,8,6,7,3,1,6,4,8,10,10,9,2,8,10,10,10,10,4],'exact174_scope')
 # Admission is provided by the reviewed existing workflow, not inferred from offline data.
 need(ad.get('state')=='FRESH_READ_ONLY_ADMISSION' and ad.get('intent_sha256')==sha(raw) and ad.get('expected_installed_sha')==intent['expected_installed_sha'] and ad.get('source_acceptance_verified') is True and ad.get('owner_and_concurrency_verified') is True,'live_admission_missing')
 dest.mkdir(exist_ok=False)
 for name,content in [('fresh-admission.json',pathlib.Path(sys.argv[3]).read_bytes()),('intent.json',raw),('admission-config.json',(root/'admission-config.json').read_bytes()),('namespace-provenance.json',(root/'namespace-provenance.json').read_bytes())]:
  (dest/name).write_bytes(content)
 provenance=json.loads((root/'namespace-provenance.json').read_bytes());namespace_rows=[]
 config=json.loads((root/'admission-config.json').read_bytes())
 need(sha((root/'namespace-provenance.json').read_bytes())==config['source_sha256']['namespace-provenance.json'],'namespace_provenance_changed')
 (dest/'source-provenance.json').write_text(json.dumps({'github_sha':os.environ['GITHUB_SHA'],'github_run_id':os.environ['GITHUB_RUN_ID'],'source_sha256':config['source_sha256'],'intent_sha256':sha(raw),'admission_config_sha256':sha((root/'admission-config.json').read_bytes()),'reader_sha256':{str(g['batch']):g['reader_sha256'] for g in intent['groups']}})+'\n')
 temp=pathlib.Path(os.environ['RUNNER_TEMP']);host=os.environ['DEPLOY_HOST'];user=os.environ['DEPLOY_USER']
 import re
 need(re.fullmatch(r'[A-Za-z0-9_.:-]+',host) and not host.startswith('-') and re.fullmatch(r'[A-Za-z0-9_-]+',user),'destination_invalid')
 # Same max-search-bot pinned-key/known-host transport. No keys created here.
 cmd=['ssh','-i',str(temp/'channel_key'),'-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes','-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(temp/'channel_known_hosts'),user+'@'+host,"unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php"]
 summaries=[]
 for g in intent['groups']:
  folder=root/('p%02d'%g['batch']);program=(folder/'read.php').read_bytes();need(sha(program)==g['reader_sha256'],'reader_changed')
  need(program.decode().count(provenance['reader_source']['query_exact_line'])==1,'native_catalog_query_changed')
  p=subprocess.run(cmd,input=program,capture_output=True,timeout=90)
  (dest/('p%02d.stdout.json'%g['batch'])).write_bytes(p.stdout)
  need(p.returncode==0,'bounded_read_exit_stop_no_retry');need(len(p.stdout)<2000000,'oversize_read')
  result=json.loads(p.stdout);validate(result,g);need(result['installed_sha']==intent['expected_installed_sha'],'runtime_changed')
  need(len(result.get('current_registry_receipts',[]))==2*len(g['hotel_ids']) and all(x.get('current_registry_receipt_verified') is True and x.get('provider_read_performed') is False for x in result['current_registry_receipts']),'current_registry_receipt_missing')
  original_posts={(p['hotel_id'],p['platform']):p for p in g['original_publications']}
  for row,b in zip(result['rows'],result['private_max_bindings']):
   hid=row['hotel_id'];maxp=original_posts[(hid,'max')];tgp=original_posts[(hid,'telegram')]
   need(str(b['publication_id'])==str(maxp['publication_id']) and b['version_id']==maxp['version_id'] and b['post_url']==row['max_post_url']==maxp['post_url'] and row['telegram_post_url']==tgp['post_url'],'original_publication_drift')
  for row in result['rows']:
   need(type(row['hotel_id']) is int and row['hotel_id']>0 and int(row['country_id'])>0 and int(row['country_id'])!=11 and int(row['region_id'])>0,'native_catalog_result_invalid')
   namespace_rows.append({'batch':g['batch'],'catalog_table':'catalog_hotels','native_catalog_id':row['hotel_id'],'local_tv_id':row['hotel_id'],'country_id':row['country_id'],'region_id':row['region_id'],'name':row['name'],'source_result_sha256':sha(p.stdout),'reader_sha256':g['reader_sha256'],'derivation':'validated existing SELECT catalog_hotels.id membership; consumer native-ID contract, not legacy mapping'})
  summaries.append({'batch':g['batch'],'sha256':sha(p.stdout),'hotels':len(g['hotel_ids'])})
 need(len(summaries)==23 and sum(s['hotels'] for s in summaries)==174,'aggregate_incomplete')
 need(len(namespace_rows)==174 and len({x['native_catalog_id'] for x in namespace_rows})==174,'namespace_scope_changed')
 (dest/'namespace-proof.json').write_text(json.dumps({'ok':True,'provenance':provenance,'rows':namespace_rows,'native_namespace':[{'hotel_id':x['native_catalog_id'],'local_tv_id':x['local_tv_id'],'catalog_table':x['catalog_table'],'source_read_sha256':x['source_result_sha256']} for x in namespace_rows],'provider_calls':0,'additional_sql_queries':0,'legacy_anytour_ids_inferred':False})+'\n')
 (dest/'summary.json').write_text(json.dumps({'ok':True,'groups':summaries,'hotels':174,'writes':0,'provider_calls':0,'sender_replays':0,'current_tg_photo_buttons_verified':False})+'\n')
if __name__=='__main__':main()
