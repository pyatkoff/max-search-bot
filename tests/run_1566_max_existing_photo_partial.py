import hashlib
import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import tarfile
import tempfile
import textwrap
from datetime import datetime, timezone

root=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location("producer",root/"tools/direct_demand/operations/max-existing-photo/run_batch.py")
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
scope={"rows":[{"hotel_id":n} for n in range(1,8)]}
plan={"ready":[1,2,4,5,6,7],"held":{"3":"occupied"},"provider_writes":0}
def exercise(result):
    visits=[]
    def visit(mode,hotel,checkpoint):
        visits.append((mode,hotel))
        if mode=="--batch-check":
            return {"state":"ready_read_only","checkpoint_sha256":"a"*64,"hotel_id":hotel,"provider_writes":0,"ad_writes":0,"new_posts":0}
        assert checkpoint=="a"*64
        if hotel==2: return result
        return {"state":"existing_max_message_updated_and_verified","body_photo_button_verified":True,"button_url_sha256":"a"*64,
                "source_photo_sha256":"b"*64,"caption_sha256":"c"*64,"hotel_id":hotel}
    return module.run_batch(scope,plan,visit,lambda row:None), visits

states,visits=exercise({"state":"held_no_retry","reason":"native_photo_differs"})
assert states[1]["state"]=="existing_max_message_updated_and_verified" and states[2]["state"]=="held_no_retry"
assert visits==[("--batch-check",1),("--batch-apply",1),("--batch-check",2),("--batch-apply",2)]
assert states[3]["state"]=="stopped_before_edit" and all(states[n]["state"]=="untouched" for n in range(4,8))
states,visits=exercise({"state":"stopped_before_edit","reason":"target_receipt_exists_no_replay"})
assert len(visits)==12 and all(states[n]["state"]=="existing_max_message_updated_and_verified" for n in [1,4,5,6,7])
states,visits=exercise({"state":"stopped_before_edit","reason":"editor_busy"})
assert len(visits)==4 and states[4]["state"]=="untouched"
print("Partial MAX confirmations, ambiguity and common lease passed")


# The legacy combined call retains its original stop when the read receipt
# is missing. Only the explicitly read-only preparation may continue.
legacy_visits = []
def legacy_visit(mode, hotel, checkpoint):
    legacy_visits.append((mode, hotel))
    assert mode == "--batch-check" and checkpoint is None
    return {"state": "unobserved", "reason": "missing_process_receipt"}

legacy = module.run_batch(scope, plan, legacy_visit, lambda row: None)
assert legacy_visits == [("--batch-check", 1)]
assert legacy[1]["state"] == "unobserved" and legacy[3]["reason"] == "occupied"
assert all(legacy[n]["state"] == "untouched" for n in (2, 4, 5, 6, 7))

# Preparation holds only the row with a missing read-only receipt and never
# reaches the writer. A later write of a different row still has PHP's recheck.
prepared_visits = []
def prepare_visit(mode, hotel, checkpoint):
    assert mode == "--batch-prepare-check" and checkpoint is None
    prepared_visits.append(hotel)
    if hotel == 2:
        return {"state": "unobserved", "reason": "missing_process_receipt"}
    return {"state": "ready_read_only", "hotel_id": hotel, "checkpoint_sha256": "a" * 64,
            "provider_writes": 0, "new_posts": 0, "ad_writes": 0}

prepared = module.prepare_batch(scope, plan, prepare_visit)
assert prepared_visits == [1, 2, 4, 5, 6, 7]
assert prepared[1]["reason"] == "read_only_checkpoint_unavailable"
assert prepared[2]["reason"] == "occupied"

applied = []
def apply_visit(mode, hotel, checkpoint):
    assert mode == "--batch-apply" and checkpoint == "a" * 64
    applied.append(hotel)
    if hotel == 5:
        return {"state": "unobserved", "reason": "missing_process_receipt"}
    return {"state": "existing_max_message_updated_and_verified", "hotel_id": hotel,
            "body_photo_button_verified": True, "button_url_sha256": "a" * 64,
            "source_photo_sha256": "b" * 64, "caption_sha256": "c" * 64}

