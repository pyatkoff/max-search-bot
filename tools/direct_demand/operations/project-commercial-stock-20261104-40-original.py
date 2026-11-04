import copy,json,os,pathlib
temp=pathlib.Path(os.environ['RUNNER_TEMP']);paths=list((temp/'stock40-original').rglob('stock40-readback.json'));assert len(paths)==1
d=json.loads(paths[0].read_text().splitlines()[-1]);assert d['operation']=='commercial-stock-20261104-40-read1' and d['ok'] is True and d['installed_sha']=='f84391d45dd9643271473cf3c325e9940e2a4593'
assert d['whole_previous159_registry_read'] is False and d['db_writes']==d['provider_calls']==d['message_sends']==0
def project(g):
 if g['state']!='unique_catalog_match' or len(g['matches'])!=1:return None
 h=g['matches'][0]
 if int(h['country_id'])!=4 or int(h['is_active'])!=1 or h['existing_registry_matches']!={'telegram':0,'max':0}:return None
 return {'key':g['key'],'hotel_id':int(h['id']),'hotel':h['name'],'country_id':4,'region_id':int(h['region_id']),'region':h['region'],'is_active':1,'registry_matches':h['existing_registry_matches'],'identity_provenance':'accepted_native_catalog_read','source_run':41924993837,'source_artifact':13369371370,'ready_for_send':False,'new_posts':0}
g=d['candidates'][0];assert project(g)['hotel_id']==47025
x=copy.deepcopy(g);x['matches'][0]['country_id']=1;assert project(x) is None
x=copy.deepcopy(g);x['matches'][0]['existing_registry_matches']['max']=1;assert project(x) is None
rows=[r for r in (project(g) for g in d['candidates']) if r is not None]
assert [r['hotel_id'] for r in rows]==[47025,21642,948,1217,1369,1370,1368,1013,1531,1530] and len(set(r['hotel_id'] for r in rows))==10
out={'operation':'commercial-stock-20261104-40-native-projection','state':'TEN_NEW_UNPUBLISHED_NATIVE_IDENTITIES_PROVEN_NOT_READY','source_run':41924993837,'source_artifact':13369371370,'new_native_identities':rows,'new_projection_cases_passed':3,'old_projection_fixtures_rerun':0,'scope_db_rereads':0,'provider_calls':0,'ready_for_send':0,'new_posts':0}
(temp/'stock40-native-projection.json').write_text(json.dumps(out,ensure_ascii=False)+'\n');print(json.dumps(out,ensure_ascii=False))
