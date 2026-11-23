"""Read exact 55 current bindings and public XML through the existing carrier; no writes."""
import hashlib,json,os,pathlib,re,subprocess,sys,urllib.request,xml.etree.ElementTree as ET,datetime
sha=lambda b:hashlib.sha256(b).hexdigest()
def need(v,m):
 if not v: raise ValueError(m)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*a,**k): raise ValueError("redirect_refused")
def get(url):
 req=urllib.request.Request(url,headers={"Cache-Control":"no-cache","Accept-Encoding":"identity","User-Agent":"AnyTour-FEED55-preflight/1"})
 with urllib.request.build_opener(NoRedirect()).open(req,timeout=45) as r:
  need(r.status==200 and r.geturl()==url,"public_response_changed");b=r.read(5000001)
 need(len(b)<=5000000,"public_feed_too_large");return b
def parse(b):
 need(b"<!DOCTYPE" not in b and b"<!ENTITY" not in b,"unsafe_xml")
 root=ET.fromstring(b);offers=root.findall("./shop/offers/offer");ids=[o.attrib.get("id") for o in offers]
 need(all(isinstance(x,str) for x in ids) and len(ids)==len(set(ids)),"duplicate_offer_ids")
 urls={}
 for o in offers:
  n=o.findall("./url");need(len(n)==1 and isinstance(n[0].text,str),"offer_url_invalid");urls[o.attrib["id"]]=n[0].text
 return ids,urls
