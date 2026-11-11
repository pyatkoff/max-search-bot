<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/hotel_server_cycle.php';

/** A visible child result must already contain the complete JSON document. */
function hsPublishChildResult(string $out,array $result): void
{
    $bytes=HotelServerInputs::json($result);
    $temporary=$out.'.'.bin2hex(random_bytes(8)).'.tmp';
    try {
        if(file_put_contents($temporary,$bytes)!==strlen($bytes))throw new RuntimeException('race_result_write');
        if(!rename($temporary,$out))throw new RuntimeException('race_result_publish');
    } finally {
        if(is_file($temporary))@unlink($temporary);
    }
}

if(($argv[1]??null)==='--correction-race'){
    [$unused,$mode,$dsn,$go,$out,$payload]=array_pad($argv,6,'');$p=hsConnect($dsn);
    $cycle=new HotelServerCycle($p,new HotelServerLane($p));
    $correction=json_decode((string)file_get_contents($payload),true,64,JSON_THROW_ON_ERROR);
    file_put_contents($out.'.ready','1');$until=microtime(true)+12;
    while(!is_file($go)&&microtime(true)<$until)usleep(1000);
    if(!is_file($go))exit(3);
    $r=$cycle->correctRejectedFinalStage('NEW',$correction,static fn():bool=>true);
    hsPublishChildResult($out,['state'=>$r['state']]);exit(0);
}

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
    $r=$cycle->tick('NEW');hsPublishChildResult($out,['state'=>$r['state'],'calls'=>count($provider->requests)]);exit(0);
}
$hsChecks=0;
function hsCheck(bool $ok,string $label): void { global $hsChecks; ++$hsChecks; if(!$ok)throw new RuntimeException('HOTEL SERVER FAIL:'.$label); }
function hsReject(callable $fn,string $label,?string $message=null): void {
    try{$fn();}catch(Throwable $e){hsCheck($message===null||$e->getMessage()===$message,$label.':'.$e->getMessage());return;}hsCheck(false,$label);
}
function hsBoot(array $f,?array $plan=null): array { return $f['cycle']->bootstrap('NEW',hsInputs(),$plan??hsPlan(),static fn():bool=>true); }
function hsEnable(array $f): void { $s=$f['cycle']->status('NEW');$f['cycle']->enable('NEW',$s['bootstrap_sha256'],static fn():bool=>true); }
function hsCount(PDO $p,string $table): int { return (int)$p->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
function hsForwardInputs(array $plan,string $tag='next'): HotelServerInputs {
    $ret=hsLegacy();$bytes=hsInputs()->package()['bytes'];$contexts=[];
    foreach(['criteria','corpus','map','archives','instructions'] as $name)$bytes[$name]='Synthetic '.$tag.' '.$name;
    foreach($plan['stages'] as $scopes)foreach($scopes as $scope)$contexts[]=['scope'=>$scope,'dependencies'=>array_fill_keys(['archives','corpus','criteria','instructions','map'],'Assigned '.$tag.' projection')];
    $bytes['stage_contexts']=HotelServerInputs::json($contexts);return HotelServerInputs::validate($ret,hash('sha256',$ret['receipt_bytes']),$bytes);
}
function hsFinish(array $f,string $id,int $stages): array {
    for($i=0;$i<3*$stages;$i++)$status=$f['cycle']->tick($id);return $status;
}
function hsNext(array $f,string $old,string $next,array $plan,?HotelServerInputs $inputs=null): array {
    $s=$f['cycle']->status($old);return $f['cycle']->bootstrapNext($old,$s['bootstrap_sha256'],$s['baseline_sha256'],$next,$inputs??hsForwardInputs($plan),$plan,static fn()=>true);
}

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

// Terminal handoff preserves the full accepted view while each campaign keeps a bounded local chain.
$f=hsFixture();try {
    $plan=hsPlan(10);$f['cycle']->bootstrap('NEW',hsForwardInputs($plan,'first'),$plan,static fn()=>true);hsEnable($f);$old=hsFinish($f,'NEW',10);
    hsCheck($old['state']==='complete'&&$old['new_accepted']===10,'ten-stage predecessor really completes');
    $oldBytes=$f['p']->query("SELECT package_bytes FROM hotel_server_inputs WHERE campaign_id='NEW'")->fetchColumn();
    $oldReceipts=$f['p']->query("SELECT receipt_sha256 FROM hotel_server_receipts WHERE campaign_id='NEW' ORDER BY stage_index")->fetchAll(PDO::FETCH_COLUMN);
    $next=hsPlan();foreach($next['stages'] as &$scopes)$scopes[0]['question']='new-forward-question';unset($scopes);
    $inputs=hsForwardInputs($next);$calls=count($f['provider']->requests);
    hsReject(fn()=>$f['cycle']->bootstrapNext('NEW',$old['bootstrap_sha256'],$old['baseline_sha256'],'NEXT',$inputs,$next,static fn()=>false),'handoff needs explicit authorization','campaign_bootstrap_not_authorized');
    hsReject(fn()=>$f['cycle']->bootstrapNext('NEW',str_repeat('0',64),$old['baseline_sha256'],'NEXT',$inputs,$next,static fn()=>true),'stale predecessor bootstrap denied','predecessor_CAS');
    hsReject(fn()=>$f['cycle']->bootstrapNext('NEW',$old['bootstrap_sha256'],str_repeat('0',64),'NEXT',$inputs,$next,static fn()=>true),'stale predecessor baseline denied','predecessor_CAS');
    hsReject(fn()=>$f['cycle']->bootstrapNext('FOREIGN',$old['bootstrap_sha256'],$old['baseline_sha256'],'NEXT',$inputs,$next,static fn()=>true),'foreign lane owner denied','hotel_lane_not_owned');
    $changed=$inputs->package()['bytes'];$changed['reserves']=HotelServerInputs::json([hsScope('reserve'),hsScope('extra')]);$ret=hsLegacy();
    hsReject(fn()=>hsNext($f,'NEW','NEXT',$next,HotelServerInputs::validate($ret,hash('sha256',$ret['receipt_bytes']),$changed)),'history cannot be changed through successor','continuation_history_changed');
    $duplicate=hsPlan(1);hsReject(fn()=>hsNext($f,'NEW','NEXT',$duplicate),'global accepted tuple cannot be recollected','accepted_or_reserved_scope');
    $held=hsPlan(1);$held['stages']=[[hsScope('dynamic-reserve')]];$f['cycle']->protect('NEW',$held['stages'][0][0]);
    hsReject(fn()=>hsNext($f,'NEW','NEXT',$held),'dynamic predecessor reserve survives handoff admission','accepted_or_reserved_scope');
    $fault=new HotelServerCycle($f['p'],$f['lane'],$f['provider'],null,static function(string $at):void{if($at==='handoff_before_commit')throw new RuntimeException('synthetic_handoff_crash');});
    hsReject(fn()=>$fault->bootstrapNext('NEW',$old['bootstrap_sha256'],$old['baseline_sha256'],'NEXT',$inputs,$next,static fn()=>true),'handoff fault after every write','synthetic_handoff_crash');
    hsCheck(hsCount($f['p'],'hotel_server_campaigns')===1&&$f['p']->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='NEW'
        &&$f['cycle']->status('NEW')['enabled']===true&&hsCount($f['p'],'hotel_server_baselines')===11,'fault rolls back lane disable seed inputs exclusions and successor');$fault=null;
    $f['provider']->ready=false;$s=hsNext($f,'NEW','NEXT',$next,$inputs);
    hsCheck($s['state']==='disabled'&&!$s['enabled']&&$s['new_accepted']===0&&$s['historical_accepted']===19&&count($f['provider']->requests)===$calls,'handoff imports no new credit and performs no provider call');
    hsCheck(!$f['cycle']->status('NEW')['enabled']&&$f['cycle']->status('NEW')['new_accepted']===10
        &&$f['p']->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='NEXT','one transaction disables predecessor and transfers lane');
    hsCheck((int)$f['p']->query("SELECT COUNT(*) FROM hotel_server_campaigns WHERE enabled=1")->fetchColumn()===0,'both campaigns disabled until separate enable');
    hsCheck($f['cycle']->tick('NEXT')['state']==='disabled'&&count($f['provider']->requests)===$calls,'disabled successor cannot dispatch');
    hsReject(fn()=>$f['cycle']->tick('NEW'),'old tick cannot reacquire transferred lane','hotel_lane_not_owned');
    hsReject(fn()=>$f['lane']->reserve('NEW',static fn()=>true),'old reservation cannot steal successor lane','hotel_lane_busy');
    hsCheck(count($f['cycle']->readAccepted('NEXT')['records'])===29&&hsCount($f['p'],'hotel_server_records')===10,'carry-forward has all ten facts without reinserting global records');
    $root=$f['p']->query("SELECT document_bytes,baseline_sha256 FROM hotel_server_baselines WHERE campaign_id='NEXT' AND parent_sha256 IS NULL")->fetch(PDO::FETCH_ASSOC);$seed=json_decode($root['document_bytes'],true,64,JSON_THROW_ON_ERROR);
    hsCheck($seed['predecessor']['baseline_sha256']===$old['baseline_sha256']&&count($seed['records'])===10
        &&$s['bootstrap_sha256']===hash('sha256',HotelServerInputs::json([$inputs->package()['inputs_sha256'],$next,['continuation_seed_sha256'=>$root['baseline_sha256']]])),'new bootstrap binds exact server-derived seed and new task inputs');
    hsReject(fn()=>$f['cycle']->enable('NEXT',$s['bootstrap_sha256'],static fn()=>true),'ordinary provider gate still applies');$f['provider']->ready=true;
    $f['cycle']->enable('NEXT',$s['bootstrap_sha256'],static fn()=>true);$f['cycle']->tick('NEXT');$request=$f['provider']->requests[$calls];
    hsCheck(count($request['accepted_context']['records'])===1&&$request['accepted_context']['records'][0]['scope']['question']==='synthetic-question','new producer sees inherited accepted fact for the same hotel');
    for($i=0;$i<5;$i++)$f['cycle']->tick('NEXT');$second=$f['cycle']->status('NEXT');
    hsCheck($second['state']==='complete'&&$second['new_accepted']===2,'successor earns only its two real acceptances');
    hsReject(fn()=>hsNext($f,'NEXT','NEW',$next),'existing predecessor ID cannot be reused','successor_campaign_exists');
    $third=hsPlan(1);$third['stages'][0][0]['question']='third-forward-question';$s3=hsNext($f,'NEXT','THIRD',$third);
    hsCheck(count($f['cycle']->readAccepted('THIRD')['records'])===31,'second handoff retains twelve prior deltas without the ten-delta limit');
    $f['cycle']->enable('THIRD',$s3['bootstrap_sha256'],static fn()=>true);$end=hsFinish($f,'THIRD',1);
    hsCheck($end['state']==='complete'&&$end['new_accepted']===1&&count($f['cycle']->readAccepted('THIRD')['records'])===32
        &&hsCount($f['p'],'hotel_server_records')===13,'two handoffs preserve all history and credit only thirteen real accepted records');
    hsCheck((int)$f['p']->query("SELECT COUNT(*) FROM hotel_server_baselines WHERE campaign_id='THIRD'")->fetchColumn()===2,'latest chain contains seed plus only its own delta');
    hsCheck($f['p']->query("SELECT package_bytes FROM hotel_server_inputs WHERE campaign_id='NEW'")->fetchColumn()===$oldBytes
        &&$f['p']->query("SELECT receipt_sha256 FROM hotel_server_receipts WHERE campaign_id='NEW' ORDER BY stage_index")->fetchAll(PDO::FETCH_COLUMN)===$oldReceipts,'original input bytes and all receipt pins remain unchanged');
    $reserveKey=HotelServerInputs::key(hsScope('dynamic-reserve'));$q=$f['p']->prepare("SELECT kind FROM hotel_server_exclusions WHERE campaign_id='THIRD' AND semantic_key=?");$q->execute([$reserveKey]);
    hsCheck($q->fetchColumn()==='protected_reserve','dynamic reserve survives two successive handoffs');$q->closeCursor();
}finally{$fault=null;hsCleanup($f);}

// A complete flag cannot hide an UNKNOWN attempt or an unfinished work outbox.
foreach(['attempt','outbox'] as $corruption){$f=hsFixture();try{
    hsBoot($f,hsPlan(1));hsEnable($f);hsFinish($f,'NEW',1);$next=hsPlan(1);$next['stages'][0][0]['question']='next';
    if($corruption==='attempt')$f['p']->exec("UPDATE hotel_server_attempts SET state='unknown',result_sha256=NULL,result_bytes=NULL WHERE role='producer'");
    else $f['p']->exec("UPDATE hotel_server_outbox SET state='pending' WHERE role='producer'");
    hsReject(fn()=>hsNext($f,'NEW','NEXT',$next),'terminal flag does not bypass '.$corruption.' evidence');
    hsCheck(hsCount($f['p'],'hotel_server_campaigns')===1&&$f['cycle']->status('NEW')['enabled'],'failed terminal proof leaves predecessor owned and enabled');
}finally{hsCleanup($f);}}

// Even a self-consistent new seed hash/bootstrap cannot invent an old receipt pin.
$f=hsFixture();try{
    hsBoot($f,hsPlan(1));hsEnable($f);hsFinish($f,'NEW',1);$next=hsPlan(1);$next['stages'][0][0]['question']='next';$inputs=hsForwardInputs($next);$s=hsNext($f,'NEW','NEXT',$next,$inputs);
    $row=$f['p']->query("SELECT document_bytes FROM hotel_server_baselines WHERE campaign_id='NEXT' AND parent_sha256 IS NULL")->fetchColumn();$seed=json_decode($row,true,64,JSON_THROW_ON_ERROR);
    $seed['records'][0]['receipt_sha256']=str_repeat('f',64);$raw=HotelServerInputs::json($seed);$hash=hash('sha256',$raw);
    // Fixture-only corruption bypasses the DB trigger to exercise the reader's independent proof.
    $f['p']->exec('DROP TRIGGER hotel_server_baselines_immutable_update');
    $q=$f['p']->prepare("UPDATE hotel_server_baselines SET baseline_sha256=?,document_bytes=? WHERE campaign_id='NEXT'");$q->execute([$hash,$raw]);
    $q=$f['p']->prepare("UPDATE hotel_server_campaigns SET baseline_sha256=?,bootstrap_sha256=? WHERE campaign_id='NEXT'");
    $q->execute([$hash,hash('sha256',HotelServerInputs::json([$inputs->package()['inputs_sha256'],$next,['continuation_seed_sha256'=>$hash]]))]);
    hsReject(fn()=>$f['cycle']->readAccepted('NEXT'),'seed cannot self-certify a forged receipt pin','continuation_seed_lineage');
    hsCheck(hsCount($f['p'],'hotel_server_records')===1&&count($f['provider']->requests)===2,'forged seed does not create data credit or a call');
}finally{$q=null;hsCleanup($f);}

// A catalog commit with lost ACK remains replayable after ownership has moved forward.
$f=hsFixture();$other=null;$projector=null;try{
    hsBoot($f,hsPlan(1));hsEnable($f);$accepted=hsFinish($f,'NEW',1);$other=hsConnect($f['dsn']);$lost=true;
    $projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane'],static function()use(&$lost):void{if($lost){$lost=false;throw new RuntimeException('lost_after_catalog_commit');}});
    hsReject(fn()=>$projector->project('NEW',$accepted['receipt_sha256']),'original catalog commit loses ACK','lost_after_catalog_commit');
    $next=hsPlan(1);$next['stages'][0][0]['question']='next';hsNext($f,'NEW','NEXT',$next);
    $payload=$f['p']->query("SELECT payload_bytes FROM hotel_server_catalog_outbox WHERE campaign_id='NEW'")->fetchColumn();$hash=hash('sha256',$payload);
    hsReject(fn()=>$f['lane']->acknowledgeProjection('NEW',$accepted['receipt_sha256'],str_repeat('0',64)),'old projection wrong hash refused','projection_ack_CAS');
    $f['p']->exec("UPDATE hotel_server_campaigns SET state='candidate' WHERE campaign_id='NEW'");
    hsReject(fn()=>$f['lane']->acknowledgeProjection('NEW',$accepted['receipt_sha256'],$hash),'nonterminal foreign campaign cannot ACK','hotel_lane_not_owned');
    $f['p']->exec("UPDATE hotel_server_campaigns SET state='complete' WHERE campaign_id='NEW'");
    $projector->project('NEW',$accepted['receipt_sha256']);$projector->project('NEW',$accepted['receipt_sha256']);
    hsCheck(hsCount($other,'hotel_server_catalog_documents')===1&&$f['p']->query("SELECT state FROM hotel_server_catalog_outbox WHERE campaign_id='NEW'")->fetchColumn()==='done','exact old projection replay ACKs without a duplicate catalog document');
    hsCheck($f['cycle']->status('NEW')['new_accepted']===1&&$f['cycle']->status('NEXT')['new_accepted']===0
        &&$f['p']->query("SELECT queue_id FROM hotel_work_lane WHERE lane_key='HOTEL'")->fetchColumn()==='NEXT','projection ACK changes neither credit nor lane ownership');
    hsReject(fn()=>$f['cycle']->tick('NEW'),'projection exception never permits old tick','hotel_lane_not_owned');
}finally{$projector=null;$other=null;hsCleanup($f);}

// Unknown send has no refund, replacement or automatic retry, even beyond expiry.
$f=hsFixture();try {
    hsBoot($f);hsEnable($f);$f['provider']->loseAck=true;$r=$f['cycle']->tick('NEW');hsCheck($r['state']==='unknown','lost provider ACK holds');
    $next=hsPlan(1);$next['stages'][0][0]['question']='next';hsReject(fn()=>hsNext($f,'NEW','NEXT',$next),'UNKNOWN cannot authorize forward replacement','predecessor_not_complete');
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

$f=hsFixture();try{
    hsBoot($f);hsEnable($f);
    $f['p']->exec("UPDATE hotel_server_campaigns SET state='hold' WHERE campaign_id='NEW'");
    $before=$f['p']->query("SELECT * FROM hotel_server_campaigns WHERE campaign_id='NEW'")->fetch(PDO::FETCH_ASSOC);
    $outboxBefore=$f['p']->query("SELECT * FROM hotel_server_outbox ORDER BY stage_index,role")->fetchAll(PDO::FETCH_ASSOC);
    $runtime=HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$f['provider'],'clock'=>static fn()=>1800000000]);
    hsCheck($runtime['state']==='hold'&&($runtime['worker_completed']??false)===true,'confirmed stored campaign HOLD completes the worker without becoming business success');
    hsCheck(count($f['provider']->requests)===0&&hsCount($f['p'],'hotel_server_attempts')===0
        &&$f['p']->query("SELECT * FROM hotel_server_campaigns WHERE campaign_id='NEW'")->fetch(PDO::FETCH_ASSOC)===$before
        &&$f['p']->query("SELECT * FROM hotel_server_outbox ORDER BY stage_index,role")->fetchAll(PDO::FETCH_ASSOC)===$outboxBefore,
        'held recurrence preserves campaign outbox budgets and performs no provider dispatch');
    $failed=HotelServerCronRuntime::tick($flags,static function():array{throw new RuntimeException('synthetic context failure');});
    hsCheck($failed['state']==='hold'&&$failed['reason']==='server_tick_unconfirmed'&&($failed['worker_completed']??false)===false,
        'unconfirmed context failures cannot receive a completion attestation');
}finally{hsCleanup($f);}

