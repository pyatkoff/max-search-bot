<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/hotel_server_cycle.php';

// Real processes contend on the shared HOTEL row before the campaign/attempt row.
if(($argv[1]??null)==='--race') {
    [$unused,$mode,$dsn,$go,$out]=array_pad($argv,5,'');$p=hsConnect($dsn);$provider=new HotelServerFixtureProvider($p);
    if(($argv[5]??'')==='hold')$provider->onSend=static function()use($out):void{
        file_put_contents($out.'.sending','1');$until=microtime(true)+12;
        while(!is_file($out.'.release')&&microtime(true)<$until)usleep(1000);
        if(!is_file($out.'.release'))throw new RuntimeException('race_send_release_timeout');
    };
    $cycle=new HotelServerCycle($p,new HotelServerLane($p),$provider,static fn():int=>1800000000);
    file_put_contents($out.'.ready','1');$until=microtime(true)+12;
    while(!is_file($go)&&microtime(true)<$until)usleep(1000);
    if(!is_file($go))exit(3);
    $r=$cycle->tick('NEW');file_put_contents($out,HotelServerInputs::json(['state'=>$r['state'],'calls'=>count($provider->requests)]));exit(0);
}
$hsChecks=0;
function hsCheck(bool $ok,string $label): void { global $hsChecks; ++$hsChecks; if(!$ok)throw new RuntimeException('HOTEL SERVER FAIL:'.$label); }
function hsReject(callable $fn,string $label,?string $message=null): void {
    try{$fn();}catch(Throwable $e){hsCheck($message===null||$e->getMessage()===$message,$label.':'.$e->getMessage());return;}hsCheck(false,$label);
}
function hsBoot(array $f,?array $plan=null): array { return $f['cycle']->bootstrap('NEW',hsInputs(),$plan??hsPlan(),static fn():bool=>true); }
function hsEnable(array $f): void { $s=$f['cycle']->status('NEW');$f['cycle']->enable('NEW',$s['bootstrap_sha256'],static fn():bool=>true); }
function hsCount(PDO $p,string $table): int { return (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }

$ret=hsLegacy();$pin=hash('sha256',$ret['receipt_bytes']);$reg=HotelServerInputs::registry($ret,$pin);
hsCheck(count($reg)===19&&$reg[0]['stage_index']===1&&$reg[18]['stage_index']===37,'only nineteen intake receipts; CENTRAL41 UNKNOWN excluded');
hsReject(fn()=>HotelServerInputs::registry($ret,str_repeat('a',64)),'retirement pin mismatch');
$bad=$ret;$bad['snapshot_bytes'].=' ';hsReject(fn()=>HotelServerInputs::registry($bad,$pin),'snapshot mismatch');
$map=array_map(static fn(array $r): array=>$r+['scopes'=>[hsScope('old-'.$r['stage_index'])]],$reg);
hsReject(fn()=>hsInputs(array_slice($map,0,18)),'partial mapping HOLD');
$badMap=$map;$badMap[0]['packet_sha256']=str_repeat('f',64);hsReject(fn()=>hsInputs($badMap),'mapping bound to actual accepted packet');
$badMap=$map;$badMap[0]['scopes']=[];hsReject(fn()=>hsInputs($badMap),'empty semantic mapping HOLD');
hsReject(fn()=>HotelServerInputs::key(['hotel'=>'only-hotel']),'full country hotel question source required');

$f=hsFixture();
try {
    $s=hsBoot($f);hsCheck($s['state']==='disabled'&&$s['new_accepted']===0&&$s['historical_accepted']===19,'import dormant; historical19 new0');
    hsCheck(hsCount($f['p'],'hotel_server_receipts')===0&&hsCount($f['p'],'hotel_server_records')===0,'import gives no acceptance/data credit');
    hsCheck($f['cycle']->tick('NEW')['state']==='disabled'&&count($f['provider']->requests)===0,'disabled tick no external sends');
    hsCheck(hsBoot($f)===$s,'same bootstrap readback idempotent');
    hsReject(fn()=>$f['cycle']->bootstrap(HotelServerInputs::OLD_QUEUE,hsInputs(),hsPlan(),static fn()=>true),'old identity rejected');
    hsReject(fn()=>$f['lane']->reserve('LEGACY-CONTENDER',static fn()=>true),'legacy admission sees busy shared lane');
    hsReject(fn()=>$f['cycle']->enable('NEW',$s['bootstrap_sha256'],static fn()=>false),'enable requires separate approval');
    $notReady=new HotelServerCycle($f['p'],$f['lane']);hsReject(fn()=>$notReady->enable('NEW',$s['bootstrap_sha256'],static fn()=>true),'unconfigured callback not ready');
    $f['provider']->samePrincipal=true;hsReject(fn()=>hsEnable($f),'review principal independent');$f['provider']->samePrincipal=false;
    hsEnable($f);hsCheck($f['cycle']->tick('NEW')['state']==='candidate','one producer attempt saves immutable candidate');
    hsCheck(hsCount($f['p'],'hotel_server_records')===0&&hsCount($f['p'],'hotel_server_receipts')===0,'producer cannot accept');
    hsReject(fn()=>$f['p']->exec("UPDATE hotel_server_candidates SET candidate_bytes='tampered'"),'candidate DB immutable');
    hsCheck($f['cycle']->tick('NEW')['state']==='reviewed','separate reviewer invocation');
    $requests=$f['provider']->requests;hsCheck($requests[0]['attempt_id']!==$requests[1]['attempt_id']&&$requests[0]['principal']!==$requests[1]['principal'],'fresh independent reviewer identity');
    $armed=true;$faultCycle=new HotelServerCycle($f['p'],$f['lane'],$f['provider'],null,static function(string $at)use(&$armed):void{if($armed&&$at==='accept_before_commit'){ $armed=false;throw new RuntimeException('synthetic_accept_crash'); }});
    hsReject(fn()=>$faultCycle->tick('NEW'),'atomic accept injected crash');
    hsCheck(hsCount($f['p'],'hotel_server_records')===0&&hsCount($f['p'],'hotel_server_receipts')===0&&hsCount($f['p'],'hotel_server_catalog_outbox')===0&&$f['cycle']->status('NEW')['new_accepted']===0,'rollback data receipt credit and outboxes');
    $accepted=$f['cycle']->tick('NEW');hsCheck($accepted['new_accepted']===1&&$accepted['stage_index']===1&&$accepted['state']==='ready','atomic accept advances exact baseline/counter');
    hsCheck(hsCount($f['p'],'hotel_server_records')===1&&hsCount($f['p'],'hotel_server_receipts')===1&&hsCount($f['p'],'hotel_server_catalog_outbox')===1,'data receipt and projection outbox committed together');
    hsCheck(hsCount($f['p'],'hotel_server_outbox')===3,'exactly one next producer outbox');
    $original=$f['provider']->results[0];$f['cycle']->recordResult('NEW',$original['attempt_id'],$original);hsCheck($f['cycle']->status('NEW')['new_accepted']===1,'duplicate result gives no new credit');
    $original['candidate']['records'][0]['answer']='changed';hsReject(fn()=>$f['cycle']->recordResult('NEW',$original['attempt_id'],$original),'immutable duplicate result conflict');
    $catalog=$f['dsn'];$other=hsConnect($catalog);$fault=true;
    $projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane'],static function()use(&$fault):void{if($fault){$fault=false;throw new RuntimeException('lost_projection_ACK');}});
    hsReject(fn()=>$projector->project('NEW',$accepted['receipt_sha256']),'projection commit lost ACK','lost_projection_ACK');
    hsCheck(hsCount($other,'hotel_server_catalog_documents')===1,'catalog receipt committed before lost ACK');
    $projector->project('NEW',$accepted['receipt_sha256']);hsCheck(hsCount($other,'hotel_server_catalog_documents')===1,'idempotent catalog projection duplicate');
    hsCheck($f['cycle']->status('NEW')['new_accepted']===1,'projection never grants acceptance credit');$projector=null;$other=null;
    for($i=0;$i<3;$i++)$end=$f['cycle']->tick('NEW');
    hsCheck($end['state']==='complete'&&$end['new_accepted']===2&&count($f['provider']->requests)===4,'bounded two-stage cycle completes with four sends');
    $f['cycle']->tick('NEW');hsCheck(count($f['provider']->requests)===4,'completed cycle never dispatches again');
$faultCycle=null;$notReady=null;
}finally{$faultCycle=null;$notReady=null;$projector=null;$other=null;hsCleanup($f);}

// Unknown send has no refund, replacement or automatic retry, even beyond expiry.
$f=hsFixture();try {
    hsBoot($f);hsEnable($f);$f['provider']->loseAck=true;$r=$f['cycle']->tick('NEW');hsCheck($r['state']==='unknown','lost provider ACK holds');
    $f['clock']->now=2100000000;for($i=0;$i<3;$i++)$f['cycle']->tick('NEW');
    hsCheck(count($f['provider']->requests)===1&&hsCount($f['p'],'hotel_server_attempts')===1,'no resend after deadline or repeated cron');
    $saved=$f['provider']->results[0];$forged=$saved;$forged['principal']='foreign';hsReject(fn()=>$f['cycle']->recordResult('NEW',$saved['attempt_id'],$forged),'own invocation required');
    hsCheck($f['cycle']->recordResult('NEW',$saved['attempt_id'],$saved)['state']==='candidate','same attempt reconciled without send');
    hsCheck($f['cycle']->tick('NEW')['state']==='hold'&&count($f['provider']->requests)===1,'expired budget blocks next role; no refund');
}finally{hsCleanup($f);}

// Reserves are rechecked after assignment, just before atomic acceptance.
$f=hsFixture();try {
    hsBoot($f);hsEnable($f);$f['cycle']->tick('NEW');$f['cycle']->tick('NEW');$f['cycle']->protect('NEW',hsScope('new-0'));
    hsReject(fn()=>$f['cycle']->tick('NEW'),'late reserve defeats accept');
    hsCheck(hsCount($f['p'],'hotel_server_records')===0&&$f['cycle']->status('NEW')['new_accepted']===0,'protected reserve no partial data or credit');
}finally{hsCleanup($f);}
$f=hsFixture();try {
    $plan=hsPlan();$plan['stages'][0]=[hsScope('old-1')];hsReject(fn()=>hsBoot($f,$plan),'accepted historical semantic key excluded at assignment');
    hsCheck(hsCount($f['p'],'hotel_server_attempts')===0,'excluded assignment no attempt');
}finally{hsCleanup($f);}

// Two real PHP processes: exactly one initial send despite concurrent cron ticks.
$f=hsFixture();$children=[];try {
    hsBoot($f);hsEnable($f);
    for($i=0;$i<2;$i++) {
        $cmd=[PHP_BINARY];if(php_ini_loaded_file())array_push($cmd,'-c',php_ini_loaded_file());
        array_push($cmd,__FILE__,'--race',$f['dsn'],$f['dir'].'/go'.$i,$f['dir'].'/out'.$i,$i===0?'hold':'normal');
        $pipes=[];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('race_process');fclose($pipes[0]);$children[]=[$process,$pipes];
    }
    $until=microtime(true)+12;while((!is_file($f['dir'].'/out0.ready')||!is_file($f['dir'].'/out1.ready'))&&microtime(true)<$until)usleep(1000);
    hsCheck(is_file($f['dir'].'/out0.ready')&&is_file($f['dir'].'/out1.ready'),'both cron processes ready');file_put_contents($f['dir'].'/go0','1');
    $until=microtime(true)+12;while(!is_file($f['dir'].'/out0.sending')&&microtime(true)<$until)usleep(1000);
    hsCheck(is_file($f['dir'].'/out0.sending'),'winner is outside SQL lock in external send');file_put_contents($f['dir'].'/go1','1');
    $until=microtime(true)+8;while(!is_file($f['dir'].'/out1')&&microtime(true)<$until)usleep(1000);
    hsCheck(is_file($f['dir'].'/out1'),'contender does not block on network');
    $loser=json_decode((string)file_get_contents($f['dir'].'/out1'),true,16,JSON_THROW_ON_ERROR);
    hsCheck($loser['state']==='unknown'&&$loser['calls']===0,'concurrent cron observes durable UNKNOWN and sends nothing');file_put_contents($f['dir'].'/out0.release','1');
    foreach($children as [$process,$pipes]) { $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);hsCheck(proc_close($process)===0&&$stdout===''&&$stderr==='','race child exits clean'); }
    $children=[];$calls=0;for($i=0;$i<2;$i++)$calls+=json_decode((string)file_get_contents($f['dir'].'/out'.$i),true,16,JSON_THROW_ON_ERROR)['calls'];
    hsCheck($calls===1&&hsCount($f['p'],'hotel_server_candidates')===1,'concurrent ticks have exactly one producer winner');
    $dupes=$f['p']->query('SELECT role,COUNT(*) n FROM hotel_server_attempts GROUP BY role HAVING COUNT(*)>1')->fetchAll();hsCheck($dupes===[],'SQL unique stage/role admission across actual processes');
}finally{foreach($children as [$p,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($p)){proc_terminate($p);proc_close($p);}}hsCleanup($f);}