def main():
 os.umask(0o077);root=pathlib.Path(sys.argv[1]);dest=pathlib.Path(sys.argv[2]);ad=json.loads(pathlib.Path(sys.argv[3]).read_bytes())
 raw=(root/"intent.json").read_bytes();need(sha(raw)==os.environ["REVIEWED_FEED55_INTENT_SHA256"],"intent_changed");intent=json.loads(raw)
 need(ad.get("state")=="FEED55_FRESH_READ_ONLY_ADMISSION" and ad.get("owner_and_concurrency_verified") is True and ad.get("writes_authorized") is False,"admission_missing")
 order=[1,2,4,5,9,12,13];need(intent["expected_installed_sha"]==ad["runtime_sha"] and [g["batch"] for g in intent["groups"]]==order and intent["hotels"]==55 and intent["publications"]==110,"scope_changed")
 dest.mkdir(mode=0o700,parents=True,exist_ok=False);(dest/"intent.json").write_bytes(raw);(dest/"admission.json").write_bytes(pathlib.Path(sys.argv[3]).read_bytes())
 temp=pathlib.Path(os.environ["RUNNER_TEMP"]);host=os.environ["DEPLOY_HOST"];user=os.environ["DEPLOY_USER"]
 need(re.fullmatch(r"[A-Za-z0-9_.:-]+",host) and not host.startswith("-") and re.fullmatch(r"[A-Za-z0-9_-]+",user),"destination_invalid")
 cmd=["ssh","-i",str(temp/"channel_key"),"-o","BatchMode=yes","-o","ConnectTimeout=10","-o","ServerAliveInterval=15","-o","ServerAliveCountMax=2","-o","IdentitiesOnly=yes","-o","StrictHostKeyChecking=yes","-o","GlobalKnownHostsFile=/dev/null","-o","UserKnownHostsFile="+str(temp/"channel_known_hosts"),user+"@"+host,"unset GIT_DIR GIT_WORK_TREE GIT_COMMON_DIR GIT_INDEX_FILE GIT_CONFIG_PARAMETERS GIT_CONFIG_COUNT GIT_OBJECT_DIRECTORY GIT_ALTERNATE_OBJECT_DIRECTORIES; cd /var/www/anytoour/data/www/app.anytoour.ru && GIT_OPTIONAL_LOCKS=0 php"]
 summaries=[];all_ids=[]
 for g in intent["groups"]:
  program=(root/("p%02d"%g["batch"])/"read.php").read_bytes();need(sha(program)==g["reader_sha256"],"reader_changed")
  p=subprocess.run(cmd,input=program,capture_output=True,timeout=120)
  if p.returncode!=0 or len(p.stdout)>=2000000:
   (dest/("p%02d.failure.json"%g["batch"])).write_text(json.dumps({"batch":g["batch"],"returncode":p.returncode,"stdout_bytes":len(p.stdout),"stdout_sha256":sha(p.stdout),"stderr_bytes":len(p.stderr),"stderr_sha256":sha(p.stderr)})+"\n")
   if len(p.stdout)<2000000: (dest/("p%02d.failure.stdout"%g["batch"])).write_bytes(p.stdout)
   raise ValueError("bounded_read_failed_p%02d"%g["batch"])
  result=json.loads(p.stdout);n=len(g["hotel_ids"])
  need(result.get("ok") is True and result.get("installed_sha")==intent["expected_installed_sha"],"runtime_or_read_changed")
  need(all(result.get(k)==0 for k in ["db_writes","provider_calls","message_sends","deferred_count"]),"read_boundary")
  need(all(result.get(k)==n for k in ["scope_hotels","feed_rows","private_binding_count"]),"read_scope")
  need([x["hotel_id"] for x in result["rows"]]==g["hotel_ids"] and [x["hotel_id"] for x in result["private_max_bindings"]]==g["hotel_ids"],"hotel_identity")
  receipts=result.get("current_registry_receipts",[]);need(len(receipts)==2*n and all(x.get("current_registry_receipt_verified") is True and x.get("provider_read_performed") is False for x in receipts),"registry_receipt_missing")
  original={(x["hotel_id"],x["platform"]):x for x in g["original_publications"]}
  for row,binding in zip(result["rows"],result["private_max_bindings"]):
   hid=row["hotel_id"];mp=original[(hid,"max")];tp=original[(hid,"telegram")]
   need(str(binding["publication_id"])==str(mp["publication_id"]) and binding["version_id"]==mp["version_id"] and binding["post_url"]==row["max_post_url"]==mp["post_url"] and row["telegram_post_url"]==tp["post_url"],"publication_drift")
  (dest/("p%02d.stdout.json"%g["batch"])).write_bytes(p.stdout);summaries.append({"batch":g["batch"],"hotels":n,"sha256":sha(p.stdout)});all_ids.extend(g["hotel_ids"])
 need(len(all_ids)==55 and len(set(all_ids))==55,"aggregate_scope")
 feed_summary={};canonical_urls=None
 for name in ["telegram","max","all","derived"]:
  b=get(intent["public_feeds"][name]);ids,urls=parse(b);expected=intent["expected_before"][name]
  need(len(ids)==expected,"feed_count_changed")
  if name=="telegram":
   need(all(re.fullmatch(r"tg_[1-9][0-9]*",x) for x in ids),"telegram_ids");canonical_urls=urls
  elif name=="max": need(all(re.fullmatch(r"max_[1-9][0-9]*",x) for x in ids),"max_ids")
  elif name=="all": need(sum(x.startswith("tg_") for x in ids)==1000 and sum(x.startswith("max_") for x in ids)==1000,"all_platform_counts")
  else:
   need(set(ids)==set(canonical_urls) and all(urls[x]=="https://t.me/Any_tour_bot?startapp={yclid}_"+x for x in ids),"derived_miniapp_urls_changed")
  wanted={(("tg_" if name in ("telegram","derived") else "max_")+str(h)) for h in all_ids} if name!="all" else {p+str(h) for h in all_ids for p in ("tg_","max_")}
  need(not(set(ids)&wanted),"feed55_already_present_reconcile")
  (dest/(name+".xml")).write_bytes(b);feed_summary[name]={"offers":len(ids),"unique":len(set(ids)),"sha256":sha(b),"target_present":0,"url":intent["public_feeds"][name]}
 (dest/"summary.json").write_text(json.dumps({"state":"FEED55_PUBLICATION_XML_PREFLIGHT_OK_ROUTE_PROOF_PENDING","ok":True,"observed_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),"runtime_sha":intent["expected_installed_sha"],"groups":summaries,"hotels":55,"publications":110,"feeds":feed_summary,"writes":0,"provider_calls":0,"message_sends":0,"route_writes":0,"route_proof_pending":True},sort_keys=True)+"\n")
 print("FEED55_READONLY_PREFLIGHT_OK hotels=55 publications=110 feeds=1000/1000/2000 derived=1000 writes=0 route_proof=pending")
if __name__=="__main__":
 try: main()
 except Exception as e:
  print("FEED55_PREFLIGHT_STOP:"+str(e));sys.exit(2)