// A follow-up accepts only the exact already-reviewed baseline; it cannot dispatch.
function hsAcceptanceSnapshot(PDO $p): array
{
    $tables=['campaigns'=>'campaign_id','attempts'=>'attempt_id','candidates'=>'campaign_id,stage_index',
        'reviews'=>'campaign_id,stage_index','records'=>'semantic_key','receipts'=>'campaign_id,stage_index',
        'baselines'=>'campaign_id,baseline_sha256','outbox'=>'campaign_id,stage_index,role','catalog_outbox'=>'receipt_sha256'];$out=[];
    foreach($tables as $table=>$order){$s=$p->query('SELECT * FROM hotel_server_'.$table.' ORDER BY '.$order);$out[$table]=$s->fetchAll(PDO::FETCH_ASSOC);$s->closeCursor();}
    return $out;
}
$f=hsFixture();$local=null;$fault=null;try{
    $start=hsBoot($f);$pin=$start['baseline_sha256'];$local=new HotelServerCycle($f['p'],$f['lane']);
    $before=hsAcceptanceSnapshot($f['p']);$r=$local->acceptReviewed('NEW',$pin);
    hsCheck($r['state']==='disabled'&&hsAcceptanceSnapshot($f['p'])===$before&&count($f['provider']->requests)===0,'local accept cannot enable or reserve a disabled campaign');
    hsEnable($f);$before=hsAcceptanceSnapshot($f['p']);$r=$local->acceptReviewed('NEW',$pin);
    hsCheck($r['state']==='ready'&&hsAcceptanceSnapshot($f['p'])===$before&&count($f['provider']->requests)===0,'local accept cannot dispatch a ready producer');
    $f['cycle']->tick('NEW');$before=hsAcceptanceSnapshot($f['p']);$r=$local->acceptReviewed('NEW',$pin);
    hsCheck($r['state']==='candidate'&&hsAcceptanceSnapshot($f['p'])===$before&&count($f['provider']->requests)===1,'local accept cannot dispatch a candidate reviewer');
    $f['cycle']->tick('NEW');$reviewed=hsAcceptanceSnapshot($f['p']);
    hsReject(fn()=>$local->acceptReviewed('FOREIGN',$pin),'local accept cannot acquire a foreign lane','hotel_lane_not_owned');
    $r=$local->acceptReviewed('NEW',str_repeat('0',64));
    hsCheck($r['state']==='reviewed'&&hsAcceptanceSnapshot($f['p'])===$reviewed,'stale baseline cannot accept an approved stage');
    $f['p']->exec("UPDATE hotel_server_campaigns SET enabled=0 WHERE campaign_id='NEW'");$disabled=hsAcceptanceSnapshot($f['p']);
    $r=$local->acceptReviewed('NEW',$pin);
    hsCheck($r['state']==='reviewed'&&!$r['enabled']&&hsAcceptanceSnapshot($f['p'])===$disabled,'reviewed state cannot bypass the enabled guard');
    $f['p']->exec("UPDATE hotel_server_campaigns SET enabled=1 WHERE campaign_id='NEW'");
    $fault=new HotelServerCycle($f['p'],$f['lane'],null,null,static function(string $at):void{
        if($at==='accept_before_commit')throw new RuntimeException('synthetic_followup_accept_crash');});
    hsReject(fn()=>$fault->acceptReviewed('NEW',$pin),'local acceptance fault rolls back','synthetic_followup_accept_crash');
    hsCheck(hsAcceptanceSnapshot($f['p'])===$reviewed,'rollback preserves durable approved review and every pre-acceptance row');$fault=null;
    $f['provider']->ready=false;$r=$local->tick('NEW');$accepted=hsAcceptanceSnapshot($f['p']);
    hsCheck($r['state']==='ready'&&$r['stage_index']===1&&$r['new_accepted']===1
        &&count($accepted['records'])===1&&count($accepted['receipts'])===1&&count($f['provider']->requests)===2,
        'ordinary tick resumes durable reviewed acceptance after rollback without a provider or readiness check');
    $old=$reviewed['campaigns'][0];$new=$accepted['campaigns'][0];
    foreach(['state','stage_cursor','new_accepted','baseline_sha256'] as $field){unset($old[$field],$new[$field]);}
    hsCheck($old===$new&&$accepted['attempts']===$reviewed['attempts']&&$accepted['reviews']===$reviewed['reviews'],
        'local acceptance leaves enable plan reservations attempts and original review unchanged');
    $local->acceptReviewed('NEW',$pin);
    hsCheck(hsAcceptanceSnapshot($f['p'])===$accepted&&count($f['provider']->requests)===2,'duplicate follow-up neither credits again nor dispatches the next producer');
    $f['provider']->ready=true;$f['cycle']->tick('NEW');$second=$f['cycle']->tick('NEW');$later=hsAcceptanceSnapshot($f['p']);
    $r=$local->acceptReviewed('NEW',$pin);
    hsCheck($r['state']==='reviewed'&&$r['new_accepted']===1&&hsAcceptanceSnapshot($f['p'])===$later,
        'earlier baseline follow-up cannot accept a later reviewed stage');
    $f['provider']->ready=false;$r=$local->acceptReviewed('NEW',$second['baseline_sha256']);$complete=hsAcceptanceSnapshot($f['p']);
    hsCheck($r['state']==='complete'&&$r['new_accepted']===2&&count($complete['records'])===2
        &&count($complete['receipts'])===2&&count($complete['attempts'])===4&&count($f['provider']->requests)===4,
        'exact second baseline completes locally with only the original four provider sends');
    $local->acceptReviewed('NEW',$r['baseline_sha256']);
    hsCheck(hsAcceptanceSnapshot($f['p'])===$complete&&count($f['provider']->requests)===4,'complete campaign follow-up is a byte-preserving no-op');
}finally{$local=null;$fault=null;hsCleanup($f);}

