<?php
declare(strict_types=1);
define('AS39_GOAL_AUDIT_LIBRARY_ONLY', true);
require_once dirname(__DIR__).'/tools/appleservice39_goal_admission_readonly.php';

$settings=['project_key'=>'appleservice39','bot_key'=>'id390521129062_bot','metrika'=>[
    'counter_id'=>'42611469','mode'=>'collect','credential_source'=>'platform',
    'goals'=>['predictive_value'=>'predict'],'goal_ids'=>['predictive_value'=>'680570232'],
    'goal_match'=>['predictive_value'=>'exact']]];
$passed=0;
$check=static function(bool $ok,string $name)use(&$passed):void{
    if(!$ok)throw new RuntimeException('FAILED '.$name);
    $passed++;
};
$wire=static fn($data,int $http=200,int $error=0):array=>['http'=>$http,'error'=>$error,'ssl_verify_result'=>0,
    'body'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)];
$counter=['counter'=>['id'=>42611469,'status'=>'Active','type'=>'simple','permission'=>'own',
    'name'=>'PRIVATE-CANARY','owner_login'=>'PRIVATE-CANARY']];
$grant=['grant'=>['perm'=>'view','user_login'=>'PRIVATE-CANARY','comment'=>'PRIVATE-CANARY']];
$expectedCalls=[['GET','https://api-metrika.yandex.net/management/v1/counter/42611469',null],
    ['GET','https://api-metrika.yandex.net/management/v1/counter/42611469/my_grant',null]];
$read=static function(array $first,array $second)use($settings,$check,$expectedCalls):array{
    $calls=[];
    $result=as39GoalAuditAccessRead($settings,static function($method,$url,$payload)use(&$calls,$first,$second):array{
        $calls[]=[$method,$url,$payload];return count($calls)===1?$first:$second;
    });
    $check($calls===$expectedCalls&&$result['http_attempts']===2,'exact two GETs, ordered, no payload, no repeats');
    $check($result['ok']===true&&$result['purpose']==='access_detail_only'&&$result['diagnostic_version']===4
        &&$result['scope']==='appleservice39_goal_admission_readonly'&&$result['mode']==='collect'
        &&$result['native_probe_calls']===0&&!array_key_exists('native_admission',$result),'diagnostic purpose cannot claim native admission');
    $check(array_column($result['observations'],'stage')===['counter','my_grant'],'both fixed observations are identified');
    foreach(['provider_mutations','database_writes','conversion_requests','customer_messages']as$field)
        $check($result[$field]===0,'no '.$field);
    $safe=json_encode($result,JSON_THROW_ON_ERROR);
    $check(!str_contains($safe,'PRIVATE-CANARY')&&!str_contains($safe,'"body"')
        &&!str_contains($safe,'"user_login"')&&!str_contains($safe,'"owner_login"')
        &&!str_contains($safe,'"message"')&&!str_contains($safe,'"location"'),'private values and identities are absent');
    return $result;
};
$r=$read($wire($counter),$wire($grant));
$check($r['observations'][0]['counter_id_matches']&&$r['observations'][0]['status']==='Active'
    &&$r['observations'][0]['counter_type']==='simple'&&$r['observations'][0]['permission']==='own','counter details preserve documented values');
$check($r['observations'][1]['grant_shape_ok']&&$r['observations'][1]['perm']==='view'
    &&$r['observations'][1]['user_login_present']===true,'grant exposes only role and login presence');
foreach(['Active','Deleted']as$status)foreach(['simple','partner']as$type){
    $data=$counter;$data['counter']['status']=$status;$data['counter']['type']=$type;
    $r=$read($wire($data),$wire($grant));
    $check($r['observations'][0]['status']===$status&&$r['observations'][0]['counter_type']===$type,'counter status/type are not inferred');
}
foreach(['own','edit','view','analyst','view_access_filter','analyst_access_filter']as$permission){
    $data=$counter;$data['counter']['permission']=$permission;
    $r=$read($wire($data),$wire($grant));
    $check($r['observations'][0]['permission']===$permission,'counter permission enum retained');
}
foreach(['public_stat','view','edit','analyst','analyst_access_filter']as$permission){
    $data=$grant;$data['grant']['perm']=$permission;$data['grant']['user_login']='';
    $r=$read($wire($counter),$wire($data));
    $check($r['observations'][1]['perm']===$permission&&$r['observations'][1]['perm_type']==='string'
        &&$r['observations'][1]['user_login_present']===true,'documented grant is observed, empty login is only present');
}
$r=$read($wire(['counter'=>['id'=>'42611469']]),$wire(['grant'=>(object)[]]));
$check($r['observations'][0]['counter_id_matches']&&$r['observations'][0]['status']==='missing'
    &&$r['observations'][0]['counter_type']==='missing'&&$r['observations'][0]['permission']==='missing'
    &&$r['observations'][1]['perm']==='missing'&&!$r['observations'][1]['user_login_present'],'absence remains explicit');
