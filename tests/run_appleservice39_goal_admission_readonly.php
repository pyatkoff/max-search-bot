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
$observe = static function(array $first,array $second,string $expectedReason,string $expectedStatus,int $expectedCalls=2)
    use ($settings,$check): array {
    $calls=[];
    $response=as39GoalAuditProbe($settings,'PRIVATE-CANARY-TOKEN',
        static function(string $method,string $url,$payload) use (&$calls,$first,$second): array {
            if ($method!=='GET'||$payload!==null) throw new RuntimeException('mutation_attempt');
            $calls[]=$url;
            return count($calls)===1?$first:$second;
        });
    $check($response['reason']===$expectedReason,$expectedReason.' classification');
    $check($response['native_admission']['status']===$expectedStatus,$expectedReason.' canonical native result');
    $check(count($calls)===$expectedCalls && $response['http_attempts']===$expectedCalls,$expectedReason.' bounded requests');
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
$observe($okCounter,$okGoals,'ready','ready');
$observe($wire(['errors'=>[['message'=>'PRIVATE-CANARY']]],403),$okGoals,'counter_http_not_200','goal_access_unavailable',1);
$observe($wire([],0,60),$okGoals,'counter_transport_failed','goal_access_unavailable',1);
$observe(['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY-NONJSON'],$okGoals,'counter_payload_invalid','goal_access_unavailable',1);
$observe($wire(['counter'=>['id'=>7,'permission'=>'own']]),$okGoals,'counter_identity_mismatch','goal_access_unavailable',1);
$observe($wire(['counter'=>['id'=>42611469,'permission'=>'view']]),$okGoals,'counter_permission_not_own_or_edit','goal_access_unavailable',1);
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
$check($transportFailure['reason']==='bounded_transport_exception'&&$transportFailure['http_attempts']===1,'transport exception stays bounded');
$check(!str_contains(json_encode($transportFailure,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),'transport exception text is never exported');
echo json_encode(['ok'=>true,'checks'=>$passed,'network_requests'=>0,'production_writes'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
