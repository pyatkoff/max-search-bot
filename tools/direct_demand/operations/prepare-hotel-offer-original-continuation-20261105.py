import base64,hashlib,json,os,pathlib
t=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
def one(folder,name):
 p=list((t/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
original=json.loads(one('original-execution','offer-execution-summary.json').read_text());done=[x for x in original if x['ok']];bad=[x for x in original if not x['ok']]
assert len(done)==13 and len(bad)==1 and bad[0]['catalog_id']==273 and bad[0]['error']=='max_read_failed' and bad[0]['post_write_attempts']==1
witness=json.loads(one('original-reconciliation','original273-reconcile-result.private.json').read_text().splitlines()[-1]);assert witness['ok'] and witness['max_original_write_confirmed'] and witness['telegram_not_attempted'] and witness['provider_write_requests']==witness['gateway_calls']==0
witness.update(native_hotel_id=273,original_execution_run=41984228461,reconciliation_run=41986606018)
root=one('prepared-offers','update-index.json').parent;index=json.loads((root/'update-index.json').read_text());assert len(index)==54
doneIDs={x['catalog_id'] for x in done};attempted={x['catalog_id'] for x in original};pending=[x for x in index if x['hotel_id'] not in attempted];assert len(pending)==40
started=list((t/'original-execution').rglob('update-attempt-started.private.json'));assert len(started)==14 and {int(p.parent.name) for p in started}==attempted
subset=[next(x for x in index if x['hotel_id']==273)]+pending;assert len(subset)==41 and not doneIDs&{x['hotel_id'] for x in subset}
frozen=(base/'update-current-hotel-offers-20261105-manual1.php').read_bytes();assert hashlib.sha256(frozen).hexdigest()=='cc3e04f24e93178ef1ffa773fa9636abe4e2220b0c189802b19adbbf72cad246'
resume=(base/'continue-reconciled-hotel-offer273-20261105.php').read_bytes();assert hashlib.sha256(resume).hexdigest()=='eb814824477a0ada4e365519c0c84f6e2fba2fe934c8ff754bd47ac89f933976'
manifestRaw=(base/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes();assert hashlib.sha256(manifestRaw).hexdigest()=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361';identities=json.loads(manifestRaw)['native_bindings']
sha=os.environ['EXPECTED_PRODUCTION_SHA'];assert sha=='95dcf9893fb161b1e4fb52c02ad2d982604e7518';frozenSha='8f209b1ac93090ab399c1b8225151849fceb4fd0';intents=[]
for entry in subset:
 hid=int(entry['hotel_id']);assert entry['folder']==str(hid);folder=root/entry['folder'];raw=(folder/'update-manifest.json').read_bytes();m=json.loads(raw);a=json.loads((folder/'update-approval.json').read_text());chosen=json.loads((folder/'candidate.json').read_text());f=chosen['canonical_context'];b=f['binding'];fr=json.dumps(f,ensure_ascii=False,separators=(',',':')).encode();frameSHA=hashlib.sha256(fr).hexdigest()
 assert a['expected_installed_sha']==frozenSha and set(a)=={'schema_version','operation','expected_installed_sha','hotel_ids','manifest_sha256'} and a['hotel_ids']==[hid] and a['operation']==m['operation']==entry['operation'] and hashlib.sha256(raw).hexdigest()==a['manifest_sha256']==entry['manifest_sha256'] and m['rows'][0]['legacy_context_sha256']==frameSHA==chosen['canonical_context_sha256']
 row=m['rows'][0];constants={'EXPECTED_INSTALLED_SHA':frozenSha,'APPROVED_MANIFEST_SHA256':a['manifest_sha256'],'MANIFEST_BASE64':base64.b64encode(raw).decode(),'VERIFIED_QUOTE_RESPONSE_SHA256':m['quote_response_sha256'],'VERIFIED_QUOTE_EPOCH':m['quote_epoch_utc'],'VERIFIED_QUOTE_INDEX':m['quote_index'],'APPROVED_UPDATE_HOTEL_ID':hid,'APPROVED_UPDATE_KEY_B64':base64.b64encode(b['key'].encode()).decode(),'APPROVED_UPDATE_LOGICAL_B64':base64.b64encode(b['logical_id'].encode()).decode(),'APPROVED_UPDATE_OPERATION':m['operation'],'APPROVED_LEGACY_CONTEXT_B64':base64.b64encode(fr).decode(),'APPROVED_LEGACY_CONTEXT_SHA256':frameSHA,'APPROVED_IDENTITY_BINDINGS_B64':base64.b64encode(json.dumps(identities,ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_SEARCH_URL_B64':base64.b64encode(row['search_link_url'].encode()).decode()}
 prefix=b"<?php\ndeclare(strict_types=1);\n"
 def script(template,values):return prefix+b''.join(('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n').encode() for k,v in values.items())+template[len(prefix):]
 assert (folder/'update.php').read_bytes()==script(frozen,constants),'FROZEN_ORIGINAL_SCRIPT_CHANGED'
 constants['EXPECTED_INSTALLED_SHA']=sha
 if hid==273:constants['APPROVED_ORIGINAL273_WITNESS_B64']=base64.b64encode(json.dumps(witness,separators=(',',':')).encode()).decode()
 execution=script(resume if hid==273 else frozen,constants);(folder/'continuation.php').write_bytes(execution)
 entry['expected_new_content_attempts']=1 if hid==273 else 2;entry['completed_original_max_must_not_be_reread_or_written']=hid==273
 intents.append({'operation':m['operation'],'state':'ORIGINAL273_TG_ONLY_OR_PREVIOUSLY_UNATTEMPTED_PAIR_INTENT_BEFORE_PROVIDER','hotel_id':hid,'manifest_sha256':a['manifest_sha256'],'expected_source':sha,'new_content_edit_limit':entry['expected_new_content_attempts'],'original_execution_run':41984228461,'original273_reconciliation_run':41986606018,'new_posts':0,'additional_gateway_calls':0})
(root/'continuation-index.json').write_text(json.dumps(subset)+'\n');(t/'offer-continuation-root.txt').write_text(str(root)+'\n');(t/'offer-continuation-intents.private.json').write_text(json.dumps(intents)+'\n');(t/'original-completed13-safe.json').write_text(json.dumps(done)+'\n')
proof={'operation':'commercial-hotel-offer-update-20261105-manual1-pass1-continuation1','state':'FROZEN_ORIGINAL_273_TG_ONLY_AND40_UNATTEMPTED_PAIRS_BOUND','original_completed_pairs_excluded':13,'original_max273_confirmed_not_repeated':True,'unattempted_pairs':40,'continuation_hotels':41,'max_new_content_edits':81,'new_posts':0,'additional_gateway_calls':0,'old_fixtures_rerun':0,'native_new_resume_witness_cases':3}
(t/'offer-continuation-binding-safe.json').write_text(json.dumps(proof)+'\n');print(json.dumps(proof))
