"""Offline adapter for the existing publisher -> bounded read -> native route -> three feeds.
It never sends a post, calls a provider, connects to a server or creates a workflow.
"""
import argparse,base64,datetime,hashlib,json,os,re,subprocess,tempfile
from pathlib import Path

PREFIX="<?php\ndeclare(strict_types=1);\n"
READER_SHA256="4b066afc72cd60cbda911cb0dd91db0a06209731685f0cd7231f647a92a0500c"
NAMES=("hotel-posts-telegram.xml","hotel-posts-max.xml","hotel-posts-all.xml")
def require(ok,why):
 if not ok:raise ValueError(why)
def digest(raw):return hashlib.sha256(raw).hexdigest()
def encode(raw):return base64.b64encode(raw).decode()
def compact(value):return json.dumps(value,ensure_ascii=False,separators=(",",":")).encode()
def write(path,raw):
 path=Path(path);path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(raw);path.chmod(0o600)
def terminal(path):
 raw=Path(path).read_bytes();line=raw.splitlines()[-1];return raw,line,json.loads(line)
def verify_route_proof(raw,ad,expected_new,route,manifest,receipt_raw,manifest_raw):
 require(digest(raw)==ad.get("route_proof_sha256"),"root_route_proof_changed")
 p=json.loads(raw)
 require(p.get("schema_version")==1 and p.get("kind")=="root_authenticated_private_route_extension","root_route_proof_schema")
 require(p.get("operation")==route["operation"] and p.get("previous_count")==manifest["previous_count"]
  and p.get("logical_hotels")==manifest["logical_hotels"] and p.get("new_hotel_ids")==manifest["new_hotel_ids"],"root_route_proof_scope_changed")
 for key in ("publication_summary_sha256","bound_read_sha256"):
  require(p.get(key)==ad[key],"root_route_proof_source_changed")
 tuple_bytes=json.dumps(expected_new,ensure_ascii=False,separators=(",",":"),sort_keys=True).encode()
 require(p.get("new_binding_tuples_sha256")==digest(tuple_bytes),"root_route_proof_identity_changed")
 require(p.get("route_receipt_sha256")==digest(receipt_raw) and p.get("route_manifest_projection_sha256")==digest(manifest_raw),"root_route_proof_projection_changed")
 require(p.get("original_route_manifest_sha256")==ad.get("original_route_manifest_sha256")
  and p.get("original_route_map_sha256")==manifest["files"][0]["sha256"]
  and p.get("original_route_module_sha256")==manifest["files"][1]["sha256"],"root_route_proof_original_changed")
 require(p.get("verification")=={"full_map_checked":True,"previous_rows_preserved":True,"new_tuples_checked":True},"root_route_proof_checks_missing")
 require(p.get("source",{}).get("repository")=="pyatkoff/tour-max" and p["source"].get("run_attempt")==1,"root_route_proof_owner_changed")
def accepted(manifest_path,approval_path):
 raw=Path(manifest_path).read_bytes();m=json.loads(raw);a=json.loads(Path(approval_path).read_bytes())
 require(a.get("schema_version")==1 and a.get("manifest_sha256")==digest(raw),"approved_manifest_changed")
 require(m.get("state")=="PREPARED_NOT_SENT" and m.get("operation")==a.get("operation"),"publication_operation_changed")
 require(re.fullmatch(r"commercial-hotel-batch-\d{8}-[1-9]\d*-v[1-9]\d*",m["operation"]) is not None,"publication_operation_invalid")
 require(re.fullmatch(r"[a-f0-9]{40}",a.get("expected_installed_sha","")) is not None,"runtime_invalid")
 require(isinstance(m.get("rows"),list) and 1<=len(m["rows"])<=10 and [r["hotel_id"] for r in m["rows"]]==a.get("hotel_ids"),"approved_scope_changed")
 require(len(set(a["hotel_ids"]))==len(a["hotel_ids"]) and all(type(i) is int and i>0 for i in a["hotel_ids"]),"approved_ids_invalid")
 for r in m["rows"]:
  require(r.get("media_state")=="REAL_OFFICIAL_PHOTOGRAPHY_BRANDED_COVER_VISUALLY_VERIFIED","cover_not_accepted")
  require(re.fullmatch(r"https://raw\.githubusercontent\.com/pyatkoff/max-search-bot/[a-f0-9]{40}/\.github/traffic-publish/[a-zA-Z0-9/_.-]+\.png",r.get("photo_url","")) is not None,"cover_not_immutable")
  require(digest(r["text"].encode())==r["text_sha256"],"accepted_text_changed")
 return m,a

