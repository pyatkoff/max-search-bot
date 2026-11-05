import base64,hashlib,json,os,pathlib
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 ps=list((t/folder).rglob(name));assert len(ps)==1,(folder,name);return ps[0]
rawInput=one('original-context18','context18-input.private.json').read_bytes();rawOutput=one('original-context18','context18-readback.private.json').read_bytes()
inp=json.loads(rawInput);d=json.loads(rawOutput.decode().splitlines()[-1]);scope=json.loads((b/'commercial-hotel-search-links-20261105-context18-ten2-scope.json').read_text())
reader=json.loads((t/'context18-original-reader.json').read_text());preparer=json.loads((t/'context18-original-preparer.json').read_text())
src=base64.b64decode(reader['content']).decode();pre=base64.b64decode(preparer['content']).decode()
assert hashlib.sha256(src.encode()).hexdigest()=='d688a5fbe2d33a931a1c8386968c5a6a2eaaafb8d0420f0953b5ed8faae94570' and hashlib.sha256(pre.encode()).hexdigest()=='abb8984611cb34464bef3d5c4be00d76ea7c7e2879f3bc4c0a92b420ed165d78'
bad="$out['input_sha256']=APPROVED_CONTEXT17_INPUT_SHA256;"
assert src.count(bad)==1 and src.count('APPROVED_CONTEXT17_INPUT_SHA256')==1 and "'APPROVED_CONTEXT17_INPUT_SHA256'" not in pre and "'APPROVED_CONTEXT18_INPUT_SHA256':hashlib.sha256(raw).hexdigest()" in pre
tail=src[src.index("if(!$shared)$pdo->rollBack();$catalog->rollBack();"):]
assert tail.startswith("if(!$shared)$pdo->rollBack();$catalog->rollBack();$out['canonical_source_receipts_read']=$GLOBALS['context13_receipt_reads']??0;$out['captured_epoch_utc']=time();$out['installed_sha']=EXPECTED_INSTALLED_SHA;"+bad+"$out['deferred_before_server']=$d['deferred_before_server'];$out['quote_state']='NOQUOTE';$out['ok']=true;")
marker=json.loads((t/'context18-final-metadata-native-proof.private.json').read_text());ih=hashlib.sha256(rawInput).hexdigest();assert marker=={'original_error_reproduced':True,'corrected_input_sha256':ih}
assert d['ok'] is False and d['error']=='noquote_current_context_read_failed' and d['operation']==scope['operation']==inp['operation']=='commercial-hotel-search-links-20261105-context18-ten2'
assert d['installed_sha']=='1fec9202b45a8e1a6343750a8896a6e5072e1880' and d['captured_epoch_utc']>0 and 'input_sha256' not in d and 'quote_state' not in d and 'deferred_before_server' not in d
assert d['db_writes']==d['gateway_calls']==d['message_sends']==d['price_edits']==0 and d['provider_post_reads']==10 and d['provider_channel_rights_checks']==2 and d['canonical_source_receipts_read']==20 and d['deferred']==[] and inp['deferred_before_server']==[]
ids=scope['native_hotel_ids'];assert ids==[265,269,272,275,282,287,315,328,358,382] and inp['scope_native_ids']==ids and inp['quote_state']=='NOQUOTE' and len(inp['items'])==len(d['bindings'])==len(d['owned_max_context_proofs'])==10
items={x['hotel']['local_tv_id']:x for x in inp['items']};proofs={x['native_hotel_id']:x for x in d['owned_max_context_proofs']};assert set(items)==set(proofs)=={x['hotel']['local_tv_id'] for x in d['bindings']}==set(ids)
for x in d['bindings']:
 h=x['hotel'];hid=h['local_tv_id'];item=items[hid];f=x['canonical_context'];v=f['binding'];proof=proofs[hid]
 assert h==item['hotel'] and h in scope['native_bindings'] and item['quote_state']=='NOQUOTE' and f['capture_operation']==d['operation'] and f['capture_run']==42012518360 and f['capture_source_sha']==d['installed_sha'] and f['capture_input_sha256']==ih and 0<f['capture_epoch_utc']<=d['captured_epoch_utc']
 publisher=h['anytour_id'] if h['identity_provenance']=='accepted_typed_bridge' else hid
 assert v['native_hotel_id']==hid and v['expected_country_id']==h['expected_country_id'] and v['identity_provenance']==h['identity_provenance'] and v['publisher_hotel_id']==publisher and v['original_role_proof_kind']=='original_publisher_and_latest_confirmed_caption' and v['quote_state']=='NOQUOTE' and v['search_presets']==inp['presets']==scope['presets']
 assert hashlib.sha256(v['caption'].encode()).hexdigest()==v['caption_sha256'] and len([q for q in item['caption_variants'] if q['caption']==v['caption'] and q['caption_sha256']==v['caption_sha256']])==1
 for platform in ['max','telegram']:
  c=v['contexts'][platform];assert c['logical_id']==v['logical_id'] and c['old_text']==v['caption'] and c['old_text_sha256']==v['caption_sha256'] and c['original_publication_hotel_id']==publisher and c['publication_id']>0 and c['version_id']>0
  assert c['source_key']=={'max':'max_anytour_msk1','telegram':'tg_anytour_msk'}[platform]
  assert c['canonical_proof_kind'].endswith('_NEW_RELATED_OWNED_MAX_BODY_PHOTO_ONLYBUTTON_PROOF' if platform=='max' else '_EXACT_CURRENT_TG_CONFIRMED_TRANSPORT_PROOF')
 c=v['contexts']['max'];snap=proof['snapshot']
 assert proof['publication_id']==c['publication_id'] and proof['version_id']==c['version_id'] and proof['message_id']==c['message_id'] and proof['caption_sha256']==c['old_text_sha256'] and proof['photo_signature']==c['photo_signature'] and proof['button_url_sha256']==c['button_url_sha256'] and snap['text']==c['old_text'] and snap['post_url']==c['post_url']
 at=snap['attachments'];assert len(at)==1 and at[0]['type']=='image';photo=at[0].get('photo_id',at[0].get('file_unique_id',''));assert photo and at[0].get('provider','')+':'+str(photo)==c['photo_signature']
