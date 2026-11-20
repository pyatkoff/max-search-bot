"""Fresh exact FEED55 read-only admission. No native writes."""
import base64,datetime,hashlib,json,os,pathlib,re,subprocess,sys
RUNTIME="b4b65daaa472c15426a244b87ede3e6c0394626b"
TRANSFER_ID=7026915700
TRANSFER_SHA256="af864a46251e212c0395382c2bed28471ead44c533e04c8a4eb4459f3de35adf"
ACCEPT_ID=7027410356
ACCEPT_SHA256="16d3b23bbbf26e5cba2e5d057e8984ea8076dce356bb82378db7f916718a3aba"
CARRIER="audit/price660-27316-resume-readiness-20261117"
STALE={
"43097373885":("837cc8068d5680190e878eeab0e27c8078707dc1",".github/workflows/channel-post-context.yml",384242777,"feature/manager2-file-preflight-20261111","277978baaf410c72372b8faea7f74a9f085ba8fdb5d7b153b6643e4881eb7942"),
"43097373883":("837cc8068d5680190e878eeab0e27c8078707dc1",".github/workflows/workspace-sound.yml",363300483,"feature/manager2-file-preflight-20261111","f2ac71144414b911a4091c20fcb482e38911d582a0b082ed0d792f127e0437a4"),
"43097373914":("837cc8068d5680190e878eeab0e27c8078707dc1",".github/workflows/retired-domain-guard.yml",348353174,"feature/manager2-file-preflight-20261111","66ac194e95cf25de4b915bf378c25dc2cf33b05c4378b4c2ebdc229ca4a92a0b"),
"43097373916":("837cc8068d5680190e878eeab0e27c8078707dc1",".github/workflows/regression-tests.yml",340550044,"feature/manager2-file-preflight-20261111","317c2cbd9f597414a3207d7181beb8661cb881c98e1f85aaa32a793166e43b2e"),
"43097374035":("837cc8068d5680190e878eeab0e27c8078707dc1",".github/workflows/workspace-v2-visual-qa.yml",343986842,"feature/manager2-file-preflight-20261111","2a3d3613ccc558314cdd1b532533657557dea417da6609eaae931c62fb0004c9"),
"43097373867":("837cc8068d5680190e878eeab0e27c8078707dc1",".github/workflows/publish-appleservice39-pilot.yml",411288987,"feature/manager2-file-preflight-20261111","119b369e381f6f5ff6d2c17966334b32b83ca059f32dcc699317bfff86e3f5d5"),
"43097356777":("a40f92d641bf36a7b1db955df03835c1a3cd77f2",".github/workflows/retired-domain-guard.yml",348353174,"fix/modeled-frozen-outcomes-20261111","66ac194e95cf25de4b915bf378c25dc2cf33b05c4378b4c2ebdc229ca4a92a0b"),
"43097356829":("a40f92d641bf36a7b1db955df03835c1a3cd77f2",".github/workflows/regression-tests.yml",340550044,"fix/modeled-frozen-outcomes-20261111","317c2cbd9f597414a3207d7181beb8661cb881c98e1f85aaa32a793166e43b2e"),
"43097357094":("a40f92d641bf36a7b1db955df03835c1a3cd77f2",".github/workflows/max-channel-attribution.yml",385615339,"fix/modeled-frozen-outcomes-20261111","b3412052410d7a9649877cb87f9ee4fafb1f079510e62a6ffff888dd61d6b4e5")
}
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v: raise ValueError(m)
def api(repo,path):
 return json.loads(subprocess.check_output(["gh","api","--method","GET","repos/pyatkoff/"+repo+"/"+path],timeout=45))