staged = module.run_batch(scope, plan, apply_visit, lambda row: None, prepared)
assert applied == [1, 4, 5]
assert staged[2]["state"] == "stopped_before_edit" and staged[5]["state"] == "unobserved"
assert staged[6]["state"] == staged[7]["state"] == "untouched"

for invalid in (prepared[:-1], prepared[:2] + [prepared[0] | {"hotel_id": 3}] + prepared[3:]):
    try:
        module.run_batch(scope, plan, apply_visit, lambda row: None, invalid)
    except ValueError:
        pass
    else:
        raise AssertionError("Unapproved prepared rows reached the editor")
assert applied == [1, 4, 5]

with tempfile.TemporaryDirectory() as directory:
    stage = pathlib.Path(directory)
    for name in ("scope.json", "native-targets.json", "native-source.json"):
        (stage / name).write_text("{}")
    receipt = stage / "preparation.json"
    receipt.write_text(json.dumps({"contract": module.PREPARATION_CONTRACT, "provider_writes": 0,
                                  "input_sha256": module.stage_binding(stage), "rows": prepared}))
    digest = hashlib.sha256(receipt.read_bytes()).hexdigest()
    assert module.read_preparation(receipt, digest, stage) == prepared
    for wrong_digest, changed_input, expected in [
        (True, False, "preparation_digest_mismatch"), (False, True, "preparation_input_changed"),
    ]:
        if changed_input:
            (stage / "native-source.json").write_text('{"changed":true}')
        try:
            module.read_preparation(receipt, "0" * 64 if wrong_digest else digest, stage)
        except ValueError as error:
            assert str(error) == expected
        else:
            raise AssertionError("Changed native inputs were accepted")

    (stage / "native-source.json").write_text("{}")
    scope_file = stage / "scope.json"
    route_scope = {"rows": [{"hotel_id": 466, "target_mid": "original"}],
                   "route_readback": {"repository": "pyatkoff/tour-max", "run_id": 41, "job_id": 42,
                                      "observed_utc": "2026-11-26T22:21:51+00:00", "write_calls": 0}}
    scope_file.write_text(json.dumps(route_scope))
    original_scope = stage / "preparation-scope.json"
    original_scope.write_bytes(scope_file.read_bytes())
    receipt.write_text(json.dumps({"contract": module.PREPARATION_CONTRACT, "provider_writes": 0,
                                  "input_sha256": module.stage_binding(stage), "rows": prepared}))
    digest = hashlib.sha256(receipt.read_bytes()).hexdigest()
    route_scope["route_readback"].update(run_id=43, job_id=44, observed_utc="2026-11-26T22:36:00+00:00")
    scope_file.write_text(json.dumps(route_scope))
    assert module.read_preparation(receipt, digest, stage) == prepared
    for key, value in (("target_mid", "changed"), ("write_calls", 1)):
        changed = json.loads(scope_file.read_text())
        if key == "target_mid":
            changed["rows"][0][key] = value
        else:
            changed["route_readback"][key] = value
        scope_file.write_text(json.dumps(changed))
        try:
            module.read_preparation(receipt, digest, stage)
        except ValueError as error:
            assert str(error) == "preparation_input_changed"
        else:
            raise AssertionError("An editorial or route policy change reused an old checkpoint")
        scope_file.write_text(json.dumps(route_scope))
    original_scope.write_text("{}")
    try:
        module.read_preparation(receipt, digest, stage)
    except ValueError as error:
        assert str(error) == "preparation_input_changed"
    else:
        raise AssertionError("An unbound original scope reused a checkpoint")

print("Read-only preparation, separated apply and receipt integrity passed")
print("A new MAX2 route receipt can reuse preparation; changed post, route policy and original scope cannot")

