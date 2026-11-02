<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/hotel_server_cycle.php';
require_once dirname(__DIR__).'/services/HotelServerAiProvider.php';
require_once dirname(__DIR__).'/services/HotelServerCronRuntime.php';
$n=0;
function haCheck(bool $ok,string $label):void{global $n;++$n;if(!$ok)throw new RuntimeException('HOTEL AI FAIL:'.$label);}
function haReject(callable $fn,string $label,?string $reason=null):void{
    try{$fn();}catch(Throwable $e){haCheck($reason===null||$reason===$e->getMessage(),$label.':'.$e->getMessage());return;}haCheck(false,$label);
}
function haCount(PDO $p,string $table):int{return (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();}
function haPolicy():array{
    return ['source_mode'=>'assigned_retained_excerpts','model'=>ManagerDraftClient::requestBody('',[])['model'],'profile_sha256'=>hash('sha256',HotelServerInputs::json(ManagerDraftClient::hotelRuntimeProfile())),'budget_attestation_sha256'=>hash('sha256','synthetic budget policy, not pricing'),
        'valid_until'=>1800086400,'max_source_age_seconds'=>86400,'max_scopes'=>1,'request_byte_ceiling'=>ManagerDraftClient::MAX_REQUEST_BYTES,
        'input_token_ceiling'=>50000,'reserved_micros_minimum'=>10000,'principals'=>['producer'=>'synthetic-producer-policy-v1','reviewer'=>'synthetic-review-policy-v1']];
}
function haViews():array{
    $excerpt='Synthetic hotel evidence: a pool is available.';$source=['url'=>'https://hotel.example.test/facts','excerpt'=>$excerpt,'sha256'=>hash('sha256',$excerpt),'captured_at'=>1799999900,'kind'=>'retained_excerpt'];
    $deps=array_fill_keys(['archives','corpus','criteria','instructions','map'],'Assigned synthetic criteria/context');
    $deps['archives']=HotelServerInputs::json(['schema'=>'hotel-assigned-source-slice-v1','sources'=>[$source]]);
    return [['scope'=>hsScope('new-0'),'dependencies'=>$deps]];
}
function haPlan():array{$p=hsPlan(1);$p['research_mode']='assigned_retained_excerpts';$p['models']=array_fill_keys(['producer','reviewer'],haPolicy()['model']);$p['limits']['tokens_per_call']=60000;$p['limits']['max_tokens']=120000;return $p;}
function haEnvelope(array $result,string $id='resp_synthetic_1'):array{
    return ['id'=>$id,'status'=>'completed','model'=>haPolicy()['model'],'service_tier'=>'default','usage'=>['input_tokens'=>100,'output_tokens'=>30,'total_tokens'=>130],
        'output'=>[['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>HotelServerInputs::json($result)]]]]];
}
function haMock(object $state):callable{
    return static function(string $role,string $instructions,array $payload,array $schema,callable $observer)use($state):array{
        ++$state->calls;$body=ManagerDraftClient::hotelRequestBody($role,$instructions,$payload,$schema);$state->bodies[]=$body;
        if(($state->mode??'')==='timeout')throw new RuntimeException('synthetic lost ACK');
        if($role==='producer'){
            $scope=$payload['scopes'][0];$source=$payload['assigned_materials'][0]['dependencies']['archives']['sources'][0];
            $candidate=['schema'=>'hotel-server-candidate-v1','records'=>[['scope'=>$scope,'answer'=>'A pool is available.','evidence'=>[['url'=>$source['url'],'quote'=>($state->mode??'')==='fabricate'?'UNSUPPORTED_QUOTE':'a pool is available']]]]];
            $result=['status'=>'answered','candidate'=>$candidate];
        }else{
            $result=['approved'=>true,'checks'=>($state->mode??'')==='partial'?[]:[['scope'=>$payload['scopes'][0],'approved'=>true,'reason'=>'Exact quoted source and assigned criteria checked']],
                'evidence'=>'Independent assigned-source review'];
        }
        if(($state->mode??'')==='insufficient')$result=['status'=>'insufficient_evidence','candidate'=>null];
        $envelope=haEnvelope($result,'resp_synthetic_'.$state->calls);
        if(($state->mode??'')==='refuse')$envelope['output'][0]['content']=[['type'=>'refusal','refusal'=>'synthetic refusal']];
        if(($state->mode??'')==='incomplete'){$envelope['status']='incomplete';$envelope['incomplete_details']=['reason'=>'max_output_tokens'];}
        return ManagerDraftClient::parseHotelResponse(HotelServerInputs::json($envelope),200,$observer);
    };
}
function haCycle(array &$f,object $state,?array $views=null,?array $policy=null):HotelServerAiProvider{
    $adapter=new HotelServerAiProvider($policy??haPolicy(),haMock($state),static fn()=>1800000000);
    $f['cycle']=new HotelServerCycle($f['p'],$f['lane'],$adapter,static fn()=>1800000000);
    $b=$f['cycle']->bootstrap('NEW',hsInputs(null,$views??haViews()),haPlan(),static fn()=>true);
    $f['cycle']->enable('NEW',$b['bootstrap_sha256'],static fn()=>true);return $adapter;
}
$legacy=ManagerDraftClient::requestBody('legacy',[]);
haCheck($legacy['text']['format']['name']==='manager_reply_draft'&&$legacy['max_output_tokens']===2200&&$legacy['store']===false,'legacy manager profile/schema unchanged');
foreach(['producer','reviewer'] as $role){
    $b=ManagerDraftClient::hotelRequestBody($role,'synthetic',[],HotelServerAiProvider::responseSchema($role));
    haCheck($b['model']===$legacy['model']&&$b['reasoning']===$legacy['reasoning']&&$b['service_tier']===$legacy['service_tier'],'reuse existing profile');
    haCheck($b['max_output_tokens']===2200&&$b['store']===false&&!isset($b['tools'],$b['previous_response_id']),'no web tools, session reuse or deadline expansion');
    haCheck($b['text']['format']['name']==='hotel_server_'.$role&&$b['text']['format']['strict']===true,'role-specific strict schema');
}
$meta=null;$bytes=HotelServerInputs::json(haEnvelope(['ok'=>true]));
$r=ManagerDraftClient::parseHotelResponse($bytes,200,static function($m)use(&$meta){$meta=$m;});
haCheck($r===['ok'=>true]&&$meta['response_id']==='resp_synthetic_1'&&$meta['usage']['total_tokens']===130,'completed invocation and measured token evidence');
foreach(['id','usage','model'] as $missing){$v=haEnvelope(['ok'=>true]);unset($v[$missing]);haCheck(isset(ManagerDraftClient::parseHotelResponse(HotelServerInputs::json($v),200)['_error']),'missing metadata rejected');}
$v=haEnvelope(['ok'=>true]);$v['status']='incomplete';
haCheck(isset(ManagerDraftClient::parseHotelResponse(HotelServerInputs::json($v),200)['_error']),'incomplete cannot become a hotel result');
$v=haEnvelope(['ok'=>true]);$v['usage']['total_tokens']=999;
haCheck(isset(ManagerDraftClient::parseHotelResponse(HotelServerInputs::json($v),200)['_error']),'inconsistent measured usage rejected');
haCheck(ManagerDraftClient::parseHotelResponse('PRIVATE_PROVIDER_BODY',500)===['_error'=>'provider_unavailable'],'provider body never leaks');
$policy=haPolicy();$policy['principals']['reviewer']=$policy['principals']['producer'];
haReject(fn()=>new HotelServerAiProvider($policy),'separate principal policies required');
$badProfile=haPolicy();$badProfile['profile_sha256']=str_repeat('a',64);
haReject(fn()=>new HotelServerAiProvider($badProfile),'existing reasoning/tier/output profile must match private policy','hotel_provider_profile_mismatch');
$state=(object)['calls'=>0,'bodies'=>[]];$expired=haPolicy();$expired['valid_until']=1799999999;
$adapter=new HotelServerAiProvider($expired,haMock($state),static fn()=>1800000000);
haCheck(!$adapter->ready('producer',haPolicy()['model'])&&$state->calls===0,'expiry readiness is local and makes no calls');
$policyA=haPolicy();$policyB=haPolicy();$policyB['max_source_age_seconds']=1000;
$aPolicy=new HotelServerAiProvider($policyA,haMock($state),static fn()=>1800000000);$bPolicy=new HotelServerAiProvider($policyB,haMock($state),static fn()=>1800000000);
haCheck($aPolicy->principal('producer')!==$bPolicy->principal('producer'),'policy revision is pinned in immutable attempt principal');

$f=hsFixture();try{
    $state=(object)['calls'=>0,'bodies'=>[]];$adapter=haCycle($f,$state);
    haCheck($f['cycle']->tick('NEW')['state']==='candidate'&&$state->calls===1,'concrete adapter produces an exact source-grounded candidate');
    $data=json_decode($state->bodies[0]['input'][1]['content'][0]['text'],true,64,JSON_THROW_ON_ERROR);
    haCheck(!isset($data['attempt_id'],$data['principal'],$data['limits'])&&!str_contains(HotelServerInputs::json($data),'synthetic original baseline bytes'),'no control/budget/raw retained bytes sent');
    haCheck($f['cycle']->tick('NEW')['state']==='reviewed'&&$state->calls===2,'separate concrete reviewer uses one fresh response');
    $result=$f['cycle']->tick('NEW');
    haCheck($result['new_accepted']===1&&$result['historical_accepted']===19&&$state->calls===2,'atomic acceptance with actual adapter contract, no extra sends');
    $rows=$f['p']->query('SELECT request_bytes,result_bytes FROM hotel_server_attempts ORDER BY role')->fetchAll(PDO::FETCH_ASSOC);
    $a=json_decode($rows[0]['result_bytes'],true);$b=json_decode($rows[1]['result_bytes'],true);
    haCheck($a['provider_response_id']!==$b['provider_response_id']&&$a['principal']!==$b['principal'],'distinct invocation IDs and reviewer policy identities');
    haCheck($a['usage']['micros_kind']==='full_reserved_ceiling_not_provider_invoice'&&$a['usage']['tokens']===130,'measured tokens and pessimistic money reservation are distinguished');
    $request=json_decode($rows[0]['request_bytes'],true);haReject(fn()=>$adapter->send($request),'adapter refuses repeated same attempt in process','hotel_attempt_already_submitted');
    haCheck($state->calls===2,'no transport call on repeated attempt');
}finally{hsCleanup($f);}
foreach(['fabricate','partial','insufficient','refuse','incomplete','timeout'] as $mode){
    $f=hsFixture();try{
        $state=(object)['calls'=>0,'bodies'=>[],'mode'=>$mode];haCycle($f,$state);
        if($mode==='partial')$f['cycle']->tick('NEW');
        $r=$f['cycle']->tick('NEW');haCheck($r['state']===($mode==='timeout'?'unknown':'hold'),'known bad output holds with evidence; lost ACK remains UNKNOWN');
        if($mode!=='timeout'){
            $row=$f['p']->query("SELECT result_bytes FROM hotel_server_attempts WHERE result_bytes IS NOT NULL ORDER BY role DESC LIMIT 1")->fetchColumn();$proof=json_decode($row,true);
            haCheck($proof['provider_outcome']==='rejected'&&isset($proof['provider_response_id']),'known rejected provider response ID retained for reconciliation');
            $f['cycle']->recordResult('NEW',$proof['attempt_id'],$proof);
            haCheck($f['cycle']->status('NEW')['new_accepted']===0,'identical known rejection readback earns no credit');
            $changed=$proof;$changed['provider_response_id']='resp_conflicting';
            haReject(fn()=>$f['cycle']->recordResult('NEW',$proof['attempt_id'],$changed),'rejected result is immutable');
        }
        $nCalls=$state->calls;$f['cycle']->tick('NEW');
        haCheck($state->calls===$nCalls&&$f['cycle']->status('NEW')['new_accepted']===0,'no retry or credit after invalid quote/partial review/lost ACK');
    }finally{hsCleanup($f);}
}
foreach(['missing','stale','hash','private_url','budget','money'] as $mode){
    $f=hsFixture();try{
        $views=haViews();$policy=haPolicy();
        $slice=json_decode($views[0]['dependencies']['archives'],true,64,JSON_THROW_ON_ERROR);
        if($mode==='missing')$views[0]['dependencies']['archives']='unmaterialized archive reference';
        if($mode==='stale'){$slice['sources'][0]['captured_at']=1700000000;$views[0]['dependencies']['archives']=HotelServerInputs::json($slice);}
        if($mode==='hash'){$slice['sources'][0]['sha256']=str_repeat('a',64);$views[0]['dependencies']['archives']=HotelServerInputs::json($slice);}
        if($mode==='private_url'){$slice['sources'][0]['url']='https://127.0.0.1/';$views[0]['dependencies']['archives']=HotelServerInputs::json($slice);}
        if($mode==='budget')$policy['request_byte_ceiling']=100;
        if($mode==='money')$policy['reserved_micros_minimum']=10001;
        $state=(object)['calls'=>0,'bodies'=>[]];haCycle($f,$state,$views,$policy);
        haReject(fn()=>$f['cycle']->tick('NEW'),'source or budget preflight holds before send');
        haCheck($state->calls===0&&haCount($f['p'],'hotel_server_attempts')===0,'local rejection precedes UNKNOWN/attempt/provider');
    }finally{hsCleanup($f);}
}

$f=hsFixture();try{
    $state=(object)['calls'=>0,'bodies'=>[]];$adapter=new HotelServerAiProvider(haPolicy(),haMock($state),static fn()=>1800000000);
    $f['cycle']=new HotelServerCycle($f['p'],$f['lane'],$adapter,static fn()=>1800000000);
    $plan=haPlan();unset($plan['research_mode']);
    $b=$f['cycle']->bootstrap('NEW',hsInputs(null,haViews()),$plan,static fn()=>true);
    $f['cycle']->enable('NEW',$b['bootstrap_sha256'],static fn()=>true);
    haReject(fn()=>$f['cycle']->tick('NEW'),'no silent fresh-web substitution','hotel_fresh_source_method_unavailable');
    haCheck($state->calls===0&&haCount($f['p'],'hotel_server_attempts')===0,'unsupported research mode refuses before provider or persistence');
}finally{hsCleanup($f);}

$f=hsFixture();try{
    $state=(object)['calls'=>0,'bodies'=>[]];$adapter=haCycle($f,$state);
    $pin=$f['cycle']->status('NEW')['bootstrap_sha256'];
    $flags=['enabled'=>true,'dispatch_allowed'=>true,'replacement_dispatch_allowed'=>true];
    $context=['pdo'=>$f['p'],'provider'=>$adapter,'clock'=>static fn()=>1800000000,
        'expected_campaign_id'=>'NEW','expected_bootstrap_sha256'=>$pin];
    foreach(['foreign_campaign','foreign_bootstrap','missing_bootstrap','missing_campaign'] as $mode){
        $bad=$context;
        if($mode==='foreign_campaign')$bad['expected_campaign_id']='ANOTHER';
        if($mode==='foreign_bootstrap')$bad['expected_bootstrap_sha256']=str_repeat('0',64);
        if($mode==='missing_bootstrap')unset($bad['expected_bootstrap_sha256']);
        if($mode==='missing_campaign')unset($bad['expected_campaign_id']);
        $r=HotelServerCronRuntime::tick($flags,static fn()=>$bad);
        haCheck($r['handled']&&$r['state']==='hold'&&$r['reason']==='server_campaign_pin_mismatch',
            'private runtime '.$mode.' cannot select another campaign');
        haCheck($state->calls===0&&haCount($f['p'],'hotel_server_attempts')===0,'campaign pin check precedes persistence and provider');
    }
    $r=HotelServerCronRuntime::tick($flags,static fn()=>$context);
    haCheck($r['state']==='candidate'&&$state->calls===1&&haCount($f['p'],'hotel_server_attempts')===1,
        'exact private runtime binding reaches the same existing single-send cycle');
}finally{hsCleanup($f);}

echo 'HOTEL SERVER AI ADAPTER '.(getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'NATIVE MYSQL':'SQLITE').': '.$n." PASS (synthetic transport only; real provider calls0)\n";

