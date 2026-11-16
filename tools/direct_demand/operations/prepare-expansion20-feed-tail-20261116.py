"""Offline preparation for already-published20; same writer, unchanged old offers, no sends."""
from __future__ import annotations
import argparse,base64,copy,hashlib,json,pathlib,re,subprocess,types,zipfile
CURRENT='729bd1cf40567b2072f2471af283954cd9989cd1'
PUBLISHED='d5091653179ac0460ed0129ae32cc52495abfbdd'
NAMES=['hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml']
def need(v,why):
 if not v:raise ValueError(why)
def sha(b):return hashlib.sha256(b).hexdigest()
def compact(v):return json.dumps(v,ensure_ascii=False,separators=(',',':')).encode()
def gitblob(b):return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
def admit_sources(approval,pub,read,accepted):
 need(approval['expected_installed_sha']==pub['installed_sha']==PUBLISHED,'original_publication_source_changed')
 need(read['installed_sha']==CURRENT,'current_read_source_changed')
 need(accepted.get('ok') is True and accepted.get('publishing_blob_count')==22 and accepted.get('source_verify')=='success' and accepted.get('strict_tls')=='success','current_source_acceptance_missing')
def prepare(bound,proof_file,preimages,source_evidence,out,base):
 acceptance=json.loads((source_evidence/'acceptance.json').read_bytes())
 need(json.loads((source_evidence/'main.json').read_bytes())['object']['sha']==CURRENT,'current_main_changed')
 gates=json.loads((source_evidence/'gate-receipts.json').read_bytes());need(len(gates)==9 and all(r['head_sha']==CURRENT and r['run_attempt']==1 and r['conclusion']=='success' for r in gates.values()),'original_nine_gates_missing')
 source=(base/'prepare-publication-tail.py').read_bytes();need(gitblob(source)=='83af97a1a54e3ee3f2319301bc0897ab42d928ba','existing_adapter_changed')
 old='require(read.get("ok") is True and read.get("installed_sha")==a["expected_installed_sha"],"bound_read_not_confirmed")'
 new='require(read.get("ok") is True and read.get("installed_sha")=="'+CURRENT+'","bound_read_not_confirmed")'
 text=source.decode();need(text.count(old)==1,'current_read_admission_anchor_changed');adapted=text.replace(old,new,1)
 need(adapted.replace(new,old,1)==text,'adapter_body_changed')
 mod=types.ModuleType('scoped_current_release_tail');exec(compile(adapted,'scoped_existing_tail','exec'),mod.__dict__)
 writer=base/'publish-commercial-channel-hotel-feeds-20261103-1.php';contract=base/'monitor-price-contract.php';guard=base/'commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php'
 need(sha(writer.read_bytes())=='8609a2cf06d4a46738f934047d46744bdc54d3863e7c2921fdfec1a9be4b7a5e','writer_changed')
 need(sha(contract.read_bytes())=='d5382b0bf62ca90f1f87c3c8c69b7cd095dc6f2f34d05e7b832aa0aa387b0445','contract_changed')
 need(sha(guard.read_bytes())=='19c921720bfc3c1a1ca06e1d43854420e8f4f8c0a44b9fb80c76ed0cefe038fa','monitor_guard_changed')
 proofs_raw=proof_file.read_bytes();need(sha(proofs_raw)=='96f87f6680081aa9299aed080e93e90ebd1c1c9a0b23ff97537618b01526daaa','root_route_proofs_changed');packet=json.loads(proofs_raw)
 need([x['group'] for x in packet['groups']]==[20,21] and packet['source']['run_id']==43872922554,'route_scope_changed')
 out.mkdir(parents=True,exist_ok=True);results=[];previous=preimages
 with zipfile.ZipFile(bound/'publication-result.zip') as z:
  for item in packet['groups']:
   g=item['group'];total=item['total'];dest=out/str(g);dest.mkdir();original=bound/str(g)
   mraw=(original/'manifest.json').read_bytes();araw=(original/'approval.json').read_bytes();rr=(original/'bound-read.json').read_bytes();pubraw=z.read(f'{g}-stdout.jsonl');publine=pubraw.splitlines()[-1]
   m=json.loads(mraw);a=json.loads(araw);read=json.loads(rr);pub=json.loads(publine)
   admit_sources(a,pub,read,acceptance)
   need(pub==json.loads((original/'publication.json').read_bytes()),'original_summary_differs')
   # Controlled negative cases are pure and leave all receipts untouched.
   for field in ['publication','read','acceptance']:
    aa=copy.deepcopy(a);pp=copy.deepcopy(pub);rd=copy.deepcopy(read);ac=copy.deepcopy(acceptance)
    if field=='publication':pp['installed_sha']='0'*40
    elif field=='read':rd['installed_sha']='0'*40
    else:ac['publishing_blob_count']=21
    rejected=False
    try:admit_sources(aa,pp,rd,ac)
    except ValueError:rejected=True
    need(rejected,'unsafe_runtime_admission')
   proof=item['proof'];receipt=item['receipt_utf8'].encode();projection=item['manifest_projection_utf8'].encode();proofraw=compact(proof)
   need(sha(publine)==proof['publication_summary_sha256'] and sha(rr)==proof['bound_read_sha256'],'root_publication_or_read_changed')
   need(sha(receipt)==proof['route_receipt_sha256'] and sha(projection)==proof['route_manifest_projection_sha256'],'root_route_receipt_changed')
   for name,raw in [('manifest.json',mraw),('approval.json',araw),('publication.jsonl',pubraw),('bound-read.json',rr),('route-receipt.json',receipt),('route-manifest.json',projection),('route-proof.json',proofraw)]: (dest/name).write_bytes(raw)
   admission={'schema_version':1,'operation':f'commercial-channel-hotel-feeds-20261116-update{g}','expected_installed_sha':PUBLISHED,'previous_count':total-10,'manifest_sha256':sha(mraw),'publication_summary_sha256':sha(publine),'bound_read_sha256':sha(rr),'route_receipt_sha256':sha(receipt),'route_manifest_sha256':sha(projection),'original_route_manifest_sha256':proof['original_route_manifest_sha256'],'route_proof_sha256':sha(proofraw),'previous_feed_sha256':{n:sha((previous/n).read_bytes()) for n in NAMES},'writer_sha256':sha(writer.read_bytes()),'monitor_contract_sha256':sha(contract.read_bytes())}
   (dest/'admission.json').write_bytes(compact(admission))
   args=argparse.Namespace(manifest=dest/'manifest.json',approval=dest/'approval.json',admission=dest/'admission.json',publication=dest/'publication.jsonl',bound_read=dest/'bound-read.json',route_receipt=dest/'route-receipt.json',route_manifest=dest/'route-manifest.json',route_map=None,route_proof=dest/'route-proof.json',previous_feed_dir=previous,writer=writer,monitor_contract=contract,output=dest/'write-original.php')
   mod.feed(args)
   initial=(dest/'write-original.php').read_bytes();old_header=("const EXPECTED_INSTALLED_SHA='"+PUBLISHED+"';").encode();new_header=("const EXPECTED_INSTALLED_SHA='"+CURRENT+"';").encode();prefix=b'<?php\ndeclare(strict_types=1);\n'
   need(initial.startswith(prefix+old_header) and initial.count(old_header)==1,'original_writer_header_changed')
   current=initial.replace(old_header,new_header,1);need(current.replace(new_header,old_header,1)==initial,'writer_body_changed')
   guardraw=guard.read_bytes();need(guardraw.startswith(prefix),'guard_prefix_changed')
   script=prefix+guardraw[len(prefix):]+b'\n'+current[len(prefix):];(dest/'write.php').write_bytes(script)
   data=json.loads((dest/'write-original.php.input.json').read_bytes());postdir=dest/'after';postdir.mkdir();preserved=[]
   for f in data['files']:
    before=base64.b64decode(f['previous_content_base64'],validate=True);after=base64.b64decode(f['content_base64'],validate=True)
    need(sha(before)==f['expected_previous_sha256'] and sha(after)==f['sha256'],'feed_bytes_changed')
    blocks=lambda raw:re.findall(rb'<offer\b[^>]*>.*?</offer>',raw,re.S)
    old_blocks=blocks(before);new_blocks=blocks(after);count=(total-10)*(2 if f['name']==NAMES[2] else 1)
    need(len(old_blocks)==count and len(new_blocks)==f['rows'] and new_blocks[:count]==old_blocks,'old_offers_changed')
    need(all(b'<price' not in b and b'<oldprice' not in b for b in new_blocks[count:]),'unconfirmed_price_added')
    (postdir/f['name']).write_bytes(after);preserved.append({'name':f['name'],'previous_offers':count,'old_offer_bytes_identical':True,'new_unpriced_offers':len(new_blocks)-count})
   subprocess.run(['php','-l',str(dest/'write.php')],check=True,capture_output=True)
   results.append({'group':g,'operation':data['operation'],'original_publication_source':PUBLISHED,'current_execution_source':CURRENT,'writer_body_unchanged':True,'monitor_guard_preserved':True,'script_sha256':sha(script),'input_sha256':sha((dest/'write-original.php.input.json').read_bytes()),'route_receipt_sha256':sha(receipt),'files':[{k:f[k] for k in ['name','sha256','bytes','rows','expected_previous_sha256']} for f in data['files']],'preservation':preserved})
   previous=postdir
 summary={'ok':True,'state':'PREPARED_NOT_WRITTEN','existing_writer_unchanged':True,'original_receipts_unchanged':True,'runtime_negative_cases':6,'source_gates':9,'publisher_pins':22,'new_messages':0,'supplier_calls':0,'groups':results}
 (out/'intent.json').write_bytes(compact(summary));print(json.dumps(summary))
if __name__=='__main__':
 import sys
 need(len(sys.argv)==6,'bound proof preimages source-evidence output required')
 prepare(*(pathlib.Path(x) for x in sys.argv[1:]),pathlib.Path(__file__).parent)