$f=hsFixture();$local=null;try{
    hsBoot($f);hsEnable($f);$f['provider']->loseAck=true;$f['cycle']->tick('NEW');
    $before=hsAcceptanceSnapshot($f['p']);$local=new HotelServerCycle($f['p'],$f['lane']);
    $r=$local->acceptReviewed('NEW',$f['cycle']->status('NEW')['baseline_sha256']);
    hsCheck($r['state']==='unknown'&&hsAcceptanceSnapshot($f['p'])===$before&&count($f['provider']->requests)===1,
        'local acceptance cannot resolve replace refund or resend an UNKNOWN attempt');
}finally{$local=null;hsCleanup($f);}

$f=hsFixture();$local=null;try{
    hsBoot($f);hsEnable($f);$f['cycle']->tick('NEW');$f['provider']->loseAck=true;$f['cycle']->tick('NEW');
    $negative=$f['provider']->results[1];$negative['approved']=false;$negative['evidence']='Synthetic independent reviewer explicitly rejects this candidate.';
    $f['cycle']->recordResult('NEW',$negative['attempt_id'],$negative);$before=hsAcceptanceSnapshot($f['p']);
    $local=new HotelServerCycle($f['p'],$f['lane']);$r=$local->acceptReviewed('NEW',$f['cycle']->status('NEW')['baseline_sha256']);
    hsCheck($r['state']==='hold'&&hsAcceptanceSnapshot($f['p'])===$before&&count($f['provider']->requests)===2,
        'negative independent review is never accepted by the follow-up');
    $runtime=HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$f['provider'],'clock'=>static fn()=>1800000000]);
    hsCheck($runtime['state']==='hold'&&($runtime['worker_completed']??false)===true
        &&hsAcceptanceSnapshot($f['p'])===$before&&count($f['provider']->requests)===2,
        'known rejected review remains confirmed HOLD with no new attempt');
}finally{$local=null;hsCleanup($f);}

