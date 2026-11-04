import json,os,pathlib
temp=pathlib.Path(os.environ["RUNNER_TEMP"]);files=list((temp/"quote7-original").rglob("channel-preflight.json"));assert len(files)==1
d=json.loads(files[0].read_text().splitlines()[-1])
assert d["operation"]=="commercial-price-monitor-20261104-quote7" and d["catalog_id"]==110 and d["ok"] is True and d["gateway_requests_attempted"]==1 and d["http_status"]==200
q=d.get("gateway_response")
def outcome(q):
 if not isinstance(q,dict):return "RETAINED_RESPONSE_NOT_OBJECT_NO_PRICE_EDIT"
 if q.get("error") not in (None,False,0,""):return "RETAINED_GATEWAY_DECLARED_ERROR_NO_PRICE_EDIT"
 if not isinstance(q.get("flights"),list) or not q["flights"]:return "RETAINED_NO_FLIGHT_OPTIONS_NO_PRICE_EDIT"
 return "RETAINED_FLIGHTS_REQUIRE_FULL_CONTRACT_VALIDATION_NO_EDIT_YET"
assert outcome({"error":"fixture"})=="RETAINED_GATEWAY_DECLARED_ERROR_NO_PRICE_EDIT"
assert outcome({"flights":[]})=="RETAINED_NO_FLIGHT_OPTIONS_NO_PRICE_EDIT"
assert outcome({"flights":[{}]})=="RETAINED_FLIGHTS_REQUIRE_FULL_CONTRACT_VALIDATION_NO_EDIT_YET"
state={"operation":"commercial-price-monitor-20261104-quote7-contract-inspection1","catalog_id":110,"state":outcome(q),"original_quote_run":41920783291,"original_gateway_requests":1,"original_response_bytes":d["response_bytes"],"response_top_level_fields":sorted(q) if isinstance(q,dict) else [],"response_has_declared_error":isinstance(q,dict) and q.get("error") not in (None,False,0,""),"retained_flight_option_count":len(q.get("flights",[])) if isinstance(q,dict) and isinstance(q.get("flights"),list) else 0,"new_contract_shape_cases":3,"new_provider_calls":0,"db_reads":0,"server_calls":0,"message_edits":0,"new_posts":0,"automatic_retry_allowed":False}
(temp/"quote7-contract-state.json").write_text(json.dumps(state)+"\n")
print(json.dumps(state))