def reader(args):
 m,a=accepted(args.manifest,args.approval);ids=a["hotel_ids"];count=len(ids)
 source=Path(args.reader_template).read_bytes();require(digest(source)==READER_SHA256,"bounded_reader_template_changed")
 require(re.fullmatch(r"commercial-channel-hotel-feeds-\d{8}-read[1-9]\d*",args.reader_operation) is not None,"reader_operation_invalid")
 rows=[]
 for r in m["rows"]:
  first=r["text"].split("\n",1)[0];require(first.startswith("✈️ Туры в "),"accepted_heading_missing")
  rows.append({"hotel_id":r["hotel_id"],"name":first.removeprefix("✈️ Туры в "),
   "accepted_first_line":first,"image_url":r["photo_url"],"image_sha256":r["photo_sha256"]})
 raw=compact(rows);body=source.decode()
 anchors={
  "commercial-channel-hotel-feeds-20261108-read48":args.reader_operation,
  "count($in)===7&&array_column($in,'hotel_id')===[70030,1555,1690,28704,1699,82545,70048]":"count($in)==="+str(count)+"&&array_column($in,'hotel_id')==="+json.dumps(ids,separators=(",",":")),
  "count(array_unique(array_column($in,'accepted_first_line')))===7":"count(array_unique(array_column($in,'accepted_first_line')))==="+str(count),
  "count(array_unique($ids))===7":"count(array_unique($ids))==="+str(count),
  "count($built['rows'])===7":"count($built['rows'])==="+str(count),
  "$out['scope_hotels']=7":"$out['scope_hotels']="+str(count),
 }
 for old,new in anchors.items():
  require(body.count(old)==1,"bounded_reader_anchor_changed");body=body.replace(old,new)
 require(body.startswith(PREFIX),"bounded_reader_prefix_changed")
 header=PREFIX+"const EXPECTED_INSTALLED_SHA='"+a["expected_installed_sha"]+"';\n"
 header+="const FEED_EXPORT_INPUT_B64='"+encode(raw)+"';\nconst FEED_EXPORT_INPUT_SHA256='"+digest(raw)+"';\n"
 header+="const CANDIDATE_INPUT_B64='W10=';\nconst CANDIDATE_INPUT_SHA256='"+digest(b"[]")+"';\n"
 write(args.output,(header+body[len(PREFIX):]).encode())
 write(str(args.output)+".inputs.json",raw)
 print(json.dumps({"operation":args.reader_operation,"mode":"prepare_existing_bounded_reader","rows":count,
 "input_sha256":digest(raw),"output_sha256":digest(Path(args.output).read_bytes()),"provider_calls":0,"server_calls":0}))