foreach(['PRIVATE-CANARY','own','view_access_filter']as$permission){
    $r=$read($wire($counter),$wire(['grant'=>['perm'=>$permission,'user_login'=>['PRIVATE-CANARY']]]));
    $check($r['observations'][1]['perm']==='unrecognized'&&$r['observations'][1]['user_login_present'],
        'undocumented grant values never imply owner access or expose a login');
}
foreach([null,7,true,['PRIVATE-CANARY']]as$value){
    $r=$read($wire(['counter'=>['id'=>42611469,'status'=>$value,'type'=>$value,'permission'=>$value]]),
        $wire(['grant'=>['perm'=>$value,'user_login'=>$value]]));
    $check($r['observations'][0]['status']==='non_string'&&$r['observations'][0]['counter_type']==='non_string'
        &&$r['observations'][0]['permission']==='non_string'&&$r['observations'][1]['perm']==='non_string'
        &&$r['observations'][1]['perm_type']===get_debug_type($value),'non-string enum values are typed without export');
}
$r=$read($wire(['counter'=>['id'=>7,'status'=>'PRIVATE-CANARY','type'=>'PRIVATE-CANARY','permission'=>'PRIVATE-CANARY']]),$wire(['grant'=>[]]));
$check(!$r['observations'][0]['counter_id_matches']&&$r['observations'][0]['status']==='unrecognized'
    &&$r['observations'][0]['counter_type']==='unrecognized'&&!$r['observations'][1]['grant_shape_ok'],'wrong identity and shape stay observable');
$r=$read(['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY'],['http'=>200,'error'=>0,'body'=>'PRIVATE-CANARY']);
$check(!$r['observations'][0]['json_valid']&&!$r['observations'][1]['json_valid'],'invalid JSON is explicit without raw payload');
$r=$read($wire($counter,200,60),$wire(['code'=>403,'errors'=>[['error_type'=>'access_denied','message'=>'PRIVATE-CANARY']]],403));
$check($r['observations'][0]['curl_errno']===60&&$r['observations'][1]['http']===403
    &&$r['observations'][1]['provider_error']['error_types']===['access_denied'],'HTTP and transport evidence do not become a grant');
foreach(['exception','non-map','non-string-body','oversize']as$failure)foreach([1,2]as$failedAttempt){
    $calls=[];
    $r=as39GoalAuditAccessRead($settings,static function($method,$url,$payload)use(&$calls,$failure,$failedAttempt,$wire,$counter,$grant){
        $calls[]=[$method,$url,$payload];$n=count($calls);
        if($n!==$failedAttempt)return $wire($n===1?$counter:$grant);
        if($failure==='exception')throw new RuntimeException('PRIVATE-CANARY');
        if($failure==='non-map')return null;
        return ['http'=>200,'error'=>0,'body'=>$failure==='oversize'?str_repeat('X',1048577):['PRIVATE-CANARY']];
    });
    $check($calls===$expectedCalls&&$r['http_attempts']===2&&$r['native_probe_calls']===0
        &&$r['observations'][$failedAttempt-1]['read_error']===($failure==='exception'?'bounded_transport_exception':'bounded_response_invalid')
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),'each transport failure is bounded and never repeated');
}
$badSettings=[];
foreach(['project_key'=>'another','bot_key'=>'another_bot']as$field=>$value){$bad=$settings;$bad[$field]=$value;$badSettings[]=$bad;}
foreach(['counter_id'=>'98615635','mode'=>'send','credential_source'=>'another','goals'=>['predictive_value'=>'another'],
    'goal_ids'=>['predictive_value'=>'7'],'goal_match'=>['predictive_value'=>'contain']]as$field=>$value){$bad=$settings;$bad['metrika'][$field]=$value;$badSettings[]=$bad;}
foreach($badSettings as$bad){
    $calls=0;$refused=false;
    try{as39GoalAuditAccessRead($bad,static function()use(&$calls){$calls++;return [];});}
    catch(RuntimeException $error){$refused=$error->getMessage()==='policy_changed';}
    $check($refused&&$calls===0,'policy drift is rejected before transport');
}
$check(!class_exists('ProjectFunnelConversions',false)&&!class_exists('MetrikaOfflineClient',false),'diagnostic reader never loads or calls native admission');
echo json_encode(['ok'=>true,'checks'=>$passed,'network_requests'=>0,'production_writes'=>0,'native_probe_calls'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
