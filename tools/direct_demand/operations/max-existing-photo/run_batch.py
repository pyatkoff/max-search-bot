import json
import pathlib
import re
import subprocess
import sys

def call(cmd):
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        response = json.loads(result.stdout)
    except (ValueError, TypeError):
        return {"state": "unobserved", "reason": "missing_process_receipt"}
    if result.returncode and response.get("state") not in {"stopped_before_edit", "held_no_retry"}:
        return {"state": "unobserved", "reason": "incomplete_process_receipt"}
    return response

def run_batch(scope, plan, visit, emit):
    known = [r["hotel_id"] for r in scope["rows"]]
    held = {int(k): v for k, v in plan["held"].items()} if isinstance(plan["held"], dict) else {}
    ready = plan["ready"]
    if not (isinstance(ready,list) and len(set(ready))==len(ready) and set(ready).isdisjoint(held) and set(ready)|set(held)==set(known) and plan["provider_writes"]==0):
        raise ValueError("incomplete_original_preflight")
    states = {}
    stop = None
    for hotel in known:
        if hotel in held:
            response = {"state": "stopped_before_edit", "reason": held[hotel]}
        elif stop is not None:
            response = {"state": "untouched", "reason": stop}
        else:
            response = visit("--batch-check",hotel,None)
            h = response.get("checkpoint_sha256","")
            if response.get("state") == "ready_read_only":
                if response.get("hotel_id") != hotel or not re.fullmatch("[0-9a-f]{64}",h) or any(response.get(k) != 0 for k in ["provider_writes","new_posts","ad_writes"]):
                    response = {"state":"stopped_before_edit","reason":"invalid_checkpoint"}
                else:
                    response = visit("--batch-apply",hotel,h)
            state = response.get("state")
            if state in {"held_no_retry","unobserved"}:
                stop = "previous_write_requires_reconciliation"
            elif state == "stopped_before_edit" and response.get("reason") in {"editor_busy","editor_lock_unavailable","installed_checkout_changed","main_channel_edit_not_ready","max_transfer_channel_configuration_changed"}:
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

if __name__ == "__main__":
    stage = pathlib.Path(sys.argv[1]).resolve()
    scope=json.loads((stage/"scope.json").read_bytes())
    e=scope["native_source"]
    common=[str(stage/"native-targets.json"),str(stage/"native-source.json"),e["json_sha256"],e["repository_sha"],e["application_sha"]]
    editor=["php",str(stage/"editor.php")]
    plan=call(editor+["--batch-plan",*common])
    def visit(mode,hotel,checkpoint):
        return call(editor+[mode,*common,str(hotel)]+([checkpoint] if checkpoint else []))
    def emit(row):
        print("RESULT "+str(row["hotel"])+" "+json.dumps(row,ensure_ascii=False,separators=(",",":")),flush=True)
    run_batch(scope,plan,visit,emit)
