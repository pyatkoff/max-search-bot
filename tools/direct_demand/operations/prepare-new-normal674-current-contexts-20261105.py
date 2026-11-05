import base64,collections,hashlib,json,os,pathlib
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((t/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
d=json.loads(one('read14','price14-readback.private.json').read_text().splitlines()[-1]);assert d['ok'] and d['operation']=='commercial-price-monitor-20261105-read14-new674-unpriced1' and d['source_run']==41982177052
exact={157359,1291,1582,27316,3405,1422,78251};hs=[h for h in d['hotels'] if h['observations']];assert {int(h['local_tv_id']) for h in hs}==exact and sum(len(h['observations']) for h in hs)==14
ownIDs={1582,27316,3405};priorRoot=one('prior-offers','update-index.json').parent
names=["commercial-batch-20261102-10-manifest.json","commercial-batch-20261102-11-manifest.json","commercial-batch-20261102-12-manifest.json","commercial-batch-20261102-13-manifest.json","commercial-batch-20261102-14-manifest.json","commercial-batch-20261102-9-manifest.json","commercial-batch-20261103-15-manifest.json","commercial-batch-20261103-16-manifest.json","commercial-batch-20261103-17-manifest.json","commercial-batch-20261103-18-manifest.json","commercial-batch-20261103-19-manifest.json","commercial-batch-20261103-20-manifest.json","commercial-batch-20261103-21-manifest.json","commercial-batch-20261103-22-manifest.json","commercial-batch-20261103-23-manifest.json","commercial-batch-20261103-24-manifest.json","commercial-batch-20261103-25-manifest.json","commercial-batch-20261103-26-manifest.json","commercial-batch-20261103-27-manifest.json","commercial-batch-20261103-28-manifest.json","commercial-batch-20261103-29-manifest.json","commercial-batch-20261103-30-manifest.json","commercial-batch-20261103-31-manifest.json","commercial-batch-20261103-32-manifest.json","commercial-batch-20261103-33-manifest.json","commercial-batch-20261103-34-manifest.json","commercial-batch-20261104-35-manifest.json","commercial-batch-20261104-36-manifest.json","commercial-batch-20261104-37-manifest.json","commercial-batch-20261104-38-manifest.json","commercial-batch-20261104-43-manifest.json","commercial-batch-20261105-49-manifest.json","commercial-batch-20261105-51-manifest.json","commercial-final-two-manifest.json","commercial-four-update-manifest.json","commercial-human-copy-early-nine-manifest.json","commercial-human-copy-three-manifest.json","commercial-next-three-manifest.json","commercial-three-20261102-2-manifest.json","commercial-three-20261102-3-manifest.json","commercial-three-20261102-4-manifest.json","commercial-three-20261102-5-manifest.json","commercial-three-20261102-6-manifest.json","commercial-three-20261102-7-manifest.json","commercial-three-20261102-8-manifest.json"]
needed={int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else int(h['local_tv_id']) for h in hs if int(h['local_tv_id']) not in ownIDs};pub=collections.defaultdict(list);manifests={}
for name in names:
 m=json.loads((b/name).read_text());manifests[name]=m
 for r in m.get('rows',[]):
  if isinstance(r,dict) and all(k in r for k in ('hotel_id','logical_id','text','text_sha256','photo_sha256')) and int(r['hotel_id']) in needed:
   assert hashlib.sha256(r['text'].encode()).hexdigest()==r['text_sha256'];pub[int(r['hotel_id'])].append((m,r,name))
items=[];deferred=[];ops=set();originals=[]
for h in hs:
 hid=int(h['local_tv_id']);expected=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 if hid in ownIDs:
  raw=(priorRoot/str(hid)/'update-manifest.json').read_bytes();m=json.loads(raw);r=m['rows'][0];assert len(m['rows'])==1 and m['operation']=='commercial-hotel-offer-update-20261105-manual1-h'+str(hid)+'-v1' and m['update_kind']=='search_link_only' and not m['price_total_verified'] and r['hotel_id']==hid and r['original_publication_hotel_id']==expected
  assert hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256'];base=r['old_text'].rstrip('\r\n');trail=r['old_text'][len(base):];before,cta=base.rsplit('\n',1);assert r['new_text']==before+'\n\n'+r['added_text']+'\n\n'+cta+trail and '💰' not in r['added_text']
  mh=hashlib.sha256(json.dumps(m,ensure_ascii=False,separators=(',',':')).encode()).hexdigest();ops.add(m['operation'])
  items.append({'hotel':h,'kind':'current_confirmed_own_offer_manifest_and_receipt','publisher_hotel_id':expected,'owned_offer_payload':m,'owned_offer_manifest_sha256':hashlib.sha256(raw).hexdigest(),'source_manifest_hash':mh});continue
 unique={(r['logical_id'],r['text_sha256'],r['photo_sha256']):(m,r,name) for m,r,name in pub.get(expected,[])}
 if len(unique)!=1:deferred.append({'native_hotel_id':hid,'reason':'ORIGINAL_PUBLISHER_MANIFEST_NOT_UNIQUE_OR_MISSING','matching_original_publishers':len(unique)});continue
 m,r,name=next(iter(unique.values()));row={k:r[k] for k in ('hotel_id','logical_id','text','text_sha256','photo_sha256')};ops.add(m['operation'])
 variants=[{'caption':r['text'],'caption_sha256':r['text_sha256'],'source_kind':'original','source_operation':m['operation']}]
 for cn,cm in manifests.items():
  for cr in cm.get('rows',[]):
   if isinstance(cr,dict) and cr.get('logical_id')==r['logical_id'] and all(k in cr for k in ('new_text','new_text_sha256','key')):
    assert hashlib.sha256(cr['new_text'].encode()).hexdigest()==cr['new_text_sha256'];ops.add(cm['operation']);mh=hashlib.sha256(json.dumps(cm,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
    variants.append({'caption':cr['new_text'],'caption_sha256':cr['new_text_sha256'],'source_kind':'confirmed_copy','source_operation':cm['operation'],'source_key':cr['key'],'source_manifest_hash':mh})
 items.append({'hotel':h,'kind':'original_publisher_and_latest_confirmed_caption','publisher_hotel_id':expected,'original_publication_operation':m['operation'],'original_manifest_row':row,'original_manifest_path':name,'caption_variants':variants})
assert len(items)+len(deferred)==7 and len({int(x['hotel']['local_tv_id']) for x in items})==len(items)
out={'operation':'commercial-price-monitor-20261105-context16-new674-seven','source_run':41982177052,'price_read14_run':41993389158,'price_read14_artifact':13398688427,'items':items,'deferred_before_server':deferred,'allowed_source_operations':sorted(ops),'max_hotels':7}
raw=(json.dumps(out,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'context16-input.private.json').write_bytes(raw)
s=(b/'read-new-normal674-current-contexts-20261105-context16.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT16_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT16_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
(t/'context16.php').write_text(prefix+''.join('const '+k+'='+json.dumps(x,ensure_ascii=False)+';\n' for k,x in v.items())+s[len(prefix):])
print(json.dumps({'operation':out['operation'],'actual_new_normal_hotels':7,'prepared_context_hotels':len(items),'prepared_current_owned_offer_contexts':sum(x['kind']=='current_confirmed_own_offer_manifest_and_receipt' for x in items),'deferred':deferred,'whitelisted_manifest_files':len(names),'historical_private_id_fields_projected':0,'old_quote_bodies_read':0,'provider_calls':0,'db_calls':0,'new_scope_cases':3,'old_fixtures':0}))
