import base64,hashlib,json,os,pathlib,re
root=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
assert os.environ['GITHUB_RUN_ATTEMPT']=='1'
raw=(root/'commercial-top500-internal-base-20261105-ten1-scope.json').read_bytes()
assert hashlib.sha256(raw).hexdigest()==os.environ['EXPECTED_TOP500_SCOPE_SHA256']
s=json.loads(raw);assert len(s['hotel_ids'])==10 and len(set(s['hotel_ids']))==10
assert s['hotel_ids']==[22879,8520,8208,12575,8188,39872,35319,27611,8554,8602]
assert s['metadata_only'] and s['photo_http']==s['quotes']==s['post_writes']==0
c=json.loads((root/'commercial-top500-internal-source-discovery-20261105-pass3-state.json').read_text())
assert c['canonical_unique_tourvisor_ids']==492 and len(set(c['canonical_ids']))==492
assert hashlib.sha256(json.dumps(c['canonical_ids'],separators=(',',':')).encode()).hexdigest()==s['canonical_ids_sha256']
assert not set(s['hotel_ids'])&set(s['excluded_published_native_ids'])
template=(root/'read-top500-internal-metadata-20261105-ten1.php').read_bytes()
assert hashlib.sha256(template).hexdigest()==os.environ['EXPECTED_TOP500_READER_SHA256']
head="<?php\n"
values={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'TOP500_OPERATION':s['operation'],'TOP500_SCOPE_SHA256':hashlib.sha256(raw).hexdigest(),'TOP500_ROSTER_SHA256':s['canonical_ids_sha256'],'TOP500_CATALOG_CONNECTOR_SHA256':os.environ['EXPECTED_CATALOG_CONNECTOR_SHA256'],'TOP500_SCOPE_B64':base64.b64encode(raw).decode(),'TOP500_CANONICAL_IDS':c['canonical_ids'],'TOP500_HOTEL_IDS':s['hotel_ids'],'TOP500_PUBLISHED_IDS':s['excluded_published_native_ids']}
for k,v in values.items():head+='define('+json.dumps(k)+','+json.dumps(v,separators=(',',':'))+');\n'
assert template.startswith(b'<?php\n');prepared=head.encode()+template[len(b'<?php\n'):]
out=t/'top500-metadata.php';assert not out.exists();out.write_bytes(prepared)
intent={'operation':s['operation'],'scope_sha256':hashlib.sha256(raw).hexdigest(),'reader_template_sha256':hashlib.sha256(template).hexdigest(),'prepared_php_sha256':hashlib.sha256(prepared).hexdigest(),'expected_installed_sha':os.environ['EXPECTED_PRODUCTION_SHA'],'hotel_ids':s['hotel_ids'],'db_read_only':True,'quotes':0,'post_writes':0,'photo_http':0}
(t/'top500-input.private.json').write_text(json.dumps(intent,separators=(',',':'))+'\n')
print(json.dumps({'operation':s['operation'],'new_preparation_contract':'PASS','hotels':10,'server_calls':0}))
