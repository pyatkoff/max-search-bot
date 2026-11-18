"""Offline exact-tail compiler. Never connects, publishes, or executes a generated writer.
The CLI stays closed until a reviewer pins an authenticated admission JSON digest.
"""
from __future__ import annotations
import argparse,base64,contextlib,datetime,hashlib,io,json,pathlib,re,subprocess,types
INVENTORY_SHA='c8cee3897d32ae513b537ba55ce4d8fe5d850017789b60fea53eca8a9f9ce565'
CURRENT='3ffab7f2e2d01c8cd35144fba621897e4ad75e5d'
REVIEWED_ADMISSION_SHA256='PIN_AFTER_AUTHENTICATED_CURRENT_READ_ROUTES_AND_PREIMAGES'
PINS={'prepare-publication-tail.py':'6db0ef9d8bb51d897387140c6f5c1d9e5b68bc33ffff41c9cea98621c927c4b8','publish-commercial-channel-hotel-feeds-20261103-1.php':'8609a2cf06d4a46738f934047d46744bdc54d3863e7c2921fdfec1a9be4b7a5e','monitor-price-contract.php':'3c61a260d9c40f7741d644a9531aebea58790595ae2adbad6ff8163975621ede','commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php':'02bffda02ed05e311b44b90fea63717a5cd787b99cc2653f3b5a17f7d9bfb00f'}
PUBLIC_LABELS={22940:'Anantara Sir Bani Yas Al Yamm',52337:'Four Seasons Abu Dhabi Al Maryah Island',64648:'Four Seasons Dubai DIFC',78669:'Four Seasons Bangkok Chao Phraya River',60788:'Four Seasons Cairo at The First Residence',77515:'The First Collection Jumeirah Village Circle',69774:'Waldorf Astoria Dubai DIFC',126601:'Danang Marriott Non Nuoc Beach Villas'}
PUBLIC_LABEL_ORIGINALS={22940: 'Anantara Sir Bani Yas Island Al Yamm Villa Resort', 52337: 'Four Seasons Hotel Abu Dhabi at Al Maryah Island', 64648: 'Four Seasons Hotel Dubai International Financial Centre', 78669: 'Four Seasons Hotel Bangkok at Chao Phraya River', 60788: 'Four Seasons Hotel Cairo at The First Residence', 77515: 'The First Collection at Jumeirah Village Circle', 69774: 'Waldorf Astoria Dubai International Financial Centre', 126601: 'Danang Marriott Resort & Spa Non Nuoc Beach Villas'}
NAMES=('hotel-posts-telegram.xml','hotel-posts-max.xml','hotel-posts-all.xml')
PREFIX=b'<?php\ndeclare(strict_types=1);\n'
def need(v,m):
 if not v:raise ValueError(m)
def sha(b):return hashlib.sha256(b).hexdigest()
def compact(x):return json.dumps(x,ensure_ascii=False,separators=(',',':')).encode()
def issha(x):return isinstance(x,str) and re.fullmatch('[a-f0-9]{64}',x) is not None
def readpin(path,digest):
 need(issha(digest),'missing_digest');b=path.read_bytes();need(sha(b)==digest,'evidence_digest_changed:'+path.name);return b
def within(root,name):
 need(isinstance(name,str) and name and not pathlib.Path(name).is_absolute(),'evidence_relative_path_required');p=root/name
 need(root.resolve() in p.resolve().parents and not p.is_symlink(),'evidence_path_escape');return p

def adapt(source,current,published):
 """Scoped runtime comparisons and reviewed feed-only labels; original approvals unchanged."""
 changes=[('"name":r["name"].replace("-"," ")','"name":PUBLIC_LABELS.get(hid,r["name"].replace("-"," "))'),('read.get("installed_sha")==a["expected_installed_sha"]','read.get("installed_sha")=='+repr(current)),('pub.get("installed_sha")==a["expected_installed_sha"]','pub.get("installed_sha")=='+repr(published))]
 result=source
 for a,b in changes:need(result.count(a)==1,'adapter_anchor_changed');result=result.replace(a,b,1)
 inverse=result
 for a,b in reversed(changes):need(inverse.count(b)==1,'inverse_anchor_changed');inverse=inverse.replace(b,a,1)
 need(inverse==source,'adapter_not_byte_reversible')
 return result

