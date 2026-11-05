import json,os,pathlib
temp=pathlib.Path(os.environ['RUNNER_TEMP'])
paths=list((temp/'stock41-original').rglob('stock41-readback.json'));assert len(paths)==1
d=json.loads(paths[0].read_text().splitlines()[-1])
assert d['operation']=='commercial-stock-20261105-41-read1' and d['ok'] is True and d['installed_sha']=='25a2d5e31aec7243b45c47e836a544e659e28b1d'
assert d['whole_previous159_registry_read'] is False and d['targeted_registry_rows']==0 and d['db_writes']==d['provider_calls']==d['message_sends']==0
expected=[1420,1421,1422,1253,15904,15913,78251,27349,108942,1260]
keys=["papillon-ayscha","papillon-belvil","papillon-zeugma","kaya-belek","kaya-palazzo-golf","titanic-deluxe-golf-belek","cullinan-belek","maxx-royal-kemer","maxx-royal-bodrum","ic-santai-family"]
assert [g['key'] for g in d['candidates']]==keys
rows=[]
for g,expected_id in zip(d['candidates'],expected):
 assert g['state']=='unique_catalog_match' and len(g['matches'])==1
 h=g['matches'][0]
 assert int(h['id'])==expected_id and int(h['country_id'])==4 and int(h['is_active'])==1 and h['existing_registry_matches']=={'telegram':0,'max':0}
 rows.append({'key':g['key'],'hotel_id':int(h['id']),'hotel':h['name'],'country_id':4,'region_id':int(h['region_id']),'region':h['region'],'is_active':1,'registry_matches':h['existing_registry_matches'],'identity_provenance':'accepted_native_catalog_read','source_run':41956089070,'source_artifact':13383226155,'ready_for_send':False,'new_posts':0})
assert len(rows)==len(set(r['hotel_id'] for r in rows))==10
out={'operation':'commercial-stock-20261105-41-native-projection','state':'TEN_NEW_UNPUBLISHED_NATIVE_IDENTITIES_PROVEN_NOT_READY','source_run':41956089070,'source_artifact':13383226155,'installed_source':d['installed_sha'],'new_native_identities':rows,'new_input_binding_assertions_passed':True,'old_projection_fixtures_rerun':0,'scope_db_rereads':0,'provider_calls':0,'ready_for_send':0,'new_posts':0}
(temp/'stock41-native-projection.json').write_text(json.dumps(out,ensure_ascii=False)+'\n')
print(json.dumps(out,ensure_ascii=False))
