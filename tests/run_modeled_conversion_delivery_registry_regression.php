<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_value_export_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionDeliveryRegistry.php';
require_once dirname(__DIR__).'/services/ModeledConversionDailyReconciliationReader.php';
require_once dirname(__DIR__).'/services/ModeledConversionDailyReconciliationView.php';
require_once __DIR__.'/fixtures/modeled_forecast_payment_checks.php';

function deliveryRegistrySchema(PDO $p):void
{
    modeledValueSchema($p);
    $p->exec('DROP TABLE IF EXISTS customers');
    $p->exec('CREATE TABLE customers(id BIGINT PRIMARY KEY,display_name VARCHAR(1024))');
}
function deliveryRegistryFixtureEvent(PDO $p,int $conversation,int $rank,int $at,array $options=[]):string
{
    $q=$p->prepare('SELECT channel FROM conversations WHERE id=?');$q->execute([$conversation]);$platform=$q->fetchColumn();
    if($platform===false){
        $platform=$options['platform']??($conversation%2===0?'max':'telegram');
        modeledValueBinding($p,$conversation,$platform,$options['test']??0);
        $p->prepare('INSERT INTO customers VALUES(?,?)')->execute([$conversation,$options['name']??'Турист '.$conversation]);
    }
    $subject=['project_key'=>'anytour','platform'=>$platform,'customer_key'=>(string)$conversation,'conversation_id'=>(string)$conversation];
    $profileAt=$options['profile_at']??$at;
    $wrapper=['access_status'=>'allowed','subject'=>$subject,'profile'=>['prediction_at'=>gmdate('Y-m-d\TH:i:s\Z',$profileAt)]];
    if(!empty($options['bad_profile']))$wrapper['subject']['customer_key']='999999';
    $p->prepare('INSERT INTO modeled_conversion_profile_versions(project_key,platform,customer_key,conversation_id,observed_at_unix,profile_json) VALUES(?,?,?,?,?,?)')
        ->execute(['anytour',$platform,(string)$conversation,$conversation,$profileAt,json_encode($wrapper,JSON_THROW_ON_ERROR)]);
    $profile=(string)$p->lastInsertId();$state=$options['state']??'pending';
    $receipt=in_array($state,['accepted','processed'],true);
    $columns=['project_key','conversation_id','external_user_id','counter_id','target','transport_target','event_unix','platform',
        'value_rub','value_profile_id','value_stage','value_contract','value_origin','state','client_id','upload_id','upload_status',
        'last_error','value_ready_at_unix','claimed_at_unix','value_api_accepted_at_unix','value_processed_at_unix','value_receipt_basis'];
    $values=['anytour',$conversation,(string)(70000+$conversation),'98615635',!empty($options['predictive'])?ModeledConversionValueContract::predictionKey($profile):ModeledConversionValueContract::key($rank),
        ModeledConversionValueContract::TARGET,$at,$platform,$options['value']??ModeledConversionValueContract::VALUES[$rank],
        $profile,ModeledConversionValueContract::STAGES[$rank],ModeledConversionValueContract::VERSION,$options['origin']??null,$state,
        $receipt?'fixturePrivateClientIdentifier':null,$receipt?'fixtureUploadId':null,$state==='processed'?'PROCESSED':($receipt?'UPLOADED':null),
        $options['error']??'', $receipt?$at+1:null,$receipt?$at+2:null,
        !empty($options['legacy_clock'])?null:($receipt?$at+3:null),$state==='processed'?$at+4:null,
        !empty($options['legacy_clock'])?null:($receipt?'post_response':null)];
    $p->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute($values);
    return(string)$p->lastInsertId();
}
function deliveryRegistryDataDigest(PDO $p):string
{
    $data=[];foreach(['customers','customer_channels','conversations','modeled_conversion_profile_versions','max_channel_conversion_outbox']as$table)
        $data[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}

/** Shared SQLite/native-MySQL assertions. Real-data identities never enter this fixture. */
function dailyReconciliationAssertions(PDO $p,callable $check):void
{
    $zone=new DateTimeZone('Europe/Moscow');$date='2026-11-02';$day=new DateTimeImmutable($date.' 00:00:00',$zone);
    $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();$now=$until+3600;
    $auth=static function():void{};$ids=[];
    $event=static function(int $conversation,int $rank,int $at,array $options=[])use($p,&$ids):string{
        $id=deliveryRegistryFixtureEvent($p,$conversation,$rank,$at,$options);$ids[$conversation.':'.$rank]=$id;return$id;
    };
    $set=static function(string $id,array $values)use($p):void{
        $fields=array_map(static fn(string $key):string=>$key.'=?',array_keys($values));
        // Deliberate fixture probes must not advance native ON UPDATE metadata.
        // The reader's full-state digest still includes updated_at.
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'&&!array_key_exists('updated_at',$values))$fields[]='updated_at=updated_at';
        $p->prepare('UPDATE max_channel_conversion_outbox SET '.implode(',',$fields).' WHERE id=?')->execute([...array_values($values),$id]);
    };
    try{
        $a=$event(400,1,$from,['name'=>'Private reconciliation <script>fixtureName</script>']);
        $b=$event(400,2,$from+100,['value'=>833,'state'=>'processed']);
        $c=$event(401,1,$from-86400+100,['state'=>'accepted']);
        $set($c,['claimed_at_unix'=>$from+10,'value_api_accepted_at_unix'=>$from+20]);
        $d=$event(402,1,$from+200,['state'=>'ready','origin'=>'owner_day_review_v1']);
        $set($d,['client_id'=>'fixturePrivateClientIdentifier']);
        $event(403,1,$from+300,['state'=>'unknown']);
        $f=$event(404,1,$from-86400+400,['state'=>'processed','legacy_clock'=>true,'origin'=>'owner_fortnight_review_v1']);
        $set($f,['claimed_at_unix'=>$from+40,'value_processed_at_unix'=>null]);
        $g=$event(405,1,$from-1,['state'=>'accepted']);
        $set($g,['claimed_at_unix'=>$from-1,'value_api_accepted_at_unix'=>$from-1]);
        $event(406,1,$until);
        $event(407,1,$from-1);
        $j=$event(408,1,$from+400,['state'=>'accepted']);
        $set($j,['value_api_accepted_at_unix'=>$until]);
        $k=$event(409,1,$from+500,['state'=>'processed']);
        $set($k,['value_processed_at_unix'=>$until]);
        $event(410,1,$from+600,['state'=>'failed']);
        $event(411,1,$from+700,['state'=>'expired']);
        $event(412,1,$from+800,['state'=>'unmatched']);
        $o=$event(413,1,$from+900,['state'=>'uploading']);
        $set($o,['claimed_at_unix'=>$from+901,'client_id'=>'fixturePrivateClientIdentifier']);
        // Same exact admission predicate as the owner ledger, including native and bound-chat identity.
        $event(1,1,$from+1,['platform'=>'telegram']);$event(2,1,$from+2,['platform'=>'max']);
        $event(420,1,$from+3,['test'=>1]);
        $badPeer=$event(421,1,$from+4);$set($badPeer,['external_user_id'=>'fixtureWrongPeer']);
        $event(422,1,$from+5);$p->exec("UPDATE customer_channels SET external_chat_id='fixtureWrongChat' WHERE id=422");
        $badCounter=$event(423,1,$from+6);$set($badCounter,['counter_id'=>'other']);
        $badContract=$event(424,1,$from+7);$set($badContract,['value_contract'=>'foreign_contract']);
        $badTarget=$event(425,1,$from+8);$set($badTarget,['transport_target'=>'foreign_target']);
        $badProject=$event(426,1,$from+9);$set($badProject,['project_key'=>'foreign']);
        if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
            // Make the original helper fail deterministically, without sleep or SET TIMESTAMP.
            $fixtureClock='2001-01-01 00:00:00';
            $p->prepare('UPDATE max_channel_conversion_outbox SET updated_at=? WHERE id=?')->execute([$fixtureClock,$a]);
            $storedClock=$p->prepare('SELECT updated_at FROM max_channel_conversion_outbox WHERE id=?');$storedClock->execute([$a]);
            $check('native fixture stored clock differs from the probe-write clock',
                (string)$p->query('SELECT CURRENT_TIMESTAMP')->fetchColumn()!==$fixtureClock
                &&$storedClock->fetchColumn()===$fixtureClock);
        }
        $before=deliveryRegistryDataDigest($p);
        $read=static fn(array $input=[]):array=>(new ModeledConversionDailyReconciliationReader($p,$auth))->read(['date'=>$date]+$input,$now);
        $page=$read();
        $check('daily reconciliation uses the real shared schema on each SQL driver',($page['available']??false)===true);
        $check('owner reconciliation fixes Moscow calendar midnight independently of UTC',
            $page['date']===$date&&$page['timezone']==='Europe/Moscow'
            &&$page['window']===['from'=>gmdate('Y-m-d\TH:i:s\Z',$from),'until_exclusive'=>gmdate('Y-m-d\TH:i:s\Z',$until)]
            &&$page['as_of']===gmdate('Y-m-d\TH:i:s\Z',$now));
        $accrual=$page['calendar']['accruals'];
        $check('accrual totals use distinct conversations and exclude every foreign owner native or test scope',
            $accrual['events']===10&&$accrual['conversations']===9&&$accrual['value_rub']===12083
            &&$accrual['by_platform']['max']===['events'=>6,'conversations'=>5,'value_rub'=>7083]
            &&$accrual['by_platform']['telegram']===['events'=>4,'conversations'=>4,'value_rub'=>5000]);
        $claims=$page['calendar']['upload_claims'];
        $check('last saved claim day includes older accruals without becoming an HTTP attempt counter',
            $claims['events']===6&&$claims['conversations']===6&&$claims['value_rub']===7083
            &&$claims['by_platform']['max']['value_rub']===3333&&$claims['by_platform']['telegram']['value_rub']===3750
            &&$claims['basis']==='last_current_persisted_claim_not_http_send_count');
        $api=$page['calendar']['api_receipts'];$processed=$page['calendar']['processed'];
        $check('API and processed cohorts use their own first observation day rather than accrual or claim day',
            $api['events']===3&&$api['conversations']===3&&$api['value_rub']===3333
            &&$api['by_platform']['max']['value_rub']===833&&$api['by_platform']['telegram']['value_rub']===2500
            &&$processed['events']===1&&$processed['conversations']===1&&$processed['value_rub']===833
            &&$processed['basis']==='first_server_observation_of_processed_rows');
        $check('origin is independent of every calendar cohort and conserves value',
            $accrual['origins']['live']['value_rub']===10833&&$accrual['origins']['historical_review']['value_rub']===1250
            &&$claims['origins']['historical_review']['value_rub']===1250);
        $status=$page['current_statuses'];
        $check('selected day states are current and expose waiting problem and stored-identity subsets',
            count($status['by_state'])===9&&$status['by_state']['processed']===['events'=>2,'conversations'=>2,'value_rub'=>2083]
            &&$status['waiting']===['events'=>3,'conversations'=>3,'value_rub'=>3750]
            &&$status['problems']===['events'=>4,'conversations'=>4,'value_rub'=>5000]
            &&$status['waiting_identity']===['events'=>1,'conversations'=>1,'value_rub'=>1250]
            &&$status['basis']==='current_state_of_selected_day_accruals');
        $unknownApi=$page['coverage']['api_receipts_without_observed_clock'];$unknownProcessed=$page['coverage']['processed_without_observed_clock'];
        $check('legacy receipts with no observation clocks remain unknown rather than backfilled to a claim date',
            $unknownApi['events']===1&&$unknownApi['value_rub']===1250&&$unknownProcessed['events']===1
            &&$unknownProcessed['value_rub']===1250&&$unknownApi['origins']['historical_review']['events']===1
            &&$unknownApi['basis']==='all_current_scope_receipts_not_assigned_to_any_day');
        $check('per-event visit matching remains unavailable instead of accepting processed as a match',
            $page['coverage']['per_event_visit_match']===['status'=>'unavailable','reason'=>'not_recorded_per_conversion']
            &&$page['interpretation']['receipt_and_processing_are_not_individual_visit_linkage']===true);
        foreach(['max','telegram']as$platform){
            $filtered=$read(['platform'=>$platform]);
            $check('calendar platform filter preserves exact '.$platform.' totals and selected-day statuses',
                $filtered['available']&&$filtered['platform']===$platform
                &&$filtered['calendar']['accruals']['events']===$accrual['by_platform'][$platform]['events']
                &&$filtered['calendar']['accruals']['conversations']===$accrual['by_platform'][$platform]['conversations']
                &&$filtered['calendar']['accruals']['value_rub']===$accrual['by_platform'][$platform]['value_rub']
                &&$filtered['calendar']['accruals']['by_platform'][$platform==='max'?'telegram':'max']['events']===0);
        }
        $empty=(new ModeledConversionDailyReconciliationReader($p,$auth))->read(['date'=>'2026-10-20'],$now);
        $check('an empty calendar day is complete zero while global legacy-clock coverage remains explicit',
            $empty['available']&&$empty['calendar']['accruals']['events']===0&&$empty['calendar']['api_receipts']['events']===0
            &&$empty['coverage']['api_receipts_without_observed_clock']['events']===1);
        $wire=json_encode($page,JSON_THROW_ON_ERROR);
        $check('aggregate projection contains no customer name client identifier upload receipt or raw rows',
            !str_contains($wire,'fixturePrivateClientIdentifier')&&!str_contains($wire,'fixtureUploadId')
            &&!str_contains($wire,'fixtureName')&&!array_key_exists('rows',$page));
        $html=ModeledConversionDailyReconciliationView::render($page);
        $check('owner view separates movement current states unknown visits and legacy coverage',
            str_contains($html,'12 083,00 ₽')&&str_contains($html,'Приём API подтверждён')
            &&str_contains($html,'Это неизвестность, а не ноль совпадений.')
            &&str_contains($html,'Это весь текущий допущенный охват выбранного канала')
            &&str_contains($html,'не восстановленная очередь на конец суток')
            &&str_contains($html,'view=delivery&amp;date=2026-11-02&amp;platform=all'));
        $tg=ModeledConversionDailyReconciliationView::render($read(['platform'=>'telegram']));
        $check('detail navigation preserves selected date and channel and never invents excluded-lane totals',
            str_contains($tg,'view=delivery&amp;date=2026-11-02&amp;platform=telegram')
            &&str_contains($tg,'<th>Telegram</th>')&&!str_contains($tg,'<th>MAX</th>'));
        $malformed=$page;$malformed['calendar']['accruals']['value_rub']++;
        $hidden=ModeledConversionDailyReconciliationView::render($malformed);
        $closed=ModeledConversionDailyReconciliationView::render(null,['date'=>'"><script>fixtureBad</script>','platform'=>'"><script>fixtureBad</script>']);
        $check('invalid or unavailable reports hide all amount tables and normalize hostile filters',
            str_contains($hidden,'Сверка сейчас недоступна')&&!str_contains($hidden,'<table>')
            &&!str_contains($hidden,'12 083,00 ₽')&&!str_contains($closed,'<script>fixtureBad</script>'));
        $check('all successful reconciliation reads are byte-identical and close their read-only snapshots',
            deliveryRegistryDataDigest($p)===$before&&!$p->inTransaction());
        foreach([['date'=>'2026-02-30'],['date'=>'2026-11-04'],['platform'=>'MAX'],['cursor'=>'foreign'],['date'=>[]]]as$input){
            $invalid=(new ModeledConversionDailyReconciliationReader($p,$auth))->read($input,$now);
            $check('invalid reconciliation selection is closed without changing data '.json_encode(array_keys($input)),
                $invalid===['available'=>false,'reason'=>'invalid_request']&&!$p->inTransaction());
        }
        $p->beginTransaction();$outer=$read();
        $check('reconciliation refuses a caller-owned snapshot and leaves it open',
            $outer===['available'=>false,'reason'=>'outer_transaction']&&$p->inTransaction());$p->rollBack();
        $outside=0;$revoked=false;
        try{(new ModeledConversionDailyReconciliationReader($p,static function()use($p,&$outside):void{
            if(!$p->inTransaction()&&++$outside>1)throw new DomainException('fixture_revoked');
        }))->read(['date'=>$date],$now);}catch(DomainException $ignored){$revoked=true;}
        $check('final authorization can revoke a complete aggregate after the read-only transaction',
            $revoked&&!$p->inTransaction());
        $clock=0.0;$limited=(new ModeledConversionDailyReconciliationReader($p,$auth,static function()use(&$clock):float{return$clock+=4.0;}))->read(['date'=>$date],$now);
        $check('reconciliation deadline fails closed and releases its snapshot',
            $limited===['available'=>false,'reason'=>'read_limit']&&!$p->inTransaction());
        $set($a,['value_stage'=>'Interest']);$invalid=$read();$set($a,['value_stage'=>'interest']);
        $check('case-insensitive native collation cannot admit a foreign financial stage into global sums',
            $invalid===['available'=>false,'reason'=>'invalid_aggregate']&&!$p->inTransaction());
        $set($a,['value_api_accepted_at_unix'=>$from+1]);$invalid=$read();$set($a,['value_api_accepted_at_unix'=>null]);
        $check('receipt observation without a stored API receipt withholds every aggregate',
            $invalid===['available'=>false,'reason'=>'invalid_aggregate']&&!$p->inTransaction());
        $set($a,['value_receipt_basis'=>'PrivateUnexpectedBasis']);$invalid=$read();$set($a,['value_receipt_basis'=>null]);
        $check('unrecognized receipt evidence cannot become a calendar total or a raw error label',
            $invalid===['available'=>false,'reason'=>'invalid_aggregate']
            &&!str_contains(json_encode($invalid,JSON_THROW_ON_ERROR),'PrivateUnexpectedBasis'));
        $set($ids['403:1'],['state'=>'accepted']);$invalid=$read();$set($ids['403:1'],['state'=>'unknown']);
        $check('accepted or processed labels without any stored receipt withhold all totals',
            $invalid===['available'=>false,'reason'=>'invalid_aggregate']&&!$p->inTransaction());
        $check('closed and rejected reads preserve the full fixture state',deliveryRegistryDataDigest($p)===$before&&!$p->inTransaction());
        $event(440,1,$from+1000,['value'=>0.01]);$event(441,1,$from+1001,['value'=>0.10]);$event(442,1,$from+1002,['value'=>0.20]);
        $decimalBefore=deliveryRegistryDataDigest($p);$decimal=$read();$dc=$decimal['calendar']['accruals']??[];
        $check('native DECIMAL and SQLite floating sums preserve one ten and twenty kopeck increments',
            ($decimal['available']??false)===true&&($dc['events']??null)===13
            &&(int)round(($dc['value_rub']??0)*100)===1208331
            &&(int)round(($dc['by_platform']['max']['value_rub']??0)*100)===708321
            &&(int)round(($dc['by_platform']['telegram']['value_rub']??0)*100)===500010
            &&deliveryRegistryDataDigest($p)===$decimalBefore&&!$p->inTransaction());

    }finally{
        if($p->inTransaction())$p->rollBack();
        foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DELETE FROM '.$table);
    }
}