def lineage(op,folder,approval,pub):
 need(pub['installed_sha']==op['installed_sha_at_publication'],'publication_runtime_changed')
 if approval['expected_installed_sha']==pub['installed_sha']:
  need(op['approval_runtime_matches_original_publication'] is True and op['runtime_rebind_evidence'] is None,'unexpected_lineage_proof');return
 proof=op['runtime_rebind_evidence'];need(op['batch']==2 and isinstance(proof,dict),'unaccepted_runtime_rebind')
 need(proof=={'source_commit':'7ab5e0fd282035620601ddfe5f2e7dd42ef7dc53','workflow_sha256':'c86f331670c39058319898a8ac17c519949b6d3b3fc52c6645a946b4bcfbe38d','kind':'exact_single_expected_installed_sha_replacement_no_sender_change'},'P2_lineage_changed')
 readpin(folder/'original-runtime-rebind-workflow.yml',proof['workflow_sha256'])
 need(approval['expected_installed_sha']=='02f8267f39e6435c298fe75f646ce06da694dd7a' and pub['installed_sha']=='6d3da4fb7602de9e6dcd7ba3af06f70749606f1f','P2_runtime_pair_changed')

def preserve(before,after,file,total,count):
 blocks=lambda raw:re.findall(rb'<offer\b[^>]*>.*?</offer>',raw,re.S)
 old,new=blocks(before),blocks(after);factor=2 if file=='hotel-posts-all.xml' else 1
 need(len(old)==total*factor and len(new)==(total+count)*factor,'offer_count_changed')
 need(new[:len(old)]==old,'previous_offer_bytes_changed')
 need(all(b'<price' not in x.lower() and b'<oldprice' not in x.lower() for x in new[len(old):]),'unconfirmed_new_price')
 ids=re.findall(rb'<offer\b[^>]*\bid="([^"]+)"',after)
 need(len(ids)==len(set(ids))==len(new),'duplicate_offer_id')
 return {'name':file,'previous_offers':len(old),'old_offer_bytes_identical':True,'new_unpriced_offers':len(new)-len(old)}

def fresh(stamp):
 need(isinstance(stamp,str),'read_timestamp_missing')
 observed=datetime.datetime.fromisoformat(stamp.replace('Z','+00:00'));need(observed.tzinfo is not None,'read_timestamp_naive')
 need(-60<=(datetime.datetime.now(datetime.timezone.utc)-observed).total_seconds()<=900,'read_timestamp_stale')

def feed_operation(date,execution,batch):
 need(execution.get('repository')=='pyatkoff/max-search-bot' and type(execution.get('run_id')) is int and execution['run_id']>0 and execution.get('run_attempt')==1,'execution_run_scope_missing')
 need(type(batch) is int and 1<=batch<=26,'original_batch_invalid')
 return f"commercial-channel-hotel-feeds-{date}-update{execution['run_id']*100+batch}"

