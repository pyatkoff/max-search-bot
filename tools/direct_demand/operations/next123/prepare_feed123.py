"""Recovered CLOSED narrow adapter; old inventory metadata was NOT byte-restored.
No native/network calls. Admission/hook/guard/metadata review gates remain CLOSED.
"""
import pathlib,hashlib,json,types,re
ROOT=pathlib.Path(__file__).resolve().parent
BASE=ROOT/'base_compiler/prepare_feed217.py'
BASE_SHA='8499ff729a194e0bdc62c5c505a857ee125c295f741680689a9cbcaf2af8af14'
HISTORICAL_INVENTORY_SHA='f721ec8749809a54e3f98b914a3fbeb27f336fcd5a58f8fc54a62b6bd8c4df07'
HISTORICAL_BINDING_SHA='b10b444397d71d2fbb0d6a6543f8eca7e5a70867ca9cea8500344dcc7cd0b58b'
INPUT_SHA='8d13a4bc065fdbcc4ed920fccc9ee57c0adca12b626a671d36981c11dcfaf40a'
RECOVERED_INVENTORY_SHA='b10c580f83bb994e22f705c6712ff3a17e188dcc13825aec82f131222fda0843'
RECOVERED_BINDING_SHA='8462dfb9d319c8cef24ca742b49b328a77a6191bf547c87acd21192673c677cd'
REVIEWED_RECOVERY_METADATA=False
REVIEWED_ADMISSION_SHA256=None
REVIEWED_INSTALLED_HOOK_SHA256=None
REVIEWED_NIGHTLY_GUARD_SHA256=None
LABELS={45232:'Sathorn Vista Bangkok Marriott'}
LABEL_ORIGINALS={45232:'Sathorn Vista Bangkok Marriott Executive Apartments'}
ORDER=list(range(28,42));COUNTS=[10]*7+[9]+[10]*4+[3,1]
H=lambda b:hashlib.sha256(b).hexdigest()
def need(x,m):
 if not x:raise ValueError(m)
def pin(p,h):
 raw=p.read_bytes();need(H(raw)==h,'frozen_input_changed:'+p.name);return json.loads(raw)
def inventory_projection(root):
 inv=pin(root/'recovered-inventory-next123.json',RECOVERED_INVENTORY_SHA);inp=pin(root/'reader-input123.json',INPUT_SHA);binding=pin(root/'recovered-route-publication-binding.json',RECOVERED_BINDING_SHA)
 need(binding['groups']==inv['groups'] and binding['reader_input_sha256']==INPUT_SHA,'mixed_lineage_changed')
 need([g['batch'] for g in inp['groups']]==ORDER and [len(g['hotel_ids']) for g in inp['groups']]==COUNTS,'frozen_scope_changed');ops=[]
 for g,lg in zip(inp['groups'],binding['groups']):
  b=g['batch'];src=root/'original-groups'/f'p{b:02d}';spec=lg['completion_summary'];raw=(src/pathlib.Path(spec['member']).name).read_bytes();need(H(raw)==spec['member_sha256'],'original_native_bytes_changed');line=raw.splitlines()[-1];pub=json.loads(line)
  need(H(line)==lg['completion_summary_line_sha256'] and pub['operation']==lg['completion_operation'] and pub['installed_sha']==lg['installed_publication_runtime'],'original_completion_changed')
  need(pub['new_posts']==lg['original_new_posts'] and pub['reused']==lg['original_reused_posts'],'historical_counts_changed')
  expected=[dict(r['posts'][p],photo_url=r['photo_url']) for r in g['hotels'] for p in ['telegram','max']];actual={(p['hotel_id'],p['platform']):p for p in pub['posts']};need(len(actual)==len(pub['posts'])==len(expected),'original_pairs_changed')
  for p in expected:need(actual[(p['hotel_id'],p['platform'])]==p,'mixed_pair_not_actual_completion')
  a=pin(src/'approval.json',lg['original_approval']['member_sha256']);pin(src/'manifest.json',lg['original_manifest']['member_sha256']);need(a['expected_installed_sha']==pub['installed_sha'],'historical_approval_runtime_changed')
  ops.append({'batch':b,'hotel_ids':g['hotel_ids'],'operation':lg['completion_operation'],'manifest_sha256':lg['original_manifest']['member_sha256'],'approval_sha256':lg['original_approval']['member_sha256'],'original_output_sha256':spec['member_sha256'],'original_output_member':pathlib.Path(spec['member']).name,'summary_line_sha256':lg['completion_summary_line_sha256'],'installed_sha_at_publication':pub['installed_sha'],'approval_runtime_matches_original_publication':True,'runtime_rebind_evidence':None})
 return {'kind':'EXACT_COMPILER_PROJECTION_NOT_SENDER_SUMMARY','missing_proofs':[],'unique_hotels':123,'original_operations':14,'original_posts':246,'operations':ops,'hotel_ids_in_original_batch_order':[h for g in inp['groups'] for h in g['hotel_ids']]}
def validate_current_receipts(read,pub):
 expected={(p['hotel_id'],p['platform']):p for p in pub['posts']};rs=read['current_registry_receipts'];need(len(rs)==len(expected),'current_receipt_count');seen=set()
 for x in rs:
  key=(x['hotel_id'],x['platform']);need(key in expected and key not in seen,'current_receipt_pair');seen.add(key);p=expected[key]
  need(x['current_registry_receipt_verified'] is True and x['provider_read_performed'] is False,'current_receipt_unverified')
  need(all(type(x[k]) is int and x[k]==p[k] for k in ['hotel_id','publication_id','version_id']),'current_receipt_version_changed')
  need(all(x[k]==p[k] for k in ['post_url','text_sha256','button_url','button_text']) and x['original_photo_sha256']==p['photo_sha256'] and x['image_count']==1,'current_receipt_content_changed')
