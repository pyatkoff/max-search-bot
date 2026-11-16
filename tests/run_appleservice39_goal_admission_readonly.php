<?php
declare(strict_types=1);
define('AS39_GOAL_AUDIT_LIBRARY_ONLY', true);
require_once dirname(__DIR__).'/services/ProjectFunnelConversions.php';
require_once dirname(__DIR__).'/tools/appleservice39_goal_admission_readonly.php';

$settings = ['project_key'=>'appleservice39','bot_key'=>'id390521129062_bot',
    'metrika'=>['counter_id'=>'42611469','mode'=>'collect','credential_source'=>'platform',
        'goals'=>['predictive_value'=>'predict'],'goal_ids'=>['predictive_value'=>'680570232'],
        'goal_match'=>['predictive_value'=>'exact']]];
$counter = ['counter'=>['id'=>42611469,'permission'=>'own','name'=>'PRIVATE-CANARY']];
$goal = ['id'=>680570232,'type'=>'action','name'=>'PRIVATE-CANARY',
    'conditions'=>[['type'=>'exact','url'=>'predict']]];
$passed = 0;
$check = static function(bool $ok,string $name) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED '.$name);
    $passed++;
};
$observe = static function(array $first,array $second,string $expectedReason,string $expectedStatus,int $expectedNativeCalls=2,?array $collection=null)
    use ($settings,$check): array {
    $calls=[];
    $response=as39GoalAuditProbe($settings,'PRIVATE-CANARY-TOKEN',
        static function(string $method,string $url,$payload) use (&$calls,$first,$second,$collection): array {
            if ($method!=='GET'||$payload!==null) throw new RuntimeException('mutation_attempt');
            $calls[]=$url;
            if ($url==='https://api-metrika.yandex.net/management/v1/counter/42611469') return $first;
            if ($url==='https://api-metrika.yandex.net/management/v1/counter/42611469/goals') return $second;
            if ($url==='https://api-metrika.yandex.net/management/v1/counters?counter_ids=42611469&permission=own%2Cedit&offset=1&per_page=1') {
                if ($collection===null) throw new RuntimeException('unexpected_collection');
                return $collection;
            }
            throw new RuntimeException('unexpected_url');
        });
    $check($response['reason']===$expectedReason,$expectedReason.' classification');
    $check($response['native_admission']===['ok'=>$expectedStatus==='ready','status'=>$expectedStatus,'conversion_requests'=>0],$expectedReason.' canonical native result');
    $expectedUrls=['https://api-metrika.yandex.net/management/v1/counter/42611469'];
    if ($collection!==null) $expectedUrls[]='https://api-metrika.yandex.net/management/v1/counters?counter_ids=42611469&permission=own%2Cedit&offset=1&per_page=1';
    $expectedUrls[]='https://api-metrika.yandex.net/management/v1/counter/42611469/goals';
    $check($calls===$expectedUrls && $response['http_attempts']===count($expectedUrls)
        && $response['native_http_attempts']===$expectedNativeCalls,$expectedReason.' bounded requests');
    $check($response['diagnostic_version']===3 && $response['goal_read_source']===($expectedNativeCalls===count($expectedUrls)?'native_admission':'independent_diagnostic')
        && $response['goal_read_error']===null,$expectedReason.' explicit observation source');
    $check($response['collection_read_source']===($collection!==null?'native_admission':'not_requested')
        &&$response['native_transport_stop_stage']===null,$expectedReason.' collection source and native transport stage');
    $check($response['conversion_requests']===0 && $response['database_writes']===0
        && $response['customer_messages']===0 && $response['provider_mutations']===0,$expectedReason.' zero writes');
    $json=json_encode($response,JSON_THROW_ON_ERROR);
    $check(!str_contains($json,'PRIVATE-CANARY') && !str_contains($json,'Authorization')
        && !str_contains($json,'"body"') && !str_contains($json,'"url"'),$expectedReason.' no raw payload or credential');
    return $response;
};
$wire = static fn($body,int $http=200,int $error=0): array =>
    ['http'=>$http,'error'=>$error,'ssl_verify_result'=>0,'body'=>json_encode($body,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)];
$okCounter=$wire($counter); $okGoals=$wire(['goals'=>[$goal]]);
$ready=$observe($okCounter,$okGoals,'ready','ready');
$check($ready['observations'][0]['permission']==='own'&&$ready['observations'][0]['permission_present']===true
    &&$ready['observations'][0]['permission_type']==='string','own permission is observed exactly');
