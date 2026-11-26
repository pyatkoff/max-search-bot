import argparse
import hashlib
import json
import pathlib
import re
import subprocess
import sys

SHARED_UNAVAILABLE = {
    "editor_busy", "editor_lock_unavailable", "installed_checkout_changed",
    "main_channel_edit_not_ready", "max_transfer_channel_configuration_changed",
}
PREPARATION_CONTRACT = "anytour-max-transfer-preparation-v1"


def call(cmd):
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        response = json.loads(result.stdout)
    except (ValueError, TypeError):
        return {"state": "unobserved", "reason": "missing_process_receipt"}
    if not isinstance(response, dict):
        return {"state": "unobserved", "reason": "invalid_process_receipt"}
    if result.returncode and response.get("state") not in {"stopped_before_edit", "held_no_retry"}:
        return {"state": "unobserved", "reason": "incomplete_process_receipt"}
    return response


def plan_rows(scope, plan):
    known = [r["hotel_id"] for r in scope["rows"]]
    held = {int(k): v for k, v in plan["held"].items()} if isinstance(plan["held"], dict) else {}
    ready = plan["ready"]
    if not (isinstance(ready,list) and len(set(ready))==len(ready) and set(ready).isdisjoint(held) and set(ready)|set(held)==set(known) and plan["provider_writes"]==0):
        raise ValueError("incomplete_original_preflight")
    return known, held


def preparation_row(hotel, response):
    state = response.get("state")
    if state == "ready_read_only":
        digest = response.get("checkpoint_sha256")
        if (response.get("hotel_id") == hotel and isinstance(digest, str)
                and re.fullmatch("[0-9a-f]{64}", digest)
                and all(response.get(key) == 0 for key in ("provider_writes", "new_posts", "ad_writes"))):
            return {"state": "ready_read_only", "checkpoint_sha256": digest}
        return {"state": "stopped_before_edit", "reason": "invalid_checkpoint"}
    if state == "stopped_before_edit" and isinstance(response.get("reason"), str):
        return {"state": state, "reason": response["reason"]}
    return {"state": "stopped_before_edit", "reason": "read_only_checkpoint_unavailable"}


def prepare_batch(scope, plan, visit):
    known, held = plan_rows(scope, plan)
    rows = []
    shared_reason = None
    for hotel in known:
        reason = held.get(hotel, shared_reason)
        if reason is not None:
            result = {"state": "stopped_before_edit", "reason": reason}
        else:
            result = preparation_row(hotel, visit("--batch-check", hotel, None))
            if result.get("reason") in SHARED_UNAVAILABLE:
                shared_reason = result["reason"]
        rows.append({"hotel_id": hotel, **result})
    return rows


def prepared_checkpoints(known, held, rows):
    if (not isinstance(rows, list) or not all(isinstance(row, dict) for row in rows)
            or [row.get("hotel_id") for row in rows] != known):
        raise ValueError("prepared_rows_differ_from_approved_scope")
    checkpoints = {}
    for hotel, row in zip(known, rows):
        state = row.get("state")
        if state == "ready_read_only":
            if (hotel in held or set(row) != {"hotel_id", "state", "checkpoint_sha256"}
                    or not isinstance(row["checkpoint_sha256"], str)
                    or not re.fullmatch("[0-9a-f]{64}", row["checkpoint_sha256"])):
                raise ValueError("invalid_prepared_checkpoint")
        elif (state != "stopped_before_edit" or set(row) != {"hotel_id", "state", "reason"}
              or not isinstance(row["reason"], str) or held.get(hotel, row["reason"]) != row["reason"]):
            raise ValueError("invalid_prepared_hold")
        checkpoints[hotel] = {key: value for key, value in row.items() if key != "hotel_id"}
    return checkpoints


