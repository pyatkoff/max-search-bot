"""Prepare one new route while preserving the accepted156 from private artifacts. No server calls."""
import base64,hashlib,json,os,re,subprocess
from pathlib import Path
T=Path(os.environ['RUNNER_TEMP'])
OLD_MAP='anytour-hotel-feed-routes-v156-20261104-37.private.php'
NEW_MAP='anytour-hotel-feed-routes-v157-20261104-38.private.php'
IDS=[1543]
EXPECTED={'anytour-hotel-feed-context.php':'3371bef92e15f595a1da4e6629a27a218029b33223ffed3735941fa53fb03ae0',OLD_MAP:'eeab1d15705d6e80eb1be383be8a03ac61126aa0c8b15cdf441bb8d475b9224b'}
def exact(root,name):
 m=list(root.rglob(name));assert len(m)==1;return m[0]
oldroot=T/'old156';old={}
for name,digest in EXPECTED.items():
 raw=exact(oldroot,name).read_bytes();assert hashlib.sha256(raw).hexdigest()==digest;old[name]=raw
def parse_map(raw):
 m=re.search(rb"base64_decode\('([A-Za-z0-9+/=]+)',true\)",raw);assert m;return json.loads(base64.b64decode(m.group(1),validate=True))
previous=parse_map(old[OLD_MAP]);assert len(previous['bindings'])==156
r=json.loads(exact(T/'new1','channel-preflight.json').read_text().splitlines()[-1])
assert r['ok'] and r['operation']=='commercial-channel-hotel-feeds-20261104-read14' and r['scope_hotels']==1 and r['private_binding_count']==1 and r['deferred_count']==0
assert [x['hotel_id'] for x in r['private_max_bindings']]==IDS
new=[]
for x in r['private_max_bindings']:
 assert x['platform']=='max' and x['project_key']=='anytour' and x['post_bot_key']=='id9704048781_1_bot' and x['channel_id']==previous['channel_id']
 assert x['post_url'].startswith(previous['channel_url']+'/')
 offer='max_'+str(x['hotel_id']);alias='h1_'+hashlib.sha256(('anytour|'+offer+'|'+x['post_url']).encode()).hexdigest()[:32]
 assert alias==x['public_route_alias'] and offer==x['public_offer_id']
 new.append({'hotel_id':x['hotel_id'],'alias':alias,'offer_id':offer,'post_id':x['publication_id'],'post_url':x['post_url']})
data=dict(previous);data['bindings']=previous['bindings']+new
def validate(candidate):
 assert candidate['bindings'][:156]==previous['bindings'] and len(candidate['bindings'])==157
 for key in ['hotel_id','alias','offer_id','post_id','post_url']:assert len({x[key] for x in candidate['bindings']})==157
validate(data)
bad=dict(data);bad['bindings']=[dict(x) for x in data['bindings']];bad['bindings'][0]['offer_id']='max_0'
try:validate(bad);raise RuntimeError('MUTATED_PREFIX_ACCEPTED')
except AssertionError:pass
bad=dict(data);bad['bindings']=[dict(x) for x in data['bindings']];bad['bindings'][-1]=dict(bad['bindings'][0])
try:validate(bad);raise RuntimeError('DUPLICATE_APPEND_ACCEPTED')
except AssertionError:pass
payload=base64.b64encode(json.dumps(data,separators=(',',':')).encode()).decode()
raw=("<?php\ndeclare(strict_types=1);\nif (!isset($anytourHotelFeedPrivateLoad) || $anytourHotelFeedPrivateLoad!==true) {http_response_code(404);exit;}\nreturn json_decode(base64_decode('"+payload+"',true),true,32,JSON_THROW_ON_ERROR);\n").encode()
module=old['anytour-hotel-feed-context.php'].decode()
assert module.count(OLD_MAP)==1 and module.count("count($data['bindings'])!==156")==1 and module.count(EXPECTED[OLD_MAP])==1
module=module.replace(OLD_MAP,NEW_MAP).replace("count($data['bindings'])!==156","count($data['bindings'])!==157").replace(EXPECTED[OLD_MAP],hashlib.sha256(raw).hexdigest())
out=T/'max157-candidate';out.mkdir(mode=0o700)
(out/NEW_MAP).write_bytes(raw);(out/'anytour-hotel-feed-context.php').write_text(module)
(out/'private-expected-new1.json').write_text(json.dumps(new,separators=(',',':')))
for name in [NEW_MAP,'anytour-hotel-feed-context.php']:
 p=subprocess.run(['php','-l',str(out/name)],capture_output=True);assert p.returncode==0
fixture="""class AppError extends \\RuntimeException{};require $argv[1];$a=json_decode(file_get_contents($argv[2]),true);$anytourHotelFeedPrivateLoad=true;$map=require $argv[3];foreach($a as $r){$v=\\TourMax\\Max2Candidate\\HotelFeedContext::resolveAlias($r['alias'],$r['offer_id'],$map['project_id']);if($v!==['post_id'=>$r['post_id'],'offer_id'=>$r['offer_id']])exit(2);}try{\\TourMax\\Max2Candidate\\HotelFeedContext::resolveAlias($a[0]['alias'],'max_0',$map['project_id']);exit(3);}catch(AppError $e){if($e->getMessage()!=='HOTEL_FEED_OFFER')exit(4);}echo 'NEW1_EXACT_ALIAS_AND_WRONG_OFFER_OK';"""
# Define the fixture AppError in the module namespace without exposing values.
fixture="namespace TourMax\\Max2Candidate;"+fixture.replace("class AppError extends \\RuntimeException{};","class AppError extends \\RuntimeException{};")
p=subprocess.run(['php','-r',fixture,str(out/'anytour-hotel-feed-context.php'),str(out/'private-expected-new1.json'),str(out/NEW_MAP)],capture_output=True)
assert p.returncode==0,'NEW_ALIAS_MODULE_FIXTURE_FAILED'
unchanged={'lib.php':'2574585eaaef169e671966931c59bfe1b2bdf0e38d345c3d06a140c11dc65795','anytour-hotel-feed-routes.private.php':'afb4c6a2ceac44a99286697651bcc9f91ebf3361a4dad3b4ed134154c5703ae0',OLD_MAP:EXPECTED[OLD_MAP],'production.php':'1f71d498d57904fbe3d76e270397fa65503c5747fe6c2535fe5c82bc20158452','script3.php':'f136fc22f508edcde47d02912752bfe3242fa85830ec306c88eeb7ddce221bf6','post-routes.json':'7a9ea3ce525df4ebd0d8bd6db773ca5879b90d744e363689687519deb1a23571'}
manifest={'operation':'commercial-max157-private-route-extension-20261104-38','state':'CANDIDATE_NOT_INSTALLED','previous_count':156,'logical_hotels':157,'new_hotel_ids':IDS,'new_map_filename':NEW_MAP,'previous_module_sha256':EXPECTED['anytour-hotel-feed-context.php'],'unchanged_server_files':unchanged,'files':[{'name':n,'bytes':(out/n).stat().st_size,'sha256':hashlib.sha256((out/n).read_bytes()).hexdigest()} for n in [NEW_MAP,'anytour-hotel-feed-context.php']],'previous156_rows_identical':True,'private_ids_in_public_outputs':0,'server_calls':0,'provider_calls':0,'db_writes':0,'message_sends':0,'new_scope_cases':3}
(T/'max157-candidate-manifest.json').write_text(json.dumps(manifest,sort_keys=True))
print(json.dumps(manifest,sort_keys=True))
