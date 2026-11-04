import json,pathlib,urllib.parse
base=pathlib.Path("tools/direct_demand/operations")
plan=json.loads((base/"commercial-batch-20261104-44-dedup-plan.json").read_text())
def basename(u):return urllib.parse.unquote(urllib.parse.urlsplit(u).path.rsplit("/",1)[-1]).lower()
def fresh(u,urls,names):return u not in urls and basename(u) not in names
assert not fresh("https://owner.example/a.jpg",{"https://owner.example/a.jpg"},set())
assert not fresh("https://other.example/%61.jpg",set(),{"a.jpg"})
assert "Alibey_Resort_Sorgun_Plajlar_13.jpg".lower()!=basename("https://www.alibey.com/content/upload/content-slider/alibey_park_Manavgat_Havuzlar_1.jpg")
def contains_id(x,hid):
 if isinstance(x,dict):return any((k in ("hotel_id","catalog_id","native_catalog_id","local_tv_id") and str(v)==str(hid)) or contains_id(v,hid) for k,v in x.items())
 if isinstance(x,list):return any(contains_id(v,hid) for v in x)
 return False
def urls(x):
 if isinstance(x,str):return [x] if x.startswith("https://") else []
 if isinstance(x,dict):return [u for v in x.values() for u in urls(v)]
 if isinstance(x,list):return [u for v in x for u in urls(v)]
 return []
addressed={62505:[],968:[]};closed={62505:set(),968:set()}
for p in sorted(base.glob("*contact*.json")):
 if "20261104-44" in p.name:continue
 d=json.loads(p.read_text())
 for hid in addressed:
  if contains_id(d,hid):
   addressed[hid].append(p.name);closed[hid].update(urls(d))
out=[]
for item in plan["candidate_images"]:
 hid=item["hotel_id"];u=item["url"]
 names={basename(v) for v in closed[hid]}
 state="DEFERRED_MISSING_ADDRESSED_OLD_CONTACT_PROOF" if not addressed[hid] else ("EXCLUDED_OLD_URL_OR_DECODED_BASENAME" if not fresh(u,closed[hid],names) else "NEW_EXACT_RETAINED_URI_OLD_CONTACT_FILES_CHECKED_PHOTO_NOT_GET")
 assert not u.endswith("/Alibey_Resort_Sorgun_Plajlar_13.jpg")
 out.append(dict(item,dedup_state=state,raw_photo_gets=0))
state={"operation":plan["operation"],"state":"CLOSED_PURE_DEDUP_PREPARATION_NO_PHOTO_GET_NO_READY","proven_scope":plan["boundaries"]["proven_scope"],"addressed_contact_files":addressed,"old_contact_url_counts":{str(k):len(v) for k,v in closed.items()},"candidate_images":out,"new_input_cases_passed":3,"old_image_guard_fixtures_rerun":0,"owner_page_gets":0,"raw_photo_gets":0,"server_calls":0,"db_reads":0,"provider_calls":0,"canva_transactions":0,"message_sends":0,"ready_for_send":0,"new_posts":0}
pathlib.Path("retained-dedup-state.json").write_text(json.dumps(state,ensure_ascii=False)+"\n")
print(json.dumps(state,ensure_ascii=False))