assert src.count("$out['installed_sha']=EXPECTED_INSTALLED_SHA;")==1 and src.count("$out['captured_epoch_utc']=time();")==1
admitted={'operation':d['operation'],'state':'ORIGINAL_ALL10_CANONICAL_BINDINGS_ADMITTED_OFFLINE_AFTER_FINAL_METADATA_UNDEFINED_CONSTANT','original_run':42012518360,'original_job':127194829064,'original_artifact':13406909136,'original_readback_ok':False,'original_error':d['error'],'original_readback_sha256':hashlib.sha256(rawOutput).hexdigest(),'original_input_sha256':ih,'original_provider_post_reads':10,'provider_calls_in_offline_validation':0,'db_calls_in_offline_validation':0,'quote_state':'NOQUOTE','bindings':d['bindings'],'deferred':[],'deferred_before_server':[]}
(t/'context18-admitted-bindings.private.json').write_text(json.dumps(admitted,ensure_ascii=False,separators=(',',':'))+'\n')
safe={'operation':'commercial-hotel-search-links-20261105-context18-original-final-metadata-offline1','state':'ORIGINAL10_CONTEXT_BINDINGS_PROVEN_AFTER_EXACT_FINAL_METADATA_CONSTANT_ERROR_NO_READ_REPLAY','original_run':42012518360,'original_job':127194829064,'original_artifact':13406909136,'original_ok':False,'original_error':'noquote_current_context_read_failed','root_cause':'Undefined constant APPROVED_CONTEXT17_INPUT_SHA256 after all10bindings and both readonly rollbacks','bound_native_ids':ids,'bound_hotels':10,'original_owned_MAX_reads':10,'owned_MAX_reads_repeated':0,'original_canonical_receipts':20,'original_source_sha':d['installed_sha'],'native_new_metadata_cases':2,'old_fixtures':0,'provider_calls':0,'db_calls':0,'provider_writes':0,'gateway_calls':0}
(t/'context18-offline-proof-safe.json').write_text(json.dumps(safe)+'\n');print(json.dumps(safe))
