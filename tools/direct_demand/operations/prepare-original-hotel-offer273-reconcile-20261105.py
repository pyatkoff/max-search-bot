import base64,hashlib,json,os,pathlib
t=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
def one(root,name):
 p=list((t/root).rglob(name));assert len(p)==1,(root,name);return p[0]
summary=json.loads(one('original-execution','offer-execution-summary.json').read_text());done=[x for x in summary if x['ok']];failed=[x for x in summary if not x['ok']]
assert len(done)==13 and len(failed)==1 and failed[0]['catalog_id']==273 and failed[0]['error']=='max_read_failed' and failed[0]['post_write_attempts']==1
indices=json.loads(one('prepared-offers','update-index.json').read_text());root=one('prepared-offers','update-index.json').parent
entry=next(x for x in indices if x['hotel_id']==273);folder=root/entry['folder'];raw=(folder/'update-manifest.json').read_bytes();m=json.loads(raw);c=json.loads((folder/'candidate.json').read_text());assert m['operation']==failed[0]['operation'] and hashlib.sha256(raw).hexdigest()==entry['manifest_sha256']
started=[p for p in (t/'original-execution').rglob('update-attempt-started.private.json')];assert len(started)==14 and {int(p.parent.name) for p in started}=={x['catalog_id'] for x in summary}
pending=[x for x in indices if x['hotel_id'] not in {x['catalog_id'] for x in summary}];assert len(pending)==40
d={'native_hotel_id':273,'original_execution_run':41984228461,'original_error':'max_read_failed','original_write_attempts':1,'manifest_sha256':entry['manifest_sha256'],'manifest':m,'canonical_context':c['canonical_context'],'completed_native_ids':[x['catalog_id'] for x in done],'unattempted_native_ids':[x['hotel_id'] for x in pending]}
inputRaw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'original273-reconcile-input.private.json').write_bytes(inputRaw);(t/'original273-reconcile-intent.private.json').write_text(json.dumps({'operation':'commercial-hotel-offer-update-20261105-manual1-h273-v1-reconcile1','original_operation':m['operation'],'original_execution_run':41984228461,'manifest_sha256':entry['manifest_sha256'],'input_sha256':hashlib.sha256(inputRaw).hexdigest(),'max_owned_provider_reads':1,'provider_writes':0,'gateway_calls':0,'other_publications_touched':0,'manual_capture':False,'own_registry_ack_only_after_exact_actual_body_photo_button_proof':True})+'\n')
s=(base/'reconcile-original-hotel-offer273-20261105.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_RECONCILE_INPUT_B64':base64.b64encode(inputRaw).decode(),'APPROVED_RECONCILE_INPUT_SHA256':hashlib.sha256(inputRaw).hexdigest()}
header=prefix+''.join('const '+k+'='+json.dumps(v)+';\n' for k,v in constants.items());(t/'reconcile273.php').write_text(header+s[len(prefix):])
print(json.dumps({'operation':'commercial-hotel-offer-update-20261105-manual1-h273-v1-reconcile1','original_completed_pairs_not_reread':13,'original_single_write_unknown_native':273,'unattempted_pairs_held':40,'max_owned_reads':1,'provider_writes':0,'new_posts':0,'gateway_calls':0}))
