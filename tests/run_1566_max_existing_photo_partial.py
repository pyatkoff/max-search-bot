import hashlib
import importlib.util
import json
import pathlib
import tempfile

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

# Preparation holds only the row with a missing read-only receipt and never
# reaches the writer. A later write of a different row still has PHP's recheck.
prepared_visits = []
def prepare_visit(mode, hotel, checkpoint):
    assert mode == "--batch-check" and checkpoint is None
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

print("Read-only preparation, separated apply and receipt integrity passed")