// Two stages finish in four runtime invocations, with no second dispatch in any invocation.
$f=hsFixture();try{
    hsBoot($f);hsEnable($f);$provider=$f['provider'];
    $provider->onSend=static function()use($provider):void{if(count($provider->requests)%2===0)$provider->ready=false;};
    foreach(['candidate','ready','candidate','complete'] as $i=>$expected){
        $provider->ready=true;
        $runtime=HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$provider,'clock'=>static fn()=>1800000000]);
        hsCheck($runtime['state']===$expected&&($runtime['worker_completed']??false)===true
            &&$runtime['new_accepted']===intdiv($i+1,2)&&count($provider->requests)===$i+1,
            'runtime invocation '.($i+1).' completes one send and its eligible local acceptance');
    }
    hsCheck(hsCount($f['p'],'hotel_server_records')===2&&hsCount($f['p'],'hotel_server_receipts')===2
        &&hsCount($f['p'],'hotel_server_attempts')===4&&hsCount($f['p'],'hotel_server_outbox')===4,
        'four runtime invocations finish two stages without hidden provider reservations or outboxes');
    $before=hsAcceptanceSnapshot($f['p']);
    $runtime=HotelServerCronRuntime::tick($flags,static fn()=>['pdo'=>$f['p'],'provider'=>$provider,'clock'=>static fn()=>1800000000]);
    hsCheck($runtime['state']==='complete'&&($runtime['worker_completed']??false)===true
        &&count($provider->requests)===4&&hsAcceptanceSnapshot($f['p'])===$before,'completed runtime remains idle even when provider readiness is unavailable');
}finally{if(isset($provider))$provider->onSend=null;$provider=null;hsCleanup($f);}

