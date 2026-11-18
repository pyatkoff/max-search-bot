"""Exact PRICE1000 install + bounded pass, not all1000 fresh or old cycle success."""
import hashlib,json,io,zipfile
RUN=44269492828
HEAD='4b1247a97487b553917ec54502b402da6b81e5bf'
ARTIFACT=14411763726
ARCHIVE_SHA='171d0042489a2b11042a81fae819f6fa1a5bd22732cbecd2ad484f29f75132dc'
SUMMARY_SHA='8b33e64659f19c5bef1f645c79ce2aaba5fb5d019b168b224b65402e0f09fddf'
HOOK='d605b8292a8d380d328d585f90d388a254d1dc780188e4b0edbfc92b6c407c30'
ARCHIVE_PATH='price1000-original.zip'
H=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v:raise ValueError(m)
def expected():return {'kind':'EXACT_PRICE1000_INSTALL_BOUNDED_PASS','repository':'poisk-turov-test','run_id':RUN,'head_sha':HEAD,'artifact_id':ARTIFACT,'archive_sha256':ARCHIVE_SHA,'archive_path':ARCHIVE_PATH,'summary_sha256':SUMMARY_SHA}
def validate_chain(m):
 s=m['summary.json'];i=m['paired-install.stdout'];p=m['price-result.json'];j=m['journal-after-pass.stdout'];b=m['installer-binding.json'];a=m['admission.json'];ins=m['inspect1000-result.json']
 need(s['ok'] is True and s['roster_pairs']==1000 and s['feed_counts']==[1000,1000,2000] and s['all1000_fresh_claimed'] is False,'summary_scope')
 need(i['ok'] is True and i['mode']=='install' and i['operation']=='price1000-hook-only-20261118-1' and i['code_replacements']==1 and i['code_hashes']['monitor-price-hook.php']==HOOK,'installed_hook')
 need(i['journal_unchanged'] is True and i['backup_retained'] is True and i['journal']['pending_null'] is True,'install_preservation')
 need(all(type(i[k]) is int and i[k]==0 for k in ['post_writes','feed_writes','global_journal_writes','collector_runs','module_executions','consumer_code_writes']),'installer_side_effect')
 need(i['native_receipt_sha']==b['admission_sha256'] and a['native_result_sha256']==b['native_result_sha256'],'install_admission_chain')
 need(ins['ok'] is True and ins['roster_pairs']==ins['scanned_pairs']==1000 and ins['scan_complete'] is True,'cohort1000')
 need(p['ok'] is True and p['roster_pairs']==1000 and p['updated_pairs']==s['updated_pairs']==30 and p['result_status']=='partial' and p['scan_complete'] is False and p['bounded_pass_stopped'] is True,'bounded_pass')
 need(all(p[k]==0 for k in ['new_posts','supplier_calls','site_writes']),'pass_unexpected_effect')
 need(sorted(x['tourvisor_hotel_id'] for x in p['updates'])==s['updated_ids'] and sorted(x['operation'] for x in p['updates'])==sorted(s['operations']),'pass_summary_binding')
 need(sum(len(x['posts']) for x in p['updates'])==s['confirmed_messages']==60,'confirmed_messages')
 need(j==s['journal'] and j['ok'] is True and j['prefix_verified'] is True and j['pending'] is None,'journal_not_idle')
 need(j['cycle_state']=='preflight_blocked' and j['cycle_collector_run_id']=='44236272559' and j['latest_cycle']['preflight_block']['terminal']['state']=='abandoned_before_write','history_rewritten')
 need(all(type(j[k]) is int and j[k]==0 for k in ['writes','preflight_write_attempts','supplier_calls','post_writes','feed_writes','site_writes']),'journal_verifier_effect')
 for name,count in [('hotel-posts-telegram.xml',1000),('hotel-posts-max.xml',1000),('hotel-posts-all.xml',2000)]:
  f=m['feeds-after-pass.stdout']['files'][name];need(len(f['rows'])==count and f['public_http']==200 and f['sha256']==f['public_sha256'],'public_local_feeds')
 return {'kind':expected()['kind'],'roster_pairs':1000,'bounded_updated_pairs':30,'all1000_fresh':False,'historical_cycle_success':False,'pending':None,'hook_sha256':HOOK}
def validate_archive(raw):
 need(H(raw)==ARCHIVE_SHA,'original_archive_changed');z=zipfile.ZipFile(io.BytesIO(raw));need(len(z.namelist())==len(set(z.namelist())),'duplicate_zip_members')
 need(H(z.read('summary.json'))==SUMMARY_SHA,'summary_changed')
 names=['summary.json','paired-install.stdout','price-result.json','journal-after-pass.stdout','installer-binding.json','admission.json','inspect1000-result.json','feeds-after-pass.stdout'];m={n:json.loads(z.read(n)) for n in names}
 for n in ['paired-install','price-pass','journal-after-pass','code-after-pass','feeds-after-pass','inspect1000']:need(json.loads(z.read(n+'.status.json'))=={'returncode':0},'native_step_failed:'+n)
 hashes=dict((line.split()[1].split('/')[-1],line.split()[0]) for line in z.read('code-after-pass.stdout').decode().splitlines());need(hashes==m['paired-install.stdout']['code_hashes'],'code_readback')
 need(H(z.read('admission.json'))==m['installer-binding.json']['admission_sha256'],'admission_raw_binding')
 return validate_chain(m)
def validate_handoff(config,root,api):
 need(config['price_terminal_handoff']==expected(),'handoff_scope')
 need(config['source_sha256'].get(ARCHIVE_PATH)==ARCHIVE_SHA,'archive_source_pin');path=root/ARCHIVE_PATH;need(not any(x.is_symlink() for x in [path,*path.parents]),'archive_symlink');result=validate_archive(path.read_bytes())
 r=api('poisk-turov-test','actions/runs/'+str(RUN));need(r['id']==RUN and r['repository']['full_name']=='pyatkoff/poisk-turov-test' and r['head_sha']==HEAD and r['run_attempt']==1 and r['status']=='completed' and r['conclusion']=='success','price_run_not_success')
 a=api('poisk-turov-test','actions/artifacts/'+str(ARTIFACT));need(a['id']==ARTIFACT and a['expired'] is False and a['digest']=='sha256:'+ARCHIVE_SHA and a['workflow_run']['id']==RUN and a['workflow_run']['head_sha']==HEAD,'price_artifact_changed')
 return result