$observe($wire(['errors'=>[['message'=>'PRIVATE-CANARY']]],403),$okGoals,'counter_http_not_200','goal_access_unavailable',1);
$observe($wire([],0,60),$okGoals,'counter_transport_failed','goal_access_unavailable',1);
$observe(['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY-NONJSON'],$okGoals,'counter_payload_invalid','goal_access_unavailable',1);
$observe($wire(['counter'=>['id'=>7,'permission'=>'own']]),$okGoals,'counter_identity_mismatch','goal_access_unavailable',1);
$observe($wire(['counter'=>['id'=>42611469,'permission'=>'view']]),$okGoals,'counter_permission_not_own_or_edit','goal_access_unavailable',1);
foreach(['edit','analyst','view_access_filter','analyst_access_filter'] as $permission){
    $canAdmit=$permission==='edit';
    $r=$observe($wire(['counter'=>['id'=>42611469,'permission'=>$permission]]),$okGoals,
        $canAdmit?'ready':'counter_permission_not_own_or_edit',$canAdmit?'ready':'goal_access_unavailable',$canAdmit?2:1);
    $check($r['observations'][0]['permission']===$permission&&$r['observations'][0]['permission_present']===true
        &&$r['observations'][0]['permission_type']==='string','documented permission preserved: '.$permission);
    $check($r['observations'][1]['selected_goal_count']===1&&$r['observations'][1]['selected_identifier_matches']===true,
        'goals observed without widening permission: '.$permission);
}
$missingCounter=$wire(['counter'=>['id'=>42611469]]);
$missing=$observe($missingCounter,$okGoals,'collection_permission_missing','goal_access_unavailable',2,
    $wire(['rows'=>1,'counters'=>[['id'=>42611469]]]));
$check($missing['observations'][0]['permission_present']===false&&$missing['observations'][0]['permission_type']==='missing'
    &&$missing['observations'][0]['permission']==='missing','missing permission stays distinct');
$collection=['rows'=>1,'counters'=>[['id'=>42611469,'permission'=>'edit','name'=>'PRIVATE-CANARY']]];
foreach (['own','edit'] as $permission) {
    $data=$collection;$data['counters'][0]['permission']=$permission;
    if ($permission==='own') $data['counters'][0]['id']='42611469';
    $r=$observe($missingCounter,$okGoals,'ready','ready',3,$wire($data));
    $c=$r['observations'][1];
    $check($c['rows_present']&&$c['rows_type']==='int'&&$c['rows_count']===1
        &&$c['counters_type']==='array'&&$c['counters_is_list']&&$c['counters_count']===1
        &&$c['entry_shape_ok']&&$c['counter_id_matches']&&$c['permission']===$permission,
        'collection positive evidence is explicit and goals are not repeated: '.$permission);
}
$cases=[
    'top-level array'=>['collection_payload_invalid',[]],
    'missing rows'=>['collection_rows_missing',['counters'=>$collection['counters']]],
    'null rows'=>['collection_rows_invalid_type',['rows'=>null,'counters'=>$collection['counters']]],
    'string rows'=>['collection_rows_invalid_type',['rows'=>'1','counters'=>$collection['counters']]],
    'float rows'=>['collection_rows_invalid_type',['rows'=>1.0,'counters'=>$collection['counters']]],
    'empty filtered set'=>['collection_rows_not_one',['rows'=>0,'counters'=>[]]],
    'multiple filtered rows'=>['collection_rows_not_one',['rows'=>2,'counters'=>$collection['counters']]],
    'missing counters'=>['collection_counters_missing',['rows'=>1]],
    'null counters'=>['collection_counters_not_list',['rows'=>1,'counters'=>null]],
    'numeric-key JSON object'=>['collection_counters_not_list',['rows'=>1,'counters'=>(object)$collection['counters']]],
    'empty entries'=>['collection_counter_count_not_one',['rows'=>1,'counters'=>[]]],
    'duplicate entries'=>['collection_counter_count_not_one',['rows'=>1,'counters'=>[$collection['counters'][0],$collection['counters'][0]]]],
    'non-object entry'=>['collection_counter_payload_invalid',['rows'=>1,'counters'=>[null]]],
    'missing ID'=>['collection_counter_id_missing',['rows'=>1,'counters'=>[['permission'=>'edit']]]],
    'float ID'=>['collection_counter_id_invalid_type',['rows'=>1,'counters'=>[['id'=>42611469.0,'permission'=>'edit']]]],
    'wrong ID'=>['collection_counter_identity_mismatch',['rows'=>1,'counters'=>[['id'=>7,'permission'=>'edit']]]],
    'missing permission'=>['collection_permission_missing',['rows'=>1,'counters'=>[['id'=>42611469]]]],
];
foreach (['view','analyst','view_access_filter','analyst_access_filter'] as $permission)
    $cases['denied '.$permission]=['collection_permission_not_own_or_edit',['rows'=>1,'counters'=>[['id'=>42611469,'permission'=>$permission]]]];