$cli=(string)file_get_contents(dirname(__DIR__).'/tools/hotel_work.php');
hsCheck(strpos($cli,'HotelServerCronRuntime::tick')<strpos($cli,'hasActiveSubscriptions'),'server hook precedes legacy subscription gate');


$f=hsFixture();try{
    $ret=hsLegacy();$pin=hash('sha256',$ret['receipt_bytes']);$bytes=hsInputs()->package()['bytes'];
    $views=json_decode($bytes['stage_contexts'],true,64,JSON_THROW_ON_ERROR);
    $views[0]['extra_raw']='UNACCEPTED_CANARY';$bytes['stage_contexts']=HotelServerInputs::json($views);
    hsReject(fn()=>$f['cycle']->bootstrap('NEW',HotelServerInputs::validate($ret,$pin,$bytes),hsPlan(),static fn()=>true),'outer raw context field rejected','strict_stage_context_required');
    hsCheck(hsCount($f['p'],'hotel_server_campaigns')===0&&hsCount($f['p'],'hotel_server_attempts')===0&&count($f['provider']->requests)===0,'extra raw field cannot persist an assignment or send');
}finally{hsCleanup($f);}

require __DIR__.'/fixtures/hotel_retained_baseline_cases.php';
require __DIR__.'/fixtures/hotel_legacy_availability_cases.php';

