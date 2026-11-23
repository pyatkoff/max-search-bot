"""Exact offline price-evolution edge. Historical prefix is never rewritten.
No network, production writes, or authorization. Closed until exact descriptor review.
"""
import datetime,hashlib,json,pathlib,re,zipfile,io,stat
REVIEWED_EVOLUTION_SHA256='c66c2fbde42e118b05fb9edd8efd898644c8a9e4572d17a4d8268e72e9d7f466'
HISTORICAL={'hotel-posts-telegram.xml':'dd0ca832b3e8721c19b990e2acc2ddaf55361c07f72c5584d7cbae18b90266c7','hotel-posts-max.xml':'2230ff13613de02f7c4fe637bc3442dc4da4eef49826c16904835860ad8d1335','hotel-posts-all.xml':'397e4fef3a4004beaff124bbebcf9860144a2163c076b514b7522b023d0327ec'}
HOOK='1ad41a8a3a8d10632e0e6127289481675f655c7f7f9a5bbe39b698c03e73e592'
RUNTIME='cc017ee5bb669f4c7c13efc91d5c5812a50e55f2'
sha=lambda b:hashlib.sha256(b).hexdigest()
canon=lambda x:json.dumps(x,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()
def need(x,s):
 if not x:raise ValueError(s)
def projection(raw):
 need(len(raw)<=4000000 and b'<!DOCTYPE' not in raw and b'<!ENTITY' not in raw,'unsafe_xml')
 matches=list(re.finditer(rb'<offer id="([A-Za-z0-9_-]+)">.*?</offer>',raw,re.S));ids=[m[1].decode() for m in matches];need(len(ids)==len(set(ids)),'duplicate_ids')
 need(raw.count(b'<offer ')==len(ids),'unparsed_offer')
 envelope=re.sub(rb'<offer id="([A-Za-z0-9_-]+)">.*?</offer>',b'OFFER',raw,flags=re.S);envelope=re.sub(rb'<yml_catalog date="[^"<>]+">',b'<yml_catalog date="DATE">',envelope)
 return ids,{m[1].decode():m[0] for m in matches},envelope

def verify_xml_edge(historical_dir,current_dir,prefix_offer_ids):
 need(len(prefix_offer_ids)==20 and len(set(prefix_offer_ids))==20,'prefix_scope')
 result={}
 for name,digest in HISTORICAL.items():
  old=(pathlib.Path(historical_dir)/name).read_bytes();new=(pathlib.Path(current_dir)/name).read_bytes();need(sha(old)==digest,'historical_hash')
  ids,a,env=projection(old);nids,b,nenv=projection(new);need(ids==nids and env==nenv,'ids_order_or_envelope_changed');need(len(ids)==(1340 if name.endswith('all.xml') else 670),'count')
  scope=set(prefix_offer_ids)&set(ids);need(len(scope)==(20 if name.endswith('all.xml') else 10),'prefix_membership')
  stable=lambda raw:re.sub(rb'\s*<(price|currencyId|description)>.*?</\1>',b'',raw,flags=re.S)
  for k in ids:
   need(stable(a[k])==stable(b[k]),'nonprice_changed')
   if k in scope:need(a[k]==b[k],'prefix_offer_changed')
  result[name]={'historical_sha256':digest,'current_sha256':sha(new),'bytes':len(new),'rows':len(ids),'ids_order_sha256':sha(canon(ids)),'envelope_sha256':sha(env),'changed_rows':sum(a[k]!=b[k] for k in ids),'prefix_full_bytes_preserved':True}
 return result

def authenticate_evolution(descriptor_raw,api,archive_raw,historical_dir=None,current_dir=None):
 need(REVIEWED_EVOLUTION_SHA256 is not None and sha(descriptor_raw)==REVIEWED_EVOLUTION_SHA256,'closed_price_evolution_gate');d=json.loads(descriptor_raw)
 need(d['kind']=='EXACT_AUTHENTICATED_PRICE_EVOLUTION_670_NOT_PREFIX_REWRITE','kind');identity=d['artifact'];need(identity['run_id']==44217252234 and identity['repository']=='pyatkoff/poisk-turov-test','price_run')
 need(api['run']['id']==identity['run_id'] and api['run']['head_sha']==identity['head_sha'] and api['run']['status']=='completed' and api['run']['conclusion']=='success' and type(api['run']['run_attempt']) is int and api['run']['run_attempt']==1,'price_run_not_accepted')
 a=api['artifact'];need(a['id']==identity['artifact_id'] and a['workflow_run']['id']==identity['run_id'] and a['workflow_run']['head_sha']==identity['head_sha'] and a['expired'] is False and a['digest']=='sha256:'+identity['archive_sha256'] and sha(archive_raw)==identity['archive_sha256'],'artifact_identity')
 need(d['runtime']==RUNTIME and d['hook']==HOOK,'runtime_hook')
 with zipfile.ZipFile(io.BytesIO(archive_raw)) as z:
  names=z.namelist();need(len(names)==len(set(names)),'duplicate_zip_member')
  for info in z.infolist():
   path=pathlib.PurePosixPath(info.filename);need(not path.is_absolute() and '..' not in path.parts and not stat.S_ISLNK(info.external_attr>>16),'unsafe_zip')
  members={}
  for name,digest in d['receipt_members'].items():
   need(name in names and z.getinfo(name).file_size<=12000000,'receipt_member');raw=z.read(name);need(sha(raw)==digest,'receipt_hash');members[name]=json.loads(raw)
 feed=members[d['final_feed_member']];inspect=members[d['final_inspect_member']];need(feed['ok'] is True and inspect['ok'] is True,'price_receipt_failed')
 pf=inspect['preflight'];need(pf['app_head']==RUNTIME and pf['new_sha']==HOOK and pf['new_bytes']==631611 and pf['tracked_clean'] is True and pf['pins_match'] is True and pf['pending_null'] is True and pf['cycle_terminal'] is True and pf['active_collectors']==0,'price_final_state')
 evidence=d['xml_edge']
 if historical_dir is not None or current_dir is not None:
  need(historical_dir is not None and current_dir is not None,'offline_both_dirs_required')
  need(canon(verify_xml_edge(historical_dir,current_dir,d['prefix_offer_ids']))==canon(evidence),'xml_edge_changed')
 need(set(evidence)==set(HISTORICAL),'edge_scope')
 for name,x in evidence.items():
  need(x['historical_sha256']==HISTORICAL[name] and x['prefix_full_bytes_preserved'] is True,'history_edge')
  f=feed['files'][name];need(f['sha256']==f['public_sha256']==x['current_sha256'] and f['public_http']==200 and f['bytes']==x['bytes'],'price_native_public_binding')
  historical=d['historical_rows'][name];need(list(historical)==list(f['rows']) and len(historical)==x['rows'],'native_row_order')
  need(f['envelope']==x['envelope_sha256'],'native_envelope')
  for key,row in historical.items():
   need(row['stable']==f['rows'][key]['stable'],'native_nonprice_changed')
   if key in d['prefix_offer_ids']:need(row['full']==f['rows'][key]['full'],'native_prefix_changed')
 return {'kind':'AUTHENTICATED_EXACT_PRICE_EVOLUTION_NOT_CURRENT_ADMISSION','descriptor_sha256':sha(descriptor_raw),'historical_feeds':dict(HISTORICAL),'current_feeds':{k:v['current_sha256'] for k,v in evidence.items()},'installed_hook_sha256':HOOK,'runtime_sha':RUNTIME,'artifact':identity}

def validate_current_preimage(prefix,evolution,probe,preimages,execution):
 need(prefix['kind']=='AUTHENTICATED_EXACT_PREFIX1_NOT_CURRENT_ADMISSION' and canon(prefix['expected_feeds'])==canon(HISTORICAL),'historical_prefix_changed')
 need(evolution['kind']=='AUTHENTICATED_EXACT_PRICE_EVOLUTION_NOT_CURRENT_ADMISSION' and REVIEWED_EVOLUTION_SHA256 is not None and evolution['descriptor_sha256']==REVIEWED_EVOLUTION_SHA256,'evolution_not_authenticated')
 need(canon(evolution['historical_feeds'])==canon(HISTORICAL) and evolution['installed_hook_sha256']==HOOK and evolution['runtime_sha']==RUNTIME,'evolution_scope')
 need(execution['repository']=='pyatkoff/max-search-bot' and type(execution['run_id']) is int and execution['run_id']!=44163849869 and execution['run_attempt']==1,'new_execution_required')
 need(probe['ok'] is True and probe['state']=='READONLY_PREIMAGE_PROBE_COMPLETE_NOT_EXECUTION_ADMISSION' and probe['tracked_tree_clean'] is True and probe['local_public_verified'] is True,'fresh_probe_required')
 need(probe['installed_hook_sha256']==HOOK,'hook_changed')
 need(all(type(probe[k]) is int and probe[k]==0 for k in ['production_writes','provider_calls','message_sends','writer_runs']),'probe_boundary')
 for k in ['monitor_before','monitor_after']:need(probe[k]['pending_null'] is True and probe[k]['global_ex_nb_available'] is True and probe[k]['lock_released'] is True,'monitor_not_idle')
 stamp=datetime.datetime.fromisoformat(probe['observed_at'].replace('Z','+00:00'));need(stamp.tzinfo is not None and 0<=(datetime.datetime.now(datetime.timezone.utc)-stamp).total_seconds()<=900,'probe_stale')
 need(len(probe['files'])==3 and {x['name'] for x in probe['files']}==set(HISTORICAL),'probe_files')
 for f in probe['files']:
  need(f['public_local_identical'] is True and f['sha256']==evolution['current_feeds'][f['name']] and f['offers']==(1340 if f['name']=='hotel-posts-all.xml' else 670),'evolved_preimage_changed')
  raw=(pathlib.Path(preimages)/f['name']).read_bytes();need(sha(raw)==f['sha256'] and len(raw)==f['bytes'],'preimage_bytes')
 return dict(evolution['current_feeds'])
