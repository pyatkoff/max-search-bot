"""Exact CONTENT49 terminal handoff. No new174 admission or publication authority."""
import json,hashlib,zipfile
H=lambda b:hashlib.sha256(b).hexdigest()
def need(x,m):
 if not x:raise ValueError(m)
def validate_local(root,d):
 for n,h in d['files'].items():need(H((root/'content49'/n).read_bytes())==h,'content_evidence_changed:'+n)
 r=json.loads((root/'content49/receipt49.json').read_bytes());need(r['unique_new_hotels']==49 and r['new_posts']==98 and r['this_continuation_new_pairs']==29 and r['paired_total_after']==1235,'content_counts')
 need(r['replayed_completed_operations']==0 and r['feed_writes']==0 and r['price_queries']==0,'content_side_effects')
 ids=[];posts=[];ops=[]
 for a in d['artifacts']:
  z=zipfile.ZipFile(root/'content49'/str(a['id']+'.zip'))
  if a['kind']=='aggregate':continue
  raw=z.read('original-native-output.jsonl');s=json.loads(raw.splitlines()[-1]);t=json.loads(z.read('terminal-verified.json'));m=json.loads(z.read('manifest.json'))
  need(z.read('original-exit.txt').strip()==b'0' and s['ok'] is True and s['reused']==0,'native_not_success')
  need(H(raw)==t['original_output_sha256'] and s['operation']==t['operation']==m['operation'],'native_operation_binding')
  need(s['posts']==t['posts'] and len(s['posts'])==2*a['pairs']==s['new_posts'],'native_pair_count')
  for p in s['posts']:
   mr=next(x for x in m['rows'] if x['hotel_id']==p['hotel_id']);need(H(mr['text'].encode())==p['text_sha256'] and mr['photo_sha256']==p['photo_sha256'] and p['body_photo_button_verified'] is True,'native_content_binding')
   rr=next(x for x in r['rows'] if x['hotel_id']==p['hotel_id']);rp=next(x for x in rr['posts'] if x['platform']==p['platform']);need(all(rp[k]==v for k,v in p.items()),'aggregate_post_mismatch')
  ids.extend(x['hotel_id'] for x in m['rows']);posts+=s['posts'];ops.append({'operation':s['operation'],'unique_new_hotels':a['pairs'],'original_output_sha256':H(raw)})
 need(len(ids)==len(set(ids))==49 and len({(p['platform'],p['publication_id']) for p in posts})==98,'duplicate_pairs')
 z=zipfile.ZipFile(root/'content49/14418897828.zip');agg=json.loads(z.read('wave10-native-verified.json'));need(agg['operations']==ops[2:] and agg['posts']==posts[40:] and agg['unique_new_hotels']==29 and agg['paired_total_after']==1235,'continuation_aggregate')
 return {'content_terminal':True,'new_pairs':49,'continuation_pairs':29,'original_failed_run_preserved':True,'reader174_scope_changed':False}
def validate(config,root,api):
 d=json.loads((root/'content49/descriptor.json').read_bytes());need(H((root/'content49/descriptor.json').read_bytes())==DESCRIPTOR_SHA,'descriptor_changed')
 need(config['content_terminal_handoff']=={'kind':'EXACT_CONTENT49_TERMINAL','descriptor_sha256':DESCRIPTOR_SHA},'handoff_scope')
 need(config['content_window_ack']=={'comment_id':6961029169,'body_sha256':d['claim_body_sha256']},'claim_scope')
 for n,h in d['files'].items():need(config['source_sha256'].get('content49/'+n)==h,'content_source_pin')
 result=validate_local(root,d)
 claim=api('max-search-bot','issues/comments/6961029169');need(claim['user']['id']==226193297 and H(claim['body'].encode())==d['claim_body_sha256'],'content_owner_changed')
 need(api('max-search-bot','git/ref/heads/audit/content100-bot-publication-20261101')['object']['sha']==d['carrier'],'content_carrier_moved')
 for run in d['runs']:
  r=api('max-search-bot','actions/runs/'+str(run['id']));need(r['id']==run['id'] and r['head_sha']==run['head'] and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']==run['conclusion'],'original_run_changed')
 for a in d['artifacts']:
  r=api('max-search-bot','actions/artifacts/'+a['id']);need(r['id']==int(a['id']) and r['digest']=='sha256:'+a['sha256'] and r['expired'] is False and r['workflow_run']['id']==a['run'] and r['workflow_run']['head_sha']==a['head'],'artifact_binding')
 return result
DESCRIPTOR_SHA='2ffab378a7a6b197435bd970710370bcaf9cac1e46f008325b61b0bb90f0cd68'
