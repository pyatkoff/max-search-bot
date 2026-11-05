import base64,hashlib,json,os,pathlib
t=pathlib.Path(os.environ['RUNNER_TEMP']);b=pathlib.Path('tools/direct_demand/operations')
def one(folder,name):
 p=list((t/folder).rglob(name));assert len(p)==1,(folder,name);return p[0]
r=json.loads(one('original-read13','price-readback.json').read_text().splitlines()[-1]);assert r['ok'] and r['operation']=='commercial-price-monitor-20261105-read13-approved-manual1' and r['source_run']==41976677950 and len(r['hotels'])==56
m=json.loads((b/'commercial-human-copy-early-nine-manifest.json').read_text());assert m['operation']=='commercial-human-copy-early-nine-20261102-v1'
mh=hashlib.sha256(json.dumps(m,ensure_ascii=False,separators=(',',':')).encode()).hexdigest();exact={67910:('rixos','at-hotel-rixos'),68323:('solara','at-hotel-solara')};items=[]
for hid,(key,logical) in exact.items():
 hs=[x for x in r['hotels'] if int(x['local_tv_id'])==hid];rs=[x for x in m['rows'] if x['key']==key and x['logical_id']==logical];assert len(hs)==len(rs)==1 and hs[0]['observations'] and int(hs[0]['expected_country_id'])==1
 row={k:rs[0][k] for k in ('key','logical_id','new_text','new_text_sha256','update_text')};assert hashlib.sha256(row['new_text'].encode()).hexdigest()==row['new_text_sha256']
 items.append({'hotel':hs[0],'publisher_hotel_id':None,'copy_operation':m['operation'],'copy_manifest_sha256':mh,'copy_row':row})
d={'operation':'commercial-price-monitor-20261105-context15-copy-only-two','source_run':41976677950,'price_read13_run':41978466494,'items':items}
raw=(json.dumps(d,ensure_ascii=False,separators=(',',':'))+'\n').encode();(t/'context15-input.private.json').write_bytes(raw)
source=(b/'read-copy-only-two-price-contexts-20261105-context15.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
values={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT15_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT15_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
(t/'context15.php').write_text(prefix+''.join('const '+k+'='+json.dumps(v,ensure_ascii=False)+';\n' for k,v in values.items())+source[len(prefix):])
print(json.dumps({'operation':d['operation'],'native_ids':[67910,68323],'copy_only_publisher_roles_null':2,'historical_public_private_ids_projected':0,'new_scope_cases':3,'old_fixtures':0,'provider_calls':0,'db_calls':0}))
