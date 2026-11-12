import json,hashlib,sys,os
from pathlib import Path

def sha(raw):return hashlib.sha256(raw).hexdigest()
def require(ok,why):
 if not ok:raise RuntimeError(why)
def save(p,v):p.write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')

def result(private,safe,name,status):
 m=json.loads((private/f'{name}.manifest.json').read_bytes());raw=(private/f'{name}.runtime.jsonl').read_bytes()
 out={'operation':m['operation'],'manifest_sha256':sha((private/f'{name}.manifest.json').read_bytes()),'runtime_sha256':sha(raw),'ssh_status':status,'validated':False,'posts':[],'retry_authorized':False}
 try:
  require(0<len(raw)<5000000 and raw.endswith(b'\n'),'runtime_output_incomplete')
  r=json.loads(raw.splitlines()[-1]);out['runtime_error']=r.get('error');targets={x['publication_id']:x for x in m['rows']};seen=set()
  for p in r.get('posts',[]):
   pid=p.get('publication_id');require(pid in targets and pid not in seen,'receipt_identity_changed');seen.add(pid);x=targets[pid]
   for k in ['hotel_id','platform','publication_id','message_id','post_url','photo_signature','button_url_sha256','source_key']:require(p[k]==x[k],'receipt_binding_changed_'+k)
   require(p['previous_version_id']==x['version_id'] and type(p['version_id']) is int and p['version_id']!=x['version_id'],'receipt_version_changed')
   require(p['text_sha256']==x['new_text_sha256'] and p['previous_text_sha256']==x['old_text_sha256'] and p['previous_attachments_sha256']==x['attachments_sha256'],'receipt_caption_photo_changed')
   require(p['state']=='sent' and p['button_text']==m['button_text'],'receipt_not_sent')
   for k in ['body_photo_button_verified','formatting_verified','existing_message_reused','existing_token_resolves_to_current_version']:require(p[k] is True,'receipt_unverified_'+k)
   out['posts'].append(p)
  require(status==0 and r['ok'] is True,'runtime_not_successful')
  require(r['operation']==m['operation'] and r['manifest_sha256']==out['manifest_sha256'] and r['installed_sha']==m['expected_installed_sha'],'runtime_provenance_changed')
  require(r['mode']=='approved_existing_caption_quality_revision','runtime_mode_changed')
  require(len(out['posts'])==len(targets)==r['post_write_attempts']==r['caption_updates']==r['preflight_existing_messages'],'runtime_count_changed')
  for k in ['new_posts_created','configuration_write_attempts','button_write_attempts','tour_search_calls','price_queries']:require(r[k]==0,'outside_scope_'+k)
  out['validated']=True;out['confirmed_posts']=len(out['posts'])
 except Exception as e:out['verification_error']=str(e)
 save(safe/f'{name}.result.json',out)
 print(json.dumps({k:out.get(k) for k in ['operation','validated','confirmed_posts','runtime_error','verification_error']}))
 require(out['validated'],'unconfirmed_result_no_retry')

def final(private,safe,status):
 cohort=json.loads((private/'approved-cohort.json').read_bytes());expected={p['publication_id']:p for p in cohort['rows']};confirmed={}
 for name in ['canary','batch001','batch002','batch003','batch004']:
  r=json.loads((safe/f'{name}.result.json').read_bytes());require(r['validated'] is True,'prior_result_not_validated')
  for p in r['posts']:require(p['publication_id'] not in confirmed,'duplicate_receipt');confirmed[p['publication_id']]=p
 raw=(safe/'final-snapshot.json').read_bytes();r=json.loads(raw)
 require(status==0 and r['ok'] is True and r['installed_sha']==cohort['expected_installed_sha'],'final_read_failed')
 require(r['post_write_attempts']==r['provider_calls']==0 and len(r['posts'])==len(expected)==len(confirmed)==176,'final_scope_changed')
 seen=set()
 for p in r['posts']:
  pid=p['publication_id'];require(pid in expected and pid not in seen,'final_identity_changed');seen.add(pid);x=expected[pid];receipt=confirmed[pid]
  for k in ['hotel_id','platform','publication_id','message_id','post_url','source_key','config_version','photo_signature','button_url_sha256']:require(p[k]==x[k],'final_binding_changed_'+k)
  require(p['status']=='sent' and p['button_observed_in_snapshot'] is True and p['button_resolves_to_current_post'] is True and p['media_count']==1,'final_publication_unconfirmed')
  require(p['caption']==x['new_text'] and p['caption_sha256']==x['new_text_sha256'] and p['version_id']==receipt['version_id'] and p['attachments_sha256']==receipt['attachments_sha256'],'final_current_version_changed')
 out={'ok':True,'operation':'commercial-caption-repair-20261113','source_read_run_id':cohort['source_read_run_id'],'cohort_sha256':sha((private/'approved-cohort.json').read_bytes()),'installed_sha':r['installed_sha'],'observed_at_utc':r['observed_at_utc'],'confirmed_caption_edits':176,'corrected_hotels':88,'price_blocks_retained':22,'timestamp_display_conversions':2,'new_posts_created':0,'existing_buttons_and_photos_verified':176,'final_snapshot_sha256':sha(raw),'run_id':os.environ['GITHUB_RUN_ID'],'run_attempt':int(os.environ['GITHUB_RUN_ATTEMPT'])}
 save(safe/'summary.json',out);print(json.dumps(out))

if __name__=='__main__':
 command=sys.argv[1];private=Path(os.environ['RUNNER_TEMP'])/'caption-repair-private';safe=Path(os.environ['RUNNER_TEMP'])/'caption-repair-safe'
 if command=='result':result(private,safe,sys.argv[2],int(sys.argv[3]))
 elif command=='final':final(private,safe,int(sys.argv[2]))
 else:raise RuntimeError('unsupported_verification')
