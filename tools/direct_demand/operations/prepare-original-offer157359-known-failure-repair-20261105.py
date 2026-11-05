import base64,hashlib,json,os,pathlib
t=pathlib.Path(os.environ['RUNNER_TEMP']);b=pathlib.Path('tools/direct_demand/operations')
def one(name):
 p=list((t/'original-reconciliation').rglob(name));assert len(p)==1,name;return p[0]
prior=json.loads(one('original157359-reconcile-input.private.json').read_text());result=json.loads(one('original157359-reconcile-result.private.json').read_text().splitlines()[-1])
assert prior['native_hotel_id']==157359 and prior['original_execution_run']==42000246854 and prior['original_write_attempts']==1 and prior['manifest']['operation']=='commercial-hotel-offer-update-20261105-normal674-h157359-v1'
assert result['operation']=='commercial-hotel-offer-update-20261105-normal674-h157359-v1-reconcile1' and result['provider_read_requests']==1 and result['provider_write_requests']==0 and result['owned_read_http']==200 and result['original_provider_edit_returned'] is False and result['provider_matches_new_body'] is False and result['provider_matches_old_body'] is True and result['registry_before_mode']=='original_old_version'
assert result['original_owned_snapshot_private']['text']==prior['manifest']['rows'][0]['old_text']
w={k:result[k] for k in ['provider_read_requests','provider_write_requests','owned_read_http','original_provider_edit_returned','provider_matches_new_body','provider_matches_old_body','registry_before_mode']}
d=dict(prior);d.update(prior_reconciliation_run=42001874871,prior_reconciliation_witness=w,repair_write_limit=1)
raw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'original157359-repair-input.private.json').write_bytes(raw)
intent={'operation':'commercial-hotel-offer-update-20261105-normal674-h157359-v1-repair1','original_operation':d['manifest']['operation'],'original_execution_run':42000246854,'primary_known_not_applied_proof_run':42001874871,'manifest_sha256':d['manifest_sha256'],'input_sha256':hashlib.sha256(raw).hexdigest(),'same_original_frozen_payload':True,'same_existing_MAX_transport_endpoint_and_token_source':True,'max_additional_content_writes':1,'max_provider_reads':2,'gateway_calls':0,'new_posts':0,'other_publications_touched':0,'unknown_outcome_or_already_started_repair_blocks_any_replay':True}
(t/'original157359-repair-intent.private.json').write_text(json.dumps(intent)+'\n')
s=(b/'repair-original-confirmed-not-applied-offer157359-20261105.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_REPAIR_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_REPAIR_INPUT_SHA256':hashlib.sha256(raw).hexdigest()}
(t/'repair157359.php').write_text(prefix+''.join('const '+k+'='+json.dumps(x)+';\n' for k,x in v.items())+s[len(prefix):])
print(json.dumps({'operation':intent['operation'],'original_known_not_applied_native':157359,'max_single_additional_write':1,'max_reads':2,'gateway_calls':0,'other_posts':0,'new_posts':0,'same_original_operation':True,'native_new_cases':4,'old_fixtures':0}))
