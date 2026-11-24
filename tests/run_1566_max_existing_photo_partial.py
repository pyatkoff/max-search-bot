import importlib.util
import pathlib

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
