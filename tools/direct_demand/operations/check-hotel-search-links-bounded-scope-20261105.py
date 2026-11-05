import ast,base64,copy,hashlib,json,os,pathlib,subprocess
b=pathlib.Path('tools/direct_demand/operations');s=json.loads((b/os.environ['LINK_SCOPE_FILENAME']).read_text());ids=s['native_hotel_ids'];bindings=s['native_bindings'];assert len(ids)==3 and [x['local_tv_id'] for x in bindings]==ids
plan=json.loads((b/'commercial-hotel-search-links-20261105-bounded1to10-plan1.json').read_text())
for filename,expected in plan['changed_file_sha256'].items():assert hashlib.sha256((b/filename).read_bytes()).hexdigest()==expected
harness=r'''<?php
function declarations(string $file,array $wanted):string{
 $t=token_get_all(file_get_contents($file));$out='';$found=[];
 for($i=0;$i<count($t);$i++){
  if(!is_array($t[$i])||$t[$i][0]!==T_FUNCTION)continue;
  $j=$i+1;while($j<count($t)&&is_array($t[$j])&&$t[$j][0]===T_WHITESPACE)$j++;
  if(!isset($t[$j])||!is_array($t[$j])||$t[$j][0]!==T_STRING)continue;
  $name=$t[$j][1];if(!in_array($name,$wanted,true))continue;
  $code='';$depth=0;$entered=false;
  for(;$i<count($t);$i++){$z=$t[$i];$v=is_array($z)?$z[1]:$z;$code.=$v;if(!is_array($z)&&$z==='{'){$depth++;$entered=true;}elseif(!is_array($z)&&$z==='}'){$depth--;if($entered&&$depth===0)break;}}
  $out.=$code."\n";$found[]=$name;
 }
 sort($found);$w=$wanted;sort($w);if($found!==$w)throw new RuntimeException('native_declarations_missing');return $out;
}
$x=json_decode(stream_get_contents(STDIN),true,128,JSON_THROW_ON_ERROR);
define('APPROVED_IDENTITY_BINDINGS_B64',base64_encode(json_encode($x['map'],JSON_THROW_ON_ERROR)));
eval(declarations($x['reader'],['contextPartition18Ok']));
eval(declarations($x['updater'],['linkIdentityMap18Ok','linkRole18Ok']));
echo json_encode(['partition'=>contextPartition18Ok($x['data'],$x['ids'],2),'map'=>linkIdentityMap18Ok($x['map']),'role'=>linkRole18Ok($x['binding'])],JSON_THROW_ON_ERROR);
'''
temp=pathlib.Path(os.environ['RUNNER_TEMP']);php=temp/'bounded-link-contract-native.php';php.write_text(harness)
checks=0;native=0;python=0
def check(value,label):
 global checks
 assert value,label
 checks+=1
def fixture(n):
 rows=copy.deepcopy(bindings[:min(n,len(bindings))])
 while len(rows)<n:rows.append({'local_tv_id':900000000+len(rows),'expected_name':'PURE_COUNT_CONTRACT_ONLY','expected_country_id':4,'identity_provenance':'accepted_native_catalog_read'})
 return [x['local_tv_id'] for x in rows],rows
def partition(z):
 return {'scope_native_ids':z,'items':[{'hotel':{'local_tv_id':x}} for x in z],'deferred_before_server':[],'exclude_already_linked_native_ids':[800000001,800000002]}
def native_case(z,m,d,expected,label,role=True):
 global native
 hid=m[0]['local_tv_id'] if m else ids[0];binding={'native_hotel_id':hid,'quote_state':'NOQUOTE','identity_provenance':'accepted_native_catalog_read','expected_country_id':4,'publisher_hotel_id':hid,'original_role_proof_kind':'original_publisher_and_latest_confirmed_caption'}
 if not role:binding['publisher_hotel_id']=hid+1
 inp={'ids':z,'map':m,'data':d,'binding':binding,'reader':str(b/'read-hotel-search-links-current-contexts-20261105-data-bound.php'),'updater':str(b/'update-hotel-search-links-explicit-noquote-20261105-data-bound.php')}
 p=subprocess.run(['php',str(php)],input=json.dumps(inp),text=True,capture_output=True,timeout=5)
 check(p.returncode==0,'native_execution:'+label);result=json.loads(p.stdout);check(result==expected,'native_contract:'+label);native+=1