foreach ([null,true,7,['PRIVATE-CANARY']] as $i=>$permission)
    $cases['non-string permission '.$i]=['collection_permission_invalid_type',['rows'=>1,'counters'=>[['id'=>42611469,'permission'=>$permission]]]];
$cases['unknown permission']=['collection_permission_unrecognized',['rows'=>1,'counters'=>[['id'=>42611469,'permission'=>'PRIVATE-CANARY']]]];
foreach ($cases as $name=>[$reason,$data]) {
    $r=$observe($missingCounter,$okGoals,$reason,'goal_access_unavailable',2,$wire($data));
    $check($r['observations'][2]['stage']==='goals'&&$r['observations'][2]['selected_goal_count']===1,
        'independent goals cannot authorize rejected collection: '.$name);
}
$errorWire=$wire(['code'=>403,'message'=>'PRIVATE-CANARY','errors'=>[
    ['error_type'=>'access_denied','message'=>'PRIVATE-CANARY','location'=>'PRIVATE-CANARY'],
    ['error_type'=>'PRIVATE-CANARY','message'=>'PRIVATE-CANARY']]],403);
$http=$observe($missingCounter,$okGoals,'collection_http_not_200','goal_access_unavailable',2,$errorWire);
$check($http['observations'][1]['provider_error']['code']===403
    &&$http['observations'][1]['provider_error']['error_types']===['access_denied','other'],
    'only documented error types and bounded HTTP code are exported');
$observe($missingCounter,$okGoals,'collection_transport_failed','goal_access_unavailable',2,$wire($collection,200,60));
$observe($missingCounter,$okGoals,'collection_payload_invalid','goal_access_unavailable',2,
    ['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY-NONJSON']);
foreach ([null,'403',403.0,999999] as $code) {
    $error=as39GoalAuditErrors(['code'=>$code,'errors'=>array_fill(0,9,['error_type'=>'PRIVATE-CANARY','message'=>'PRIVATE-CANARY'])]);
    $check($error['code']===null&&$error['error_count']===9&&$error['error_types']===['other']
        &&$error['error_types_truncated']&&!str_contains(json_encode($error,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),
        'provider error values are bounded and sanitized');
}
foreach([null,true,7,['PRIVATE-CANARY']] as $permission){
    $r=$observe($wire(['counter'=>['id'=>42611469,'permission'=>$permission]]),$okGoals,
        'counter_permission_invalid_type','goal_access_unavailable',1);
    $check($r['observations'][0]['permission_present']===true&&$r['observations'][0]['permission_type']===get_debug_type($permission)
        &&$r['observations'][0]['permission']==='non_string','non-string permission type is exported without value');
}
$unknown=$observe($wire(['counter'=>['id'=>42611469,'permission'=>'PRIVATE-CANARY']]),$okGoals,
    'counter_permission_unrecognized','goal_access_unavailable',1);
$check($unknown['observations'][0]['permission_present']===true&&$unknown['observations'][0]['permission_type']==='string'
    &&$unknown['observations'][0]['permission']==='unrecognized','unknown permission text is not exported');
$observe($okCounter,$wire(['errors'=>[['message'=>'PRIVATE-CANARY']]],503),'goals_http_not_200','goal_access_unavailable');
$observe($okCounter,$wire(['goals'=>[]]),'selected_goal_missing_or_duplicated','goal_access_unavailable');
$changed=$goal;$changed['type']='url';
$observe($okCounter,$wire(['goals'=>[$changed]]),'selected_goal_not_action','goal_access_unavailable');
$changed=$goal;$changed['conditions'][]=['type'=>'exact','url'=>'another'];
$observe($okCounter,$wire(['goals'=>[$changed]]),'selected_goal_not_one_condition','goal_access_unavailable');
$changed=$goal;$changed['conditions'][0]['url']='PRIVATE-CANARY';
$observe($okCounter,$wire(['goals'=>[$changed]]),'selected_goal_identifier_mismatch','goal_access_unavailable');
$changed=$goal;$changed['conditions'][0]['type']='contain';
$observe($okCounter,$wire(['goals'=>[$changed]]),'selected_goal_condition_not_exact','goal_condition_unsupported');
$duplicate=$goal;$duplicate['id']=8;
$observe($okCounter,$wire(['goals'=>[$goal,$duplicate]]),'other_goal_same_exact_identifier','goal_access_unavailable');
$regexp=$duplicate;$regexp['conditions']=[['type'=>'regexp','url'=>'PRIVATE-CANARY']];
$observe($okCounter,$wire(['goals'=>[$goal,$regexp]]),'other_goal_regexp_not_proven_disjoint','goal_access_unavailable');
$broad=$duplicate;$broad['conditions']=[['type'=>'contain','url'=>'pred']];
$observe($okCounter,$wire(['goals'=>[$goal,$broad]]),'other_goal_contains_identifier','goal_access_unavailable');
$changed=$settings;$changed['metrika']['mode']='send';$called=0;$refused=false;
try { as39GoalAuditProbe($changed,'PRIVATE-CANARY',static function() use (&$called){$called++;return [];}); }
catch(RuntimeException $error){$refused=$error->getMessage()==='policy_changed';}
$check($refused&&$called===0,'send mode is refused before any API call');
$transportFailure=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function(){throw new RuntimeException('PRIVATE-CANARY');});
$check($transportFailure['reason']==='bounded_transport_exception'&&$transportFailure['http_attempts']===2
    &&$transportFailure['native_http_attempts']===1&&$transportFailure['goal_read_error']==='bounded_transport_exception','transport exception stays bounded');
