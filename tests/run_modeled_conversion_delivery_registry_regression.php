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

    // Failed uploads are diagnosed without changing terminal delivery state or authorizing a replay.
    $auditNow=$day+23*86400;$failed=$a;$failed['state']='unmatched';$failed['upload_status']='LINKAGE_FAILURE';
    $failed['event_unix']=$day+20*86400;$failed['claimed_at_unix']=$day+22*86400;
    $snapshot=serialize($failed);$noGoals=['status'=>'ok','visits'=>[]];
    $uploadReceipt=['create_time'=>gmdate('Y-m-d\TH:i:s\Z',$failed['claimed_at_unix'])];
    $oldVisit=['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$day+11*86400),'visits'=>1,'source_class'=>'yandex_direct']]];
    $audit=ModeledConversionVisitProof::failureAudit($failed,$uploadReceipt,$oldVisit,$noGoals,[$failed],$auditNow);
    $check('a visit eligible at the signal can have expired by the original upload without implying a provider fault',
        $audit['eligible_at_signal']===true&&$audit['eligible_at_upload']===false
        &&$audit['diagnosis']==='outside_upload_window'&&$audit['upload_clock']==='provider');
    $recent=['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$day+19*86400),'visits'=>1]]];
    $audit=ModeledConversionVisitProof::failureAudit($failed,$uploadReceipt,$recent,$noGoals,[$failed],$auditNow);
    $check('an eligible original visit with no reported goal remains unexplained rather than credited',
        $audit['eligible_at_upload']===true&&$audit['diagnosis']==='eligible_unexplained'
        &&$audit['reported_goal_reaches']===0&&$audit['report_comparison']==='processed_only');
    $allGoals=['status'=>'ok','visits'=>[['started_at'=>gmdate('c',$day+19*86400),'visits'=>1,
        'goal_reaches'=>1,'revenue_rub'=>(int)$failed['value_rub']]]];
    $audit=ModeledConversionVisitProof::failureAudit($failed,$uploadReceipt,$recent,$allGoals,[$failed],$auditNow);
    $check('reported count and revenue despite a failure receipt are an explicit contradiction and never a resend instruction',
        $audit['diagnosis']==='reported_despite_failure'&&$audit['report_comparison']==='all_uploads'
        &&serialize($failed)===$snapshot&&!array_key_exists('client_id',$audit)&&!array_key_exists('outbox_id',$audit));
    $collision=$failed;$collision['id']='99';$collision['upload_id']='599';
    $audit=ModeledConversionVisitProof::failureAudit($failed,$uploadReceipt,$recent,$noGoals,[$failed,$collision],$auditNow);
    $check('same ClientID target and second across distinct accepted rows is exposed before any possible correction',
        $audit['wire_collision_rows']===1&&$audit['diagnosis']==='wire_collision');
    $badRows=ModeledConversionVisitProof::failureAudit($failed,['source_quantity'=>1,'line_quantity'=>0]+$uploadReceipt,
        $recent,$noGoals,[$failed],$auditNow);
    $check('an original source row rejected during validation is separate from a missing visit',
        $badRows['source_rows']===1&&$badRows['validated_rows']===0&&$badRows['diagnosis']==='invalid_receipt_rows');
    $wireFirst=$failed;$wireSecond=$collision;$wireSecond['state']='processed';$wireSecond['upload_status']='PROCESSED';$wireSecond['value_rub']=23;
    $wireSnapshot=serialize([$wireFirst,$wireSecond]);
    $plan=ModeledConversionWireValue::plan([$wireSecond,$wireFirst],$auditNow);
    $check('wire correction preserves both original allocations but produces one stable total and provider key',
        $plan['total_rub']===40&&$plan['event']['event_unix']===$wireFirst['event_unix']
        &&$plan['event']['client_id']===$wireFirst['client_id']
        &&serialize([$wireFirst,$wireSecond])===$wireSnapshot
        &&$plan===ModeledConversionWireValue::plan([$wireFirst,$wireSecond],$auditNow));
    $wireCalls=[];$wireSourceAt=$day+19*86400;
    $wireTransport=static function(string $method,string $url,mixed $body)use(&$wireCalls,&$wireSourceAt):array{
        $wireCalls[]=[$method,$url,$body];parse_str((string)parse_url($url,PHP_URL_QUERY),$q);
        $path=(string)parse_url($url,PHP_URL_PATH);
        if($method==='GET'&&str_ends_with($path,'/98615635'))return ['http'=>200,'error'=>0,'body'=>json_encode(['counter'=>['id'=>'98615635','time_zone_name'=>'UTC']])];
        if($method==='GET'&&$path==='/stat/v1/data'){
            preg_match("/clientID=='([0-9]+)'/",$q['filters'],$match);
            return ['http'=>200,'error'=>0,'body'=>json_encode(['query'=>['ids'=>[98615635],'metrics'=>['ym:s:visits'],
                'dimensions'=>explode(',',$q['dimensions']),'filters'=>$q['filters']],'sampled'=>false,'total_rows'=>1,
                'data'=>[['dimensions'=>array_merge([['name'=>$match[1]],['name'=>gmdate('Y-m-d H:i:s',$wireSourceAt)]],
                    count(explode(',',$q['dimensions']))===4?[['id'=>'ad'],['id'=>'ya_direct']]:[]),'metrics'=>[1]]]])];
        }
        return ['http'=>200,'error'=>0,'body'=>json_encode(['uploading'=>['id'=>'5999','status'=>'UPLOADED',
            'source_quantity'=>1,'line_quantity'=>1,'comment'=>$q['comment']]])];
    };
    // Source dimensions may omit undefined values. They must never own visit existence.
    $sourceNow=$auditNow;$sourceBase=[];$sourceDetails=[];$sourceUnavailable=false;$sourceMethods=[];$sourceQueries=[];
    $sourceTransport=static function(string $method,string $url,mixed $body)use(&$sourceBase,&$sourceDetails,&$sourceUnavailable,&$sourceMethods,&$sourceQueries):array{
        $sourceMethods[]=$method;$path=(string)parse_url($url,PHP_URL_PATH);
        if(str_ends_with($path,'/98615635'))return ['http'=>200,'error'=>0,'body'=>json_encode(['counter'=>['id'=>'98615635','time_zone_name'=>'UTC']])];
        parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$sourceQueries[]=$q;$isSource=count(explode(',',$q['dimensions']))===4;
        if($isSource&&$sourceUnavailable)return ['http'=>503,'error'=>0,'body'=>'PRIVATE_SOURCE_ONLY_FAILURE'];
        $rows=[];foreach($isSource?$sourceDetails:$sourceBase as$v){
            $dims=[['name'=>'900000000000000001'],['name'=>gmdate('Y-m-d H:i:s',$v['at'])]];
            if($isSource)$dims=array_merge($dims,[['id'=>($v['direct']??false)?'ad':'direct'],['id'=>($v['direct']??false)?'ya_direct':null]]);
            $rows[]=['dimensions'=>$dims,'metrics'=>[$v['visits']??1]];
        }
        return ['http'=>200,'error'=>0,'body'=>json_encode(['query'=>['ids'=>[98615635],'metrics'=>['ym:s:visits'],
            'dimensions'=>explode(',',$q['dimensions']),'filters'=>$q['filters']],'sampled'=>false,'total_rows'=>count($rows),'data'=>$rows])];
    };
    $sourceApi=new MetrikaOfflineClient('98615635','SYNTHETIC_TOKEN',$sourceTransport);
    $sourceBase=[['at'=>$sourceNow-86400]];$sourceDetails=[];
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('a real fresh visit remains eligible for the conservative three-day window when advertising labels are absent',
        count($retainedVisits['visits'])===1&&$retainedVisits['visits'][0]['source_class']==='other_or_unknown'
        &&ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']&&count($sourceQueries)===1);
    $sourceBase=[['at'=>$sourceNow-4*86400],['at'=>$sourceNow-8*86400]];
    $sourceDetails=[['at'=>$sourceNow-8*86400,'direct'=>true]];
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('an older visible Direct visit cannot displace the more recent visit omitted by source grouping',
        count($retainedVisits['visits'])===2&&!ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']
        &&$retainedVisits['visits'][0]['source_class']==='other_or_unknown');
    $sourceBase=[['at'=>$sourceNow-9*86400]];$sourceDetails=[['at'=>$sourceNow-9*86400,'direct'=>true]];
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('exact date and visit-count matched Direct evidence grants the ten-day extension',
        ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']
        &&$retainedVisits['visits'][0]['source_class']==='yandex_direct');
    $sourceUnavailable=true;
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('source-only failure retains real visits without promoting them to Direct',
        count($retainedVisits['visits'])===1&&!ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']
        &&$retainedVisits['visits'][0]['source_class']==='other_or_unknown');
    $sourceUnavailable=false;$sourceDetails=[['at'=>$sourceNow-9*86400,'direct'=>true],['at'=>$sourceNow-9*86400,'direct'=>false]];
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('ambiguous source rows cannot grant an extended window and all enrichment requests are GET-only',
        !ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']
        &&array_values(array_unique($sourceMethods))===['GET']);

    $sourceBase=[['at'=>$sourceNow-9*86400,'visits'=>2]];$sourceDetails=[['at'=>$sourceNow-9*86400,'direct'=>true,'visits'=>1]];
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('partial source coverage of a multi-visit second cannot grant ten days',
        $retainedVisits['visits'][0]['visits']===2&&!ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']);
    $sourceBase=[['at'=>$sourceNow-9*86400]];$sourceDetails=[['at'=>$sourceNow-9*86400,'direct'=>true],['at'=>$sourceNow-4*86400,'direct'=>false]];
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('incoherent source-only rows cannot create visits or promote an older base visit',
        count($retainedVisits['visits'])===1&&!ModeledConversionAttributionWindow::assess($retainedVisits,$sourceNow,$sourceNow)['allowed']);
    $sourceDetails=[['at'=>$sourceNow-9*86400,'direct'=>true]];
    $readsBefore=count($sourceQueries);$budgetRefused=false;
    try{$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow,static function():void{throw new RuntimeException('fixture_read_budget');});}
    catch(RuntimeException $e){$budgetRefused=$e->getMessage()==='fixture_read_budget';}
    $check('the shared admission refuses the optional source request before it reaches transport',
        $budgetRefused&&count($sourceQueries)===$readsBefore+1&&count(explode(',',$sourceQueries[count($sourceQueries)-1]['dimensions']))===2);
    $sourceBase=[];$sourceDetails=[['at'=>$sourceNow-86400,'direct'=>true]];$readsBefore=count($sourceQueries);
    $retainedVisits=$sourceApi->modeledAttributionVisits('900000000000000001',$sourceNow,$sourceNow);
    $check('source evidence cannot invent a missing canonical visit or trigger an unneeded request',
        $retainedVisits['status']==='ok'&&$retainedVisits['visits']===[]&&count($sourceQueries)===$readsBefore+1);

    $wireApi=new MetrikaOfflineClient('98615635','SYNTHETIC_TOKEN',$wireTransport);
    $repair=$wireApi->uploadModeledWireCorrection([$wireFirst,$wireSecond],$auditNow);
    $wirePosts=array_values(array_filter($wireCalls,static fn(array $c):bool=>$c[0]==='POST'));$csvLines=explode("\n",trim($wirePosts[0][2]));$csvRow=str_getcsv($csvLines[1]);
    $check('explicit correction sends one CSV row with the sum and identical ClientID target and second',
        $repair['outcome']==='accepted'&&count($wirePosts)===1&&$wirePosts[0][0]==='POST'
        &&count($csvLines)===2&&$csvRow[0]===$wireFirst['client_id']
        &&$csvRow[1]===ModeledConversionValueContract::TARGET
        &&(int)$csvRow[2]===$wireFirst['event_unix']&&(int)$csvRow[3]===40);
    foreach(['duplicate','foreign_client','other_second','unknown']as$case){
        $bad=$wireSecond;if($case==='duplicate')$bad=$wireFirst;
        if($case==='foreign_client')$bad['client_id']='900000000000000009';
        if($case==='other_second')$bad['event_unix']++;
        if($case==='unknown')$bad['state']='unknown';
        $blocked=false;try{ModeledConversionWireValue::plan([$wireFirst,$bad],$auditNow);}catch(InvalidArgumentException $e){$blocked=true;}
        $check('a correction cannot mix or replay ambiguous members: '.$case,$blocked);
    }
    $blocked=false;try{$wireApi->uploadModeledWireCorrection([$wireFirst,$wireSecond],$wireFirst['event_unix']+22*86400);}
    catch(InvalidArgumentException $e){$blocked=true;}
    $check('historical correction evidence remains readable but an expired POST is refused before transport',
        $blocked&&count(array_filter($wireCalls,static fn(array $c):bool=>$c[0]==='POST'))===1&&ModeledConversionWireValue::plan([$wireFirst,$wireSecond],$wireFirst['event_unix']+22*86400)['total_rub']===40);
    $wireSourceAt=$auditNow-17*86400;
    $refused=$wireApi->uploadModeledWireCorrection([$wireFirst,$wireSecond],$auditNow);
    $check('a visit inside the obsolete twenty-one day window but outside both special-mode windows cannot cause another POST',
        $refused===['outcome'=>'rejected','reason'=>'metrika_visit_window']
        &&count(array_filter($wireCalls,static fn(array $c):bool=>$c[0]==='POST'))===1);
    $wireGoal=['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\TH:i:s\Z',$day+19*86400),
        'visits'=>1,'goal_reaches'=>1,'revenue_rub'=>40]]];
    $wireAudit=ModeledConversionVisitProof::failureAudit($wireFirst,$uploadReceipt,$recent,$wireGoal,[$wireFirst,$wireSecond],$auditNow,'processed');
    $check('one provider goal reconciles all original wire allocations by total without demanding duplicate goals',
        $wireAudit['known_wire_reaches']===1&&$wireAudit['known_wire_rub']===40
        &&$wireAudit['report_comparison']==='all_wire_value'&&$wireAudit['diagnosis']==='wire_value_reported'
        &&$wireAudit['wire_correction_status']==='processed');
    $isolated=sys_get_temp_dir().'/wire-journal-fixture-'.bin2hex(random_bytes(8));
    mkdir($isolated,0700);mkdir($isolated.'/app',0700);mkdir($isolated.'/app/services',0700);
    mkdir($isolated.'/max-search-bot-modeled-visit-proof',0700);
    copy(__DIR__.'/../services/ModeledConversionWireValue.php',$isolated.'/app/services/ModeledConversionWireValue.php');
    foreach(['MetrikaOfflineClient','ModeledConversionValueContract','ModeledConversionAttributionWindow']as$name)
        file_put_contents($isolated.'/app/services/'.$name.'.php','<?php require_once '.var_export(realpath(__DIR__.'/../services/'.$name.'.php'),true).';');
    copy(__DIR__.'/../services/ModeledConversionVisitProof.php',$isolated.'/app/services/ModeledConversionVisitProof.php');
    $fixtureScript= <<<'PHP'