for n in [1,3,10]:
 z,m=fixture(n);native_case(z,m,partition(z),{'partition':True,'map':True,'role':True},'valid'+str(n))
for n in [0,11]:
 z,m=fixture(n);native_case(z,m,partition(z),{'partition':False,'map':False,'role':False},'invalidcount'+str(n))
z,m=fixture(3);d=partition(z);d['deferred_before_server']=[{'native_hotel_id':z[-1]}];d['items']=d['items'][:-1]
native_case(z,m,d,{'partition':True,'map':True,'role':True},'exact_deferred_partition')
bad=copy.deepcopy(d);bad['deferred_before_server'][0]['native_hotel_id']=999999999
native_case(z,m,bad,{'partition':False,'map':True,'role':True},'foreign_deferred')
bad=copy.deepcopy(d);bad['deferred_before_server'][0]['native_hotel_id']=z[0]
native_case(z,m,bad,{'partition':False,'map':True,'role':True},'duplicate_deferred')
bad=copy.deepcopy(m);bad[-1]=bad[0]
native_case(z,bad,partition(z),{'partition':True,'map':False,'role':False},'duplicate_map')
native_case(z,m,partition(z),{'partition':True,'map':True,'role':False},'wrong_publisher',False)
for filename in ['prepare-hotel-search-links-current-contexts-20261105-data-bound.py','prepare-hotel-search-links-explicit-noquote-20261105-data-bound.py']:
 tree=ast.parse((b/filename).read_text());selected=[x for x in tree.body if isinstance(x,ast.FunctionDef) and x.name in ['link_scope_ids_valid','link_context_partition_valid']]
 check(len(selected)==2,'python_actual_functions_present');env={};exec(compile(ast.Module(body=selected,type_ignores=[]),filename,'exec'),env)
 scope_ok=env['link_scope_ids_valid'];part_ok=env['link_context_partition_valid']
 for n in [1,3,10]:
  z,m=fixture(n);check(scope_ok(z,m,10,n),'python_valid'+str(n));check(part_ok(z,[{'hotel':x} for x in m],[]),'python_partition'+str(n));python+=2
 for n in [0,11]:
  z,m=fixture(n);check(not scope_ok(z,m,10,n),'python_invalidcount'+str(n));python+=1
 z,m=fixture(3);check(not scope_ok(z+[z[0]],m+[m[0]],10,4),'python_duplicate');check(not scope_ok(z,list(reversed(m)),10,3),'python_foreign_map_order');check(not scope_ok(z,m,10,10),'python_exact_read_bound');python+=3
 check(part_ok(z,[{'hotel':x} for x in m[:-1]],[{'native_hotel_id':z[-1]}]),'python_deferred')
 check(not part_ok(z,[{'hotel':x} for x in m[:-1]],[{'native_hotel_id':z[0]}]),'python_duplicate_deferred')
 check(not part_ok(z,[{'hotel':x} for x in m[:-1]],[{'native_hotel_id':999999999}]),'python_foreign_deferred');python+=3
print(json.dumps({'operation':plan['operation'],'state':'NEW_BOUNDED1TO10_ACTUAL_NATIVE_FUNCTION_CONTRACTS_PASSED','checks':checks,'new_native_cases':native,'new_python_cases':python,'native_scope_ids':ids,'old_fixtures':0,'ssh_calls':0,'db_calls':0,'provider_calls':0,'source_searches':0,'quotes':0,'edits':0}))
