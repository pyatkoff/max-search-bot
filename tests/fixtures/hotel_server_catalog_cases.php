<?php
declare(strict_types=1);

// Runs in the existing isolated SQLite/native-MySQL harness, with synthetic providers only.
function hsCatalogContext(array $f,callable $catalog): array
{
    $clock=$f['clock'];
    return ['pdo'=>$f['p'],'provider'=>$f['provider'],'clock'=>static fn():int=>$clock->now,'catalog'=>$catalog];
}
function hsCatalogStable(PDO $p): array
{
    $rows=hscSnapshot($p);unset($rows['hotel_server_catalog_outbox']);return $rows;
}
$catalogFlags=['enabled'=>true,'dispatch_allowed'=>true,'replacement_dispatch_allowed'=>true];

// A malformed operational payload must never become a visible catalogue document.
$f=hsFixture();$other=null;$projector=null;
try{
    hsBoot($f,hsPlan(1));hsEnable($f);$accepted=hsFinish($f,'NEW',1);
    $other=hsConnect($f['dsn']);$projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane']);
    $row=hscRow($f['p'],'SELECT payload_bytes FROM hotel_server_catalog_outbox WHERE receipt_sha256=?',[$accepted['receipt_sha256']]);
    foreach(['payload','state'] as $bad){
        $q=$f['p']->prepare($bad==='payload'
            ?'UPDATE hotel_server_catalog_outbox SET payload_bytes=? WHERE receipt_sha256=?'
            :'UPDATE hotel_server_catalog_outbox SET state=? WHERE receipt_sha256=?');
        $q->execute([$bad==='payload'?'{"unaccepted":"synthetic"}':'invalid',$accepted['receipt_sha256']]);$q->closeCursor();
        $before=hscSnapshot($f['p']);
        hsReject(fn()=>$projector->project('NEW',$accepted['receipt_sha256']),'invalid '.$bad.' rejected before catalogue insert');
        hsCheck(hsCount($other,'hotel_server_catalog_documents')===0&&hscSnapshot($f['p'])===$before,'invalid '.$bad.' leaves catalogue empty and application unchanged');
        $q->execute([$bad==='payload'?$row['payload_bytes']:'pending',$accepted['receipt_sha256']]);$q->closeCursor();
    }
    $receipt=json_decode(hscRow($f['p'],'SELECT receipt_bytes FROM hotel_server_receipts WHERE receipt_sha256=?',[$accepted['receipt_sha256']])['receipt_bytes'],true,64,JSON_THROW_ON_ERROR);
    $slot=1;
    foreach(['json','schema','campaign','baseline','parent','candidate','missing'] as $bad){
        $forged=$receipt;
        if($bad==='schema')$forged['schema']='unaccepted-fixture';
        if($bad==='campaign')$forged['campaign_id']='FOREIGN';
        if($bad==='baseline')$forged['baseline_sha256']=str_repeat('0',64);
        if($bad==='parent')$forged['previous_baseline_sha256']=str_repeat('0',64);
        if($bad==='candidate')$forged['candidate_sha256']=str_repeat('0',64);
        $raw=$bad==='json'?'{':HotelServerInputs::json($forged);
        $pin=$bad==='missing'?hash('sha256','missing synthetic receipt'):hash('sha256',$raw);
        if($bad!=='missing'){
            $q=$f['p']->prepare('INSERT INTO hotel_server_receipts(campaign_id,stage_index,receipt_sha256,receipt_bytes) VALUES(?,?,?,?)');
            $q->execute(['NEW',$slot++,$pin,$raw]);$q->closeCursor();
        }
        $q=$f['p']->prepare('INSERT INTO hotel_server_catalog_outbox(receipt_sha256,campaign_id,payload_bytes,state) VALUES(?,?,?,?)');
        $q->execute([$pin,'NEW',$row['payload_bytes'],'pending']);$q->closeCursor();$before=hscSnapshot($f['p']);
        hsReject(fn()=>$projector->project('NEW',$pin),'invalid receipt '.$bad.' rejected before catalogue insert');
        hsCheck(hsCount($other,'hotel_server_catalog_documents')===0&&hscSnapshot($f['p'])===$before,'invalid receipt '.$bad.' cannot publish or acknowledge');
    }
    $other->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);
    hsReject(fn()=>new HotelServerCatalogProjector($f['p'],$other,$f['lane']),'silent catalogue PDO refused','catalog_exception_mode_required');
    $other->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$q=null;
}finally{$q=null;$projector=null;$other=null;hsCleanup($f);}