<?php
require __FIXTURE_ROOT__.'/app/services/ModeledConversionWireValue.php';
$rows=json_decode(__FIXTURE_ROWS__,true,32,JSON_THROW_ON_ERROR);$now=__FIXTURE_NOW__;
foreach($rows as&$member){$member['value_api_accepted_at_unix']=(int)$member['event_unix']+10;$member['value_processed_at_unix']=$member['state']==='processed'?(int)$member['event_unix']+20:null;}unset($member);
$plan=ModeledConversionWireValue::plan($rows,$now);$r=$rows[0];
$j=['version'=>'modeled_wire_correction_v1','wire_key'=>$plan['wire_key'],'members'=>$plan['members'],
'total_rub'=>$plan['total_rub'],'comment'=>$plan['comment'],'state'=>'sending','upload_id'=>null,'upload_status'=>null,
'sent_at_unix'=>$now-301,'checked_at_unix'=>$now-301];
ModeledConversionWireValue::save($r,$j);
if(ModeledConversionWireValue::read($r)!==$j)throw new RuntimeException('intent_not_durable');
$api=new class($plan,$rows){public int $reads=0;public int $writes=0;public function __construct(private array $plan,private array $rows){}
public function findModeledWireCorrection($members,$now){$this->reads++;return ['id'=>'5999','status'=>'UPLOADED','source_quantity'=>1,'line_quantity'=>1,'comment'=>$this->plan['comment']];}
public function poll($id){$this->reads++;if($id!=='5999')foreach($this->rows as$r)if($r['upload_id']===$id)return ['id'=>$id,'status'=>$r['upload_status'],'source_quantity'=>1,'line_quantity'=>1,'comment'=>MetrikaOfflineClient::comment($r)];return ['id'=>$id,'status'=>'PROCESSED','source_quantity'=>1,'line_quantity'=>1,'comment'=>$this->plan['comment']];}
public function modeledValueVisits($cid,$activation,$now){return ['status'=>'ok','visits'=>[['started_at'=>gmdate('Y-m-d\\TH:i:s\\Z',(int)$this->rows[0]['event_unix']-60),'visits'=>1,'goal_reaches'=>1,'revenue_rub'=>40]]];}
public function uploadModeledWireCorrection($members,$now){$this->writes++;throw new RuntimeException('forbidden');}};
$budgetRefused=false;try{ModeledConversionWireValue::observe($r,$rows,$api,$now,static function():void{throw new RuntimeException('budget');});}catch(RuntimeException $e){$budgetRefused=true;}
$first=ModeledConversionWireValue::observe($r,$rows,$api,$now);
$again=ModeledConversionWireValue::observe($r,$rows,$api,$now);
$last=ModeledConversionWireValue::observe($r,$rows,$api,$now+301);
$read=ModeledConversionWireValue::read($r);
$noReplay=$api->reads===2&&$api->writes===0;
$proof=ModeledConversionVisitProof::verify($rows,$api,(int)$r['event_unix']-1,(int)$r['event_unix']+5,$now+301,[(string)$rows[1]['id']]);
$expectedTotal=count($proof['events'])===1&&$proof['events'][0]['linkage']==='unique_visit_reconciled'
&&$proof['events'][0]['expected_goal_reaches']===1&&$proof['events'][0]['expected_goal_value_rub']===40.0;
$ordinary=$rows[1];$ordinary['id']='999';$ordinary['upload_id']='9999';$ordinary['client_id']='900000000000000009';
$ordinary['target']=ModeledConversionValueContract::predictionKey('999');$ordinary['value_profile_id']='999';
$selection=ModeledConversionVisitProof::dueIds([$ordinary,...$rows],(int)$r['event_unix']+5,$now+602,
static fn(array $item):array=>['at'=>$now-400,'event'=>['upload_status'=>$item['id']==='999'?'UPLOADED':$item['upload_status'],
'linkage'=>'failed','failure_audit'=>['diagnosis'=>'wire_collision']]],2);
$repairPriority=count($selection)===2&&$selection[0]===(string)$rows[0]['id']&&in_array('999',$selection,true);
$path=__FIXTURE_ROOT__.'/max-search-bot-modeled-visit-proof/wire-corrections/'.$plan['wire_key'].'.json';
$private=(fileperms($path)&0777)===0600&&(fileperms(dirname($path))&0777)===0700;
chmod($path,0644);clearstatcache(true,$path);$blocked=false;
try{ModeledConversionWireValue::read($r);}catch(RuntimeException $e){$blocked=true;}
echo json_encode(['ok'=>$first==='accepted'&&$again==='accepted'&&$last==='processed'
&&$read['state']==='processed'&&$noReplay&&$api->writes===0&&$budgetRefused&&$expectedTotal&&$repairPriority&&$private&&$blocked],JSON_THROW_ON_ERROR);
PHP;
    $fixtureScript=str_replace(['__FIXTURE_ROOT__','__FIXTURE_ROWS__','__FIXTURE_NOW__'],
        [var_export($isolated,true),var_export(json_encode([$wireFirst,$wireSecond],JSON_THROW_ON_ERROR),true),(string)$auditNow],$fixtureScript);
    try{
        $process=proc_open([PHP_BINARY,'-d','display_errors=stderr'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('wire_fixture_process');
        fwrite($pipes[0],$fixtureScript);fclose($pipes[0]);$result=stream_get_contents($pipes[1],2048);fclose($pipes[1]);
        $error=stream_get_contents($pipes[2],2048);fclose($pipes[2]);$code=proc_close($process);
        $check('wire evidence keeps original allocations, one reported goal, bounded repair priority and lost-ack GET recovery',
            $code===0&&json_decode($result,true)===['ok'=>true]&&$error==='');
    }finally{
        $remove=function(string $dir)use(&$remove):void{foreach(new DirectoryIterator($dir)as$f){
            if($f->isDot())continue;if($f->isDir()&&!$f->isLink())$remove($f->getPathname());else unlink($f->getPathname());
        }rmdir($dir);};$remove($isolated);
    }

    $validateAudit=new ReflectionMethod(ModeledConversionVisitProof::class,'validatedFailureAudit');
    $check('anonymous audit accepts only its exact scalar contract',$validateAudit->invoke(null,$audit,$auditNow)===$audit);
    $foreign=$audit;$foreign['client_id']='PRIVATE';
    $check('an injected provider or customer identifier cannot cross the private MCP projection',$validateAudit->invoke(null,$foreign,$auditNow)===null);
    $unknown=ModeledConversionVisitProof::failureAudit($failed,$uploadReceipt,['status'=>'unavailable'],$noGoals,[$failed],$auditNow);
    $check('unavailable visit evidence stays unknown instead of becoming absence or expiry',
        $unknown['diagnosis']==='unavailable'&&$unknown['eligible_at_upload']===null);

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
    for($i=40;$i>0;$i--){$r=$a;$r['id']=(string)$i;$r['value_api_accepted_at_unix']=$day+600;$r['upload_status']='UPLOADED';$queue[]=$r;}
    $selection=ModeledConversionVisitProof::dueIds($queue,$day+500,$now,static function(array $r)use($now):array{
        return $r['id']==='40'?['at'=>$now-400,'event'=>['linkage'=>'not_checked']]:[];
    },2);
    $check('a bounded scheduled batch includes the freshest row and an older never-checked row',$selection===['40','39']);
    $checked=ModeledConversionVisitProof::dueIds($queue,$day+500,$now,static fn(array $r):array=>[
        'at'=>$now-500,'event'=>['linkage'=>'unique_visit_reconciled','upload_status'=>'PROCESSED']],2);
    $check('terminal visit evidence is not repeatedly queried every five minutes',$checked===[]);
    $finished=$queue[35];$finished['upload_status']='PROCESSED';$finished['state']='processed';
    $legacyFinished=$legacy;$legacyFinished['value_api_accepted_at_unix']=$day+600;
    $priority=ModeledConversionVisitProof::dueIds([$queue[0],$finished,$legacyFinished],$day+500,$now,
        static fn(array $r):array=>['at'=>$now-1,'event'=>['upload_status'=>'UPLOADED','linkage'=>'not_checked']],2);
    $check('newly completed receipts precede repeated pending reads and include legacy goal rows',
        $priority===[(string)$finished['id'],(string)$legacyFinished['id']]);
    $historic=$queue[0];$historic['id']='41';$historic['upload_status']='PROCESSED';$historic['value_processed_at_unix']=$day+1;
    $fresh=$queue[0];$fresh['id']='42';
    $fair=ModeledConversionVisitProof::dueIds([$fresh,$historic,$finished],$day+500,$now,
        static fn(array $r):array=>$r['id']===(string)$finished['id']
            ?['at'=>$now-1,'event'=>['upload_status'=>'UPLOADED','linkage'=>'not_checked']]:[],2);
    $check('old unchecked processing history cannot consume the new-upload slot',
        $fair===[(string)$finished['id'],(string)$fresh['id']]);
    $deadlineCalls=count($api->calls);$deadlineBlocked=false;
    try{ModeledConversionVisitProof::verify([$a],$api,$day,$day+500,$now,null,microtime(true)-1);}catch(RuntimeException $ignored){$deadlineBlocked=true;}
    $check('expired verifier time budget stops before a provider receipt read',$deadlineBlocked&&count($api->calls)===$deadlineCalls);
    $retainedProof=ModeledConversionVisitProof::verify([$a],$api,$day,$day+500,$now,[],null,[(string)$a['id']=>$proof['events'][0]]);
    $check('stable original-cohort evidence remains available without repeating its requests',
        $retainedProof['events'][0]===$proof['events'][0]&&$retainedProof['selected_rows']===0&&count($api->calls)===$deadlineCalls);


    $api->mismatch=false;$api->duplicate=false;
    $explicitLegacy=ModeledConversionVisitProof::verify($ledger,$api,$day,$day+500,$now,[(string)$legacy['id']]);
    $legacyResult=[];foreach($explicitLegacy['events']as$e)if($e['outbox_id']===(string)$legacy['id'])$legacyResult=$e;
    $check('an explicit full-goal daily read can reconcile a legacy row without changing the frozen predictive cohort',
        ($legacyResult['linkage']??null)==='unique_visit_reconciled'&&$legacyResult['cohort']===false
        &&count(array_filter($explicitLegacy['events'],static fn(array $e):bool=>$e['cohort']))===2);

    $todayA=$a;$todayA['claimed_at_unix']=$day+3600;$todayA['value_api_accepted_at_unix']=$day+3601;
    $todayB=$b;$todayB['claimed_at_unix']=$day+4000;$todayB['value_api_accepted_at_unix']=$day+4001;
    $todayB['upload_status']='UPLOADED';$todayB['state']='accepted';
    $todayC=$a;$todayC['id']='4';$todayC['target']=ModeledConversionValueContract::predictionKey('4');$todayC['value_profile_id']='4';
    $todayC['upload_id']='504';$todayC['value_rub']=20;$todayC['claimed_at_unix']=$day+4100;
    $todayC['value_api_accepted_at_unix']=$day+4101;$todayC['upload_status']='LINKAGE_FAILURE';$todayC['state']='unmatched';
    $yesterday=$todayA;$yesterday['id']='5';$yesterday['claimed_at_unix']=$day+3599;$yesterday['value_processed_at_unix']=$now-1;
    $archived=static function(array $r)use($todayB,$todayC,$now):array{
        $e=['fingerprint'=>ModeledConversionVisitProof::fingerprint($r),'upload_status'=>$r['id']===$todayC['id']?'LINKAGE_FAILURE':'UPLOADED',
            'linkage'=>$r['id']===$todayC['id']?'failed':'not_checked'];
        return ['at'=>$now-1,'event'=>$e];
    };
    $daily=ModeledConversionVisitProof::dailySentCohort([$todayA,$todayB,$todayC,$yesterday],
        [(string)$todayA['id']=>$proof['events'][0]],$now,$archived);
    $m=$daily['by_platform']['max'];$tg=$daily['by_platform']['telegram'];
    $check('daily export report uses Kaliningrad midnight and excludes an earlier send processed today',
        $daily['date']==='2026-11-02'&&$daily['timezone']==='Europe/Kaliningrad'
        &&$daily['window']['from']===gmdate('Y-m-d\\TH:i:s\\Z',$day+3600)&&$m['sent']===['events'=>2,'conversations'=>1,'value_rub'=>37]);
    $check('receipt processing and exact visit evidence stay separate within the same sent cohort',
        $m['api_accepted']['value_rub']===37&&$m['processed']['value_rub']===17&&$m['visit_linked']['value_rub']===17
        &&$m['linkage_failed']['value_rub']===20&&$m['proof_missing']['events']===0);
    $check('Telegram has its own row and provider processing never becomes a confirmed visit',
        $tg['sent']===['events'=>1,'conversations'=>1,'value_rub'=>100000]
        &&$tg['api_accepted']['events']===1&&$tg['provider_processing']['events']===1&&$tg['visit_linked']['events']===0);
    $processedB=$todayB;$processedB['upload_status']='PROCESSED';$processedB['state']='processed';$processedB['value_processed_at_unix']=$now;
    $advanced=ModeledConversionVisitProof::dailySentCohort([$processedB],[],$now,$archived);
    $check('a newly persisted processing receipt survives an older archived intermediate status without inventing a visit',
        $advanced['by_platform']['telegram']['processed']['events']===1
        &&$advanced['by_platform']['telegram']['visit_linked']['events']===0
        &&$advanced['by_platform']['telegram']['unverified_visits']['events']===1);
    $reader=new ReflectionMethod(ModeledConversionVisitProof::class,'dailySummary');
    $summary=$reader->invoke(null,$daily,$now);$bad=$daily;$bad['by_platform']['max']['visit_linked']['value_rub']++;
    $check('private daily summary validates arithmetic before publishing cached values',
        $summary['available']&&$summary['fresh']&&!$reader->invoke(null,$bad,$now)['available']
        &&!$reader->invoke(null,null,$now)['available']);
    $check('daily cached summary becomes explicitly stale and contains no individual advertising identities',
        !$reader->invoke(null,$daily,$now+901)['fresh']
        &&!str_contains(json_encode($summary,JSON_THROW_ON_ERROR),'900000000000000001')
        &&!str_contains(json_encode($summary,JSON_THROW_ON_ERROR),'conversation_id'));
    $changedProof=$proof['events'][0];$changedProof['fingerprint']=str_repeat('a',64);
    $unbound=ModeledConversionVisitProof::dailySentCohort([$todayA],[(string)$todayA['id']=>$changedProof],$now,$archived);
    $check('a different event fingerprint cannot contribute to daily confirmed visit totals',
        $unbound['by_platform']['max']['visit_linked']['events']===0&&$unbound['by_platform']['max']['proof_missing']['events']===1);


    $cut=$day+5000;$beforeA=$todayA;$beforeA['value_processed_at_unix']=$day+4500;
    $afterB=$todayB;$afterB['value_processed_at_unix']=$day+7000;$afterB['state']='processed';$afterB['upload_status']='PROCESSED';
    $newer=$todayA;$newer['id']='6';$newer['claimed_at_unix']=$day+6000;$newer['value_rub']=19;
    $currentB=$proof['events'][1];$currentB['checked_at']=gmdate('c',$now);
    $follow=ModeledConversionVisitProof::followupReport([$beforeA,$afterB,$todayC,$newer],
        [(string)$afterB['id']=>$currentB],$cut,$now,$archived);
    $check('followup excludes later sends and receipts already processed at the original checkpoint',
        $follow['baseline_by_platform']['max']['sent']['value_rub']===37
        &&$follow['baseline_by_platform']['max']['processed_at_checkpoint']['value_rub']===17
        &&$follow['outcome']['by_platform']['max']['sent']['value_rub']===20
        &&$follow['outcome']['by_platform']['telegram']['visit_linked']['value_rub']===100000);
    $followReader=new ReflectionMethod(ModeledConversionVisitProof::class,'followupSummary');
    $followSafe=$followReader->invoke(null,$follow,$now);
    $badFollow=$follow;$badFollow['baseline_by_platform']['max']['sent']['value_rub']++;
    $check('followup arithmetic and original selection are validated before private disclosure',
        $followSafe['available']&&!$followReader->invoke(null,$badFollow,$now)['available']
        &&!str_contains(json_encode($followSafe,JSON_THROW_ON_ERROR),'conversation_id'));
    $failedRows=[];$failedProofs=[];
    for($i=0;$i<16;$i++){
        $r=$a;$r['id']=(string)(100+$i);$r['conversation_id']=(string)(700+$i);$r['upload_id']=(string)(800+$i);
        $r['event_unix']=$day+3900+$i;$r['claimed_at_unix']=$day+4000+$i;
        $r['state']='unmatched';$r['upload_status']='LINKAGE_FAILURE';$r['value_processed_at_unix']=null;
        $e=$proof['events'][0];$e['fingerprint']=ModeledConversionVisitProof::fingerprint($r);
        $e['upload_status']='LINKAGE_FAILURE';$e['linkage']='failed';$e['reason']='eligible_visit_but_provider_linkage_failed';
        $e['failure_audit']=ModeledConversionVisitProof::failureAudit($r,[],['status'=>'ok','visits'=>[]],
            ['status'=>'unavailable'],[$r],$now);
        $failedRows[]=$r;$failedProofs[$r['id']]=$e;
    }
    $failedFollow=ModeledConversionVisitProof::followupReport($failedRows,$failedProofs,$cut,$now,$archived);
    $failedSafe=$followReader->invoke(null,$failedFollow,$now);
    $check('the bounded private followup exposes every failed row up to sixteen without identifiers or changing delivery counts',
        $failedSafe['available']&&count($failedSafe['failure_examples'])===16
        &&$failedSafe['by_platform']['max']['linkage_failed']['events']===16
        &&$failedSafe['by_platform']['max']['linkage_failed']['value_rub']===16*17
        &&$failedSafe['failure_examples'][0]['audit']['diagnosis']==='no_reported_visits'
        &&!str_contains(json_encode($failedSafe,JSON_THROW_ON_ERROR),'client_id')
        &&!str_contains(json_encode($failedSafe,JSON_THROW_ON_ERROR),'upload_id'));

    $nextDay=$day+100000;$nextProof=$currentB;$nextProof['checked_at']=gmdate('c',$nextDay);
    $later=ModeledConversionVisitProof::followupReport([$beforeA,$afterB,$todayC,$newer],
        [(string)$afterB['id']=>$nextProof],$cut,$nextDay,$archived);
    $laterSafe=$followReader->invoke(null,$later,$nextDay);
    $check('an explicit followup retains the original calendar after midnight while evidence stays current',
        $laterSafe['available']&&$laterSafe['fresh']&&$laterSafe['date']==='2026-11-02'
        &&$laterSafe['by_platform']['telegram']['sent']['events']===1);

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