// Source review regressions: executable quarantine and the current accepted view.
$ret=hsLegacy();$pin=hash('sha256',$ret['receipt_bytes']);$held=HotelServerInputs::quarantine($ret,$pin);
hsCheck(count($held)===1&&$held[0]['stage_index']===40,'unaccepted TR40 research is quarantined, not credited');
$p=hsInputs()->package();$bytes=$p['bytes'];$bytes['quarantine_mapping']='[]';
hsReject(fn()=>HotelServerInputs::validate($ret,$pin,$bytes),'missing TR40 mapping is HOLD');
$bytes=$p['bytes'];$badView=json_decode($bytes['accepted_context'],true);
$badView[]=['scope'=>hsScope('TR40-held'),'answer'=>'unaccepted injected source','packet_sha256'=>$held[0]['packet_sha256'],'receipt_sha256'=>$held[0]['receipt_sha256']];
$bytes['accepted_context']=HotelServerInputs::json($badView);
hsReject(fn()=>HotelServerInputs::validate($ret,$pin,$bytes),'unaccepted record cannot enter accepted projection');
$f=hsFixture();try{
    $plan=hsPlan();$plan['stages'][0]=[hsScope('TR40-held')];
    hsReject(fn()=>hsBoot($f,$plan),'TR40 cannot be assigned for repeated research');
    hsCheck(hsCount($f['p'],'hotel_server_attempts')===0,'TR40 rejection sends nothing');
}finally{hsCleanup($f);}
$f=hsFixture();try{
    $plan=hsPlan();$plan['stages'][1]=[$plan['stages'][0][0]];$plan['stages'][1][0]['question']='followup-question';
    hsBoot($f,$plan);hsEnable($f);
    for($i=0;$i<3;$i++)$f['cycle']->tick('NEW');
    $first=$f['cycle']->readAccepted('NEW');hsCheck(count($first['records'])===20,'full current accepted reader retains 19 plus one new record');
    $head=$f['cycle']->status('NEW')['baseline_sha256'];$f['cycle']->tick('NEW');$r=$f['provider']->requests[2];
    hsCheck($r['baseline_sha256']===$head&&$r['accepted_context']['baseline_sha256']===$head,'second producer reads its current pinned base');
    hsCheck(count($r['accepted_context']['records'])===1&&$r['accepted_context']['records'][0]['answer']==='Synthetic fixture answer','following producer sees first accepted fact for its hotel');
    hsCheck(!isset($r['input_bytes'])&&!str_contains(HotelServerInputs::json($r),'synthetic original baseline bytes'),'raw retained WORKING not sent to model');
    hsCheck(count($r['dependencies'])===1&&$r['dependencies'][0]['scope']===$plan['stages'][1][0],'only assigned dependency projection is sent');
    hsCheck(strlen(HotelServerInputs::json($r))<=262144,'assignment bounded before durable send');
}finally{hsCleanup($f);}
$f=hsFixture();try{
    $plan=hsPlan(1);$views=[];$scopes=[];
    for($i=0;$i<32;$i++){
        $scope=hsScope('large-'.$i);$scopes[]=$scope;
        $views[]=['scope'=>$scope,'dependencies'=>array_fill_keys(['archives','corpus','criteria','instructions','map'],str_repeat('x',8192))];
    }
    $plan['stages'][0]=$scopes;$f['cycle']->bootstrap('NEW',hsInputs(null,$views),$plan,static fn()=>true);hsEnable($f);
    hsReject(fn()=>$f['cycle']->tick('NEW'),'oversized projection holds before attempt');
    hsCheck(count($f['provider']->requests)===0&&hsCount($f['p'],'hotel_server_attempts')===0,'no send or reserved attempt on oversized request');
}finally{hsCleanup($f);}


