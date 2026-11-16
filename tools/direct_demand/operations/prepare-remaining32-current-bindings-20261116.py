"""Compile four existing bounded readers, preserving original publication receipts."""
from __future__ import annotations
import ast,base64,hashlib,importlib.util,json,pathlib,re,subprocess,sys,zipfile
CURRENT='ec72fcffa24ace22a997b642c33ea7fb6fb251d9'
PUBLISHED='372823d98e580e708169bc8ac9367245376a803b'
IDS=[548,2947,4032,43675,48263,82790,24743,66547,60232,78033,43569,78002,2451,63208,2491,2470,64954,2479,2493,76318,53617,12124,76011,65108,123308,65938,80008,2436,2444,2445,218,63191]
CHANGES={'services/ChannelPostEntryService.php':('f0e04e9b4e0ed6859ced96279cbf0619931596e6','1982ff7368763edf8b7238c3142e3cc28579e991'),'services/ChannelPostRegistryService.php':('db8181426091a685a79ee55991cde22671f0d9dd','7304af19cc19666f00ca4ead1b04c2bdce719646')}
def need(value,why):
 if not value:raise ValueError(why)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def compact(value):return json.dumps(value,ensure_ascii=False,separators=(',',':')).encode()
def unpack(path,expected,destination):
 need(sha(path.read_bytes())==expected,'archive_changed:'+path.name)
 with zipfile.ZipFile(path) as z:
  need(z.testzip() is None and sum(i.file_size for i in z.infolist())<40000000,'archive_invalid')
  need(all(not pathlib.PurePosixPath(n).is_absolute() and '..' not in pathlib.PurePosixPath(n).parts for n in z.namelist()),'archive_path_invalid');z.extractall(destination)
def replace_once(text,old,new):
 need(text.count(old)==1,'source_anchor_changed:'+old[:70]);return text.replace(old,new,1)
