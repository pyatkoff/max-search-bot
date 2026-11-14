<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ModeledConversionDailyReconciliationReader.php';

/** Disposable synthetic database only. Call last after the other fixture state checks. */
function modeledBacklogAssertions(PDO $p,callable $check):void
{
    foreach(['manager_prepared_replies','max_channel_conversion_outbox','conversations','customer_channels','customers']as$table)
        $p->exec('DROP TABLE IF EXISTS '.$table);
    $p->exec('CREATE TABLE customers(id BIGINT PRIMARY KEY)');
    $p->exec('CREATE TABLE customer_channels(id BIGINT PRIMARY KEY,customer_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_user_id VARCHAR(40),external_chat_id VARCHAR(40))');
    $p->exec('CREATE TABLE conversations(id BIGINT PRIMARY KEY,customer_id BIGINT,customer_channel_id BIGINT,project_key VARCHAR(40),channel VARCHAR(16),external_chat_id VARCHAR(40),is_test INT)');
    $p->exec('CREATE TABLE max_channel_conversion_outbox(id BIGINT PRIMARY KEY,project_key VARCHAR(40),platform VARCHAR(16),conversation_id BIGINT,external_user_id VARCHAR(40),counter_id VARCHAR(20),transport_target VARCHAR(64),value_contract VARCHAR(64),state VARCHAR(24),client_id VARCHAR(40),value_rub DECIMAL(12,2),event_unix BIGINT,next_attempt_unix BIGINT,value_ready_at_unix BIGINT,last_error VARCHAR(64),attempts INT,claim_token VARCHAR(64),claimed_at_unix BIGINT,upload_id VARCHAR(40),upload_status VARCHAR(32),value_receipt_basis VARCHAR(32),value_api_accepted_at_unix BIGINT,value_processed_at_unix BIGINT,tracker_observed_unix BIGINT)');
    foreach([111,333,444,555,666,2]as$id)$p->exec('INSERT INTO customers VALUES('.$id.')');
    $p->exec("INSERT INTO customer_channels VALUES
        (111,111,'anytour','max','111','-111'),(333,333,'anytour','telegram','333','333'),
        (444,999,'anytour','max','444','-444'),(555,555,'anytour','max','555','-555'),
        (666,666,'anytour','max','666','-666'),(2,2,'anytour','max','2','-2')");
    $p->exec("INSERT INTO conversations VALUES
        (111,111,111,'anytour','max','-111',0),(333,333,333,'anytour','telegram','333',0),
        (444,444,444,'anytour','max','-444',0),(555,555,555,'anytour','max','-555',1),
        (666,666,666,'anytour','max','wrong-current-chat',0),(2,2,2,'anytour','max','-2',0)");
    $now=1741089600; // Fixed synthetic clock, unrelated to live delivery.
    $columns=['id','project_key','platform','conversation_id','external_user_id','counter_id','transport_target','value_contract',
        'state','client_id','value_rub','event_unix','next_attempt_unix','value_ready_at_unix','last_error','attempts',
        'claim_token','claimed_at_unix','upload_id','upload_status','value_receipt_basis','value_api_accepted_at_unix',
        'value_processed_at_unix','tracker_observed_unix'];
    $insert=$p->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')');
    $base=['id'=>1,'project_key'=>'anytour','platform'=>'max','conversation_id'=>111,'external_user_id'=>'111',
        'counter_id'=>'98615635','transport_target'=>'anytour_modeled_value_v1','value_contract'=>ModeledConversionValueContract::VERSION,
        'state'=>'ready','client_id'=>'880000001','value_rub'=>'5.25','event_unix'=>$now-1800,
        'next_attempt_unix'=>0,'value_ready_at_unix'=>$now-600,'last_error'=>'','attempts'=>0,
        'claim_token'=>null,'claimed_at_unix'=>null,'upload_id'=>null,'upload_status'=>null,'value_receipt_basis'=>null,
        'value_api_accepted_at_unix'=>null,'value_processed_at_unix'=>null,'tracker_observed_unix'=>$now-2000];
    $append=static function(array $row)use($insert,$columns,$base):void{
        $row=array_replace($base,$row);$insert->execute(array_map(static fn(string $key):mixed=>$row[$key],$columns));
    };
    $p->beginTransaction();
    for($i=1;$i<=250;$i++)$append(['id'=>$i,'value_rub'=>$i%2===0?'10.10':'5.25',
        'next_attempt_unix'=>$i<=100?$now-1200:($i<=200?$now+600:0),
        'tracker_observed_unix'=>$i<=20?$now-864001:$now-2000,
        'last_error'=>$i<=75?'metrika_visit_unverified':($i<=150?'metrika_visit_window':($i<=175?'metrika_no_prior_visit':''))]);
    for($i=251;$i<=255;$i++)$append(['id'=>$i,'platform'=>'telegram','conversation_id'=>333,'external_user_id'=>'333',
        'state'=>'pending','client_id'=>null,'value_rub'=>'7.77','next_attempt_unix'=>$now-300,'value_ready_at_unix'=>null]);
    $append(['id'=>256,'state'=>'accepted','client_id'=>'880000002','value_rub'=>'12.34','upload_id'=>'220000001',
        'upload_status'=>'UPLOADED','attempts'=>1,'claimed_at_unix'=>$now-100,'value_receipt_basis'=>'post_response',
        'value_api_accepted_at_unix'=>$now-90]);
    $wrong=[
        ['project_key'=>'AnyTour'],['counter_id'=>'98615636'],['transport_target'=>'unrelated_goal'],
        ['value_contract'=>'other_contract'],['platform'=>'Max'],['conversation_id'=>444,'external_user_id'=>'444'],
        ['conversation_id'=>555,'external_user_id'=>'555'],['conversation_id'=>666,'external_user_id'=>'666'],
        ['conversation_id'=>2,'external_user_id'=>'2'],['external_user_id'=>'888'],
        ['state'=>'failed','value_rub'=>'0.00','last_error'=>'price_loss_reconciled'],
    ];
    foreach($wrong as$i=>$row)$append(['id'=>30000+$i]+$row);
    $p->commit();
    $snapshot=static function()use($p):string{
        $rows=[];foreach(['max_channel_conversion_outbox','conversations','customer_channels','customers']as$table)
            $rows[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
    };
    $before=$snapshot();$reader=new ModeledConversionDailyReconciliationReader($p,static function():void{});
    $r=$reader->readBacklog($now);
    $check('backlog covers every exact-owner row beyond the old 201 suffix',
        ($r['available']??false)&&($r['coverage']['inspected_rows']??null)===256
        &&($r['coverage']['complete_within_bound']??false)===true
        &&($r['total']['events']??null)===256&&($r['total']['conversations']??null)===2
        &&($r['total']['value_rub']??null)===1969.94&&($r['total']['stored_client_ids']??null)===2
        &&($r['by_platform']['max']['events']??null)===251
        &&($r['by_platform']['max']['value_rub']??null)===1931.09
        &&($r['by_platform']['telegram']['events']??null)===5
        &&($r['by_platform']['telegram']['value_rub']??null)===38.85);
    $q=$r['total']['queue']??[];
    $check('backlog separates due future and exact ten-minute queue age boundaries',
        ($q['waiting']['events']??null)===255&&($q['due']['events']??null)===155
        &&($q['held_until_later']['events']??null)===100
        &&($q['overdue_at_least_600_seconds']['events']??null)===150
        &&($q['oldest_due_wait_seconds']??null)===1200
        &&($q['ready_never_claimed']['events']??null)===250
        &&($q['ready_max_source_old_or_missing']??null)===20
        &&($q['saved_hold_reason_counts']['metrika_visit_unverified']??null)===75
        &&($q['saved_hold_reason_counts']['metrika_visit_window']??null)===75
        &&($q['saved_hold_reason_counts']['metrika_no_prior_visit']??null)===25);
    $check('backlog state dialogue counts retain overlap and no stored identifier escapes',
        ($r['by_state']['ready']['conversations']??null)===1
        &&($r['by_state']['accepted']['conversations']??null)===1
        &&($r['by_state']['pending']['conversations']??null)===1
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'880000001')
        &&!str_contains(json_encode($r,JSON_THROW_ON_ERROR),'220000001')
        &&($r['interpretation']['sender_rechecks_current_eligibility']??false)
        &&($r['interpretation']['no_ids_text_provider_requests_or_writes']??false)
        &&$snapshot()===$before&&!$p->inTransaction());
    $p->beginTransaction();$outer=$reader->readBacklog($now);
    $check('backlog refuses to adopt an existing transaction without rolling it back',
        ($outer['reason']??null)==='outer_transaction'&&$p->inTransaction());$p->rollBack();
    $calls=0;$denied=false;
    try{(new ModeledConversionDailyReconciliationReader($p,static function()use(&$calls):void{
        if(++$calls===3)throw new DomainException('synthetic_access_revoked');
    }))->readBacklog($now);}catch(DomainException $expected){$denied=true;}
    $check('backlog reauthorizes after copying and rolls back on revocation',
        $denied&&$calls===3&&!$p->inTransaction()&&$snapshot()===$before);
    $budget=new ModeledConversionStatusBudget();
    $budgeted=(new ModeledConversionDailyReconciliationReader($p,static function():void{},null,$budget))->readBacklog($now);
    $check('backlog uses the shared copied-byte budget for bounded scalar aggregates',
        ($budgeted['available']??false)&&$budget->remainingBytes()<ModeledConversionStatusBudget::MAX_BYTES
        &&$budget->remainingBytes()>40000&&strlen(json_encode($budgeted,JSON_THROW_ON_ERROR))<10000);
    $p->exec("UPDATE max_channel_conversion_outbox SET next_attempt_unix=-1 WHERE id=1");
    $invalid=$reader->readBacklog($now);
    $check('backlog rejects corrupt pending-ready clocks rather than hiding a due row',
        ($invalid['available']??true)===false&&($invalid['reason']??null)==='invalid_aggregate'&&!$p->inTransaction());
    $p->prepare('UPDATE max_channel_conversion_outbox SET next_attempt_unix=? WHERE id=1')->execute([$now-1200]);
    $check('backlog invalid aggregate read did not change any row',$snapshot()===$before);
    $p->beginTransaction();
    for($i=0;$i<9744;$i++)$append(['id'=>40000+$i,'value_rub'=>'1.01','next_attempt_unix'=>$now+3600]);
    $p->commit();
    $exact=$reader->readBacklog($now);
    $check('backlog exact ten-thousand bound remains complete',
        ($exact['available']??false)&&($exact['coverage']['inspected_rows']??null)===10000
        &&($exact['coverage']['complete_within_bound']??false)===true);
    $append(['id'=>50000]);$append(['id'=>50001]);
    $overflow=$reader->readBacklog($now);
    $check('backlog one-row sentinel explicitly marks overflow and never calls its suffix a total population',
        ($overflow['available']??false)&&($overflow['coverage']['inspected_rows']??null)===10001
        &&($overflow['coverage']['complete_within_bound']??true)===false
        &&($overflow['interpretation']['incomplete_bound_is_not_the_whole_backlog']??false));
}
