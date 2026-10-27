#!/usr/bin/env python3
"""Canonical SQLite parent on runner; canonical provider/key only on trusted host."""
from __future__ import annotations
import base64
import datetime as dt
import io
import json
import os
from pathlib import Path
import re
import shlex
import shutil
import subprocess
import tarfile
import time
import pyrage
from trusted_ssh import trusted_hosts, require, sha, enc, MAX_ENVELOPE, APP_ROOT

FILES=("model_control_launch.php","model_control_fixture.php","model_control_provider_once.php",
       "model_control_provider_rpc.php","model_control_provider_remote.php","model-case-plan.json","RUN_SPEC.json")

def run_ssh(command: list[str], script: str, timeout: int=60) -> subprocess.CompletedProcess:
    return subprocess.run(command+["bash -se"],input=script,capture_output=True,text=True,timeout=timeout)

def archive_files(files: dict[str,bytes]) -> bytes:
    out=io.BytesIO()
    with tarfile.open(fileobj=out,mode="w") as archive:
        for name,data in files.items():
            item=tarfile.TarInfo(name);item.size=len(data);item.mode=0o600;item.mtime=0
            archive.addfile(item,io.BytesIO(data))
    return out.getvalue()

def main() -> int:
    temp=Path(os.environ["RUNNER_TEMP"]);repo=Path.cwd();payload=repo/os.environ["CONTROL_PAYLOAD_PATH"]
    operation=os.environ["OPERATION_ID"];expected=os.environ["EXPECTED_RUNTIME_SHA"];admission=os.environ["OWNER_ADMISSION_URL"]
    require(operation=="HOTEL-MODEL-JAZ99-SUNRISE1554-20261027-V1","operation_id_invalid")
    require(re.fullmatch(r"[a-f0-9]{40}",expected) is not None,"runtime_sha_invalid")
    require(re.fullmatch(r"https://github.com/pyatkoff/max-search-bot/issues/1122#issuecomment-[0-9]+",admission) is not None,"owner_receipt_invalid")
    recipient=pyrage.x25519.Recipient.from_str(os.environ["AGE_RECIPIENT"])
    envelope={"schema":"hotel_two_model_audit_envelope_v2","operation_id":operation,"expected_runtime_sha":expected,
        "audit_sha":os.environ["GITHUB_SHA"],"github_run_id":os.environ["GITHUB_RUN_ID"],"github_run_attempt":os.environ["GITHUB_RUN_ATTEMPT"],
        "status":"NOT_ISSUED","automatic_retry_allowed":False,"parent_result":None,"remote_result":None}
    phase="before_credentials";prepared=None;command=None;work="/tmp/"+operation.lower();remote_staged=False
    try:
        require(os.environ["GITHUB_RUN_ATTEMPT"]=="1","run_attempt_not_original")
        raw=(payload/"payload-manifest.json").read_bytes();require(sha(raw)==os.environ["EXPECTED_PAYLOAD_MANIFEST_SHA256"],"payload_manifest_seal")
        manifest=json.loads(raw);require(set(manifest["files"])==set(FILES),"payload_members_changed")
        local=temp/"hotel-model-parent";require(not local.exists(),"parent_directory_already_exists");local.mkdir(mode=0o700)
        for name in FILES:
            data=(payload/name).read_bytes();require(sha(data)==manifest["files"][name] and len(data)<=65536,"payload_file_seal")
            (local/name).write_bytes(data);(local/name).chmod(0o600)
        (local/"repo").symlink_to(repo,target_is_directory=True);prepared=local/"prepared"
        run=subprocess.run(["php",str(local/"model_control_launch.php"),"--prepare","--prepared="+str(prepared)],capture_output=True,text=True,timeout=60)
        require(run.returncode==0,"runner_zero_call_prepare_failed")
        frozen=json.loads((prepared/"prepared-manifest.json").read_bytes());records={}
        require(len(frozen["cases"])==2,"two_cases_required")
        for row in frozen["cases"]:
            record=json.loads((prepared/(row["case_id"]+".json")).read_bytes());records[row["case_id"]]=record
        require(sum(x["request_bytes"] for x in records.values())<=100000,"total_request_byte_reserve_exceeded")
        envelope["prepared_manifest"]=frozen;envelope["case_records"]=records
        known=trusted_hosts(os.environ["DEPLOY_HOST"],temp)
        command=["ssh","-i",str(temp/"hotel_model_existing_key"),"-o","BatchMode=yes","-o","StrictHostKeyChecking=yes",
            "-o","UserKnownHostsFile="+str(known),"-o","ConnectTimeout=10","-o","ServerAliveInterval=10","-o","ServerAliveCountMax=2",
            os.environ["DEPLOY_USER"]+"@"+os.environ["DEPLOY_HOST"]]
        deadline=int(time.time())+1200
        meta={"schema":"hotel_model_remote_operation_v1","operation_id":operation,"expected_runtime_sha":expected,
            "operator_receipt":admission,"max_paid_calls":2,"deadline_unix":deadline,
            "allowed_request_hashes":{x["request_sha256"]:case for case,x in records.items()},
            "source_pins":{k:v for k,v in frozen["source_pins"].items() if not k.startswith("control/")}}
        archive=archive_files({"model_control_provider_remote.php":(local/"model_control_provider_remote.php").read_bytes(),"operation.json":enc(meta).encode()})
        script=["set -euo pipefail","umask 077",f"cd {shlex.quote(APP_ROOT)}",f'test "$(git rev-parse HEAD)" = "{expected}"',
            "git diff --quiet","git diff --cached --quiet",f"test ! -e {shlex.quote(work)}",f"mkdir -m 700 {shlex.quote(work)}",
            f"printf '%s' '{base64.b64encode(archive).decode()}' | base64 --decode > '{work}/payload.tar'",
            f'test "$(sha256sum {work}/payload.tar | cut -d\' \' -f1)" = "{sha(archive)}"',
            f"tar -xf '{work}/payload.tar' -C '{work}'",f"rm -f '{work}/payload.tar'",f"ln -s '{APP_ROOT}' '{work}/repo'",
            "php -r 'exit(PHP_SAPI===\"cli\"&&extension_loaded(\"curl\")?0:44);'"]
        for path,digest in meta["source_pins"].items():script.append(f'test "$(sha256sum {shlex.quote(path)} | cut -d\' \' -f1)" = "{digest}"')
        phase="remote_staging_started_no_provider";remote=run_ssh(command,"\n".join(script)+"\n")
        require(remote.returncode==0,"remote_provider_staging_or_capability_failed");remote_staged=True
        approval=json.loads((prepared/"approval-required.json").read_bytes());approval.update(operator_authorized=True,operator_receipt=admission,
            not_before_utc=dt.datetime.fromtimestamp(time.time()-5,dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            deadline_utc=dt.datetime.fromtimestamp(deadline,dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))
        approved=prepared/"APPROVED.json";approved.write_text(enc(approval)+"\n");approved.chmod(0o600)
        child_env=os.environ.copy();child_env.update(HOTEL_CONTROL_PROVIDER_RPC="1",HOTEL_CONTROL_RPC_HOST=os.environ["DEPLOY_HOST"],
            HOTEL_CONTROL_RPC_USER=os.environ["DEPLOY_USER"],HOTEL_CONTROL_RPC_WORK=work,
            HOTEL_CONTROL_RPC_KEY=str(temp/"hotel_model_existing_key"),HOTEL_CONTROL_RPC_KNOWN_HOSTS=str(known))
        phase="parent_execution_started_outcome_unknown"
        run=subprocess.run(["php",str(local/"model_control_launch.php"),"--execute","--prepared="+str(prepared),"--approval="+str(approved)],
            capture_output=True,text=True,env=child_env,timeout=150)
        result_path=prepared/"execution/attempt-receipt.json"
        if result_path.is_file():
            require(result_path.stat().st_size<=MAX_ENVELOPE,"parent_receipt_bound");envelope["parent_result"]=json.loads(result_path.read_bytes())
        attempt=envelope["parent_result"] or {};require(type(attempt.get("calls_issued",0)) is int and 0<=attempt.get("calls_issued",0)<=2,"parent_call_bound")
        envelope["status"]="COMPLETED_REQUIRES_SEMANTIC_REVIEW" if run.returncode==0 and attempt.get("status")=="TWO_MODEL_TEXTS_SAVED_READBACK_PENDING_SEMANTIC_REVIEW" else "STOPPED_NO_AUTOMATIC_RETRY"
    except Exception as exc:
        code=str(exc);envelope["safe_error"]=code if re.fullmatch(r"[a-z_]{3,80}",code) else "audit_preflight_or_outcome_unknown"
        envelope["phase"]=phase;envelope["status"]="STOPPED_NO_AUTOMATIC_RETRY"
        if prepared is not None and (prepared/"execution/attempt-receipt.json").is_file():
            p=prepared/"execution/attempt-receipt.json"
            if p.stat().st_size<=MAX_ENVELOPE:envelope["parent_result"]=json.loads(p.read_bytes())
    # This is receipt recovery only: never invokes a provider or resumes execution.
    if remote_staged and command is not None:
        recovery="""set -euo pipefail
php <<'READ_PRIVATE_RECEIPTS'
<?php
ini_set('display_errors','0');error_reporting(0);$w=__WORK__;$rows=[];
foreach(glob($w.'/provider-receipts/*.json')?:[] as $p){if(filesize($p)>262144)exit(45);$x=json_decode(file_get_contents($p),true);if(is_array($x))$rows[basename($p)]=$x;}
$out=['schema'=>'hotel_remote_provider_receipts_v1','receipts'=>$rows];$raw=json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($raw)>1048576)exit(45);echo $raw;
READ_PRIVATE_RECEIPTS
""".replace("__WORK__",json.dumps(work))
        try:
            recovered=run_ssh(command,recovery,45)
            if recovered.returncode==0 and len(recovered.stdout.encode())<=MAX_ENVELOPE:envelope["remote_result"]=json.loads(recovered.stdout)
            else:envelope["remote_receipt_recovery"]="UNAVAILABLE_NO_REEXECUTION"
        except Exception:envelope["remote_receipt_recovery"]="UNKNOWN_NO_REEXECUTION"
    raw=enc(envelope).encode();require(len(raw)<=MAX_ENVELOPE,"encrypted_envelope_bound")
    encrypted=pyrage.encrypt(raw,[recipient]);out=temp/"hotel-two-model-evidence.age"
    with out.open("xb") as stream:stream.write(encrypted)
    out.chmod(0o600)
    attempt=envelope.get("parent_result") or {}
    print(enc({"status":envelope["status"],"calls_started":attempt.get("calls_issued"),"automatic_retry_allowed":False,
        "ciphertext_bytes":len(encrypted),"ciphertext_sha256":sha(encrypted)}))
    return 0 if envelope["status"]=="COMPLETED_REQUIRES_SEMANTIC_REVIEW" else 1

if __name__=="__main__":raise SystemExit(main())