def scoped_module():
 raw=BASE.read_bytes();need(H(raw)==BASE_SHA,'base_compiler_changed');s=raw.decode()
 def sub(a,b):
  nonlocal s
  need(s.count(a)==1,'base_anchor_changed:'+a[:100]);s=s.replace(a,b,1)
 start=s.index(' prefix=None;continuation=None\n',s.index('def prepare('));end=s.index(' evidence_gate(ad,inv,prefix,continuation);',start)
 s=s[:start]+' prefix=None;continuation=None\n need(prefix_evidence is None,"no_feed123_resume_without_review")\n'+s[end:]
 sub("inv=json.loads(readpin(originals/'inventory217.json',INVENTORY_SHA))","inv=inventory_projection(originals)")
 sub("inv['unique_hotels']==217 and inv['original_operations']==24 and inv['original_posts']==434","inv['unique_hotels']==123 and inv['original_operations']==14 and inv['original_posts']==246")
 sub("src=originals/f'p{batch:02d}'","src=originals/'original-groups'/f'p{batch:02d}'")
 sub("src/'original-native-output.jsonl'","src/op['original_output_member']")
 sub("read=json.loads(rd);fresh(read.get('read_at_utc'))","read=json.loads(rd);fresh(read.get('read_at_utc'));validate_current_receipts(read,pub)")
 sub("1<=batch<=26","28<=batch<=41")
 sub("'AUTHENTICATED_TAIL217_FEED_COMPILATION_ONLY'","'AUTHENTICATED_NEXT123_FEED_COMPILATION_ONLY'")
 sub("ad.get('hotels')==217","ad.get('hotels')==123")
 sub("ad.get('previous_pairs')==(680 if continuation else (670 if prefix else 660)) and ad.get('final_pairs')==877","ad.get('previous_pairs')==877 and ad.get('final_pairs')==1000")
 sub("ad.get('old660_routes_preserved')","ad.get('old877_routes_preserved')")
 sub("ad.get('installed_hook_sha256')=='1ad41a8a3a8d10632e0e6127289481675f655c7f7f9a5bbe39b698c03e73e592'","ad.get('installed_hook_sha256')==REVIEWED_INSTALLED_HOOK_SHA256")
 sub("total=680 if continuation else (670 if prefix else 660)","total=877")
 sub("total==877 and len(set(seen))==217","total==1000 and len(set(seen))==123")
 sub("'hotels':197 if continuation else (207 if prefix else 217),'previous_pairs':680 if continuation else (670 if prefix else 660),'final_pairs':877","'hotels':123,'previous_pairs':877,'final_pairs':1000")
 m=types.ModuleType('exact_existing_feed123_adapter');exec(compile(s,'exact_existing_feed123_adapter','exec'),m.__dict__)
 original_adapt=m.adapt
 def exact_read_operation_adapter(source,current,published):
  adapted=original_adapt(source,current,published)
  old='re.fullmatch(r"commercial-channel-hotel-feeds-\\d{8}-read[1-9]\\d*",read.get("operation",""))'
  new='re.fullmatch(r"commercial-channel-hotel-feeds-20261118-next123-read(?:2[89]|3[0-9]|4[01])",read.get("operation",""))'
  need(adapted.count(old)==1 and new not in adapted,'exact123_operation_anchor_changed')
  result=adapted.replace(old,new,1)
  need(result.replace(new,old,1)==adapted,'operation_adapter_not_byte_reversible')
  return result
 m.adapt=exact_read_operation_adapter
 m.INVENTORY_SHA=RECOVERED_INVENTORY_SHA;m.PUBLIC_LABELS=LABELS;m.PUBLIC_LABEL_ORIGINALS=LABEL_ORIGINALS;m.inventory_projection=inventory_projection;m.validate_current_receipts=validate_current_receipts;m.REVIEWED_INSTALLED_HOOK_SHA256=REVIEWED_INSTALLED_HOOK_SHA256
 m.PINS['commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php']=REVIEWED_NIGHTLY_GUARD_SHA256
 return m

def prepare(repo,originals,reads,routes,preimages,admission,out):
 need(REVIEWED_RECOVERY_METADATA is True,'RECOVERY_METADATA_NOT_REVIEWED')
 need(all(isinstance(h,str) and re.fullmatch('[a-f0-9]{64}',h) for h in [REVIEWED_ADMISSION_SHA256,REVIEWED_INSTALLED_HOOK_SHA256,REVIEWED_NIGHTLY_GUARD_SHA256]),'FEED123_ACTUAL_READ_ROUTES_PRICE_ADMISSION_CLOSED')
 guard=(repo/'tools/direct_demand/operations/commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php').read_bytes();need(H(guard)==REVIEWED_NIGHTLY_GUARD_SHA256,'reviewed_native_guard_changed')
 expected=("hash_file('sha256',$extensionHook)==='"+REVIEWED_INSTALLED_HOOK_SHA256+"'").encode();need(guard.count(expected)==1,'guard_hook_pin_mismatch')
 m=scoped_module();result=m.prepare(repo,originals,reads,routes,preimages,admission,out,reviewed_digest=REVIEWED_ADMISSION_SHA256)
 need(len(result['groups'])==14 and sum(g['hotels'] for g in result['groups'])==123,'final14_scope');return result
