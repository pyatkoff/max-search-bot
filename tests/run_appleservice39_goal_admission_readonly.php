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
$fixtureLogin='fixture-account';
$fixtureDigest=hash('sha256',$fixtureLogin);
$fixturePair=sodium_crypto_box_keypair();
$fixturePublicHex=bin2hex(sodium_crypto_box_publickey($fixturePair));
$identityWire=['http'=>200,'error'=>0,'ssl_verify_result'=>0,'body'=>json_encode([
    'login'=>$fixtureLogin,'id'=>'PRIVATE-CANARY-ID','client_id'=>'PRIVATE-CANARY-APP',
    'emails'=>['PRIVATE-CANARY-EMAIL'],'real_name'=>'PRIVATE-CANARY-NAME'],JSON_THROW_ON_ERROR)];
$identityUrl='https://login.yandex.ru/info?format=json';
$passed = 0;
$check = static function(bool $ok,string $name) use (&$passed): void {
    if (!$ok) throw new RuntimeException('FAILED '.$name);
    $passed++;
};
$observe = static function(array $first,array $second,string $expectedReason,string $expectedStatus,int $expectedNativeCalls=2,?array $collection=null)
    use ($settings,$check,$fixtureDigest,$fixturePublicHex,$identityWire,$identityUrl,$fixtureLogin): array {
    $calls=[];
    $response=as39GoalAuditProbe($settings,'PRIVATE-CANARY-TOKEN',
        static function(string $method,string $url,$payload) use (&$calls,$first,$second,$collection,$identityWire,$identityUrl): array {
            if ($method!=='GET'||$payload!==null) throw new RuntimeException('mutation_attempt');
            $calls[]=$url;
            if ($url===$identityUrl) return $identityWire;
            if ($url==='https://api-metrika.yandex.net/management/v1/counter/42611469') return $first;
            if ($url==='https://api-metrika.yandex.net/management/v1/counter/42611469/goals') return $second;
            if ($url==='https://api-metrika.yandex.net/management/v1/counters?counter_ids=42611469&permission=own%2Cedit&offset=1&per_page=1') {
                if ($collection===null) throw new RuntimeException('unexpected_collection');
                return $collection;
            }
            throw new RuntimeException('unexpected_url');
        },$fixtureDigest,$fixturePublicHex);
    $check($response['reason']===$expectedReason,$expectedReason.' classification');
    $check($response['native_admission']===['ok'=>$expectedStatus==='ready','status'=>$expectedStatus,'conversion_requests'=>0],$expectedReason.' canonical native result');
    $expectedNativeUrls=['https://api-metrika.yandex.net/management/v1/counter/42611469'];
    if ($collection!==null) $expectedNativeUrls[]='https://api-metrika.yandex.net/management/v1/counters?counter_ids=42611469&permission=own%2Cedit&offset=1&per_page=1';
    if ($expectedNativeCalls>count($expectedNativeUrls)) $expectedNativeUrls[]='https://api-metrika.yandex.net/management/v1/counter/42611469/goals';
    $expectedUrls=[$identityUrl,...$expectedNativeUrls];
    $check($calls===$expectedUrls && $response['http_attempts']===count($expectedUrls)
        && $response['native_http_attempts']===$expectedNativeCalls,$expectedReason.' bounded requests');
    $hasGoals=in_array('https://api-metrika.yandex.net/management/v1/counter/42611469/goals',$expectedNativeUrls,true);
    $check($response['diagnostic_version']===5 && $response['purpose']==='account_identity_and_admission'
        && $response['goal_read_source']===($hasGoals?'native_admission':'not_requested')
        && $response['native_probe_calls']===1 && count($response['observations'])===$expectedNativeCalls,
        $expectedReason.' native-only observation source');
    $check($response['identity']['login_matches_expected']===true && $response['identity']['mismatch_login_sealed']===null,
        $expectedReason.' identity is confirmed without publishing the login');
    $check($response['collection_read_source']===($collection!==null?'native_admission':'not_requested')
        &&$response['native_transport_stop_stage']===null,$expectedReason.' collection source and native transport stage');
    $check($response['conversion_requests']===0 && $response['database_writes']===0
        && $response['customer_messages']===0 && $response['provider_mutations']===0,$expectedReason.' zero writes');
    $json=json_encode($response,JSON_THROW_ON_ERROR);
    $check(!str_contains($json,'PRIVATE-CANARY') && !str_contains($json,$fixtureLogin) && !str_contains($json,'Authorization')
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
    $check($canAdmit ? ($r['observations'][1]['selected_goal_count']===1&&$r['observations'][1]['selected_identifier_matches']===true)
        : (count($r['observations'])===1&&$r['goal_read_source']==='not_requested'),
        'permission rejection does not start another goal request: '.$permission);
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
    $check(count($r['observations'])===2&&$r['goal_read_source']==='not_requested',
        'rejected collection stops before goals: '.$name);
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

$identityStop=static function($reply,string $reason,?bool $match=null) use ($settings,$check,$fixtureDigest,$fixturePublicHex,$identityUrl): array {
    $calls=[];
    $r=as39GoalAuditProbe($settings,'PRIVATE-CANARY-TOKEN',static function($method,$url,$payload) use (&$calls,$reply,$identityUrl) {
        $calls[]=[$method,$url,$payload];
        if($method!=='GET'||$url!==$identityUrl||$payload!==null)throw new RuntimeException('unexpected_native_call');
        if($reply==='throw')throw new RuntimeException('PRIVATE-CANARY-EXCEPTION');
        return $reply;
    },$fixtureDigest,$fixturePublicHex);
    $check($calls===[['GET',$identityUrl,null]]&&$r['http_attempts']===1&&$r['native_http_attempts']===0,
        $reason.' one identity request only');
    $check($r['reason']===$reason&&$r['native_probe_calls']===0&&$r['native_admission']===null
        &&$r['observations']===[]&&$r['goal_read_source']==='not_requested'&&$r['collection_read_source']==='not_requested',
        $reason.' blocks canonical probe without invented outcome');
    $check(($r['identity']['login_matches_expected']??null)===$match,$reason.' explicit tri-state match');
    $check($r['database_writes']===0&&$r['provider_mutations']===0&&$r['conversion_requests']===0
        &&$r['customer_messages']===0,$reason.' zero writes');
    $json=json_encode($r,JSON_THROW_ON_ERROR);
    $check(!str_contains($json,'PRIVATE-CANARY')&&!str_contains($json,'Authorization')
        &&!str_contains($json,'"body"')&&!str_contains($json,'"login"')&&!str_contains($json,'"client_id"')
        &&!str_contains($json,'"emails"'),$reason.' no raw identity or secret');
    if($match!==false)$check(($r['identity']['mismatch_login_sealed']??null)===null,$reason.' no unnecessary encrypted identity');
    return $r;
};
$identityStop('throw','identity_transport_exception');
foreach([null,7,['http'=>200,'error'=>0],['body'=>['PRIVATE-CANARY']],
    ['body'=>str_repeat('X',1048577)]] as $bad)$identityStop($bad,'identity_response_invalid');
foreach([null,[],true,7,'PRIVATE-CANARY'] as $bad)$identityStop($wire($bad),'identity_payload_invalid');
$identityStop(['http'=>200,'error'=>0,'ssl_verify_result'=>0,'body'=>'PRIVATE-CANARY-NONJSON'],'identity_payload_invalid');
foreach([(object)[],['login'=>null],['login'=>7],['login'=>false],['login'=>[]],['login'=>(object)[]],
    ['login'=>''],['login'=>' '],['login'=>"fixture-account\n"],['login'=>str_repeat('x',129)]] as $bad)
    $identityStop($wire($bad),'identity_login_invalid');
$identityStop($wire(['login'=>'fixture-account','message'=>'PRIVATE-CANARY'],401),'identity_http_not_200');
$identityStop($wire(['login'=>'fixture-account'],200,60),'identity_transport_failed');
foreach([null,42,'0'] as $ssl){$bad=$identityWire;$bad['ssl_verify_result']=$ssl;$identityStop($bad,'identity_tls_unconfirmed');}
$bad=$identityWire;unset($bad['ssl_verify_result']);$identityStop($bad,'identity_tls_unconfirmed');
$bad=$identityWire;$bad['http']='200';$identityStop($bad,'identity_http_not_200');
$bad=$identityWire;$bad['error']='0';$identityStop($bad,'identity_transport_failed');
$mismatch=$identityStop($wire(['login'=>'fixture-other','id'=>'PRIVATE-CANARY-ID']), 'identity_account_mismatch',false);
$sealed=$mismatch['identity']['mismatch_login_sealed'];
$decoded=is_string($sealed)?base64_decode($sealed,true):false;
$check(is_string($sealed)&&strlen($sealed)<=384&&is_string($decoded)
    &&sodium_crypto_box_seal_open($decoded,$fixturePair)==='fixture-other','mismatch contains only decryptable bounded sealed login');
$check(!str_contains(json_encode($mismatch,JSON_THROW_ON_ERROR),'fixture-other'),'mismatch login is never plaintext');
$otherPair=sodium_crypto_box_keypair();
$check(sodium_crypto_box_seal_open($decoded,$otherPair)===false,'different private key cannot decrypt mismatch');
$identityStop($wire(['login'=>'FIXTURE-ACCOUNT']), 'identity_account_mismatch',false);
$longMismatch=$identityStop($wire(['login'=>str_repeat('x',128)]),'identity_account_mismatch',false);
$check(strlen($longMismatch['identity']['mismatch_login_sealed'])<=384,'largest admitted login is bounded after encryption');

// The CLI default remains the fixed operator-supplied digest, not the test fixture.
$defaultCalls=0;
$default=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function()use(&$defaultCalls,$identityWire){$defaultCalls++;return $identityWire;});
$check($defaultCalls===1&&$default['reason']==='identity_account_mismatch'&&$default['native_probe_calls']===0,
    'default identity binding cannot be replaced by fixture configuration');
foreach(['',str_repeat('a',63),str_repeat('z',64)] as $badDigest){
    $called=0;$refused=false;
    try{as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function()use(&$called){$called++;return [];},$badDigest,$fixturePublicHex);}
    catch(RuntimeException $e){$refused=$e->getMessage()==='expected_identity_invalid';}
    $check($refused&&$called===0,'invalid expected digest fails before network');
}
foreach(['',str_repeat('a',63),str_repeat('z',64)] as $badKey){
    $called=0;$refused=false;
    try{as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function()use(&$called){$called++;return [];},$fixtureDigest,$badKey);}
    catch(RuntimeException $e){$refused=$e->getMessage()==='identity_sealer_unavailable';}
    $check($refused&&$called===0,'invalid sealing key fails before network');
}
$badSealCalls=0;
$badSeal=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function()use(&$badSealCalls,$wire){
    $badSealCalls++;return $wire(['login'=>'fixture-other']);
},$fixtureDigest,str_repeat('0',64));
$check($badSealCalls===1&&$badSeal['reason']==='identity_sealing_failed'&&$badSeal['native_probe_calls']===0
    &&$badSeal['identity']===null&&!str_contains(json_encode($badSeal,JSON_THROW_ON_ERROR),'fixture-other'),
    'sealing failure cannot emit plaintext or trigger native probe');