def evidence_gate(ad,inv,prefix=None):
 need(ad.get('schema_version')==1 and ad.get('state')=='AUTHENTICATED_TAIL217_FEED_COMPILATION_ONLY','admission_not_reviewed')
 need(ad.get('inventory_sha256')==INVENTORY_SHA and re.fullmatch('[a-f0-9]{40}',ad.get('expected_installed_sha','')) is not None,'admission_scope_changed')
 need(ad.get('original_batches')==[x['batch'] for x in inv['operations']] and ad.get('hotels')==217 and ad.get('previous_pairs')==(670 if prefix else 660) and ad.get('final_pairs')==877,'admission_counts_changed')
 for key,repo in [('current_read','pyatkoff/max-search-bot'),('route_install','pyatkoff/tour-max'),('preimage_read','pyatkoff/max-search-bot')]:
  p=ad.get(key,{})
  if key!='route_install' and p.get('source')=='THIS_AUTHENTICATED_WORKFLOW_RUN':
   need(p.get('repository')==repo and p.get('run_id')==ad.get('execution',{}).get('run_id') and p.get('run_attempt')==1 and p.get('conclusion')=='success' and issha(p.get('local_result_sha256')) and p.get('head_sha')==ad.get('execution',{}).get('head_sha') and re.fullmatch('[a-f0-9]{40}',p.get('head_sha','')) is not None,'current_run_evidence_missing:'+key)
  else:need(p.get('repository')==repo and p.get('run_attempt')==1 and p.get('conclusion')=='success' and isinstance(p.get('run_id'),int) and p['run_id']>0 and isinstance(p.get('artifact_id'),int) and p['artifact_id']>0 and issha(p.get('archive_sha256')) and re.fullmatch('[a-f0-9]{40}',p.get('head_sha','')) is not None,'authenticated_evidence_missing:'+key)
 accept=ad.get('runtime_acceptance',{});need(accept.get('state')=='DEPLOYED_AND_NATIVE_ACCEPTED' and accept.get('head_sha')==ad['expected_installed_sha'] and isinstance(accept.get('deploy_run_id'),int) and accept['deploy_run_id']>0 and issha(accept.get('native_acceptance_sha256')),'runtime_acceptance_missing')
 fresh(ad['current_read'].get('observed_at'))
 stamp=ad['preimage_read'].get('observed_at');need(isinstance(stamp,str),'preimage_timestamp_missing')
 observed=datetime.datetime.fromisoformat(stamp.replace('Z','+00:00'));need(observed.tzinfo is not None,'preimage_timestamp_naive')
 age=(datetime.datetime.now(datetime.timezone.utc)-observed).total_seconds();need(-60<=age<=900 and ad['preimage_read'].get('local_public_verified') is True,'fresh_public_preimages_required')
 need(set(ad.get('previous_feed_sha256',{}))==set(NAMES) and all(issha(x) for x in ad['previous_feed_sha256'].values()),'preimage_scope_changed')
 expected_batches=ad['original_batches'][1:] if prefix else ad['original_batches']
 need(len(ad.get('groups',[]))==len(expected_batches) and [g['batch'] for g in ad['groups']]==expected_batches,'admission_group_order_changed')
 if prefix:
  need(json.dumps(ad.get('resume_prefix'),sort_keys=True,separators=(',',':'))==json.dumps(prefix,sort_keys=True,separators=(',',':')),'admitted_prefix_changed')
  need(ad.get('remaining_hotels')==207,'remaining_scope_changed')
 else:need('resume_prefix' not in ad and 'remaining_hotels' not in ad,'unexpected_resume_fields')
 need(ad.get('current_registry_receipts_verified') is True and ad.get('old660_routes_preserved') is True and ad.get('monitor_pending_null') is True and ad.get('installed_hook_sha256')=='1ad41a8a3a8d10632e0e6127289481675f655c7f7f9a5bbe39b698c03e73e592','admission_boundary_missing')
 need([g.get('operation') for g in ad['groups']]==[feed_operation(ad.get('feed_date'),ad.get('execution',{}),g['batch']) for g in ad['groups']],'execution_operation_scheme_changed')
 need(ad.get('public_label_overrides')=={str(k):v for k,v in PUBLIC_LABELS.items()},'reviewed_short_labels_required')
 need(ad.get('public_label_originals')=={str(k):v for k,v in PUBLIC_LABEL_ORIGINALS.items()},'original_label_proof_required')
 need(re.fullmatch('[0-9]{8}',ad.get('feed_date','')) is not None,'feed_date_required')

