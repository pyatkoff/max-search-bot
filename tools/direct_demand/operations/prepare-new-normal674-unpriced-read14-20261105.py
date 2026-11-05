import base64,hashlib,json,os,pathlib
b=pathlib.Path('tools/direct_demand/operations');t=pathlib.Path(os.environ['RUNNER_TEMP'])
def one(folder,name):
 p=list((t/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
manifest=(b/'commercial-price-source-refresh-20261105-pass1-manifest.json').read_bytes();assert hashlib.sha256(manifest).hexdigest()=='2035f0382b815f721a42dfe4a0045f75b0e1db9f0d16891ed0db8ac1081b0361';m=json.loads(manifest);assert len(m['native_bindings'])==121
accepted=json.loads((b/'commercial-hotel-materials-20261105-161-acceptance-state.json').read_text());priced=accepted['price_hotel_ids'];assert len(priced)==len(set(priced))==81 and accepted['logical_hotels_published_per_channel']==161
stock=json.loads((b/'commercial-stock-20261105-41-native-projection.json').read_text());new=[]
for hid,sender,binding in [(1422,41961272340,41961641655),(78251,41969870445,41970261735)]:
 rows=[x for x in stock['new_native_identities'] if int(x['hotel_id'])==hid];assert len(rows)==1 and rows[0]['country_id']==4 and rows[0]['is_active']==1 and rows[0]['source_run']==41956089070
 r=rows[0];new.append({'local_tv_id':hid,'expected_name':r['hotel'],'expected_country_id':4,'identity_provenance':'accepted_native_catalog_read','anytour_id':None,'identity_source_run':41956089070,'identity_source_artifact':13383226155,'actual_publication_send_run':sender,'canonical_binding_run':binding})
bindings=[{'binding':x} for x in m['native_bindings'] if int(x['local_tv_id']) not in set(priced)]+[{'binding':x} for x in new];assert len(bindings)==74 and len({x['binding']['local_tv_id'] for x in bindings})==74
source=json.loads(one('old-source-scope','approved-source-new-scope.private.json').read_text());assert source['old_retained_rows']==85 and source['source_run']==41976677950
old=json.loads(one('manual-read13','price-readback.json').read_text().splitlines()[-1]);assert old['ok'] and len(old['hotels'])==56
blocked=set(source['old_retained_fingerprints']);consumed=0
# Anti-replay projection only. No old quotes, canonical post bodies or provider reads.
for h in old['hotels']:
 for o in h['observations']:blocked.add(o['retained_tour_fingerprint']);consumed+=1
assert consumed==104 and 0<len(blocked)<=189;blocked=sorted(blocked)
d={'operation':'commercial-price-monitor-20261105-read14-new674-unpriced1','source_run':41982177052,'source_job':127094628296,'lower_epoch':1793861003,'upper_epoch':1793861528,'old_retained_rows':85,'consumed_manual_rows':104,'bindings':bindings,'blocked_fingerprints':blocked}
raw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'price14-input.private.json').write_bytes(raw)
s=(b/'read-new-normal674-unpriced-20261105-read14.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
v={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_MANIFEST_B64':base64.b64encode(manifest).decode(),'APPROVED_PRICED_IDS_B64':base64.b64encode(json.dumps(priced).encode()).decode(),'APPROVED_NEW_ADMISSIONS_B64':base64.b64encode(json.dumps(new,ensure_ascii=False,separators=(',',':')).encode()).decode(),'APPROVED_BLOCKED_FP_SHA256':hashlib.sha256(json.dumps(blocked,separators=(',',':')).encode()).hexdigest()}
(t/'price14.php').write_text(prefix+''.join('const '+k+'='+json.dumps(x,ensure_ascii=False)+';\n' for k,x in v.items())+s[len(prefix):])
print(json.dumps({'operation':d['operation'],'actual_normal_run':41982177052,'scoped_unpriced_hotels':74,'excluded_already_priced':81,'excluded_name_review':6,'old85_and_manual104_fingerprint_rows_excluded':189,'unique_fingerprints_excluded':len(blocked),'max_rows_per_hotel':2,'window_start':1793861003,'window_end_exclusive':1793861528,'source_searches':0,'provider_calls':0,'db_calls':0,'new_scope_cases':4,'old_fixtures':0}))
