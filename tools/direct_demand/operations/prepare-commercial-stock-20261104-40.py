import base64,hashlib,json,os,pathlib
base=pathlib.Path("tools/direct_demand/operations");temp=pathlib.Path(os.environ["RUNNER_TEMP"])
plan=json.loads((base/"commercial-stock-20261104-40-read1-plan.json").read_text());groups=plan["boundaries"]["exact_named_groups"]
assert len(groups)==10 and plan["boundaries"]["country_id"]==4 and plan["new_group_public_name_matches"]==0
hits=[]
for p in base.glob("read-commercial-stock-*-candidates.php"):
 if p.name=="read-commercial-stock-20261104-40-candidates.php":continue
 text=p.read_text().lower()
 for key,aliases in groups.items():
  if any(alias.lower() in text for alias in aliases):hits.append({"key":key,"previous_reader":p.name})
assert not hits,("CLOSED_OLD_NAMED_LOOKUP_INPUT",hits)
raw=(json.dumps(groups,ensure_ascii=False,separators=(",",":"))+"\n").encode()
source=(base/"read-commercial-stock-20261104-40-candidates.php").read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert source.startswith(prefix)
header=prefix+"const EXPECTED_INSTALLED_SHA='"+os.environ["EXPECTED_PRODUCTION_SHA"]+"';\n"+"const STOCK40_GROUPS_B64='"+base64.b64encode(raw).decode()+"';\n"+"const STOCK40_GROUPS_SHA256='"+hashlib.sha256(raw).hexdigest()+"';\n"
(temp/"channel-preflight.php").write_text(header+source[len(prefix):])
print("STOCK40_EXACT_TEN_NEW_NAMED_GROUPS_PREPARED old_scope_rereads=0 server_calls=0")
