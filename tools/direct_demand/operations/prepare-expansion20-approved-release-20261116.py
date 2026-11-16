"""Bind reviewed immutable media and actual clearance to the existing two bounded senders."""
from __future__ import annotations
import hashlib,importlib.util,json,pathlib,sys
PREFIX='<?php\ndeclare(strict_types=1);\n'
EXPECTED='d5091653179ac0460ed0129ae32cc52495abfbdd'
ASSET='a10f69ed7790cda97f3dee0162fd6d0bdd2fe890'
IDS=[88169,547,15774,12125,57335,2480,2417,2418,2468,12127,27481,23289,50262,72939,2489,85417,2466,2460,99713,43676]
def sha(b):return hashlib.sha256(b).hexdigest()
def load(p,digest):
 raw=p.read_bytes();assert sha(raw)==digest,str(p)+' changed';return json.loads(raw)
def main():
 source,output=map(pathlib.Path,sys.argv[1:]);output.mkdir(parents=True,exist_ok=True)
 assets=load(source/'covers/asset-manifest.json','64090abd077c58de194949829ddf654eeeec3de400ea5dfe8a077309a6136c78')
 publication=load(source/'publication-result.json','152b62a48c8cfdf5b70d3af453ffdc5e4004e5ef3d3ddfc2259b426108a057a8')
 clearance=load(source/'clearance.json','b5dda69f4c5d58afec3c9742037d70c4311c762f4576af7c847bf9847d234875')
 identities=load(source/'clearance-input.json','188a7f63e97b147e3d367d8d801b26645d59051c8d532df6722decb5866d9889')
 assert publication['ok'] and publication['asset_commit']==ASSET and clearance['ok'] and clearance['clear_count']==20
 assert clearance['runtime']=={'head':EXPECTED,'tracked_clean':True,'component_mismatches':[]}
 assert [r['hotel_id'] for r in assets['rows']]==[r['hotel_id'] for r in publication['blobs']]==[r['hotel_id'] for r in identities]==IDS
 base=pathlib.Path('tools/direct_demand/operations')
 spec=importlib.util.spec_from_file_location('batch',base/'prepare-commercial-batch.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
 template=(base/'commercial-batch-template-20261102.php').read_text();guard=(base/'commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php').read_text();assert guard.startswith(PREFIX)
 rows=[]
 for image,link,identity in zip(assets['rows'],publication['blobs'],identities):
  tv=image['hotel_id'];raw=(source/'covers'/image['photo_file']).read_bytes();assert sha(raw)==image['photo_sha256']==link['sha256']
  assert image['text_sha256']==identity['text_sha256']==sha(image['text'].encode())
  assert link['url']==f'https://raw.githubusercontent.com/pyatkoff/max-search-bot/{ASSET}/.github/traffic-publish/commercial-expansion20-20261116/{tv}.png'
  rows.append({'hotel_id':tv,'logical_id':identity['logical_id'],'aliases':identity['aliases'],'text':image['text'],'text_sha256':image['text_sha256'],'photo_url':link['url'],'photo_sha256':link['sha256'],'media_state':module.READY,'sources':image['sources'],'media_provenance':'Three previously selected retained property photographs; actual runner-rendered cover reviewed on 16 November 2026. No assertion of current physical condition or room category from image alone.'})
 report={'schema':1,'current_source':EXPECTED,'asset_commit':ASSET,'visual_review':'all20 actual rendered covers inspected in4 contact sheets, captions legible, exact source photographs retained','clearance_run':43857847149,'new_prices':0,'site_admission_claimed':False,'ai_admission_claimed':False,'batches':[]}
 for number,start in [(20,0),(21,10)]:
  selected=rows[start:start+10];op=f'commercial-hotel-batch-20261116-{number}-v1';folder=output/str(number);folder.mkdir()
  manifest={'state':'PREPARED_NOT_SENT','operation':op,'button_text':'✈️ Узнать стоимость тура','expected_new_full_pairs':10,'server_clearance_run':43857847149,'source_asset_commit':ASSET,'owner_scope_issue':1142,'owner_scope_comment':6915033319,'rows':selected}
  raw=(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n').encode();approval={'schema_version':1,'operation':op,'expected_installed_sha':EXPECTED,'hotel_ids':[r['hotel_id'] for r in selected],'manifest_sha256':sha(raw)}
  compiled,proof=module.prepare(template,raw,approval);assert compiled.startswith(PREFIX)
  # Preserve canonical guard and sender unchanged; require a truly new operation directory.
  preflight="if(file_exists('/var/www/anytoour/data/channel-post-operations/"+op+"')){echo json_encode(['ok'=>false,'operation'=>'"+op+"','error'=>'existing_operation_no_replay','post_write_attempts'=>0]),PHP_EOL;exit(2);}\n"
  program=PREFIX+preflight+guard[len(PREFIX):]+'\n'+compiled[len(PREFIX):]
  (folder/'manifest.json').write_bytes(raw);(folder/'approval.json').write_text(json.dumps(approval,ensure_ascii=False,indent=2)+'\n');(folder/'send.php').write_text(program)
  report['batches'].append({**proof,'operation':op,'hotel_ids':approval['hotel_ids'],'manifest_sha256':sha(raw),'compiled_sender_sha256':sha(compiled.encode()),'guard_sha256':sha(guard.encode()),'script_sha256':sha(program.encode()),'directory_must_be_absent':True})
 (output/'release-intent.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
 print(json.dumps({'prepared_hotels':20,'batches':2,'expected_new_posts':40,'script_writes_only':True,'provider_calls':0,'source':EXPECTED}))
if __name__=='__main__':main()