with tempfile.TemporaryDirectory() as directory:
    stage = pathlib.Path(directory)
    ssh = stage / "ssh"
    ssh.write_text('#!/usr/bin/env bash\nexec bash -c "${@: -1}"\n')
    ssh.chmod(0o700)
    entry = stage / "execute.sh"
    entry.write_text('#!/usr/bin/env bash\ntest -d "$1" && printf "%s %s\\n" "$2" "$3"\n')
    archive = stage / "approved.tar"
    with tarfile.open(archive, "w") as package:
        package.add(entry, arcname=entry.name)
    environment = dict(os.environ, PATH=f"{stage}:{os.environ['PATH']}", RUNNER_TEMP=directory,
                       HOST_VALUE="fixture", USER_VALUE="fixture")
    helper = root / "tools/direct_demand/operations/max-existing-photo/run_remote_batch.sh"
    for phase, seal, valid in (("prepare", "-", True), ("apply", "e" * 64, True), ("apply", "-", False)):
        result = subprocess.run(["bash", helper, phase, seal], input=archive.read_bytes(),
                                env=environment, capture_output=True, check=False)
        assert (result.returncode == 0) is valid, result.stderr
        assert result.stdout == (f"{phase} {seal}\n".encode() if valid else b"")

print("SSH wrapper preserves the requested phase and rejects an unsealed apply")

