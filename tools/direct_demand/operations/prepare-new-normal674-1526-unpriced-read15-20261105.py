import base64,copy,hashlib,json,os,pathlib,re
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
def sha(raw):return hashlib.sha256(raw).hexdigest()
scope_raw=(b/os.environ['PRICE15_SCOPE_FILENAME']).read_bytes();assert sha(scope_raw)==os.environ['EXPECTED_PRICE15_SCOPE_SHA256'];p=json.loads(scope_raw)
assert p['schema']==1 and p['source']=='scheduled_monitor' and p['source_run']==42054958159 and p['source_job']==127340818871 and p['lower_epoch']<p['upper_epoch']
manifest=(b/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes();accepted=(b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_bytes();stock_raw=(b/'commercial-stock-20261105-41-native-projection.json').read_bytes()
assert sha(manifest)==p['public_hashes']['manifest'] and sha(accepted)==p['public_hashes']['acceptance'] and sha(stock_raw)==p['public_hashes']['stock']
m=json.loads(manifest);a=json.loads(accepted);stock=json.loads(stock_raw)
def bindings15(m,a,stock,p):
 assert a['price_hotel_ids']==p['priced_ids'] and len(set(a['price_hotel_ids']))==p['priced_count'] and a['logical_hotels_published_per_channel']==p['published_pairs']
 assert len(m['native_bindings'])==p['original_manifest_count']
 new=[]
 for hid,sender,binding in [(1422,41961272340,41961641655),(78251,41969870445,41970261735)]:
  rows=[x for x in stock['new_native_identities'] if int(x['hotel_id'])==hid];assert len(rows)==1
  r=rows[0];assert r['country_id']==4 and r['is_active']==1 and r['source_run']==41956089070
  new.append({'local_tv_id':hid,'expected_name':r['hotel'],'expected_country_id':4,'identity_provenance':'accepted_native_catalog_read','anytour_id':None,'identity_source_run':41956089070,'identity_source_artifact':13383226155,'actual_publication_send_run':sender,'canonical_binding_run':binding})
 assert new==p['new_admissions']
 bindings=[x for x in m['native_bindings']+new if int(x['local_tv_id']) not in set(p['priced_ids'])|set(p['review_ids'])]
 ids=[int(x['local_tv_id']) for x in bindings]
 assert ids==p['native_ids'] and len(ids)==len(set(ids))==p['scope_count']
 return bindings
def one(folder,name):
 files=list((t/folder).rglob(name));assert len(files)==1,(folder,name);return files[0]
source=json.loads(one('old-source-scope','approved-source-new-scope.private.json').read_text())
manual=json.loads(one('manual-read13','price-readback.json').read_text().splitlines()[-1])
previous=json.loads(one('normal-read14','price14-readback.private.json').read_text().splitlines()[-1])
def exclusions15(source,manual,previous):
 assert source['old_retained_rows']==85 and source['source_run']==41976677950
 assert manual['ok'] and len(manual['hotels'])==56
 manual_fps=[o['retained_tour_fingerprint'] for h in manual['hotels'] for o in h['observations']];assert len(manual_fps)==104
 old=sorted(set(source['old_retained_fingerprints'])|set(manual_fps));assert len(old)==p['old_unique_fingerprints']
 assert previous['ok'] and previous['source_run']==41982177052 and previous['readback_epoch_utc']==1793866709 and len(previous['hotels'])==74
 added=[o['retained_tour_fingerprint'] for h in previous['hotels'] for o in h['observations']];assert len(added)==p['read14_retained_rows']
 assert all(isinstance(x,str) and re.fullmatch('[0-9a-f]{64}',x) for x in old+added)
 return old,added,sorted(set(old)|set(added))
bindings=bindings15(m,a,stock,p);old,added,blocked=exclusions15(source,manual,previous)
# Only these new actual-input negatives; no old fixtures or source/provider work.
bad=copy.deepcopy(p);bad['native_ids'][0]=1422
try:bindings15(m,a,stock,bad);raise RuntimeError('new_already_priced_scope_admitted')
except AssertionError:pass
bad=copy.deepcopy(a);bad['price_hotel_ids'].remove(1422)
try:bindings15(m,bad,stock,p);raise RuntimeError('new_priced_admission_removal_admitted')
except AssertionError:pass
bad=copy.deepcopy(previous)
for h in bad['hotels']:
 if h['observations']:h['observations'].pop();break
try:exclusions15(source,manual,bad);raise RuntimeError('new_read14_consumed_row_omitted')
except AssertionError:pass
d={k:p[k] for k in ['operation','source_run','source_job','lower_epoch','upper_epoch','priced_ids']}
d.update(bindings=bindings,old_blocked_fingerprints=old,read14_retained_fingerprints=added,blocked_fingerprints=blocked)
raw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'price15-input.private.json').write_bytes(raw)
s=(b/'read-new-normal674-1526-unpriced-20261105-read15.php').read_text();prefix='<?php\ndeclare(strict_types=1);\n';assert s.startswith(prefix)
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_PRICE15_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_PRICE15_INPUT_SHA256':sha(raw),'APPROVED_PRICE15_PLAN_B64':base64.b64encode(scope_raw).decode(),'APPROVED_PRICE15_PLAN_SHA256':sha(scope_raw),'APPROVED_PRICE15_MANIFEST_B64':base64.b64encode(manifest).decode(),'APPROVED_PRICE15_MANIFEST_SHA256':sha(manifest),'APPROVED_PRICE15_BLOCKED_SHA256':sha(json.dumps(blocked,separators=(',',':')).encode())}
refs=set(re.findall(r'\b(?:APPROVED_[A-Z0-9_]+|EXPECTED_INSTALLED_SHA)\b',s));assert refs==set(v),'UNDECLARED_PRICE15_HEADER_CONSTANT'
script=prefix+''.join('const '+k+'='+json.dumps(value,ensure_ascii=False)+';\n' for k,value in v.items())+s[len(prefix):]
(t/'price15.php').write_text(script)
print(json.dumps({'operation':p['operation'],'source_run':p['source_run'],'scope_count':len(bindings),'priced_excluded':len(p['priced_ids']),'old_unique_excluded':len(old),'read14_rows_excluded':len(added),'computed_unique_excluded':len(blocked),'new_python_cases':3,'old_fixtures':0,'db_calls':0,'provider_calls':0}))