def reviewed_stale(row):
 expected=STALE.get(str(row.get("id")))
 if expected is None:return False
 head,path,wid,branch,wsha=expected
 if row.get("status")!="queued" or row.get("run_attempt")!=1 or row.get("conclusion") is not None or row.get("head_sha")!=head or row.get("path")!=path or row.get("event")!="pull_request" or row.get("workflow_id")!=wid or row.get("head_branch")!=branch:return False
 jobs=api("max-search-bot","actions/runs/"+str(row["id"])+"/jobs?per_page=100")
 if jobs.get("total_count")!=0 or jobs.get("jobs")!=[]:return False
 wf=api("max-search-bot","contents/"+path+"?ref="+head)
 if wf.get("encoding")!="base64":return False
 raw=base64.b64decode("".join(wf.get("content","").split()),validate=True)
 return sha(raw)==wsha
def active(repo,status,now):
 d=api(repo,"actions/runs?status="+status+"&per_page=100")
 rows=d.get("workflow_runs");total=d.get("total_count")
 need(isinstance(rows,list) and isinstance(total,int) and len(rows)==total and total<=100,"ambiguous_active_runs:"+repo+":"+status+":"+str(total)+":"+str(len(rows) if isinstance(rows,list) else -1))
 cutoff=now-datetime.timedelta(hours=12);recent=[]
 for row in rows:
  try: created=datetime.datetime.fromisoformat(row["created_at"].replace("Z","+00:00"))
  except Exception: raise ValueError("active_run_timestamp_invalid")
  if created>=cutoff: recent.append(row)
 if repo=="poisk-turov-test":
  need(all(isinstance(x.get("workflow_id"),int) for x in recent),"collector_identity_missing")
  recent=[x for x in recent if x["workflow_id"]==346454799]
 return recent
def main():
 out=pathlib.Path(sys.argv[1])
 need(os.environ.get("GITHUB_REPOSITORY")=="pyatkoff/max-search-bot" and os.environ.get("GITHUB_ACTOR")=="pyatkoff" and os.environ.get("GITHUB_EVENT_NAME")=="push" and os.environ.get("GITHUB_RUN_ATTEMPT")=="1","event_scope")
 event=json.loads(pathlib.Path(os.environ["GITHUB_EVENT_PATH"]).read_bytes())
 parent=os.environ["EXPECTED_PARENT_SHA"]; need(re.fullmatch("[a-f0-9]{40}",parent) and event.get("before")==parent,"exact_parent_changed")
 head=os.environ["GITHUB_SHA"]; need(subprocess.check_output(["git","rev-parse","HEAD^"],text=True).strip()==parent,"checkout_parent")
 need(api("max-search-bot","git/ref/heads/"+CARRIER)["object"]["sha"]==head,"carrier_moved")
 need(api("max-search-bot","git/ref/heads/main")["object"]["sha"]==RUNTIME,"working_main_changed")
 for cid,digest in [(TRANSFER_ID,TRANSFER_SHA256),(ACCEPT_ID,ACCEPT_SHA256)]:
  c=api("max-search-bot","issues/comments/"+str(cid))
  need(c["user"]["id"]==226193297 and sha(c["body"].encode())==digest,"owner_comment_changed")
 current=int(os.environ["GITHUB_RUN_ID"]); checks=[];now=datetime.datetime.now(datetime.timezone.utc)
 for status in ["in_progress","queued","waiting","pending","requested"]:
  for short in ["max-search-bot","poisk-turov-test"]:
   rows=active(short,status,now); other=[r for r in rows if not(short=="max-search-bot" and r.get("id")==current and r.get("head_sha")==head) and not(short=="max-search-bot" and reviewed_stale(r))]
   need(not other,"competing_run:"+short+":"+status);checks.append({"repository":short,"status":status,"other_count":0})
 out.write_text(json.dumps({"state":"FEED55_FRESH_READ_ONLY_ADMISSION","runtime_sha":RUNTIME,"transfer_comment_id":TRANSFER_ID,"acceptance_comment_id":ACCEPT_ID,"owner_and_concurrency_verified":True,"writes_authorized":False,"observed_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),"checks":checks},sort_keys=True)+"\n")
if __name__=="__main__":
 try: main()
 except Exception as e:
  print("FEED55_ADMISSION_STOP:"+str(e));sys.exit(2)
