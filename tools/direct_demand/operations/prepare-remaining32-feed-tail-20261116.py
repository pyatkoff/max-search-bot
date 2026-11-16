"""Offline preparation of four proven route/feed tails; no sends or supplier queries."""
from __future__ import annotations
import argparse,base64,hashlib,json,pathlib,re,subprocess,types,sys
CURRENT='ec72fcffa24ace22a997b642c33ea7fb6fb251d9'
PUBLISHED='372823d98e580e708169bc8ac9367245376a803b'
NAMES=['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']
PREFIX=b'<?php\ndeclare(strict_types=1);\n'
def need(ok,why):
 if not ok:raise ValueError(why)
def sha(raw):return hashlib.sha256(raw).hexdigest()
def compact(obj):return json.dumps(obj,ensure_ascii=False,separators=(',',':')).encode()
def blob(raw):return hashlib.sha1(b'blob '+str(len(raw)).encode()+b'\0'+raw).hexdigest()
def prepare(bound,proof_file,preimages,out,base):
 acceptance=json.loads((bound/'source-evidence/acceptance.json').read_bytes())
 need(acceptance.get('ok') is True and acceptance.get('publishing_blob_count')==22 and acceptance.get('source_verify')=='success' and acceptance.get('strict_tls')=='success','current_source_not_accepted')
 need(json.loads((bound/'source-evidence/main.json').read_bytes())['object']['sha']==CURRENT,'current_source_changed')
 need(json.loads((bound/'summary.json').read_bytes())['hotels']==32,'bound_scope_changed')
 proof_bytes=proof_file.read_bytes();need(sha(proof_bytes)=='64f1ee3efcd24b0be6422b68df798baa029f97ff7e39a869d6579e1d20f98454','root_route_proofs_changed');packet=json.loads(proof_bytes)
 need(packet['source']['run_id']==43928966057 and packet['bound_run']==43927951309 and [x['group'] for x in packet['groups']]==[22,23,24,25],'route_proof_scope_changed')
 source=(base/'prepare-publication-tail.py').read_bytes();need(blob(source)=='83af97a1a54e3ee3f2319301bc0897ab42d928ba','existing_tail_adapter_changed')
 old='require(read.get("ok") is True and read.get("installed_sha")==a["expected_installed_sha"],"bound_read_not_confirmed")'
 new='require(read.get("ok") is True and read.get("installed_sha")=="'+CURRENT+'","bound_read_not_confirmed")'
 text=source.decode();need(text.count(old)==1,'current_read_anchor_changed');adapted=text.replace(old,new,1);need(adapted.replace(new,old,1)==text,'adapter_logic_changed')
 mod=types.ModuleType('existing_feed_adapter_exact32');exec(compile(adapted,'existing_feed_adapter_exact32','exec'),mod.__dict__)
 writer=base/'publish-commercial-channel-hotel-feeds-20261103-1.php';contract=base/'monitor-price-contract.php';guard=base/'commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php'
 need(sha(writer.read_bytes())=='8609a2cf06d4a46738f934047d46744bdc54d3863e7c2921fdfec1a9be4b7a5e','existing_writer_changed')
 need(sha(contract.read_bytes())=='d5382b0bf62ca90f1f87c3c8c69b7cd095dc6f2f34d05e7b832aa0aa387b0445','price_contract_changed')
 need(sha(guard.read_bytes())=='19c921720bfc3c1a1ca06e1d43854420e8f4f8c0a44b9fb80c76ed0cefe038fa','nightly_guard_changed')
 out.mkdir(parents=True,exist_ok=True);previous=preimages;results=[];all_ids=[]
 for item in packet['groups']:
  group=item['group'];total=item['total'];count=2 if group==25 else 10;b=bound/str(group);dest=out/str(group);dest.mkdir()
  mraw=(b/'manifest.json').read_bytes();araw=(b/'approval.json').read_bytes();rdraw=(b/'bound-read.json').read_bytes();pubraw=(b/'publication.jsonl').read_bytes();publine=pubraw.splitlines()[-1]
  m=json.loads(mraw);a=json.loads(araw);read=json.loads(rdraw);pub=json.loads(publine)
  need(a['expected_installed_sha']==pub['installed_sha']==PUBLISHED and read['installed_sha']==CURRENT,'publication_or_current_read_source_changed')
  ids=a['hotel_ids'];all_ids+=ids;need(len(ids)==count and pub['ok'] and pub['new_posts']==2*count,'publication_count_changed')
  receipt_raw=item['receipt_utf8'].encode();manifest_raw=item['manifest_projection_utf8'].encode();proof_raw=compact(item['proof']);receipt=json.loads(receipt_raw);rm=json.loads(manifest_raw);proof=item['proof']
  need(sha(receipt_raw)==proof['route_receipt_sha256'] and sha(manifest_raw)==proof['route_manifest_projection_sha256'],'native_route_receipt_changed')
  need(sha(publine)==proof['publication_summary_sha256'] and sha(rdraw)==proof['bound_read_sha256'],'native_route_source_changed')
  need(receipt['ok'] and receipt['state']=='INSTALLED_AND_NATIVE_VERIFIED' and receipt['checks']['exact_total_routes']==total and receipt['checks']['new_native_routes_verified']==count,'native_route_not_installed')
  need(rm['previous_count']==total-count and rm['logical_hotels']==total and rm['new_hotel_ids']==ids,'native_route_scope_changed')
  need(all(receipt[k]==0 for k in ['provider_calls','db_writes','feed_writes','message_sends']),'native_route_boundary_changed')
  for name,data in [('manifest.json',mraw),('approval.json',araw),('publication.jsonl',pubraw),('bound-read.json',rdraw),('route-receipt.json',receipt_raw),('route-manifest.json',manifest_raw),('route-proof.json',proof_raw)]: (dest/name).write_bytes(data)
  admission={'schema_version':1,'operation':f'commercial-channel-hotel-feeds-20261116-update{group}','expected_installed_sha':PUBLISHED,'previous_count':total-count,'manifest_sha256':sha(mraw),'publication_summary_sha256':sha(publine),'bound_read_sha256':sha(rdraw),'route_receipt_sha256':sha(receipt_raw),'route_manifest_sha256':sha(manifest_raw),'original_route_manifest_sha256':proof['original_route_manifest_sha256'],'route_proof_sha256':sha(proof_raw),'previous_feed_sha256':{n:sha((previous/n).read_bytes()) for n in NAMES},'writer_sha256':sha(writer.read_bytes()),'monitor_contract_sha256':sha(contract.read_bytes())}
  (dest/'admission.json').write_bytes(compact(admission))
  args=argparse.Namespace(manifest=dest/'manifest.json',approval=dest/'approval.json',admission=dest/'admission.json',publication=dest/'publication.jsonl',bound_read=dest/'bound-read.json',route_receipt=dest/'route-receipt.json',route_manifest=dest/'route-manifest.json',route_map=None,route_proof=dest/'route-proof.json',previous_feed_dir=previous,writer=writer,monitor_contract=contract,output=dest/'write-original.php')
  mod.feed(args)
  initial=(dest/'write-original.php').read_bytes();old_header=("const EXPECTED_INSTALLED_SHA='"+PUBLISHED+"';").encode();new_header=("const EXPECTED_INSTALLED_SHA='"+CURRENT+"';").encode()
  need(initial.startswith(PREFIX+old_header) and initial.count(old_header)==1,'writer_source_header_changed')
  current=initial.replace(old_header,new_header,1);need(current.replace(new_header,old_header,1)==initial,'writer_body_changed')
  guardraw=guard.read_bytes();need(guardraw.startswith(PREFIX),'nightly_guard_prefix_changed')
  script=PREFIX+guardraw[len(PREFIX):]+b'\n'+current[len(PREFIX):];(dest/'write.php').write_bytes(script)
  data=json.loads((dest/'write-original.php.input.json').read_bytes());postdir=dest/'after';postdir.mkdir();preserved=[]
  for f in data['files']:
   before=base64.b64decode(f['previous_content_base64'],validate=True);after=base64.b64decode(f['content_base64'],validate=True)
   need(sha(before)==f['expected_previous_sha256'] and sha(after)==f['sha256'],'feed_content_hash_changed')
   blocks=lambda raw:re.findall(rb'<offer\b[^>]*>.*?</offer>',raw,re.S)
   old_blocks=blocks(before);new_blocks=blocks(after);prior=(total-count)*(2 if f['name']==NAMES[2] else 1)
   need(len(old_blocks)==prior and len(new_blocks)==f['rows'] and new_blocks[:prior]==old_blocks,'old_offers_changed')
   need(all(b'<price' not in entry and b'<oldprice' not in entry for entry in new_blocks[prior:]),'unconfirmed_price_added')
   (postdir/f['name']).write_bytes(after);preserved.append({'name':f['name'],'previous_offers':prior,'old_offer_bytes_identical':True,'new_unpriced_offers':len(new_blocks)-prior})
  subprocess.run(['php','-l',str(dest/'write.php')],check=True,capture_output=True)
  results.append({'group':group,'operation':data['operation'],'original_publication_source':PUBLISHED,'current_execution_source':CURRENT,'writer_body_unchanged':True,'monitor_guard_preserved':True,'script_sha256':sha(script),'input_sha256':sha((dest/'write-original.php.input.json').read_bytes()),'route_receipt_sha256':sha(receipt_raw),'files':[{k:f[k] for k in ['name','sha256','bytes','rows','expected_previous_sha256']} for f in data['files']],'preservation':preserved});previous=postdir
 need(len(all_ids)==len(set(all_ids))==32,'duplicate_hotel_scope')
 result={'ok':True,'state':'PREPARED_NOT_WRITTEN','hotels':32,'previous_pairs':628,'final_pairs':660,'new_prices':0,'new_messages':0,'supplier_calls':0,'groups':results}
 (out/'intent.json').write_bytes(compact(result));print(json.dumps(result))
if __name__=='__main__':
 need(len(sys.argv)==5,'bound proof preimages output required');prepare(*(pathlib.Path(v) for v in sys.argv[1:]),pathlib.Path(__file__).parent)