foreach(['counter','collection','goals'] as $stage){
    foreach(['exception','oversize','non-map','non-string-body'] as $failure){
        $calls=[];
        $r=as39GoalAuditProbe($settings,'PRIVATE-CANARY',static function($method,$url,$payload)
            use(&$calls,$stage,$failure,$identityUrl,$identityWire,$okCounter,$missingCounter){
            $calls[]=$url;
            if($url===$identityUrl)return $identityWire;
            if($url==='https://api-metrika.yandex.net/management/v1/counter/42611469'&&$stage!=='counter')
                return $stage==='collection'?$missingCounter:$okCounter;
            if($failure==='exception')throw new RuntimeException('PRIVATE-CANARY');
            if($failure==='non-map')return null;
            return ['http'=>200,'error'=>0,'ssl_verify_result'=>0,
                'body'=>$failure==='oversize'?str_repeat('X',1048577):['PRIVATE-CANARY']];
        },$fixtureDigest,$fixturePublicHex);
        $nativeCalls=$stage==='counter'?1:2;
        $check($r['native_probe_calls']===1&&$r['identity']['login_matches_expected']===true
            &&$r['native_admission']===['ok'=>false,'status'=>'goal_access_unavailable','conversion_requests'=>0]
            &&$r['reason']===($failure==='exception'?'bounded_transport_exception':'bounded_response_invalid')
            &&$r['native_transport_stop_stage']===$stage&&$r['native_http_attempts']===$nativeCalls,
            'native failure stays authoritative after matching identity: '.$stage.'/'.$failure);
        $check($r['http_attempts']===$nativeCalls+1&&count($calls)===$nativeCalls+1&&count(array_unique($calls))===count($calls)
            &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'PRIVATE-CANARY'),
            'native failure has no retry or independent follow-up: '.$stage.'/'.$failure);
    }
}
foreach(['project_key','bot_key','counter_id','credential_source','goals','goal_ids','goal_match'] as $field){
    $changed=$settings;
    if(in_array($field,['project_key','bot_key'],true))$changed[$field]='changed';
    else $changed['metrika'][$field]='changed';
    $called=0;$refused=false;
    try{as39GoalAuditProbe($changed,'PRIVATE-CANARY',static function()use(&$called){$called++;return [];},$fixtureDigest,$fixturePublicHex);}
    catch(RuntimeException $e){$refused=$e->getMessage()==='policy_changed';}
    $check($refused&&$called===0,'changed '.$field.' is refused before identity or native requests');
}
echo json_encode(['ok'=>true,'checks'=>$passed,'network_requests'=>0,'production_writes'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
