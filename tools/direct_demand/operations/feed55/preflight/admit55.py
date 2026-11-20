"""Fresh exact FEED55 read-only admission. No native writes."""
import datetime,hashlib,json,os,pathlib,re,subprocess,sys
RUNTIME="9385571c86c45189c31d9c4aa904c23970e3b11a"
TRANSFER_ID=7026915700
TRANSFER_SHA256="af864a46251e212c0395382c2bed28471ead44c533e04c8a4eb4459f3de35adf"
ACCEPT_ID=7027410356
ACCEPT_SHA256="16d3b23bbbf26e5cba2e5d057e8984ea8076dce356bb82378db7f916718a3aba"
CARRIER="audit/price660-27316-resume-readiness-20261117"
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v: raise ValueError(m)
def api(repo,path):
 return json.loads(subprocess.check_output(["gh","api","--method","GET","repos/pyatkoff/"+repo+"/"+path],timeout=45))
def active(repo,status):
 d=api(repo,"actions/runs?status="+status+"&per_page=100")
 rows=d.get("workflow_runs"); need(isinstance(rows,list) and isinstance(d.get("total_count"),int) and len(rows)==d["total_count"]<=100,"ambiguous_active_runs")
 if repo=="poisk-turov-test":
  need(all(isinstance(x.get("workflow_id"),int) for x in rows),"collector_identity_missing")
  rows=[x for x in rows if x["workflow_id"]==346454799]
 return rows
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
 current=int(os.environ["GITHUB_RUN_ID"]); checks=[]
 for status in ["in_progress","queued","waiting","pending","requested"]:
  for short in ["max-search-bot","poisk-turov-test"]:
   rows=active(short,status); other=[r for r in rows if not(short=="max-search-bot" and r.get("id")==current and r.get("head_sha")==head)]
   need(not other,"competing_run:"+short+":"+status);checks.append({"repository":short,"status":status,"other_count":0})
 out.write_text(json.dumps({"state":"FEED55_FRESH_READ_ONLY_ADMISSION","runtime_sha":RUNTIME,"transfer_comment_id":TRANSFER_ID,"acceptance_comment_id":ACCEPT_ID,"owner_and_concurrency_verified":True,"writes_authorized":False,"observed_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),"checks":checks},sort_keys=True)+"\n")
if __name__=="__main__":
 try: main()
 except Exception as e:
  print("FEED55_ADMISSION_STOP:"+str(e));sys.exit(2)
