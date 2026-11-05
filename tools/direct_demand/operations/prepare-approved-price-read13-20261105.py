import base64,datetime,hashlib,json,os,pathlib
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
p=list((temp/'actual-new-scope').rglob('approved-source-new-scope.private.json'));assert len(p)==1;raw=p[0].read_bytes();d=json.loads(raw)
assert d['source_run']==41976677950 and d['source_artifact']==13391673842 and d['source_hotels']==56 and d['source_groups']==7 and 0<len(d['bindings'])<=56 and d['old_retained_rows']==85
manifest=(base/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes();assert hashlib.sha256(manifest).hexdigest()=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361'
s=(base/'read-approved-manual-price-inputs-20261105-read13.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_MANIFEST_B64':base64.b64encode(manifest).decode(),'SOURCE_COLLECTION_INTENT_EPOCH_UTC':int(datetime.datetime.fromisoformat('2026-11-05T05:52:07+00:00').timestamp())}
header=prefix+''.join('const '+k+'='+json.dumps(v)+';\n' for k,v in constants.items());(temp/'price13.php').write_text(header+s[len(prefix):])
print(json.dumps({'operation':'commercial-price-monitor-20261105-read13-approved-manual1','source_hotels':56,'scoped_hotels':len(d['bindings']),'max_rows_per_hotel':2,'old_retained_rows_excluded':85,'source_searches':0,'db_calls':0,'new_cases_prepared':3,'old_fixtures':0}))
