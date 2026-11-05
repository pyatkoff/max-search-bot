import base64,collections,hashlib,json,os,pathlib,re
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((temp/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
d=json.loads(one('read13','price-readback.json').read_text().splitlines()[-1]);assert d['operation']=='commercial-price-monitor-20261105-read13-approved-manual1' and d['ok'] and d['installed_sha']=='627a9c6284624e63b24287bea61277ef6c8f5364' and len(d['hotels'])==56
legacy=json.loads(one('legacy-frames','legacy9-canonical-frames.private.json').read_text());legacy={int(f['binding']['native_hotel_id']):f for f in legacy if int(f['binding']['native_hotel_id']) in {240,381,46672}}
assert set(legacy)=={240,381,46672}
names=["commercial-batch-20261102-10-manifest.json","commercial-batch-20261102-11-manifest.json","commercial-batch-20261102-12-manifest.json","commercial-batch-20261102-13-manifest.json","commercial-batch-20261102-14-manifest.json","commercial-batch-20261102-9-manifest.json","commercial-batch-20261103-15-manifest.json","commercial-batch-20261103-16-manifest.json","commercial-batch-20261103-17-manifest.json","commercial-batch-20261103-18-manifest.json","commercial-batch-20261103-19-manifest.json","commercial-batch-20261103-20-manifest.json","commercial-batch-20261103-21-manifest.json","commercial-batch-20261103-22-manifest.json","commercial-batch-20261103-23-manifest.json","commercial-batch-20261103-24-manifest.json","commercial-batch-20261103-25-manifest.json","commercial-batch-20261103-26-manifest.json","commercial-batch-20261103-27-manifest.json","commercial-batch-20261103-28-manifest.json","commercial-batch-20261103-29-manifest.json","commercial-batch-20261103-30-manifest.json","commercial-batch-20261103-31-manifest.json","commercial-batch-20261103-32-manifest.json","commercial-batch-20261103-33-manifest.json","commercial-batch-20261103-34-manifest.json","commercial-batch-20261104-35-manifest.json","commercial-batch-20261104-36-manifest.json","commercial-batch-20261104-37-manifest.json","commercial-batch-20261104-38-manifest.json","commercial-batch-20261104-43-manifest.json","commercial-batch-20261105-49-manifest.json","commercial-batch-20261105-51-manifest.json","commercial-final-two-manifest.json","commercial-four-update-manifest.json","commercial-human-copy-early-nine-manifest.json","commercial-human-copy-three-manifest.json","commercial-next-three-manifest.json","commercial-three-20261102-2-manifest.json","commercial-three-20261102-3-manifest.json","commercial-three-20261102-4-manifest.json","commercial-three-20261102-5-manifest.json","commercial-three-20261102-6-manifest.json","commercial-three-20261102-7-manifest.json","commercial-three-20261102-8-manifest.json"]
publishers=collections.defaultdict(list);copies=collections.defaultdict(list)
for name in names:
 path=base/name;m=json.loads(path.read_text())
 for row in m.get('rows',[]):
  if not isinstance(row,dict):continue
  if all(k in row for k in ('hotel_id','logical_id','text','text_sha256','photo_sha256')):
   assert hashlib.sha256(row['text'].encode()).hexdigest()==row['text_sha256'];publishers[int(row['hotel_id'])].append((m,row,name))
  elif all(k in row for k in ('logical_id','new_text','new_text_sha256')):
   assert hashlib.sha256(row['new_text'].encode()).hexdigest()==row['new_text_sha256'];copies[row['logical_id']].append((m,row,name))
items=[];deferred=[];ops=set()
for h in d['hotels']:
 if not h['observations']:continue
 hid=int(h['local_tv_id'])
 if hid in legacy:
  f=legacy[hid];b=f['binding'];assert int(b['expected_country_id'])==int(h['expected_country_id']) and b['identity_provenance']==h['identity_provenance']
  items.append({'hotel':h,'kind':'accepted_legacy_frame_exact_current_refresh','prior_frame':f});continue
 publisher_id=int(h['anytour_id']) if h['identity_provenance']=='accepted_typed_bridge' else hid
 matches=publishers.get(publisher_id,[])
 unique={(row['logical_id'],row['text_sha256'],row['photo_sha256']): (m,row,name) for m,row,name in matches}
 if len(unique)!=1:deferred.append({'native_hotel_id':hid,'reason':'ORIGINAL_PUBLISHER_MANIFEST_NOT_UNIQUE_OR_MISSING','matching_original_publishers':len(unique)});continue
 m,row,name=next(iter(unique.values()));ops.add(m['operation'])
 variants=[{'caption':row['text'],'caption_sha256':row['text_sha256'],'source_kind':'original','source_operation':m['operation']}]
 for cm,cr,cn in copies.get(row['logical_id'],[]):
  raw=json.dumps(cm,ensure_ascii=False,separators=(',',':')).encode();ops.add(cm['operation'])
  variants.append({'caption':cr['new_text'],'caption_sha256':cr['new_text_sha256'],'source_kind':'confirmed_copy','source_operation':cm['operation'],'source_key':cr['key'],'source_manifest_hash':hashlib.sha256(raw).hexdigest()})
 items.append({'hotel':h,'kind':'original_publisher_and_latest_confirmed_caption','publisher_hotel_id':publisher_id,'original_publication_operation':m['operation'],'original_manifest_row':row,'original_manifest_path':name,'caption_variants':variants})
assert len(items)+len(deferred)==56
out={'operation':'commercial-price-monitor-20261105-context13-approved-manual1','price_read13_run':41978466494,'price_read13_artifact':13393205782,'source_run':41976677950,'items':items,'deferred_before_server':deferred,'allowed_source_operations':sorted(ops),'max_hotels':56}
raw=(json.dumps(out,ensure_ascii=False,separators=(',',':'))+'\n').encode();(temp/'context13-input.private.json').write_bytes(raw)
source=(base/'read-current-approved-price-contexts-20261105-context13.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
header=prefix+''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in constants.items());(temp/'context13.php').write_text(header+source[len(prefix):])
print(json.dumps({'operation':out['operation'],'actual_new_source_hotels':56,'prepared_context_hotels':len(items),'deferred':deferred,'only_current_whitelisted_manifest_files':len(names),'legacy_frame_reuse_only_new_related_tuples':3,'provider_calls':0,'db_calls':0,'old_fixtures':0}))