PHP_RENDER=r"""
putenv('ANYTOUR_STATIC_FEED_LIBRARY_ONLY=1');require $argv[1];
$d=json_decode(file_get_contents($argv[2]),true,64,JSON_THROW_ON_ERROR);
$rows=static_extension_rows($d['publication_updates'],$d['publication_summaries']);
static_extension_route_receipt(base64_decode($d['route_receipt_base64'],true),$d['route_receipt_sha256'],$d['route_operation'],$d['previous_count'],count($rows));
$at=monitor_price_time($d['generated_at']);$files=[];
foreach($d['previous_files'] as $f){
 $old=base64_decode($f['content_base64'],true);$next=static_extension_render_feed($old,$rows,$f['name'],$at,$d['previous_count']);
 static_extension_assert_content($old,$f['sha256'],$next,$rows,$f['name'],$at,$d['previous_count']);
 $files[]=['name'=>$f['name'],'sha256'=>hash('sha256',$next),'bytes'=>strlen($next),
  'rows'=>($d['previous_count']+count($rows))*($f['name']==='hotel-posts-all.xml'?2:1),
  'expected_previous_sha256'=>$f['sha256'],'content_base64'=>base64_encode($next),'previous_content_base64'=>base64_encode($old)];
}
echo json_encode(['schema_version'=>4,'operation'=>$d['operation'],'files'=>$files,'generated_at'=>$d['generated_at'],
 'publication_updates'=>$rows,'route_receipt_base64'=>$d['route_receipt_base64']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
"""
def feed(args):
 m,a=accepted(args.manifest,args.approval);ad=json.loads(Path(args.admission).read_bytes());ids=a["hotel_ids"]
 require(ad.get("schema_version")==1 and ad.get("expected_installed_sha")==a["expected_installed_sha"],"tail_runtime_changed")
 require(re.fullmatch(r"commercial-channel-hotel-feeds-\d{8}-update[1-9]\d*",ad.get("operation","")) is not None,"feed_operation_invalid")
 require(type(ad.get("previous_count")) is int and ad["previous_count"]>0,"prior_count_invalid")
 pubfile,pubraw,pub=terminal(args.publication);readraw,_,read=terminal(args.bound_read)
 routeraw=Path(args.route_receipt).read_bytes();route=json.loads(routeraw)
 route_manifest_raw=Path(args.route_manifest).read_bytes();rm=json.loads(route_manifest_raw)
 for key,raw in [("manifest_sha256",Path(args.manifest).read_bytes()),("publication_summary_sha256",pubraw),
  ("bound_read_sha256",readraw),("route_receipt_sha256",routeraw),("route_manifest_sha256",route_manifest_raw)]:
  require(ad.get(key)==digest(raw),"tail_"+key+"_changed")
 require(pub.get("ok") is True and pub.get("operation")==m["operation"] and pub.get("installed_sha")==a["expected_installed_sha"],"publication_not_confirmed")
 require(pub.get("mode")=="publish_approved_commercial_batch" and len(pub.get("posts",[]))==2*len(ids),"publication_pair_count")
 require(read.get("ok") is True and read.get("installed_sha")==a["expected_installed_sha"],"bound_read_not_confirmed")
 require(re.fullmatch(r"commercial-channel-hotel-feeds-\d{8}-read[1-9]\d*",read.get("operation","")) is not None,"bound_read_operation_invalid")
 require(read.get("scope_hotels")==read.get("feed_rows")==read.get("private_binding_count")==len(ids) and read.get("deferred_count")==0,"bound_read_scope_changed")
 require(all(read.get(k)==0 for k in ("db_writes","provider_calls","message_sends")),"bound_read_write_boundary")
 require([r["hotel_id"] for r in read["rows"]]==[r["hotel_id"] for r in read["private_max_bindings"]]==ids,"bound_read_hotel_order")
 require(rm.get("state")=="CANDIDATE_NOT_INSTALLED" and rm.get("new_hotel_ids")==ids and rm.get("previous_count")==ad["previous_count"]
  and rm.get("logical_hotels")==ad["previous_count"]+len(ids) and rm.get("previous"+str(ad["previous_count"])+"_rows_identical") is True,"route_manifest_scope_changed")
 require(route.get("operation")==rm["operation"]+"-install1" and route.get("previous_module_sha256")==rm["previous_module_sha256"],"route_manifest_install_changed")
 files=rm["files"];require([x["name"] for x in files]==[rm["new_map_filename"],"anytour-hotel-feed-context.php"],"route_manifest_files")
 require(route.get("candidate_module_sha256")==files[1]["sha256"] and route.get("changed_files")==[x["name"] for x in files],"installed_route_files_changed")
 expected_new=[{"hotel_id":b["hotel_id"],"alias":b["public_route_alias"],"offer_id":b["public_offer_id"],
  "post_id":b["publication_id"],"post_url":b["post_url"]} for b in read["private_max_bindings"]]
 if args.route_map:
  route_map_raw=Path(args.route_map).read_bytes();require(digest(route_map_raw)==files[0]["sha256"],"installed_route_map_changed")
  encoded=re.search(rb"base64_decode\('([A-Za-z0-9+/=]+)',true\)",route_map_raw);require(encoded is not None,"route_map_payload_missing")
  route_map=json.loads(base64.b64decode(encoded.group(1),validate=True))
  require(len(route_map["bindings"])==rm["logical_hotels"],"installed_route_map_count")
  require(route_map["bindings"][-len(ids):]==expected_new,"installed_route_map_identity_changed")
 else:
  verify_route_proof(Path(args.route_proof).read_bytes(),ad,expected_new,route,rm,routeraw,route_manifest_raw)
 rows=[]
 for source,r,binding in zip(m["rows"],read["rows"],read["private_max_bindings"]):
  hid=r["hotel_id"];matches={p["platform"]:p for p in pub["posts"] if p["hotel_id"]==hid}
  require(set(matches)=={"telegram","max"},"publication_pair_missing")
  first=source["text"].split("\n",1)[0]
  require(r["name"]==first.removeprefix("✈️ Туры в ") and r["image_url"]==source["photo_url"] and r["image_missing"] is False,"bound_read_public_fields_changed")
  require(binding.get("platform")=="max" and binding.get("project_key")=="anytour" and binding.get("post_bot_key")=="id9704048781_1_bot"
   and binding.get("channel_id")=="-80492244209683" and binding.get("public_offer_id")=="max_"+str(hid),"bound_read_native_identity")
  require(binding["post_url"]==r["max_post_url"]==matches["max"]["post_url"] and str(binding["publication_id"])==str(matches["max"]["publication_id"])
   and binding["version_id"]==matches["max"]["version_id"] and r["telegram_post_url"]==matches["telegram"]["post_url"],"bound_read_publication_changed")
  rows.append({"publication_operation":m["operation"],"publication_summary_sha256":digest(pubraw),"hotel_id":hid,
   "logical_id":source["logical_id"],"text_sha256":source["text_sha256"],"photo_sha256":source["photo_sha256"],
   "photo_url":source["photo_url"],"name":r["name"].replace("-"," "),"destination":r["destination"].replace("-"," "),
   "telegram_post_url":r["telegram_post_url"],"max_post_url":r["max_post_url"],"public_route_alias":binding["public_route_alias"]})
 previous=[]
 require(set(ad.get("previous_feed_sha256",{}))==set(NAMES),"previous_feed_hash_scope")
 for name in NAMES:
  old=(Path(args.previous_feed_dir)/name).read_bytes();require(digest(old)==ad["previous_feed_sha256"][name],"previous_feed_changed")
  previous.append({"name":name,"sha256":digest(old),"content_base64":encode(old)})
 writer=Path(args.writer).read_bytes();contract=Path(args.monitor_contract).read_bytes()
 require(digest(writer)==ad.get("writer_sha256") and digest(contract)==ad.get("monitor_contract_sha256"),"tail_implementation_changed")
 d={"operation":ad["operation"],"generated_at":datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
  "previous_count":ad["previous_count"],"publication_updates":rows,"publication_summaries":{m["operation"]:pubraw.decode()},
  "route_receipt_base64":encode(routeraw),"route_receipt_sha256":digest(routeraw),"route_operation":route["operation"],"previous_files":previous}
 with tempfile.TemporaryDirectory(prefix="publication-tail-") as t:
  p=Path(t)/"render-input.json";write(p,compact(d))
  result=subprocess.run(["php","-r",PHP_RENDER,str(Path(args.writer).resolve()),str(p)],capture_output=True,timeout=30)
  require(result.returncode==0,"native_append_validation_failed:"+result.stderr.decode()[:100])
  raw=result.stdout;data=json.loads(raw)
 require([x["name"] for x in data["files"]]==list(NAMES),"render_scope_changed")
 header=PREFIX+"const EXPECTED_INSTALLED_SHA='"+a["expected_installed_sha"]+"';\n"
 for key,value in {"PUBLIC_STATIC_FEED_OPERATION":ad["operation"],"PUBLIC_STATIC_FEED_INPUT_B64":encode(raw),"PUBLIC_STATIC_FEED_INPUT_SHA256":digest(raw),
  "PUBLIC_STATIC_FEED_ROUTE_RECEIPT_SHA256":digest(routeraw),"PUBLIC_STATIC_FEED_ROUTE_OPERATION":route["operation"]}.items():
  header+="const "+key+"='"+value+"';\n"
 header+="const PUBLIC_STATIC_FEED_PREVIOUS_COUNT="+str(ad["previous_count"])+";\n"
 body=writer.decode();contract=contract.decode();require(body.startswith(PREFIX) and contract.startswith(PREFIX),"php_prefix_changed")
 anchor="require_once __DIR__.'/monitor-price-contract.php';";require(body.count(anchor)==1,"monitor_contract_anchor_changed")
 body=body.replace(anchor,contract[len(PREFIX):],1);script=(header+body[len(PREFIX):]).encode()
 write(args.output,script);write(str(args.output)+".input.json",raw)
 print(json.dumps({"operation":ad["operation"],"mode":"prepare_existing_three_feed_write","schema_version":4,
  "rows":[x["rows"] for x in data["files"]],"old_offer_bytes":"identical","new_hotel_ids":ids,
  "input_sha256":digest(raw),"output_sha256":digest(script),"provider_calls":0,"server_calls":0,"writes":0}))

def main():
 p=argparse.ArgumentParser();sub=p.add_subparsers(dest="command",required=True)
 r=sub.add_parser("reader");f=sub.add_parser("feed")
 for q in (r,f):
  q.add_argument("--manifest",required=True);q.add_argument("--approval",required=True);q.add_argument("--output",required=True)
 r.add_argument("--reader-template",required=True);r.add_argument("--reader-operation",required=True);r.set_defaults(run=reader)
 for name in ("admission","publication","bound-read","route-receipt","route-manifest","previous-feed-dir","writer","monitor-contract"):
  f.add_argument("--"+name,required=True)
 route=f.add_mutually_exclusive_group(required=True);route.add_argument("--route-map");route.add_argument("--route-proof")
 f.set_defaults(run=feed);args=p.parse_args();args.run(args)
if __name__=="__main__":main()