require_once dirname(__DIR__).'/services/HotelServerCronRuntime.php';
$factoryCalls=0;$factory=static function()use(&$factoryCalls):array{++$factoryCalls;throw new RuntimeException('must_not_be_called');};
foreach([[],['enabled'=>true],['enabled'=>true,'dispatch_allowed'=>true],['enabled'=>true,'dispatch_allowed'=>true,'replacement_dispatch_allowed'=>'true'],['enabled'=>false,'dispatch_allowed'=>true,'replacement_dispatch_allowed'=>true],['enabled'=>true,'dispatch_allowed'=>null,'replacement_dispatch_allowed'=>true]] as $flags)
    hsCheck(HotelServerCronRuntime::tick($flags,$factory)===['handled'=>false,'state'=>'disabled','provider_calls'=>0],'strict missing/mismatched flags refuse before context');
hsCheck($factoryCalls===0,'disabled flags make zero factory/SQL/provider calls');
$flags=['enabled'=>true,'dispatch_allowed'=>true,'replacement_dispatch_allowed'=>true];
hsCheck(HotelServerCronRuntime::tick($flags,static fn()=>[])['state']==='hold','unconfigured provider holds without legacy fallback');
$f=hsFixture();try{
    hsBoot($f);hsEnable($f);
    // There is deliberately no hotel_work_events table/active legacy subscription.
    $runtime=HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$f['provider'],'clock'=>static fn()=>1800000000]);
    hsCheck($runtime['handled']===true&&$runtime['state']==='candidate'&&count($f['provider']->requests)===1,'server cron reaches producer without legacy event subscriptions');
    $f['provider']->loseAck=true;HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$f['provider'],'clock'=>static fn()=>1800000000]);
    $n=count($f['provider']->requests);
    HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$f['provider'],'clock'=>static fn()=>1800000000]);
    hsCheck(count($f['provider']->requests)===$n,'runtime UNKNOWN never sends again');
}finally{hsCleanup($f);}
$cli=(string)file_get_contents(dirname(__DIR__).'/tools/hotel_work.php');
hsCheck(strpos($cli,'HotelServerCronRuntime::tick')<strpos($cli,'hasActiveSubscriptions'),'server hook precedes legacy subscription gate');

echo 'HOTEL SERVER CYCLE '.(getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'NATIVE MYSQL':'SQLITE').': '.$hsChecks." PASS\n";