function deliveryPredictiveAndVisitAssertions(PDO $p,callable $check):void
{
    $day=(new DateTimeImmutable('2026-11-02 00:00:00',new DateTimeZone('Europe/Moscow')))->getTimestamp();$now=$day+8000;
    $legacy=['id'=>'1','conversation_id'=>'600','project_key'=>'anytour','counter_id'=>'98615635','transport_target'=>ModeledConversionValueContract::TARGET,
        'value_contract'=>ModeledConversionValueContract::VERSION,'platform'=>'max','target'=>ModeledConversionValueContract::key(1),
        'value_profile_id'=>'1','value_stage'=>'interest','value_rub'=>100,'event_unix'=>$day+120,'client_id'=>'900000000000000001',
        'upload_id'=>'501','external_user_id'=>'70600','state'=>'processed','upload_status'=>'PROCESSED',
        'value_api_accepted_at_unix'=>$day+130,'value_processed_at_unix'=>$day+140];
    $a=$legacy;$a['id']='2';$a['target']=ModeledConversionValueContract::predictionKey('2');$a['value_profile_id']='2';
    $a['value_rub']=17;$a['event_unix']=$day+220;$a['upload_id']='502';$a['value_api_accepted_at_unix']=$day+230;$a['value_processed_at_unix']=null;
    $b=$a;$b['id']='3';$b['target']=ModeledConversionValueContract::predictionKey('3');$b['value_profile_id']='3';
    $b['value_rub']=100000;$b['conversation_id']='601';$b['platform']='telegram';$b['client_id']='900000000000000002';$b['upload_id']='503';
    $api=new class($day) {
        public array $statuses=['501'=>'PROCESSED','502'=>'PROCESSED','503'=>'UPLOADED'];public bool $duplicate=false;public bool $mismatch=false;public array $calls=[];
        public function __construct(public int $day){}
        public function poll(string $id):array{$this->calls[]='GET receipt';return ['id'=>$id,'status'=>$this->statuses[$id],'source_quantity'=>1,'line_quantity'=>1];}
        public function modeledValueVisits(string $cid,int $activation,int $now):array{
            $this->calls[]='GET report';$visits=[['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->day+60),'visits'=>1,'goal_reaches'=>$cid==='900000000000000001'?2:1,
                'revenue_rub'=>($cid==='900000000000000001'?117:100000)+($this->mismatch?1:0)]];
            if($this->duplicate)$visits[]=$visits[0];return ['status'=>'ok','visits'=>$visits];
        }
    };
    $ledger=[$legacy,$a,$b];$before=serialize($ledger);
    $proof=ModeledConversionVisitProof::verify($ledger,$api,$day,$day+500,$now);
    $check('visit proof reconciles a predictive increment together with the legacy amount in one exact visit',
        $proof['events'][0]['increment_rub']===17&&$proof['events'][0]['linkage']==='unique_visit_reconciled'
        &&$proof['events'][0]['visit_goal_value_rub']===117&&$proof['events'][0]['expected_goal_reaches']===2);
    $check('an uploaded row stays waiting rather than becoming a matched visit',
        $proof['events'][1]['upload_status']==='UPLOADED'&&$proof['events'][1]['linkage']==='not_checked');
    $api->statuses['503']='PROCESSED';$proof=ModeledConversionVisitProof::verify($ledger,$api,$day,$day+500,$now);
    $wrap=static fn(array $v):array=>['sha256'=>hash('sha256',json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'receipt'=>$v];
    $cache=ModeledConversionVisitProof::validateCache($wrap($proof),$now);
    $check('saved proof is fingerprint-bound to the exact row amount and advertising binding',
        ModeledConversionVisitProof::forEvent($cache,$a)['linkage']==='unique_visit_reconciled');
    $check('a 100000 increment can be reconciled by the fake visit report and retained in the checked cache',
        $cache['available']&&$proof['events'][1]['increment_rub']===100000
        &&ModeledConversionVisitProof::forEvent($cache,$b)['linkage']==='unique_visit_reconciled');
    $over=$b;$over['value_rub']=100001;$calls=count($api->calls);$blocked=false;
    try{ModeledConversionVisitProof::verify([$over],$api,$day,$day+500,$now);}
    catch(RuntimeException $e){$blocked=$e->getMessage()==='verification_invalid_ledger';}
    $overCache=$proof;$overCache['events'][1]['increment_rub']=100001;
    $check('an over-cap ledger fails before provider reads and a freshly hashed over-cap cache remains invalid',
        $blocked&&count($api->calls)===$calls
        &&ModeledConversionVisitProof::validateCache($wrap($overCache),$now)===['available'=>false,'reason'=>'invalid_cache']);
    $changed=$a;$changed['value_rub']=18;
    $check('an amended ledger row cannot inherit the old visit proof',
        ModeledConversionVisitProof::forEvent($cache,$changed)===['available'=>false,'reason'=>'event_changed']);
    $corrupt=$wrap($proof);$corrupt['receipt']['events'][0]['increment_rub']=18;
    $check('corrupted private receipt is unavailable rather than trusted',!ModeledConversionVisitProof::validateCache($corrupt,$now)['available']);
    $check('saved visit evidence becomes explicitly stale',ModeledConversionVisitProof::validateCache($wrap($proof),$now+901)['fresh']===false);
    $directory=sys_get_temp_dir().'/modeled-visit-cache-fixture-'.bin2hex(random_bytes(8));
    if(!mkdir($directory,0700))throw new RuntimeException('fixture_cache_directory');$file=$directory.'/receipt.json';
    try{
        if(file_put_contents($file,json_encode($wrap($proof),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))===false
            ||!chmod($file,0600))throw new RuntimeException('fixture_cache_write');
        $process=proc_open([PHP_BINARY,'-d','disable_functions=posix_geteuid',__DIR__.'/fixtures/modeled_visit_cache_without_posix.php',$file,(string)$now],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('fixture_cache_process');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1],1024);fclose($pipes[1]);$errors=stream_get_contents($pipes[2],1024);fclose($pipes[2]);$code=proc_close($process);
        $check('private cache remains owner-bound and readable when the web runtime has no POSIX function',
            $code===0&&json_decode((string)$output,true)===['ok'=>true]&&$errors==='');
        $archive=$directory.'/event.json';$single=$proof;$single['events']=[$proof['events'][0]];
        $writer=new ReflectionMethod(ModeledConversionVisitProof::class,'storeAt');$writer->invoke(null,$archive,$single);
        $reader=new ReflectionMethod(ModeledConversionVisitProof::class,'readCacheAt');$saved=$reader->invoke(null,$archive,$now,null);
        $check('rotated single-event evidence uses a private atomic hashed receipt with its original event time',
            $saved['available']&&count($saved['receipt']['events'])===1&&(fileperms($archive)&0777)===0600
            &&ModeledConversionVisitProof::forEvent($saved,$a,$now)['checked_at']===$single['events'][0]['checked_at']);
        chmod($archive,0644);clearstatcache(true,$archive);
        $check('a publicly readable archived receipt is never trusted',!$reader->invoke(null,$archive,$now,null)['available']);
        unlink($archive);
    }finally{if(is_file($file))unlink($file);if(is_dir($directory))rmdir($directory);}

    $api->duplicate=true;$bad=ModeledConversionVisitProof::verify($ledger,$api,$day,$day+500,$now);
    $check('multiple goal visits are unverified rather than guessed by amount',$bad['events'][0]['linkage']==='unverified'&&$bad['events'][0]['reason']==='no_unique_goal_visit');
    $api->duplicate=false;$api->mismatch=true;$bad=ModeledConversionVisitProof::verify($ledger,$api,$day,$day+500,$now);
    $check('goal revenue mismatch prevents individual linkage confirmation',$bad['events'][0]['reason']==='goal_count_or_value_mismatch');
    $check('verification changes no profiles ledger rows or advertising state and uses reads only',
        serialize($ledger)===$before&&array_values(array_unique($api->calls))===['GET receipt','GET report']&&$proof['provider_write_requests']===0&&$proof['source_writes']===0);

    // Exact HTTP scope and counter timezone are exercised through the real client, without network.
    $wire=[];$transport=static function(string $method,string $url,mixed $body)use(&$wire,$day):array{
        $wire[]=[$method,$url,$body];
        if($url==='https://api-metrika.yandex.net/management/v1/counter/98615635')
            return ['http'=>200,'error'=>0,'body'=>json_encode(['counter'=>['id'=>98615635,'time_zone_name'=>'Europe/Moscow']])];
        parse_str((string)parse_url($url,PHP_URL_QUERY),$q);
        return ['http'=>200,'error'=>0,'body'=>json_encode(['query'=>['ids'=>[98615635],'metrics'=>explode(',',$q['metrics']),
            'dimensions'=>explode(',',$q['dimensions']),'filters'=>$q['filters']],'sampled'=>false,'total_rows'=>1,
            'data'=>[['dimensions'=>[['name'=>'900000000000000001'],['name'=>(new DateTimeImmutable('@'.($day+60)))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s')]],'metrics'=>[1,2,117]]]])];
    };
    $client=new MetrikaOfflineClient('98615635','SYNTHETIC_TOKEN',$transport);$visits=$client->modeledValueVisits('900000000000000001',$day,$now);
    parse_str((string)parse_url($wire[1][1],PHP_URL_QUERY),$q);
    $check('real report wire is GET-only exact goal ClientID full accuracy and counter timezone',
        $visits['status']==='ok'&&$visits['visits'][0]['started_at']===gmdate('Y-m-d\TH:i:s\Z',$day+60)
        &&$wire[0][0]==='GET'&&$wire[1][0]==='GET'&&$q['ids']==='98615635'&&$q['accuracy']==='full'
        &&$q['filters']==="ym:s:clientID=='900000000000000001' AND ym:s:goal==678187097"
        &&!str_contains(json_encode($wire,JSON_THROW_ON_ERROR),'SYNTHETIC_TOKEN'));


    $clientWire=[];$sampled=false;$truncated=false;
    $clientTransport=static function(string $method,string $url,mixed $body)use(&$clientWire,&$sampled,&$truncated,$day):array{
        $clientWire[]=[$method,$url,$body];
        if(!str_contains($url,'stat/v1/'))return ['http'=>200,'error'=>0,'body'=>json_encode(['counter'=>['id'=>98615635,'time_zone_name'=>'Europe/Moscow']])];
        parse_str((string)parse_url($url,PHP_URL_QUERY),$q);
        return ['http'=>200,'error'=>0,'body'=>json_encode(['query'=>['ids'=>[98615635],'metrics'=>explode(',',$q['metrics']),
            'dimensions'=>explode(',',$q['dimensions']),'filters'=>$q['filters']],'sampled'=>$sampled,'total_rows'=>$truncated?2:1,
            'data'=>[['dimensions'=>[['name'=>'900000000000000001'],['name'=>(new DateTimeImmutable('@'.($day+60)))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s')]],'metrics'=>[1]]]])];
    };
    $clientOnly=new MetrikaOfflineClient('98615635','SYNTHETIC_TOKEN',$clientTransport,microtime(true)+30);
    $allVisits=$clientOnly->modeledClientVisits('900000000000000001',$day,$now);
    parse_str((string)parse_url($clientWire[1][1],PHP_URL_QUERY),$clientQuery);
    $check('rejected linkage diagnosis reads exact ClientID visits without requiring a goal achievement',
        $allVisits['status']==='ok'&&$clientQuery['filters']==="ym:s:clientID=='900000000000000001'"
        &&$clientQuery['metrics']==='ym:s:visits'&&$clientQuery['accuracy']==='full'
        &&array_unique(array_column($clientWire,0))===['GET']);
    $clientOnly->modeledClientVisits('900000000000000001',$day,$now);
    $check('counter timezone is retained only within the same bounded verifier instance',count($clientWire)===3);
    $sampled=true;$badClient=$clientOnly->modeledClientVisits('900000000000000001',$day,$now);
    $sampled=false;$truncated=true;$truncatedClient=$clientOnly->modeledClientVisits('900000000000000001',$day,$now);
    $check('sampled or incomplete visit reports cannot diagnose absent visits',$badClient['status']==='unavailable'&&$truncatedClient['status']==='unavailable');
    $check('failure diagnosis distinguishes no visits later visits expired visits and an eligible but rejected visit',
        ModeledConversionVisitProof::failureReason(['status'=>'ok','visits'=>[]],$day+200)==='no_reported_client_visits'
        &&ModeledConversionVisitProof::failureReason(['status'=>'ok','visits'=>[['started_at'=>gmdate('c',$day+300)]]],$day+200)==='only_later_or_expired_client_visits'
        &&ModeledConversionVisitProof::failureReason(['status'=>'ok','visits'=>[['started_at'=>gmdate('c',$day-22*86400)]]],$day+200)==='no_client_visit_in_attribution_window'
        &&ModeledConversionVisitProof::failureReason($allVisits,$day+200)==='eligible_visit_but_provider_linkage_failed'
        &&ModeledConversionVisitProof::failureReason(['status'=>'unavailable','visits'=>[]],$day+200)==='client_visit_report_unavailable');
    $failureApi=new class($day){
        public function __construct(public int $day){}
        public function poll(string $id):array{return ['status'=>'LINKAGE_FAILURE','source_quantity'=>1,'line_quantity'=>1];}
        public function modeledClientVisits(string $cid,int $activation,int $now):array{return ['status'=>'ok','visits'=>[['started_at'=>gmdate('c',$this->day+60),'visits'=>1]]];}
    };
    $rejected=ModeledConversionVisitProof::verify([$a],$failureApi,$day,$day+500,$now);
    $check('an eligible candidate never turns a provider linkage failure into a credited conversion',
        $rejected['events'][0]['linkage']==='failed'&&$rejected['events'][0]['reason']==='eligible_visit_but_provider_linkage_failed'
        &&$rejected['events'][0]['visit_started_at']===null);
    $queue=[];
    for($i=40;$i>0;$i--){$r=$a;$r['id']=(string)$i;$r['value_api_accepted_at_unix']=$day+600;$queue[]=$r;}
    $selection=ModeledConversionVisitProof::dueIds($queue,$day+500,$now,static function(array $r)use($now):array{
        return $r['id']==='40'?['at'=>$now-400,'event'=>['linkage'=>'not_checked']]:[];
    },2);
    $check('a bounded scheduled batch includes the freshest row and an older never-checked row',$selection===['40','39']);
    $checked=ModeledConversionVisitProof::dueIds($queue,$day+500,$now,static fn(array $r):array=>[
        'at'=>$now-500,'event'=>['linkage'=>'unique_visit_reconciled']],2);
    $check('terminal visit evidence is not repeatedly queried every five minutes',$checked===[]);
    $deadlineCalls=count($api->calls);$deadlineBlocked=false;
    try{ModeledConversionVisitProof::verify([$a],$api,$day,$day+500,$now,null,microtime(true)-1);}catch(RuntimeException $ignored){$deadlineBlocked=true;}
    $check('expired verifier time budget stops before a provider receipt read',$deadlineBlocked&&count($api->calls)===$deadlineCalls);
    $retainedProof=ModeledConversionVisitProof::verify([$a],$api,$day,$day+500,$now,[],null,[(string)$a['id']=>$proof['events'][0]]);
    $check('stable original-cohort evidence remains available without repeating its requests',
        $retainedProof['events'][0]===$proof['events'][0]&&$retainedProof['selected_rows']===0&&count($api->calls)===$deadlineCalls);

    deliveryRegistrySchema($p);
    for($i=0;$i<35;$i++)deliveryRegistryFixtureEvent($p,610,1,$day+100+$i,['predictive'=>true,'value'=>10]);
    deliveryRegistryFixtureEvent($p,611,1,$day+200,['predictive'=>true,'value'=>0,'state'=>'failed','error'=>'price_loss_reconciled']);
    $digest=deliveryRegistryDataDigest($p);
    $page=(new ModeledConversionDeliveryRegistry($p,static function():void{},str_repeat('predictive-fixture-',3)))->read(['date'=>'2026-11-02'],$now);
    $check('predictive registry supports more than six increments while keeping exact full totals',
        ($page['available']??false)===true&&$page['summary']['events']===35&&$page['summary']['value_rub']===350
        &&count($page['rows'])===1&&$page['rows'][0]['events_total']===35&&$page['rows'][0]['events_omitted']===3
        &&count($page['rows'][0]['events'])===32&&$page['rows'][0]['total_value_rub']===350
        &&$page['rows'][0]['events'][0]['valuation']['basis']==='predictive_v1'&&deliveryRegistryDataDigest($p)===$digest);
    $daily=(new ModeledConversionDailyReconciliationReader($p,static function():void{}))->read(['date'=>'2026-11-02'],$now);
    $check('daily reconciliation admits all 35 predictive increments and excludes the recorded zero-value cancellation',
        ($daily['available']??false)&&$daily['calendar']['accruals']['events']===35
        &&$daily['calendar']['accruals']['conversations']===1&&$daily['calendar']['accruals']['value_rub']===350
        &&$daily['current_statuses']['by_state']['failed']['events']===0&&deliveryRegistryDataDigest($p)===$digest);
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DELETE FROM '.$table);
}

function deliveryRegistryValueCapAssertions(PDO $p,callable $check):void
{
    $date='2026-11-03';$anchor=strtotime('2026-11-03T12:00:00Z');$now=$anchor+3600;
    $auth=static function():void{};$key=str_repeat('value-cap-fixture-',3);
    $registry=static fn():array=>(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>$date],$now);
    $daily=static fn():array=>(new ModeledConversionDailyReconciliationReader($p,$auth))->read(['date'=>$date],$now);
    $set=static function(string $id,string $field,mixed $value)use($p):void{
        $clock=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?',updated_at=updated_at':'';
        $p->prepare('UPDATE max_channel_conversion_outbox SET '.$field.'=?'.$clock.' WHERE id=?')->execute([$value,$id]);
    };
    try{
        deliveryRegistryFixtureEvent($p,620,6,$anchor-86400,['value'=>25000,'state'=>'processed']);
        $increment=deliveryRegistryFixtureEvent($p,620,6,$anchor+100,['predictive'=>true,'value'=>45000,'state'=>'processed']);
        $page=$registry();$reconciliation=$daily();
        $check('a legacy 25000 plus a new 45000 delta preserves the 70000 lifetime total and the 45000 current day',
            ($page['available']??false)&&($reconciliation['available']??false)&&count($page['rows'])===1
            &&$page['rows'][0]['total_value_rub']===70000&&$page['rows'][0]['period_value_rub']===45000
            &&$reconciliation['calendar']['accruals']['events']===1&&$reconciliation['calendar']['accruals']['value_rub']===45000);
        $tail=deliveryRegistryFixtureEvent($p,620,6,$anchor+200,['predictive'=>true,'value'=>30000,'state'=>'processed']);
        $cap=deliveryRegistryFixtureEvent($p,622,6,$anchor+300,['predictive'=>true,'value'=>100000,'state'=>'processed']);
        $before=deliveryRegistryDataDigest($p);$page=$registry();$reconciliation=$daily();$by=array_column($page['rows']??[],null,'conversation_id');
        $check('mixed increments reach 100000 per conversation while separate conversations may total more in a day',
            ($page['available']??false)&&($reconciliation['available']??false)
            &&$page['summary']['events']===3&&$page['summary']['conversations']===2&&$page['summary']['value_rub']===175000
            &&$by['620']['total_value_rub']===100000&&$by['620']['period_value_rub']===75000
            &&array_column($by['620']['events'],'value_rub')===[25000,45000,30000]&&$by['622']['total_value_rub']===100000
            &&$reconciliation['calendar']['accruals']['value_rub']===175000
            &&$reconciliation['calendar']['api_receipts']['value_rub']===175000&&$reconciliation['calendar']['processed']['value_rub']===175000);
        $set($cap,'value_rub',100001);
        $check('a recorded event above 100000 withholds both financial reports',
            $registry()===['available'=>false,'reason'=>'invalid_ledger']&&$daily()===['available'=>false,'reason'=>'invalid_aggregate']);
        $set($cap,'value_rub',100000);$set($tail,'value_rub',30001);
        $check('individually valid increments cannot conceal a conversation total above 100000',
            $registry()===['available'=>false,'reason'=>'invalid_ledger']);
        $set($tail,'value_rub',30000);
        $binding=$p->query('SELECT target,value_profile_id FROM max_channel_conversion_outbox WHERE id='.(int)$increment)->fetch(PDO::FETCH_ASSOC);
        $set($increment,'target',ModeledConversionValueContract::predictionKey('999999'));
        $check('a predictive key for another profile cannot enter either financial aggregate',
            $registry()===['available'=>false,'reason'=>'invalid_ledger']&&$daily()===['available'=>false,'reason'=>'invalid_aggregate']);
        $set($increment,'value_profile_id',null);
        $check('a predictive key with a NULL profile fails closed on native SQL as well as SQLite',
            $registry()===['available'=>false,'reason'=>'invalid_ledger']&&$daily()===['available'=>false,'reason'=>'invalid_aggregate']);
        $set($increment,'value_profile_id',$binding['value_profile_id']);$set($increment,'target',$binding['target']);
        $check('successful and rejected financial reads preserve all recorded values profiles and clocks',
            deliveryRegistryDataDigest($p)===$before&&!$p->inTransaction());
    }finally{
        if($p->inTransaction())$p->rollBack();
        foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DELETE FROM '.$table);
    }
}

function deliveryRegistryMoscowAssertions(PDO $p,callable $check):void
{
    $midnight=strtotime('2026-12-31T21:00:00Z');$auth=static function():void{};
    $key=str_repeat('moscow-calendar-fixture-',3);
    foreach([-1,0,1]as$i=>$offset)deliveryRegistryFixtureEvent($p,31000+$i,1,$midnight+$offset);
    $before=deliveryRegistryDataDigest($p);
    foreach([[-1,'2026-12-31',1,'2026-12-30T21:00:00Z','2026-12-31T21:00:00Z'],
        [0,'2027-01-01',1,'2026-12-31T21:00:00Z','2027-01-01T21:00:00Z'],
        [1,'2027-01-01',2,'2026-12-31T21:00:00Z','2027-01-01T21:00:00Z']]as[$offset,$date,$count,$from,$until]){
        foreach(['registry','reconciliation']as$kind){
            $reader=$kind==='registry'?new ModeledConversionDeliveryRegistry($p,$auth,$key):new ModeledConversionDailyReconciliationReader($p,$auth);
            $automatic=$reader->read([],$midnight+$offset);$explicit=$reader->read(['date'=>$date],$midnight+$offset);
            $events=$kind==='registry'?($automatic['summary']['events']??null):($automatic['calendar']['accruals']['events']??null);
            $check($kind.' uses Moscow midnight for default and explicit dates at offset '.$offset,
                ($automatic['available']??false)&&($explicit['available']??false)&&$automatic['date']===$date
                &&$automatic['timezone']==='Europe/Moscow'&&$events===$count
                &&$automatic['window']===['from'=>$from,'until_exclusive'=>$until]&&$automatic['window']===$explicit['window']);
        }
    }
    foreach([new ModeledConversionDeliveryRegistry($p,$auth,$key),new ModeledConversionDailyReconciliationReader($p,$auth)]as$reader)
        $check('next Moscow date stays invalid before midnight',($reader->read(['date'=>'2027-01-01'],$midnight-1)['reason']??null)==='invalid_request');
    $check('Moscow calendar reads preserve every UTC record value receipt and identity',deliveryRegistryDataDigest($p)===$before);
    // An old-day assignment can receive a new-day API receipt; do not shift either stored clock.
    $p->prepare("UPDATE max_channel_conversion_outbox SET state='processed',upload_id='calendar-receipt',upload_status='PROCESSED',claimed_at_unix=?,value_api_accepted_at_unix=?,value_processed_at_unix=?,value_receipt_basis='post_response' WHERE conversation_id=31000")
        ->execute([$midnight-1,$midnight,$midnight+1]);
    $before=deliveryRegistryDataDigest($p);$reader=new ModeledConversionDailyReconciliationReader($p,$auth);
    $prior=$reader->read(['date'=>'2026-12-31'],$midnight+2);$next=$reader->read(['date'=>'2027-01-01'],$midnight+2);
    $check('assignment claim and receipt keep their own calendar days across Moscow midnight',
        ($prior['available']??false)&&($next['available']??false)
        &&$prior['calendar']['accruals']['events']===1&&$prior['calendar']['upload_claims']['events']===1
        &&$prior['calendar']['api_receipts']['events']===0&&$prior['calendar']['processed']['events']===0
        &&$next['calendar']['accruals']['events']===2&&$next['calendar']['upload_claims']['events']===0
        &&$next['calendar']['api_receipts']['events']===1&&$next['calendar']['processed']['events']===1
        &&deliveryRegistryDataDigest($p)===$before);
    $check('reconciliation renders the explicit year-boundary instant in Moscow',
        str_contains(ModeledConversionDailyReconciliationView::render($next),'01.01.2027 00:00:02'));
    foreach(['max_channel_conversion_outbox','modeled_conversion_profile_versions','conversations','customer_channels','customers']as$table)$p->exec('DELETE FROM '.$table);
}

function deliveryRegistryAssertions(PDO $p,callable $check):void
{
    // The dedicated forecast fixture cleans its disposable schema before the ledger suite resets it.
    forecastPaymentAssertions($p,$check);
    deliveryRegistrySchema($p);
    dailyReconciliationAssertions($p,$check);
    deliveryPredictiveAndVisitAssertions($p,$check);
    deliveryRegistryValueCapAssertions($p,$check);
    deliveryRegistryMoscowAssertions($p,$check);
    $date='2026-11-02';$day=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Europe/Moscow'));
    $from=$day->getTimestamp();$until=$day->modify('+1 day')->getTimestamp();$now=$until+3600;
    $key=str_repeat('fixture-session-key-only-',3);$auth=static function():void{};
    for($id=10;$id<=66;$id++){
        $options=[];
        if($id===10)$options=['state'=>'accepted','legacy_clock'=>true,'name'=>'Турист <img src=x onerror=alert(1)>'];
        if($id===11)$options=['state'=>'processed'];
        if($id===12)$options=['state'=>'unknown','error'=>'upload_outcome_unknown'];
        if($id===13)$options=['origin'=>'owner_fortnight_review_v1','profile_at'=>$from-86400];
        if($id===14)$options=['bad_profile'=>true];
        deliveryRegistryFixtureEvent($p,$id,1,$from+$id,$options);
    }
    deliveryRegistryFixtureEvent($p,100,1,$from-1,['platform'=>'telegram']);
    deliveryRegistryFixtureEvent($p,100,2,$from+100,['value'=>833]);
    deliveryRegistryFixtureEvent($p,101,1,$from+101,['platform'=>'max']);
    deliveryRegistryFixtureEvent($p,101,2,$from+102,['value'=>833]);
    deliveryRegistryFixtureEvent($p,1,1,$from+1,['platform'=>'telegram']);
    deliveryRegistryFixtureEvent($p,2,1,$from+2,['platform'=>'max']);
    deliveryRegistryFixtureEvent($p,200,1,$from+200);
    $p->exec("UPDATE max_channel_conversion_outbox SET external_user_id='fixtureWrongPeer' WHERE conversation_id=200");
    deliveryRegistryFixtureEvent($p,201,1,$from+201);
    $p->exec("UPDATE max_channel_conversion_outbox SET counter_id='other' WHERE conversation_id=201");
    deliveryRegistryFixtureEvent($p,202,1,$from+202);
    $p->exec("UPDATE max_channel_conversion_outbox SET value_contract='foreign_contract' WHERE conversation_id=202");
    deliveryRegistryFixtureEvent($p,203,1,$from+203);
    $p->exec("UPDATE max_channel_conversion_outbox SET transport_target='foreign_target' WHERE conversation_id=203");
    deliveryRegistryFixtureEvent($p,204,1,$from+204);
    $p->exec("UPDATE max_channel_conversion_outbox SET project_key='foreign' WHERE conversation_id=204");
    deliveryRegistryFixtureEvent($p,205,1,$from+205,['test'=>1]);
    deliveryRegistryFixtureEvent($p,206,1,$from-1);
    deliveryRegistryFixtureEvent($p,207,1,$until);
    deliveryRegistryFixtureEvent($p,208,1,$from+208);
    $p->exec("UPDATE customer_channels SET external_chat_id='wrong-private-peer' WHERE id=208");
    $read=static fn(array $input=[]):array=>(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>$date]+$input,$now);
    $before=deliveryRegistryDataDigest($p);$first=$read();
    if(($first['available']??false)!==true){
        $reason=in_array($first['reason']??null,['invalid_request','invalid_cursor','storage_unavailable','invalid_ledger','read_limit'],true)?$first['reason']:'unknown';
        echo 'REGISTRY SYNTHETIC FIRST READ UNAVAILABLE reason='.$reason.PHP_EOL;
    }
    $check('registry is an actual recorded-value read with an explicit event-day basis',
        $first['available']&&$first['basis']==='saved_value_event_unix'&&$first['timezone']==='Europe/Moscow');
    $check('same-scope exact day summary counts sixty deltas and fifty-nine conversations beyond one page',
        $first['summary']['events']===60&&$first['summary']['conversations']===59&&$first['summary']['value_rub']===74166);
    $check('Moscow half-open midnight window preserves the correct UTC boundaries',
        $first['window']['from']===gmdate('Y-m-d\TH:i:s\Z',$from)&&$first['window']['until_exclusive']===gmdate('Y-m-d\TH:i:s\Z',$until));
    $check('history is a direct origin subset and not a different sending day',
        $first['summary']['historical_review']===['events'=>1,'conversations'=>1,'value_rub'=>1250]
        &&$first['summary']['live']['value_rub']===72916);
    $check('delivery cards are global summary values rather than the shown page rows',
        $first['summary']['delivery']['accepted_receipts']['events']===2
        &&$first['summary']['delivery']['accepted_receipts']['value_rub']===2500
        &&$first['summary']['delivery']['processed']['events']===1
        &&$first['summary']['delivery']['problem']['events']===1
        &&$first['summary']['delivery']['unresolved_identity']['events']===58);
    $check('first page is ten distinct conversations with a signed continuation',
        count($first['rows'])===10&&$first['pagination']['has_more']&&is_string($first['pagination']['next_cursor']));
    $all=[];$sum=0;$page=$first;$pages=0;
    do{
        ++$pages;foreach($page['rows']as$row){$all[$row['conversation_id']]=$row;$sum+=$row['period_value_rub'];}
        $cursor=$page['pagination']['next_cursor'];
        if($cursor!==null)$page=$read(['cursor'=>$cursor]);
    }while($cursor!==null&&$pages<10);
    $check('all pages reconcile exactly without duplicates or a four-dialogue ceiling',
        $pages===6&&count($all)===59&&$sum===$first['summary']['value_rub']);
    $check('period delta is separate from same-contract lifetime reserved value',
        $all['100']['period_value_rub']===833&&$all['100']['total_value_rub']===2083
        &&$all['100']['stage']==='specific_request'&&count($all['100']['events'])===2
        &&$all['100']['events'][0]['in_selected_day']===false);
    $check('several increments in one conversation remain one row with their actual deltas',
        $all['101']['period_value_rub']===2083&&count($all['101']['events'])===2
        &&array_column($all['101']['events'],'value_rub')===[1250,833]);
    $check('old API receipt keeps an explicitly unknown acknowledgement clock',
        $all['10']['events'][0]['api_receipt_present']===true&&$all['10']['events'][0]['api_accepted_at']===null);
    $check('processed row preserves its independently recorded lifecycle moments',
        $all['11']['events'][0]['processed_at']!==null&&$all['11']['events'][0]['api_accepted_at']!==null
        &&$all['11']['events'][0]['upload_status']==='PROCESSED');
    $check('grounds reference the exact bound saved profile rather than the latest profile',
        $all['100']['events'][0]['profile_id']!==$all['100']['events'][1]['profile_id']
        &&$all['100']['events'][0]['profile_saved_at']===gmdate('Y-m-d\TH:i:s\Z',$from-1)
        &&$all['100']['events'][1]['grounds_status']==='saved_stage_profile');
    $check('invalid frozen subject withholds grounds without fabricating another explanation',
        $all['14']['events'][0]['grounds_status']==='profile_unavailable'&&$all['14']['events'][0]['profile_saved_at']===null);
    $serialized=json_encode($all,JSON_THROW_ON_ERROR);
    $check('no advertising identifiers upload identifiers native peers or raw JSON enter the projection',
        !str_contains($serialized,'fixturePrivateClientIdentifier')&&!str_contains($serialized,'fixtureUploadId')
        &&!str_contains($serialized,'external_user_id')&&!str_contains($serialized,'profile_json'));
    $check('reader returns names as bounded data for the HTML boundary to escape',
        $all['10']['customer_name']==='Турист <img src=x onerror=alert(1)>');
    $max=$read(['platform'=>'max']);$tg=$read(['platform'=>'telegram']);
    $check('platform filters have independent exact denominators',
        $max['summary']['conversations']===30&&$tg['summary']['conversations']===29
        &&$max['summary']['value_rub']+$tg['summary']['value_rub']===$first['summary']['value_rub']);
    $check('owner test foreign counter contract target project and mismatched peers are excluded consistently',
        !array_intersect(array_keys($all),['1','2','200','201','202','203','204','205','206','207','208']));
    $empty=(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>'2026-10-01'],$now);
    $check('empty successful date is distinct from unavailable storage',
        $empty['available']&&$empty['summary']['events']===0&&$empty['rows']===[]&&!$empty['pagination']['has_more']);
    foreach([
        ['date'=>'2026-02-30'],['date'=>'2027-01-01'],['platform'=>'website'],['date'=>['2026-11-02']],['unexpected'=>'x']
    ]as$bad){
        $r=(new ModeledConversionDeliveryRegistry($p,$auth,$key))->read($bad,$now);
        $check('strict date channel and parameter admission rejects malformed or unsupported requests',$r===['available'=>false,'reason'=>'invalid_request']);
    }
    $cursor=$first['pagination']['next_cursor'];
    $parts=explode('.',$cursor);$legacy='v1.'.$parts[1].'.'.hash_hmac('sha256',$parts[1],$key);
    $check('a correctly signed old-calendar cursor cannot change the cohort during paging',
        ($read(['cursor'=>$legacy])['reason']??null)==='invalid_cursor');
    $check('edited signed cursor is rejected before releasing any result',
        ($read(['cursor'=>substr($cursor,0,-1).(str_ends_with($cursor,'0')?'1':'0')])['reason']??null)==='invalid_cursor');
    $check('cursor is bound to the selected platform',($read(['cursor'=>$cursor,'platform'=>'telegram'])['reason']??null)==='invalid_cursor');
    $check('cursor is bound to the selected day',
        ((new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>'2026-11-01','cursor'=>$cursor],$now)['reason']??null)==='invalid_cursor');
    $check('cursor is bound to the current actor session key',
        ((new ModeledConversionDeliveryRegistry($p,$auth,str_repeat('other-session-',4)))->read(['date'=>$date,'cursor'=>$cursor],$now)['reason']??null)==='invalid_cursor');
    $check('old continuation expires rather than silently refreshing the snapshot',
        ((new ModeledConversionDeliveryRegistry($p,$auth,$key))->read(['date'=>$date,'cursor'=>$cursor],$now+3601)['reason']??null)==='invalid_cursor');
    $check('all successful private reads leave canonical rows byte-identical',deliveryRegistryDataDigest($p)===$before&&!$p->inTransaction());
    deliveryRegistryFixtureEvent($p,300,1,$from+600);
    deliveryRegistryFixtureEvent($p,10,3,$from+601,['value'=>2917]);
    $continued=$read(['cursor'=>$cursor]);$fresh=$read();
    $check('continuation pins its original membership and financial summary when new rows arrive',
        $continued['summary']===$first['summary']&&$fresh['summary']['events']===62
        &&$fresh['summary']['value_rub']===$first['summary']['value_rub']+4167);
    $p->exec("UPDATE max_channel_conversion_outbox SET last_error='fixturePrivateUnclassifiedError' WHERE conversation_id=12");
    $page=$read();$rows=$page['rows'];while($page['pagination']['next_cursor']!==null){
        $page=$read(['cursor'=>$page['pagination']['next_cursor']]);$rows=[...$rows,...$page['rows']];
    }
    $found=null;foreach($rows as$row)if($row['conversation_id']==='12')$found=$row['events'][0];
    $check('unrecognized transport details never become a public error label',
        $found['error']==='unclassified_error'&&!str_contains(json_encode($rows,JSON_THROW_ON_ERROR),'fixturePrivateUnclassifiedError'));
    $revoked=false;$outside=0;
    try{(new ModeledConversionDeliveryRegistry($p,static function()use($p,&$outside):void{
        if(!$p->inTransaction()&&++$outside>1)throw new DomainException('fixture_revoked');
    },$key))->read(['date'=>$date],$now);}catch(DomainException $ignored){$revoked=true;}
    $check('fresh authorization after the read transaction can revoke the entire private result',$revoked&&!$p->inTransaction());
    $time=0.0;$limited=(new ModeledConversionDeliveryRegistry($p,$auth,$key,static function()use(&$time):float{return $time+=2.0;}))->read(['date'=>$date],$now);
    $check('bounded execution fails closed and releases its read transaction',
        $limited===['available'=>false,'reason'=>'read_limit']&&!$p->inTransaction());
    // Native case-insensitive column collations must not admit enum variants on an unseen page.
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='Interest' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    $caseVariant=$read();
    $check('capitalized stage on a later page invalidates first-page totals on every SQL collation',
        $caseVariant===['available'=>false,'reason'=>'invalid_ledger']&&!$p->inTransaction());
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='interest' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    // Conversation 10 is below the first page; a bad row must invalidate GLOBAL totals before any page is released.
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='unknown_stage' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    $offPageInvalid=$read();
    $check('invalid stage on a later page withholds the first page and its complete-day totals',
        $offPageInvalid===['available'=>false,'reason'=>'invalid_ledger']&&!$p->inTransaction());
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='interest' WHERE conversation_id=10 AND target='anytour_value_1_v1'");
    $p->exec("UPDATE max_channel_conversion_outbox SET value_stage='unknown_stage' WHERE conversation_id=300");
    $invalid=$read();
    $check('an invalid recorded stage does not get silently converted into a valid award',
        $invalid===['available'=>false,'reason'=>'invalid_ledger']&&!$p->inTransaction());
    $p->exec('DROP TABLE max_channel_conversion_outbox');$missing=$read();
    $check('storage failure is unavailable rather than an empty successful day',$missing===['available'=>false,'reason'=>'storage_unavailable']);
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $passed=0;$check=static function(string $name,bool $ok)use(&$passed):void{
        if(!$ok)throw new RuntimeException('FAIL '.$name);++$passed;echo 'PASS '.$name.PHP_EOL;
    };
    deliveryRegistryAssertions($p,$check);echo 'MODELED DELIVERY REGISTRY PASSED '.$passed.PHP_EOL;
}
