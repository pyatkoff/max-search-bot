import base64,hashlib,json,os,pathlib
temp=pathlib.Path(os.environ['RUNNER_TEMP']);base=pathlib.Path('tools/direct_demand/operations')
def one(name):
 p=list((temp/'original-context13').rglob(name));assert len(p)==1;return p[0]
result=json.loads(one('context13-readback.private.json').read_text().splitlines()[-1]);old=json.loads(one('context13-input.private.json').read_text())
assert result['ok'] and result['operation']=='commercial-price-monitor-20261105-context13-approved-manual1' and len(result['bindings'])==5
ids={int(x['native_hotel_id']) for x in result['deferred'] if x['reason']=='current_original_button_shape_not_proven'}
assert ids==set([235,110,132,111,262,237,174,178,273,408,416,342,267,371,280,32575,12112,4191,26044,435,9374,13870,79059,55574,78898,43204,37668,77914,69409,111801,115918,112914,108090,1348,1379,1583,1559,1453,1543,1582,1449,1199,111129,109900,111045,15869,74503,27316,3405]) and len(ids)==49 and not(ids&{int(x['hotel']['local_tv_id']) for x in result['bindings']})
old['items']=[x for x in old['items'] if int(x['hotel']['local_tv_id']) in ids];assert len(old['items'])==49
old['operation']='commercial-price-monitor-20261105-context14-owned-button-proof1';old['owned_max_only_native_scope']=sorted(ids);old['previous_bound_five_excluded']=True
raw=(json.dumps(old,ensure_ascii=False,separators=(',',':'))+'\n').encode();(temp/'context14-input.private.json').write_bytes(raw)
s=(base/'read-current-price-owned-button-contexts-20261105-context14.php').read_text();prefix="<?php\ndeclare(strict_types=1);\n";assert s.startswith(prefix)
constants={'EXPECTED_INSTALLED_SHA':os.environ['EXPECTED_PRODUCTION_SHA'],'APPROVED_CONTEXT_INPUT_B64':base64.b64encode(raw).decode(),'APPROVED_CONTEXT_INPUT_SHA256':hashlib.sha256(raw).hexdigest(),'APPROVED_CAPTURE_RUN':int(os.environ['GITHUB_RUN_ID'])}
header=prefix+''.join('const '+k+'='+json.dumps(v)+';\n' for k,v in constants.items());(temp/'context14.php').write_text(header+s[len(prefix):])
print(json.dumps({'operation':old['operation'],'new_related_tour_hotels_only':49,'previous_bound_five_reread':0,'missing_two_not_queried':2,'max_owned_get_limit':49,'telegram_owned_gets':0,'source_or_price_db_requeries':0,'old_fixtures':0}))