def main():
 w=pathlib.Path(sys.argv[1]);base=pathlib.Path(__file__).parent;e=w/'source-evidence'
 unpack(w/'old-reader.zip','a809657d638cfafb122e0faaebf50e40088325ade6491890574f3c0770ae6c93',w/'old-reader')
 unpack(w/'publication-intent.zip','ab7d4b54280e4f0daf070f6b675502f8a98f521c74847a2e4524f8468078e1ce',w/'publication-intent')
 unpack(w/'publication-result.zip','beb2e126de6efb8bca8506da7e116bb332baae8666c6cdcbe108eb842c0a53af',w/'publication-result')
 spec=importlib.util.spec_from_file_location('existing_source_verifier',base/'verify-commercial-source.py');mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
 mod.self_test()
 for path,(old,new) in CHANGES.items():need(mod.PINS[path]==old,'reviewed_original_pin_changed');mod.PINS[path]=new
 evidence={n:json.loads((e/(n+'.json')).read_bytes()) for n in ['main','verify_run','verify_jobs','tls_run','source_commit','source_tree']}
 accepted=mod.verify(evidence,CURRENT);need(accepted['aggregate_deploy_conclusion']=='success','current_deploy_incomplete')
 (e/'acceptance.json').write_bytes(compact(accepted))
 raw=(w/'old-reader/20/read.php').read_bytes();need(sha(raw)=='2f0633f53f6ef6e8c7272282904c3116eabd3744eda3346d538b17cfb2644afb','original_reader_changed');template=raw.decode()
 old_inputs=json.loads((w/'old-reader/20/read.php.inputs.json').read_bytes());old_ids=[r['hotel_id'] for r in old_inputs]
 seen=[];summary=[]
 for group in [22,23,24,25]:
  original=w/'publication-intent/prepared'/str(group);dest=w/str(group);dest.mkdir()
  mraw=(original/'manifest.json').read_bytes();araw=(original/'approval.json').read_bytes();m=json.loads(mraw);a=json.loads(araw)
  pubraw=(w/'publication-result'/f'{group}-stdout.jsonl').read_bytes();pub=json.loads(pubraw.splitlines()[-1]);ids=a['hotel_ids'];seen+=ids;n=len(ids)
  need(a['manifest_sha256']==sha(mraw) and a['expected_installed_sha']==pub['installed_sha']==PUBLISHED,'publication_source_changed')
  need(a['operation']==m['operation']==pub['operation'] and [r['hotel_id'] for r in m['rows']]==ids and 1<=n<=10,'publication_scope_changed')
  need(pub['ok'] and pub['new_posts']==pub['post_write_attempts']==len(pub['posts'])==2*n and pub['reused']==0,'publication_not_complete')
  need({(r['hotel_id'],r['platform']) for r in pub['posts']}=={(i,p) for i in ids for p in ['max','telegram']},'publication_pairs_changed')
  inputs=[]
  for row in m['rows']:
   matches=[p for p in pub['posts'] if p['hotel_id']==row['hotel_id']]
   need(len(matches)==2 and all(p['state']=='sent' and p['body_photo_button_verified'] and p['text_sha256']==row['text_sha256'] and p['photo_sha256']==row['photo_sha256'] for p in matches),'publication_content_changed')
   first=row['text'].split('\n',1)[0];need(first.startswith('✈️ Туры в '),'heading_missing')
   inputs.append({'hotel_id':row['hotel_id'],'name':first.removeprefix('✈️ Туры в '),'accepted_first_line':first,'image_url':row['photo_url'],'image_sha256':row['photo_sha256']})
  data=compact(inputs);program=template
  program=replace_once(program,"const EXPECTED_INSTALLED_SHA='729bd1cf40567b2072f2471af283954cd9989cd1';","const EXPECTED_INSTALLED_SHA='"+CURRENT+"';")
  for name,value in [('FEED_EXPORT_INPUT_B64',base64.b64encode(data).decode()),('FEED_EXPORT_INPUT_SHA256',sha(data))]:
   anchor=re.search(r"const "+name+r"='[^']*';",program);need(anchor is not None,'reader_header_missing');program=replace_once(program,anchor.group(),"const "+name+"='"+value+"';")
  program=replace_once(program,'commercial-channel-hotel-feeds-20261116-read22',f'commercial-channel-hotel-feeds-20261116-read{100+group}')
  program=replace_once(program,'array_column($in,\'hotel_id\')==='+json.dumps(old_ids,separators=(',',':')),'array_column($in,\'hotel_id\')==='+json.dumps(ids,separators=(',',':')))
  if n!=10:
   for old in ['count($in)===10',"count(array_unique(array_column($in,'accepted_first_line')))===10",'count(array_unique($ids))===10',"count($built['rows'])===10","$out['scope_hotels']=10"]:program=replace_once(program,old,old[:-2]+str(n))
  for path,(old,new) in CHANGES.items():program=replace_once(program,'"'+path+'"=>"'+old+'"','"'+path+'"=>"'+new+'"')
  for name,content in [('manifest.json',mraw),('approval.json',araw),('publication.json',compact(pub)),('publication.jsonl',pubraw),('read.php',program.encode()),('read.php.inputs.json',data)]: (dest/name).write_bytes(content)
  subprocess.run(['php','-l',str(dest/'read.php')],check=True,capture_output=True)
  summary.append({'group':group,'hotel_ids':ids,'script_sha256':sha(program.encode()),'publication_summary_sha256':sha(pubraw.splitlines()[-1])})
 need(seen==IDS and len(set(seen))==32,'exact_remaining32_changed')
 (w/'reader-intent.json').write_bytes(compact({'ok':True,'state':'PREPARED_READ_ONLY','source':CURRENT,'original_publication_source':PUBLISHED,'groups':summary,'reviewed_pin_changes':CHANGES,'writes':0,'provider_calls':0}))
 print(json.dumps({'ok':True,'groups':4,'hotels':32,'writes':0,'provider_calls':0,'source':CURRENT}))
if __name__=='__main__':main()