$check(!str_contains(json_encode($transportFailure,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),'transport exception text is never exported');
$calls=0;
$separateFailure=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function() use (&$calls,$wire):array{
    if(++$calls===1)return $wire(['counter'=>['id'=>42611469,'permission'=>'analyst']]);
    throw new RuntimeException('PRIVATE-CANARY');
});
$check($calls===2&&$separateFailure['native_admission']===['ok'=>false,'status'=>'goal_access_unavailable','conversion_requests'=>0]
    &&$separateFailure['reason']==='counter_permission_not_own_or_edit','separate goal failure cannot overwrite native admission or its reason');
$check($separateFailure['goal_read_source']==='independent_diagnostic'&&$separateFailure['goal_read_error']==='bounded_transport_exception'
    &&!str_contains(json_encode($separateFailure,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),'separate goal error stays sanitized and explicit');
foreach (['exception','oversize','non-map','non-string-body'] as $failure) {
    $calls=[];
    $r=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function($method,$url,$payload)use(&$calls,$missingCounter,$okGoals,$failure){
        $calls[]=$url;
        if (count($calls)===1) return $missingCounter;
        if (count($calls)===3) return $okGoals;
        if ($failure==='exception') throw new RuntimeException('PRIVATE-CANARY');
        if ($failure==='non-map') return null;
        return ['http'=>200,'error'=>0,'body'=>$failure==='oversize'?str_repeat('X',1048577):['PRIVATE-CANARY']];
    });
    $check($r['native_admission']===['ok'=>false,'status'=>'goal_access_unavailable','conversion_requests'=>0]
        &&$r['reason']===($failure==='exception'?'bounded_transport_exception':'bounded_response_invalid')
        &&$r['native_transport_stop_stage']==='collection'&&$r['native_http_attempts']===2
        &&$r['http_attempts']===3&&count(array_unique($calls))===3&&$r['goal_read_source']==='independent_diagnostic'
        &&$r['goal_read_error']===null&&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),
        'failed collection is never repeated and cannot be hidden by successful independent goals: '.$failure);
}
$calls=0;
$r=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function()use(&$calls,$missingCounter,$wire):array{
    if (++$calls===1) return $missingCounter;
    if ($calls===2) return $wire(['rows'=>1,'counters'=>[['id'=>42611469]]]);
    throw new RuntimeException('PRIVATE-CANARY');
});
$check($calls===3&&$r['reason']==='collection_permission_missing'&&$r['native_http_attempts']===2
    &&$r['native_transport_stop_stage']===null&&$r['goal_read_error']==='bounded_transport_exception',
    'independent goal failure preserves the exact native collection refusal');
echo json_encode(['ok'=>true,'checks'=>$passed,'network_requests'=>0,'production_writes'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
