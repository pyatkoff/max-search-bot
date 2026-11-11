<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/run_modeled_conversion_value_export_regression.php';
require_once dirname(__DIR__).'/services/ModeledConversionDailyDeliveryReader.php';
require_once dirname(__DIR__).'/services/ModeledConversionDeliveryHealth.php';

function deliveryObservationFixture(PDO $p,int $now):void
{
    modeledValueSchema($p);
    $p->exec('DROP TABLE IF EXISTS modeled_conversion_profiles_state');
    $p->exec('CREATE TABLE modeled_conversion_profiles_state(project_key VARCHAR(64) PRIMARY KEY,enabled INT,last_error_at_unix BIGINT,last_error_category VARCHAR(40),last_receipt_json TEXT)');
    $p->prepare("INSERT INTO modeled_conversion_profiles_state VALUES('anytour',1,NULL,NULL,?)")
        ->execute([json_encode(['status'=>'idle','observed_at_unix'=>$now,'profiles_written'=>0],JSON_THROW_ON_ERROR)]);
    $report=['status'=>'ok','goal_id'=>'678187097','currency'=>'RUB','fetched_at'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
    $p->prepare("UPDATE modeled_conversion_value_export_settings SET mode='send',goal_id='678187097',goal_intent='verified',activated_at_unix=?,last_tick_unix=?,last_receipt_json=?,last_report_success_unix=? WHERE project_key='anytour'")
        ->execute([$now-3600,$now,json_encode(['errors'=>0,'metrika_report'=>$report],JSON_THROW_ON_ERROR),$now]);
}
function deliveryObservationInsert(PDO $p,array $v):void
{
    $base=['project_key'=>'anytour','counter_id'=>'98615635','conversation_id'=>101,'external_user_id'=>'70001',
        'target'=>'observation_fixture_'.($v['id']??1),'transport_target'=>'anytour_modeled_value_v1','value_contract'=>'semantic_stage_value_25000_v1',
        'platform'=>'max','state'=>'accepted','value_rub'=>1250,'value_stage'=>'interest','event_unix'=>1793000000,
        'upload_id'=>'5001','upload_status'=>'UPLOADED','claimed_at_unix'=>null,'value_origin'=>null,
        'value_api_accepted_at_unix'=>null,'value_processed_at_unix'=>null,'value_receipt_basis'=>null];
    $v=array_replace($base,$v);$keys=array_keys($v);
    $p->prepare('INSERT INTO max_channel_conversion_outbox('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($v),'?')).')')->execute(array_values($v));
}
function deliveryDailyAssertions(PDO $p,callable $check):void
{
    deliveryMoscowMidnightAssertions($p,$check);
    deliveryDailyValueCapAssertions($p,$check);
    $now=1793700000;$from=strtotime('2026-11-02T21:00:00Z');$until=$from+86400;deliveryObservationFixture($p,$now);
    deliveryObservationInsert($p,['id'=>1,'claimed_at_unix'=>$from,'value_api_accepted_at_unix'=>$from,'value_receipt_basis'=>'post_response']);
    deliveryObservationInsert($p,['id'=>2,'conversation_id'=>101,'value_rub'=>833,'value_stage'=>'specific_request','claimed_at_unix'=>$from+1,
        'value_api_accepted_at_unix'=>$from+2,'value_receipt_basis'=>'post_response','value_origin'=>'owner_day_review_v1']);
    deliveryObservationInsert($p,['id'=>3,'conversation_id'=>102,'platform'=>'telegram','value_rub'=>2083,'claimed_at_unix'=>$from-1,
        'value_api_accepted_at_unix'=>$from+3,'value_receipt_basis'=>'post_response']);
    deliveryObservationInsert($p,['id'=>4,'conversation_id'=>103,'claimed_at_unix'=>$from+4]);
    deliveryObservationInsert($p,['id'=>5,'conversation_id'=>104,'claimed_at_unix'=>$from-1,'value_processed_at_unix'=>$from+5,
        'value_receipt_basis'=>'get_reconciliation','state'=>'processed']);
    deliveryObservationInsert($p,['id'=>6,'conversation_id'=>105,'claimed_at_unix'=>$until,'value_api_accepted_at_unix'=>$until,
        'value_receipt_basis'=>'post_response']);
    deliveryObservationInsert($p,['id'=>7,'conversation_id'=>106,'project_key'=>'foreign','claimed_at_unix'=>$from+1]);
    deliveryObservationInsert($p,['id'=>8,'conversation_id'=>107,'counter_id'=>'999','claimed_at_unix'=>$from+1]);
    deliveryObservationInsert($p,['id'=>9,'conversation_id'=>108,'transport_target'=>'foreign','claimed_at_unix'=>$from+1]);
    $read=static fn():array=>(new ModeledConversionDailyDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($now);
    $before=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$r=$read();
    $check('daily boundary is Moscow midnight in UTC',$r['available']&&$r['date']==='2026-11-03'&&$r['timezone']==='Europe/Moscow'
        &&$r['window']['from']==='2026-11-02T21:00:00Z'&&$r['window']['until_exclusive']==='2026-11-03T21:00:00Z');
    $check('two increments have one exact distinct dialogue',$r['upload_claims_today']['events']===3&&$r['upload_claims_today']['conversations']===2&&$r['upload_claims_today']['value_rub']===3333);
    $check('new receipt observation day differs from prior claim day',$r['api_receipts_observed_today']['events']===3&&$r['api_receipts_observed_today']['conversations']===2&&$r['api_receipts_observed_today']['value_rub']===4166);
    $check('platform distinct and sums are independently counted',$r['api_receipts_observed_today']['by_platform']['telegram']['conversations']===1&&$r['api_receipts_observed_today']['by_platform']['telegram']['value_rub']===2083);
    $check('historical origin does not change delivery day or inflate distinct',$r['api_receipts_observed_today']['historical_review']['events']===1&&$r['api_receipts_observed_today']['historical_review']['value_rub']===833);
    $check('legacy receipt is not backdated into exact acceptance day',$r['legacy_receipts_with_claim_today']['events']===1&&$r['coverage']['api_receipts_without_observed_clock']['events']===2);
    $check('processed observation permits unknown legacy acceptance day',$r['processed_observed_today']['events']===1&&$r['processed_observed_today']['value_rub']===1250);
    $check('daily read preserves every stored row',$before===$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    for($i=20;$i<240;$i++)deliveryObservationInsert($p,['id'=>$i,'conversation_id'=>2000+$i,'claimed_at_unix'=>$from+10,'value_api_accepted_at_unix'=>$from+10,'value_receipt_basis'=>'post_response']);
    $r=$read();$check('exact daily totals are not truncated at old201 window',$r['api_receipts_observed_today']['events']===223&&$r['api_receipts_observed_today']['conversations']===222);
    $revoked=false;$calls=0;try{(new ModeledConversionDailyDeliveryReader($p,static function()use(&$calls):void{if(++$calls>=3)throw new DomainException();},new ModeledConversionStatusBudget()))->read($now);}catch(DomainException $ignored){$revoked=true;}
    $check('revoked authorization prevents release of daily sums',$revoked);
    deliveryObservationInsert($p,['id'=>300,'conversation_id'=>1300,'platform'=>'foreign','claimed_at_unix'=>$from+1]);
    $check('invalid platform fails closed rather than vanishing from totals',($read()['reason']??null)==='invalid_aggregate');
}
function deliveryDailyValueCapAssertions(PDO $p,callable $check):void
{
    $anchor=strtotime('2026-11-03T12:00:00Z');$now=$anchor+3600;deliveryObservationFixture($p,$now);
    foreach([[1,101,'max',25000],[2,101,'max',45000],[3,101,'max',30000],[4,102,'telegram',100000]]as[$id,$conversation,$platform,$value])
        deliveryObservationInsert($p,['id'=>$id,'conversation_id'=>$conversation,'platform'=>$platform,'value_rub'=>$value,
            'event_unix'=>$id===1?$anchor-86400:$anchor+$id,
            'state'=>'processed','upload_id'=>(string)(6000+$id),'upload_status'=>'PROCESSED',
            'claimed_at_unix'=>$anchor+$id,'value_api_accepted_at_unix'=>$anchor+$id,'value_processed_at_unix'=>$anchor+$id,
            'value_receipt_basis'=>'post_response']);
    $read=static fn():array=>(new ModeledConversionDailyDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($now);
    $before=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$r=$read();
    foreach(['upload_claims_today','api_receipts_observed_today','processed_observed_today']as$key)
        $check($key.' counts mixed increments and the 100000 boundary with independent dialogue and platform totals',
            ($r['available']??false)&&$r[$key]['events']===4&&$r[$key]['conversations']===2&&$r[$key]['value_rub']===200000
            &&$r[$key]['by_platform']['max']===['events'=>3,'conversations'=>1,'value_rub'=>100000]
            &&$r[$key]['by_platform']['telegram']===['events'=>1,'conversations'=>1,'value_rub'=>100000]);
    $check('large-value daily reads preserve the recorded ledger',
        $before===$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    $p->exec('UPDATE max_channel_conversion_outbox SET value_rub=100001 WHERE id=4');
    $before=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $check('one event above 100000 withholds every daily cohort without changing any stored row',
        $read()===['available'=>false,'reason'=>'invalid_aggregate']
        &&$before===$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}
function deliveryMoscowMidnightAssertions(PDO $p,callable $check):void
{
    $midnight=strtotime('2026-12-31T21:00:00Z');
    deliveryObservationFixture($p,$midnight+1);
    foreach([-1,0,1]as$i=>$offset)deliveryObservationInsert($p,['id'=>$i+1,'conversation_id'=>101+$i,
        'event_unix'=>$midnight+$offset,'claimed_at_unix'=>$midnight+$offset,
        'value_api_accepted_at_unix'=>$midnight+$offset,'value_receipt_basis'=>'post_response']);
    $before=$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach([[-1,'2026-12-31',1,'2026-12-30T21:00:00Z','2026-12-31T21:00:00Z'],
        [0,'2027-01-01',1,'2026-12-31T21:00:00Z','2027-01-01T21:00:00Z'],
        [1,'2027-01-01',2,'2026-12-31T21:00:00Z','2027-01-01T21:00:00Z']]as[$offset,$date,$count,$from,$until]){
        $r=(new ModeledConversionDailyDeliveryReader($p,static function():void{},new ModeledConversionStatusBudget()))->read($midnight+$offset);
        $check('Moscow year boundary selects exact current-day claims and receipts at offset '.$offset,
            ($r['available']??false)&&$r['date']===$date&&$r['timezone']==='Europe/Moscow'
            &&$r['window']['from']===$from&&$r['window']['until_exclusive']===$until
            &&$r['upload_claims_today']['events']===$count&&$r['api_receipts_observed_today']['events']===$count);
    }
    $check('calendar projection preserves original UTC clocks values receipts and identifiers',
        $before===$p->query('SELECT * FROM max_channel_conversion_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
}
function deliveryHealthAssertions(PDO $p,callable $check):void
{
    deliveryHealthPriceCorrectionAssertions($p,$check);
    $now=1793700000;deliveryObservationFixture($p,$now);
    $h=static fn():array=>ModeledConversionDeliveryHealth::collect($p,$now);
    $check('idle healthy pipeline with no new conversions stays healthy',$h()['status']==='ok');
    deliveryObservationInsert($p,['id'=>1,'state'=>'pending','upload_id'=>null,'last_error'=>'identity_not_found']);
    $check('missing advertising identity does not trigger technical incident',$h()['status']==='ok');
    $p->exec("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=".($now-901));
    $check('independent read detects stopped delivery cycle',in_array('delivery_tick_stale',$h()['flags'],true));
    $p->exec("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=$now,report_failure_since_unix=".($now-900));
    $check('continuous report failure is distinct from retained last success',in_array('report_unavailable',$h()['flags'],true)&&$h()['last_report_success_at']===gmdate('Y-m-d\TH:i:s\Z',$now));
    $p->exec("UPDATE modeled_conversion_value_export_settings SET report_failure_since_unix=NULL");
    deliveryObservationInsert($p,['id'=>2,'conversation_id'=>102,'state'=>'ready','upload_id'=>null,'value_ready_at_unix'=>$now-600]);
    $check('eligible ready work waiting two cycles triggers incident',in_array('ready_queue_stale',$h()['flags'],true));
    $p->exec("UPDATE max_channel_conversion_outbox SET next_attempt_unix=".($now+300)." WHERE id=2");
    $check('intentional retry delay is not eligible ready backlog',!in_array('ready_queue_stale',$h()['flags'],true));
    $p->exec("UPDATE max_channel_conversion_outbox SET next_attempt_unix=".($now-1)." WHERE id=2");
    $check('just eligible retry gets its full two-cycle grace',!in_array('ready_queue_stale',$h()['flags'],true));
    $p->exec("UPDATE max_channel_conversion_outbox SET state='unknown',claimed_at_unix=".($now-901)." WHERE id=2");
    $check('unresolved upload outcome is separately visible',in_array('upload_outcome_unknown',$h()['flags'],true));
    $p->exec("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=".($now+1));
    $check('concurrent next-second commit is not clock failure',$h()['status']!=='unavailable');
    $p->exec("UPDATE modeled_conversion_value_export_settings SET last_tick_unix=".($now+61));
    $check('material future clock fails health closed',$h()['flags']===['invalid_clock']);
    $p->exec("UPDATE modeled_conversion_value_export_settings SET mode='off'");
    $check('explicit disabled mode does not report stopped cron',$h()['status']==='off'&&$h()['flags']===[]);
}
function deliveryHealthPriceCorrectionAssertions(PDO $p,callable $check):void
{
    $now=1793700000;deliveryObservationFixture($p,$now);$scope=modeledValueBinding($p,1201);
    $priced=modeledExpertWrapper($scope,$now-2,70000);modeledValueSave($p,$priced);$profile=(string)$p->lastInsertId();
    $reserved=$priced['deal_value_prediction']['value_rub'];
    deliveryObservationInsert($p,['id'=>1,'conversation_id'=>1201,'external_user_id'=>'71201','event_unix'=>$now-2,
        'target'=>ModeledConversionValueContract::predictionKey($profile),'value_profile_id'=>$profile,'value_rub'=>$reserved]);
    $fallback=modeledUnknownPriceWrapper(modeledExpertWrapper($scope,$now-1,70000),true);modeledValueSave($p,$fallback);$profile=(string)$p->lastInsertId();
    $candidate=ModeledConversionValueContract::candidate($fallback,$scope,$now-3600,$now);
    if(!$candidate||$candidate['value']<=$reserved)throw new RuntimeException('fixture_price_correction_missing');
    deliveryObservationInsert($p,['id'=>2,'conversation_id'=>1201,'external_user_id'=>'71201','event_unix'=>$now-1,
        'target'=>ModeledConversionValueContract::predictionKey($profile),'value_profile_id'=>$profile,
        'value_rub'=>$candidate['value']-$reserved,'value_stage'=>$candidate['stage'],'state'=>'pending','upload_id'=>null,'upload_status'=>null]);
    (new ModeledConversionPriceContinuity($p))->reconcile($now,static fn(array $row):bool=>true);
    $void=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE id=2')->fetch(PDO::FETCH_ASSOC);
    $audit=$p->query('SELECT * FROM modeled_conversion_price_audit WHERE outbox_id=2')->fetch(PDO::FETCH_ASSOC);
    $check('canonical price correction retains a zero unattempted failed row and its void audit',
        $void&&$audit&&$void['state']==='failed'&&$void['last_error']==='price_loss_reconciled'
        &&(int)$void['value_rub']===0&&(int)$void['attempts']===0&&$audit['action']==='voided'
        &&(int)$audit['original_value_rub']===$candidate['value']-$reserved&&(int)$audit['applied_value_rub']===0);
    $snapshot=static function()use($p):array{
        $rows=[];foreach(['max_channel_conversion_outbox','modeled_conversion_price_audit','modeled_conversion_profile_versions',
            'modeled_conversion_value_export_settings','modeled_conversion_profiles_state']as$table)
            $rows[$table]=$p->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        return$rows;
    };
    $preserved=true;$h=static function()use($p,$now,$snapshot,&$preserved):array{
        $before=$snapshot();$result=ModeledConversionDeliveryHealth::collect($p,$now);
        $preserved=$preserved&&$before===$snapshot();return$result;
    };
    $check('audited never-uploaded cancellation is healthy without removing its ledger row',$h()['status']==='ok');
    $fields=['last_error','value_rub','attempts','claim_token','claimed_at_unix','upload_id','upload_status',
        'value_api_accepted_at_unix','value_processed_at_unix','value_receipt_basis'];
    $setRow=static function(array $changes=[])use($p,$void,$fields):void{
        $values=array_replace($void,$changes);
        $p->prepare('UPDATE max_channel_conversion_outbox SET '.implode(',',array_map(static fn(string $key):string=>$key.'=?',$fields)).' WHERE id=2')
            ->execute(array_map(static fn(string $key):mixed=>$values[$key],$fields));
    };
    $setRow(['claim_token'=>'','upload_status'=>'']);
    $check('producer-supported empty claim and upload status are still unattempted',$h()['status']==='ok');$setRow();
    $rowCases=[
        'a positive remaining amount'=>['value_rub'=>1], 'an unknown amount'=>['value_rub'=>null],
        'an ordinary API row rejection'=>['last_error'=>'api_row_rejected'], 'an absent reason'=>['last_error'=>''],
        'a differently cased reason'=>['last_error'=>'PRICE_LOSS_RECONCILED'], 'a padded reason'=>['last_error'=>'price_loss_reconciled '],
        'an upload attempt'=>['attempts'=>1], 'an outstanding claim'=>['claim_token'=>str_repeat('a',32)],
        'a padded claim'=>['claim_token'=>' '.str_repeat('a',31)], 'a recorded claim time'=>['claimed_at_unix'=>$now-1],
        'a provider upload ID'=>['upload_id'=>'9001'], 'an empty non-null upload ID'=>['upload_id'=>''],
        'a provider upload status'=>['upload_status'=>'PROCESSED'], 'a padded upload status'=>['upload_status'=>' '],
        'an accepted receipt time'=>['value_api_accepted_at_unix'=>$now-1], 'a processed receipt time'=>['value_processed_at_unix'=>$now-1],
        'a receipt provenance marker'=>['value_receipt_basis'=>'post_response']];
    if($p->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$rowCases['a null reason']=['last_error'=>null];
    foreach($rowCases as$name=>$changes){
        $setRow($changes);$r=$h();$check('price cancellation with '.$name.' still alerts',
            $r['status']==='degraded'&&in_array('api_row_rejected',$r['flags'],true));$setRow();
    }
    $setAudit=static function(?array $changes=[])use($p,$audit):void{
        $p->exec('DELETE FROM modeled_conversion_price_audit WHERE outbox_id IN (2,9999)');if($changes===null)return;
        $values=array_replace($audit,$changes);$keys=array_keys($values);
        $p->prepare('INSERT INTO modeled_conversion_price_audit('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')
            ->execute(array_values($values));
    };
    foreach(['missing audit'=>null,'another row audit'=>['outbox_id'=>9999],'review-only audit'=>['action'=>'review_required'],
        'differently cased action'=>['action'=>'VOIDED'],'padded action'=>['action'=>'voided '],
        'nonzero applied value'=>['applied_value_rub'=>1],'zero original value'=>['original_value_rub'=>0]]as$name=>$changes){
        $setAudit($changes);$r=$h();$check('price cancellation with '.$name.' still alerts',
            $r['status']==='degraded'&&in_array('api_row_rejected',$r['flags'],true));$setAudit();
    }
    $receipt=static function(int $errors)use($p):void{
        $p->prepare("UPDATE modeled_conversion_value_export_settings SET last_receipt_json=? WHERE project_key='anytour'")
            ->execute([json_encode(['errors'=>$errors],JSON_THROW_ON_ERROR)]);
    };
    $receipt(1);$r=$h();$check('an independent tick error still alerts alongside a valid cancellation',
        $r['status']==='degraded'&&$r['flags']===['delivery_tick_errors']);
    $receipt(0);$check('a successful later tick clears its transient flag while the cancellation stays recorded',$h()['status']==='ok');
    deliveryObservationInsert($p,['id'=>3,'state'=>'failed','last_error'=>'api_row_rejected','attempts'=>1]);
    $receipt(1);$r=$h();$check('a separate genuine failed upload remains visible beside the audited cancellation',
        $r['status']==='degraded'&&in_array('api_row_rejected',$r['flags'],true)&&in_array('delivery_tick_errors',$r['flags'],true));
    $receipt(0);$r=$h();$check('a successful tick cannot erase the genuine failed upload',
        $r['status']==='degraded'&&$r['flags']===['api_row_rejected']);
    $check('health reads preserve ledger audit immutable profiles and both worker receipts',$preserved);
}
function deliveryClockWriterAssertions(PDO $p,callable $check):void
{
    $tick=1793743198;$observed=$tick+3;$gate=static fn(string $chat):bool=>true;
    modeledValueSchema($p);$wire=new ModeledValueTestWire($tick-86400);$api=$wire->api();$control=new ModeledConversionValueExport($p);
    $control->configure('create-goal',$api,$tick-10);$control->configure('enable',$api,$tick-9);
    $subject=modeledValueBinding($p,901);modeledValueSave($p,modeledValueWrapper($subject,$tick));
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$tick,$gate,static fn():int=>$observed);
    $r=$p->query('SELECT * FROM max_channel_conversion_outbox')->fetch(PDO::FETCH_ASSOC);
    $check('POST receipt stores actual injected observation across midnight',(int)$r['claimed_at_unix']===$tick&&(int)$r['value_api_accepted_at_unix']===$observed&&$r['value_receipt_basis']==='post_response');
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$tick+301,$gate);
    $r2=$p->query('SELECT * FROM max_channel_conversion_outbox')->fetch(PDO::FETCH_ASSOC);
    $check('poll preserves acceptance clock and records first validated processing',(int)$r2['value_api_accepted_at_unix']===$observed&&(int)$r2['value_processed_at_unix']===$tick+301&&$wire->posts===1);
    $p->exec('UPDATE max_channel_conversion_outbox SET value_api_accepted_at_unix=NULL,value_processed_at_unix=NULL,value_receipt_basis=NULL,state=\'accepted\',next_attempt_unix=0');
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$tick+602,$gate);
    $legacy=$p->query('SELECT * FROM max_channel_conversion_outbox')->fetch(PDO::FETCH_ASSOC);
    $check('legacy polling never fabricates acceptance time',$legacy['value_api_accepted_at_unix']===null&&(int)$legacy['value_processed_at_unix']===$tick+602&&$legacy['value_receipt_basis']==='get_reconciliation');
    $u=modeledValueBinding($p,902);modeledValueSave($p,modeledValueWrapper($u,$tick+700));$wire->postMode='unknown';
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$tick+700,$gate);
    $p->exec("UPDATE max_channel_conversion_outbox SET next_attempt_unix=0 WHERE conversation_id=902");$wire->postMode='accepted';
    ModeledConversionValueExport::tick($p,new ModeledValueTestLookup(),$api,$tick+1001,$gate);
    $q=$p->query('SELECT * FROM max_channel_conversion_outbox WHERE conversation_id=902')->fetch(PDO::FETCH_ASSOC);
    $check('GET-only unknown reconciliation records observed receipt without another POST',$wire->posts===2&&(int)$q['value_api_accepted_at_unix']===$tick+1001&&$q['value_receipt_basis']==='get_reconciliation');
}
function deliveryExternalMonitorAssertions(callable $check):void
{
    $now=1793700000;$at=static fn(int $n):string=>gmdate('Y-m-d\TH:i:s\Z',$n);
    $result=['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','status'=>'ok','flags'=>[],
        'notification'=>'quiet','ok'=>true,'private_token'=>'synthetic-do-not-return','customer_text'=>'synthetic-hidden'];
    $source=static fn(string $id,string $event='schedule',int $attempt=1,string $sha='a'):array=>
        ['event'=>$event,'run_id'=>$id,'run_attempt'=>$attempt,'source_sha'=>str_repeat($sha,40)];
    $compose=static fn(array $r,?array $old,int $clock,?array $s=null):array=>
        ModeledConversionDeliveryHealth::composeExternalReceipt($r,$old,$clock,$s);
    $view=static fn(array $r,int $clock):array=>ModeledConversionDeliveryHealth::externalReceiptStatus($r,$clock);
    $reject=static function(callable $f):bool{try{$f();return false;}catch(UnexpectedValueException $ignored){return true;}};
    $old=['schema_version'=>1,'project'=>'anytour','counter_id'=>'98615635','checked_at'=>$at($now-10),
        'status'=>'ok','flags'=>[],'notification'=>'sent','last_notification_at'=>$at($now-10)];
    $legacy=$view($old,$now);
    $check('legacy check is fresh without inventing scheduled execution',$legacy['fresh']&&$legacy['source_event']===null
        &&!$legacy['scheduled']['available']&&!$legacy['scheduled']['regular']&&$legacy['scheduled']['fresh']===null);
    $quiet=$compose($result,$old,$now,$source('100','workflow_dispatch'));
    $check('quiet manual check retains original successful notification clock',$quiet['last_notification_at']===$at($now-10));
    $check('receipt strips synthetic private and customer fields',!str_contains(json_encode($quiet,JSON_THROW_ON_ERROR),'synthetic-'));
    $one=$compose($result,$quiet,$now+300,$source('101'));
    $oneView=$view($one,$now+300);
    $check('one natural original schedule is not regular proof',$oneView['scheduled']['success_streak']===1&&!$oneView['scheduled']['regular']);
    $duplicate=$compose($result,$one,$now+301,$source('101'));
    $duplicate=$compose($result,$duplicate,$now+302,$source('101'));
    $check('same original run cannot be counted three times',count($duplicate['scheduled_checks'])===1
        &&$view($duplicate,$now+302)['scheduled']['success_streak']===1);
    $two=$compose($result,$one,$now+600,$source('102'));
    $three=$compose($result,$two,$now+900,$source('103','schedule',1,'b'));
    $third=$view($three,$now+900);
    $check('three distinct timely original schedules establish regularity',$third['scheduled']['regular']
        &&$third['scheduled']['success_streak']===3&&count($third['scheduled']['checks'])===3);
    $check('ordinary source revision change does not erase real scheduled history',$third['source_sha']===str_repeat('b',40)
        &&$third['scheduled']['checks'][0]['source_sha']===str_repeat('a',40));
    $manual=$compose($result,$three,$now+1000,$source('104','workflow_dispatch'));
    $deploy=$compose($result,$manual,$now+1100,$source('105','workflow_run'));
    $check('manual and post-deploy checks do not increment or erase scheduled history',$manual['scheduled_checks']===$three['scheduled_checks']
        &&$deploy['scheduled_checks']===$three['scheduled_checks']);
    $lateManual=$compose($result,$deploy,$now+1801,$source('106','workflow_dispatch'));
    $late=$view($lateManual,$now+1801);
    $check('fresh manual check cannot hide stale scheduled monitoring',$late['fresh']&&!$late['scheduled']['fresh']&&!$late['scheduled']['regular']);
    $gap=$compose($result,$lateManual,$now+1802,$source('107'));
    $check('long scheduled gap resets successful streak to one',$view($gap,$now+1802)['scheduled']['success_streak']===1);
    $rerun=$compose($result,$gap,$now+1900,$source('107','schedule',2));
    $check('rerun attempt does not qualify as new automatic proof',$rerun['scheduled_checks']===$gap['scheduled_checks']
        &&$rerun['source_run_attempt']===2);
    $failed=$compose(array_replace($result,['status'=>'degraded','flags'=>['delivery_tick_stale']]),$rerun,$now+2102,$source('108'));
    $check('scheduled health failure is retained and resets successful streak',$view($failed,$now+2102)['scheduled']['success_streak']===0
        &&$failed['scheduled_checks'][2]['ok']===false);
    $recovered=$compose($result,$failed,$now+2402,$source('109'));
    $check('recovered schedule begins a new streak',$view($recovered,$now+2402)['scheduled']['success_streak']===1);
    $unknown=$compose(array_replace($result,['notification'=>'unknown','ok'=>false]),$recovered,$now+2702,$source('110'));
    $check('uncertain notification cannot qualify as healthy scheduled proof',$view($unknown,$now+2702)['scheduled']['success_streak']===0);
    $recordedWithoutOrigin=$compose($result,$three,$now+1000);
    $check('legacy caller without origin does not fabricate scheduled progress',$recordedWithoutOrigin['source_event']===null
        &&$recordedWithoutOrigin['scheduled_checks']===$three['scheduled_checks']);
    $sent=$compose(array_replace($result,['notification'=>'sent']),$three,$now+1000,$source('111','workflow_dispatch'));
    $check('new confirmed notification advances only its own observation clock',$sent['last_notification_at']===$at($now+1000));
    $check('future stored observation is rejected',$reject(static fn():array=>$view(array_replace($old,['checked_at'=>$at($now+61)]),$now)));
    $check('future notification clock is rejected',$reject(static fn():array=>$view(array_replace($old,['last_notification_at'=>$at($now+61)]),$now)));
    $check('foreign counter receipt is rejected',$reject(static fn():array=>$compose(array_replace($result,['counter_id'=>'999']),null,$now,$source('201'))));
    $check('untrusted event name is rejected',$reject(static fn():array=>$compose($result,null,$now,$source('202','push'))));
    $check('run identifier injection is rejected',$reject(static fn():array=>$compose($result,null,$now,$source('1;echo synthetic'))));
    $check('noncanonical source SHA is rejected',$reject(static fn():array=>$compose($result,null,$now,$source('203','schedule',1,'A'))));
    $check('partial provenance is rejected',$reject(static fn():array=>$compose($result,null,$now,['event'=>'schedule'])));
    $bad=$three;$bad['scheduled_checks'][1]=$bad['scheduled_checks'][0];
    $check('duplicate stored run cannot become regular proof',$reject(static fn():array=>$view($bad,$now+900)));
    $bad=$three;$bad['scheduled_checks'][1]['run_attempt']=2;
    $check('stored rerun cannot become original schedule proof',$reject(static fn():array=>$view($bad,$now+900)));
    $bad=$three;$bad['scheduled_checks'][0]['checked_at']=$at($now+901);
    $check('future or out-of-order scheduled history fails closed',$reject(static fn():array=>$view($bad,$now+900)));
    $bad=$three;$bad['scheduled_checks'][2]['status']='degraded';
    $check('inconsistent stored healthy marker fails closed',$reject(static fn():array=>$view($bad,$now+900)));
    $bad=$old;$bad['last_notification_at']=$at($now-9);
    $check('notification observation after its receipt fails closed',$reject(static fn():array=>$view($bad,$now)));
    $bad=$old;$bad['checked_at']=$at($now+1);
    $check('backwards recorder clock cannot overwrite valid prior state',$reject(static fn():array=>$compose($result,$bad,$now,$source('210'))));
    $bounded=$quiet;
    for($i=1;$i<=100;$i++)$bounded=$compose($result,$bounded,$now+300*$i,$source((string)(1000+$i)));
    $check('long automatic operation keeps exactly three records within private file bound',count($bounded['scheduled_checks'])===3
        &&strlen(json_encode($bounded,JSON_THROW_ON_ERROR))<=4096&&$view($bounded,$now+30000)['scheduled']['regular']);
}
if(realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
    $p=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$passed=0;
    $check=static function(string $name,bool $ok)use(&$passed):void{if(!$ok)throw new RuntimeException('FAIL '.$name);$passed++;echo 'PASS '.$name.PHP_EOL;};
    deliveryDailyAssertions($p,$check);deliveryHealthAssertions($p,$check);deliveryClockWriterAssertions($p,$check);deliveryExternalMonitorAssertions($check);
    echo 'MODELED DELIVERY OBSERVABILITY PASSED '.$passed.PHP_EOL;
}
