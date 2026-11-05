import ast,base64,hashlib,json,os,pathlib,sys
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
manifest=(base/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes()
assert hashlib.sha256(manifest).hexdigest()=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361'
m=json.loads(manifest);source=(base/'execute-published-price-source-refresh-20261105-pass1.php').read_text()
assert hashlib.sha256(source.encode()).hexdigest()=='1dd5f40e632acbc07ac98f8d65cab279796a1bbfbf9c088cd1ccb77bbb2c5a4a'
fixture='--fixture' in sys.argv
constants={'EXPECTED_INSTALLED_SHA':os.environ.get('EXPECTED_PRODUCTION_SHA','25a2d5e31aec7243b45c47e836a544e659e28b1d'),'APPROVED_MANUAL_OPERATION':m['operation'],'APPROVED_MANUAL_MANIFEST_SHA256':hashlib.sha256(manifest).hexdigest(),'APPROVED_MANUAL_MANIFEST_B64':base64.b64encode(manifest).decode()}
if not fixture:
 approval_path=base/'commercial-price-source-refresh-20261105-pass1-user-approval.json'
 assert approval_path.is_file(),'EXPLICIT_USER_MANUAL_COLLECTION_APPROVAL_NOT_RECORDED'
 raw=approval_path.read_bytes();a=json.loads(raw)
 assert a['state']=='EXPLICIT_USER_MANUAL_COLLECTION_APPROVAL_RECORDED' and a['operation']==m['operation'] and a['manifest_sha256']==hashlib.sha256(manifest).hexdigest() and a['max_new_searches']==7 and a['permission_text_sha256'] and a['granted_at_utc']
 constants.update(APPROVED_MANUAL_COLLECTION=True,APPROVED_MANUAL_APPROVAL_SHA256=hashlib.sha256(raw).hexdigest())
 intent={'operation':m['operation'],'state':'EXPLICIT_APPROVED_SEVEN_NEW_SEARCHES_INTENT_BEFORE_ANY_PROVIDER','manifest_sha256':constants['APPROVED_MANUAL_MANIFEST_SHA256'],'manual_approval_sha256':constants['APPROVED_MANUAL_APPROVAL_SHA256'],'expected_source':constants['EXPECTED_INSTALLED_SHA'],'native_hotel_count':121,'max_new_searches':7,'max_poll_requests_per_search':20,'max_result_requests_per_search':1,'max_http_attempts':154,'automatic_retry':False,'post_edits':0,'new_posts':0}
 (temp/'manual-refresh-intent.json').write_text(json.dumps(intent)+'\n')
prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
header=prefix+''.join('const '+key+'='+json.dumps(value,ensure_ascii=False)+';\n' for key,value in constants.items())
(temp/'manual-refresh.php').write_text(header+source[len(prefix):])
print(json.dumps({'operation':m['operation'],'mode':'pure_fixture' if fixture else 'explicitly_approved_execution_prepare','native_hotels':121,'new_search_limit':7,'provider_calls':0,'db_calls':0,'approval_record_required_for_execution':True}))
