"""Seal a separately approved #1566 owner operation using the existing release verifier and installed CLI."""
import hashlib
import json
import re
from textwrap import dedent, indent

BASE_WORKFLOW = ".github/workflows/edit-1566-next-historical-telegram.yml"
WORKFLOW = ".github/workflows/edit-1566-ranked-46-historical-telegram.yml"
RELEASE = "tools/deployment_release.js"
BRANCH = "ops/anytour-hotel-ranked-46-apply-20261123"
BASE_PLAN = "30f93518da51279979f7669538f7c09f6096733e9423f755dc21981af821b98d"
READ_SHA = "db1157a5091ff0f708d7825acf6ca4e1dc9a5339"


def require(ok, message):
    if not ok:
        raise ValueError(message)


def blob(raw):
    return hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest()


def sha256(raw):
    return hashlib.sha256(raw).hexdigest()


def once(text, old, new):
    require(text.count(old) == 1, "owner source changed: " + old[:70])
    return text.replace(old, new)


def plan_path(path):
    return isinstance(path, str) and re.fullmatch(r"config/[a-zA-Z0-9_/-]+\.json", path) and "//" not in path


def validate(plans, index_path, index_bytes):
    require(plan_path(index_path) and 0 < len(index_bytes) <= 64000, "invalid owner index")
    data = json.loads(index_bytes)
    reference = data["native_preflight"]
    require(data["schema"] == 1 and 0 < len(plans) <= 46, "empty or unbounded list of plans")
    require(re.fullmatch(r"[1-9][0-9]{0,15}", reference["run_id"]) and reference["run_attempt"] == 1
            and type(reference["artifact_id"]) is int and 0 < reference["artifact_id"] < 2**53
            and re.fullmatch(r"[0-9a-f]{64}", reference["artifact_zip_sha256"])
            and type(data["native_zip_bytes"]) is int and 0 < data["native_zip_bytes"] <= 8000000, "original native artifact identity")
    items, entries, ids, targets = [], [], [], []
    for seq, (path, raw) in enumerate(plans, 1):
        require(plan_path(path) and path != index_path and 0 < len(raw) <= 64000, "invalid sealed plan")
        entry = dict(path=path, bytes=len(raw), git_blob=blob(raw), sha256=sha256(raw))
        plan = json.loads(raw)
        require(plan["operation"] == f"anytour_hotel_transfer_46_20261123_{seq:02d}"
                and plan["native_preflight"] == reference and plan["rows"], "native proof or chunk order changed")
        hotels = [r["hotel_id"] for r in plan["rows"]]
        messages = [r["target_message_id"] for r in plan["rows"]]
        require(all(type(h) is int and h > 0 for h in hotels) and all(isinstance(m, str)
                and re.fullmatch(r"[1-9][0-9]{0,9}", m) for m in messages), "invalid hotel or target")
        ids.extend(hotels)
        targets.extend(messages)
        entries.append(entry)
        items.append(dict(**entry, hotels=hotels, messages=messages, operation=plan["operation"]))
    require(len(ids) == len(set(ids)) == len(set(targets)) and len(ids) <= 46, "repeated target or hotel")
    require(data["plans"] == entries and len({i["path"] for i in entries}) == len(entries), "owner index does not bind exact plans")
    return reference, data["native_zip_bytes"], items


