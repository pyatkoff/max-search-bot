import json,pathlib,re
p=pathlib.Path('tools/direct_demand/operations')
old=(p/'read-commercial-price-monitor-20261104-read7.php').read_text()
new=(p/'read-commercial-price-monitor-20261104-read8.php').read_text()
a=json.loads(re.search(r"<<<'_ANYTOUR_PRICE150_SCOPE_'\n([\s\S]*?)\n_ANYTOUR_PRICE150_SCOPE_",old).group(1))
b=json.loads(re.search(r"<<<'_ANYTOUR_PRICE157_SCOPE_'\n([\s\S]*?)\n_ANYTOUR_PRICE157_SCOPE_",new).group(1))
plan=json.loads((p/'commercial-price-monitor-20261104-read8-plan.json').read_text())
assert len(a)==150 and b[:150]==a and len(b)==157
assert [r['local_tv_id'] for r in b]==plan['catalog_ids'] and len({r['local_tv_id'] for r in b})==157
assert sum(r['identity_provenance']=='accepted_typed_bridge' for r in b)==15
assert all(r['expected_country_id']==4 and r['identity_provenance']=='accepted_native_catalog_read' and r['expected_name'] for r in b[150:])
assert [r['local_tv_id'] for r in b[150:]]==[1659,21695,28668,55205,1334,1715,1543]
assert plan['persistence_cutoff_utc_epoch']==1793812524 and plan['max_age_seconds']==108000
assert "AND UNIX_TIMESTAMP(created_at)>=" in new and "$createdAfterEpochUtc=price157_persistence_cutoff(time());" in new
print(json.dumps({'operation':'commercial-price-monitor-20261104-read8-pure-prepare','ok':True,'scope_hotels':157,'previous150_scope_identical':True,'typed_bridges_preserved':15,'new_native_scope':7,'provider_calls':0,'db_reads':0,'db_writes':0,'message_sends':0},sort_keys=True))