def run_batch(scope, plan, visit, emit, prepared=None):
    known, held = plan_rows(scope, plan)
    checkpoints = None if prepared is None else prepared_checkpoints(known, held, prepared)
    states = {}
    stop = None
    for hotel in known:
        if hotel in held:
            response = {"state": "stopped_before_edit", "reason": held[hotel]}
        elif stop is not None:
            response = {"state": "untouched", "reason": stop}
        else:
            if checkpoints is None:
                response = visit("--batch-check", hotel, None)
                if response.get("state") == "ready_read_only":
                    response = preparation_row(hotel, response)
            else:
                response = checkpoints[hotel]
            if response.get("state") == "ready_read_only":
                response = visit("--batch-apply", hotel, response["checkpoint_sha256"])
            state = response.get("state")
            if state in {"held_no_retry","unobserved"}:
                stop = "previous_write_requires_reconciliation"
            elif state == "stopped_before_edit" and response.get("reason") in SHARED_UNAVAILABLE:
                stop = "shared_publisher_unavailable"
            elif state == "existing_max_message_updated_and_verified":
                if response.get("hotel_id") != hotel or response.get("body_photo_button_verified") is not True or any(not re.fullmatch("[0-9a-f]{64}",response.get(k,"")) for k in ["button_url_sha256","source_photo_sha256","caption_sha256"]):
                    response={"state":"unobserved","reason":"incomplete_native_confirmation"}
                    stop="previous_write_requires_reconciliation"
            elif state != "stopped_before_edit":
                response={"state":"unobserved","reason":"unexpected_editor_response"}
                stop="previous_write_requires_reconciliation"
        response={"hotel":hotel,**response}
        states[hotel]=response
        emit(response)
    return states

def stage_binding(stage):
    return {name: hashlib.sha256((stage / name).read_bytes()).hexdigest()
            for name in ("scope.json", "native-targets.json", "native-source.json")}


def read_preparation(file, digest, stage):
    with file.open("rb") as stream:
        raw = stream.read(128_001)
    if len(raw) > 128_000 or hashlib.sha256(raw).hexdigest() != digest:
        raise ValueError("preparation_digest_mismatch")
    receipt = json.loads(raw)
    if (receipt.get("contract") != PREPARATION_CONTRACT or receipt.get("provider_writes") != 0
            or receipt.get("input_sha256") != stage_binding(stage)):
        raise ValueError("preparation_input_changed")
    return receipt["rows"]


def main(argv):
    parser = argparse.ArgumentParser()
    parser.add_argument("stage", type=pathlib.Path)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--prepare", action="store_true")
    mode.add_argument("--apply-prepared", nargs=2, metavar=("RECEIPT", "SHA256"))
    args = parser.parse_args(argv)
    stage = args.stage.resolve()
    inputs = stage_binding(stage) if args.prepare else None
    scope=json.loads((stage/"scope.json").read_bytes())
    e=scope["native_source"]
    common=[str(stage/"native-targets.json"),str(stage/"native-source.json"),e["json_sha256"],e["repository_sha"],e["application_sha"]]
    editor=["php",str(stage/"editor.php")]
    plan=call(editor+["--batch-plan",*common])
    def visit(mode,hotel,checkpoint):
        return call(editor+[mode,*common,str(hotel)]+([checkpoint] if checkpoint else []))
    def emit(row):
        print("RESULT "+str(row["hotel"])+" "+json.dumps(row,ensure_ascii=False,separators=(",",":")),flush=True)
    if args.prepare:
        rows = prepare_batch(scope, plan, visit)
        if stage_binding(stage) != inputs:
            raise ValueError("preparation_input_changed")
        print(json.dumps({"contract": PREPARATION_CONTRACT, "provider_writes": 0,
                          "input_sha256": inputs, "rows": rows}))
        return
    prepared = None
    if args.apply_prepared is not None:
        file, digest = args.apply_prepared
        prepared = read_preparation(pathlib.Path(file), digest, stage)
    run_batch(scope, plan, visit, emit, prepared)


if __name__ == "__main__":
    main(sys.argv[1:])