with tempfile.TemporaryDirectory() as directory:
    stage = pathlib.Path(directory)
    pub = stage / "pub"
    pub.mkdir()
    tool = stage / "tools"
    tool.mkdir()
    workflow = (root / ".github/workflows/edit-1566-historical-max-photo-posts.yml").read_text()
    code = workflow.split("cat > pub/admit.cjs <<'NODE'\n", 1)[1].split("          NODE\n", 1)[0]
    admission = pub / "admit.cjs"
    admission.write_text(textwrap.dedent(code))
    now = datetime.now(timezone.utc).isoformat()
    archive_head = "3a9195debcfbeb703a220895871f5a40a61d3010"
    (pub / "manifest.json").write_text(json.dumps({
        "archive_run_id": "45630394590", "archive_sha256": "a" * 64,
        "native_source": {"attempt": 1, "artifact_id": 22, "run_id": 23, "job_id": 24,
                          "workflow_head": "b" * 40, "zip_sha256": "c" * 64,
                          "repository_sha": "d" * 40, "application_sha": "d" * 40},
        "route_readback": {"write_calls": 0, "observed_utc": now},
    }))
    (tool / "deployment_release.js").write_text("""
const {native_source: native, archive_sha256: archiveDigest} = require('../pub/manifest.json')
exports.API_ROOT = '/repo'
exports.REPOSITORY = 'test/max'
exports.makeReadApi = () => async (_, endpoint) => {
  const archive = endpoint.includes('/actions/runs/45630394590')
  if (!endpoint.includes('/actions/runs/')) {
    if (process.env.TEST_MODE === 'verify-originals') throw Error('preparation_accessed_live_slot')
    const slot = process.env.TEST_FREE === '1' ? 'PR1748: установлено, выпуск свободен' : 'PR1748: выпуск занят'
    return {object:{sha:process.env.TEST_REPOSITORY},user:{id:226193297},body:slot}
  }
  const id = archive ? 45630394590 : native.run_id
  const head = archive ? process.env.TEST_ARCHIVE_HEAD : native.workflow_head
  const path = archive ? '.github/workflows/read-1566-max-archive-20261123.yml'
    : '.github/workflows/read-1566-old-ordinary-max-posts.yml'
  if (endpoint.includes('/jobs')) return {total_count:1,jobs:[{id:archive?139261677044:native.job_id,conclusion:'success',
    name:process.env.TEST_AUDIT_JOB}]}
  if (endpoint.includes('/artifacts')) return {total_count:1,artifacts:[{
    id:archive?15032515599:native.artifact_id,name:archive?'issue-1566-main-max-archive-inventory':'max-commercial-album-sources',
    expired:false,digest:'sha256:'+(archive?archiveDigest:native.zip_sha256),workflow_run:{id,head_sha:head}
  }]}
  return {id,head_sha:head,path,head_branch:process.env.TEST_AUDIT_BRANCH,repository:{id:1342493152},actor:{id:226193297},
    event:'push',run_attempt:1,status:'completed',conclusion:'success'}
}
exports.readRecord = async () => ({identity:'current-release-record'})
exports.resolveCurrent = async (api, input, record) => {
  if (input.sha !== process.env.TEST_REPOSITORY || record.identity !== 'current-release-record')
    throw Error('unexpected_release_resolution')
  return {ready:process.env.TEST_KIND !== 'pending', kind:process.env.TEST_KIND,
    repository_sha:process.env.TEST_ACCEPTED_REPOSITORY, application_sha:process.env.TEST_APPLICATION}
}
""")
    (tool / "release_priority.js").write_text("""
exports.verifyPreviousRelease = async ({repository, baseSha}) => {
  if (repository !== 'test/max' || baseSha !== process.env.TEST_REPOSITORY) throw Error('wrong_release_priority_base')
  console.log('current_release_admitted')
}
""")
    installed = "d" * 40
    later = "e" * 40
    for mode, free, repository, accepted_repo, application, kind, expected_error in [
        ("verify-originals", "0", later, later, installed, "pending", ""),
        ("authorize-write", "0", installed, installed, installed, "installed", "shared_release_changed"),
        ("authorize-write", "1", installed, installed, installed, "installed", ""),
        ("authorize-write", "1", later, later, installed, "application_unchanged", ""),
        ("authorize-write", "1", later, later, installed, "pending", "current_release_changed"),
        ("authorize-write", "1", later, later, later, "installed", "current_release_changed"),
        ("authorize-write", "1", later, later, installed, "installed", "current_release_changed"),
        ("authorize-write", "1", later, installed, installed, "application_unchanged", "current_release_changed"),
        ("", "1", installed, installed, installed, "installed", "invalid_admission_mode"),
    ]:
        environment = dict(os.environ, MAX_ARCHIVE_ID="15032515599", MAX_NATIVE_ID="22", MAX_PREPARED_ARTIFACT_ID="",
                           MAX_REPOSITORY_SHA="9" * 40, TEST_MODE=mode, TEST_FREE=free,
                           TEST_AUDIT_BRANCH="audit/1566-main-hotel-registry-20261119", TEST_AUDIT_JOB="registry",
                           TEST_ARCHIVE_HEAD=archive_head, TEST_REPOSITORY=repository,
                           TEST_ACCEPTED_REPOSITORY=accepted_repo, TEST_APPLICATION=application, TEST_KIND=kind)
        result = subprocess.run(["node", admission, mode], cwd=stage, env=environment,
                                capture_output=True, text=True, check=False)
        assert (result.returncode == 0) == (not expected_error), result.stderr
        assert result.stderr.strip() == expected_error, result.stderr
        if mode == "authorize-write" and not expected_error:
            assert result.stdout.strip() == "current_release_admitted", result.stdout

    for field, value in (("TEST_AUDIT_BRANCH", "main"), ("TEST_AUDIT_JOB", "prepare_only")):
        result = subprocess.run(["node", admission, "verify-originals"], cwd=stage,
                                env=environment | {field: value, "TEST_MODE": "verify-originals"},
                                capture_output=True, text=True, check=False)
        assert result.returncode != 0 and result.stderr.strip() == "original_audit_role_changed", result.stderr

print("MAX preparation ignores the release slot; writing requires the canonical accepted application")

editor_tools = root / "tools/direct_demand/operations/max-existing-photo"
sys.path.insert(0, str(editor_tools))
from stage_approved_scope import apply_activation, replace_route_read, verify_activation, verify_adopted_publisher

activation_path = "tools/direct_demand/operations/max-existing-photo/max-route-readback.json"
parent, head = "b" * 40, "c" * 40
activation = {"sha": head, "parents": [{"sha": parent}],
              "files": [{"filename": activation_path, "status": "modified"}]}
