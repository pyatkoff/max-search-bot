"""Exact consumed P14/P15 failed-workflow provenance; NEVER re-label a failed run."""
import hashlib,io,json,zipfile
ACCEPT_RUN=44113899346
ACCEPT_HEAD='3369137fedb899c9402aef97c32a67ffcaaf9ef9'
ACCEPT_SOURCE='.github/workflows/fastfill-final18-native-publish-20261117.yml'
ACCEPT_SOURCE_SHA='4cceccc81b5a1cb273ffda07b4bc2e6770febc08f8b4ab37246061946e193bb7'
EXACT={14:(14340336646,'e1d35651afffe2f5a640ecd05b3ef25dbdf6b0e51f15d401194c1b773ccf77a7',44110155660,'4038f433c870fa7c5396609d98405c54fcfe57bb'),15:(14340142496,'f96c4467d398f45ea2a2ac09c937c3c02b800c087edf0191124448afc7dc2478',44110867949,'4e230a191e8ee96a3fba36745d309a33675eb66b')}
def accept(op,run,api,source,download):
 def need(v,m):
  if not v:raise ValueError(m)
 sha=lambda b:hashlib.sha256(b).hexdigest()
 need(run['run_attempt']==1 and run['status']=='completed','original_run_not_completed_once')
 if op['batch'] not in [2,14,15]:
  need(run['conclusion']=='success','unaccepted_original_failure');return {'original_conclusion':'success'}
 if op['batch']==2:
  need((op['artifact_id'],op['archive_sha256'],run['id'],run['head_sha'],run['conclusion'])==(14323322806,'931f4126cdf4eb4b0958cc00a9438742d14110de457640b3dd559700389a8589',44072766806,'7ab5e0fd282035620601ddfe5f2e7dd42ef7dc53','failure'),'P2_original_failure_changed')
  need(sha(source('.github/workflows/fastfill-second10-current6d-native-publish-20261117.yml',run['head_sha']))=='c86f331670c39058319898a8ac17c519949b6d3b3fc52c6645a946b4bcfbe38d','P2_original_source_changed')
  jobs=api('actions/runs/44072766806/attempts/1/jobs?per_page=100');need(jobs['total_count']==1 and len(jobs['jobs'])==1,'P2_original_jobs_changed');j=jobs['jobs'][0]
  need(j['id']==134002884069 and j['status']=='completed' and j['conclusion']=='failure','P2_original_job_changed')
  steps={x['number']:x for x in j['steps']}
  for number,name,conclusion in [(7,'Invoke the original bounded telegram and MAX publisher ONCE','success'),(8,'Independently confirm 20 native receipts and hotel bot CTA','failure'),(9,'Retain exact one-attempt receipt including UNKNOWN, NEVER re-send','success')]:
   need(steps[number]['name']==name and steps[number]['status']=='completed' and steps[number]['conclusion']==conclusion,'P2_native_vs_wrapper_status_changed')
  return {'original_conclusion':'failure','original_run_id':44072766806,'native_sender_step':7,'native_sender_conclusion':'success','late_wrapper_step':8,'late_wrapper_conclusion':'failure','independent_output_validation':'exact434_inventory_hash_plus_per_pair_native_receipts','sender_replayed':False}
 expected=EXACT.get(op['batch']);need(expected is not None and (op['artifact_id'],op['archive_sha256'],run['id'],run['head_sha'])==expected and run['conclusion']=='failure','unaccepted_original_failure')
 later=api('actions/runs/'+str(ACCEPT_RUN));need(later['run_attempt']==1 and later['head_sha']==ACCEPT_HEAD and later['status']=='completed' and later['conclusion']=='success','later_acceptance_run_changed')
 need(sha(source(ACCEPT_SOURCE,ACCEPT_HEAD))==ACCEPT_SOURCE_SHA,'later_acceptance_source_changed')
 jobs=api('actions/runs/'+str(ACCEPT_RUN)+'/attempts/1/jobs?per_page=100');need(jobs['total_count']==1 and len(jobs['jobs'])==1,'later_acceptance_jobs_changed');j=jobs['jobs'][0]
 need(j['id']==134146575478 and j['conclusion']=='success' and j['status']=='completed','later_acceptance_job_failed')
 step=[s for s in j['steps'] if s['name']=='Retain20 already published hotels and prove both remaining operations never invoked'];need(len(step)==1 and step[0]['number']==4 and step[0]['conclusion']=='success' and step[0]['status']=='completed','later_readonly_acceptance_failed')
 aid=14340252400 if op['batch']==14 else 14340142496;digest='e6e9f0bd972209ef60e9e54e96280187c3ba6336a183ea42596e759f4ea24632' if op['batch']==14 else EXACT[15][1]
 a=api('actions/artifacts/'+str(aid));need(not a['expired'] and a['workflow_run']['id']==44110867949 and a['digest']=='sha256:'+digest,'corrected_readonly_artifact_changed')
 raw=download(aid);need(sha(raw)==digest,'corrected_readonly_zip_changed');z=zipfile.ZipFile(io.BytesIO(raw));need(z.testzip() is None,'corrected_readonly_crc_failed');output=z.read('original-native-output.jsonl');p=json.loads(output.splitlines()[-1]);t=json.loads(z.read('terminal-verified.json'))
 need(sha(output)==op['original_output_sha256']==t['original_output_sha256'] and t['posts']==p['posts'] and t['operation']==op['operation'] and t['unique_new_hotels']==10 and p['ok'] is True and p['new_posts']==p['post_write_attempts']==20 and p['reused']==0 and z.read('original-exit.txt').strip()==b'0','corrected_readonly_original_mismatch')
 return {'original_conclusion':'failure','original_run_id':run['id'],'later_readonly_acceptance_run':ACCEPT_RUN,'later_readonly_job':134146575478,'later_source_sha256':ACCEPT_SOURCE_SHA,'corrected_artifact_id':aid,'corrected_archive_sha256':digest,'original_native_output_identical':True,'sender_replayed':False}