// Both correction formats must prove the original negative-review lineage before writing.
foreach([1,2] as $version){
    $f=hscFixture(['stage'=>$version===1?3:0,'policy'=>true]);$writer=null;$other=null;$projector=null;
    try{
        $clock=$f['clock'];$writer=new HotelServerCycle($f['p'],$f['lane'],null,static fn():int=>$clock->now);
        $correction=$version===1?hscCorrection($f):hscContinuation($f);
        $accepted=$writer->correctRejectedStage('NEW',$correction,static fn():bool=>true);
        $attempt=$correction['proposal']['review_attempt'];$q=$f['p']->prepare('UPDATE hotel_server_attempts SET state=? WHERE attempt_id=?');
        $q->execute(['unknown',$attempt]);$q->closeCursor();$before=hscSnapshot($f['p']);
        $other=hsConnect($f['dsn']);$projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane']);
        hsReject(fn()=>$projector->project('NEW',$accepted['receipt_sha256']),'v'.$version.' correction loses original known review proof before catalogue insert');
        hsCheck(hsCount($other,'hotel_server_catalog_documents')===0&&hscSnapshot($f['p'])===$before,'v'.$version.' invalid correction leaves both stores unchanged');
        $q->execute(['succeeded',$attempt]);$q->closeCursor();$q=null;$clock->now=1800100000;$calls=count($f['provider']->requests);
        $projector->project('NEW',$accepted['receipt_sha256']);
        hsCheck(hsCount($other,'hotel_server_catalog_documents')===1&&count($f['provider']->requests)===$calls,'v'.$version.' valid historical correction projects after source and policy expiry without a send');
    }finally{$q=null;$writer=null;$projector=null;$other=null;hsCleanup($f);}
}

// Four accepted deltas drain one per tick, including exact replay of a committed lost ACK.
$f=hscFixture(['policy'=>true]);$other=null;$projector=null;$context=null;$factory=null;
try{
    $accepted=$f['cycle']->correctRejectedFinalStage('NEW',hscCorrection($f),static fn():bool=>true);
    $f['clock']->now=1800100000;
    $first=hscRow($f['p'],"SELECT receipt_sha256 FROM hotel_server_receipts WHERE campaign_id='NEW' ORDER BY stage_index LIMIT 1")['receipt_sha256'];
    $other=hsConnect($f['dsn']);$projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane'],static function():void{throw new RuntimeException('synthetic_catalogue_ACK_lost');});
    hsReject(fn()=>$projector->project('NEW',$first),'catalogue commits before synthetic lost ACK','synthetic_catalogue_ACK_lost');
    hsCheck(hsCount($other,'hotel_server_catalog_documents')===1&&hscRow($f['p'],'SELECT state FROM hotel_server_catalog_outbox WHERE receipt_sha256=?',[$first])['state']==='pending','lost ACK retains exact pending receipt after one catalogue commit');
    $projector=null;$stable=hsCatalogStable($f['p']);$factoryCalls=0;$dsn=$f['dsn'];
    $factory=static function()use(&$factoryCalls,$dsn):PDO{++$factoryCalls;return hsConnect($dsn);};
    $context=hsCatalogContext($f,$factory);
    for($i=1;$i<=4;$i++){
        $r=HotelServerCronRuntime::tick($catalogFlags,static fn():array=>$context);
        $done=(int)$f['p']->query("SELECT COUNT(*) FROM hotel_server_catalog_outbox WHERE state='done'")->fetchColumn();
        hsCheck($r['state']==='complete'&&$r['worker_completed']===true&&$r['catalog']===['state'=>'projected','projected'=>1]
            &&$done===$i&&hsCount($other,'hotel_server_catalog_documents')===$i&&hsCatalogStable($f['p'])===$stable
            &&count($f['provider']->requests)===8,'completed expired campaign tick '.$i.' projects only one exact receipt without another call or credit');
    }
    $before=hscSnapshot($f['p']);$r=HotelServerCronRuntime::tick($catalogFlags,static fn():array=>$context);
    hsCheck($r['state']==='complete'&&$r['catalog']===['state'=>'idle','projected'=>0]&&$factoryCalls===4
        &&hscSnapshot($f['p'])===$before&&count($f['provider']->requests)===8,'empty projection queue does not connect to catalogue or repeat accepted work');
}finally{$context=null;$factory=null;$projector=null;$other=null;hsCleanup($f);}

// Live collection still sends once per invocation and projects only its durable accepted delta.
$f=hsFixture();$context=null;$factory=null;
try{
    hsBoot($f);hsEnable($f);$factoryCalls=0;$dsn=$f['dsn'];
    $factory=static function()use(&$factoryCalls,$dsn):PDO{++$factoryCalls;return hsConnect($dsn);};
    $context=hsCatalogContext($f,$factory);
    foreach(['candidate','ready','candidate','complete'] as $i=>$state){
        $r=HotelServerCronRuntime::tick($catalogFlags,static fn():array=>$context);
        hsCheck($r['state']===$state&&count($f['provider']->requests)===$i+1
            &&$r['catalog']['state']===($i%2===0?'idle':'projected')&&$factoryCalls===intdiv($i+1,2)
            &&hsCount($f['p'],'hotel_server_catalog_documents')===intdiv($i+1,2),'active runtime retains one send and one eligible projection at tick '.($i+1));
    }
}finally{$context=null;$factory=null;hsCleanup($f);}

