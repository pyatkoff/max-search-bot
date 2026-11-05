import base64,hashlib,html,json,os,pathlib,re,unicodedata
base=pathlib.Path('tools/direct_demand/operations');temp=pathlib.Path(os.environ['RUNNER_TEMP'])
source=(base/'read-commercial-price-monitor-20261104-read10-72h.php').read_text()
assert hashlib.sha256(source.encode()).hexdigest()=='ddfa54ff247af046e25fcae7b0ca594864ff105901999d2c4f33f5d121d94f14'
left="$scope=json_decode(<<<'_ANYTOUR_PRICE157_SCOPE_'\n";right="\n_ANYTOUR_PRICE157_SCOPE_,true,512,JSON_THROW_ON_ERROR);"
scope=json.loads(source.split(left)[1].split(right)[0]);assert len(scope)==159
by={int(h['local_tv_id']):h for h in scope}
specs=[(310,None,None,'commercial-human-copy-early-nine-manifest.json','golf'),(469,None,None,'commercial-human-copy-early-nine-manifest.json','sultan'),(381,None,None,'commercial-human-copy-early-nine-manifest.json','rehana'),(1971,None,None,'commercial-human-copy-early-nine-manifest.json','palace'),(240,'commercial-next-three-manifest.json',80,'commercial-human-copy-early-nine-manifest.json','happy-life'),(1981,'commercial-three-20261102-2-manifest.json',877,'commercial-human-copy-three-manifest.json','jaz-neo-naama'),(46672,'commercial-three-20261102-3-manifest.json',2432,None,None),(498,'commercial-three-20261102-5-manifest.json',228,None,None),(9373,'commercial-three-20261102-7-manifest.json',9373,None,None)]
def compact(s):
 s=re.sub(r'\s*\(.*?\)','',html.unescape(s));return ''.join(c for c in unicodedata.normalize('NFKC',s).casefold() if c.isalnum())
bindings=[]
for hid,pubfile,pubid,copyfile,copykey in specs:
 h=by[hid];publisher=None;copy=None;logical=None;names=[]
 if pubfile:
  m=json.loads((base/pubfile).read_text());r=[x for x in m['rows'] if int(x['hotel_id'])==pubid];assert len(r)==1;r=r[0]
  assert hashlib.sha256(r['text'].encode()).hexdigest()==r['text_sha256']
  assert re.fullmatch('[0-9a-f]{64}',r['photo_sha256'])
  if h['identity_provenance']=='accepted_typed_bridge':assert int(h['anytour_id'])==pubid
  else:assert pubid==hid
  publisher={'operation':m['operation'],'hotel_id':pubid,'text_sha256':r['text_sha256'],'photo_sha256':r['photo_sha256']}
  logical=r['logical_id'];caption=r['text'];caption_sha=r['text_sha256'];names=[r['hotel']]+r.get('aliases',[])
 if copyfile:
  m=json.loads((base/copyfile).read_text());r=[x for x in m['rows'] if x['key']==copykey];assert len(r)==1;r=r[0]
  assert hashlib.sha256(r['new_text'].encode()).hexdigest()==r['new_text_sha256']
  if logical:assert r['logical_id']==logical
  logical=r['logical_id'];caption=r['new_text'];caption_sha=r['new_text_sha256']
  copy={'operation':m['operation'],'key':copykey,'manifest_hash':hashlib.sha256(json.dumps(m,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()}
  title=caption.split('\n')[0]
  assert title.startswith('✈️ Туры в ') and title.endswith(' с перелётом')
  names.append(title[len('✈️ Туры в '):-len(' с перелётом')])
 assert compact(h['expected_name']) in {compact(x) for x in names},'SOURCE_NAME_NOT_BOUND_TO_NATIVE_'+str(hid)
 assert re.fullmatch('[a-z0-9-]{1,120}',logical)
 bindings.append({'native_hotel_id':hid,'expected_name':h['expected_name'],'expected_country_id':h['expected_country_id'],'identity_provenance':h['identity_provenance'],'publisher':publisher,'copy':copy,'logical_id':logical,'caption':caption,'caption_sha256':caption_sha,'approved_source_hotel_names':names})
assert {b['native_hotel_id'] for b in bindings}=={240,1981,46672,498,9373,469,381,310,1971}
raw=(json.dumps(bindings,ensure_ascii=False,separators=(',',':'))+'\n').encode()
(temp/'legacy9-approved-bindings.private.json').write_bytes(raw)
reader=(base/'read-commercial-legacy9-price-contexts-20261105.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert reader.startswith(prefix)
header=prefix+"const EXPECTED_INSTALLED_SHA='"+os.environ.get('EXPECTED_PRODUCTION_SHA','PURE_NOT_APPROVED_FOR_SERVER')+"';\n"+"const APPROVED_BINDINGS_SHA256='"+hashlib.sha256(raw).hexdigest()+"';\n"+"const APPROVED_BINDINGS_B64='"+base64.b64encode(raw).decode()+"';\n"
(temp/'legacy9-read.php').write_text(header+reader[len(prefix):])
print(json.dumps({'operation':'commercial-price-legacy9-contexts-20261105-read1-prepare','exact_native_ids':[b['native_hotel_id'] for b in bindings],'typed_publisher_native_roles':4,'native_direct_or_copy_roles':5,'source_name_bindings':9,'input_sha256':hashlib.sha256(raw).hexdigest(),'provider_calls':0,'db_calls':0,'edits':0}))