def prepare(repo,originals,reads,routes,preimages,admission,out,reviewed_digest=REVIEWED_ADMISSION_SHA256,prefix_evidence=None):
 # Hash pin is the offline trust boundary. Do not derive it from the file being admitted.
 adraw=readpin(admission,reviewed_digest);ad=json.loads(adraw)
 inv=json.loads(readpin(originals/'inventory217.json',INVENTORY_SHA))
 need(inv['missing_proofs']==[] and inv['unique_hotels']==217 and inv['original_operations']==24 and inv['original_posts']==434,'original_inventory_invalid')
 prefix=None
 if prefix_evidence is not None:
  import prefix207_checkpoint as checkpoint
  need(set(prefix_evidence)=={'descriptor_raw','api_metadata','archives','fresh_probe_raw','price_evolution_evidence'},'prefix_evidence_scope')
  prefix=checkpoint.authenticate_checkpoint(prefix_evidence['descriptor_raw'],prefix_evidence['api_metadata'],prefix_evidence['archives'])
  need(inv['operations'][0]['batch']==1 and inv['operations'][0]['hotel_ids']==prefix['prefix_hotel_ids'],'prefix_inventory_ids_changed')
  probe_raw=prefix_evidence['fresh_probe_raw'];probe=json.loads(probe_raw)
  import price_evolution207 as evolution_helper
  ev=prefix_evidence['price_evolution_evidence'];evolution=evolution_helper.authenticate_evolution(ev['descriptor_raw'],ev['api'],ev['archive_raw'])
  need(evolution_helper.canon(evolution)==evolution_helper.canon(ad['price_evolution']),'admission_evolution_changed')
  evolution_helper.validate_current_preimage(prefix,evolution,probe,preimages,ad['execution'])
  need(sha(probe_raw)==ad['preimage_read'].get('local_result_sha256') and ad['preimage_read'].get('source')=='THIS_AUTHENTICATED_WORKFLOW_RUN','fresh_probe_execution_binding')
  need(probe['installed_sha']==ad['expected_installed_sha'],'probe_runtime_changed')
  need(probe.get('inventory_sha256')==INVENTORY_SHA,'probe_inventory_changed')
  need(ad['previous_feed_sha256']==evolution['current_feeds'],'resume_preimage_changed')
 evidence_gate(ad,inv,prefix);current=ad['expected_installed_sha']
 ops=repo/'tools/direct_demand/operations';sources={n:readpin(ops/n,h) for n,h in PINS.items()}
 for n in NAMES:readpin(preimages/n,ad['previous_feed_sha256'][n])
 need(not out.exists(),'output_exists_no_overwrite');out.mkdir(mode=0o700,parents=True)
 (out/'authenticated-admission.json').write_bytes(adraw)
 previous=preimages;total=670 if prefix else 660;results=[];seen=list(prefix['prefix_hotel_ids']) if prefix else []
 selected_ops=inv['operations'][1:] if prefix else inv['operations']
 for op,g in zip(selected_ops,ad['groups']):
  batch=op['batch'];ids=op['hotel_ids'];need(g['batch']==batch and 1<=len(ids)<=10,'batch_scope_changed');seen+=ids
  src=originals/f'p{batch:02d}';dest=out/f'p{batch:02d}';dest.mkdir(mode=0o700)
  mraw=readpin(src/'manifest.json',op['manifest_sha256']);araw=readpin(src/'approval.json',op['approval_sha256']);pubfile=readpin(src/'original-native-output.jsonl',op['original_output_sha256']);publine=pubfile.splitlines()[-1];pub=json.loads(publine);a=json.loads(araw)
  need(sha(publine)==op['summary_line_sha256'],'original_summary_changed');lineage(op,src,a,pub)
  for row in json.loads(mraw)['rows']:
   if row['hotel_id'] in PUBLIC_LABELS:need(row['text'].split('\n',1)[0].removeprefix('✈️ Туры в ')==PUBLIC_LABEL_ORIGINALS[row['hotel_id']],'original_full_label_changed')
  rd=readpin(within(reads,g['read_file']),g['read_sha256']);read=json.loads(rd);fresh(read.get('read_at_utc'))
  rr=readpin(within(routes,g['route_receipt_file']),g['route_receipt_sha256']);rmraw=readpin(within(routes,g['route_manifest_file']),g['route_manifest_sha256']);route=json.loads(rr);rm=json.loads(rmraw)
  need(route.get('ok') is True and route.get('state')=='INSTALLED_AND_NATIVE_VERIFIED','route_not_installed')
  need(rm.get('previous_count')==total and rm.get('logical_hotels')==total+len(ids) and rm.get('new_hotel_ids')==ids,'route_batch_changed')
  projection='route_proof_file' in g
  if projection:
   proofraw=readpin(within(routes,g['route_proof_file']),g['route_proof_sha256']);(dest/'route-proof.json').write_bytes(proofraw)
  else:
   mapraw=readpin(within(routes,g['route_map_file']),g['route_map_sha256']);need(sha(mapraw)==rm['files'][0]['sha256'],'route_map_not_installed_bytes');(dest/'route-map.php').write_bytes(mapraw)
  for name,b in [('manifest.json',mraw),('approval.json',araw),('publication.jsonl',pubfile),('bound-read.json',rd),('route-receipt.json',rr),('route-manifest.json',rmraw)]: (dest/name).write_bytes(b)
  operation=feed_operation(ad['feed_date'],ad['execution'],batch)
  tail={'schema_version':1,'operation':operation,'expected_installed_sha':a['expected_installed_sha'],'previous_count':total,'manifest_sha256':sha(mraw),'publication_summary_sha256':sha(publine),'bound_read_sha256':sha(rd),'route_receipt_sha256':sha(rr),'route_manifest_sha256':sha(rmraw),'previous_feed_sha256':{n:sha((previous/n).read_bytes()) for n in NAMES},'writer_sha256':PINS['publish-commercial-channel-hotel-feeds-20261103-1.php'],'monitor_contract_sha256':PINS['monitor-price-contract.php']}
  if projection:tail.update(original_route_manifest_sha256=g['original_route_manifest_sha256'],route_proof_sha256=g['route_proof_sha256'])
  (dest/'admission.json').write_bytes(compact(tail))
  source=sources['prepare-publication-tail.py'].decode();adapted=adapt(source,current,pub['installed_sha']);module=types.ModuleType('exact_existing_tail_adapter');module.PUBLIC_LABELS=PUBLIC_LABELS;exec(compile(adapted,'exact_existing_tail_adapter','exec'),module.__dict__)
  args=argparse.Namespace(manifest=dest/'manifest.json',approval=dest/'approval.json',admission=dest/'admission.json',publication=dest/'publication.jsonl',bound_read=dest/'bound-read.json',route_receipt=dest/'route-receipt.json',route_manifest=dest/'route-manifest.json',route_map=None if projection else dest/'route-map.php',route_proof=dest/'route-proof.json' if projection else None,previous_feed_dir=previous,writer=ops/'publish-commercial-channel-hotel-feeds-20261103-1.php',monitor_contract=ops/'monitor-price-contract.php',output=dest/'writer-original.php')
  with contextlib.redirect_stdout(io.StringIO()):module.feed(args)
  raw=(dest/'writer-original.php').read_bytes();old=("const EXPECTED_INSTALLED_SHA='"+a['expected_installed_sha']+"';").encode();new=("const EXPECTED_INSTALLED_SHA='"+current+"';").encode()
  need(raw.startswith(PREFIX+old) and raw.count(old)==1,'writer_header_changed');writer_current=raw.replace(old,new,1);need(writer_current.replace(new,old,1)==raw,'writer_body_changed')
  guard=sources['commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php'];need(guard.startswith(PREFIX),'guard_prefix_changed');program=PREFIX+guard[len(PREFIX):]+b'\n'+writer_current[len(PREFIX):];(dest/'write.php').write_bytes(program)
  inp=(dest/'writer-original.php.input.json').read_bytes();data=json.loads(inp);need(data['schema_version']==4 and data['operation']==operation,'writer_schema_changed')
  after=dest/'after';after.mkdir(mode=0o700);preserved=[]
  for f in data['files']:
   before=base64.b64decode(f['previous_content_base64'],validate=True);nextbytes=base64.b64decode(f['content_base64'],validate=True)
   need(sha(before)==f['expected_previous_sha256'] and sha(nextbytes)==f['sha256'],'render_digest_changed')
   preserved.append(preserve(before,nextbytes,f['name'],total,len(ids)));(after/f['name']).write_bytes(nextbytes)
  subprocess.run(['php','-l',str(dest/'write.php')],capture_output=True,check=True)
  results.append({'batch':batch,'operation':operation,'hotels':len(ids),'original_publication_operation':op['operation'],'original_approval_sha256':op['approval_sha256'],'original_publication_runtime':pub['installed_sha'],'current_execution_runtime':current,'adapter_inverse_byte_identity':True,'writer_body_unchanged':True,'nightly_guard_unchanged':False,'nightly_guard_exact_hook_pin':True,'script_sha256':sha(program),'input_sha256':sha(inp),'route_receipt_sha256':sha(rr),'files':[{k:f[k] for k in ['name','sha256','bytes','rows','expected_previous_sha256']} for f in data['files']],'preservation':preserved})
  previous=after;total+=len(ids)
 need(total==877 and len(set(seen))==217 and seen==inv['hotel_ids_in_original_batch_order'],'final_scope_changed')
 result={'state':'PREPARED_NOT_WRITTEN_NOT_EXECUTION_APPROVAL','hotels':207 if prefix else 217,'previous_pairs':670 if prefix else 660,'final_pairs':877,'groups':results,'inventory_sha256':INVENTORY_SHA,'reviewed_admission_sha256':reviewed_digest,'new_prices':0,'new_messages':0,'supplier_calls':0,'production_writes':0,'writer_runs':0,'requires_fresh_execution_admission':True,'public_label_overrides':{str(k):v for k,v in PUBLIC_LABELS.items()},'public_label_originals':{str(k):v for k,v in PUBLIC_LABEL_ORIGINALS.items()}}
 if prefix:
  need(len(results)==23 and sum(x['hotels'] for x in results)==207 and not (out/'p01').exists(),'suffix_writer_scope_changed')
  result.update(price_evolution=evolution,resume_prefix=prefix,original_hotels=217,completed_prefix=[1],previous_native_receipt_sha256=prefix['previous_native_receipt_sha256'])
 (out/'intent.json').write_bytes(compact(result));return result
if __name__=='__main__':
 p=argparse.ArgumentParser();
 for n in ['repo','originals','reads','routes','preimages','admission','out']:p.add_argument(n,type=pathlib.Path)
 a=p.parse_args();r=prepare(**vars(a));print(json.dumps({k:r[k] for k in ['state','hotels','previous_pairs','final_pairs','production_writes','writer_runs']}))
