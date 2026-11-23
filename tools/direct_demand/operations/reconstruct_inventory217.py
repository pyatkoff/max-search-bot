"""Recover exact private inventory from original24 authenticated artifacts, locally.
Committed provenance contains hashes/IDs only, never original tokens or full receipts.
"""
import base64,hashlib,io,json,pathlib,subprocess,zipfile
from original_acceptance217 import accept as accept_original
PROVENANCE_SHA='69d6813bd7a3a06ad7f62f6a13baa33c8f13ab62f12ca5d57899fb329609fb88'
INVENTORY_SHA='c8cee3897d32ae513b537ba55ce4d8fe5d850017789b60fea53eca8a9f9ce565'
def need(v,m):
 if not v:raise ValueError(m)
def sha(b):return hashlib.sha256(b).hexdigest()
def api(path):return json.loads(subprocess.check_output(['gh','api','--method','GET','repos/pyatkoff/max-search-bot/'+path],timeout=45))
def source(path,commit):
 r=api('contents/'+path+'?ref='+commit);need(r.get('encoding')=='base64','source_encoding');return base64.b64decode(r['content'],validate=False)
def reconstruct(provenance,dest):
 raw=provenance.read_bytes();need(sha(raw)==PROVENANCE_SHA,'provenance_changed');inv=json.loads(raw);need(not dest.exists(),'private_inventory_destination_exists');dest.mkdir(mode=0o700)
 proofs=[];seen=set()
 for op in inv['operations']:
  aid=op['artifact_id'];a=api('actions/artifacts/'+str(aid));need(a['id']==aid and not a['expired'] and a['digest']=='sha256:'+op['archive_sha256'],'original_artifact_changed')
  run=api('actions/runs/'+str(a['workflow_run']['id']));need(run['id']==a['workflow_run']['id'] and run['run_attempt']==1 and run['status']=='completed' and run['head_sha']==a['workflow_run']['head_sha'],'original_run_not_accepted')
  download=lambda artifact_id:subprocess.check_output(['gh','api','--method','GET','repos/pyatkoff/max-search-bot/actions/artifacts/'+str(artifact_id)+'/zip'],timeout=120)
  acceptance=accept_original(op,run,api,source,download)
  zraw=download(aid)
  need(sha(zraw)==op['archive_sha256'],'original_zip_changed');z=zipfile.ZipFile(io.BytesIO(zraw));need(len(z.infolist())<100 and sum(x.file_size for x in z.infolist())<20000000 and z.testzip() is None,'original_zip_invalid')
  need(len(z.namelist())==len(set(z.namelist())),'duplicate_original_member')
  output=z.read('original-native-output.jsonl');need(sha(output)==op['original_output_sha256'] and z.read('original-exit.txt').strip()==b'0','original_output_changed');line=output.splitlines()[-1];pub=json.loads(line)
  need(sha(line)==op['summary_line_sha256'] and sha(line+b'\n')==op['summary_line_with_lf_sha256'] and pub['ok'] is True and pub['operation']==op['operation'] and pub['installed_sha']==op['installed_sha_at_publication'],'original_summary_changed')
  d=dest/f"p{op['batch']:02d}";d.mkdir(mode=0o700)
  for n,b in [('original-native-output.jsonl',output),('original-exit.txt',z.read('original-exit.txt')),('publication-summary.json',line+b'\n')]: (d/n).write_bytes(b)
  for n,key in [('manifest.json','manifest_sha256'),('approval.json','approval_sha256')]:
   b=z.read(n) if n in z.namelist() else source(f"tools/direct_demand/operations/commercial-batch-20261117-{op['batch']}-{n}",inv['canonical_source_commit'])
   need(sha(b)==op[key],'original_'+n+'_changed');(d/n).write_bytes(b)
  if op['runtime_rebind_evidence']:
   p=op['runtime_rebind_evidence'];b=source('.github/workflows/fastfill-second10-current6d-native-publish-20261117.yml',p['source_commit']);need(sha(b)==p['workflow_sha256'],'P2_workflow_changed');(d/'original-runtime-rebind-workflow.yml').write_bytes(b)
  m=json.loads((d/'manifest.json').read_bytes());approval=json.loads((d/'approval.json').read_bytes());need(m['operation']==approval['operation']==op['operation'] and approval['manifest_sha256']==op['manifest_sha256'] and approval['hotel_ids']==[r['hotel_id'] for r in m['rows']]==op['hotel_ids'],'original_manifest_scope')
  rows=[]
  for r in m['rows']:
   hid=r['hotel_id'];pair={p['platform']:p for p in pub['posts'] if p['hotel_id']==hid};need(set(pair)=={'max','telegram'},'original_pair_missing')
   for platform,p in pair.items():
    need((hid,platform) not in seen,'duplicate_original_pair');seen.add((hid,platform))
    need(p['state']=='sent' and p['body_photo_button_verified'] is True and p['reused'] is False and all(p[k]==r[k] for k in ['logical_id','text_sha256','photo_sha256','photo_url']),'original_pair_changed')
   rows.append({'hotel_id':hid,'logical_id':r['logical_id'],'text_sha256':r['text_sha256'],'photo_sha256':r['photo_sha256'],'photo_url':r['photo_url'],'text':r['text'],'posts':pair})
  op['rows']=rows;proofs.append({'batch':op['batch'],'artifact_id':aid,'archive_sha256':sha(zraw),'run_id':run['id'],'head_sha':run['head_sha'],'acceptance':acceptance})
 need(len(seen)==434,'original217_scope');complete=(json.dumps(inv,ensure_ascii=False,indent=2)+'\n').encode();need(sha(complete)==INVENTORY_SHA,'reconstructed_inventory_not_byte_identical')
 (dest/'inventory217.json').write_bytes(complete);(dest/'original-artifact-authentication.json').write_text(json.dumps(proofs,sort_keys=True));return dest