// Catalogue failures are separate from a confirmed research result; no hidden DDL or resend.
foreach(['connection','missing_table','same_pdo','conflict'] as $mode){
    $f=hsFixture();$other=null;$context=null;$factory=null;
    try{
        hsBoot($f,hsPlan(1));hsEnable($f);$accepted=hsFinish($f,'NEW',1);$f['provider']->ready=false;
        $other=hsConnect($f['dsn']);
        if($mode==='missing_table')$other->exec('DROP TABLE hotel_server_catalog_documents');
        if($mode==='conflict'){
            $q=$other->prepare('INSERT INTO hotel_server_catalog_documents(receipt_sha256,payload_sha256,document_bytes) VALUES(?,?,?)');
            $q->execute([$accepted['receipt_sha256'],hash('sha256','conflicting fixture'),'conflicting fixture']);$q->closeCursor();$q=null;
        }
        $factory=static function()use($mode,$other,$f):PDO{
            if($mode==='connection')throw new RuntimeException('SYNTHETIC_PRIVATE_CONNECTION_ERROR');
            return $mode==='same_pdo'?$f['p']:$other;
        };
        $context=hsCatalogContext($f,$factory);$before=hscSnapshot($f['p']);
        $r=HotelServerCronRuntime::tick($catalogFlags,static fn():array=>$context);
        hsCheck($r['state']==='complete'&&$r['worker_completed']===true
            &&$r['catalog']===['state'=>'unavailable','projected'=>null,'reason'=>'catalog_projection_unconfirmed']
            &&!array_key_exists('provider_calls',$r)&&!str_contains(HotelServerInputs::json($r),'SYNTHETIC_PRIVATE')
            &&hscSnapshot($f['p'])===$before&&count($f['provider']->requests)===2&&!$f['p']->inTransaction()&&!$other->inTransaction(),
            'catalogue '.$mode.' preserves pending receipt and durable completion with truthful separate failure');
        if($mode==='missing_table')hsReject(fn()=>$other->query('SELECT * FROM hotel_server_catalog_documents'),'missing catalogue table is not automatically created');
        if($mode==='conflict')hsCheck(hscRow($other,'SELECT document_bytes FROM hotel_server_catalog_documents')['document_bytes']==='conflicting fixture','conflicting catalogue bytes are never overwritten');
    }finally{$q=null;$context=null;$factory=null;$other=null;hsCleanup($f);}
}

// A predecessor's late ACK cannot be stranded by handoff or change the current UNKNOWN attempt.
$f=hsFixture();$other=null;$projector=null;$context=null;$factory=null;
try{
    hsBoot($f,hsPlan(1));hsEnable($f);$accepted=hsFinish($f,'NEW',1);$other=hsConnect($f['dsn']);
    $projector=new HotelServerCatalogProjector($f['p'],$other,$f['lane'],static function():void{throw new RuntimeException('synthetic_old_ACK_lost');});
    hsReject(fn()=>$projector->project('NEW',$accepted['receipt_sha256']),'old receipt committed before handoff loses ACK','synthetic_old_ACK_lost');$projector=null;
    $plan=hsPlan(1);$plan['stages'][0][0]['question']='new-campaign-question';$next=hsNext($f,'NEW','NEXT',$plan);
    $f['cycle']->enable('NEXT',$next['bootstrap_sha256'],static fn():bool=>true);$f['provider']->loseAck=true;$f['cycle']->tick('NEXT');
    $before=hsCatalogStable($f['p']);$calls=count($f['provider']->requests);$factoryCalls=0;
    $factory=static function()use(&$factoryCalls,$other):PDO{++$factoryCalls;return $other;};$context=hsCatalogContext($f,$factory);
    $r=HotelServerCronRuntime::tick($catalogFlags,static fn():array=>$context);
    hsCheck($r['state']==='unknown'&&$r['catalog']===['state'=>'projected','projected'=>1]
        &&hsCount($other,'hotel_server_catalog_documents')===1&&hsCatalogStable($f['p'])===$before
        &&hscRow($f['p'],'SELECT state FROM hotel_server_catalog_outbox WHERE receipt_sha256=?',[$accepted['receipt_sha256']])['state']==='done'
        &&count($f['provider']->requests)===$calls,'retired complete predecessor late ACK preserves current lane and exact UNKNOWN attempt');
    $f['p']->exec("UPDATE hotel_server_campaigns SET state='hold' WHERE campaign_id='NEW'");
    $f['p']->exec("UPDATE hotel_server_catalog_outbox SET state='pending' WHERE campaign_id='NEW'");$before=hscSnapshot($f['p']);
    $r=HotelServerCronRuntime::tick($catalogFlags,static fn():array=>$context);
    hsCheck($r['state']==='unknown'&&$r['catalog']===['state'=>'idle','projected'=>0]&&$factoryCalls===1
        &&hscSnapshot($f['p'])===$before&&count($f['provider']->requests)===$calls,'unfinished foreign campaign is excluded without a catalogue connection or UNKNOWN resend');
}finally{$context=null;$factory=null;$projector=null;$other=null;hsCleanup($f);}