def bind_gate(base, plans, index_path, index_bytes):
    proof, zip_size, items = validate(plans, index_path, index_bytes)
    old = base[BASE_WORKFLOW]
    require(blob(old.encode()) == "e9442c60f49b4deedb206ec72862075a9d0ba840", "existing owner workflow changed")
    require(WORKFLOW not in base and "HOTEL_RANKED_46" not in base[RELEASE], "ranked owner path already present")
    config = f"""const HOTEL_RANKED_46 = Object.freeze({{...HOTEL_FOUR_TRANSFER,
  branch:'{BRANCH}',workflow:PREFIX+'{WORKFLOW.rsplit('/',1)[1]}',operation:'hotel_ranked_46_transfer',
  plan_path:'{index_path}',plan_size:{len(index_bytes)},plan_blob:'{blob(index_bytes)}',
  plan_digest:'{sha256(index_bytes)}',native_run:'{proof['run_id']}',native_artifact:{proof['artifact_id']},
  native_zip_bytes:{zip_size},native_zip_digest:'{proof['artifact_zip_sha256']}'}});"""
    release = base[RELEASE]
    anchor = "  plan_digest:'" + BASE_PLAN + "'});"
    release = once(release, anchor, anchor + "\n" + config)
    proof_function = """async function resolveRankedHotelTransfer(inputs) {
  const p = HOTEL_RANKED_46;
  const run = await inputs.api('GET', API_ROOT+'/actions/runs/'+p.native_run);
  runIdentity(run, {id:p.native_run, attempt:1, head:'__READ_SHA__',
    workflow:PREFIX+'read-1566-telegram-46-20261123.yml', event:'push',
    branch:'ops/1566-telegram-46-native-read-20261123'});
  requireValue(run.repository?.full_name===REPOSITORY && run.repository.id===p.repository_id
    && run.head_repository?.id===p.repository_id && run.actor?.login===p.owner && run.actor?.id===p.owner_id
    && run.triggering_actor?.login===p.owner && run.triggering_actor?.id===p.owner_id,'ranked_native_actor_changed');
  const artifacts = boundedList(await inputs.api('GET',API_ROOT+'/actions/runs/'+p.native_run+'/artifacts?per_page=100'),
    'artifacts','ranked_native_artifact_inventory_incomplete');
  const artifact = artifacts[0];
  requireValue(artifacts.length===1 && artifact.id===p.native_artifact
    && artifact.name==='issue-1566-telegram-46-preflight' && artifact.expired===false
    && artifact.digest==='sha256:'+p.native_zip_digest && artifact.size_in_bytes===p.native_zip_bytes
    && String(artifact.workflow_run?.id)===p.native_run && artifact.workflow_run.repository_id===p.repository_id
    && artifact.workflow_run.head_sha==='__READ_SHA__','ranked_native_original_not_confirmed');
  return resolveHotelTransfer(inputs,p);
}
""".replace("__READ_SHA__", READ_SHA)
    anchor = "async function resolveConsumer({env,event,api,"
    release = once(release, anchor, proof_function + anchor)
    anchor = "  if(env.GITHUB_REF==='refs/heads/'+HOTEL_NEXT_TRANSFER.branch)return resolveHotelTransfer({env,event,api},HOTEL_NEXT_TRANSFER);"
    release = once(release, anchor, anchor + "\n  if(env.GITHUB_REF==='refs/heads/'+HOTEL_RANKED_46.branch)return resolveRankedHotelTransfer({env,event,api});")

    workflow = once(old, "Apply next approved hotel transfers", "Apply approved ranked Telegram hotel transfers")
    workflow = once(workflow, "ops/anytour-hotel-next-apply-20261123", BRANCH)
    workflow = once(workflow, "issue-1566-hotel-next-transfer", "issue-1566-hotel-ranked-46-transfer")
    workflow = once(workflow, "test \"$WRITE_OPERATION\" = hotel_next_transfer", "test \"$WRITE_OPERATION\" = hotel_ranked_46_transfer")
    # The existing CLI performs several bounded network reads per row and never retries a write.
    runtime_limit = max(15, 10 + 3 * sum(len(i["hotels"]) for i in items))
    workflow = once(workflow, "timeout-minutes: 10", f"timeout-minutes: {runtime_limit}")
    digests = " ".join(item["sha256"] for item in items)
    precheck = """python3 - <<'PY'
import hashlib, json, pathlib
p=pathlib.Path('__INDEX_PATH__')
raw=p.read_bytes()
assert hashlib.sha256(raw).hexdigest()=='__INDEX_SHA__','owner_index_changed'
index=json.loads(raw)
for item in index['plans']:
    plan=pathlib.Path(item['path']).read_bytes()
    assert len(plan)==item['bytes'] and hashlib.sha256(plan).hexdigest()==item['sha256'],'chunk_bytes_changed'
    blob=hashlib.sha1(b'blob '+str(len(plan)).encode()+b'\\0'+plan).hexdigest()
    assert blob==item['git_blob'],'chunk_git_blob_changed'
PY
for digest in __DIGESTS__; do
  php tools/anytour_existing_post_batch.php --check-ranked-transfer-plan "$digest" > "pub/check-$digest.json"
done
python3 - <<'PY'
import json, pathlib
chunks=__ITEMS__
out={}
for item in chunks:
    digest=item['sha256']
    checked=json.loads(pathlib.Path('pub/check-'+digest+'.json').read_bytes())
    assert checked['ok'] is True and checked['mode']=='offline_plan_check' and checked['operation']==item['operation']
    assert checked['production_writes']==checked['provider_writes']==0 and checked['plan_sha256']==digest
    assert [r['hotel_id'] for r in checked['rows']]==item['hotels']
    assert [r['target_url'] for r in checked['rows']]==['https://t.me/anytour/'+m for m in item['messages']]
    out[digest]={'state':'not_started','hotel_ids':item['hotels']}
pathlib.Path('pub/summary.json').write_text(json.dumps(out))
PY""".replace("__INDEX_PATH__", index_path).replace("__INDEX_SHA__", sha256(index_bytes)).replace("__DIGESTS__", digests).replace("__ITEMS__", repr(items))
    oldcheck = "          php tools/anytour_existing_post_batch.php --check-next-transfer-plan " + BASE_PLAN + " > pub/plan-check.json"
    workflow = once(workflow, oldcheck, indent(precheck, "          "))
    start, end = '          set +e\n', '          test "$status" -eq 0\n'
    require(workflow.count(start) == workflow.count(end) == 1, "existing apply block changed")
    block = workflow.partition(start)[2].partition(end)[0]
    original_apply = [line.strip() for line in block.splitlines() if line.strip().startswith('ssh ')]
    require(len(original_apply) == 1, "existing editor invocation changed")
    invoke = once(original_apply[0], "--apply-next-transfer "+BASE_PLAN, "--apply-ranked-transfer $digest")
    invoke = once(invoke, "> pub/result.json", '> "pub/result-$digest.json"')
    run_checks = """for digest in __DIGESTS__; do
  python3 - "$digest" <<'PY'
import json, pathlib, sys
p=pathlib.Path('pub/summary.json')
summary=json.loads(p.read_bytes())
assert summary[sys.argv[1]]['state']=='not_started','chunk_replay_forbidden'
summary[sys.argv[1]]['state']='invoked_requires_readback'
p.write_text(json.dumps(summary))
PY
  status=0
  __INVOKE__ || status=$?
  python3 - "$digest" "$status" <<'PY'
import json, os, pathlib, sys
digest,status=sys.argv[1],int(sys.argv[2])
items=__ITEMS__
item=next(r for r in items if r['sha256']==digest)
summary=pathlib.Path('pub/summary.json')
states=json.loads(summary.read_bytes())
state=states[digest]
p=pathlib.Path('pub/result-'+digest+'.json')
assert 0<p.stat().st_size<=1000000,'original_result_missing_or_unbounded_requires_readback'
d=json.loads(p.read_bytes())
if not isinstance(d.get('results'),list):
    state['state']='requires_original_journals'
    summary.write_text(json.dumps(states))
    raise SystemExit('result_inconclusive_do_not_replay')
rows=d['results']
assert d.get('native_run_id')==os.environ['GITHUB_RUN_ID'] and d.get('application_sha')==os.environ['APPLICATION_SHA']
assert d.get('operation')==item['operation'] and d.get('plan_sha256')==digest
assert len(rows)<=len(item['hotels']) and [r['hotel_id'] for r in rows]==item['hotels'][:len(rows)]
assert [r['message_id'] for r in rows]==item['messages'][:len(rows)]
assert d.get('supplier_requests')==d.get('bot_starts')==0
confirmed=[]
held=[]
for i,row in enumerate(rows):
    assert row['url']=='https://t.me/anytour/'+item['messages'][i]
    if row.get('ok') is True:
        assert row['state']=='native_and_registry_verified' and row['provider_write_attempts']==1
        assert row['hotel_id_verified'] and row['photo_caption_button_verified'] and row['original_message_id_and_date_preserved']
        confirmed.append(row['hotel_id'])
    else:
        assert i==len(rows)-1 and row.get('ok') is False
        held.append({'hotel_id':row['hotel_id'],'state':row['state']})
assert d.get('provider_write_attempts')==sum(r['provider_write_attempts'] for r in rows)
ok=status==0 and d.get('ok') is True and confirmed==item['hotels'] and not held
state.update(state='complete' if ok else 'partial_requires_readback',confirmed=confirmed,held=held,unreached=item['hotels'][len(rows):])
summary.write_text(json.dumps(states))
print(json.dumps(state))
if not ok:
    raise SystemExit('held_or_partial_do_not_replay')
PY
done""".replace("__DIGESTS__", digests).replace("__INVOKE__", invoke).replace("__ITEMS__", repr(items))
    workflow = once(workflow, start+block+end, indent(run_checks, "          ")+"\n")
    return {RELEASE: release, WORKFLOW: workflow}
