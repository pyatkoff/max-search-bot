import base64,hashlib,json,os,pathlib
root=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
scope=json.loads((root/'commercial-top500-internal-base-20261106-8208-profile1-scope.json').read_text())
parent=json.loads((t/'original-8208'/'top500-8208-metadata.private.json').read_text())
assert parent['operation']=='commercial-top500-internal-base-20261106-8208-original-metadata1' and parent['native_hotel_id']==8208 and parent['source_run']==42165764181 and parent['source_artifact']==13479140677
n=parent['current_native'];g=parent['local_tv_metadata']
assert n['id']==8208 and n['country_id']==2 and n['is_active']==1 and n['name']=='THE SARANN'
assert g['id']==8208 and g['revision']==3 and g['source_fetched_at']=='2026-10-10 10:06:20' and len(g['distinct_declared_image_urls'])==41
assert scope['native_hotel_id']==8208 and scope['max_related_hotels']==1 and scope['read_only'] and not scope['raw_source_json'] and not scope['price_or_quote_fields'] and scope['photo_http']==scope['post_writes']==0
gallery=sorted(hashlib.sha256(v.encode()).hexdigest() for v in g['distinct_declared_image_urls']);assert len(set(gallery))==41
frame={'operation':scope['operation'],'native_hotel_id':8208,'country_id':2,'expected_local_revision':3,'fields':scope['selected_normalized_fields'],'gallery_sha256':gallery,'parent_artifact':13479576337,'parent_run':42166900411,'parent_file_sha256':hashlib.sha256((t/'original-8208'/'top500-8208-metadata.private.json').read_bytes()).hexdigest()}
raw=json.dumps(frame,separators=(',',':')).encode()
template=(root/'read-top500-internal-base-20261106-8208-profile1.php').read_bytes()
assert hashlib.sha256(template).hexdigest()==os.environ['EXPECTED_PROFILE_READER_SHA256']
head="<?php\ndeclare(strict_types=1);\n"
values={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'PROFILE_OPERATION':scope['operation'],'PROFILE_INPUT_B64':base64.b64encode(raw).decode(),'PROFILE_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'PROFILE_EXPECTED_NAME':'THE SARANN','PROFILE_SOURCE_FETCHED_AT':'2026-10-10 10:06:20','PROFILE_FIELDS':scope['selected_normalized_fields'],'PROFILE_CATALOG_CONNECTOR_SHA256':os.environ['EXPECTED_CATALOG_CONNECTOR_SHA256']}
for k,v in values.items():head+='define('+json.dumps(k)+','+json.dumps(v,ensure_ascii=True,separators=(',',':'))+');\n'
prefix=b'<?php\ndeclare(strict_types=1);\n';assert template.startswith(prefix)
php=head.encode()+template[len(prefix):];out=t/'top500-profile.php';assert not out.exists();out.write_bytes(php)
intent={'operation':scope['operation'],'native_hotel_id':8208,'country_id':2,'scope_file_sha256':hashlib.sha256((root/'commercial-top500-internal-base-20261106-8208-profile1-scope.json').read_bytes()).hexdigest(),'prepared_php_sha256':hashlib.sha256(php).hexdigest(),'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'frame_sha256':hashlib.sha256(raw).hexdigest(),'parent_artifact':13479576337,'parent_run':42166900411,'photo_http':0,'gateway_calls':0,'post_writes':0}
(t/'top500-profile-input.private.json').write_text(json.dumps(intent,separators=(',',':'))+'\n')
print(json.dumps({'operation':scope['operation'],'native_hotel_ids':[8208],'prepared_new_profile':True,'server_calls':0}))
