import json,pathlib,re
base=pathlib.Path('tools/direct_demand/operations')
old=(base/'read-commercial-price-monitor-20261104-read9.php').read_text();new=(base/'read-commercial-price-monitor-20261104-read10-72h.php').read_text()
left="$scope=json_decode(<<<'_ANYTOUR_PRICE157_SCOPE_'\n";right="\n_ANYTOUR_PRICE157_SCOPE_,true,512,JSON_THROW_ON_ERROR);"
def scope(s):return json.loads(s.split(left)[1].split(right)[0])
a,b=scope(old),scope(new);assert len(a)==157 and len(b)==159 and a==b[:157]
assert len({x['local_tv_id'] for x in b})==159 and {x['local_tv_id'] for x in b[157:]}=={17480,1100}
assert [x for x in a if x['identity_provenance']=='accepted_typed_bridge']==[x for x in b if x['identity_provenance']=='accepted_typed_bridge'] and len([x for x in b if x['identity_provenance']=='accepted_typed_bridge'])==15
assert 'UNIX_TIMESTAMP(created_at)<?' in new and 'price72_upper_for_hotel' in new and 'LIMIT 2' in new and '$now-259200' in new
print('READ10_72H_EXACT_SCOPE_OK prefix157_preserved typed15_preserved new2_unread_scope bounded2 new_historical_slice_excludes_closed_read9 rows_server0 provider0')
