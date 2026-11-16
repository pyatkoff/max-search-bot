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
$observe = static function(array $first,array $second,string $expectedReason,string $expectedStatus,int $expectedNativeCalls=2)
    use ($settings,$check): array {
    $calls=[];
    $response=as39GoalAuditProbe($settings,'PRIVATE-CANARY-TOKEN',
        static function(string $method,string $url,$payload) use (&$calls,$first,$second): array {
            if ($method!=='GET'||$payload!==null) throw new RuntimeException('mutation_attempt');
            $calls[]=$url;
            return count($calls)===1?$first:$second;
        });
    $check($response['reason']===$expectedReason,$expectedReason.' classification');
    $check($response['native_admission']===['ok'=>$expectedStatus==='ready','status'=>$expectedStatus,'conversion_requests'=>0],$expectedReason.' canonical native result');
    $check($calls===['https://api-metrika.yandex.net/management/v1/counter/42611469','https://api-metrika.yandex.net/management/v1/counter/42611469/goals']
        && $response['http_attempts']===2 && $response['native_http_attempts']===$expectedNativeCalls,$expectedReason.' bounded requests');
    $check($response['diagnostic_version']===2 && $response['goal_read_source']===($expectedNativeCalls===1?'independent_diagnostic':'native_admission')
        && $response['goal_read_error']===null,$expectedReason.' explicit observation source');
    $check($response['conversion_requests']===0 && $response['database_writes']===0
        && $response['customer_messages']===0 && $response['provider_mutations']===0,$expectedReason.' zero writes');
    $json=json_encode($response,JSON_THROW_ON_ERROR);
    $check(!str_contains($json,'PRIVATE-CANARY') && !str_contains($json,'Authorization')
        && !str_contains($json,'"body"') && !str_contains($json,'"url"'),$expectedReason.' no raw payload or credential');
    return $response;
};
$wire = static fn($body,int $http=200,int $error=0): array =>
    ['http'=>$http,'error'=>$error,'ssl_verify_result'=>0,'body'=>json_encode($body,JSON_THROW_ON_ERROR)];
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
$missing=$observe($wire(['counter'=>['id'=>42611469]]),$okGoals,'counter_permission_missing','goal_access_unavailable',1);
$check($missing['observations'][0]['permission_present']===false&&$missing['observations'][0]['permission_type']==='missing'
    &&$missing['observations'][0]['permission']==='missing','missing permission stays distinct');
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
echo json_encode(['ok'=>true,'checks'=>$passed,'network_requests'=>0,'production_writes'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