verify_activation(activation, parent, head, activation_path)
for rejected in [
    {**activation, "parents": [{"sha": head}]},
    {**activation, "files": activation["files"] + [{"filename": ".github/workflows/edit.yml", "status": "modified"}]},
    {**activation, "files": [{"filename": activation_path, "status": "removed"}]},
]:
    try:
        verify_activation(rejected, parent, head, activation_path)
    except ValueError:
        pass
    else:
        raise AssertionError("A non-data activation passed validation")

comparison = {"status": "ahead", "behind_by": 0, "merge_base_commit": {"sha": parent},
              "files": [{"filename": activation_path},
                        {"filename": ".github/workflows/read-1566-old-ordinary-max-posts.yml"}]}
verify_adopted_publisher(comparison, parent, activation_path)
for rejected in [
    {**comparison, "merge_base_commit": {"sha": head}},
    {**comparison, "files": comparison["files"] + [{"filename": "tools/publisher.py"}]},
]:
    try:
        verify_adopted_publisher(rejected, parent, activation_path)
    except ValueError:
        pass
    else:
        raise AssertionError("Publisher drift passed validation")

approved_scope = {"rows": [{"hotel_id": 466, "target_mid": "target", "text": "Approved"}],
                  "route_readback": {"repository": "pyatkoff/tour-max", "write_calls": 0,
                                     "run_id": 1, "job_id": 2, "observed_utc": "2026-11-26T22:42:39+00:00"}}
activation_route = {"targets": [{"offer_id": "max_466", "target_mid": "target"}],
                    "run_id": 3, "job_id": 4, "observed_utc": "2026-11-26T22:46:39+00:00"}
replace_route_read(approved_scope, activation_route)
assert approved_scope["rows"] == [{"hotel_id": 466, "target_mid": "target", "text": "Approved"}]
assert approved_scope["route_readback"] == {
    "repository": "pyatkoff/tour-max", "write_calls": 0, "run_id": 3, "job_id": 4,
    "observed_utc": "2026-11-26T22:46:39+00:00"
}
for rejected in [
    {**activation_route, "targets": [{"offer_id": "max_466", "target_mid": "other"}]},
    {**activation_route, "write_calls": 1},
    {**activation_route, "job_id": False},
]:
    try:
        replace_route_read(approved_scope, rejected)
    except ValueError:
        pass
    else:
        raise AssertionError("A different target or route policy passed activation")

base_native = {
    "operation": "max_commercial_album_sources_read_only", "attempt": 1,
    "run_id": 5, "job_id": 6, "artifact_id": 7,
    "workflow_head": "a" * 40, "repository_sha": "1" * 40, "application_sha": "2" * 40,
    "zip_sha256": "3" * 64, "json_sha256": "4" * 64,
}
baseline = approved_scope | {"native_source": base_native, "archive_sha256": "5" * 64,
                             "price_review": {"mode": "caption_without_fixed_price"}}
fresh = {"run_id": 8, "job_id": 9, "artifact_id": 10, "workflow_head": "b" * 40,
         "repository_sha": "6" * 40, "application_sha": "7" * 40,
         "zip_sha256": "8" * 64, "json_sha256": "9" * 64}
selected = json.loads(json.dumps(baseline))
assert apply_activation(selected, activation_route | {"native_source": fresh}) is True
assert selected == (baseline | {"native_source": base_native | fresh})
assert apply_activation(selected, activation_route | {"native_source": fresh}) is False
assert apply_activation(selected, activation_route) is False
for corrupt in (None, fresh | {"attempt": 2}, fresh | {"run_id": True},
                fresh | {"operation": "arbitrary"}, fresh | {"application_sha": "main"},
                fresh | {"zip_sha256": "0" * 63}):
    try:
        apply_activation(json.loads(json.dumps(baseline)), activation_route | {"native_source": corrupt})
    except ValueError:
        pass
    else:
        raise AssertionError("Unapproved native evidence passed activation")
try:
    apply_activation(selected, activation_route | {"rows": []})
except ValueError:
    pass
else:
    raise AssertionError("Activation changed the editorial scope")

print("One data-only activation can refresh the native receipt, with post, archive and publication policy fixed")
