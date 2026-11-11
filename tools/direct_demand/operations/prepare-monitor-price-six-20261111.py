"""Prepare exact existing-message edits from an approved Site handoff and native context.
No supplier, database, feed or messaging writes. Invoked by the existing publisher only.
"""
import base64, hashlib, json, os, pathlib
p=pathlib.Path(__file__).parent
t=pathlib.Path(os.environ['RUNNER_TEMP'])
handoff=json.loads((p/'monitor-price-six-20261111-handoff.json').read_text())
context=json.loads((p/'monitor-price-six-20261111-context.json').read_text())
assert context['ok'] and not context['deferred']
assert context['installed_sha']==os.environ['EXPECTED_PRODUCTION_SHA']
assert context['db_writes']==context['message_writes']==context['supplier_calls']==0
ids=[1183,117935,27426,13869,1151,1173]
assert [r['tourvisor_hotel_id'] for r in context['bindings']]==ids
hotels={int(h['tourvisorHotelId']):h for h in handoff['hotels']}
assert set(hotels)==set(ids)
prefix='<?php\ndeclare(strict_types=1);\n'
contract=(p/'monitor-price-contract.php').read_text();assert contract.startswith(prefix)
editor=(p/'update-commercial-price-20261104-remaining1.php').read_text();assert editor.startswith(prefix)
assert editor.count("require_once __DIR__.'/monitor-price-contract.php';")==1
editor=editor[len(prefix):].replace("require_once __DIR__.'/monitor-price-contract.php';",'')
operations=[]
for r in context['bindings']:
    tv=r['tourvisor_hotel_id'];h=hotels[tv];plan=h['updatePlan'];binding=h['feedBinding'];source=h['source']
    assert h['hotelId']==r['site_hotel_id'] and plan['hotelRevision']==r['site_revision']
    assert h['releaseEligible'] and binding['status']=='bound' and plan['registryVersion']==handoff['version']
    assert source['item']['tourvisorHotelId']==tv and source['item']['state']=='observed'
    minimum=source['item']['offers'][0]
    assert minimum['recordId']==plan['priceRecordId'] and minimum['price']==plan['price'] and minimum['tourId']==plan['tourId']
    assert plan['media']=='preserve_existing' and plan['ctaMode']=='publisher' and plan['ctaUrl']==''
    row={k:r[k] for k in ['key','hotel_id','tourvisor_hotel_id','logical_id','original_publication_operation','cover_sha256']}
    row['channel_texts']={}
    for channel in ['max','telegram']:
        old=r['channels'][channel];new=plan['posts'][channel]
        assert old['post_url']==new['url']==binding['posts'][channel]
        assert hashlib.sha256(old['current_text'].encode()).hexdigest()==old['current_text_sha256']==new['expectedTextSha256']
        row['channel_texts'][channel]={'old_text':old['current_text'],'old_text_sha256':old['current_text_sha256'],'new_text':new['text'],'new_text_sha256':hashlib.sha256(new['text'].encode()).hexdigest(),'original_text_sha256':old['original_text_sha256'],'expected_version_id':old['version_id'],'expected_post_url':old['post_url']}
    feed_binding={'tourvisor_hotel_id':tv,'posts':binding['posts'],'offer_ids':binding['offerIds'],'destinations':binding['destinations']}
    payload={'operation':'commercial-monitor-price-h'+str(tv)+'-'+minimum['recordId'],'price_basis':'scheduled_monitor_result','monitor_snapshot':source,'button_text':'✈️ Узнать стоимость тура','feed_binding':feed_binding,'rows':[row]}
    raw=json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()
    values={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_UPDATE_OPERATION':payload['operation'],'APPROVED_UPDATE_TOURVISOR_ID':tv,'APPROVED_UPDATE_HOTEL_ID':r['hotel_id'],'APPROVED_UPDATE_KEY_B64':base64.b64encode(r['key'].encode()).decode(),'APPROVED_UPDATE_LOGICAL_B64':base64.b64encode(r['logical_id'].encode()).decode(),'APPROVED_MANIFEST_SHA256':hashlib.sha256(raw).hexdigest(),'MANIFEST_BASE64':base64.b64encode(raw).decode()}
    header=''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in values.items())
    (t/f'monitor-{tv}.php').write_text(prefix+header+contract[len(prefix):]+'\n'+editor)
    (t/f'monitor-{tv}-manifest.json').write_bytes(raw)
    operations.append({'tourvisor_hotel_id':tv,'operation':payload['operation'],'manifest_sha256':values['APPROVED_MANIFEST_SHA256']})
(t/'monitor-operations.json').write_text(json.dumps(operations,ensure_ascii=False,indent=2))
print(json.dumps({'prepared_pairs':len(operations),'version':handoff['version'],'supplier_calls':0,'post_writes':0,'feed_writes':0}))