$f=hsFixture();try{
    $bytes=haBytes();$f['cycle']->bootstrap('NEW',haInputs($bytes),hsPlan(1),static fn()=>true);hsEnable($f);hsFinish($f,'NEW',1);
    $next=hsPlan(1);$next['stages'][0][0]['question']='available-history-followup';
    $task=hsForwardInputs($next)->package()['bytes'];foreach(['criteria','corpus','map','archives','instructions','stage_contexts'] as $name)$bytes[$name]=$task[$name];
    $changed=haEditPolicy($bytes,static function(array &$policy):void{$policy['owner_decision']='DIFFERENT-SYNTHETIC-OWNER-DECISION';});
    hsReject(fn()=>hsNext($f,'NEW','NEXT',$next,haInputs($changed)),'availability provenance cannot change during handoff','continuation_history_changed');
    $s=hsNext($f,'NEW','NEXT',$next,haInputs($bytes));
    hsCheck($s['historical_accepted']===19&&$s['new_accepted']===0&&$s['retained_admission']['available_accepted']===13
        &&$s['retained_admission']['unavailable_accepted']===6&&$s['retained_admission']['unavailable_quarantine']===1
        &&count($f['cycle']->readAccepted('NEXT')['records'])===14,'forward import preserves 19 historical,13 available,6 unavailable,1 quarantine and one prior new fact without credit');
}finally{hsCleanup($f);}

require __DIR__.'/fixtures/hotel_server_correction_cases.php';
require __DIR__.'/fixtures/hotel_server_catalog_cases.php';

echo 'HOTEL SERVER CYCLE '.(getenv('HOTEL_WORK_NATIVE_MYSQL')==='1'?'NATIVE MYSQL':'SQLITE').': '.$hsChecks." PASS\n";


